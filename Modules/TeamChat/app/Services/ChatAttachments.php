<?php

declare(strict_types=1);

namespace Modules\TeamChat\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\CollaborationCore\Data\ActorContext;
use Modules\CollaborationCore\Exceptions\ContractException;
use Modules\CollaborationCore\Support\Guard;

final class ChatAttachments
{
    public function __construct(private ChatAccess $access, private AttachmentValidation $validation) {}

    /** @return array<string,mixed> */
    public function prepare(ActorContext $actor, string $conversation, string $command, string $name, string $kind, string $mime, int $size, string $sha256): array
    {
        Guard::token($command);
        if (! in_array($kind, ['voice', 'image', 'document'], true) || ! preg_match('/^[a-f0-9]{64}$/D', $sha256) ||
            $size < 1 || $size > (int) config('teamchat.attachments.max_bytes') || mb_strlen($name) > 180 ||
            ! preg_match('/^[^\\\\\/\x00-\x1f\x7f]+$/uD', $name) || preg_match('/[\x{202a}-\x{202e}\x{2066}-\x{2069}]/u', $name) ||
            ! isset(config('teamchat.attachments.types')[strtolower(pathinfo($name, PATHINFO_EXTENSION))])) {
            throw new ContractException('invalid', 422);
        }
        $hash = hash('sha256', json_encode([$conversation, $name, $kind, $mime, $size, $sha256], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($actor, $conversation, $command, $name, $kind, $mime, $size, $sha256, $hash): array {
            $this->lock($actor);
            $row = $this->access->conversation($actor, $conversation, true);
            $existing = DB::table('collab_chat_uploads')->where('institution_id', $actor->institutionId)->where('user_id', $actor->userId)->where('command_id', $command)->first();
            if ($existing) {
                if (! hash_equals($existing->request_hash, $hash)) {
                    throw new ContractException('idempotency_conflict', 409);
                }

                return $this->status($actor, $existing->id);
            }
            if (DB::table('collab_chat_uploads')->where('institution_id', $actor->institutionId)->where('user_id', $actor->userId)->whereNull('message_id')->whereIn('state', ['prepared', 'ready'])->where('expires_at', '>', now())->count() >= 20) {
                throw new ContractException('too_many_requests', 429);
            }
            $id = (string) Str::ulid();
            DB::table('collab_chat_uploads')->insert(['id' => $id, 'institution_id' => $actor->institutionId, 'user_id' => $actor->userId, 'conversation_id' => $conversation,
                'command_id' => $command, 'request_hash' => $hash, 'name' => $name, 'kind' => $kind, 'mime' => $mime, 'expected_size' => $size, 'source_hash' => $sha256,
                'access_from_tick' => $this->access->fromTick($actor, $row), 'expires_at' => now()->addHours((int) config('teamchat.attachments.staging_hours')),
                'created_at' => now(), 'updated_at' => now()]);

            return $this->status($actor, $id);
        }, 3);
    }

    /** @return array<string,mixed> */
    public function upload(ActorContext $actor, string $id, UploadedFile $file): array
    {
        $row = $this->authorized($actor, $id, true);
        if (! $file->isValid() || $file->getSize() !== (int) $row->expected_size || ! hash_equals($row->source_hash, hash_file('sha256', $file->getPathname()))) {
            throw new ContractException('invalid', 422);
        }
        if ($row->state === 'ready') {
            return $this->metadata($row);
        }
        if ($row->state !== 'prepared') {
            throw new ContractException('invalid', 422);
        }
        $validated = $this->validation->inspect($file->getPathname(), $row);
        try {
            return DB::transaction(function () use ($actor, $id, $validated): array {
                $this->lock($actor);
                $row = $this->authorized($actor, $id, true);
                if ($row->state === 'ready') {
                    return $this->metadata($row);
                }
                if ($row->state !== 'prepared') {
                    throw new ContractException('invalid', 422);
                }
                $stream = fopen($validated['path'], 'rb');
                try {
                    if (! Storage::disk('teamchat_private')->put($this->path($row), $stream)) {
                        throw new \RuntimeException('Private storage unavailable');
                    }
                } finally {
                    fclose($stream);
                }
                DB::table('collab_chat_uploads')->where('id', $id)->update([
                    'state' => 'ready', 'mime' => $validated['mime'], 'size' => filesize($validated['path']), 'scan_status' => $validated['scan_status'],
                    'duration_ms' => $validated['duration_ms'], 'width' => $validated['width'], 'height' => $validated['height'], 'updated_at' => now()]);

                return $this->status($actor, $id);
            }, 3);
        } finally {
            if ($validated['temporary']) {
                @unlink($validated['path']);
            }
        }
    }

    /** @param list<string> $ids */
    public function attach(ActorContext $actor, string $conversation, string $message, array $ids): void
    {
        if (count($ids) > (int) config('teamchat.attachments.max_count') || ! array_is_list($ids) || count(array_unique($ids)) !== count($ids)) {
            throw new ContractException('invalid', 422);
        }
        foreach ($ids as $id) {
            $row = $this->authorized($actor, $id, true);
            if ($row->conversation_id !== $conversation || $row->state !== 'ready' || $row->message_id !== null ||
                ! Storage::disk('teamchat_private')->exists($this->path($row))) {
                throw new ContractException('invalid', 422);
            }
            DB::table('collab_chat_uploads')->where('id', $id)->update(['message_id' => $message, 'updated_at' => now()]);
        }
    }

    /** @return array<string,mixed> */
    public function status(ActorContext $actor, string $id): array
    {
        return $this->metadata($this->authorized($actor, $id));
    }

    public function cancel(ActorContext $actor, string $id): void
    {
        DB::transaction(function () use ($actor, $id): void {
            $this->lock($actor);
            $row = $this->authorized($actor, $id, true);
            if ($row->message_id !== null) {
                throw new ContractException('forbidden', 403);
            }
            DB::table('collab_chat_uploads')->where('id', $id)->update(['state' => 'cancelled', 'updated_at' => now()]);
            DB::afterCommit(fn () => Storage::disk('teamchat_private')->delete($this->path($row)));
        }, 3);
    }

    public function authorized(ActorContext $actor, string $id, bool $owner = false): object
    {
        $row = DB::table('collab_chat_uploads')->where('institution_id', $actor->institutionId)->where('id', $id)->first();
        if (! $row || ($owner && (int) $row->user_id !== $actor->userId)) {
            throw new ContractException('not_found', 404);
        }
        $conversation = $this->access->conversation($actor, $row->conversation_id, $owner);
        if ($row->message_id !== null) {
            $message = DB::table('collab_chat_messages')->where('institution_id', $actor->institutionId)->where('conversation_id', $row->conversation_id)->where('id', $row->message_id)->first();
            if (! $message || $message->deleted_at !== null || (int) $message->access_tick < $this->access->fromTick($actor, $conversation)) {
                throw new ContractException('not_found', 404);
            }
        } elseif ((int) $row->user_id !== $actor->userId || (int) $row->access_from_tick !== $this->access->fromTick($actor, $conversation) || now()->greaterThan($row->expires_at)) {
            throw new ContractException('not_found', 404);
        }

        return $row;
    }

    /** @return array<string,mixed> */
    public function metadata(object $row): array
    {
        return ['id' => $row->id, 'name' => $row->name, 'kind' => $row->kind, 'mime' => $row->mime, 'size' => $row->size === null ? (int) $row->expected_size : (int) $row->size,
            'state' => $row->state, 'scan_status' => $row->scan_status, 'duration_ms' => $row->duration_ms === null ? null : (int) $row->duration_ms,
            'width' => $row->width, 'height' => $row->height, 'content_url' => '/api/v1/collaboration/uploads/'.$row->id.'/content'];
    }

    public function path(object $row): string
    {
        return $row->institution_id.'/'.$row->id.'/content';
    }

    private function lock(ActorContext $actor): void
    {
        DB::table('institutions')->where('id', $actor->institutionId)->lockForUpdate()->firstOrFail();
    }

    public function prune(int $tenant): int
    {
        return DB::transaction(function () use ($tenant): int {
            DB::table('institutions')->where('id', $tenant)->lockForUpdate()->firstOrFail();
            $rows = DB::table('collab_chat_uploads')->where('institution_id', $tenant)->whereNull('message_id')->where('state', '!=', 'expired')->where('expires_at', '<=', now())->get();
            foreach ($rows as $row) {
                Storage::disk('teamchat_private')->delete($this->path($row));
                DB::table('collab_chat_uploads')->where('id', $row->id)->update(['state' => 'expired', 'updated_at' => now()]);
            }

            return $rows->count();
        }, 3);
    }
}
