<?php

declare(strict_types=1);

namespace Modules\CollaborationCore\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\CollaborationCore\Contracts\CollaborationAccess;
use Modules\CollaborationCore\Data\ActorContext;

final class NotificationPreferences
{
    public function __construct(private CollaborationAccess $access) {}

    public function enabled(ActorContext $actor): bool
    {
        $this->access->authorize($actor, 'collaboration.use');

        return $this->forUser($actor->institutionId, $actor->userId);
    }

    public function forUser(int $institutionId, int $userId): bool
    {
        return (bool) (DB::table('collab_notification_preferences')->where('institution_id', $institutionId)->where('user_id', $userId)->value('enabled') ?? true);
    }

    public function set(ActorContext $actor, bool $enabled): void
    {
        DB::transaction(function () use ($actor, $enabled): void {
            User::query()->whereKey($actor->userId)->lockForUpdate()->firstOrFail();
            $this->access->authorize($actor, 'collaboration.use');
            DB::table('collab_notification_preferences')->updateOrInsert(['institution_id' => $actor->institutionId, 'user_id' => $actor->userId], ['enabled' => $enabled, 'updated_at' => now(), 'created_at' => now()]);
        });
    }
}
