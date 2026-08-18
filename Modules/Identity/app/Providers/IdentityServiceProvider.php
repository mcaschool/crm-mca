<?php

declare(strict_types=1);

namespace Modules\Identity\Providers;

use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Modules\Identity\Livewire\Profile\MiPerfil;
use Modules\Identity\Livewire\Users\Form as UsersForm;
use Modules\Identity\Livewire\Users\Index as UsersIndex;
use Modules\Identity\Policies\UserPolicy;
use Nwidart\Modules\Support\ModuleServiceProvider;

class IdentityServiceProvider extends ModuleServiceProvider
{
    /**
     * The name of the module.
     */
    protected string $name = 'Identity';

    /**
     * The lowercase version of the module name.
     */
    protected string $nameLower = 'identity';

    /**
     * Autorizacion y componentes del panel del modulo.
     */
    public function boot(): void
    {
        parent::boot();

        // Politica de gestion de usuarios (no auto-descubierta: User vive en App\Models).
        Gate::policy(User::class, UserPolicy::class);

        // Entrada al panel: usuario activo. El gating fino lo hacen las Policies.
        Gate::define('access-panel', fn (User $user): bool => $user->canAccessPanel());

        // Componentes Livewire del modulo (namespace propio, no App\Livewire).
        Livewire::component('identity.users.index', UsersIndex::class);
        Livewire::component('identity.users.form', UsersForm::class);
        Livewire::component('identity.profile.mi-perfil', MiPerfil::class);
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
