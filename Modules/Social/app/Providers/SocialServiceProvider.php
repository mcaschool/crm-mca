<?php

declare(strict_types=1);

namespace Modules\Social\Providers;

use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Modules\Social\Console\AdvisorWorkerCommand;
use Modules\Social\Console\MetaConnectionsCheckCommand;
use Modules\Social\Console\MetaLeadCheckCommand;
use Modules\Social\Console\MetaLeadsPollCommand;
use Modules\Social\Livewire\AdvisorChannels;
use Modules\Social\Livewire\Channels;
use Modules\Social\Livewire\Inbox;
use Modules\Social\Livewire\MetaConnect;
use Modules\Social\Livewire\Publisher;
use Modules\Social\Models\SocialChannel;
use Modules\Social\Policies\SocialChannelPolicy;
use Modules\Social\Support\MetaDemoGraph;
use Nwidart\Modules\Support\ModuleServiceProvider;

/**
 * Módulo Social — Bandeja unificada (WhatsApp, Instagram, Messenger).
 * Bloque 1: esquema + modelos (el proveedor base auto-carga database/migrations).
 * Bloque 2: bandeja UI (componente Livewire full-page Inbox).
 * Bloque 3: webhook directo Meta (rutas API vía RouteServiceProvider).
 */
class SocialServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'Social';

    protected string $nameLower = 'social';

    /** @var array<int, class-string> */
    protected array $providers = [
        RouteServiceProvider::class,
    ];

    /** @var array<int, class-string> */
    protected array $commands = [
        AdvisorWorkerCommand::class,
        MetaLeadCheckCommand::class,
        MetaLeadsPollCommand::class,
        MetaConnectionsCheckCommand::class,
    ];

    public function boot(): void
    {
        parent::boot();

        Gate::policy(SocialChannel::class, SocialChannelPolicy::class);

        // Atajo SOLO-LOCAL para recorrer Formularios publicitarios sin Meta real.
        if ($this->app->environment('local') && config('social.meta.fake_graph') === 'demo') {
            MetaDemoGraph::register();
        }

        Livewire::component('social.inbox', Inbox::class);
        Livewire::component('social.publisher', Publisher::class);
        Livewire::component('social.channels', Channels::class);
        Livewire::component('social.advisor-channels', AdvisorChannels::class);
        Livewire::component('social.meta-connect', MetaConnect::class);
    }
}
