@props(['align' => 'right', 'width' => '48', 'contentClasses' => 'py-1 bg-white'])

@php
$alignmentClasses = match ($align) {
    'left' => 'ltr:origin-top-left rtl:origin-top-right start-0',
    'top' => 'origin-top',
    default => 'ltr:origin-top-right rtl:origin-top-left end-0',
};

// Anchos en rem: el CSS compilado de Tailwind solo incluye w-48, así que «60» y «72» (las
// campanas de la barra superior) se fijan con estilo en línea en lugar de una clase inexistente.
$widthRem = match ((string) $width) {
    '48' => 12,
    '56' => 14,
    '60' => 15,
    '64' => 16,
    '72' => 18,
    '80' => 20,
    '96' => 24,
    default => null,
};
$width = $widthRem === null ? $width : '';
@endphp

<div class="relative" x-data="{ open: false }" @click.outside="open = false" @close.stop="open = false">
    <div @click="open = ! open">
        {{ $trigger }}
    </div>

    <div x-show="open"
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="opacity-0 scale-95"
            x-transition:enter-end="opacity-100 scale-100"
            x-transition:leave="transition ease-in duration-75"
            x-transition:leave-start="opacity-100 scale-100"
            x-transition:leave-end="opacity-0 scale-95"
            class="absolute z-50 mt-2 {{ $width }} rounded-md shadow-lg {{ $alignmentClasses }}"
            @if (($widthRem ?? 0) >= 15) data-dd-wide @endif
            style="display: none;{{ $widthRem !== null ? ' width: '.$widthRem.'rem; max-width: calc(100vw - 24px);' : '' }}"
            @click="open = false">
        <div class="rounded-md ring-1 ring-black ring-opacity-5 {{ $contentClasses }}">
            {{ $content }}
        </div>
    </div>
</div>
