<?php

declare(strict_types=1);

namespace Modules\CollaborationCore\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\CollaborationCore\Contracts\DeviceRegistry;
use Modules\CollaborationCore\Data\ActorContext;
use Modules\CollaborationCore\Enums\AuthenticationLevel;
use Modules\CollaborationCore\Exceptions\ContractException;

final class WidgetSessions
{
    public function __construct(private WidgetApplications $apps, private DeviceRegistry $devices) {}

    public function issue(ActorContext $actor, string $app, string $origin, string $challenge): string
    {
        $application = $this->apps->resolve($app, $origin);
        $this->devices->assertSessionBinding($actor);
        if ((int) $application->institution_id !== $actor->institutionId || ! preg_match('/^[A-Za-z0-9_-]{43}$/D', $challenge)) {
            throw new ContractException('forbidden', 403);
        }
        $code = bin2hex(random_bytes(32));
        DB::table('collab_widget_grants')->insert(['hash' => hash('sha256', $code), 'app_id' => $app, 'origin' => $origin, 'device_id' => $actor->deviceId, 'challenge' => $challenge, 'expires_at' => now()->addSeconds(60)]);

        return $code;
    }

    /** @return array<string,mixed> */
    public function exchange(string $app, string $origin, #[\SensitiveParameter] string $code, #[\SensitiveParameter] string $verifier): array
    {
        $application = $this->apps->resolve($app, $origin);
        if (! preg_match('/^[A-Za-z0-9._~-]{43,128}$/D', $verifier)) {
            throw new ContractException('invalid', 422);
        }

        return DB::transaction(function () use ($application, $app, $origin, $code, $verifier): array {
            $grant = DB::table('collab_widget_grants')->where('hash', hash('sha256', $code))->lockForUpdate()->first();
            $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
            if (! $grant || $grant->consumed_at || \Illuminate\Support\Carbon::parse($grant->expires_at)->isPast()
                || $grant->app_id !== $app || $grant->origin !== $origin || ! hash_equals($grant->challenge, $challenge)) {
                throw new ContractException('unauthenticated', 401);
            }
            $actor = $this->actor($grant->device_id);
            if ($actor->institutionId !== (int) $application->institution_id) {
                throw new ContractException('forbidden', 403);
            }
            DB::table('collab_widget_grants')->where('hash', $grant->hash)->update(['consumed_at' => now()]);
            $id = (string) Str::ulid();
            $token = bin2hex(random_bytes(32));
            DB::table('collab_widget_sessions')->insert(['id' => $id, 'app_id' => $app, 'origin' => $origin, 'device_id' => $actor->deviceId, 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addSeconds((int) config('collaboration_widget.token_seconds')), 'absolute_expires_at' => now()->addHours(8), 'created_at' => now()]);

            return ['instance_id' => $id, 'token' => $token, 'expires_in' => (int) config('collaboration_widget.token_seconds')];
        }, 3);
    }

    /** @return array{actor:ActorContext,session:object,application:object} */
    public function authenticate(#[\SensitiveParameter] string $token, string $instance, string $app, string $origin): array
    {
        $application = $this->apps->resolve($app, $origin);
        $row = DB::table('collab_widget_sessions')->where('id', $instance)->where('token_hash', hash('sha256', $token))->first();
        if (! $row || $row->revoked_at || $row->app_id !== $app || $row->origin !== $origin
            || \Illuminate\Support\Carbon::parse($row->expires_at)->isPast() || \Illuminate\Support\Carbon::parse($row->absolute_expires_at)->isPast()) {
            throw new ContractException('session_revoked', 401);
        }
        $actor = $this->actor($row->device_id);
        if ($actor->institutionId !== (int) $application->institution_id) {
            throw new ContractException('forbidden', 403);
        }

        return ['actor' => $actor, 'session' => $row, 'application' => $application];
    }

    public function actor(string $device): ActorContext
    {
        $row = DB::table('collab_devices')->where('id', $device)->first();
        if (! $row) {
            throw new ContractException('device_revoked', 403);
        }
        $actor = new ActorContext((int) $row->institution_id, (int) $row->user_id, $row->id, $row->session_hash, (string) Str::ulid(), AuthenticationLevel::Mfa);
        $this->devices->assertSessionBinding($actor);

        return $actor;
    }

    /** @return array<string,mixed> */
    public function renew(ActorContext $actor, object $session): array
    {
        return DB::transaction(function () use ($actor, $session): array {
            $row = DB::table('collab_widget_sessions')->where('id', $session->id)->lockForUpdate()->firstOrFail();
            $this->devices->assertSessionBinding($actor);
            if ($row->revoked_at || ! hash_equals($row->token_hash, $session->token_hash)
                || \Illuminate\Support\Carbon::parse($row->expires_at)->isPast() || \Illuminate\Support\Carbon::parse($row->absolute_expires_at)->isPast()) {
                throw new ContractException('session_revoked', 401);
            }
            $token = bin2hex(random_bytes(32));
            DB::table('collab_widget_sessions')->where('id', $row->id)->update(['token_hash' => hash('sha256', $token), 'expires_at' => now()->addSeconds((int) config('collaboration_widget.token_seconds'))]);

            return ['token' => $token, 'expires_in' => (int) config('collaboration_widget.token_seconds')];
        }, 3);
    }

    public function revoke(ActorContext $actor, string $instance): void
    {
        $this->devices->assertSessionBinding($actor);
        $deviceIds = DB::table('collab_devices')->where('institution_id', $actor->institutionId)->where('user_id', $actor->userId)->pluck('id');
        DB::table('collab_widget_sessions')->where('id', $instance)->whereIn('device_id', $deviceIds)->whereNull('revoked_at')->update(['revoked_at' => now()]);
    }
}
