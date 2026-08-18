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
 * @property string|null $national_id
 * @property string|null $department
 * @property string|null $avatar_path
 * @property string|null $two_factor_secret
 * @property array<int,string>|null $two_factor_recovery_codes
 * @property \Illuminate\Support\Carbon|null $two_factor_confirmed_at
 * @property UserRole $role
 * @property bool $is_super_admin
 * @property string $preferred_language
 * @property string $status
 */
#[Fillable(['institution_id', 'name', 'email', 'national_id', 'department', 'avatar_path', 'password', 'role', 'is_super_admin', 'preferred_language', 'status'])]
#[Hidden(['password', 'remember_token', 'national_id'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Departamentos del equipo (lista fija). "Contabilidad y Finanzas" es el equipo
     * administrativo que gestiona pagos — NO confundir con el AREA academica
     * "Economia y Finanzas" (categoria de microcredencial). Espejo de
     * config('crm.departments').
     */
    public const DEPARTMENTS = ['Admisiones', 'Marketing', 'Administrativo', 'Contabilidad y Finanzas', 'Soporte', 'Académico'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            // Numero de identidad: cifrado en reposo (AES via APP_KEY), como los
            // secretos. En codigo se lee/escribe en claro; en BD queda cifrado.
            'national_id' => 'encrypted',
            // 2FA: secreto TOTP y codigos de recuperacion CIFRADOS en reposo.
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
            'role' => UserRole::class,
            'is_super_admin' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }

    /** ¿Tiene el segundo factor (TOTP) activo y confirmado? */
    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_confirmed_at !== null && $this->two_factor_secret !== null;
    }

    /**
     * Numero de identidad ENMASCARADO para la UI (solo se ven los ultimos digitos).
     * Nunca se expone completo salvo al Admin en el formulario de gestion.
     */
    public function maskedNationalId(): ?string
    {
        $id = (string) ($this->national_id ?? '');
        if ($id === '') {
            return null;
        }
        $tail = mb_substr($id, -4);

        return str_repeat('•', max(4, mb_strlen($id) - 4)).$tail;
    }

    /** Carpeta de la foto de perfil del usuario (por id). */
    public function avatarFolder(): string
    {
        return 'users/'.$this->getKey();
    }

    /** URL publica de la foto de perfil (o null: la UI usa el avatar por defecto). */
    public function avatarUrl(): ?string
    {
        if ($this->avatar_path === null || $this->avatar_path === '') {
            return null;
        }

        return \Illuminate\Support\Facades\Storage::disk('public')->url($this->avatar_path);
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
     * ¿Puede ver la auditoria de seguridad (solo lectura)? Solo Administrador o super.
     */
    public function canViewAudit(): bool
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

    /**
     * ¿Puede trabajar el CRM? Los tres roles del panel (Admin, Marketing,
     * Admisiones) trabajan los prospectos. Las acciones destructivas se reservan
     * a Admin (canManageUsers).
     */
    public function canWorkCrm(): bool
    {
        return $this->isSuperAdmin()
            || in_array($this->role, [UserRole::Admin, UserRole::Marketing, UserRole::Admissions], true);
    }

    /**
     * Persona del CRM: combina el rol (decision cerrada: 3 roles) con el
     * departamento para las dos variantes operativas basadas en area. Gobierna el
     * dashboard y el gating de acciones de forma consistente en todo el CRM.
     *
     *  - admin       Admin/super: ve y hace todo.
     *  - marketing    Marketing: ve todo en SOLO LECTURA (metricas/graficas).
     *  - academico    Admisiones + depto Academico: referidos en primer plano, actua.
     *  - soporte      Admisiones + depto Soporte: solo lectura salvo sus referidos.
     *  - admisiones   Admisiones (resto): operativo, actua.
     */
    public function crmPersona(): string
    {
        if ($this->isSuperAdmin() || $this->role === UserRole::Admin) {
            return 'admin';
        }
        if ($this->role === UserRole::Marketing) {
            return 'marketing';
        }

        return match ($this->department) {
            'Académico' => 'academico',
            'Soporte' => 'soporte',
            default => 'admisiones',
        };
    }

    /**
     * ¿El CRM es de solo lectura para este usuario POR DEFECTO? Marketing y Soporte
     * no editan en general. (Soporte SI puede actuar sobre los leads referidos a el;
     * ese matiz por-lead lo resuelve LeadPolicy::update, no este flag general.)
     */
    public function crmReadOnly(): bool
    {
        return in_array($this->crmPersona(), ['marketing', 'soporte'], true);
    }
}
