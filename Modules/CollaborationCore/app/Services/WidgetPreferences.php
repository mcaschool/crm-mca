<?php

declare(strict_types=1);

namespace Modules\CollaborationCore\Services;

use Illuminate\Support\Facades\DB;
use Modules\CollaborationCore\Contracts\CollaborationAccess;
use Modules\CollaborationCore\Data\ActorContext;

final class WidgetPreferences
{
    public function __construct(private CollaborationAccess $access, private NotificationPreferences $base) {}

    /** @return array{visual:bool,sound:bool,muted:bool,muted_until:?string} */
    public function get(ActorContext $actor, string $resource = ''): array
    {
        $this->access->authorize($actor, 'collaboration.use');
        $global = DB::table('collab_widget_preferences')->where('institution_id', $actor->institutionId)->where('user_id', $actor->userId)->where('resource_id', '')->first();
        $local = $resource !== '' ? DB::table('collab_widget_preferences')->where('institution_id', $actor->institutionId)->where('user_id', $actor->userId)->where('resource_id', $resource)->first() : null;
        $muted = ! $this->base->enabled($actor);
        foreach ([$global, $local] as $row) {
            if ($row && ($row->muted || ($row->muted_until && \Illuminate\Support\Carbon::parse($row->muted_until)->isFuture()))) {
                $muted = true;
            }
        }

        return ['visual' => (bool) ($global->visual ?? true), 'sound' => (bool) ($global->sound ?? false), 'muted' => $muted, 'muted_until' => $global->muted_until ?? null];
    }

    /** @param array<string,mixed> $data */
    public function set(ActorContext $actor, string $resource, array $data): void
    {
        $this->access->authorize($actor, 'collaboration.use');
        $changes = ['updated_at' => now()];
        foreach (['visual', 'sound', 'muted'] as $field) {
            if (array_key_exists($field, $data)) {
                $changes[$field] = (bool) $data[$field];
            }
        }
        if (array_key_exists('snooze_minutes', $data)) {
            $changes['muted_until'] = $data['snooze_minutes'] > 0 ? now()->addMinutes($data['snooze_minutes']) : null;
        }
        $row = array_merge(['institution_id' => $actor->institutionId, 'user_id' => $actor->userId, 'resource_id' => $resource,
            'visual' => true, 'sound' => false, 'muted' => false, 'muted_until' => null, 'created_at' => now()], $changes);
        DB::table('collab_widget_preferences')->upsert([$row], ['institution_id', 'user_id', 'resource_id'], array_keys($changes));
    }
}
