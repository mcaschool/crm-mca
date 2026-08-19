<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'Laravel') }}</title>

        <!-- Scripts / estilos compilados -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @livewireStyles

        {{-- Sistema de diseño MCA: tokens de marca + estilos del shell (patrón x-ui) --}}
        <x-ui.tokens />
        <x-ui.shell-styles />
        <style>[x-cloak]{display:none !important}</style>
    </head>
    <body class="antialiased" style="margin:0">
        @php
            // El título y su icono derivan de la SECCIÓN activa (misma pareja que el
            // sidebar), no se hardcodean por pantalla: al añadir páginas se mantiene solo.
            [$section, $sectionIcon] = match (true) {
                request()->routeIs('dashboard') => [__('Inicio'), 'home'],
                request()->routeIs('crm.leads.*') => [__('Leads'), 'users'],
                request()->routeIs('crm.contacts.*'), request()->routeIs('crm.conversations.*') => [__('Contactos'), 'contact'],
                request()->routeIs('advisors.*'), request()->routeIs('ai.*') => [__('Asesores Inteligentes'), 'sparkles'],
                request()->routeIs('catalog.*') => [__('Catálogo'), 'book-open'],
                request()->routeIs('users.*') => [__('Usuarios'), 'user-cog'],
                request()->routeIs('integrations.*') => [__('Integraciones'), 'plug'],
                request()->routeIs('audit.*') => [__('Auditoría'), 'shield'],
                request()->routeIs('settings.*') => [__('Ajustes'), 'user-cog'],
                request()->routeIs('profile.*') => [__('Mi perfil'), 'user'],
                default => [config('app.name', 'Panel'), 'home'],
            };
        @endphp

        <div class="mca-shell" x-data="{ sidebarOpen: false }">
            @include('layouts.navigation')

            <div class="mca-backdrop" :class="{ show: sidebarOpen }" @click="sidebarOpen = false" x-cloak></div>

            <div class="mca-main">
                <header class="mca-topbar">
                    <button type="button" class="mca-hamburger" @click="sidebarOpen = true" aria-label="{{ __('Abrir menú') }}">
                        <x-ui.icon name="menu" style="width:22px;height:22px" />
                    </button>
                    <h1 class="mca-topbar-title"><x-ui.icon name="{{ $sectionIcon }}" class="tico" /> {{ $section }}</h1>
                    <div class="sp"></div>

                    @if (config('crm.multi_institution') && auth()->user()->isSuperAdmin())
                        @php($__institutions = app(\Modules\Core\Tenancy\CurrentInstitution::class)->runGlobally(fn () => \Modules\Institutions\Models\Institution::orderBy('name')->get(['id', 'name'])))
                        @php($__activeId = app(\Modules\Core\Tenancy\CurrentInstitution::class)->id())
                        <x-dropdown align="right" width="60">
                            <x-slot name="trigger">
                                <button class="mca-inst">
                                    {{ optional($__institutions->firstWhere('id', $__activeId))->name ?? __('Institución') }}
                                    <x-ui.icon name="chevron-down" style="width:14px;height:14px" />
                                </button>
                            </x-slot>
                            <x-slot name="content">
                                @foreach ($__institutions as $__inst)
                                    <form method="POST" action="{{ route('institution.switch') }}">
                                        @csrf
                                        <input type="hidden" name="institution_id" value="{{ $__inst->id }}">
                                        <button type="submit"
                                            class="block w-full text-start px-4 py-2 text-sm leading-5 {{ $__inst->id === $__activeId ? 'text-indigo-600 font-semibold' : 'text-gray-700' }} hover:bg-gray-100">
                                            {{ $__inst->name }}
                                        </button>
                                    </form>
                                @endforeach
                            </x-slot>
                        </x-dropdown>
                    @endif

                    {{-- Aviso in-app de leads nuevos (sondeo ~10s): campana = toggle de sonido + pop-up. --}}
                    <livewire:crm.new-lead-notifier />

                    {{-- Selector de idioma (ES/EN): persiste en users.preferred_language, que SetLocale lee. --}}
                    <x-dropdown align="right" width="48">
                        <x-slot name="trigger">
                            <button type="button" class="lang" style="cursor:pointer;font-family:inherit">{{ strtoupper(app()->getLocale()) }} <x-ui.icon name="chevron-down" style="width:14px;height:14px" /></button>
                        </x-slot>
                        <x-slot name="content">
                            @foreach (['es' => 'Español', 'en' => 'English'] as $__code => $__label)
                                <form method="POST" action="{{ route('locale.switch') }}">
                                    @csrf
                                    <input type="hidden" name="lang" value="{{ $__code }}">
                                    <button type="submit"
                                        class="block w-full text-start px-4 py-2 text-sm leading-5 {{ app()->getLocale() === $__code ? 'text-indigo-600 font-semibold' : 'text-gray-700' }} hover:bg-gray-100">
                                        {{ $__label }}
                                    </button>
                                </form>
                            @endforeach
                        </x-slot>
                    </x-dropdown>
                </header>

                <main class="mca-content">
                    {{-- Marco común: cuerpo de la página + carril derecho reservado (vacío,
                         para métricas futuras). Todas las pantallas lo heredan de aquí. --}}
                    <div class="mca-canvas">
                        <div class="mca-canvas-body">
                            @isset($header)
                                <div style="padding:22px 24px 0">{{ $header }}</div>
                            @endisset

                            {{ $slot }}
                        </div>
                        <aside class="mca-rail" aria-hidden="true">
                            <div class="rail-ph"></div>
                        </aside>
                    </div>
                </main>
            </div>
        </div>

        @livewireScripts
    </body>
</html>
