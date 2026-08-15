<?php

declare(strict_types=1);

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Modules\Identity\Enums\UserRole;
use Modules\Institutions\Models\Institution;

/**
 * Usuario del PANEL.
 *
 * Supuesto documentado (Bloque 0): el modelo vive en App\Models\User porque es
 * donde la autenticacion de Laravel lo espera por convencion (config/auth.php,
 * UserFactory). El modulo Identity gobierna su LOGICA de dominio (roles, policies,
 * pantallas). Aqui esta el modelo enriquecido.
 *
 * User NO usa BelongsToInstitution: la autenticacion ocurre por email (unico
 * global) ANTES de que exista contexto de institucion. El aislamiento de los
 * listados de usuarios en el panel se hace con un scope explicito por institucion.
 *
 * @property int|null $institution_id
 * @property string $name
 * @property string $email
 * @property UserRole $role
 * @property bool $is_super_admin
 * @property string $preferred_language
 * @property string $status
 */
#[Fillable(['institution_id', 'name', 'email', 'password', 'role', 'is_super_admin', 'preferred_language', 'status'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'is_super_admin' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Institution, $this>
     */
    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }

    public function isSuperAdmin(): bool
    {
        return (bool) $this->is_super_admin;
    }

    public function hasRole(UserRole $role): bool
    {
        return $this->role === $role;
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * ¿Puede entrar al panel? Debe estar activo. (El gating fino por rol lo
     * hacen las Policies de cada accion.)
     */
    public function canAccessPanel(): bool
    {
        return $this->isActive();
    }

    /**
     * ¿Puede gestionar usuarios del panel? Solo Administrador o super-admin.
     */
    public function canManageUsers(): bool
    {
        return $this->isSuperAdmin() || $this->role === UserRole::Admin;
    }

    /**
     * ¿Puede gestionar integraciones y credenciales? Solo Administrador o super.
     * Marketing y Admisiones no tienen acceso al almacen de secretos.
     */
    public function canManageIntegrations(): bool
    {
        return $this->isSuperAdmin() || $this->role === UserRole::Admin;
    }

    /**
     * ¿Puede gestionar el catalogo? Administrador o Marketing (Admisiones no).
     */
    public function canManageCatalog(): bool
    {
        return $this->isSuperAdmin()
            || $this->role === UserRole::Admin
            || $this->role === UserRole::Marketing;
    }
}
