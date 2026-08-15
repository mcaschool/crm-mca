<?php

declare(strict_types=1);

namespace Modules\Crm\Livewire\Contacts;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Modules\Crm\Models\Contact;

/**
 * Listado de contactos con busqueda por nombre/correo. Aislado por scope global.
 */
#[Layout('layouts.app')]
class Index extends Component
{
    #[Url]
    public string $search = '';

    public function mount(): void
    {
        $this->authorize('viewAny', Contact::class);
    }

    /**
     * @return Collection<int, Contact>
     */
    private function contacts(): Collection
    {
        $term = trim($this->search);

        return Contact::query()
            ->withCount(['leads', 'conversations'])
            ->when($term !== '', function ($q) use ($term) {
                $q->where(function ($sub) use ($term) {
                    $sub->where('first_name', 'like', "%{$term}%")
                        ->orWhere('last_name', 'like', "%{$term}%")
                        ->orWhere('email', 'like', "%{$term}%");
                });
            })
            ->orderByDesc('created_at')
            ->limit(200)
            ->get();
    }

    public function render(): View
    {
        return view('crm::livewire.contacts.index', [
            'contacts' => $this->contacts(),
        ]);
    }
}
