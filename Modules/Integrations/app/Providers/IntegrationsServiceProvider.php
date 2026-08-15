<?php

declare(strict_types=1);

namespace Modules\Integrations\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Modules\Integrations\Livewire\AiProcesses\Manage as AiProcessesManage;
use Modules\Integrations\Livewire\Integrations\Configure as IntegrationsConfigure;
use Modules\Integrations\Livewire\Integrations\Index as IntegrationsIndex;
use Modules\Integrations\Models\Integration;
use Modules\Integrations\Policies\IntegrationPolicy;
use Nwidart\Modules\Support\ModuleServiceProvider;

class IntegrationsServiceProvider extends ModuleServiceProvider
{
    /**
     * The name of the module.
     */
    protected string $name = 'Integrations';

    /**
     * The lowercase version of the module name.
     */
    protected string $nameLower = 'integrations';

    /**
     * Autorizacion y componentes del panel del modulo.
     */
    public function boot(): void
    {
        parent::boot();

        Gate::policy(Integration::class, IntegrationPolicy::class);

        Livewire::component('integrations.index', IntegrationsIndex::class);
        Livewire::component('integrations.configure', IntegrationsConfigure::class);
        Livewire::component('integrations.ai-processes', AiProcessesManage::class);
    }

    /**
     * Command classes to register.
     *
     * @var string[]
     */
    // protected array $commands = [];

    /**
     * Provider classes to register.
     *
     * @var string[]
     */
    protected array $providers = [
        EventServiceProvider::class,
        RouteServiceProvider::class,
    ];

    /**
     * Define module schedules.
     *
     * @param  $schedule
     */
    // protected function configureSchedules(Schedule $schedule): void
    // {
    //     $schedule->command('inspire')->hourly();
    // }
}
