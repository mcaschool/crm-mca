{{--
  Estado de un lead como insignia del sistema (x-ui.kit): un único mapa estado → tono
  para listados, inicio y fichas. Recibe el enum Modules\Crm\Enums\LeadStatus.
--}}
@props(['status'])
@php
    $tone = match ($status->value) {
        'new' => 'blue',
        'contacted' => 'teal',
        'qualified' => 'warning',
        'enrolled' => 'success',
        default => 'neutral',
    };
@endphp
<x-ui.badge :tone="$tone" dot {{ $attributes }}>{{ __($status->label()) }}</x-ui.badge>
