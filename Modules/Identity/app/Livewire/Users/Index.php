<?php

declare(strict_types=1);

namespace Modules\Identity\Livewire\Users;

use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Modules\Audit\Services\AuditService;
use Modules\Core\Tenancy\CurrentInstitution;

/**
 * Listado de usuarios (asesores humanos) del panel, ACOTADO a la institucion
 * activa. Con busqueda y filtros por departamento y estado. Solo Administrador.
 *
 * Aislamiento: User no usa el scope global (es infraestructura de auth), asi que
 * aqui se filtra EXPLICITAMENTE por la institucion activa.
 */
#[Layout('layouts.app')]
class Index extends Component
{
    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'dep')]
    public string $department = '';

    #[Url(as: 'estado')]
    public string $status = '';

    public function mount(): void
    {
        $this->authorize('viewAny', User::class);
    }

    public function toggleActive(int $userId, AuditService $audit): void
    {
        $user = $this->scopedQuery()->findOrFail($userId);
        $this->authorize('deactivate', $user);

        $user->status = $user->isActive() ? 'inactive' : 'active';
        $user->save();

        $audit->log($user->isActive() ? 'user.activated' : 'user.deactivated', $user);
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
        $query = $this->scopedQuery();

        if (trim($this->search) !== '') {
            $term = '%'.trim($this->search).'%';
            $query->where(fn ($q) => $q->where('name', 'like', $term)->orWhere('email', 'like', $term));
        }
        if ($this->department !== '') {
            $query->where('department', $this->department);
        }
        if ($this->status !== '') {
            $query->where('status', $this->status);
        }

        return $query->orderBy('name')->get();
    }

    public function render(): View
    {
        return view('identity::livewire.users.index', [
            'users' => $this->users(),
            'departments' => User::DEPARTMENTS,
            'canManage' => (bool) auth()->user()?->canManageUsers(),
        ]);
    }
}
