<?php

declare(strict_types=1);

namespace Modules\CollaborationCore\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\CollaborationCore\Contracts\DeviceRegistry;
use Modules\CollaborationCore\Data\ActorContext;
use Modules\CollaborationCore\Data\AuthenticatedSessionContext;
use Modules\CollaborationCore\Data\DeviceDescriptor;
use Modules\CollaborationCore\Data\DeviceRegistration;
use Modules\CollaborationCore\Enums\AuthenticationLevel;
use Modules\CollaborationCore\Enums\DevicePlatform;
use Modules\CollaborationCore\Enums\DeviceStatus;
use Modules\CollaborationCore\Exceptions\ContractException;

final class DatabaseDeviceRegistry implements DeviceRegistry
{
    public function __construct(private ApplicationAccess $access) {}

    public function register(AuthenticatedSessionContext $context, DeviceRegistration $registration): DeviceDescriptor
    {
        // Until a complete MFA mobile login exists, enrollment is web only.
        if ($registration->platform !== DevicePlatform::Web || $context->authenticationLevel !== AuthenticationLevel::Mfa) {
            throw new ContractException('forbidden', 403);
        }

        return DB::transaction(function () use ($context, $registration): DeviceDescriptor {
            $user = User::query()->whereKey($context->userId)->lockForUpdate()->first();
            $this->validateUser($user, $context->institutionId);
            $row = DB::table('collab_devices')->where('session_hash', $context->sessionId)->first();
            if ($row) {
                $this->validateRow($row, $context->institutionId, $context->userId, $context->sessionId, $user);
                DB::table('collab_devices')->where('id', $row->id)->update(['last_activity_at' => now(), 'expires_at' => now()->addMinutes((int) config('session.lifetime', 120)), 'updated_at' => now()]);

                return $this->descriptor($row);
            }
            $this->assertStoredSession($user, $context->sessionId);
            $id = (string) Str::ulid();
            DB::table('collab_devices')->insert(['id' => $id, 'institution_id' => $context->institutionId, 'user_id' => $context->userId,
                'platform' => $registration->platform->value, 'label' => $registration->label, 'session_hash' => $context->sessionId,
                'auth_fingerprint' => $this->fingerprint($user), 'authentication_level' => 'mfa', 'last_activity_at' => now(),
                'expires_at' => now()->addMinutes((int) config('session.lifetime', 120)), 'created_at' => now(), 'updated_at' => now()]);

            return new DeviceDescriptor($id, $context->userId, DevicePlatform::Web, DeviceStatus::Active);
        });
    }

    public function assertSessionBinding(ActorContext $context): void
    {
        if ($context->authenticationLevel !== AuthenticationLevel::Mfa) {
            throw new ContractException('two_factor_required', 403);
        }
        $user = User::query()->find($context->userId);
        $this->validateUser($user, $context->institutionId);
        $row = DB::table('collab_devices')->where('id', $context->deviceId)->where('institution_id', $context->institutionId)->where('user_id', $context->userId)->first();
        if (! $row) {
            throw new ContractException('not_found', 404);
        }
        $this->validateRow($row, $context->institutionId, $context->userId, $context->sessionId, $user);
    }

    public function resolveCurrent(ActorContext $context): DeviceDescriptor
    {
        $this->assertSessionBinding($context);

        return $this->descriptor(DB::table('collab_devices')->where('id', $context->deviceId)->firstOrFail());
    }

    public function revoke(ActorContext $context, string $deviceId): void
    {
        $this->assertSessionBinding($context);
        DB::transaction(function () use ($context, $deviceId): void {
            User::query()->whereKey($context->userId)->lockForUpdate()->firstOrFail();
            $this->assertSessionBinding($context);
            $row = DB::table('collab_devices')->where('id', $deviceId)->where('institution_id', $context->institutionId)->where('user_id', $context->userId)->lockForUpdate()->first();
            if (! $row) {
                throw new ContractException('not_found', 404);
            }
            DB::table('collab_devices')->where('id', $row->id)->update(['revoked_at' => now(), 'updated_at' => now()]);
            DB::table('collab_presence_devices')->where('device_id', $row->id)->delete();
            app(Revocation::class)->session($context->userId, $row->session_hash);
        });
    }

    public function listForCurrentUser(ActorContext $context): array
    {
        $this->assertSessionBinding($context);

        return DB::table('collab_devices')->where('institution_id', $context->institutionId)->where('user_id', $context->userId)
            ->orderBy('id')->get()->map(fn ($row) => $this->descriptor($row))->all();
    }

    public function touch(ActorContext $context): void
    {
        $this->assertSessionBinding($context);
        DB::table('collab_devices')->where('id', $context->deviceId)->whereNull('revoked_at')->update([
            'last_activity_at' => now(), 'expires_at' => now()->addMinutes((int) config('session.lifetime', 120)), 'updated_at' => now()]);
    }

    /** Internal delivery eligibility check; registration alone is never session validity. */
    public function usable(object $row): bool
    {
        try {
            $user = User::query()->find($row->user_id);
            $this->validateUser($user, (int) $row->institution_id);
            $this->validateRow($row, (int) $row->institution_id, (int) $row->user_id, $row->session_hash, $user);

            return true;
        } catch (ContractException) {
            return false;
        }
    }

    private function validateUser(?User $user, int $institutionId): void
    {
        if (! $user || (int) $user->institution_id !== $institutionId || ! $this->access->allows($user, 'collaboration')) {
            throw new ContractException('forbidden', 403);
        }
        if (! $user->hasTwoFactorEnabled()) {
            throw new ContractException('two_factor_required', 403);
        }
    }

    private function validateRow(object $row, int $institutionId, int $userId, string $sessionId, User $user): void
    {
        if ((int) $row->institution_id !== $institutionId || (int) $row->user_id !== $userId || ! hash_equals($row->session_hash, $sessionId)) {
            throw new ContractException('forbidden', 403);
        }
        $this->assertStoredSession($user, $sessionId);
        if ($row->revoked_at !== null) {
            throw new ContractException('device_revoked', 403);
        }
        if (\Illuminate\Support\Carbon::parse($row->expires_at)->isPast() || ! hash_equals($row->auth_fingerprint, $this->fingerprint($user))) {
            throw new ContractException('session_revoked', 401);
        }
    }

    private function assertStoredSession(User $user, string $sessionHash): void
    {
        if (config('session.driver') !== 'database') {
            return;
        }
        $sessions = DB::connection(config('session.connection'))->table(config('session.table', 'sessions'))
            ->where('user_id', $user->id)->where('last_activity', '>', now()->subMinutes((int) config('session.lifetime', 120))->timestamp)->pluck('id');
        foreach ($sessions as $id) {
            if (hash_equals($sessionHash, hash('sha256', $id))) {
                return;
            }
        }
        throw new ContractException('session_revoked', 401);
    }

    private function fingerprint(User $user): string
    {
        return MfaSessionProof::fingerprint($user);
    }

    private function descriptor(object $row): DeviceDescriptor
    {
        return new DeviceDescriptor($row->id, (int) $row->user_id, DevicePlatform::from($row->platform), $row->revoked_at ? DeviceStatus::Revoked : DeviceStatus::Active);
    }
}
