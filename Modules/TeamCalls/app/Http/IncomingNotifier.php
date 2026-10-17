<?php

declare(strict_types=1);

namespace Modules\TeamCalls\Http;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Modules\TeamCalls\Http\Controllers\CallsUiController;

/**
 * Aviso de llamada entrante en CUALQUIER pantalla del CRM (fase 4I): cuándo se incluye y con qué datos.
 *
 * Se incluye (teamcalls::partials.incoming, desde el layout del CRM) solo si la interfaz de llamadas está
 * habilitada en este entorno (CallLaunch: nunca en producción), el aviso no está desactivado, hay sesión,
 * la persona tiene collaboration.use y no está ya en la página de llamadas (que avisa por sí misma).
 * Los datos son rutas de la API de llamadas de la propia persona, la dirección PÚBLICA del tiempo real y
 * textos: ningún secreto, sala ni identidad de medios.
 */
final class IncomingNotifier
{
    private const TEXTS = ['incoming_from', 'incoming_video', 'incoming_audio', 'accept', 'decline', 'status_incoming',
        'unknown_user', 'missed_from', 'dismiss', 'call_back'];

    public static function shouldRender(?User $user, Request $request): bool
    {
        return $user instanceof User
            && CallLaunch::enabled()
            && (bool) config('teamcalls.ui.incoming.enabled', true)
            && ! $request->routeIs('collab.calls.ui')
            && $user->can('collaboration.use');
    }

    /** @return array<string, mixed> */
    public static function config(User $user): array
    {
        $c = (array) config('teamcalls.ui.incoming', []);
        $ms = fn (string $k, int $default, int $min, int $max): int => max($min, min($max, (int) ($c[$k] ?? $default)));

        return [
            'me' => ['id' => (int) $user->id],
            'page' => route('collab.calls.ui'),
            // Sondeo de respaldo de la pestaña que lidera; con tiempo real, solo como red de seguridad.
            'poll' => ['visibleMs' => $ms('poll_ms', 8000, 3000, 60000), 'hiddenMs' => $ms('hidden_poll_ms', 20000, 5000, 120000),
                'realtimeMs' => $ms('realtime_poll_ms', 30000, 10000, 300000)],
            'ringtone' => (bool) ($c['ringtone'] ?? true),
            'routes' => [
                'live' => route('collab.calls.ui.live'),
                'contacts' => route('collab.calls.ui.contacts'),
                'show' => route('collab.calls.show', ['call' => '__CALL__']),
                'decline' => route('collab.calls.decline', ['call' => '__CALL__']),
            ],
            'realtime' => CallsUiController::realtimeConfig(),
            'i18n' => Arr::only((array) trans('teamcalls.ui.js'), self::TEXTS),
        ];
    }
}
