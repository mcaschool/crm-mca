<?php

declare(strict_types=1);

namespace Modules\FollowUp\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Modules\Audit\Services\AuditService;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Crm\Models\Activity;
use Modules\Crm\Models\Contact;
use Modules\Crm\Models\Conversation;
use Modules\Crm\Models\Lead;
use Modules\Crm\Models\ProgramInterest;
use Modules\Crm\Services\Agenda\ActivityService;
use Modules\FollowUp\Models\CommunicationConsent;
use Modules\FollowUp\Models\FollowUpOpportunity;
use Modules\Social\Models\SocialMessage;
use Modules\Social\Support\ConversationContactLink;

final class FollowUpCommercialCoordinator
{
    public function __construct(private readonly ActionInvalidator $invalidator, private readonly FollowUpEventRecorder $events, private readonly ActivityService $activities) {}

    public function sync(int $id): FollowUpOpportunity
    {
        return DB::transaction(function () use ($id): FollowUpOpportunity {
            $op = FollowUpOpportunity::query()->lockForUpdate()->findOrFail($id);
            $conversation = $op->socialConversation;
            $ambiguous = false;
            if ($op->commercial_link_source !== 'manual' && $conversation !== null) {
                $contact = ConversationContactLink::resolve($conversation)['contact'];
                $candidates = $contact === null ? collect() : Lead::query()->where('contact_id', $contact->id)->limit(2)->get();
                $ambiguous = $candidates->count() > 1;
                $op->contact_id = $contact?->id;
                $op->lead_id = $candidates->count() === 1 ? $candidates->first()->id : null;
                $op->commercial_link_source = $contact === null ? 'unresolved' : 'verified_phone';
            }
            $contact = $op->contact_id === null ? null : Contact::query()->find($op->contact_id);
            $lead = $op->lead_id === null ? null : Lead::query()->lockForUpdate()->find($op->lead_id);
            $crmConversation = $op->crm_conversation_id === null ? null : Conversation::query()->where('contact_id', $op->contact_id)->where('bot_id', $op->bot_id)->where('is_test', false)->find($op->crm_conversation_id);
            $activity = $op->commercial_activity_id === null ? null : Activity::query()->find($op->commercial_activity_id);
            if ($activity === null && $contact !== null) {
                $activity = Activity::query()->where('contact_id', $contact->id)->where('status', 'scheduled')->orderBy('starts_at')->first();
                $op->commercial_activity_id = $activity?->id;
            }
            $owners = array_values(array_unique(array_filter([$lead?->assigned_to_user_id, $conversation?->assigned_to, $activity?->isScheduled() ? $activity->assigned_to_user_id : null])));
            $ownerId = count($owners) === 1 ? (int) $owners[0] : (count($owners) > 1 ? null : $op->commercial_owner_id);
            $owner = $ownerId === null ? null : User::query()->where('institution_id', $op->institution_id)->find($ownerId);
            if ($owner !== null && (! $owner->isActive() || ! $owner->canWorkCrm() || $owner->crmReadOnly())) {
                $owner = null;
            }
            $op->commercial_owner_id = $owner?->id;
            $interests = $contact === null ? collect() : ProgramInterest::query()->where('contact_id', $contact->id)->where('bot_id', $op->bot_id)->distinct()->limit(2)->pluck('program_id');
            $op->commercial_program_id = $lead->program_id ?? ($interests->count() === 1 ? $interests->first() : null);
            $messages = SocialMessage::query()->where('social_conversation_id', $op->social_conversation_id)->latest('id')->limit(20)->get();
            $text = \Illuminate\Support\Str::ascii(mb_strtolower($messages->where('direction', 'inbound')->pluck('body')->implode(' ')));
            $subject = ConsentService::subjectKey($op->contact_id, $op->contact_id === null ? $op->social_conversation_id : null);
            $consents = CommunicationConsent::query()->where(function ($q) use ($subject, $op): void {
                $q->where('subject_key', $subject)->orWhere('subject_key', 'conversation:'.$op->social_conversation_id);
            })->where('channel', $op->provider)->get();
            $exclusion = $contact?->unsubscribed_at !== null || $consents->whereIn('state', ['denied', 'withdrawn'])->isNotEmpty()
                || preg_match('/no me contacten|no me escriban|dejen de escribir|darme de baja|no quiero recibir|unsubscribe|stop messaging|do not contact/', $text);
            $close = match (true) {
                $lead?->status->value === 'enrolled' => 'converted',
                $lead?->status->value === 'discarded' => 'discarded',
                (bool) $exclusion => 'contact_excluded',
                default => null,
            };
            $recommendation = ['action' => 'continue_conversation', 'stage' => 'keep', 'basis' => 'inferred', 'reason' => 'general_interest'];
            $result = $op->commercial_result;
            if ($close !== null) {
                $op->status = 'closed';
                $op->commercial_hold = true;
                $op->commercial_close_reason = $close;
                $recommendation = ['action' => 'close', 'stage' => 'keep', 'basis' => $close === 'converted' ? 'confirmed_crm' : 'observed', 'reason' => $close];
                $result = ['kind' => $close === 'converted' ? 'confirmed_conversion' : 'observed', 'code' => $close];
            } elseif ($op->commercial_close_reason !== null) {
                $op->status = 'closed';
                $op->commercial_hold = true;
                $recommendation = ['action' => 'no_action', 'stage' => 'keep', 'basis' => 'observed', 'reason' => 'closed_requires_review'];
            } elseif ($activity !== null) {
                $op->status = 'paused';
                $op->commercial_hold = true;
                $recommendation = ['action' => 'human_review', 'stage' => 'keep', 'basis' => 'observed', 'reason' => $activity->isScheduled() ? 'activity_pending' : 'activity_result_review'];
                $result = ['kind' => 'observed', 'code' => 'activity_'.$activity->status->value, 'activity_id' => $activity->id, 'reschedule_count' => $activity->reschedule_count];
            } elseif (($conversation !== null && ! $conversation->advisorMayReply()) || $crmConversation?->mode === 'human' || ($op->crm_conversation_id !== null && $crmConversation === null)) {
                $op->status = 'paused';
                $op->commercial_hold = true;
                $recommendation = ['action' => 'human_review', 'stage' => 'keep', 'basis' => 'observed', 'reason' => 'human_intervention'];
            } elseif ($op->commercial_hold) {
                $op->status = 'paused';
                $recommendation = $op->commercial_recommendation ?? ['action' => 'human_review', 'stage' => 'keep', 'basis' => 'observed', 'reason' => 'explicit_resume_required'];
            } elseif ($ambiguous || count($owners) > 1 || ($owners !== [] && $owner === null)) {
                $op->status = 'paused';
                $op->commercial_hold = true;
                $recommendation['action'] = 'human_review';
                $recommendation['reason'] = $ambiguous ? 'ambiguous_lead' : (count($owners) > 1 ? 'conflicting_owners' : 'invalid_responsible');
            } elseif (preg_match('/reunion|reunir|meeting|hablar con|representante/', $text)) {
                $recommendation['action'] = 'meeting_proposal';
                $recommendation['reason'] = 'meeting_requested';
            } elseif (preg_match('/llamame|llamada|call me|phone call/', $text)) {
                $recommendation['action'] = 'call_proposal';
                $recommendation['reason'] = 'call_requested';
            } elseif ($op->classification === 'enrollment_intent') {
                $recommendation['action'] = 'admissions_task';
                $recommendation['stage'] = $lead === null ? 'keep' : 'suggest_qualified';
                $recommendation['reason'] = 'enrollment_intent_not_conversion';
            } elseif (preg_match('/presupuesto|caro|budget|expensive|no me interesa|not interested/', $text)) {
                $recommendation['action'] = 'human_review';
                $recommendation['reason'] = 'commercial_objection';
                if (preg_match('/no me interesa|not interested/', $text)) {
                    $recommendation['stage'] = 'suggest_discarded';
                    $recommendation['reason'] = 'loss_of_interest_requires_review';
                }
            } elseif ($op->classification === 'commercial_interest' && $lead?->status->value === 'new') {
                $recommendation['stage'] = 'suggest_contacted';
            }
            $nextEvaluation = $activity?->isScheduled() ? $activity->starts_at : now()->addDay();
            if (! $op->commercial_hold) {
                $settings = app(FollowUpSettingsResolver::class)->effective((int) $op->institution_id, (int) $op->bot_id);
                $attempts = \Modules\FollowUp\Models\FollowUpAction::query()->where('follow_up_opportunity_id', $op->id)->whereNotIn('status', ['cancelled', 'suppressed'])->count();
                if ($attempts >= $settings->maximumAttempts) {
                    $op->status = 'paused';
                    $op->commercial_hold = true;
                    $recommendation = ['action' => 'no_action', 'stage' => 'keep', 'basis' => 'observed', 'reason' => 'maximum_attempts_reached'];
                } elseif ($op->last_outbound_at !== null && $op->last_outbound_at->copy()->addHours($settings->minimumIntervalHours)->isFuture() && $recommendation['action'] === 'continue_conversation') {
                    $recommendation['action'] = 'no_action';
                    $recommendation['reason'] = 'minimum_interval_not_met';
                    $nextEvaluation = $op->last_outbound_at->copy()->addHours($settings->minimumIntervalHours);
                }
            }
            if ($op->status === 'open' && in_array($recommendation['action'], ['human_review', 'call_proposal', 'meeting_proposal', 'admissions_task'], true) && ($op->commercial_reviewed_message_id === null || (int) $messages->max('id') > $op->commercial_reviewed_message_id)) {
                $op->commercial_hold = true;
                $op->status = 'paused';
            }
            if ($result === null || ($result['kind'] ?? '') === 'inferred') {
                $result = ['kind' => 'inferred', 'code' => $recommendation['reason']];
            }
            if ($close === null && $activity === null && $op->cancellation_reason === 'prospect_replied') {
                $result = ['kind' => 'observed', 'code' => 'prospect_replied'];
            }
            $fingerprint = hash('sha256', json_encode([$op->contact_id, $op->lead_id, $op->commercial_owner_id, $op->commercial_program_id, $op->crm_conversation_id, $crmConversation?->getAttributes(), $lead?->getAttributes(), $contact?->getAttributes(), $activity?->getAttributes(), $conversation?->getAttributes(), $messages->map->getAttributes()->all(), $consents->map->getAttributes()->all(), $recommendation, $result, $op->commercial_hold, $op->commercial_close_reason], JSON_THROW_ON_ERROR));
            if ($fingerprint === $op->commercial_fingerprint) {
                return $op;
            }
            $op->forceFill(['commercial_fingerprint' => $fingerprint, 'commercial_recommendation' => $recommendation, 'commercial_result' => $result,
                'commercial_version' => $op->commercial_version + 1, 'commercial_updated_at' => now(), 'next_evaluation_at' => $nextEvaluation])->save();
            if ($op->commercial_close_reason !== null) {
                \Modules\FollowUp\Models\FollowUpCycle::query()->where('follow_up_opportunity_id', $op->id)->where('sequence', $op->cycle)->whereNull('ended_at')
                    ->update(['status' => 'suppressed', 'ended_at' => now(), 'end_reason' => $op->commercial_close_reason]);
            }
            $this->invalidator->forOpportunity((int) $op->id, $close ?? ($op->commercial_hold ? 'commercial_human_hold' : 'commercial_context_changed'));
            $this->events->record('commercial.synchronized', $op, reason: $recommendation['reason'], safeData: ['version' => $op->commercial_version, 'recommendation' => $recommendation['action'], 'lead_id' => $op->lead_id, 'contact_id' => $op->contact_id, 'owner_id' => $op->commercial_owner_id, 'activity_id' => $op->commercial_activity_id, 'basis' => $recommendation['basis'], 'result' => $result['code'] ?? null, 'stage_recommendation' => $recommendation['stage']]);

            return $op;
        }, 3);
    }

    public function link(User $actor, int $id, int $version, int $contactId, ?int $leadId, ?int $conversationId, string $evidence): void
    {
        $this->authorize($actor);
        abort_unless(trim($evidence) !== '' && mb_strlen($evidence) <= 500, 422);
        DB::transaction(function () use ($actor, $id, $version, $contactId, $leadId, $conversationId, $evidence): void {
            $op = FollowUpOpportunity::query()->lockForUpdate()->findOrFail($id);
            $this->version($op, $version);
            $contact = Contact::query()->findOrFail($contactId);
            Gate::forUser($actor)->authorize('view', $contact);
            $lead = $leadId === null ? null : Lead::query()->where('contact_id', $contactId)->findOrFail($leadId);
            if ($lead !== null) {
                Gate::forUser($actor)->authorize('update', $lead);
            }
            if ($conversationId !== null) {
                Conversation::query()->where('contact_id', $contactId)->where('bot_id', $op->bot_id)->where('is_test', false)->findOrFail($conversationId);
            }
            if ($op->contact_id !== $contactId || $op->lead_id !== $leadId) {
                $op->commercial_activity_id = null;
                $op->commercial_reviewed_message_id = null;
                $op->commercial_result = null;
            }
            $op->forceFill(['contact_id' => $contactId, 'lead_id' => $leadId, 'crm_conversation_id' => $conversationId,
                'commercial_link_source' => 'manual', 'commercial_linked_by' => $actor->id, 'commercial_linked_at' => now(), 'commercial_link_evidence' => trim($evidence), 'commercial_fingerprint' => null])->save();
            app(AuditService::class)->log('follow_up.commercial_linked', $op, ['contact_id' => $contactId, 'lead_id' => $leadId, 'has_evidence' => true]);
            $this->sync((int) $op->id);
        }, 3);
    }

    public function createTask(User $actor, int $id, int $version, int $ownerId, string $kind, string $startsAt): Activity
    {
        $this->authorize($actor);
        Gate::forUser($actor)->authorize('create', Activity::class);
        $titles = ['task' => 'Revisar seguimiento comercial', 'admissions_task' => 'Revisar solicitud de inscripción', 'call_proposal' => 'Coordinar una llamada', 'meeting_proposal' => 'Proponer una reunión', 'human_review' => 'Revisión comercial humana'];
        abort_unless(isset($titles[$kind]), 422);
        $this->sync($id);

        return DB::transaction(function () use ($actor, $id, $version, $ownerId, $kind, $startsAt, $titles): Activity {
            $owner = User::query()->where('institution_id', $actor->institution_id)->lockForUpdate()->findOrFail($ownerId);
            abort_unless($owner->isActive() && $owner->canWorkCrm() && ! $owner->crmReadOnly(), 422);
            // Revalidate under the same opportunity/Lead locks as task creation.
            $op = $this->sync($id);
            if ($op->commercial_close_reason !== null || $op->status === 'closed') {
                throw ValidationException::withMessages(['commercial' => 'commercial_closed']);
            }
            if ($op->commercial_activity_id !== null) {
                $existing = Activity::query()->findOrFail($op->commercial_activity_id);
                Gate::forUser($actor)->authorize('view', $existing);

                return $existing;
            }
            $this->version($op, $version);
            if ($op->commercial_owner_id !== null && $op->commercial_owner_id !== $ownerId) {
                throw ValidationException::withMessages(['commercial' => 'responsible_changed']);
            }
            if (($op->commercial_recommendation['reason'] ?? '') === 'conflicting_owners') {
                throw ValidationException::withMessages(['commercial' => 'conflicting_owners']);
            }
            $start = \Illuminate\Support\Carbon::parse($startsAt, 'UTC');
            $activity = $this->activities->create([
                'title' => $titles[$kind], 'type' => 'task', 'medium' => 'none', 'assigned_to_user_id' => $ownerId,
                'lead_id' => $op->lead_id, 'contact_id' => $op->contact_id, 'starts_at' => $start, 'ends_at' => $start->copy()->addMinutes(30),
                'timezone' => 'UTC', 'priority' => $op->priority === 'high' ? 'high' : 'normal', 'reminders' => [],
                'description' => 'Revisión interna de la oportunidad FollowUp #'.$op->id.'. No confirma una cita ni autoriza contactar.',
                'idempotency_key' => 'followup:'.$op->id.':'.$op->cycle.':human',
            ], $actor, notifyAssignment: false);
            $op->forceFill(['commercial_activity_id' => $activity->id, 'commercial_owner_id' => $ownerId, 'commercial_fingerprint' => null])->save();
            $this->sync((int) $op->id);
            app(AuditService::class)->log('follow_up.activity_linked', $op, ['activity_id' => $activity->id, 'owner_id' => $ownerId, 'kind' => $kind]);

            return $activity;
        }, 3);
    }

    public function setHold(User $actor, int $id, int $version, bool $hold): void
    {
        $this->authorize($actor);
        DB::transaction(function () use ($actor, $id, $version, $hold): void {
            $op = FollowUpOpportunity::query()->lockForUpdate()->findOrFail($id);
            $this->version($op, $version);
            if ($op->commercial_close_reason !== null) {
                throw ValidationException::withMessages(['commercial' => 'commercial_closed']);
            }
            $activity = Activity::query()->find($op->commercial_activity_id);
            if (! $hold && ($activity?->isScheduled() || ! $op->socialConversation?->advisorMayReply())) {
                throw ValidationException::withMessages(['commercial' => 'human_intervention_pending']);
            }
            if (! $hold) {
                $op->commercial_reviewed_message_id = SocialMessage::query()->where('social_conversation_id', $op->social_conversation_id)->max('id');
            }
            $op->forceFill(['commercial_hold' => $hold, 'status' => $hold ? 'paused' : 'open', 'commercial_activity_id' => $hold ? $op->commercial_activity_id : null, 'commercial_recommendation' => null, 'commercial_fingerprint' => null])->save();
            $this->events->record($hold ? 'commercial.paused' : 'commercial.resumed', $op, reason: 'explicit_human_decision', safeData: ['actor_id' => $actor->id]);
            app(AuditService::class)->log($hold ? 'follow_up.commercial_paused' : 'follow_up.commercial_resumed', $op);
            $this->sync($id);
        }, 3);
    }

    private function authorize(User $actor): void
    {
        abort_unless($actor->isActive() && $actor->canManageSettings() && (int) $actor->institution_id === app(CurrentInstitution::class)->idOrFail(), 403);
    }

    private function version(FollowUpOpportunity $op, int $version): void
    {
        if ($op->commercial_version !== $version) {
            throw ValidationException::withMessages(['commercial' => 'commercial_version_conflict']);
        }
    }
}
