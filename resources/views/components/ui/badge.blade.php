{{--
  Insignia (x-ui.kit). tone: blue · teal · success · warning · danger · neutral · gold · deep.
  `dot` para estados; `icon` para categorías; `pulse` anima el punto (actividad nueva).
--}}
@props(['tone' => 'neutral', 'dot' => false, 'icon' => null, 'pulse' => false])
<span {{ $attributes->merge(['class' => 'ui-badge ui-tone--'.$tone.($pulse ? ' ui-badge--pulse' : '')]) }}>@if ($dot)<span class="dot" aria-hidden="true"></span>@endif @if ($icon)<x-ui.icon :name="$icon" />@endif{{ $slot }}</span>
