<?php

declare(strict_types=1);

namespace Modules\TeamCalls\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Modules\TeamCalls\Console\ExpireStaleCallsCommand;
use Modules\TeamCalls\Console\SweepMediaRoomsCommand;
use Modules\TeamCalls\Contracts\CallEventPublisher;
use Modules\TeamCalls\Contracts\CallsGateway;
use Modules\TeamCalls\Contracts\ConversationAccess;
use Modules\TeamCalls\Contracts\MediaRoomService;
use Modules\TeamCalls\Contracts\Presence;
use Modules\TeamCalls\Contracts\PushNotifier;
use Modules\TeamCalls\Contracts\UserDirectory;
use Modules\TeamCalls\Integration\LiveKit\LiveKitConfig;
use Modules\TeamCalls\Integration\LiveKit\LiveKitMediaRooms;
use Modules\TeamCalls\Integration\LiveKit\LiveKitRoomClient;
use Modules\TeamCalls\Integration\LiveKit\LiveKitTokens;
use Modules\TeamCalls\Integration\Provisional\DenyAllConversationAccess;
use Modules\TeamCalls\Integration\Provisional\NoopCallEventPublisher;
use Modules\TeamCalls\Integration\Provisional\NoopPresence;
use Modules\TeamCalls\Integration\Provisional\NoopPushNotifier;
use Modules\TeamCalls\Integration\Provisional\PanelUserDirectory;
use Modules\TeamCalls\Integration\Provisional\UnconfiguredMediaRooms;
use Modules\TeamCalls\Integration\TeamCallsGateway;
use Modules\TeamCalls\Models\Call;
use Modules\TeamCalls\Policies\CallPolicy;
use Nwidart\Modules\Support\ModuleServiceProvider;

/**
 * TeamCalls (MCA Colaboración): motor de llamadas y su API web JSON (sin interfaz todavía).
 *
 * Los puertos hacia CollaborationCore se enlazan con bindIf a adaptadores PROVISIONALES: cuando
 * CollaborationCore/TeamChat registren sus implementaciones, sustituyen a estas sin tocar TeamCalls.
 */
class TeamCallsServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'TeamCalls';

    protected string $nameLower = 'teamcalls';

    /** @var string[] */
    protected array $providers = [
        RouteServiceProvider::class,
    ];

    /** @var string[] */
    protected array $commands = [
        ExpireStaleCallsCommand::class,
        SweepMediaRoomsCommand::class,
    ];

    public function register(): void
    {
        parent::register();

        $this->app->bindIf(ConversationAccess::class, DenyAllConversationAccess::class);
        $this->app->bindIf(UserDirectory::class, PanelUserDirectory::class);
        $this->app->bindIf(Presence::class, NoopPresence::class);
        $this->app->bindIf(CallEventPublisher::class, NoopCallEventPublisher::class);
        $this->app->bindIf(PushNotifier::class, NoopPushNotifier::class);
        // LiveKit autohospedado solo si está elegido Y completo (falla cerrado: sin él, 503 media_unavailable).
        $this->app->bindIf(MediaRoomService::class, function (): MediaRoomService {
            $config = LiveKitConfig::fromConfig();
            if (config('teamcalls.media.driver') !== 'livekit' || ! $config->isComplete()) {
                return new UnconfiguredMediaRooms;
            }
            $tokens = new LiveKitTokens($config);

            return new LiveKitMediaRooms($config, new LiveKitRoomClient($config, $tokens), $tokens);
        });
        $this->app->bindIf(CallsGateway::class, TeamCallsGateway::class);
    }

    public function boot(): void
    {
        parent::boot();

        Gate::policy(Call::class, CallPolicy::class);

        // Límites de la API web, por persona (o IP si aún no hay sesión).
        foreach (['start', 'write', 'read'] as $kind) {
            RateLimiter::for("teamcalls-{$kind}", fn (Request $request) => Limit::perMinute(max(1, (int) config("teamcalls.rate_limits.{$kind}", 60)))
                ->by("teamcalls-{$kind}:".($request->user()?->getAuthIdentifier() ?? $request->ip())));
        }
        // Webhooks del servidor de medios, por IP de origen.
        RateLimiter::for('teamcalls-webhook', fn (Request $request) => Limit::perMinute(max(1, (int) config('teamcalls.rate_limits.webhook', 600)))
            ->by('teamcalls-webhook:'.$request->ip()));
    }

    /** Invitaciones vencidas y conexiones que no llegan (cada minuto) y barrido de salas (cada 5), mismo cron. */
    protected function configureSchedules(Schedule $schedule): void
    {
        $schedule->command('teamcalls:expire-stale')->everyMinute()->withoutOverlapping(5);
        // Red de seguridad del servidor de medios (sin él configurado, el comando no hace nada).
        $schedule->command('teamcalls:media-sweep')->everyFiveMinutes()->withoutOverlapping(10);
    }
}
