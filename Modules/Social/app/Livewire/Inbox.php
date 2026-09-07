<?php

declare(strict_types=1);

namespace Modules\Social\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Modules\Social\Models\SocialConversation;
use Modules\Social\Services\SocialOutboundService;

/**
 * Bandeja social unificada (Bloque 2, solo UI). Cumple el rol de "InboxController" en este
 * CRM basado en Livewire (el patrón de panel es full-page Livewire, no controllers sueltos):
 * lista las conversaciones de la institución activa (scope del trait) ordenadas por
 * last_message_at DESC, y muestra el hilo de la seleccionada (mensajes ASC por
 * provider_timestamp, fallback created_at). Sin API real todavía: la caja de redacción
 * está deshabilitada. Acceso: mismo patrón del panel (grupo can:access-panel) + canWorkCrm.
 */
#[Layout('layouts.app')]
class Inbox extends Component
{
    public ?int $selectedId = null;

    public string $draft = '';

    public function mount(): void
    {
        abort_unless(auth()->user()?->canWorkCrm() ?? false, 403);
    }

    /** Selecciona una conversación (validada contra el scope de institución). */
    public function select(int $id): void
    {
        $this->selectedId = SocialConversation::query()->whereKey($id)->exists() ? $id : null;
        $this->draft = '';
    }

    /**
     * Envía la respuesta del agente (solo Instagram/Messenger; WhatsApp queda deshabilitado).
     * El scoping por institución lo garantiza la consulta (solo encuentra conversaciones de la
     * institución activa); si no la encuentra, no hace nada.
     */
    public function send(SocialOutboundService $outbound): void
    {
        $text = trim($this->draft);
        if ($text === '' || $this->selectedId === null) {
            return;
        }

        $conversation = SocialConversation::query()->find($this->selectedId);
        if ($conversation === null || ! in_array($conversation->provider, SocialOutboundService::SENDABLE, true)) {
            return;
        }

        $outbound->send($conversation, $text, auth()->user());
        $this->draft = '';
    }

    public function render(): View
    {
        $conversations = SocialConversation::query()
            ->with('channel')
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        $selected = $this->selectedId !== null
            ? SocialConversation::query()->with('channel')->find($this->selectedId)
            : null;

        $messages = $selected !== null
            ? $selected->messages()
                ->orderByRaw('COALESCE(provider_timestamp, created_at) asc')
                ->orderBy('id')
                ->get()
            : collect();

        $canReply = $selected !== null
            && in_array($selected->provider, SocialOutboundService::SENDABLE, true);

        return view('social::inbox', [
            'conversations' => $conversations,
            'selected' => $selected,
            'messages' => $messages,
            'canReply' => $canReply,
        ]);
    }
}
