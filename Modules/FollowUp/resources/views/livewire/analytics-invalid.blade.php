<div class="mca-page fu-shell">
    <x-ui.page-header title="Analítica comercial" subtitle="Revisa los filtros para consultar datos verificables." />
    @include('followup::partials.navigation', ['active'=>'analytics'])
    <section class="ui-card">
        <p role="alert">No se muestran indicadores hasta corregir los filtros.</p>
        @foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach
        <div class="fu-filters">
            <label>Desde<input class="ui-input" type="date" wire:model.live="from"></label>
            <label>Hasta<input class="ui-input" type="date" wire:model.live="to"></label>
        </div>
        <x-ui.button wire:click="$set('filters', [])">Restablecer segmentos</x-ui.button>
    </section>
</div>
