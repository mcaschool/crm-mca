<?php

declare(strict_types=1);

namespace Modules\TeamCalls\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Gate;
use Modules\TeamCalls\Console\ExpireStaleCallsCommand;
use Modules\TeamCalls\Contracts\CallEventPublisher;
use Modules\TeamCalls\Contracts\CallsGateway;
use Modules\TeamCalls\Contracts\ConversationAccess;
use Modules\TeamCalls\Contracts\MediaRoomService;
use Modules\TeamCalls\Contracts\Presence;
use Modules\TeamCalls\Contracts\PushNotifier;
use Modules\TeamCalls\Contracts\UserDirectory;
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
 * TeamCalls (MCA Colaboración). Sin rutas ni interfaz en esta fase: solo el motor de llamadas.
 *
 * Los puertos hacia CollaborationCore se enlazan con bindIf a adaptadores PROVISIONALES: cuando
 * CollaborationCore/TeamChat registren sus implementaciones, sustituyen a estas sin tocar TeamCalls.
 */
class TeamCallsServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'TeamCalls';

    protected string $nameLower = 'teamcalls';

    /** @var string[] */
    protected array $commands = [
        ExpireStaleCallsCommand::class,
    ];

    public function register(): void
    {
        parent::register();

        $this->app->bindIf(ConversationAccess::class, DenyAllConversationAccess::class);
        $this->app->bindIf(UserDirectory::class, PanelUserDirectory::class);
        $this->app->bindIf(Presence::class, NoopPresence::class);
        $this->app->bindIf(CallEventPublisher::class, NoopCallEventPublisher::class);
        $this->app->bindIf(PushNotifier::class, NoopPushNotifier::class);
        $this->app->bindIf(MediaRoomService::class, UnconfiguredMediaRooms::class);
        $this->app->bindIf(CallsGateway::class, TeamCallsGateway::class);
    }

    public function boot(): void
    {
        parent::boot();

        Gate::policy(Call::class, CallPolicy::class);
    }

    /** Invitaciones vencidas y conexiones que no llegan, cada minuto (mismo cron de schedule:run). */
    protected function configureSchedules(Schedule $schedule): void
    {
        $schedule->command('teamcalls:expire-stale')->everyMinute()->withoutOverlapping(5);
    }
}
