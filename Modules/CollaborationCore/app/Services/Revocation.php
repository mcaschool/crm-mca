<?php

declare(strict_types=1);

namespace Modules\CollaborationCore\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\CollaborationCore\Contracts\RevocationRecorder;
use Modules\CollaborationCore\Events\CollaborationAccessRevoked;
use Modules\CollaborationCore\Events\CollaborationDeviceRevoked;

final class Revocation
{
    public function access(int $institutionId, int $userId, string $reason): void
    {
        app(RevocationRecorder::class)->record(new CollaborationAccessRevoked($institutionId, $userId, $reason));
        $this->devices($institutionId, $userId, null, $reason);
        DB::table('collab_presence_calls')->where('institution_id', $institutionId)->where('user_id', $userId)->delete();
    }

    /** Kept for trusted callers; revokes only effective device transitions. */
    public function user(User $user): void
    {
        if (! $user->institution_id) {
            return;
        }
        DB::transaction(function () use ($user): void {
            DB::table('institutions')->where('id', $user->institution_id)->lockForUpdate()->firstOrFail();
            $this->devices((int) $user->institution_id, (int) $user->id, null, 'credentials_changed');
            DB::table('collab_presence_calls')->where('institution_id', $user->institution_id)->where('user_id', $user->id)->delete();
        }, 3);
    }

    public function session(int $userId, string $sessionHash, string $reason = 'session_ended'): void
    {
        $tenants = DB::table('collab_devices')->where('user_id', $userId)->where('session_hash', $sessionHash)->distinct()->orderBy('institution_id')->pluck('institution_id');
        DB::transaction(function () use ($tenants, $userId, $sessionHash, $reason): void {
            foreach ($tenants as $tenant) {
                DB::table('institutions')->where('id', $tenant)->lockForUpdate()->firstOrFail();
                $this->devices((int) $tenant, $userId, $sessionHash, $reason);
            }
            if (config('session.driver') === 'database') {
                if (DB::connection(config('session.connection'))->getName() !== DB::connection()->getName()) {
                    throw new \LogicException('Session revocation requires the same transactional connection.');
                }
                foreach (DB::table(config('session.table', 'sessions'))->where('user_id', $userId)->pluck('id') as $id) {
                    if (hash_equals($sessionHash, hash('sha256', $id))) {
                        DB::table(config('session.table', 'sessions'))->where('id', $id)->delete();
                    }
                }
            }
        }, 3);
    }

    private function devices(int $tenant, int $user, ?string $session, string $reason): void
    {
        $rows = DB::table('collab_devices')->where('institution_id', $tenant)->where('user_id', $user)
            ->when($session !== null, fn ($q) => $q->where('session_hash', $session))->whereNull('revoked_at')->orderBy('id')->lockForUpdate()->get();
        foreach ($rows as $row) {
            DB::table('collab_devices')->where('id', $row->id)->update(['revoked_at' => now(), 'updated_at' => now()]);
            DB::table('collab_presence_devices')->where('device_id', $row->id)->delete();
            app(RevocationRecorder::class)->record(new CollaborationDeviceRevoked($tenant, $user, $row->id, $reason));
        }
    }

    /** Called inside the model save transaction with a locked database pre-image. */
    public function userChanged(object $before, User $after): void
    {
        $tenant = (int) $before->institution_id;
        if ($tenant < 1) {
            return;
        }
        $reason = null;
        if ((int) $after->institution_id !== $tenant) {
            $reason = 'institution_changed';
        } elseif ($before->status === 'active' && ! $after->isActive()) {
            $reason = 'user_inactive';
        } elseif ($before->password !== $after->getRawOriginal('password') ||
            $before->two_factor_secret !== $after->getRawOriginal('two_factor_secret') ||
            $before->two_factor_confirmed_at !== $after->getRawOriginal('two_factor_confirmed_at')) {
            $reason = 'credentials_changed';
        }
        if ($reason !== null) {
            $this->access($tenant, (int) $after->id, $reason);
        }
    }

    public function institutionChanged(object $before, object $after): void
    {
        if ($before->status === 'active' && $before->collaboration_enabled &&
            ($after->status !== 'active' || ! $after->collaboration_enabled)) {
            foreach (DB::table('users')->where('institution_id', $before->id)->orderBy('id')->get() as $user) {
                $this->access((int) $before->id, (int) $user->id, 'institution_disabled');
            }
        }
    }

    /** Reconcile expiration/external session removal. Authorization already fails synchronously. */
    public function reconcile(int $institutionId): int
    {
        return DB::transaction(function () use ($institutionId): int {
            DB::table('institutions')->where('id', $institutionId)->lockForUpdate()->firstOrFail();
            $count = 0;
            foreach (DB::table('collab_devices')->where('institution_id', $institutionId)->whereNull('revoked_at')->orderBy('id')->get() as $row) {
                if (! app(DatabaseDeviceRegistry::class)->usable($row)) {
                    $this->devices($institutionId, (int) $row->user_id, $row->session_hash, 'eligibility_lost');
                    $count++;
                }
            }

            return $count;
        }, 3);
    }
}
