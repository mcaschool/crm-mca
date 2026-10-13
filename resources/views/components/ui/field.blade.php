{{--
  Campo de formulario (x-ui.kit): etiqueta, control (slot), error y ayuda. El mensaje de
  error se pasa ya resuelto (`:error="$errors->first('campo')"`) porque las validaciones de
  Livewire viven en la vista del componente, no en la sesión. `for` enlaza la etiqueta
  con el control y el control con su error (aria-describedby="{for}-error").
--}}
@props(['label', 'for' => null, 'error' => null, 'help' => null, 'required' => false, 'optional' => false, 'hint' => null])
<div {{ $attributes->merge(['class' => 'ui-field']) }}>
    <label class="ui-label" @if ($for) for="{{ $for }}" @endif>{{ $label }}@if ($required)<span class="req" aria-hidden="true">*</span>@endif @if ($hint)<span class="opt">· {{ $hint }}</span>@elseif ($optional)<span class="opt">· {{ __('opcional') }}</span>@endif</label>
    {{ $slot }}
    @if ($error)
        <p class="ui-error" @if ($for) id="{{ $for }}-error" @endif role="alert"><x-ui.icon name="alert-triangle" /> {{ $error }}</p>
    @endif
    @if ($help)
        <p class="ui-help">{{ $help }}</p>
    @endif
</div>
