<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    {{-- Enlace privado: no se indexa y nunca se filtra por el Referer al abrir un enlace. --}}
    <meta name="robots" content="noindex, nofollow, noarchive">
    <meta name="referrer" content="no-referrer">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? __('Probar asesor') }}</title>
    <x-ui.tokens />
</head>
<body class="pv-body">
    {{ $slot }}
</body>
</html>
