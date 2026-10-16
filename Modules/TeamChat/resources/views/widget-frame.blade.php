<!doctype html><html lang="{{ app()->getLocale() }}"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="csrf-token" content="{{ csrf_token() }}">
<title>MCA Colaboración</title>@vite(['resources/css/app.css','Modules/TeamChat/resources/js/widget-frame.js'])
</head><body class="mca-widget-frame">
@php($en = app()->getLocale() === 'en')
<script type="application/json" id="widget-config">{!! json_encode($widget,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) !!}</script>
<header class="widget-toolbar"><strong>MCA Colaboración</strong><a href="{{ url('/colaboracion') }}" target="_blank" rel="noopener" data-full aria-label="{{ $en ? 'Open full screen' : 'Abrir pantalla completa' }}">↗</a><button data-widget-options type="button" aria-label="{{ $en ? 'Preferences' : 'Preferencias' }}">⚙</button><button data-widget-close type="button" aria-label="{{ $en ? 'Close' : 'Cerrar' }}">×</button></header>
<div data-widget-status role="status"></div><button data-widget-login type="button">{{ $en ? 'Connect' : 'Conectar' }}</button>
<section data-widget-settings hidden aria-label="{{ $en ? 'Notification preferences' : 'Preferencias de notificaciones' }}">
<label><input type="checkbox" data-visual> {{ $en ? 'Visual alerts' : 'Avisos visuales' }}</label>
<label><input type="checkbox" data-sound> {{ $en ? 'Sound (tap to enable on this page)' : 'Sonido (pulsa para activar en esta página)' }}</label>
<button type="button" data-enable-sound>{{ $en ? 'Enable sound on this page' : 'Activar sonido en esta página' }}</button>
<button type="button" data-snooze>{{ $en ? 'Snooze 1 hour' : 'Silenciar 1 hora' }}</button>
<button type="button" data-resume>{{ $en ? 'Resume notifications' : 'Reactivar notificaciones' }}</button>
<button type="button" data-mute-conversation>{{ $en ? 'Mute selected conversation' : 'Silenciar conversación seleccionada' }}</button>
<button type="button" data-unmute-conversation>{{ $en ? 'Unmute selected conversation' : 'Reactivar conversación seleccionada' }}</button>
<button type="button" data-disconnect>{{ $en ? 'Disconnect this widget' : 'Desconectar este widget' }}</button></section>
<div data-widget-content></div></body></html>
