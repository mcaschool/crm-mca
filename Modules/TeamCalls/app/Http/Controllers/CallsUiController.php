<?php

declare(strict_types=1);

namespace Modules\TeamCalls\Http\Controllers;

use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\TeamCalls\Exceptions\CallException;
use Modules\TeamCalls\Http\CallLaunch;
use Modules\TeamCalls\Http\CallResponse;
use Modules\TeamCalls\Services\CallService;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Interfaz web de llamadas (fase 3C, solo desarrollo: EnsureCallsUiEnabled). La página no recibe ningún
 * secreto ni sala: todo pasa por la API existente (sesión, CSRF, 2FA, institución) y el SDK oficial de
 * LiveKit, que se sirve desde el propio módulo (sin CDN de terceros en el panel).
 */
final class CallsUiController
{
    /** Recursos estáticos permitidos (lista cerrada: nada de rutas arbitrarias). */
    private const ASSETS = [
        'teamcalls-core.js' => 'js/teamcalls-core.js',
        'teamcalls-realtime.js' => 'js/teamcalls-realtime.js',
        'teamcalls-ui.js' => 'js/teamcalls-ui.js',
        'teamcalls-ui.css' => 'css/teamcalls-ui.css',
        'livekit-client.umd.js' => 'vendor/livekit-client/livekit-client-2.22.3.umd.js',
    ];

    public function __construct(private readonly CallService $calls) {}

    public function page(Request $request): View
    {
        $user = $this->user($request);
        $stage = CallLaunch::stage();
        // Medios sintéticos: solo para pruebas automatizadas EN DESARROLLO, nunca en preproducción.
        $synthetic = $stage === 'development' && (bool) config('teamcalls.ui.synthetic_media') && $request->boolean('sintetico');
        // Fuera de desarrollo, el canal de tiempo real solo por TLS (wss); si no, la página usa el sondeo.
        $scheme = (string) config('teamcalls.realtime.scheme');
        $socketKey = $stage === 'development' || $scheme === 'https' ? (string) config('broadcasting.connections.reverb.key', '') : '';

        return view('teamcalls::app', [
            'assets' => $this->assetUrls(),
            'config' => [
                'me' => ['id' => (int) $user->id, 'name' => (string) $user->name],
                'stage' => $stage,
                // Sondeo temporal: cadencia base sin llamada y con la pestaña visible (el núcleo adapta el resto).
                'poll' => ['idle' => max(2000, min(30000, (int) config('teamcalls.ui.poll_ms', 4000)))],
                'reconnectGraceMs' => max(5, (int) config('teamcalls.reconnect_grace', 20)) * 1000,
                'synthetic' => $synthetic,
                'routes' => [
                    'contacts' => route('collab.calls.ui.contacts'),
                    'live' => route('collab.calls.ui.live'),
                    'history' => route('collab.calls.index'),
                    'missed' => route('collab.calls.missed'),
                    'store' => route('collab.calls.store'),
                    // Plantillas: el cliente sustituye __CALL__ por el id de la llamada (nunca una sala).
                    'show' => route('collab.calls.show', ['call' => '__CALL__']),
                    'accept' => route('collab.calls.accept', ['call' => '__CALL__']),
                    'decline' => route('collab.calls.decline', ['call' => '__CALL__']),
                    'cancel' => route('collab.calls.cancel', ['call' => '__CALL__']),
                    'join' => route('collab.calls.join', ['call' => '__CALL__']),
                    'leave' => route('collab.calls.leave', ['call' => '__CALL__']),
                    'end' => route('collab.calls.end', ['call' => '__CALL__']),
                    'credentials' => route('collab.calls.credentials', ['call' => '__CALL__']),
                    // Llamadas grupales (fase 4F): invitar desde la llamada y expulsar (solo quien la inicia).
                    'invite' => route('collab.calls.invite', ['call' => '__CALL__']),
                    'revoke' => route('collab.calls.revoke', ['call' => '__CALL__']),
                    // API definitiva (CallsGateway): llamadas desde conversaciones privadas, grupos y canales.
                    'conversation_store' => route('collaboration.calls.store'),
                ],
                // Intención recibida de otra aplicación (CallLaunch): solo formato; la autoriza la API.
                'intent' => CallLaunch::fromRequest($request),
                // CollaborationRealtime: API común y dirección PÚBLICA de Reverb (la clave de la app no es secreta).
                'realtime' => [
                    'api' => url('/api/v1/collaboration'),
                    'socket' => [
                        'key' => $socketKey,
                        'host' => (string) config('teamcalls.realtime.host'),
                        'port' => (int) config('teamcalls.realtime.port'),
                        'scheme' => $scheme,
                    ],
                ],
                'i18n' => (array) trans('teamcalls.ui.js'),
            ],
        ]);
    }

    /**
     * URLs de los recursos con versión por contenido: el navegador puede cachearlos y siempre recibe el
     * archivo vigente cuando cambia.
     *
     * @return array<string, string>
     */
    private function assetUrls(): array
    {
        $urls = [];
        foreach (self::ASSETS as $asset => $relative) {
            $hash = substr((string) md5_file(module_path('TeamCalls', 'resources/assets/'.$relative)), 0, 12);
            $urls[$asset] = route('collab.calls.ui.asset', ['asset' => $asset, 'v' => $hash]);
        }

        return $urls;
    }

    /**
     * Lanzador para otras aplicaciones (TeamChat, widget universal): JS sin secretos ni datos de la persona,
     * servido sin sesión (el marco del widget no siempre la tiene). Solo existe con la interfaz habilitada.
     */
    public function launcher(): BinaryFileResponse
    {
        return response()->file(module_path('TeamCalls', 'resources/assets/js/teamcalls-launcher.js'), [
            'Content-Type' => 'application/javascript; charset=utf-8',
            'Cache-Control' => 'public, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
            'Cross-Origin-Resource-Policy' => 'same-origin',
        ]);
    }

    public function asset(string $asset): BinaryFileResponse
    {
        $relative = self::ASSETS[$asset] ?? abort(404);
        $path = module_path('TeamCalls', 'resources/assets/'.$relative);
        $type = str_ends_with($asset, '.css') ? 'text/css' : 'application/javascript';

        return response()->file($path, [
            'Content-Type' => $type.'; charset=utf-8',
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** Personas a las que se puede llamar (id y nombre). */
    public function contacts(Request $request): JsonResponse
    {
        $input = $request->validate(['q' => ['nullable', 'string', 'max:80']]);

        return $this->guard(fn () => CallResponse::data(['contacts' => $this->calls->contacts($this->user($request), $input['q'] ?? null)]));
    }

    /** Llamadas vivas de quien consulta: el sondeo temporal de llamadas entrantes. */
    public function live(Request $request): JsonResponse
    {
        return $this->guard(fn () => CallResponse::calls($this->calls->live($this->user($request))));
    }

    /** @param \Closure(): JsonResponse $fn */
    private function guard(\Closure $fn): JsonResponse
    {
        try {
            return $fn();
        } catch (CallException $e) {
            return CallResponse::error($e->reason, $e->getMessage(), $e->status);
        }
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        return $user;
    }
}
