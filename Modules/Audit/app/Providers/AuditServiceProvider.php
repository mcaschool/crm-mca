<?php

declare(strict_types=1);

namespace Modules\Audit\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Modules\Audit\Livewire\Logs\Index as LogsIndex;
use Modules\Audit\Models\AuditLog;
use Modules\Audit\Policies\AuditLogPolicy;
use Nwidart\Modules\Support\ModuleServiceProvider;

class AuditServiceProvider extends ModuleServiceProvider
{
    /**
     * The name of the module.
     */
    protected string $name = 'Audit';

    /**
     * The lowercase version of the module name.
     */
    protected string $nameLower = 'audit';

    /**
     * Politica de acceso (solo Admin) y componente de la vista de auditoria.
     */
    public function boot(): void
    {
        parent::boot();

        Gate::policy(AuditLog::class, AuditLogPolicy::class);

        Livewire::component('audit.logs.index', LogsIndex::class);
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
