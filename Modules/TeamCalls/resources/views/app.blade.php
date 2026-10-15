{{--
  TeamCalls · página de llamadas dentro del panel del CRM (fase 3C/3D, SOLO desarrollo). El layout y la
  cabecera son del CRM; la interfaz de llamadas es el parcial reutilizable teamcalls::partials.calls.
--}}
<x-app-layout>
    <div class="mca-page tc-page">
        <x-ui.page-header :title="__('teamcalls.ui.title')" :subtitle="__('teamcalls.ui.subtitle')">
            <x-slot name="badge"><x-ui.badge tone="gold">{{ __('teamcalls.ui.dev_badge') }}</x-ui.badge></x-slot>
        </x-ui.page-header>

        @include('teamcalls::partials.calls', ['config' => $config, 'assets' => $assets])
    </div>
</x-app-layout>
