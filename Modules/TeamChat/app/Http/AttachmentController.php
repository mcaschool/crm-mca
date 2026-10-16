<?php

declare(strict_types=1);

namespace Modules\TeamChat\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Modules\CollaborationCore\Data\ActorContext;
use Modules\CollaborationCore\Exceptions\ContractException;
use Modules\TeamChat\Services\ChatAttachments;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class AttachmentController
{
    public function __construct(private ChatAttachments $files) {}

    public function prepare(Request $r, string $conversation): JsonResponse
    {
        $data = $r->validate(['command_id' => 'required|string|max:64', 'name' => 'required|string|max:180', 'kind' => 'required|in:image,voice,document',
            'mime' => 'required|string|max:120', 'size' => 'required|integer|min:1', 'sha256' => 'required|string|size:64', 'institution_id' => 'prohibited', 'user_id' => 'prohibited', 'message_id' => 'prohibited', 'path' => 'prohibited']);

        return $this->reply($this->files->prepare($this->actor($r), $conversation, $data['command_id'], $data['name'], $data['kind'], $data['mime'], (int) $data['size'], $data['sha256']));
    }

    public function upload(Request $r, string $upload): JsonResponse
    {
        $r->validate(['file' => ['required', 'file', 'max:'.(int) ceil(config('teamchat.attachments.max_bytes') / 1024)]]);

        return $this->reply($this->files->upload($this->actor($r), $upload, $r->file('file')));
    }

    public function status(Request $r, string $upload): JsonResponse
    {
        return $this->reply($this->files->status($this->actor($r), $upload));
    }

    public function cancel(Request $r, string $upload): JsonResponse
    {
        $this->files->cancel($this->actor($r), $upload);

        return $this->reply(['cancelled' => true]);
    }

    public function content(Request $r, string $upload): BinaryFileResponse
    {
        $row = $this->files->authorized($this->actor($r), $upload);
        $disk = Storage::disk('teamchat_private');
        if ($row->state !== 'ready' || ! $disk->exists($this->files->path($row))) {
            throw new ContractException('not_found', 404);
        }
        $name = $row->name;
        if ($row->kind === 'voice') {
            $name = pathinfo($name, PATHINFO_FILENAME).'.mp3';
        }
        if ($row->kind === 'image') {
            $name = pathinfo($name, PATHINFO_FILENAME).'.jpg';
        }
        $response = response()->file($disk->path($this->files->path($row)), [
            'Content-Type' => $row->mime, 'Cache-Control' => 'private, no-store, max-age=0', 'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "sandbox; default-src 'none'", 'Cross-Origin-Resource-Policy' => 'same-origin', 'Referrer-Policy' => 'no-referrer']);
        $response->setContentDisposition($row->kind === 'document' || $r->boolean('download') ? 'attachment' : 'inline', $name);

        return $response;
    }

    private function actor(Request $r): ActorContext
    {
        $actor = $r->attributes->get('collaboration.actor');
        if (! $actor instanceof ActorContext) {
            throw new ContractException('unauthenticated', 401);
        }

        return $actor;
    }

    /** @param array<string,mixed> $data */
    private function reply(array $data): JsonResponse
    {
        return response()->json(['data' => $data, 'meta' => ['version' => '1.0']], 200, ['Cache-Control' => 'no-store']);
    }
}
