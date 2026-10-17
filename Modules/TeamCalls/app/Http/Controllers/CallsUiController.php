<?php

declare(strict_types=1);

namespace Modules\TeamCalls\Http\Controllers;

use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\TeamCalls\Contracts\MediaOutage;
use Modules\TeamCalls\Contracts\MediaRoomService;
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
        // Aviso de llamada entrante en cualquier pantalla del CRM (fase 4I).
        'teamcalls-incoming.js' => 'js/teamcalls-incoming.js',
        'teamcalls-incoming.css' => 'css/teamcalls-incoming.css',
        'livekit-client.umd.js' => 'vendor/livekit-client/livekit-client-2.22.3.umd.js',
    ];

    public function __construct(private readonly CallService $calls, private readonly MediaRoomService $media) {}

    public function page(Request $request): View
    {
        $user = $this->user($request);
        $stage = CallLaunch::stage();
        // Medios sintéticos: solo para pruebas automatizadas EN DESARROLLO, nunca en preproducción.
        $synthetic = $stage === 'development' && (bool) config('teamcalls.ui.synthetic_media') && $request->boolean('sintetico');

        return view('teamcalls::app', [
            'assets' => self::assetUrls(),
            'config' => [
                'me' => ['id' => (int) $user->id, 'name' => (string) $user->name],
                'stage' => $stage,
                // Sondeo temporal: cadencia base sin llamada y con la pestaña visible (el núcleo adapta el resto).
                'poll' => ['idle' => max(2000, min(30000, (int) config('teamcalls.ui.poll_ms', 4000)))],
                'reconnectGraceMs' => max(5, (int) config('teamcalls.reconnect_grace', 20)) * 1000,
                'timeouts' => self::timeouts(),
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
                'realtime' => self::realtimeConfig(),
                'i18n' => (array) trans('teamcalls.ui.js'),
            ],
        ]);
    }

    /**
     * Tiempos máximos del navegador (fase 4I), acotados a valores razonables: nunca tan cortos que corten
     * conexiones lentas legítimas ni tan largos que la interfaz parezca bloqueada.
     *
     * @return array{requestMs: int, connectMs: int, mediaMs: int, slowMs: int}
     */
    public static function timeouts(): array
    {
        $c = (array) config('teamcalls.ui.timeouts', []);
        $clamp = fn (string $k, int $default, int $min, int $max): int => max($min, min($max, (int) ($c[$k] ?? $default)));

        return [
            'requestMs' => $clamp('request_ms', 15000, 5000, 60000),
            'connectMs' => $clamp('connect_ms', 20000, 8000, 60000),
            'mediaMs' => $clamp('media_ms', 20000, 8000, 60000),
            'slowMs' => $clamp('slow_ms', 6000, 2000, 20000),
        ];
    }

    /**
     * CollaborationRealtime: API común y dirección PÚBLICA de Reverb (la clave de la app no es secreta). Fuera de
     * desarrollo, el canal solo por TLS (wss); si no, se omite la clave y la interfaz usa el sondeo (fase 4H).
     *
     * @return array{api: string, socket: array{key: string, host: string, port: int, scheme: string}}
     */
    public static function realtimeConfig(): array
    {
        $scheme = (string) config('teamcalls.realtime.scheme');
        $key = CallLaunch::stage() === 'development' || $scheme === 'https' ? (string) config('broadcasting.connections.reverb.key', '') : '';

        return ['api' => url('/api/v1/collaboration'), 'socket' => [
            'key' => $key,
            'host' => (string) config('teamcalls.realtime.host'),
            'port' => (int) config('teamcalls.realtime.port'),
            'scheme' => $scheme,
        ]];
    }

    /**
     * URLs de los recursos con versión por contenido: el navegador puede cachearlos y siempre recibe el
     * archivo vigente cuando cambia.
     *
     * @return array<string, string>
     */
    public static function assetUrls(): array
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

    /**
     * Llamadas vivas de quien consulta: el sondeo temporal de llamadas entrantes. Fase 4I: con el estado del
     * servidor de medios (sin llamada de red: lo que sabe el disyuntor) para el indicador de conexión.
     */
    public function live(Request $request): JsonResponse
    {
        return $this->guard(function () use ($request): JsonResponse {
            $response = CallResponse::calls($this->calls->live($this->user($request)));
            $payload = (array) $response->getData(true);
            $payload['meta']['media'] = $this->mediaState();

            return $response->setData($payload);
        });
    }

    /** available | down (caída conocida) | unconfigured (sin servidor de medios en este entorno). */
    private function mediaState(): string
    {
        $media = $this->media;
        if ($media instanceof MediaOutage) {
            return $media->outage() ? 'down' : 'available';
        }

        return $media->available() ? 'available' : 'unconfigured';
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
