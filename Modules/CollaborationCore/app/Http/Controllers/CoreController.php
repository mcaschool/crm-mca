<?php

declare(strict_types=1);

namespace Modules\CollaborationCore\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Modules\CollaborationCore\Contracts\DeviceRegistry;
use Modules\CollaborationCore\Contracts\PresenceService;
use Modules\CollaborationCore\Contracts\UserDirectory;
use Modules\CollaborationCore\Data\ActorContext;
use Modules\CollaborationCore\Data\PresenceSnapshot;
use Modules\CollaborationCore\Enums\ManualPresence;
use Modules\CollaborationCore\Services\ApplicationAccess;
use Modules\CollaborationCore\Services\NotificationPreferences;

final class CoreController
{
    private function actor(Request $r): ActorContext
    {
        return $r->attributes->get('collaboration.actor');
    }

    private function reply(Request $r, mixed $data): JsonResponse
    {
        return response()->json(['data' => $data, 'meta' => ['request_id' => $this->actor($r)->requestId, 'version' => '1.0']])->header('Cache-Control', 'no-store');
    }

    public function me(Request $r, UserDirectory $users): JsonResponse
    {
        $a = $this->actor($r);
        $u = $users->resolve($a, $a->userId);

        return $this->reply($r, ['id' => $u->id, 'institution_id' => $u->institutionId, 'display_name' => $u->displayName, 'device_id' => $a->deviceId, 'authentication_level' => $a->authenticationLevel->value]);
    }

    public function user(Request $r, UserDirectory $users, string $userId): JsonResponse
    {
        abort_unless(ctype_digit($userId), 404);
        $u = $users->resolve($this->actor($r), (int) $userId);

        return $this->reply($r, ['id' => $u->id, 'display_name' => $u->displayName]);
    }

    public function devices(Request $r, DeviceRegistry $devices): JsonResponse
    {
        $a = $this->actor($r);
        $data = [];
        foreach ($devices->listForCurrentUser($a) as $d) {
            $row = DB::table('collab_devices')->where('institution_id', $a->institutionId)->where('user_id', $a->userId)->where('id', $d->id)->firstOrFail();
            $data[] = ['id' => $d->id, 'platform' => $d->platform->value, 'status' => $d->status->value, 'session_valid' => app(\Modules\CollaborationCore\Services\DatabaseDeviceRegistry::class)->usable($row), 'label' => $row->label,
                'last_activity_at' => \Illuminate\Support\Carbon::parse($row->last_activity_at, 'UTC')->toISOString(),
                'expires_at' => \Illuminate\Support\Carbon::parse($row->expires_at, 'UTC')->toISOString(), 'current' => $d->id === $a->deviceId];
        }

        return $this->reply($r, $data);
    }

    public function revoke(Request $r, DeviceRegistry $devices, string $deviceId): JsonResponse
    {
        $devices->revoke($this->actor($r), $deviceId);

        return $this->reply($r, ['revoked' => true]);
    }

    public function heartbeat(Request $r, PresenceService $presence): JsonResponse
    {
        return $this->reply($r, $this->presenceJson($presence->heartbeat($this->actor($r))));
    }

    public function manual(Request $r, PresenceService $presence): JsonResponse
    {
        $v = $r->validate(['status' => ['required', Rule::enum(ManualPresence::class)]]);

        return $this->reply($r, $this->presenceJson($presence->setManualStatus($this->actor($r), ManualPresence::from($v['status']))));
    }

    public function presence(Request $r, PresenceService $presence): JsonResponse
    {
        $v = $r->validate(['user_ids' => ['required', 'array', 'min:1', 'max:100'], 'user_ids.*' => ['required', 'integer', 'min:1', 'distinct']]);

        return $this->reply($r, array_map($this->presenceJson(...), $presence->snapshot($this->actor($r), array_map('intval', $v['user_ids']))));
    }

    /** @return array<string,mixed> */
    private function presenceJson(PresenceSnapshot $p): array
    {
        $row = DB::table('collab_presence')->where('institution_id', $p->institutionId)->where('user_id', $p->userId)->first();
        $date = fn (?string $v) => $v ? \Illuminate\Support\Carbon::parse($v, 'UTC')->toISOString() : null;

        return ['user_id' => $p->userId, 'manual_status' => $p->manualStatus->value, 'online' => $p->online,
            'in_call' => $p->callId !== null, 'last_seen_at' => $p->lastSeenAt?->format('Y-m-d\\TH:i:s\\Z'),
            'last_activity_at' => $date($row?->last_activity_at), 'last_online_at' => $date($row?->last_online_at)];
    }

    public function preferences(Request $r, NotificationPreferences $preferences): JsonResponse
    {
        if ($r->isMethod('put')) {
            $v = $r->validate(['enabled' => ['required', 'boolean']]);
            $preferences->set($this->actor($r), (bool) $v['enabled']);
        }

        return $this->reply($r, ['enabled' => $preferences->enabled($this->actor($r))]);
    }

    public function identityAdministrator(Request $r, ApplicationAccess $access, string $userId): JsonResponse
    {
        $v = $r->validate(['enabled' => ['required', 'boolean']]);
        abort_unless(ctype_digit($userId), 404);
        $a = $this->actor($r);
        $target = User::query()->where('institution_id', $a->institutionId)->findOrFail((int) $userId);
        $access->setIdentityAdministrator(User::query()->findOrFail($a->userId), $target, (bool) $v['enabled']);

        return $this->reply($r, ['user_id' => (int) $userId, 'enabled' => (bool) $v['enabled']]);
    }

    public function grant(Request $r, ApplicationAccess $access, string $application, string $userId): JsonResponse
    {
        $v = $r->validate(['allowed' => ['required', 'boolean']]);
        abort_unless(ctype_digit($userId), 404);
        $a = $this->actor($r);
        $target = User::query()->where('institution_id', $a->institutionId)->findOrFail((int) $userId);
        $access->set(User::query()->findOrFail($a->userId), $target, $application, (bool) $v['allowed']);

        return $this->reply($r, ['application' => $application, 'user_id' => (int) $userId, 'allowed' => (bool) $v['allowed']]);
    }
}
