<?php

declare(strict_types=1);

namespace Modules\TeamCalls\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Modules\CollaborationCore\Contracts\CallsGateway as SharedCallsGatewayContract;
use Modules\CollaborationCore\Contracts\ConversationDirectory;
use Modules\CollaborationCore\Contracts\PresenceService;
use Modules\CollaborationCore\Exceptions\ContractException;
use Modules\CollaborationCore\Integration\CallPresenceAuthorizer;
use Modules\CollaborationRealtime\Integration\RevocationConsumer;
use Modules\CollaborationRealtime\Services\EventPolicies;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\TeamCalls\Console\ExpireStaleCallsCommand;
use Modules\TeamCalls\Console\HealthCommand;
use Modules\TeamCalls\Console\SweepMediaRoomsCommand;
use Modules\TeamCalls\Contracts\CallEventPublisher;
use Modules\TeamCalls\Contracts\CallsGateway;
use Modules\TeamCalls\Contracts\ConversationAccess;
use Modules\TeamCalls\Contracts\MediaRoomService;
use Modules\TeamCalls\Contracts\Presence;
use Modules\TeamCalls\Contracts\PushNotifier;
use Modules\TeamCalls\Contracts\UserDirectory;
use Modules\TeamCalls\Http\CallResponse;
use Modules\TeamCalls\Integration\Collaboration\CallEventEnvelopes;
use Modules\TeamCalls\Integration\Collaboration\CallEventPolicy;
use Modules\TeamCalls\Integration\Collaboration\CollaborationPresence;
use Modules\TeamCalls\Integration\Collaboration\CollaborationUserDirectory;
use Modules\TeamCalls\Integration\Collaboration\ConversationDirectoryAccess;
use Modules\TeamCalls\Integration\Collaboration\OperationContext;
use Modules\TeamCalls\Integration\Collaboration\SharedCallsGateway;
use Modules\TeamCalls\Integration\Collaboration\TeamCallsPresenceAuthorizer;
use Modules\TeamCalls\Integration\Collaboration\TeamCallsRevocationConsumer;
use Modules\TeamCalls\Integration\LiveKit\LiveKitConfig;
use Modules\TeamCalls\Integration\LiveKit\LiveKitMediaRooms;
use Modules\TeamCalls\Integration\LiveKit\LiveKitRoomClient;
use Modules\TeamCalls\Integration\LiveKit\LiveKitTokens;
use Modules\TeamCalls\Integration\LiveKit\MediaHealth;
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
        HealthCommand::class,
    ];

    public function register(): void
    {
        parent::register();

        // Correlación y actor de la operación en curso (contrato común): uno por petición o trabajo.
        $this->app->scopedIf(OperationContext::class);

        // Con los contratos de CollaborationCore enlazados (directorio de conversaciones, presencia), sus
        // adaptadores; sin ellos, los provisionales de siempre (conversaciones denegadas, presencia nula).
        $this->app->bindIf(ConversationAccess::class, fn ($app): ConversationAccess => $app->bound(ConversationDirectory::class)
            ? new ConversationDirectoryAccess($app->make(ConversationDirectory::class), $app->make(OperationContext::class))
            : new DenyAllConversationAccess);
        // Permiso definitivo (collaboration.use) solo si se elige expresamente; por defecto, el provisional.
        $this->app->bindIf(UserDirectory::class, fn ($app): UserDirectory => config('teamcalls.authorization.driver') === 'collaboration'
            ? new CollaborationUserDirectory
            : new PanelUserDirectory);
        $this->app->bindIf(Presence::class, fn ($app): Presence => $app->bound(PresenceService::class)
            ? new CollaborationPresence($app->make(PresenceService::class), $app->make(CurrentInstitution::class))
            : new NoopPresence);
        $this->app->bindIf(CallEventPublisher::class, NoopCallEventPublisher::class);
        $this->app->bindIf(PushNotifier::class, NoopPushNotifier::class);
        // LiveKit autohospedado solo si está elegido Y completo (falla cerrado: sin él, 503 media_unavailable).
        $this->app->bindIf(MediaRoomService::class, function (): MediaRoomService {
            $config = LiveKitConfig::fromConfig();
            if (config('teamcalls.media.driver') !== 'livekit' || ! $config->isComplete()) {
                return new UnconfiguredMediaRooms;
            }
            $tokens = new LiveKitTokens($config);
            $health = MediaHealth::fromConfig(); // disyuntor compartido (fase 4I)

            return new LiveKitMediaRooms($config, new LiveKitRoomClient($config, $tokens, $health), $tokens, $health);
        });
        $this->app->bindIf(CallsGateway::class, TeamCallsGateway::class);
        // Puerta del contrato común (MCA Colaboración 1.1), con el contexto autenticado de Core.
        $this->app->bindIf(SharedCallsGatewayContract::class, SharedCallsGateway::class);
        // Consumidor de las revocaciones durables (outbox de CollaborationRealtime → TeamCalls).
        $this->app->bindIf(RevocationConsumer::class, TeamCallsRevocationConsumer::class);
    }

    public function boot(): void
    {
        parent::boot();

        Gate::policy(Call::class, CallPolicy::class);

        // Señal «en llamada» de CollaborationCore: TeamCalls autoriza la participación activa (set) y la
        // asociación histórica (cleanup). Se enlaza en boot, como pide Core (su provisional deniega todo).
        $this->app->bind(CallPresenceAuthorizer::class, TeamCallsPresenceAuthorizer::class);

        // Políticas de CollaborationRealtime para cada tipo call.* (esquema cerrado + autorización actual).
        if ($this->app->bound(EventPolicies::class)) {
            $policies = $this->app->make(EventPolicies::class);
            foreach (CallEventEnvelopes::TYPES as $type => $ephemeral) {
                $policies->register($type, new CallEventPolicy($ephemeral));
            }
        }

        // Errores del contrato común en las rutas propias de llamadas (p. ej. el CSRF del widget universal rechaza
        // aquí sus credenciales delegadas): su código y estado, nunca un 500 genérico. El renderizador de Core
        // solo cubre /api/v1/collaboration.
        $this->app->make(ExceptionHandler::class)->renderable(function (ContractException $e, Request $request) {
            if (! $request->is('colaboracion/llamadas', 'colaboracion/llamadas/*', 'colaboracion/teamcalls', 'colaboracion/teamcalls/*')) {
                return null;
            }

            return $request->expectsJson() || $request->is('colaboracion/llamadas*')
                ? CallResponse::error($e->errorCode, $e->errorCode, $e->httpStatus)
                : response('', $e->httpStatus, ['Cache-Control' => 'no-store, private']);
        });

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
