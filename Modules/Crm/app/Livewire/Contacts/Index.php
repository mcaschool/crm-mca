<?php

declare(strict_types=1);

namespace Modules\Crm\Livewire\Contacts;

use Illuminate\Contracts\View\View;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Modules\Crm\Models\Contact;

/**
 * Listado de contactos con busqueda por nombre/correo. Aislado por scope global.
 *
 * Paginado: la cabecera muestra el TOTAL real (COUNT de la consulta filtrada, sin cargar las
 * filas) y el paginador, el tramo visible. Antes se cargaban como máximo 200 y se contaban esas.
 */
#[Layout('layouts.app')]
class Index extends Component
{
    use WithPagination;

    public const PER_PAGE = 25;

    #[Url]
    public string $search = '';

    public function mount(): void
    {
        $this->authorize('viewAny', Contact::class);

        // Badge del sidebar: entrar al módulo marca los contactos como VISTOS para
        // este usuario y avisa al menú (mismo patrón que la Bandeja social).
        $badges = app(\Modules\Crm\Services\SidebarBadges::class);
        $badges->markSeen(auth()->user(), 'contacts');
        $counts = $badges->counts(auth()->user());
        $this->dispatch('crm-badges-updated', leads: $counts['leads'], contacts: $counts['contacts']);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    /**
     * @return LengthAwarePaginator<int, Contact>
     */
    private function contacts(): LengthAwarePaginator
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
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE);
    }

    public function render(): View
    {
        return view('crm::livewire.contacts.index', [
            'contacts' => $this->contacts(),
        ]);
    }
}
