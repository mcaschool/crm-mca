<?php

declare(strict_types=1);

namespace Modules\Identity\Livewire\Users;

use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Modules\Core\Tenancy\CurrentInstitution;

/**
 * Listado de usuarios del panel, ACOTADO a la institucion activa del contexto.
 *
 * Aislamiento: User no usa el scope global (es infraestructura de auth), asi que
 * aqui se filtra EXPLICITAMENTE por la institucion activa. Un Admin ve los de su
 * institucion; el super-admin ve los de la institucion que tenga activa.
 */
#[Layout('layouts.app')]
class Index extends Component
{
    public function mount(): void
    {
        $this->authorize('viewAny', User::class);
    }

    /**
     * Alterna activo/inactivo de un usuario (baja/alta logica).
     */
    public function toggleActive(int $userId): void
    {
        $user = $this->scopedQuery()->findOrFail($userId);

        $this->authorize('deactivate', $user);

        $user->status = $user->isActive() ? 'inactive' : 'active';
        $user->save();
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<User>
     */
    private function scopedQuery()
    {
        $institutionId = app(CurrentInstitution::class)->idOrFail();

        return User::query()->where('institution_id', $institutionId);
    }

    /**
     * @return Collection<int, User>
     */
    private function users(): Collection
    {
        return $this->scopedQuery()->orderBy('name')->get();
    }

    public function render(): View
    {
        return view('identity::livewire.users.index', [
            'users' => $this->users(),
        ]);
    }
}
