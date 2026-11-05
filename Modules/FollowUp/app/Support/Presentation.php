<?php

declare(strict_types=1);

namespace Modules\FollowUp\Support;

final class Presentation
{
    public static function draftLabel(\Modules\FollowUp\Models\FollowUpAction $action): string
    {
        if ($action->draft_state !== 'review') {
            return self::label($action->draft_state);
        }
        if ($action->draft_expires_at?->isPast()) {
            return 'Caducado · requiere actualización';
        }
        if ($action->approved_at && $action->approved_draft_version === $action->draft_version && $action->approval_expires_at?->isFuture()) {
            return 'Aprobado';
        }

        return $action->draft_edited_at ? 'Editado · requiere revisión' : 'Requiere revisión';
    }

    public static function label(?string $value): string
    {
        return match ($value) {
            'execution.approved' => 'Contenido aprobado', 'policy.evaluated' => 'Política evaluada', 'draft.edited' => 'Borrador editado',
            'commercial.synchronized' => 'Contexto comercial actualizado', 'consent.changed', 'consent_changed' => 'Consentimiento actualizado',
            'action.cancelled' => 'Acción cancelada', 'action.rescheduled' => 'Acción pospuesta', 'action.scheduled' => 'Acción programada',
            'draft.generated' => 'Borrador generado', 'draft.requested' => 'Generación solicitada', 'draft.invalidated' => 'Borrador invalidado',
            'draft.discarded' => 'Borrador descartado', 'activity_scheduled' => 'Actividad programada', 'commercial_human_hold' => 'Pausa por atención humana',
            'silent_after_response' => 'Sin respuesta tras el último intercambio', 'draft_message' => 'Preparar un borrador',
            'general_interest' => 'Interés general', 'pending_question' => 'Pregunta pendiente', 'schedule_allowed' => 'Programación permitida',
            null, '', 'null' => 'Sin información', 'open' => 'Abierta', 'closed' => 'Cerrada', 'suppressed' => 'Bloqueada',
            'cancelled' => 'Cancelada', 'paused' => 'Pausada', 'draft' => 'Pendiente', 'pending_approval' => 'Pendiente de aprobación',
            'approved' => 'Aprobada', 'scheduled' => 'Programada', 'claimed', 'sending' => 'En proceso', 'sent' => 'Enviada',
            'delivered' => 'Entregada', 'read' => 'Leída', 'failed', 'error' => 'Error', 'expired' => 'Caducada', 'delivery_unknown' => 'Resultado desconocido',
            'queued', 'generating' => 'Generando', 'review' => 'Requiere revisión', 'invalid' => 'Invalidado', 'discarded' => 'Descartado', 'none' => 'Sin borrador',
            'empty' => 'Sin borrador', 'no_contact' => 'No contactar', 'granted' => 'Concedido', 'denied' => 'Denegado', 'withdrawn' => 'Retirado', 'unknown' => 'Sin verificar',
            'allowed' => 'Permitida', 'high' => 'Alta', 'medium' => 'Media', 'low' => 'Baja', 'new' => 'Nuevo', 'contacted' => 'Contactado', 'qualified' => 'Cualificado', 'enrolled' => 'Matriculado',
            'inferred' => 'Inferencia · requiere revisión', 'observed' => 'Hecho registrado', 'confirmed_conversion', 'confirmed_crm' => 'Confirmado en CRM',
            'continue_conversation' => 'Continuar conversación', 'admissions_task' => 'Revisar inscripción', 'call_proposal' => 'Coordinar llamada', 'meeting_proposal' => 'Proponer reunión',
            'human_review' => 'Revisión humana', 'no_action' => 'Esperar sin contactar', 'close' => 'Cerrar seguimiento', 'keep' => 'Mantener etapa',
            'suggest_contacted' => 'Sugerir contactado', 'suggest_qualified' => 'Sugerir cualificado', 'suggest_discarded' => 'Revisar pérdida de interés',
            'converted' => 'Matrícula confirmada', 'contact_excluded', 'contact_unsubscribed' => 'Contacto excluido', 'consent_unknown' => 'Falta verificar el consentimiento',
            'consent_evidence_missing' => 'Falta verificar el origen, la evidencia o la fecha del consentimiento',
            'actor_not_authorized' => 'La cuenta no está autorizada para esta institución',
            'follow_up_gateway_unauthorized' => 'La acción no conserva una autorización de despacho válida',
            'opportunity_not_open' => 'La oportunidad ya no está abierta', 'human_intervention', 'explicit_resume_required' => 'Requiere revisión humana para continuar',
            'activity_pending' => 'Hay una actividad pendiente', 'activity_result_review' => 'Revisar el resultado de la actividad',
            'maximum_attempts_reached' => 'Límite acumulado alcanzado', 'minimum_interval_not_met' => 'Esperando el intervalo mínimo',
            'ambiguous_lead' => 'Hay varios Leads posibles', 'conflicting_owners' => 'Revisar responsables distintos', 'invalid_responsible' => 'Revisar responsable no disponible',
            'commercial_version_conflict', 'approval_context_obsolete', 'draft_version_conflict' => 'El contenido cambió. Actualiza y revisa la versión vigente.',
            'commercial_closed' => 'La oportunidad está cerrada y no admite esta operación.',
            'human_intervention_pending' => 'Finaliza la actividad o intervención pendiente antes de reanudar.',
            'requested_follow_up' => 'Seguimiento solicitado', 'marketing' => 'Marketing', 'operational' => 'Operacional',
            'verified_phone' => 'Teléfono verificado', 'manual' => 'Verificación administrativa', 'unresolved' => 'Vínculo pendiente',
            default => ucfirst(str_replace(['_', '.'], ' ', $value)),
        };
    }

    public static function tone(?string $value): string
    {
        return match ($value) {
            'failed', 'error', 'invalid', 'denied', 'withdrawn', 'suppressed', 'high' => 'danger',
            'pending_approval', 'review', 'paused', 'delivery_unknown', 'unknown', 'medium' => 'warning',
            'sent', 'delivered', 'read', 'granted', 'allowed', 'enrolled' => 'success',
            default => 'neutral',
        };
    }
}
