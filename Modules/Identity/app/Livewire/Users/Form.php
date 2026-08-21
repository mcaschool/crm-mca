<?php

declare(strict_types=1);

namespace Modules\Identity\Livewire\Users;

use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Modules\Audit\Services\AuditService;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Identity\Enums\UserRole;
use Modules\Identity\Services\UserAvatarService;

/**
 * Alta/edicion de un usuario (asesor humano) del panel. Solo Administrador.
 *
 * - El CORREO es la identidad de login: unico y NO editable tras crearse.
 * - El numero de identidad es sensible (cifrado en el modelo); aqui el Admin lo
 *   ve/edita en claro, pero nunca se expone en el widget, logs ni URLs.
 * - Acceso por INVITACION: al crear, el usuario recibe un enlace para fijar su
 *   propia contrasena. El Admin nunca ve una contrasena en claro.
 */
#[Layout('layouts.app')]
class Form extends Component
{
    use WithFileUploads;

    public ?int $userId = null;

    /** Foto de perfil en transito (previsualizacion antes de guardar). */
    public mixed $avatar = null;

    public string $name = '';

    public string $email = '';

    public string $nationalId = '';

    public string $department = '';

    public string $role = 'admissions';

    public string $status = 'active';

    public bool $is_super_admin = false;

    /** Permiso por usuario: puede usar el modo código (HTML/CSS) del editor de correo. */
    public bool $canEmailCode = false;

    /** Enlace de invitacion recien generado (se muestra al Admin para reenviar). */
    public ?string $invitationLink = null;

    public function mount(?User $user = null): void
    {
        if ($user !== null && $user->exists) {
            $this->authorize('update', $user);

            $this->userId = $user->getKey();
            $this->name = $user->name;
            $this->email = $user->email;
            $this->nationalId = (string) ($user->national_id ?? '');
            $this->department = (string) ($user->department ?? '');
            $this->role = $user->role->value;
            $this->status = $user->status;
            $this->is_super_admin = $user->isSuperAdmin();
            $this->canEmailCode = (bool) $user->can_email_code;

            // Acceso a dato sensible: abrir la ficha DESCIFRA el numero de identidad y
            // lo muestra en claro al Admin. Se audita el HECHO del acceso, nunca el valor.
            if (($user->national_id ?? null) !== null) {
                app(AuditService::class)->log('user.national_id_viewed', $user, ['field' => 'national_id']);
            }

            // Enlace de invitacion recien creado (viene por flash tras el alta).
            $this->invitationLink = session('invitation_link');

            return;
        }

        $this->authorize('create', User::class);
    }

    public function save(AuditService $audit): mixed
    {
        $institutionId = app(CurrentInstitution::class)->idOrFail();
        $editing = $this->userId !== null;
        $target = $editing ? User::query()->findOrFail($this->userId) : new User;

        $this->authorize($editing ? 'update' : 'create', $editing ? $target : User::class);

        // Estado previo de los campos sensibles (para auditar SOLO lo que cambia).
        $originalRole = $editing ? $target->role->value : null;
        $originalDepartment = $editing ? $target->department : null;
        $originalStatus = $editing ? $target->status : null;
        $originalEmailCode = $editing ? (bool) $target->can_email_code : false;

        $rules = [
            'name' => ['required', 'string', 'max:120'],
            'nationalId' => ['nullable', 'string', 'max:40'],
            'department' => ['nullable', Rule::in(User::DEPARTMENTS)],
            'role' => ['required', Rule::in(UserRole::values())],
            'status' => ['required', Rule::in(['active', 'inactive'])],
            'is_super_admin' => ['boolean'],
            'canEmailCode' => ['boolean'],
        ];
        // El correo SOLO se valida/asigna al crear (es inmutable tras el alta).
        if (! $editing) {
            $rules['email'] = ['required', 'email', 'max:190', Rule::unique('users', 'email')];
        }
        $this->validate($rules);

        // No permitir desactivarse a uno mismo (evita quedarse sin acceso).
        if ($editing && $target->getKey() === auth()->id() && $this->status === 'inactive') {
            $this->addError('status', 'No puedes desactivar tu propia cuenta.');

            return null;
        }

        $grantSuper = $this->is_super_admin && auth()->user()->isSuperAdmin() && config('crm.multi_institution');

        $target->name = trim($this->name);
        $target->national_id = trim($this->nationalId) !== '' ? trim($this->nationalId) : null;
        $target->department = $this->department !== '' ? $this->department : null;
        $target->role = UserRole::from($this->role);
        $target->status = $this->status;
        $target->is_super_admin = $grantSuper;
        $target->can_email_code = $this->canEmailCode;

        if (! $editing) {
            $target->email = strtolower(trim($this->email));
            $target->institution_id = $institutionId;
            // Contrasena aleatoria e inutilizable: el usuario fija la suya por invitacion.
            // NUNCA se muestra ni se guarda en claro (solo el hash).
            $target->password = Hash::make(Str::random(48));
        }

        $target->save();

        if ($editing) {
            // Auditoria: se registra el HECHO de cada cambio sensible (nunca datos en claro).
            if ($originalRole !== $this->role) {
                $audit->log('user.role_changed', $target, ['from' => $originalRole, 'to' => $this->role]);
            }
            $newDepartment = $this->department !== '' ? $this->department : null;
            if ($originalDepartment !== $newDepartment) {
                $audit->log('user.department_changed', $target, ['from' => $originalDepartment, 'to' => $newDepartment]);
            }
            if ($originalStatus !== $this->status) {
                $audit->log($this->status === 'active' ? 'user.activated' : 'user.deactivated', $target);
            }
            if ($originalEmailCode !== $this->canEmailCode) {
                $audit->log('user.email_code_changed', $target, ['to' => $this->canEmailCode ? 'on' : 'off']);
            }

            session()->flash('status', 'Usuario actualizado.');

            return null;
        }

        // Auditoria del alta (rol y correo NO son secretos; nunca se registra la clave).
        $audit->log('user.created', $target, ['role' => $this->role, 'email' => $target->email]);

        // Enlace de invitacion (token de restablecimiento): el usuario fija su clave.
        $link = $this->buildInvitationLink($target);
        $audit->log('user.invited', $target);
        session()->flash('status', 'Usuario creado. Comparte el enlace de acceso con la persona.');
        session()->flash('invitation_link', $link);

        return redirect()->route('users.edit', $target->getKey());
    }

    /** Sube/cambia la foto de perfil del usuario gestionado (Admin). */
    public function saveAvatar(UserAvatarService $avatars): void
    {
        abort_unless($this->userId !== null, 404);
        $target = User::query()->findOrFail($this->userId);
        $this->authorize('update', $target);

        $this->validate(['avatar' => ['required', 'image', 'mimes:png,jpg,jpeg,svg,webp,gif', 'max:1024']]);
        if (! $this->avatar instanceof TemporaryUploadedFile) {
            return;
        }

        $avatars->store($target, $this->avatar);
        $this->avatar = null;
        session()->flash('status', 'Foto de perfil actualizada.');
    }

    public function removeAvatar(UserAvatarService $avatars): void
    {
        abort_unless($this->userId !== null, 404);
        $target = User::query()->findOrFail($this->userId);
        $this->authorize('update', $target);

        $avatars->remove($target);
        session()->flash('status', 'Foto de perfil eliminada.');
    }

    /** Regenera un enlace de acceso para un usuario existente (reenviar invitacion). */
    public function regenerateInvitation(AuditService $audit): void
    {
        abort_unless($this->userId !== null, 404);
        $target = User::query()->findOrFail($this->userId);
        $this->authorize('update', $target);

        $this->invitationLink = $this->buildInvitationLink($target);
        $audit->log('user.invitation_resent', $target);
        session()->flash('status', 'Nuevo enlace de acceso generado.');
    }

    private function buildInvitationLink(User $user): string
    {
        $token = Password::createToken($user);

        return route('password.reset', ['token' => $token]).'?email='.urlencode($user->email);
    }

    public function render(): View
    {
        $avatarUrl = $this->userId !== null
            ? User::query()->find($this->userId)?->avatarUrl()
            : null;

        return view('identity::livewire.users.form', [
            'roles' => UserRole::cases(),
            'departments' => User::DEPARTMENTS,
            'canGrantSuperAdmin' => config('crm.multi_institution') && (bool) auth()->user()?->isSuperAdmin(),
            'editing' => $this->userId !== null,
            'avatarUrl' => $avatarUrl,
        ]);
    }
}
