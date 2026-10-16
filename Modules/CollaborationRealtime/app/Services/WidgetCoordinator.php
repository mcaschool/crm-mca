<?php

declare(strict_types=1);

namespace Modules\CollaborationRealtime\Services;

use Illuminate\Support\Facades\DB;
use Modules\CollaborationCore\Contracts\DeviceRegistry;
use Modules\CollaborationCore\Data\ActorContext;
use Modules\CollaborationCore\Exceptions\ContractException;
use Modules\CollaborationCore\Services\WidgetPreferences;
use Modules\CollaborationRealtime\Storage\WidgetStateStore;

final class WidgetCoordinator
{
    public function __construct(private WidgetStateStore $store, private DeviceRegistry $devices, private NotificationInbox $inbox, private WidgetPreferences $preferences) {}

    private function key(ActorContext $a): string
    {
        return $a->institutionId.':'.$a->userId.':'.$a->deviceId;
    }

    /**
     * @param  array<string,mixed>  $activity
     * @return array<string,mixed>
     */
    public function heartbeat(ActorContext $actor, object $session, array $activity): array
    {
        $this->devices->assertSessionBinding($actor);
        $key = $this->key($actor);
        $now = now()->timestamp;
        $state = $this->store->mutate($key, 86400, function (array $state) use ($session, $activity, $now): array {
            $instances = array_filter($state['instances'] ?? [], fn ($i) => $i['until'] > $now);
            $old = $instances[$session->id] ?? [];
            $instances[$session->id] = ['app' => $session->app_id, 'origin' => $session->origin, 'until' => $now + (int) config('collaboration_widget.instance_seconds'), 'visible' => (bool) ($activity['visible'] ?? false), 'focused' => (bool) ($activity['focused'] ?? false), 'active_at' => ! empty($activity['interacted']) ? $now : ($old['active_at'] ?? 0)];
            if (count($instances) > 30) {
                throw new ContractException('too_many_requests', 429);
            }
            $state['instances'] = $instances;

            return $state;
        });

        return ['preferred' => $this->preferred($actor, $state), 'heartbeat_seconds' => 30, 'expires_in' => (int) config('collaboration_widget.instance_seconds')];
    }

    /** @param array<string,mixed> $state */
    private function preferred(ActorContext $actor, array $state): ?string
    {
        $ids = array_keys($state['instances'] ?? []);
        $rows = DB::table('collab_widget_sessions as s')->join('collab_widget_apps as a', 'a.id', '=', 's.app_id')->whereIn('s.id', $ids)->where('s.device_id', $actor->deviceId)->whereNull('s.revoked_at')->where('s.expires_at', '>', now())->where('s.absolute_expires_at', '>', now())->where('a.enabled', true)->select('s.id', 's.origin', 'a.origins', 'a.capabilities')->get();
        $valid = [];
        foreach ($rows as $row) {
            if (in_array($row->origin, json_decode($row->origins, true), true) && in_array('notifications', json_decode($row->capabilities, true), true)) {
                $valid[] = $row->id;
            }
        }
        $candidates = array_filter($state['instances'] ?? [], fn ($i, $id) => $i['until'] > now()->timestamp && in_array($id, $valid, true), ARRAY_FILTER_USE_BOTH);
        uksort($candidates, function ($a, $b) use ($candidates): int {
            $x = $candidates[$a];
            $y = $candidates[$b];

            return [$y['visible'], $y['focused'], $y['active_at'], $b] <=> [$x['visible'], $x['focused'], $x['active_at'], $a];
        });

        return array_key_first($candidates);
    }

    /** @return array<string,mixed> */
    public function claim(ActorContext $actor, object $session, string $notification): array
    {
        $this->devices->assertSessionBinding($actor);
        $intent = collect($this->inbox->current($actor))->firstWhere('id', $notification);
        if (! $intent) {
            throw new ContractException('not_found', 404);
        }
        $prefs = $this->preferences->get($actor, $intent['resource_id']);
        if ($prefs['muted'] || (! $prefs['visual'] && ! $prefs['sound'])) {
            return ['claimed' => false, 'reason' => 'muted'];
        }
        $key = $this->key($actor);
        $now = now()->timestamp;
        $result = ['claimed' => false];
        $this->store->mutate($key, 86400, function (array $state) use ($actor, $session, $intent, $now, $prefs, &$result): array {
            $state['alerts'] = array_filter($state['alerts'] ?? [], fn ($r) => $r['expires'] > $now);
            $event = $intent['source_event_id'];
            $old = $state['alerts'][$event] ?? null;
            if ($old && ($old['done'] || $old['until'] > $now)) {
                $result = ['claimed' => false, 'reason' => $old['done'] ? 'processed' : 'leased'];

                return $state;
            }
            if ($this->preferred($actor, $state) !== $session->id) {
                return $state;
            }
            $lease = bin2hex(random_bytes(24));
            $state['alerts'][$event] = ['owner' => $session->id, 'lease' => hash('sha256', $lease), 'until' => $now + (int) config('collaboration_widget.lease_seconds'), 'expires' => \Illuminate\Support\Carbon::parse($intent['expires_at'])->timestamp, 'done' => false];
            $result = ['claimed' => true, 'lease' => $lease, 'event_id' => $event, 'visual' => $prefs['visual'], 'sound' => $prefs['sound']];

            return $state;
        });

        return $result;
    }

    public function acknowledge(ActorContext $actor, object $session, string $event, #[\SensitiveParameter] string $lease): void
    {
        $this->devices->assertSessionBinding($actor);
        // Recheck current event eligibility and mute preferences immediately before display.
        $intent = collect($this->inbox->current($actor))->firstWhere('source_event_id', $event);
        if (! $intent || $this->preferences->get($actor, $intent['resource_id'])['muted']) {
            throw new ContractException('forbidden', 403);
        }
        $this->store->mutate($this->key($actor), 86400, function (array $state) use ($session, $event, $lease): array {
            $row = $state['alerts'][$event] ?? null;
            if (! $row || $row['owner'] !== $session->id || ! hash_equals($row['lease'], hash('sha256', $lease)) || (! $row['done'] && $row['until'] < now()->timestamp)) {
                throw new ContractException('forbidden', 403);
            }
            $state['alerts'][$event]['done'] = true;

            return $state;
        });
    }

    public function remove(ActorContext $actor, string $instance): void
    {
        $this->devices->assertSessionBinding($actor);
        $this->store->mutate($this->key($actor), 86400, function (array $state) use ($instance): array {
            unset($state['instances'][$instance]);
            foreach ($state['alerts'] ?? [] as $key => $row) {
                if (! $row['done'] && $row['owner'] === $instance) {
                    $state['alerts'][$key]['until'] = 0;
                }
            }

            return $state;
        });
    }
}
