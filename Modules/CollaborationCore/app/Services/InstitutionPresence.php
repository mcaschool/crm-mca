<?php

declare(strict_types=1);

namespace Modules\CollaborationCore\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\CollaborationCore\Contracts\PresenceService;
use Modules\CollaborationCore\Contracts\UserDirectory;
use Modules\CollaborationCore\Data\ActorContext;
use Modules\CollaborationCore\Data\InstitutionContext;
use Modules\CollaborationCore\Data\PresenceSnapshot;
use Modules\CollaborationCore\Enums\ManualPresence;
use Modules\CollaborationCore\Exceptions\ContractException;
use Modules\CollaborationCore\Integration\CallPresenceAuthorizer;
use Modules\CollaborationCore\Storage\PresenceStore;
use Modules\CollaborationCore\Support\Guard;

final class InstitutionPresence implements PresenceService
{
    public function __construct(private DatabaseDeviceRegistry $devices, private UserDirectory $users, private PresenceStore $store,
        private InstitutionExecution $execution, private ApplicationAccess $access, private CallPresenceAuthorizer $calls) {}

    public function heartbeat(ActorContext $context): PresenceSnapshot
    {
        return DB::transaction(function () use ($context) {
            User::query()->whereKey($context->userId)->lockForUpdate()->firstOrFail();
            $this->devices->touch($context);
            $this->store->heartbeat($context);

            return $this->store->snapshot($context->institutionId, $context->userId);
        });
    }

    public function setManualStatus(ActorContext $context, ManualPresence $status): PresenceSnapshot
    {
        return DB::transaction(function () use ($context, $status) {
            User::query()->whereKey($context->userId)->lockForUpdate()->firstOrFail();
            $this->devices->assertSessionBinding($context);
            $this->store->manual($context, $status);

            return $this->store->snapshot($context->institutionId, $context->userId);
        });
    }

    public function snapshot(ActorContext $context, array $userIds): array
    {
        $this->devices->assertSessionBinding($context);
        Guard::users($userIds, true);
        if (count($userIds) > 100) {
            throw new ContractException('invalid', 422);
        }
        foreach ($userIds as $id) {
            $this->users->resolve($context, $id);
        }

        return array_map(fn (int $id) => $this->store->snapshot($context->institutionId, $id), $userIds);
    }

    public function setInCall(InstitutionContext $context, int $userId, string $callId, int $ttlSeconds): void
    {
        if ($ttlSeconds < 1 || $ttlSeconds > 3600) {
            throw new ContractException('invalid', 422);
        }
        $this->updateCall($context, $userId, $callId, $ttlSeconds);
    }

    public function clearInCall(InstitutionContext $context, int $userId, string $callId): void
    {
        Guard::token($callId);
        $current = app(\Modules\Core\Tenancy\CurrentInstitution::class);
        if ($current->has() && $current->id() !== $context->institutionId) {
            throw new ContractException('forbidden', 403);
        }
        $current->runFor($context->institutionId, fn () => DB::transaction(function () use ($context, $userId, $callId): void {
            DB::table('institutions')->where('id', $context->institutionId)->lockForUpdate()->firstOrFail();
            if ($this->calls instanceof \Modules\CollaborationCore\Integration\CallPresenceCleanupAuthorizer) {
                $this->calls->authorizeCleanup($context, $userId, $callId);
            } else {
                // Legacy adapters keep their existing strict check; historical cleanup requires the new port.
                $this->calls->authorize($context, $userId, $callId);
            }
            $this->store->call($context->institutionId, $userId, $callId, null);
        }, 3));
    }

    private function updateCall(InstitutionContext $context, int $userId, string $callId, ?int $ttlSeconds): void
    {
        Guard::token($callId);
        $this->execution->run($context, fn () => DB::transaction(function () use ($context, $userId, $callId, $ttlSeconds): void {
            $user = User::query()->where('institution_id', $context->institutionId)->whereKey($userId)->lockForUpdate()->first();
            if (! $user || ! $this->access->allows($user, 'collaboration')) {
                throw new ContractException('not_found', 404);
            }
            $this->calls->authorize($context, $userId, $callId);
            $this->store->call($context->institutionId, $userId, $callId, $ttlSeconds);
        }));
    }
}
