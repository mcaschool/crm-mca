<?php

declare(strict_types=1);

namespace Modules\FollowUp\Services;

use Carbon\CarbonImmutable;
use DateTimeZone;
use Modules\Crm\Enums\LeadStatus;
use Modules\FollowUp\Data\EffectiveFollowUpSettings;
use Modules\FollowUp\Data\PolicyDecision;
use Modules\FollowUp\Data\PolicyRequest;
use Modules\FollowUp\Models\CommunicationConsent;
use Modules\FollowUp\Models\FollowUpAction;
use Modules\FollowUp\Services\Policies\Channels\EmailPolicy;
use Modules\FollowUp\Services\Policies\Channels\MetaDirectPolicy;
use Modules\FollowUp\Services\Policies\Channels\WhatsAppPolicy;
use Modules\Social\Models\SocialMessage;

final class FollowUpPolicyEngine
{
    public function __construct(
        private readonly FollowUpSettingsResolver $settings,
        private readonly WhatsAppPolicy $whatsApp,
        private readonly MetaDirectPolicy $metaDirect,
        private readonly EmailPolicy $email,
        private readonly FollowUpEventRecorder $events,
    ) {}

    public function evaluate(PolicyRequest $request, bool $record = true): PolicyDecision
    {
        $opportunity = $request->opportunity->loadMissing(['channel', 'socialConversation', 'contact', 'lead']);
        $effective = $this->settings->effective((int) $opportunity->institution_id, (int) $opportunity->bot_id);
        $decision = $this->central($request, $effective);
        if ($record) {
            $this->events->record('policy.evaluated', $opportunity, reason: $decision->reasonCode, safeData: ['stage' => $request->stage, 'status' => $decision->status, 'channel' => $request->channel, 'purpose' => $request->purpose, 'fingerprint' => $decision->fingerprint]);
        }

        return $decision;
    }

    private function central(PolicyRequest $request, EffectiveFollowUpSettings $settings): PolicyDecision
    {
        if (! in_array($request->stage, ['prepare', 'schedule', 'send'], true)) {
            return $this->decision('denied', 'invalid_policy_stage', 'La etapa de política no es válida.', $settings, $request);
        }
        if ($request->stage === 'send') {
            $reason = $request->action === null ? 'execution_action_required' : app(ExecutionSwitches::class)->reason($request->action);
            if ($reason !== null) {
                return $this->decision('denied', $reason, 'La ejecución está bloqueada por sus interruptores o límites.', $settings, $request);
            }
        }
        if (! (bool) config('followup.policy_enabled', false)) {
            return $this->decision('denied', 'global_feature_disabled', 'El motor de seguimiento está desactivado globalmente.', $settings, $request);
        }
        if (! $settings->institutionEnabled) {
            return $this->decision('denied', 'institution_disabled', 'El seguimiento institucional está desactivado.', $settings, $request);
        }
        if (! $settings->advisorEnabled) {
            return $this->decision('denied', 'advisor_disabled', 'El seguimiento del asesor está desactivado.', $settings, $request);
        }
        if (! in_array($settings->mode, ['manual', 'supervised', 'controlled_auto'], true)) {
            return $this->decision('unknown', 'operating_mode_unknown', 'El modo operativo no tiene una política reconocida.', $settings, $request);
        }
        if ($request->actor !== null && ((int) $request->actor->institution_id !== (int) $request->opportunity->institution_id || ! $request->actor->canManageSettings())) {
            return $this->decision('denied', 'actor_not_authorized', 'La persona no está autorizada para esta institución.', $settings, $request);
        }
        if ($request->opportunity->status !== 'open') {
            return $this->decision('denied', 'opportunity_not_open', 'La oportunidad ya no está abierta.', $settings, $request);
        }
        if ($request->opportunity->socialConversation === null || $request->opportunity->socialConversation->status !== 'open') {
            return $this->decision('denied', 'conversation_not_open', 'La conversación no está abierta.', $settings, $request);
        }
        if (! $request->opportunity->socialConversation->advisorMayReply()) {
            return $this->decision('denied', 'human_intervention', 'La conversación está bajo atención humana o pausada.', $settings, $request);
        }
        if ($request->channel !== $request->opportunity->provider || ! in_array($request->channel, $settings->allowedChannels, true)) {
            return $this->decision('denied', 'channel_not_allowed', 'El canal no está permitido por la configuración efectiva.', $settings, $request);
        }
        if ($request->opportunity->channel === null || ! $request->opportunity->channel->is_active || (int) $request->opportunity->channel->institution_id !== (int) $request->opportunity->institution_id) {
            return $this->decision('denied', 'channel_unavailable', 'El canal no está activo y disponible para esta institución.', $settings, $request);
        }
        if (! in_array($settings->timezone, DateTimeZone::listIdentifiers(), true)) {
            return $this->decision('unknown', 'timezone_unknown', 'La zona horaria efectiva no es válida.', $settings, $request);
        }
        if ($request->opportunity->contact?->unsubscribed_at !== null) {
            return $this->decision('denied', 'contact_unsubscribed', 'El contacto está excluido de comunicaciones.', $settings, $request);
        }
        if ($request->opportunity->lead !== null && in_array($request->opportunity->lead->status, [LeadStatus::Enrolled, LeadStatus::Discarded], true)) {
            return $this->decision('denied', 'lead_closed', 'El estado comercial del Lead impide seguimiento.', $settings, $request);
        }

        $newer = SocialMessage::query()->where('social_conversation_id', $request->opportunity->social_conversation_id)->where('id', '>', (int) $request->opportunity->source_last_message_id)->orderByDesc('id')->first();
        if ($newer?->direction === 'inbound') {
            return $this->decision('denied', 'new_inbound_message', 'Existe una respuesta posterior del prospecto.', $settings, $request);
        }
        if ($newer?->direction === 'outbound' && $newer->sender_type !== 'bot') {
            return $this->decision('denied', 'new_human_message', 'Existe una intervención humana posterior.', $settings, $request);
        }

        if ($request->stage === 'prepare') {
            return $this->channel($request, $settings) ?? $this->decision('allowed', 'prepare_allowed', 'La acción puede prepararse localmente; aún no está autorizada para envío.', $settings, $request);
        }

        if ($request->scheduledAt === null || $request->expiresAt === null || $request->expiresAt->lte($request->scheduledAt) || $request->expiresAt->isPast()) {
            return $this->decision('denied', 'invalid_action_validity', 'La programación y caducidad no son válidas.', $settings, $request);
        }
        $attempts = FollowUpAction::query()->where('follow_up_opportunity_id', $request->opportunity->id)->whereNotIn('status', ['cancelled', 'suppressed'])->when($request->action, fn ($q) => $q->whereKeyNot($request->action->id))->count();
        $firstAllowed = $request->opportunity->source_last_message_at->copy()->addHours($settings->firstFollowUpHours);
        if ($attempts === 0 && $request->scheduledAt->lt($firstAllowed)) {
            return $this->decision('denied', 'first_follow_up_not_due', 'Aún no se ha cumplido la espera configurada para el primer seguimiento.', $settings, $request, CarbonImmutable::instance($firstAllowed));
        }
        $local = CarbonImmutable::instance($request->scheduledAt)->setTimezone($settings->timezone);
        if ($settings->allowedDays === [] || $settings->businessHoursStart > $settings->businessHoursEnd) {
            return $this->decision('denied', 'no_business_hours', 'No existe un horario permitido.', $settings, $request);
        }
        if (! in_array($local->isoWeekday(), $settings->allowedDays, true) || $local->format('H:i:s') < $settings->businessHoursStart || $local->format('H:i:s') > $settings->businessHoursEnd) {
            return $this->decision('denied', 'outside_business_hours', 'La fecha queda fuera del horario comercial efectivo.', $settings, $request, $this->nextBusinessTime($local, $settings));
        }
        $minimum = $request->opportunity->last_outbound_at?->copy()->addHours($settings->minimumIntervalHours);
        if ($minimum !== null && $request->scheduledAt->lt($minimum)) {
            return $this->decision('denied', 'minimum_interval_not_met', 'No se ha cumplido el intervalo mínimo desde el último contacto.', $settings, $request, CarbonImmutable::instance($minimum));
        }
        if ($attempts >= $settings->maximumAttempts) {
            return $this->decision('denied', 'maximum_attempts_reached', 'Se alcanzó el límite acumulado de intentos.', $settings, $request);
        }
        $duplicate = FollowUpAction::query()->where('follow_up_opportunity_id', $request->opportunity->id)->where('channel', $request->channel)->where('purpose', $request->purpose)->whereIn('status', FollowUpAction::ACTIVE_STATUSES)->when($request->action, fn ($q) => $q->whereKeyNot($request->action->id))->exists();
        if ($duplicate) {
            return $this->decision('denied', 'duplicate_active_action', 'Ya existe una acción activa equivalente.', $settings, $request);
        }

        $subjectKey = ConsentService::subjectKey($request->opportunity->contact_id, $request->opportunity->contact_id === null ? $request->opportunity->social_conversation_id : null);
        $consent = CommunicationConsent::query()->where('subject_key', $subjectKey)->where('channel', $request->channel)->where('purpose', $request->purpose)->first();
        if ($consent === null || $consent->state === 'unknown') {
            return $this->decision('unknown', 'consent_unknown', 'No existe consentimiento verificable para este canal y finalidad.', $settings, $request);
        }
        if ($consent->state !== 'granted') {
            return $this->decision('denied', 'consent_'.$consent->state, 'El consentimiento vigente bloquea esta acción.', $settings, $request);
        }

        if ($request->stage === 'send') {
            $reason = app(ExecutionAuthorization::class)->reason($request->action, $settings);
            if ($reason !== null) {
                return $this->decision('denied', $reason, 'La autorización de ejecución no está vigente.', $settings, $request);
            }
        }

        return $this->channel($request, $settings) ?? $this->decision('allowed', $request->stage === 'send' ? 'send_allowed' : 'schedule_allowed', 'La evaluación de esta etapa permite continuar.', $settings, $request);
    }

    private function channel(PolicyRequest $request, EffectiveFollowUpSettings $settings): ?PolicyDecision
    {
        return match ($request->channel) {
            'whatsapp' => $this->whatsApp->evaluate($request, $settings),
            'instagram', 'messenger' => $this->metaDirect->evaluate($request, $settings),
            'email' => $this->email->evaluate($request, $settings),
            default => $this->decision('unknown', 'channel_policy_missing', 'No existe política verificada para el canal.', $settings, $request),
        };
    }

    private function nextBusinessTime(CarbonImmutable $from, EffectiveFollowUpSettings $settings): CarbonImmutable
    {
        $candidate = $from->setTimeFromTimeString($settings->businessHoursStart);
        if ($from->format('H:i:s') > $settings->businessHoursEnd) {
            $candidate = $candidate->addDay();
        }
        while (! in_array($candidate->isoWeekday(), $settings->allowedDays, true)) {
            $candidate = $candidate->addDay();
        }

        return $candidate->utc();
    }

    private function decision(string $status, string $code, string $explanation, EffectiveFollowUpSettings $settings, PolicyRequest $request, ?CarbonImmutable $next = null): PolicyDecision
    {
        $at = CarbonImmutable::now();
        $facts = ['opportunity_id' => (int) $request->opportunity->id, 'stage' => $request->stage, 'channel' => $request->channel, 'eligibility_version' => $settings->eligibilityVersion];

        return new PolicyDecision($status, $code, $explanation, 'follow_up.central', $settings->policyVersion, $at, $next, hash('sha256', json_encode($facts).'|'.$status.'|'.$code), $facts);
    }
}
