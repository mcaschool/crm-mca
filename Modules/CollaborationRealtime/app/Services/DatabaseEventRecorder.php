<?php

declare(strict_types=1);

namespace Modules\CollaborationRealtime\Services;

use Illuminate\Support\Facades\DB;
use Modules\CollaborationCore\Data\InstitutionContext;
use Modules\CollaborationCore\Exceptions\ContractException;
use Modules\CollaborationCore\Services\InstitutionExecution;
use Modules\CollaborationRealtime\Contracts\TransactionalEventRecorder;
use Modules\CollaborationRealtime\Data\DeliveryAudience;
use Modules\CollaborationRealtime\Data\EventEnvelope;

final class DatabaseEventRecorder implements TransactionalEventRecorder
{
    public function __construct(private EventPolicies $policies, private EventCodec $codec, private InstitutionExecution $execution) {}

    public function append(InstitutionContext $context, EventEnvelope $event, DeliveryAudience $audience): string
    {
        if (DB::connection()->transactionLevel() < 1) {
            throw new \LogicException('Recorder requires the business transaction on the default connection.');
        }
        if ($context->institutionId !== $event->institutionId || $context->institutionId !== $audience->institutionId || count($audience->userIds) > 100) {
            throw new ContractException('invalid', 422);
        }
        $current = app(\Modules\Core\Tenancy\CurrentInstitution::class);
        if ($current->has() && $current->id() !== $context->institutionId) {
            throw new ContractException('forbidden', 403);
        }
        $policy = $this->policies->get($event->type);
        $policy->validate($event);
        $payload = $this->codec->canonical($event->jsonSerialize());
        if (strlen($payload) > 32768 || preg_match('/"(?:password|token|access_token|session_id|session_hash|secret|credentials|livekit_token|authorization)"\s*:/i', $payload)) {
            throw new ContractException('invalid', 422);
        }
        if ($policy->ephemeral() && ($audience->expiresAt === null || $audience->expiresAt > now()->addMinutes(5))) {
            throw new ContractException('invalid', 422);
        }
        $users = $audience->userIds;
        sort($users);
        $metadata = $this->codec->canonical(['users' => $users, 'except' => $audience->exceptDeviceId]);
        $expiry = $audience->expiresAt?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
        $hash = hash('sha256', $payload.$metadata.($expiry ?? '').(int) $policy->ephemeral());

        $operation = function () use ($context, $event, $audience, $users, $metadata, $payload, $expiry, $hash, $policy): string {
            // One tenant sequence lock held through the OUTER business commit. No auto-increment gaps/cursor races.
            DB::table('collab_streams')->insertOrIgnore(['institution_id' => $context->institutionId, 'position' => 0, 'floor' => 0]);
            $stream = DB::table('collab_streams')->where('institution_id', $context->institutionId)->lockForUpdate()->firstOrFail();
            $existing = DB::table('collab_events')->where('id', $event->id)->lockForUpdate()->first();
            if ($existing) {
                if ((int) $existing->institution_id !== $context->institutionId || ! hash_equals($existing->content_hash, $hash)) {
                    throw new ContractException('idempotency_conflict', 409);
                }

                return $event->id;
            }
            if (DB::table('users')->where('institution_id', $context->institutionId)->whereIn('id', $users)->count() !== count($users)
                || ($event->actorId !== null && ! DB::table('users')->where('institution_id', $context->institutionId)->where('id', $event->actorId)->exists())
                || ($audience->exceptDeviceId !== null && ! DB::table('collab_devices')->where('institution_id', $context->institutionId)->where('id', $audience->exceptDeviceId)->exists())) {
                throw new ContractException('not_found', 404);
            }
            $last = DB::table('collab_events')->where('institution_id', $context->institutionId)->where('aggregate_type', $event->aggregateType)->where('aggregate_id', $event->aggregateId)->orderByDesc('aggregate_version')->lockForUpdate()->value('aggregate_version');
            if ($last !== null && $event->aggregateVersion <= (int) $last) {
                throw new ContractException('idempotency_conflict', 409);
            }
            $position = (int) $stream->position + 1;
            DB::table('collab_events')->insert(['id' => $event->id, 'institution_id' => $context->institutionId, 'position' => $position,
                'aggregate_type' => $event->aggregateType, 'aggregate_id' => $event->aggregateId, 'aggregate_version' => $event->aggregateVersion,
                'content_hash' => $hash, 'envelope' => $payload, 'audience' => $metadata, 'ephemeral' => $policy->ephemeral(), 'expires_at' => $expiry, 'created_at' => now()]);
            DB::table('collab_outbox')->insert(['event_id' => $event->id, 'institution_id' => $context->institutionId, 'status' => 'pending', 'attempts' => 0, 'available_at' => now()]);
            DB::table('collab_streams')->where('institution_id', $context->institutionId)->update(['position' => $position]);

            return $event->id;
        };
        // Only the strict, internal security policy can record a transition that disables its tenant.
        // Such events have no client audience and are consumed by the trusted outbox adapter.
        if ($policy instanceof RevocationPolicy) {
            if ($users !== [] || $audience->exceptDeviceId !== null || $audience->expiresAt !== null ||
                ! DB::table('institutions')->where('id', $context->institutionId)->exists()) {
                throw new ContractException('invalid', 422);
            }

            return $current->runFor($context->institutionId, $operation);
        }

        return $this->execution->run($context, $operation);
    }
}
