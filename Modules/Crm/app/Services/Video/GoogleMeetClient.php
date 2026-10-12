<?php

declare(strict_types=1);

namespace Modules\Crm\Services\Video;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Str;
use Modules\Crm\Livewire\Agenda\MeetingProfileForm;
use Modules\Integrations\Models\Integration;

/**
 * Cliente de la cuenta institucional de Google Meet: OAuth 2.0 (código de autorización + PKCE
 * S256, `state` de un solo uso), renovación y revocación de tokens, y la API REST oficial de Meet
 * (v2: espacios y miembros). Scopes mínimos: identificar la cuenta y gestionar solo los espacios
 * creados por esta aplicación. Toda respuesta se valida antes de usarse.
 */
class GoogleMeetClient
{
    public function __construct(private readonly VideoConnections $connections) {}

    /**
     * URL de consentimiento + valores que el llamador guarda en la sesión (state y verificador PKCE).
     *
     * @return array{url:string, state:string, verifier:string}
     */
    public function authorizationRequest(Integration $integration, string $redirectUri): array
    {
        $clientId = (string) $integration->secret('client_id', '');
        if ($clientId === '' || (string) $integration->secret('client_secret', '') === '') {
            throw new VideoProviderException('config', 'google: faltan client_id/client_secret');
        }
        $state = Str::random(40);
        $verifier = Str::random(96); // RFC 7636: 43–128 caracteres no reservados
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        $url = config('crm.video.google.auth_url').'?'.http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => implode(' ', (array) config('crm.video.google.scopes')),
            'access_type' => 'offline',
            'prompt' => 'consent',
            'include_granted_scopes' => 'false',
            'state' => $state,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986);

        return ['url' => $url, 'state' => $state, 'verifier' => $verifier];
    }

    /** Canjea el código (con el verificador PKCE), guarda los tokens cifrados e identifica la cuenta. */
    public function completeAuthorization(Integration $integration, string $code, string $verifier, string $redirectUri): string
    {
        $response = $this->connections->send(fn () => $this->connections->http(false)->asForm()->post((string) config('crm.video.google.token_url'), [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'code_verifier' => $verifier,
            'redirect_uri' => $redirectUri,
            'client_id' => (string) $integration->secret('client_id'),
            'client_secret' => (string) $integration->secret('client_secret'),
        ]), 'google.token');
        if (! $response->successful()) {
            throw $this->connections->failure($response, 'google.token');
        }
        $access = $response->json('access_token');
        $refresh = $response->json('refresh_token');
        $expires = $response->json('expires_in');
        $scopes = explode(' ', (string) $response->json('scope', ''));
        if (! is_string($access) || $access === '' || ! is_string($refresh) || $refresh === '' || ! is_numeric($expires)) {
            throw new VideoProviderException('invalid_response', 'google.token: tokens incompletos');
        }
        if (! in_array('https://www.googleapis.com/auth/meetings.space.created', $scopes, true)) {
            throw new VideoProviderException('permission', 'google.token: no se concedió el permiso de Meet');
        }

        $this->connections->put($integration, [
            'access_token' => $access,
            'refresh_token' => $refresh,
            'access_token_expires_at' => time() + (int) $expires,
        ]);
        $email = $this->accountEmail($integration);
        $this->connections->put($integration, ['account_email' => $email]);
        $this->connections->recordTest($integration, true, __('Conectada como :email.', ['email' => $email]), 'active');

        return $email;
    }

    /** Prueba real: renueva el token y lee la identidad de la cuenta institucional. */
    public function test(Integration $integration): string
    {
        $this->accessToken($integration, true);
        $email = $this->accountEmail($integration);
        $this->connections->put($integration, ['account_email' => $email]);

        return $email;
    }

    /** Revoca el token en Google (si se puede) y borra las credenciales de acceso locales. */
    public function disconnect(Integration $integration): void
    {
        $token = (string) ($integration->secret('refresh_token') ?: $integration->secret('access_token') ?: '');
        if ($token !== '') {
            try {
                $this->connections->send(fn () => $this->connections->http(false)->asForm()->post((string) config('crm.video.google.revoke_url'), ['token' => $token]), 'google.revoke');
            } catch (VideoProviderException) {
                // La desconexión local se completa igualmente: los tokens dejan de existir aquí.
            }
        }
        $this->connections->put($integration, [], ['access_token', 'refresh_token', 'access_token_expires_at', 'account_email']);
        $integration->forceFill(['status' => 'inactive'])->save();
    }

    /**
     * Crea un espacio permanente. accessType TRUSTED: la organización entra directa y los
     * invitados externos piden acceso (los admite el anfitrión o un coanfitrión).
     *
     * @return array{name:string, meetingUri:string, meetingCode:string}
     */
    public function createSpace(Integration $integration): array
    {
        $access = in_array($integration->secret('access_type'), ['OPEN', 'TRUSTED'], true) ? (string) $integration->secret('access_type') : 'TRUSTED';
        $response = $this->api($integration, 'post', '/spaces', ['config' => ['accessType' => $access, 'entryPointAccess' => 'ALL']], 'google.spaces.create');

        return $this->validSpace($response);
    }

    /** @return array{name:string, meetingUri:string, meetingCode:string} */
    public function getSpace(Integration $integration, string $name): array
    {
        $this->guardSpaceName($name);

        return $this->validSpace($this->api($integration, 'get', '/'.$name, [], 'google.spaces.get'));
    }

    /**
     * Asigna el rol oficial de COANFITRIÓN al correo del asesor (API spaces.members). Si la cuenta,
     * la licencia o la política no lo permiten, lo dice (sin rodeos ni accesos alternativos).
     *
     * @return array{status:string, note:string|null} status: assigned · failed
     */
    public function assignCohost(Integration $integration, string $space, string $email): array
    {
        $this->guardSpaceName($space);
        $email = Str::lower(trim($email));
        try {
            $list = $this->api($integration, 'get', '/'.$space.'/members', [], 'google.members.list');
            foreach ((array) $list->json('members', []) as $member) {
                if (is_array($member) && Str::lower((string) ($member['email'] ?? '')) === $email && ($member['role'] ?? '') === 'COHOST') {
                    return ['status' => 'assigned', 'note' => null];
                }
            }
            $created = $this->api($integration, 'post', '/'.$space.'/members', ['email' => $email, 'role' => 'COHOST'], 'google.members.create');
            if (($created->json('role') ?? 'COHOST') !== 'COHOST') {
                throw new VideoProviderException('invalid_response', 'google.members.create: rol inesperado');
            }

            return ['status' => 'assigned', 'note' => null];
        } catch (VideoProviderException $e) {
            if ($e->kind === 'auth') {
                throw $e;
            }

            return ['status' => 'failed', 'note' => in_array($e->kind, ['permission', 'license', 'not_found'], true)
                ? __('Google no permitió asignar al asesor como coanfitrión (cuenta, licencia o política). El asesor entra con el enlace y la cuenta institucional conserva el control.')
                : $e->userMessage()];
        }
    }

    /** Token de acceso vigente; lo renueva con el refresh token si caducó (o si se fuerza). */
    public function accessToken(Integration $integration, bool $force = false): string
    {
        $token = (string) $integration->secret('access_token', '');
        if (! $force && $token !== '' && (int) $integration->secret('access_token_expires_at', 0) > time() + 60) {
            return $token;
        }
        $refresh = (string) $integration->secret('refresh_token', '');
        if ($refresh === '') {
            throw new VideoProviderException('auth', 'google: sin refresh token');
        }
        $response = $this->connections->send(fn () => $this->connections->http()->asForm()->post((string) config('crm.video.google.token_url'), [
            'grant_type' => 'refresh_token',
            'refresh_token' => $refresh,
            'client_id' => (string) $integration->secret('client_id'),
            'client_secret' => (string) $integration->secret('client_secret'),
        ]), 'google.refresh');
        if (! $response->successful()) {
            $e = $this->connections->failure($response, 'google.refresh');
            if (in_array($response->status(), [400, 401], true)) {
                $e = new VideoProviderException('auth', $e->getMessage()); // invalid_grant: caducado o revocado
            }
            throw $e;
        }
        $access = $response->json('access_token');
        $expires = $response->json('expires_in');
        if (! is_string($access) || $access === '' || ! is_numeric($expires)) {
            throw new VideoProviderException('invalid_response', 'google.refresh: token no válido');
        }
        $this->connections->put($integration, ['access_token' => $access, 'access_token_expires_at' => time() + (int) $expires]);

        return $access;
    }

    private function accountEmail(Integration $integration): string
    {
        $response = $this->connections->send(fn () => $this->connections->http()->withToken($this->accessToken($integration))
            ->get((string) config('crm.video.google.userinfo_url')), 'google.userinfo');
        if (! $response->successful()) {
            throw $this->connections->failure($response, 'google.userinfo');
        }
        $email = $response->json('email');
        if (! is_string($email) || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new VideoProviderException('invalid_response', 'google.userinfo: sin correo');
        }

        return Str::lower($email);
    }

    /** @param  array<string,mixed>  $body */
    private function api(Integration $integration, string $method, string $path, array $body, string $operation): Response
    {
        $url = rtrim((string) config('crm.video.google.api_url'), '/').$path;
        $call = fn (bool $force) => $this->connections->send(function () use ($integration, $method, $url, $body, $force): Response {
            $request = $this->connections->http($method === 'get')->withToken($this->accessToken($integration, $force));

            return $method === 'get' ? $request->get($url) : $request->post($url, $body === [] ? (object) [] : $body);
        }, $operation);

        $response = $call(false);
        if ($response->status() === 401) {
            $response = $call(true); // token revocado o caducado antes de tiempo: una sola renovación
        }
        if (! $response->successful()) {
            throw $this->connections->failure($response, $operation);
        }

        return $response;
    }

    /** @return array{name:string, meetingUri:string, meetingCode:string} */
    private function validSpace(Response $response): array
    {
        $name = $response->json('name');
        $uri = $response->json('meetingUri');
        $code = $response->json('meetingCode');
        if (! is_string($name) || ! is_string($uri) || ! is_string($code)) {
            throw new VideoProviderException('invalid_response', 'google.space: campos ausentes');
        }
        $this->guardSpaceName($name);
        if (! MeetingProfileForm::validMeetUrl($uri) || preg_match('/^[a-z0-9-]{3,40}$/i', $code) !== 1) {
            throw new VideoProviderException('invalid_response', 'google.space: enlace no válido');
        }

        return ['name' => $name, 'meetingUri' => $uri, 'meetingCode' => $code];
    }

    private function guardSpaceName(string $name): void
    {
        if (preg_match('#^spaces/[A-Za-z0-9_-]{1,100}$#', $name) !== 1) {
            throw new VideoProviderException('invalid_response', 'google.space: identificador no válido');
        }
    }
}
