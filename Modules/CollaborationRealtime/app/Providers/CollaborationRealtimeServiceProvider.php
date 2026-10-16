<?php

declare(strict_types=1);

namespace Modules\CollaborationRealtime\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\CollaborationRealtime\Contracts;
use Modules\CollaborationRealtime\Integration\WakeTransport;
use Modules\CollaborationRealtime\Services;
use Modules\CollaborationRealtime\Transport\ReverbWakeTransport;

final class CollaborationRealtimeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Services\EventPolicies::class);
        $this->app->bind(\Modules\CollaborationCore\Contracts\RevocationRecorder::class, Services\RevocationEvents::class);
        $this->app->bind(Contracts\TransactionalEventRecorder::class, Services\DatabaseEventRecorder::class);
        $this->app->bind(Contracts\SyncFeed::class, Services\DatabaseSyncFeed::class);
        $this->app->bind(Contracts\RealtimePublisher::class, Services\ReverbPublisher::class);
        $this->app->bind(WakeTransport::class, ReverbWakeTransport::class);
    }

    public function boot(): void
    {
        foreach (Services\RevocationPolicy::TYPES as $type) {
            app(Services\EventPolicies::class)->register($type, new Services\RevocationPolicy);
        }
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
        $this->loadRoutesFrom(__DIR__.'/../../routes/web.php');
        $this->commands([\Modules\CollaborationRealtime\Console\RealtimeCommand::class]);
    }
}
