<?php

declare(strict_types=1);

namespace Modules\TeamCalls\Http;

use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Punto de entrada a la interfaz de llamadas desde otras aplicaciones de MCA Colaboración (fase 4E):
 * TeamChat, el widget universal o cualquier vista del panel abren /colaboracion/teamcalls con una
 * INTENCIÓN en la URL. La intención solo dice qué quiere hacer la persona; no autoriza nada: la
 * página la ejecuta con la API existente (CallsGateway), que comprueba conversación, membresía,
 * participantes, permisos, MFA, dispositivo e institución en el servidor.
 *
 * Contrato (parámetros de consulta):
 *  - media=audio|video & user=<id>                                    llamada individual
 *  - media=audio|video & conversation=direct:<id>                     desde una conversación privada
 *  - media=audio|video & conversation=group|channel:<id> & invitees=<id,id,…>   invitación explícita
 *  - call=<ulid>                                                      abrir una llamada existente
 *  - key=<8–48 [A-Za-z0-9._:-]> (opcional)                            idempotencia del inicio
 *
 * Una navegación que no viene del mismo origen (Sec-Fetch-Site distinto de same-origin: enlace de
 * otro sitio, URL pegada) nunca inicia ni abre nada sola: la página pide confirmación con un clic.
 */
final class CallLaunch
{
    public const MAX_INVITEES = 24;

    private const KEYS = ['media', 'user', 'conversation', 'invitees', 'call', 'key'];

    /**
     * La interfaz de llamadas (y este punto de entrada) existe solo con el interruptor activo y en un entorno
     * listado explícitamente (fase 4H: desarrollo, pruebas o una preproducción nombrada). Producción nunca,
     * aunque se configure: queda pendiente de una autorización posterior.
     */
    public static function enabled(): bool
    {
        if (! config('teamcalls.ui.enabled') || app()->environment('production')) {
            return false;
        }
        $environments = array_values(array_diff((array) config('teamcalls.ui.environments', ['local', 'testing']), ['production', '']));

        return $environments !== [] && app()->environment($environments);
    }

    /** Desarrollo (local/testing) o preproducción: decide avisos y lo que solo se permite al desarrollar. */
    public static function stage(): string
    {
        return app()->environment(['local', 'testing']) ? 'development' : 'preproduction';
    }

    /**
     * URL de la interfaz con la intención validada (para enlaces generados en el servidor).
     *
     * @param  array{media?: string, user?: int, conversation?: array{type: string, id: string}|string, invitees?: list<int>, call?: string, key?: string}  $intent
     */
    public static function url(array $intent): string
    {
        $query = [];
        foreach ($intent as $k => $v) {
            $query[$k] = match ($k) {
                'conversation' => is_array($v) ? $v['type'].':'.$v['id'] : (string) $v,
                'invitees' => implode(',', array_map('intval', (array) $v)),
                default => (string) $v,
            };
        }
        if (self::parse($query) === null) {
            throw new InvalidArgumentException('Intención de llamada no válida.');
        }

        return route('collab.calls.ui', $query);
    }

    /**
     * Intención de la petición para la página: null si no trae ninguna; ['action' => 'invalid'] si la trae
     * mal formada (la página lo dice en lugar de adivinar).
     *
     * @return array<string, mixed>|null
     */
    public static function fromRequest(Request $request): ?array
    {
        $query = array_intersect_key($request->query(), array_flip(self::KEYS));
        if ($query === []) {
            return null;
        }
        $intent = self::parse($query) ?? ['action' => 'invalid'];
        $intent['confirm'] = $request->headers->get('Sec-Fetch-Site') !== 'same-origin';

        return $intent;
    }

    /**
     * Solo FORMATO (lista cerrada de combinaciones); la autorización es de la API.
     *
     * @param  array<array-key, mixed>  $q
     * @return array<string, mixed>|null
     */
    private static function parse(array $q): ?array
    {
        foreach ($q as $k => $v) {
            if (! in_array($k, self::KEYS, true) || ! is_string($v)) {
                return null;
            }
        }
        $key = $q['key'] ?? null;
        if ($key !== null && preg_match('/^[A-Za-z0-9._:-]{8,48}$/D', $key) !== 1) {
            return null;
        }

        if (isset($q['call'])) {
            if (count(array_diff_key($q, ['call' => 1])) > 0 || preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/iD', $q['call']) !== 1) {
                return null;
            }

            // Tal cual: TeamCalls usa ULID en minúsculas y la página compara identificadores literalmente.
            return ['action' => 'open', 'call' => $q['call']];
        }

        $media = $q['media'] ?? null;
        if (! in_array($media, ['audio', 'video'], true) || isset($q['user']) === isset($q['conversation'])) {
            return null;
        }
        $base = ['action' => 'start', 'media' => $media, 'key' => $key];

        if (isset($q['user'])) {
            if (isset($q['invitees']) || preg_match('/^[1-9]\d{0,18}$/D', $q['user']) !== 1) {
                return null;
            }

            return $base + ['user' => (int) $q['user']];
        }

        if (preg_match('/^(direct|group|channel):([A-Za-z0-9_-]{1,64})$/D', $q['conversation'], $m) !== 1) {
            return null;
        }
        $conversation = ['type' => $m[1], 'id' => $m[2]];
        if ($m[1] === 'direct') {
            // La otra persona la resuelve TeamChat en el servidor: aquí no se elige a nadie.
            return isset($q['invitees']) ? null : $base + ['conversation' => $conversation];
        }

        // Grupos y canales: selección EXPLÍCITA obligatoria (la API la valida todo o nada contra la membresía).
        $raw = $q['invitees'] ?? '';
        if (preg_match('/^[1-9]\d{0,18}(,[1-9]\d{0,18})*$/D', $raw) !== 1) {
            return null;
        }
        $invitees = array_values(array_unique(array_map('intval', explode(',', $raw))));
        if (count($invitees) > self::MAX_INVITEES) {
            return null;
        }

        return $base + ['conversation' => $conversation, 'invitees' => $invitees];
    }
}
