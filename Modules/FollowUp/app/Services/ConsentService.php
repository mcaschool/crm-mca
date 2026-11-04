<?php

declare(strict_types=1);

namespace Modules\FollowUp\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Audit\Services\AuditService;
use Modules\Crm\Models\Contact;
use Modules\FollowUp\Models\CommunicationConsent;
use Modules\FollowUp\Models\CommunicationConsentEvent;
use Modules\Social\Models\SocialConversation;

final class ConsentService
{
    public function __construct(private readonly AuditService $audit, private readonly ActionInvalidator $invalidator, private readonly FollowUpEventRecorder $events) {}

    public static function subjectKey(?int $contactId, ?int $conversationId): string
    {
        if (($contactId === null) === ($conversationId === null)) {
            throw ValidationException::withMessages(['subject' => 'Debe identificarse exactamente un contacto o una conversación.']);
        }

        return $contactId !== null ? 'contact:'.$contactId : 'conversation:'.$conversationId;
    }

    public function record(User $actor, ?int $contactId, ?int $conversationId, string $channel, string $purpose, string $state, ?string $source, ?string $evidence, string $policyVersion = '2.0'): CommunicationConsent
    {
        abort_unless($actor->canManageSettings(), 403);
        if (! in_array($state, CommunicationConsent::STATES, true)) {
            throw ValidationException::withMessages(['state' => 'Estado de consentimiento inválido.']);
        }
        if (! in_array($purpose, CommunicationConsent::PURPOSES, true)) {
            throw ValidationException::withMessages(['purpose' => 'Finalidad inválida.']);
        }
        if (! in_array($channel, ['whatsapp', 'instagram', 'messenger', 'email'], true)) {
            throw ValidationException::withMessages(['channel' => 'Canal inválido.']);
        }
        if ($state !== 'unknown' && (trim((string) $source) === '' || trim((string) $evidence) === '')) {
            throw ValidationException::withMessages(['evidence' => 'El origen y la evidencia son obligatorios para estados explícitos.']);
        }
        $subjectKey = self::subjectKey($contactId, $conversationId);
        $subjectExists = $contactId !== null
            ? Contact::query()->whereKey($contactId)->exists()
            : SocialConversation::query()->whereKey($conversationId)->exists();
        if (! $subjectExists) {
            throw ValidationException::withMessages(['subject' => 'El sujeto no pertenece a la institución activa.']);
        }

        return DB::transaction(function () use ($actor, $contactId, $conversationId, $channel, $purpose, $state, $source, $evidence, $policyVersion, $subjectKey): CommunicationConsent {
            $consent = CommunicationConsent::query()->where('subject_key', $subjectKey)->where('channel', $channel)->where('purpose', $purpose)->lockForUpdate()->first();
            $from = $consent?->state;
            $consent ??= new CommunicationConsent;
            $consent->fill([
                'institution_id' => $actor->institution_id, 'contact_id' => $contactId, 'social_conversation_id' => $conversationId,
                'subject_key' => $subjectKey, 'channel' => $channel, 'purpose' => $purpose, 'state' => $state,
                'source' => $source, 'evidence' => $evidence, 'recorded_by_user_id' => $actor->id,
                'policy_version' => $policyVersion, 'lock_version' => max(0, (int) $consent->lock_version) + 1,
                'granted_at' => $state === 'granted' ? now() : null,
                'withdrawn_at' => $state === 'withdrawn' ? now() : null,
            ])->save();
            CommunicationConsentEvent::query()->create([
                'institution_id' => $actor->institution_id, 'communication_consent_id' => $consent->id, 'from_state' => $from,
                'to_state' => $state, 'source' => $source, 'evidence' => $evidence, 'actor_user_id' => $actor->id,
                'policy_version' => $policyVersion, 'occurred_at' => now(),
            ]);
            $this->invalidator->forSubject($subjectKey, $channel, $purpose, 'consent_changed');
            $this->events->record('consent.changed', reason: 'consent_changed', safeData: ['consent_id' => (int) $consent->id, 'state' => $state, 'channel' => $channel, 'purpose' => $purpose, 'lock_version' => $consent->lock_version]);
            $this->audit->log('follow_up.consent_changed', $consent, ['from' => $from, 'to' => $state, 'channel' => $channel, 'purpose' => $purpose, 'has_evidence' => trim((string) $evidence) !== '']);

            return $consent;
        }, 3);
    }
}
