{{--
  TeamCalls · aviso de llamada entrante en cualquier pantalla del CRM (fase 4I). Lo incluye el layout del CRM con
  @includeIf('teamcalls::partials.incoming'). No imprime nada si la interfaz de llamadas no está habilitada en este
  entorno (nunca en producción), sin sesión, sin collaboration.use o en la propia página de llamadas.
  Sin secretos: rutas de la API de llamadas de la persona, dirección pública del tiempo real y textos.
--}}
@php($tcUser = auth()->user())
@if ($tcUser instanceof \App\Models\User && \Modules\TeamCalls\Http\IncomingNotifier::shouldRender($tcUser, request()))
@php($tcAssets = \Modules\TeamCalls\Http\Controllers\CallsUiController::assetUrls())
<link rel="stylesheet" href="{{ $tcAssets['teamcalls-incoming.css'] }}">
<script type="application/json" id="tc-incoming-config">@json(\Modules\TeamCalls\Http\IncomingNotifier::config($tcUser))</script>
<script src="{{ $tcAssets['teamcalls-core.js'] }}" defer></script>
<script src="{{ $tcAssets['teamcalls-realtime.js'] }}" defer></script>
@include('teamcalls::partials.launcher')
<script src="{{ $tcAssets['teamcalls-incoming.js'] }}" defer></script>
@endif
