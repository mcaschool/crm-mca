<div class="ui-owner-pick">
    <label for="advisor-selector" class="ui-btn-label">
        <x-ui.icon name="bot" class="ui-inline-ic" /> {{ __('Agente') }}
    </label>
    @if ($options->isEmpty())
        <span class="ui-help">{{ __('No hay agentes activos.') }}</span>
    @else
        <select id="advisor-selector" wire:model.live="botId" class="ui-select">
            @foreach ($options as $opt)
                <option value="{{ $opt->id }}">{{ $opt->assistant_name ?: $opt->slug }}</option>
            @endforeach
        </select>
        <span wire:loading wire:target="botId" class="ui-spin" aria-hidden="true"></span>
    @endif
</div>
