<?php

declare(strict_types=1);

namespace Modules\CollaborationCore\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\CollaborationCore\Contracts\NotificationDispatcher;
use Modules\CollaborationCore\Data\InstitutionContext;
use Modules\CollaborationCore\Data\NotificationAudience;
use Modules\CollaborationCore\Data\NotificationIntent;
use Modules\CollaborationCore\Exceptions\ContractException;

final class DatabaseNotificationDispatcher implements NotificationDispatcher
{
    public function __construct(private InstitutionExecution $execution, private ApplicationAccess $access, private NotificationPreferences $preferences, private DatabaseDeviceRegistry $registry) {}

    public function enqueue(InstitutionContext $context, NotificationIntent $intent, NotificationAudience $audience): string
    {
        if ($context->institutionId !== $audience->institutionId || count($audience->userIds) > 100) {
            throw new ContractException('invalid', 422);
        }

        try {
            return $this->execution->run($context, fn () => DB::transaction(function () use ($context, $intent, $audience): string {
                // Serialize deduplication within the institution, including the first insert.
                DB::table('institutions')->where('id', $context->institutionId)->lockForUpdate()->firstOrFail();
                $ids = $audience->userIds;
                sort($ids);
                $eligible = [];
                foreach ($ids as $id) {
                    $user = User::query()->where('institution_id', $context->institutionId)->whereKey($id)->lockForUpdate()->first();
                    if (! $user || ! $this->access->allows($user, 'collaboration')) {
                        throw new ContractException('not_found', 404);
                    }
                    if ($this->preferences->forUser($context->institutionId, $id)) {
                        $eligible[] = $id;
                    }
                }
                if ($audience->exceptDeviceId !== null && ! DB::table('collab_devices')->where('id', $audience->exceptDeviceId)->where('institution_id', $context->institutionId)->exists()) {
                    throw new ContractException('not_found', 404);
                }
                $hash = hash('sha256', json_encode([$intent->id, $intent->type, $intent->resourceId, $intent->sourceEventId, $intent->deduplicationKey, $intent->expiresAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s.u\\Z'), $ids, $audience->exceptDeviceId], JSON_THROW_ON_ERROR));
                $existing = DB::table('collab_notification_intents')->where('institution_id', $context->institutionId)->where('deduplication_key', $intent->deduplicationKey)->first();
                if ($existing) {
                    if (! hash_equals($existing->request_hash, $hash)) {
                        throw new ContractException('idempotency_conflict', 409);
                    }

                    return $existing->id;
                }
                if (DB::table('collab_notification_intents')->where('id', $intent->id)->exists()) {
                    throw new ContractException('idempotency_conflict', 409);
                }
                $devices = DB::table('collab_devices')->where('institution_id', $context->institutionId)->whereIn('user_id', $eligible)
                    ->whereNull('revoked_at')->where('expires_at', '>', now())->when($audience->exceptDeviceId, fn ($q, $id) => $q->where('id', '!=', $id))->orderBy('id')->get()->filter(fn ($row) => $this->registry->usable($row))->pluck('id')->values()->all();
                $expired = $intent->expiresAt <= now()->toDateTimeImmutable();
                DB::table('collab_notification_intents')->insert(['id' => $intent->id, 'institution_id' => $context->institutionId,
                    'deduplication_key' => $intent->deduplicationKey, 'request_hash' => $hash, 'type' => $intent->type, 'resource_id' => $intent->resourceId,
                    'source_event_id' => $intent->sourceEventId, 'recipients' => json_encode($eligible, JSON_THROW_ON_ERROR),
                    'device_ids' => json_encode($devices, JSON_THROW_ON_ERROR), 'status' => $expired ? 'expired' : ($eligible ? 'pending' : 'suppressed'),
                    'expires_at' => $intent->expiresAt->setTimezone(new \DateTimeZone('UTC')), 'created_at' => now(), 'updated_at' => now()]);

                return $intent->id;
            }, 3));
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            throw new ContractException('idempotency_conflict', 409);
        }
    }
}
