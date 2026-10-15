<?php

declare(strict_types=1);

namespace Modules\CollaborationCore\Console;

use Illuminate\Support\Facades\DB;
use Modules\Core\Console\TenantAwareCommand;

final class PruneCollaboration extends TenantAwareCommand
{
    protected $signature = 'collaboration:prune {--institution=} {--all-institutions}';

    protected $description = 'Remove expired presence and notification records; retain device revocation tombstones.';

    protected function handleForInstitution(int $institutionId): int
    {
        foreach (['collab_presence_devices', 'collab_presence_calls'] as $table) {
            DB::table($table)->where('institution_id', $institutionId)->where('expires_at', '<=', now())->delete();
        }
        DB::table('collab_notification_intents')->where('institution_id', $institutionId)->where('expires_at', '<=', now())->update(['status' => 'expired']);
        DB::table('collab_notification_intents')->where('institution_id', $institutionId)->where('expires_at', '<', now()->subDays(30))->delete();

        // Keep hashed device tombstones: never allow a revoked session to re-enroll.
        // Session-less history can later be archived under an explicit retention policy.
        return self::SUCCESS;
    }
}
