<?php

declare(strict_types=1);

namespace Modules\Crm\Livewire\Agenda;

use Illuminate\Contracts\View\View;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;
use Livewire\Component;
use Modules\Crm\Models\Activity;
use Modules\Crm\Notifications\AgendaNotification;

/**
 * Avisos internos de la agenda en el topbar (actividad asignada y recordatorios) del
 * usuario actual. Sondeo ligero; no consulta nada para quien no trabaja el CRM.
 */
class AlertBell extends Component
{
    public function markAllRead(): void
    {
        auth()->user()?->unreadNotifications()
            ->where('type', AgendaNotification::class)
            ->update(['read_at' => now()]);
    }

    public function render(): View
    {
        $user = auth()->user();

        /** @var Collection<int, DatabaseNotification> $items */
        $items = new Collection;
        $unread = 0;
        $visible = $user !== null && $user->can('viewAny', Activity::class);

        if ($visible) {
            $items = $user->notifications()->where('type', AgendaNotification::class)->latest()->limit(8)->get();
            $unread = $user->unreadNotifications()->where('type', AgendaNotification::class)->count();
        }

        return view('crm::livewire.agenda.alert-bell', [
            'visible' => $visible,
            'items' => $items,
            'unread' => $unread,
        ]);
    }
}
