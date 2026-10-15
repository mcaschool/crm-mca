<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, interactive-widget=resizes-content">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'Laravel') }}</title>

        <!-- Scripts / estilos compilados -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @livewireStyles

        {{-- Sistema de diseño MCA: tokens de marca + componentes compartidos + shell (patrón x-ui) --}}
        <x-ui.tokens />
        <x-ui.kit />
        <x-ui.shell-styles />
        <style>[x-cloak]{display:none !important}</style>
        @stack('styles')
    </head>
    <body class="antialiased" style="margin:0">
        @php
            // Migas de pan: sección del menú + página actual, desde un único mapa de rutas.
            $crumbs = \App\View\Breadcrumbs::for(request());
        @endphp

        <div class="mca-shell" x-data="{ sidebarOpen: false }">
            @include('layouts.navigation')

            <div class="mca-backdrop" :class="{ show: sidebarOpen }" @click="sidebarOpen = false" x-cloak></div>

            <div class="mca-main">
                <header class="mca-topbar">
                    <button type="button" class="mca-hamburger" @click="sidebarOpen = true" aria-label="{{ __('Abrir menú') }}">
                        <x-ui.icon name="menu" style="width:22px;height:22px" />
                    </button>
                    <nav class="mca-crumbs" aria-label="{{ __('Ruta de navegación') }}">
                        <x-ui.icon name="{{ $crumbs['icon'] }}" class="tico" />
                        <ol>
                            @foreach ($crumbs['items'] as $crumb)
                                <li>
                                    @if ($crumb['url'])
                                        <a href="{{ $crumb['url'] }}">{{ $crumb['label'] }}</a>
                                    @else
                                        <span aria-current="page">{{ $crumb['label'] }}</span>
                                    @endif
                                </li>
                            @endforeach
                        </ol>
                    </nav>
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

                    {{-- Avisos internos de la agenda (actividad asignada y recordatorios). --}}
                    <livewire:crm.agenda.alert-bell />

                    {{-- Campana de alertas de IA (solo administradores): servicio caído/restablecido. --}}
                    <livewire:ai.ai-alert-bell />

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
                    {{-- Marco común: el cuerpo de la página ocupa todo el ancho disponible
                         (hasta un máximo legible); cada página aporta su margen con .mca-page. --}}
                    <div class="mca-canvas">
                        <div class="mca-canvas-body">
                            @isset($header)
                                <div class="mca-page" style="padding-bottom:0">{{ $header }}</div>
                            @endisset

                            {{ $slot }}
                        </div>
                    </div>
                </main>
            </div>
        </div>

        {{-- Indicadores del menú al día desde cualquier pantalla (un solo sincronizador). --}}
        @auth
            @include('partials.panel-badges-sync')
        @endauth
        @livewireScripts
    </body>
</html>
