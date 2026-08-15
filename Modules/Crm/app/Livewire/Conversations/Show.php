<?php

declare(strict_types=1);

namespace Modules\Crm\Livewire\Conversations;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Modules\Crm\Models\Contact;
use Modules\Crm\Models\Conversation;

/**
 * Vista de una conversacion (SOLO lectura en este bloque): hilo de mensajes y
 * linea de tiempo de eventos. Se llenaran de verdad con el widget del Bloque 5.
 * Se autoriza contra Contact (misma puerta del CRM).
 */
#[Layout('layouts.app')]
class Show extends Component
{
    public int $conversationId;

    public function mount(Conversation $conversation): void
    {
        $this->authorize('viewAny', Contact::class);
        $this->conversationId = $conversation->getKey();
    }

    public function render(): View
    {
        $conversation = Conversation::query()->findOrFail($this->conversationId)->load([
            'messages' => fn ($q) => $q->orderBy('created_at')->orderBy('id'),
            'events' => fn ($q) => $q->orderBy('created_at'),
            'contact',
        ]);

        return view('crm::livewire.conversations.show', [
            'conversation' => $conversation,
        ]);
    }
}
