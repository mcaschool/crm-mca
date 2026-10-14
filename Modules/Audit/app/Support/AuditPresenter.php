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
        'user.email_code_changed' => ['label' => 'Cambio del modo código de correo', 'group' => 'neutral'],
        // Integraciones / secretos
        'integration.created' => ['label' => 'Integración creada', 'group' => 'ok'],
        'integration.updated' => ['label' => 'Integración actualizada', 'group' => 'info'],
        'integration.tested' => ['label' => 'Prueba de conexión', 'group' => 'neutral'],
        'integration.activated' => ['label' => 'Integración activada', 'group' => 'ok'],
        'integration.deactivated' => ['label' => 'Integración desactivada', 'group' => 'warn'],
        'integration.connected' => ['label' => 'Integración conectada', 'group' => 'neutral'],
        'integration.disconnected' => ['label' => 'Integración desconectada', 'group' => 'neutral'],
        // Acceso a datos personales sensibles
        'contact.personal_data_viewed' => ['label' => 'Acceso a datos personales', 'group' => 'info'],
        'contact.phone_revealed' => ['label' => 'Teléfono del contacto revelado', 'group' => 'neutral'],
        // Leads y videoconferencias (etiquetas solo de presentación; el grupo neutral conserva el color previo)
        'lead.status_changed' => ['label' => 'Cambio de estado del lead', 'group' => 'neutral'],
        'incompany_lead.received' => ['label' => 'Lead InCompany recibido', 'group' => 'neutral'],
        'lead_intake.received' => ['label' => 'Lead recibido por integración', 'group' => 'neutral'],
        'lead_intake.conflict' => ['label' => 'Conflicto al recibir un lead por integración', 'group' => 'neutral'],
        'video_room.assigned' => ['label' => 'Sala de videoconferencia asignada', 'group' => 'neutral'],
        'video_room.generated' => ['label' => 'Sala de videoconferencia generada', 'group' => 'neutral'],
        'video_room.regenerated' => ['label' => 'Sala de videoconferencia regenerada', 'group' => 'neutral'],
        'video_room.applied' => ['label' => 'Salas de videoconferencia aplicadas', 'group' => 'neutral'],
        'video_room.secured' => ['label' => 'Configuración segura aplicada a las salas de Zoom', 'group' => 'info'],
        'video_room.host_start_mode_changed' => ['label' => 'Inicio de reuniones desde el CRM configurado', 'group' => 'warn'],
        'video_room.host_started' => ['label' => 'Reunión de Zoom iniciada como anfitrión', 'group' => 'neutral'],
        // Retencion / mantenimiento
        'retention.purged' => ['label' => 'Purga por retención', 'group' => 'neutral'],
        // Catalogo de programas (alta manual desde el Centro de Conocimiento)
        'program.created' => ['label' => 'Programa creado', 'group' => 'ok'],
        'program.activated' => ['label' => 'Programa activado', 'group' => 'ok'],
        // Formularios publicitarios (Meta)
        'meta.connected' => ['label' => 'Meta conectado', 'group' => 'ok'],
        'meta.disconnected' => ['label' => 'Meta desconectado', 'group' => 'warn'],
        'meta_lead_page.transferred_in' => ['label' => 'Página transferida a esta empresa', 'group' => 'info'],
        'meta_lead_page.transferred_out' => ['label' => 'Página transferida a otra empresa', 'group' => 'warn'],
        'meta_lead_page.receiving_enabled' => ['label' => 'Recepción de formularios activada', 'group' => 'ok'],
        'meta_lead_page.receiving_paused' => ['label' => 'Recepción de formularios en pausa', 'group' => 'neutral'],
        'meta_lead_page.reception_test' => ['label' => 'Prueba completa de recepción', 'group' => 'info'],
        // Publicador (borradores, programación y gestión por red)
        'social_post.draft_saved' => ['label' => 'Borrador de publicación guardado', 'group' => 'neutral'],
        'social_post.scheduled' => ['label' => 'Publicación programada', 'group' => 'info'],
        'social_post.rescheduled' => ['label' => 'Publicación reprogramada', 'group' => 'info'],
        'social_post.cancelled' => ['label' => 'Programación cancelada', 'group' => 'warn'],
        'social_post.returned_to_draft' => ['label' => 'Publicación devuelta a borrador', 'group' => 'neutral'],
        'social_post.published_now' => ['label' => 'Publicación lanzada manualmente', 'group' => 'info'],
        'social_post.deleted' => ['label' => 'Borrador de publicación eliminado', 'group' => 'warn'],
        'social_post.retry' => ['label' => 'Reintento de publicación en una red', 'group' => 'info'],
        'social_post.remote_deleted' => ['label' => 'Publicación eliminada de Facebook', 'group' => 'warn'],
        'social_post.remote_updated' => ['label' => 'Texto editado en Facebook', 'group' => 'info'],
        'social_post.executed' => ['label' => 'Publicación ejecutada', 'group' => 'ok'],
        'social_post.interrupted' => ['label' => 'Publicación interrumpida', 'group' => 'warn'],
    ];

    /** @var array<string, string> */
    private const ENTITIES = [
        'App\Models\User' => 'Usuario',
        'Modules\Integrations\Models\Integration' => 'Integración',
        'Modules\Crm\Models\Contact' => 'Contacto',
        'Modules\Crm\Models\Lead' => 'Lead',
        'Modules\Audit\Models\AuditLog' => 'Registro',
        'Modules\Institutions\Models\Institution' => 'Institución',
        'Modules\Catalog\Models\Program' => 'Programa',
        'Modules\Social\Models\MetaConnection' => 'Conexión con Meta',
        'Modules\Social\Models\MetaLeadPage' => 'Página de formularios',
        'Modules\Social\Models\SocialPost' => 'Publicación',
        'Modules\Crm\Models\VideoRoom' => 'Sala de videoconferencia',
    ];

    public static function actionLabel(string $action): string
    {
        return isset(self::ACTIONS[$action]) ? __(self::ACTIONS[$action]['label']) : Str::of($action)->replace(['.', '_'], ' ')->ucfirst()->toString();
    }

    /** Categoria visual del chip: ok | info | warn | danger | neutral. */
    public static function actionGroup(string $action): string
    {
        return self::ACTIONS[$action]['group'] ?? 'neutral';
    }

    public static function entityLabel(string $type, int|string|null $id = null): string
    {
        $label = isset(self::ENTITIES[$type]) ? __(self::ENTITIES[$type]) : class_basename($type);

        return $id ? $label.' #'.$id : $label;
    }
}
