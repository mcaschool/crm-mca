<?php

declare(strict_types=1);

namespace Modules\Social\Providers;

use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Modules\Social\Livewire\Channels;
use Modules\Social\Livewire\Inbox;
use Modules\Social\Livewire\Publisher;
use Modules\Social\Models\SocialChannel;
use Modules\Social\Policies\SocialChannelPolicy;
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

    public function boot(): void
    {
        parent::boot();

        Gate::policy(SocialChannel::class, SocialChannelPolicy::class);

        Livewire::component('social.inbox', Inbox::class);
        Livewire::component('social.publisher', Publisher::class);
        Livewire::component('social.channels', Channels::class);
    }
}
