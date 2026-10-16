<?php

declare(strict_types=1);

namespace Modules\TeamCalls\Tests\Support;

use App\Models\User;
use Illuminate\Testing\TestResponse;
use Modules\CollaborationCore\Services\MfaSessionProof;

/**
 * Peticiones a la API web de llamadas desde las pruebas, como lo haría el navegador de cada persona:
 * sesión autenticada y un navegador (sesión = dispositivo) por persona salvo que se indique otro.
 */
final class Http
{
    /**
     * Sesión de ESE navegador (cookie de sesión propia → un dispositivo de CollaborationCore por navegador)
     * con la prueba de segundo factor que deja el login con TOTP.
     */
    public static function as(User $user, ?string $browser = null): mixed
    {
        $name = $browser ?? 'u'.$user->id;
        TC::browser($name);
        $store = app('session')->driver();
        $store->put('auth.mfa_user_id', (int) $user->id);
        $store->put('auth.mfa_fingerprint', MfaSessionProof::fingerprint($user));

        return test()->withCredentials()->withCookie((string) config('session.cookie'), TC::sessionId($name))->actingAs($user);
    }

    /** @return TestResponse<\Symfony\Component\HttpFoundation\Response> */
    public static function start(User $from, int $to, string $key = 'http-key-0001', string $media = 'audio', ?string $browser = null): TestResponse
    {
        return self::as($from, $browser)->postJson(route('collab.calls.store'), ['user_id' => $to, 'media' => $media, 'idempotency_key' => $key]);
    }

    /**
     * @param  array<string,mixed>  $body
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    public static function post(User $user, string $action, string $callId, array $body = [], ?string $browser = null): TestResponse
    {
        return self::as($user, $browser)->postJson(route('collab.calls.'.$action, $callId), $body);
    }

    /**
     * @param  array<string,mixed>  $query
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    public static function get(User $user, string $name, ?string $callId = null, array $query = []): TestResponse
    {
        $params = ($callId !== null ? ['call' => $callId] : []) + $query;

        return self::as($user)->getJson(route('collab.calls.'.$name, $params));
    }
}
