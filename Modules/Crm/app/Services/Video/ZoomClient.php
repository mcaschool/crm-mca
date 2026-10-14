<?php

declare(strict_types=1);

namespace Modules\Crm\Services\Video;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Str;
use Modules\Crm\Livewire\Agenda\MeetingProfileForm;
use Modules\Integrations\Models\Integration;

/**
 * Cliente de la cuenta institucional de Zoom con Server-to-Server OAuth (grant
 * account_credentials; nunca JWT heredado) y la API REST oficial v2. Solo se conservan la URL de
 * PARTICIPANTE y datos técnicos mínimos: las contraseñas y las claves de anfitrión de las
 * respuestas se descartan sin guardarse ni registrarse. El enlace de inicio (start_url) solo sale
 * de aquí con startLink(), en memoria, para la redirección del propio usuario: nunca se guarda.
 */
class ZoomClient
{
    /** Modalidad segura (la única): los invitados esperan a que el anfitrión los admita. */
    public const SECURE_SETTINGS = ['waiting_room' => true, 'join_before_host' => false];

    public function __construct(private readonly VideoConnections $connections) {}

    /**
     * Prueba real: obtiene token, identifica la cuenta y el anfitrión institucional (licencia).
     *
     * @return array{id:string, email:string, type:int, account_id:string}
     */
    public function test(Integration $integration): array
    {
        $this->accessToken($integration, true);
        $hostRef = (string) ($integration->secret('host_email') ?: 'me');
        $host = $this->user($integration, $hostRef);
        if ($host === null) {
            throw new VideoProviderException('not_found', 'zoom.users.get: anfitrión no encontrado');
        }
        $this->connections->put($integration, [
            'host_user_id' => $host['id'],
            'account_email' => $host['email'],
            'host_type' => $host['type'],
        ]);

        return $host;
    }

    /** Revoca el token vigente (si lo hay) y borra las credenciales locales. */
    public function disconnect(Integration $integration): void
    {
        $token = (string) $integration->secret('access_token', '');
        if ($token !== '') {
            try {
                $this->connections->send(fn () => $this->connections->http(false)->asForm()
                    ->withBasicAuth((string) $integration->secret('client_id'), (string) $integration->secret('client_secret'))
                    ->post((string) config('crm.video.zoom.revoke_url'), ['token' => $token]), 'zoom.revoke');
            } catch (VideoProviderException) {
                // Se completa la desconexión local igualmente.
            }
        }
        $this->connections->put($integration, [], ['access_token', 'access_token_expires_at', 'client_secret', 'account_email', 'host_user_id', 'host_type']);
        $integration->forceFill(['status' => 'inactive'])->save();
    }

    /**
     * Usuario de la cuenta (por id, correo o «me»); null si no pertenece a la cuenta.
     *
     * @return array{id:string, email:string, type:int, account_id:string}|null
     */
    public function user(Integration $integration, string $ref): ?array
    {
        if ($ref !== 'me' && filter_var($ref, FILTER_VALIDATE_EMAIL) === false && preg_match('/^[A-Za-z0-9_-]{1,64}$/', $ref) !== 1) {
            return null;
        }
        try {
            $response = $this->api($integration, 'get', '/users/'.rawurlencode($ref), [], 'zoom.users.get');
        } catch (VideoProviderException $e) {
            if ($e->kind === 'not_found') {
                return null;
            }
            throw $e;
        }
        $id = $response->json('id');
        $email = $response->json('email');
        if (! is_string($id) || preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id) !== 1 || ! is_string($email)) {
            throw new VideoProviderException('invalid_response', 'zoom.users.get: datos no válidos');
        }
        if (($response->json('status') ?? 'active') !== 'active') {
            return null;
        }

        return ['id' => $id, 'email' => Str::lower($email), 'type' => (int) $response->json('type', 1), 'account_id' => (string) $response->json('account_id', '')];
    }

    /**
     * Busca una reunión «sin hora fija» ya creada con esta marca (reintentos tras un fallo de
     * comunicación: nunca se duplica la reunión).
     *
     * @return array{id:string, join_url:string, host_id:string, join_before_host:bool, waiting_room:bool, has_password:bool, started:bool}|null
     */
    public function findByMarker(Integration $integration, string $hostId, string $marker): ?array
    {
        $token = '';
        for ($page = 0; $page < 5; $page++) {
            $response = $this->api($integration, 'get', '/users/'.rawurlencode($hostId).'/meetings?'.http_build_query(array_filter([
                'type' => 'scheduled', 'page_size' => 300, 'next_page_token' => $token,
            ])), [], 'zoom.meetings.list');
            foreach ((array) $response->json('meetings', []) as $m) {
                if (is_array($m) && (int) ($m['type'] ?? 0) === 3 && str_contains((string) ($m['agenda'] ?? ''), $marker)) {
                    return $this->getMeeting($integration, (string) ($m['id'] ?? ''));
                }
            }
            $token = (string) $response->json('next_page_token', '');
            if ($token === '') {
                break;
            }
        }

        return null;
    }

    /**
     * Crea una reunión permanente (tipo 3, sin hora fija, nunca la PMI) del anfitrión indicado: el
     * propio usuario si tiene licencia en la cuenta, o el anfitrión técnico institucional. Siempre
     * en modalidad segura: contraseña, sala de espera, sin «unirse antes» y entrada silenciada.
     *
     * @return array{id:string, join_url:string, host_id:string, join_before_host:bool, waiting_room:bool, has_password:bool, started:bool}
     */
    public function createMeeting(Integration $integration, string $hostId, string $topic, string $agenda): array
    {
        $response = $this->api($integration, 'post', '/users/'.rawurlencode($hostId).'/meetings', [
            'topic' => Str::limit($topic, 190, ''),
            'type' => 3,
            'agenda' => Str::limit($agenda, 1900, ''),
            'default_password' => true,
            'settings' => self::SECURE_SETTINGS + [
                'host_video' => true,
                'participant_video' => false,
                'mute_upon_entry' => true,
                'approval_type' => 2,
                'meeting_authentication' => false,
                'auto_recording' => 'none',
            ],
        ], 'zoom.meetings.create');

        return $this->validMeeting($response);
    }

    /**
     * Aplica la modalidad segura a una reunión existente (mismo id y mismo enlace) y devuelve lo que
     * Zoom aplicó realmente: la cuenta puede bloquear alguna opción.
     *
     * @return array{id:string, join_url:string, host_id:string, join_before_host:bool, waiting_room:bool, has_password:bool, started:bool}
     */
    public function secure(Integration $integration, string $id): array
    {
        if (preg_match('/^\d{6,15}$/', $id) !== 1) {
            throw new VideoProviderException('invalid_response', 'zoom.meeting: id no válido');
        }
        $this->api($integration, 'patch', '/meetings/'.$id, ['settings' => self::SECURE_SETTINGS], 'zoom.meetings.update');

        return $this->getMeeting($integration, $id);
    }

    /**
     * ¿La reunión mantiene la modalidad segura (sala de espera, sin «unirse antes», contraseña)?
     *
     * @param  array{join_before_host:bool, waiting_room:bool, has_password:bool}  $meeting
     */
    public static function isSecure(array $meeting): bool
    {
        return $meeting['waiting_room'] && ! $meeting['join_before_host'] && $meeting['has_password'];
    }

    /**
     * Enlace de INICIO vigente de la reunión (GET /meetings/{id}), validado: https, dominio de Zoom,
     * ruta /s/{id} y token de inicio. Es una credencial temporal: se devuelve solo en memoria para
     * redirigir al propio usuario y nunca se guarda, registra ni audita.
     *
     * @return array{meeting:array{id:string, join_url:string, host_id:string, join_before_host:bool, waiting_room:bool, has_password:bool, started:bool}, start_url:string}
     */
    public function startLink(Integration $integration, string $id): array
    {
        if (preg_match('/^\d{6,15}$/', $id) !== 1) {
            throw new VideoProviderException('invalid_response', 'zoom.meeting: id no válido');
        }
        $response = $this->api($integration, 'get', '/meetings/'.$id, [], 'zoom.meetings.start');
        $meeting = $this->validMeeting($response);
        $start = $response->json('start_url');
        if (! is_string($start) || ! self::validStartUrl($start, $id)) {
            throw new VideoProviderException('invalid_response', 'zoom.meeting: enlace de inicio no válido');
        }

        return ['meeting' => $meeting, 'start_url' => $start];
    }

    /** Enlace de inicio legítimo de Zoom para ESA reunión (sin credenciales, puertos ni otros dominios). */
    public static function validStartUrl(string $url, string $meetingId): bool
    {
        $p = parse_url($url);
        if (! is_array($p) || ($p['scheme'] ?? '') !== 'https' || isset($p['user']) || isset($p['pass']) || isset($p['port'])) {
            return false;
        }
        $host = strtolower((string) ($p['host'] ?? ''));
        if ($host !== 'zoom.us' && ! str_ends_with($host, '.zoom.us')) {
            return false;
        }
        parse_str((string) ($p['query'] ?? ''), $query);

        return ($p['path'] ?? '') === '/s/'.$meetingId && is_string($query['zak'] ?? null) && $query['zak'] !== '';
    }

    /**
     * Reuniones EN CURSO del anfitrión (GET /users/{id}/meetings?type=live), para su capacidad.
     *
     * @return array<int,string>
     */
    public function liveMeetingIds(Integration $integration, string $hostId): array
    {
        $response = $this->api($integration, 'get', '/users/'.rawurlencode($hostId).'/meetings?'.http_build_query(['type' => 'live', 'page_size' => 300]), [], 'zoom.meetings.live');

        return collect((array) $response->json('meetings', []))->map(fn ($m) => is_array($m) ? (string) ($m['id'] ?? '') : '')->filter()->values()->all();
    }

    /** @return array{id:string, join_url:string, host_id:string, join_before_host:bool, waiting_room:bool, has_password:bool, started:bool} */
    public function getMeeting(Integration $integration, string $id): array
    {
        if (preg_match('/^\d{6,15}$/', $id) !== 1) {
            throw new VideoProviderException('invalid_response', 'zoom.meeting: id no válido');
        }

        return $this->validMeeting($this->api($integration, 'get', '/meetings/'.$id, [], 'zoom.meetings.get'));
    }

    public function accessToken(Integration $integration, bool $force = false): string
    {
        $token = (string) $integration->secret('access_token', '');
        if (! $force && $token !== '' && (int) $integration->secret('access_token_expires_at', 0) > time() + 60) {
            return $token;
        }
        $accountId = (string) $integration->secret('account_id', '');
        $clientId = (string) $integration->secret('client_id', '');
        $secret = (string) $integration->secret('client_secret', '');
        if ($accountId === '' || $clientId === '' || $secret === '') {
            throw new VideoProviderException('config', 'zoom: faltan credenciales S2S');
        }
        $response = $this->connections->send(fn () => $this->connections->http()->asForm()->withBasicAuth($clientId, $secret)
            ->post((string) config('crm.video.zoom.token_url'), ['grant_type' => 'account_credentials', 'account_id' => $accountId]), 'zoom.token');
        if (! $response->successful()) {
            $e = $this->connections->failure($response, 'zoom.token');
            throw in_array($response->status(), [400, 401], true) ? new VideoProviderException('auth', $e->getMessage()) : $e;
        }
        $access = $response->json('access_token');
        $expires = $response->json('expires_in');
        if (! is_string($access) || $access === '' || ! is_numeric($expires) || ($response->json('token_type') ?? 'bearer') !== 'bearer') {
            throw new VideoProviderException('invalid_response', 'zoom.token: token no válido');
        }
        $this->connections->put($integration, ['access_token' => $access, 'access_token_expires_at' => time() + (int) $expires]);

        return $access;
    }

    /** @param  array<string,mixed>  $body */
    private function api(Integration $integration, string $method, string $path, array $body, string $operation): Response
    {
        $url = rtrim((string) config('crm.video.zoom.api_url'), '/').$path;
        $call = fn (bool $force) => $this->connections->send(function () use ($integration, $method, $url, $body, $force): Response {
            $request = $this->connections->http($method === 'get')->withToken($this->accessToken($integration, $force));

            return match ($method) {
                'get' => $request->get($url),
                'patch' => $request->patch($url, $body),
                default => $request->post($url, $body),
            };
        }, $operation);

        $response = $call(false);
        if ($response->status() === 401) {
            $response = $call(true);
        }
        if (! $response->successful()) {
            throw $this->connections->failure($response, $operation);
        }

        return $response;
    }

    /** @return array{id:string, join_url:string, host_id:string, join_before_host:bool, waiting_room:bool, has_password:bool, started:bool} */
    private function validMeeting(Response $response): array
    {
        $id = (string) $response->json('id', '');
        $join = $response->json('join_url');
        $host = $response->json('host_id');
        if (preg_match('/^\d{6,15}$/', $id) !== 1 || ! is_string($join) || ! is_string($host) || preg_match('/^[A-Za-z0-9_-]{1,64}$/', $host) !== 1) {
            throw new VideoProviderException('invalid_response', 'zoom.meeting: campos no válidos');
        }
        if ((int) $response->json('type', 0) !== 3) {
            throw new VideoProviderException('invalid_response', 'zoom.meeting: tipo inesperado');
        }
        // Solo un enlace de PARTICIPANTE (/j/), del dominio de Zoom y sin token de anfitrión.
        if (! MeetingProfileForm::validZoomUrl($join) || ! str_contains($join, '/j/')) {
            throw new VideoProviderException('invalid_response', 'zoom.meeting: enlace no válido');
        }

        return [
            'id' => $id,
            'join_url' => $join,
            'host_id' => $host,
            'join_before_host' => (bool) $response->json('settings.join_before_host', false),
            'waiting_room' => (bool) $response->json('settings.waiting_room', false),
            // Contraseña: la devuelve la API o va cifrada en el enlace de participante (pwd=). No se guarda.
            'has_password' => (is_string($response->json('password')) && $response->json('password') !== '') || str_contains($join, 'pwd='),
            'started' => $response->json('status') === 'started',
        ];
    }
}
