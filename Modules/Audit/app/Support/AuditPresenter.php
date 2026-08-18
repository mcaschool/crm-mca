<?php

declare(strict_types=1);

namespace Modules\Audit\Support;

use Illuminate\Support\Str;

/**
 * Traduce las acciones y entidades tecnicas de la auditoria a etiquetas legibles
 * para la vista de solo lectura del Administrador, y agrupa cada accion en una
 * categoria visual (ok / info / warn / danger / neutral) para el chip de color.
 */
final class AuditPresenter
{
    /** @var array<string, array{label:string, group:string}> */
    private const ACTIONS = [
        // Autenticacion / sesion
        'auth.login_success' => ['label' => 'Inicio de sesión', 'group' => 'ok'],
        'auth.login_failed' => ['label' => 'Inicio de sesión fallido', 'group' => 'warn'],
        'auth.lockout' => ['label' => 'Bloqueo por fuerza bruta', 'group' => 'danger'],
        'auth.logout' => ['label' => 'Cierre de sesión', 'group' => 'neutral'],
        'auth.2fa_failed' => ['label' => 'Código 2FA incorrecto', 'group' => 'warn'],
        'auth.password_reset' => ['label' => 'Restablecimiento de contraseña', 'group' => 'ok'],
        // Cuenta / 2FA
        '2fa.enabled' => ['label' => 'Verificación en dos pasos activada', 'group' => 'ok'],
        '2fa.disabled' => ['label' => 'Verificación en dos pasos desactivada', 'group' => 'warn'],
        'account.password_changed' => ['label' => 'Contraseña cambiada', 'group' => 'ok'],
        // Gestion de usuarios
        'user.created' => ['label' => 'Usuario creado', 'group' => 'ok'],
        'user.invited' => ['label' => 'Invitación enviada', 'group' => 'neutral'],
        'user.invitation_resent' => ['label' => 'Invitación reenviada', 'group' => 'neutral'],
        'user.role_changed' => ['label' => 'Cambio de rol', 'group' => 'info'],
        'user.department_changed' => ['label' => 'Cambio de departamento', 'group' => 'info'],
        'user.activated' => ['label' => 'Usuario activado', 'group' => 'ok'],
        'user.deactivated' => ['label' => 'Usuario desactivado', 'group' => 'warn'],
        'user.national_id_viewed' => ['label' => 'Acceso a número de identidad', 'group' => 'info'],
        // Integraciones / secretos
        'integration.created' => ['label' => 'Integración creada', 'group' => 'ok'],
        'integration.updated' => ['label' => 'Integración actualizada', 'group' => 'info'],
        'integration.tested' => ['label' => 'Prueba de conexión', 'group' => 'neutral'],
        'integration.activated' => ['label' => 'Integración activada', 'group' => 'ok'],
        'integration.deactivated' => ['label' => 'Integración desactivada', 'group' => 'warn'],
        // Acceso a datos personales sensibles
        'contact.personal_data_viewed' => ['label' => 'Acceso a datos personales', 'group' => 'info'],
        // Retencion / mantenimiento
        'retention.purged' => ['label' => 'Purga por retención', 'group' => 'neutral'],
    ];

    /** @var array<string, string> */
    private const ENTITIES = [
        'App\Models\User' => 'Usuario',
        'Modules\Integrations\Models\Integration' => 'Integración',
        'Modules\Crm\Models\Contact' => 'Contacto',
        'Modules\Crm\Models\Lead' => 'Lead',
        'Modules\Audit\Models\AuditLog' => 'Registro',
        'Modules\Institutions\Models\Institution' => 'Institución',
    ];

    public static function actionLabel(string $action): string
    {
        return self::ACTIONS[$action]['label'] ?? Str::of($action)->replace(['.', '_'], ' ')->ucfirst()->toString();
    }

    /** Categoria visual del chip: ok | info | warn | danger | neutral. */
    public static function actionGroup(string $action): string
    {
        return self::ACTIONS[$action]['group'] ?? 'neutral';
    }

    public static function entityLabel(string $type, int|string|null $id = null): string
    {
        $label = self::ENTITIES[$type] ?? class_basename($type);

        return $id ? $label.' #'.$id : $label;
    }
}
