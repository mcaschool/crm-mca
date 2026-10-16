<?php

declare(strict_types=1);

namespace Modules\CollaborationCore\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\CollaborationCore\Exceptions\ContractException;
use Modules\Institutions\Models\Institution;

final class ApplicationAccess
{
    public const APPLICATIONS = ['crm', 'hr', 'sigac', 'collaboration'];

    public function allows(User $user, string $application): bool
    {
        if (! in_array($application, self::APPLICATIONS, true) || ! $user->isActive() || ! $user->institution_id) {
            return false;
        }
        $institution = Institution::query()->find($user->institution_id);
        if (! $institution || $institution->getAttribute('status') !== 'active') {
            return false;
        }
        if ($application === 'collaboration' && ! $institution->getAttribute('collaboration_enabled')) {
            return false;
        }
        $grant = DB::table('collab_application_access')->where('institution_id', $user->institution_id)->where('user_id', $user->id)->where('application', $application)->first();
        if ($grant !== null) {
            return (bool) $grant->allowed;
        }

        return $application === 'collaboration' || ($application === 'crm' && (bool) $user->getAttribute('crm_legacy_access'));
    }

    public function setIdentityAdministrator(User $actor, User $target, bool $enabled): void
    {
        DB::transaction(function () use ($actor, $target, $enabled): void {
            $ids = [$actor->id, $target->id];
            $locked = User::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $freshActor = $locked->get($actor->id);
            $freshTarget = $locked->get($target->id);
            if (! $freshActor || ! $freshTarget || ! $freshActor->canManageUsers()
                || (int) $freshActor->institution_id !== (int) $freshTarget->institution_id
                || (! $enabled && $freshActor->id === $freshTarget->id)) {
                throw new ContractException('forbidden', 403);
            }
            $freshTarget->forceFill(['is_identity_admin' => $enabled])->save();
            \Modules\Audit\Models\AuditLog::create(['institution_id' => $freshTarget->institution_id, 'user_id' => $freshActor->id,
                'action' => 'identity.administrator_changed', 'auditable_type' => User::class, 'auditable_id' => $freshTarget->id, 'changes' => ['enabled' => $enabled]]);
        });
        app(PanelAccessSnapshot::class)->forget();
    }

    public function set(User $actor, User $target, string $application, bool $allowed): void
    {
        $actor = $actor->fresh() ?? throw new ContractException('forbidden', 403);
        if (! $actor->canManageUsers() || $actor->institution_id !== $target->institution_id || ! in_array($application, self::APPLICATIONS, true)) {
            throw new ContractException('forbidden', 403);
        }
        DB::transaction(function () use ($actor, $target, $application, $allowed): void {
            DB::table('institutions')->where('id', $actor->institution_id)->lockForUpdate()->firstOrFail();
            $users = User::query()->whereIn('id', [$actor->id, $target->id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $actor = $users->get($actor->id);
            $locked = $users->get($target->id);
            if (! $actor || ! $locked || ! $actor->canManageUsers()) {
                throw new ContractException('forbidden', 403);
            }
            if ((int) $locked->institution_id !== (int) $actor->institution_id) {
                throw new ContractException('not_found', 404);
            }
            $target = $locked;
            $wasAllowed = $this->allows($target, 'collaboration');
            DB::table('collab_application_access')->updateOrInsert(
                ['institution_id' => $target->institution_id, 'user_id' => $target->id, 'application' => $application],
                ['allowed' => $allowed, 'created_at' => now(), 'updated_at' => now()]);
            if ($application === 'collaboration' && ! $allowed && $wasAllowed) {
                app(Revocation::class)->access((int) $target->institution_id, (int) $target->id, 'application_revoked');
            }
            \Modules\Audit\Models\AuditLog::create(['institution_id' => $target->institution_id, 'user_id' => $actor->id, 'action' => 'application.access_changed', 'auditable_type' => User::class, 'auditable_id' => $target->id, 'changes' => ['application' => $application, 'allowed' => $allowed]]);
        });
        app(PanelAccessSnapshot::class)->forget();
    }
}
