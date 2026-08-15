<?php

declare(strict_types=1);

namespace Modules\Identity\Livewire\Users;

use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Identity\Enums\UserRole;

/**
 * Alta/edicion de un usuario del panel.
 *
 * Barandillas de aislamiento y capacidad:
 *  - Al crear, institution_id se SELLA con la institucion activa; nunca se
 *    elige otra (ni el super-admin: opera dentro de la institucion activa).
 *  - Solo un super-admin puede otorgar el flag super-admin.
 *  - Editar exige que el objetivo pertenezca a la institucion activa (Policy).
 */
#[Layout('layouts.app')]
class Form extends Component
{
    public ?int $userId = null;

    public string $name = '';

    public string $email = '';

    public string $role = 'admissions';

    public bool $is_super_admin = false;

    public string $password = '';

    public function mount(?User $user = null): void
    {
        if ($user !== null && $user->exists) {
            // Editar: la Policy valida capacidad + institucion activa.
            $this->authorize('update', $user);

            $this->userId = $user->getKey();
            $this->name = $user->name;
            $this->email = $user->email;
            $this->role = $user->role->value;
            $this->is_super_admin = $user->isSuperAdmin();

            return;
        }

        $this->authorize('create', User::class);
    }

    public function save(): mixed
    {
        $institutionId = app(CurrentInstitution::class)->idOrFail();

        $editing = $this->userId !== null;
        $target = $editing ? User::query()->findOrFail($this->userId) : new User;

        // Re-autoriza sobre el recurso concreto (defensa en profundidad).
        $this->authorize($editing ? 'update' : 'create', $editing ? $target : User::class);

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => [
                'required', 'email', 'max:190',
                Rule::unique('users', 'email')->ignore($this->userId),
            ],
            'role' => ['required', Rule::in(UserRole::values())],
            'is_super_admin' => ['boolean'],
            'password' => $editing
                ? ['nullable', Password::defaults()]
                : ['required', Password::defaults()],
        ]);

        // Barandilla: otorgar super-admin es una superficie multi-institucion.
        // Solo con el flag activo Y siendo super-admin quien lo concede.
        $grantSuper = $validated['is_super_admin']
            && auth()->user()->isSuperAdmin()
            && config('crm.multi_institution');

        $target->name = $validated['name'];
        $target->email = $validated['email'];
        $target->role = $validated['role'];
        $target->is_super_admin = $grantSuper;

        if (! $editing) {
            // institution_id SIEMPRE la activa; nunca llega del formulario.
            $target->institution_id = $institutionId;
            $target->status = 'active';
        }

        if ($validated['password'] !== null && $validated['password'] !== '') {
            $target->password = Hash::make($validated['password']);
        }

        $target->save();

        session()->flash('status', $editing ? __('Usuario actualizado.') : __('Usuario creado.'));

        return redirect()->route('users.index');
    }

    public function render(): View
    {
        return view('identity::livewire.users.form', [
            'roles' => UserRole::cases(),
            // El checkbox de super-admin solo existe en modo multi-institucion.
            'canGrantSuperAdmin' => config('crm.multi_institution') && (bool) auth()->user()?->isSuperAdmin(),
            'editing' => $this->userId !== null,
        ]);
    }
}
