{{--
  TeamCalls · lanzador de llamadas para otras aplicaciones (fase 4E). Uso: @includeIf('teamcalls::partials.launcher')
  en TeamChat, el marco del widget universal o cualquier vista del panel; opcional ['nonce' => $nonce] si la
  página usa CSP con nonce (la ruta es del mismo origen: 'self' también basta). Fuera de desarrollo, o sin
  TEAMCALLS_UI_ENABLED, no imprime nada: ni el script ni rutas. Sin secretos ni datos de la persona.
--}}
@if (\Modules\TeamCalls\Http\CallLaunch::enabled())
<script src="{{ route('collab.calls.ui.launcher', ['v' => substr((string) md5_file(module_path('TeamCalls', 'resources/assets/js/teamcalls-launcher.js')), 0, 12)]) }}"
        data-page="{{ route('collab.calls.ui') }}"
        data-active="{{ route('collaboration.calls.active') }}"
        @isset($nonce) nonce="{{ $nonce }}" @endisset defer></script>
@endif
