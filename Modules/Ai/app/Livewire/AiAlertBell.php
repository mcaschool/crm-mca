<?php

declare(strict_types=1);

namespace Modules\Ai\Livewire;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Component;
use Modules\Ai\Notifications\AiServiceAlertNotification;

/**
 * Campana de alertas de IA en el topbar (solo administradores). Lee las notificaciones
 * internas (Laravel database notifications) de tipo AiServiceAlertNotification del usuario
 * actual y muestra un contador de no leídas + las últimas. Sondeo ligero; no consulta nada
 * para usuarios que no gestionan integraciones.
 */
class AiAlertBell extends Component
{
    public function markAllRead(): void
    {
        $user = auth()->user();
        if ($user === null) {
            return;
        }
        $user->unreadNotifications()
            ->where('type', AiServiceAlertNotification::class)
            ->update(['read_at' => now()]);
    }

    public function render(): View
    {
        $user = auth()->user();

        /** @var Collection<int, \Illuminate\Notifications\DatabaseNotification> $items */
        $items = new Collection;
        $unread = 0;
        $visible = false;

        if ($user !== null && $user->canManageIntegrations()) {
            $visible = true;
            $items = $user->notifications()
                ->where('type', AiServiceAlertNotification::class)
                ->latest()
                ->limit(8)
                ->get();
            $unread = $user->unreadNotifications()
                ->where('type', AiServiceAlertNotification::class)
                ->count();
        }

        return view('ai::livewire.ai-alert-bell', [
            'visible' => $visible,
            'items' => $items,
            'unread' => $unread,
        ]);
    }
}
