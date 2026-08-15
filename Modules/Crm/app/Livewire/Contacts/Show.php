<?php

declare(strict_types=1);

namespace Modules\Crm\Livewire\Contacts;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Modules\Crm\Models\Contact;

/**
 * Ficha del contacto con su historial: leads, conversaciones, eventos e intereses.
 */
#[Layout('layouts.app')]
class Show extends Component
{
    public int $contactId;

    public function mount(Contact $contact): void
    {
        $this->authorize('view', $contact);
        $this->contactId = $contact->getKey();
    }

    public function render(): View
    {
        $contact = Contact::query()->findOrFail($this->contactId)->load([
            'leads' => fn ($q) => $q->orderByDesc('updated_at'),
            'leads.program',
            'conversations' => fn ($q) => $q->orderByDesc('last_activity_at'),
            'programInterests.program',
            'events' => fn ($q) => $q->orderByDesc('created_at')->limit(50),
        ]);

        return view('crm::livewire.contacts.show', [
            'contact' => $contact,
        ]);
    }
}
