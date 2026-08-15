<?php

declare(strict_types=1);

namespace Modules\Catalog\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Modules\Catalog\Console\ImportCatalogCommand;
use Modules\Catalog\Livewire\Categories\Manage as CategoriesManage;
use Modules\Catalog\Livewire\Programs\Form as ProgramsForm;
use Modules\Catalog\Livewire\Programs\Index as ProgramsIndex;
use Modules\Catalog\Models\Program;
use Modules\Catalog\Policies\ProgramPolicy;
use Nwidart\Modules\Support\ModuleServiceProvider;

class CatalogServiceProvider extends ModuleServiceProvider
{
    /**
     * The name of the module.
     */
    protected string $name = 'Catalog';

    /**
     * The lowercase version of the module name.
     */
    protected string $nameLower = 'catalog';

    /**
     * Command classes to register.
     *
     * @var string[]
     */
    protected array $commands = [
        ImportCatalogCommand::class,
    ];

    /**
     * Autorizacion y componentes del panel del modulo.
     */
    public function boot(): void
    {
        parent::boot();

        Gate::policy(Program::class, ProgramPolicy::class);

        Livewire::component('catalog.programs.index', ProgramsIndex::class);
        Livewire::component('catalog.programs.form', ProgramsForm::class);
        Livewire::component('catalog.categories.manage', CategoriesManage::class);
    }

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
