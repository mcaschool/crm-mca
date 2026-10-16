<?php

declare(strict_types=1);

namespace Modules\TeamCalls\Http\Controllers;

use App\Models\User;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\TeamCalls\Enums\CallMedia;
use Modules\TeamCalls\Exceptions\CallException;
use Modules\TeamCalls\Http\ActorDevice;
use Modules\TeamCalls\Http\CallResponse;
use Modules\TeamCalls\Models\Call;
use Modules\TeamCalls\Models\CallEvent;
use Modules\TeamCalls\Services\CallService;
use Modules\TeamCalls\Support\CallSummary;
use Modules\TeamCalls\Support\CallTarget;

/**
 * API web de llamadas para la futura interfaz del panel. Capa FINA sobre CallService: identidad,
 * institución activa, estado del usuario, permisos, pertenencia a la llamada, transiciones e
 * idempotencia los decide el motor en cada operación; aquí solo se valida la forma de la petición
 * y se traduce el resultado a CallResponse.
 *
 *  - Quién actúa sale SIEMPRE de la sesión; el dispositivo, del registro de CollaborationCore para esa
 *    sesión (ActorDevice), nunca del cliente.
 *  - Finalizar para todos es solo del anfitrión; el resto sale con «leave» (decisión funcional aprobada).
 *  - Solo llamadas individuales hacia una persona (los campos de conversación se rechazan): las llamadas
 *    desde conversaciones de TeamChat van por la API definitiva (/api/v1/collaboration/calls).
 *  - Las credenciales de la sala solo las emite el MediaRoomService enlazado; con el adaptador
 *    provisional la respuesta es 503 «media_unavailable».
 */
final class CallController
{
    /** Campos que el cliente NO puede imponer: los decide el servidor. */
    private const UNTRUSTED = [
        'device_id' => 'prohibited',
        'institution_id' => 'prohibited',
        'initiator_id' => 'prohibited',
        'conversation' => 'prohibited',
        'conversation_id' => 'prohibited',
        'conversation_type' => 'prohibited',
        'room' => 'prohibited', // la sala la decide el backend: es la de la llamada
        'room_name' => 'prohibited',
        'identity' => 'prohibited',
    ];

    public function __construct(
        private readonly CallService $calls,
        private readonly ActorDevice $devices,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $limit = $this->limit($request);

        return $this->guard(fn () => CallResponse::calls($this->calls->history($this->user($request), $limit)));
    }

    public function missed(Request $request): JsonResponse
    {
        $limit = $this->limit($request);

        return $this->guard(fn () => CallResponse::calls($this->calls->missed($this->user($request), $limit)));
    }

    public function show(Request $request, string $call): JsonResponse
    {
        return $this->guard(fn () => CallResponse::call($this->calls->find($this->user($request), $call), replayed: false));
    }

    /** Iniciar una llamada individual con una persona autorizada. Reintentos: misma clave, misma llamada. */
    public function store(Request $request): JsonResponse
    {
        if (! $request->filled('idempotency_key') && $request->headers->has('Idempotency-Key')) {
            $request->merge(['idempotency_key' => (string) $request->headers->get('Idempotency-Key')]);
        }
        $input = $request->validate(self::UNTRUSTED + [
            'user_id' => ['required', 'integer', 'min:1'],
            'user_ids' => ['prohibited'], // las llamadas grupales se forman invitando desde la llamada
            'media' => ['required', 'string', 'in:audio,video'],
            'idempotency_key' => ['required', 'string', 'regex:/^[A-Za-z0-9._:-]{8,64}$/'],
        ]);

        return $this->guard(function () use ($request, $input): JsonResponse {
            $user = $this->user($request);
            $call = $this->calls->start($user, CallTarget::users([(int) $input['user_id']]), CallMedia::from((string) $input['media']),
                (string) $input['idempotency_key'], $this->devices->id($request));
            $replayed = ! $call->wasRecentlyCreated;

            return CallResponse::call(CallSummary::from($call, $user->id), $replayed, $replayed ? 200 : 201);
        });
    }

    public function accept(Request $request, string $call): JsonResponse
    {
        $request->validate(self::UNTRUSTED);

        return $this->mutate($request, $call, fn (User $u) => $this->calls->accept($u, $call, $this->devices->id($request)));
    }

    public function decline(Request $request, string $call): JsonResponse
    {
        $request->validate(self::UNTRUSTED);

        return $this->mutate($request, $call, fn (User $u) => $this->calls->decline($u, $call));
    }

    public function cancel(Request $request, string $call): JsonResponse
    {
        $request->validate(self::UNTRUSTED);

        return $this->mutate($request, $call, fn (User $u) => $this->calls->cancel($u, $call));
    }

    public function join(Request $request, string $call): JsonResponse
    {
        $request->validate(self::UNTRUSTED);

        return $this->mutate($request, $call, fn (User $u) => $this->calls->join($u, $call, $this->devices->id($request)));
    }

    public function leave(Request $request, string $call): JsonResponse
    {
        // all_devices: salir de la llamada aunque esté en OTRO dispositivo propio (colgar «en otro dispositivo»).
        $input = $request->validate(self::UNTRUSTED + ['all_devices' => ['sometimes', 'boolean']]);
        $device = (bool) ($input['all_devices'] ?? false) ? null : $this->devices->id($request);

        return $this->mutate($request, $call, fn (User $u) => $this->calls->leave($u, $call, $device));
    }

    public function end(Request $request, string $call): JsonResponse
    {
        $request->validate(self::UNTRUSTED);

        return $this->mutate($request, $call, fn (User $u) => $this->calls->terminate($u, $call));
    }

    public function invite(Request $request, string $call): JsonResponse
    {
        $max = max(2, (int) config('teamcalls.max_participants', 25)) - 1;
        $input = $request->validate(self::UNTRUSTED + [
            'user_ids' => ['required', 'array', 'min:1', 'max:'.$max],
            'user_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
        ]);

        return $this->mutate($request, $call, fn (User $u) => $this->calls->invite($u, $call, array_map('intval', $input['user_ids'])));
    }

    /** Quien inicia expulsa a un participante (sin credenciales nuevas y desconectado de la sala). */
    public function revoke(Request $request, string $call): JsonResponse
    {
        $input = $request->validate(self::UNTRUSTED + ['user_id' => ['required', 'integer', 'min:1']]);

        return $this->mutate($request, $call, fn (User $u) => $this->calls->revoke($u, $call, (int) $input['user_id']));
    }

    /** Participantes REALES de la sala según el servidor de medios (sin dispositivos ni identidades). */
    public function media(Request $request, string $call): JsonResponse
    {
        return $this->guard(fn () => CallResponse::data(['participants' => $this->calls->mediaParticipants($this->user($request), $call)]));
    }

    /** Credenciales de la sala para ESTE dispositivo. Nunca se guardan ni se cachean. */
    public function credentials(Request $request, string $call): JsonResponse
    {
        $request->validate(self::UNTRUSTED);

        return $this->guard(function () use ($request, $call): JsonResponse {
            $credentials = $this->calls->credentials($this->user($request), $call, $this->devices->id($request));

            return CallResponse::data([
                'server_url' => $credentials->serverUrl,
                'token' => $credentials->token,
                'identity' => $credentials->identity,
                'expires_at' => $credentials->expiresAt->toIso8601String(),
            ]);
        });
    }

    /**
     * Operación sobre una llamada existente. «replayed» = la persona no generó ningún hecho nuevo en
     * la llamada (reintento o repetición sin efecto).
     *
     * @param  Closure(User): Call  $operation
     */
    private function mutate(Request $request, string $callId, Closure $operation): JsonResponse
    {
        return $this->guard(function () use ($request, $callId, $operation): JsonResponse {
            $user = $this->user($request);
            $before = $this->eventsBy($callId, $user);
            $call = $operation($user);

            return CallResponse::call(CallSummary::from($call, $user->id), replayed: $this->eventsBy($callId, $user) === $before);
        });
    }

    /** @param Closure(): JsonResponse $fn */
    private function guard(Closure $fn): JsonResponse
    {
        try {
            return $fn();
        } catch (CallException $e) {
            return CallResponse::error($e->reason, $e->getMessage(), $e->status);
        }
    }

    private function eventsBy(string $callId, User $user): int
    {
        return CallEvent::query()->where('call_id', $callId)->where('actor_id', $user->id)->count();
    }

    private function limit(Request $request): int
    {
        $input = $request->validate(['limit' => ['nullable', 'integer', 'min:1', 'max:50']]);

        return (int) ($input['limit'] ?? 20);
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        if (! $user instanceof User) {
            throw CallException::forbidden('Unauthenticated.');
        }

        return $user;
    }
}
