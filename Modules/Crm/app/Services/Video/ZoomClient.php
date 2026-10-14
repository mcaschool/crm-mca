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
 * PARTICIPANTE y datos técnicos mínimos: el enlace de inicio (start_url), las contraseñas y las
 * claves de anfitrión de las respuestas se descartan sin guardarse ni registrarse.
 */
class ZoomClient
{
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
     * @return array{id:string, join_url:string, host_id:string, join_before_host:bool, waiting_room:bool}|null
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
     * con contraseña y entrada silenciada. Por defecto, sala de espera y sin «unirse antes»; el
     * acceso sin anfitrión solo se pide cuando la administración lo decidió expresamente.
     *
     * @return array{id:string, join_url:string, host_id:string, join_before_host:bool, waiting_room:bool}
     */
    public function createMeeting(Integration $integration, string $hostId, string $topic, string $agenda, bool $hostless = false): array
    {
        $response = $this->api($integration, 'post', '/users/'.rawurlencode($hostId).'/meetings', [
            'topic' => Str::limit($topic, 190, ''),
            'type' => 3,
            'agenda' => Str::limit($agenda, 1900, ''),
            'default_password' => true,
            'settings' => $this->accessSettings($hostless) + [
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
     * Cambia la modalidad de acceso de una reunión existente (mismo id y mismo enlace) y devuelve lo
     * que Zoom aplicó realmente: una cuenta puede bloquear estas opciones.
     *
     * @return array{id:string, join_url:string, host_id:string, join_before_host:bool, waiting_room:bool}
     */
    public function updateAccess(Integration $integration, string $id, bool $hostless): array
    {
        if (preg_match('/^\d{6,15}$/', $id) !== 1) {
            throw new VideoProviderException('invalid_response', 'zoom.meeting: id no válido');
        }
        $this->api($integration, 'patch', '/meetings/'.$id, ['settings' => $this->accessSettings($hostless)], 'zoom.meetings.update');

        return $this->getMeeting($integration, $id);
    }

    /**
     * Sala de espera (por defecto) o acceso sin anfitrión: Zoom exige desactivar la sala de espera
     * para que «unirse antes que el anfitrión» funcione. La contraseña se mantiene en ambos casos.
     *
     * @return array<string, bool|int>
     */
    private function accessSettings(bool $hostless): array
    {
        return $hostless
            ? ['waiting_room' => false, 'join_before_host' => true, 'jbh_time' => 0]
            : ['waiting_room' => true, 'join_before_host' => false];
    }

    /** @return array{id:string, join_url:string, host_id:string, join_before_host:bool, waiting_room:bool} */
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

    /** @return array{id:string, join_url:string, host_id:string, join_before_host:bool, waiting_room:bool} */
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
        ];
    }
}
