<?php

declare(strict_types=1);

namespace Modules\TeamCalls\Listeners;

use App\Models\User;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Facades\Log;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\TeamCalls\Http\WebDevice;
use Modules\TeamCalls\Services\CallService;
use Throwable;

/**
 * Cierre de sesión → la persona sale de sus llamadas vivas EN ESE dispositivo (el de la sesión que se
 * cierra; sus otros dispositivos no se tocan). Así no queda conectada a una sala con la sesión ya
 * cerrada. Nunca hace fallar el cierre de sesión.
 */
final class LeaveCallsOnLogout
{
    public function __construct(
        private readonly CallService $calls,
        private readonly CurrentInstitution $context,
    ) {}

    public function handle(Logout $event): void
    {
        $user = $event->user;
        $request = request();
        if (! $user instanceof User || $user->institution_id === null || ! $request->hasSession()) {
            return;
        }
        $device = $request->session()->get(WebDevice::SESSION_KEY);
        if (! is_string($device) || $device === '') {
            return;
        }
        try {
            $this->context->runFor((int) $user->institution_id, fn () => $this->calls->leaveDevice($user, $device));
        } catch (Throwable $e) {
            Log::warning('teamcalls.logout_leave_failed', ['error' => class_basename($e)]);
        }
    }
}
