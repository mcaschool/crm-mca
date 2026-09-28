<div style="display:inline-flex;align-items:center;gap:8px">
    <label for="advisor-selector" class="t-mut" style="font-size:12.5px;font-weight:600;display:inline-flex;align-items:center;gap:5px">
        <x-ui.icon name="bot" class="ic" style="width:15px;height:15px;color:var(--mca,#1E5AA8)" /> {{ __('Agente') }}
    </label>
    @if ($options->isEmpty())
        <span class="t-mut" style="font-size:13px">{{ __('No hay agentes activos.') }}</span>
    @else
        <select id="advisor-selector" wire:model.live="botId"
            style="font-size:13px;border:1px solid var(--line);border-radius:8px;padding:7px 10px;background:#fff;min-width:180px">
            @foreach ($options as $opt)
                <option value="{{ $opt->id }}">{{ $opt->assistant_name ?: $opt->slug }}</option>
            @endforeach
        </select>
        <span wire:loading wire:target="botId" class="mca-spin"></span>
    @endif
</div>
