<?php

declare(strict_types=1);

namespace Modules\TeamCalls\Tests\Support;

use App\Models\User;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Modules\TeamCalls\Contracts\MediaRoomService;
use Modules\TeamCalls\Models\Call;
use Modules\TeamCalls\Models\CallParticipant;
use Modules\TeamCalls\Services\CallService;
use Modules\TeamCalls\Support\MediaIdentity;
use Throwable;

/**
 * Servidor LiveKit SIMULADO para las pruebas (sin red): intercepta el API Twirp de salas con
 * Http::fake, exige tokens de servicio válidos con el permiso de cada operación y guarda el estado
 * (salas, participantes conectados, admitidos, expulsiones). También firma webhooks como LiveKit.
 * Respuestas alineadas con LiveKit 1.13.10 REAL (fase 3B): nombres snake_case, ListParticipants de
 * una sala inexistente = 200 vacío, permisos insuficientes = 401 «unauthenticated», firma inválida =
 * 401 en texto plano, retirar/cerrar lo que no existe = 404 «not_found».
 * Nada de esto prueba audio ni video reales.
 */
final class FakeLiveKit
{
    public const URL = 'https://livekit.test';

    public const CLIENT_URL = 'wss://rtc.livekit.test';

    public const KEY = 'APIteamcallstest';

    public const SECRET = 'secreto-de-pruebas-livekit-0123456789-abcdef'; // ficticio, solo pruebas

    /** @var array<string, array{max: int}> */
    public static array $rooms = [];

    /** @var array<string, array<string, string>> sala => [identidad => sid] */
    public static array $connected = [];

    /** @var list<string> «sala|identidad» desconectadas por el backend */
    public static array $removed = [];

    /** @var array<string, true> «sala|identidad» con medios concedidos (UpdateParticipant) */
    public static array $admitted = [];

    /** @var list<string> salas cerradas por el backend */
    public static array $deleted = [];

    /** @var list<string> métodos llamados */
    public static array $calls = [];

    /** null | down (sin conexión) | error (HTTP 500) */
    public static ?string $failure = null;

    /** Configura LiveKit como servidor de medios y activa el servidor simulado. */
    public static function boot(array $over = []): void
    {
        self::$rooms = self::$connected = self::$admitted = [];
        self::$removed = self::$deleted = self::$calls = [];
        self::$failure = null;
        config(['teamcalls.media.driver' => 'livekit', 'teamcalls.media.livekit' => array_merge([
            'api_url' => self::URL, 'client_url' => self::CLIENT_URL, 'api_key' => self::KEY, 'api_secret' => self::SECRET,
            'timeout' => 2, 'connect_timeout' => 1, 'retries' => 2, 'retry_sleep_ms' => 0,
            'server_token_ttl' => 60, 'empty_timeout' => 120, 'departure_timeout' => 20, 'webhook_leeway' => 5,
        ], $over)]);
        app()->forgetInstance(MediaRoomService::class);
        app()->forgetInstance(CallService::class);
        Http::preventStrayRequests();
        Http::fake(fn (Request $request) => self::handle($request));
    }

    /** Un cliente se conecta de verdad a la sala (lo que LiveKit haría con un token válido). */
    public static function connect(string $room, string $identity, string $sid): void
    {
        self::$rooms[$room] ??= ['max' => 0]; // solo se puede estar en una sala que existe
        self::$connected[$room][$identity] = $sid;
    }

    public static function room(Call|string $call): string
    {
        return $call instanceof Call ? $call->room_name : (string) Call::query()->whereKey($call)->value('room_name');
    }

    public static function identity(User $user, string $callId): string
    {
        $device = (string) CallParticipant::query()->where('call_id', $callId)->where('user_id', $user->id)->value('device_id');

        return MediaIdentity::for($user->id, $device);
    }

    /**
     * Envía un webhook firmado como LiveKit (o manipulado, según $tamper).
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $tamper  secret | iss | exp | body | sha256 | header
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    public static function webhook(array $payload, array $tamper = []): TestResponse
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $now = time();
        $claims = [
            'iss' => $tamper['iss'] ?? self::KEY,
            'nbf' => $now,
            'exp' => $tamper['exp'] ?? $now + 300,
            'sha256' => $tamper['sha256'] ?? base64_encode(hash('sha256', $body, true)),
        ];
        $token = JWT::encode($claims, $tamper['secret'] ?? self::SECRET, 'HS256');
        $sent = $tamper['body'] ?? $body;
        $headers = ['CONTENT_TYPE' => 'application/webhook+json'];
        if (($tamper['header'] ?? true) !== false) {
            $headers['HTTP_AUTHORIZATION'] = $token;
        }

        return test()->call('POST', route('api.teamcalls.media.livekit'), [], [], [], $headers, $sent);
    }

    /** @return array<string, mixed> */
    public static function participantEvent(string $event, string $room, string $identity, string $sid, ?string $id = null): array
    {
        return ['event' => $event, 'id' => $id ?? 'EV_'.$event.'_'.$sid, 'createdAt' => (string) time(),
            'room' => ['sid' => 'RM_'.substr(md5($room), 0, 10), 'name' => $room],
            'participant' => ['sid' => $sid, 'identity' => $identity, 'state' => 'ACTIVE', 'joinedAt' => (string) time()]];
    }

    /** @return array<string, mixed> */
    public static function roomEvent(string $event, string $room, ?string $id = null): array
    {
        return ['event' => $event, 'id' => $id ?? 'EV_'.$event.'_'.substr(md5($room), 0, 8), 'createdAt' => (string) time(),
            'room' => ['sid' => 'RM_'.substr(md5($room), 0, 10), 'name' => $room]];
    }

    /** @return array<string, mixed> claims verificados de un token */
    public static function decode(string $token): array
    {
        return json_decode((string) json_encode(JWT::decode($token, new Key(self::SECRET, 'HS256'))), true);
    }

    private static function handle(Request $request): mixed
    {
        if (! str_starts_with($request->url(), self::URL.'/twirp/livekit.RoomService/')) {
            return Http::response(['code' => 'not_found'], 404);
        }
        if (self::$failure === 'down') {
            self::$calls[] = 'unreachable';

            return (Http::failedConnection('LiveKit simulado: sin conexión'))($request);
        }
        $method = substr($request->url(), strlen(self::URL.'/twirp/livekit.RoomService/'));
        self::$calls[] = $method;
        if (self::$failure === 'error') {
            return Http::response(['code' => 'internal', 'msg' => 'boom'], 500);
        }

        // Como LiveKit: token de servicio firmado con el secreto y con el permiso de la operación.
        try {
            $claims = self::decode((string) preg_replace('/^Bearer\s+/', '', (string) $request->header('Authorization')[0]));
        } catch (Throwable) {
            return Http::response('invalid authorization token: token signature is invalid: signature is invalid', 401);
        }
        $video = $claims['video'] ?? [];
        $body = $request->data();
        $room = (string) ($body['room'] ?? $body['name'] ?? '');
        $needs = ['CreateRoom' => 'roomCreate', 'DeleteRoom' => 'roomCreate', 'ListRooms' => 'roomList',
            'ListParticipants' => 'roomAdmin', 'RemoveParticipant' => 'roomAdmin', 'UpdateParticipant' => 'roomAdmin'][$method] ?? null;
        if ($needs === null || empty($video[$needs]) || ($needs === 'roomAdmin' && ($video['room'] ?? null) !== $room)) {
            return Http::response(['code' => 'unauthenticated', 'msg' => 'permissions denied'], 401);
        }

        switch ($method) {
            case 'CreateRoom':
                self::$rooms[$room] ??= ['max' => (int) ($body['max_participants'] ?? 0)];

                return Http::response(['sid' => 'RM_x', 'name' => $room, 'maxParticipants' => self::$rooms[$room]['max']]);
            case 'ListRooms':
                return Http::response(['rooms' => array_map(fn ($n) => ['name' => $n, 'sid' => 'RM_x'], array_keys(self::$rooms))]);
            case 'ListParticipants':
                $list = []; // como LiveKit real: sala inexistente = lista vacía (200)
                foreach (self::$connected[$room] ?? [] as $identity => $sid) {
                    $list[] = ['sid' => $sid, 'identity' => $identity, 'state' => 'ACTIVE', 'joined_at' => (string) (time() - 120),
                        'permission' => ['can_subscribe' => isset(self::$admitted[$room.'|'.$identity]), 'hidden' => ! isset(self::$admitted[$room.'|'.$identity])]];
                }

                return Http::response(['participants' => $list]);
            case 'RemoveParticipant':
                $identity = (string) ($body['identity'] ?? '');
                if (! isset(self::$connected[$room][$identity])) {
                    return Http::response(['code' => 'not_found', 'msg' => 'participant does not exist'], 404);
                }
                unset(self::$connected[$room][$identity]);
                self::$removed[] = $room.'|'.$identity;

                return Http::response([]);
            case 'UpdateParticipant':
                $identity = (string) ($body['identity'] ?? '');
                if (! isset(self::$connected[$room][$identity])) {
                    return Http::response(['code' => 'not_found', 'msg' => 'participant does not exist'], 404);
                }
                if (! empty($body['permission']['can_subscribe'])) {
                    self::$admitted[$room.'|'.$identity] = true;
                }

                return Http::response(['sid' => self::$connected[$room][$identity], 'identity' => $identity, 'permission' => $body['permission'] ?? []]);
            case 'DeleteRoom':
                if (! isset(self::$rooms[$room])) {
                    return Http::response(['code' => 'not_found', 'msg' => 'requested room does not exist'], 404);
                }
                unset(self::$rooms[$room], self::$connected[$room]);
                self::$deleted[] = $room;

                return Http::response([]);
        }

        return Http::response(['code' => 'bad_route'], 404);
    }
}
