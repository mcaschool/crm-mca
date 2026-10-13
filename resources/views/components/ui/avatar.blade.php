{{--
  Avatar (x-ui.kit): foto si existe; si no, iniciales sobre un tono de la paleta MCA
  elegido de forma estable a partir de `seed` (o del nombre), para distinguir personas
  de un vistazo sin colores ajenos a la marca.
--}}
@props(['name' => '', 'src' => null, 'seed' => null, 'size' => 'md', 'square' => false])
@php
    $tones = ['blue', 'teal', 'gold', 'success', 'deep', 'neutral'];
    $tone = $tones[crc32((string) ($seed ?? $name)) % count($tones)];
    $parts = preg_split('/\s+/u', trim((string) $name)) ?: [];
    $initials = mb_strtoupper(mb_substr($parts[0] ?? '', 0, 1).mb_substr(count($parts) > 1 ? end($parts) : '', 0, 1));
@endphp
<span {{ $attributes->merge(['class' => 'ui-avatar ui-tone--'.$tone.($size === 'lg' ? ' ui-avatar--lg' : '').($square ? ' ui-avatar--sq' : '')]) }} aria-hidden="true">
    @if ($src)
        <img src="{{ $src }}" alt="">
    @elseif ($initials !== '')
        {{ $initials }}
    @else
        <x-ui.icon name="user" />
    @endif
</span>
