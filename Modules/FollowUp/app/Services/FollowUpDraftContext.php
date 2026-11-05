<?php

declare(strict_types=1);

namespace Modules\FollowUp\Services;

use Modules\Ai\Models\KnowledgeSource;
use Modules\Ai\Services\AdvisorCatalog;
use Modules\Ai\Services\KnowledgeRetriever;
use Modules\Ai\Services\ProgramAssignmentService;
use Modules\Ai\Support\TextLanguage;
use Modules\Catalog\Models\Program;
use Modules\FollowUp\Models\CommunicationConsent;
use Modules\FollowUp\Models\FollowUpAction;
use Modules\FollowUp\Models\FollowUpAdvisorSetting;
use Modules\FollowUp\Models\FollowUpSetting;
use Modules\Institutions\Models\Bot;
use Modules\Social\Models\SocialMessage;

final class FollowUpDraftContext
{
    public function __construct(private readonly KnowledgeRetriever $knowledge, private readonly AdvisorCatalog $catalog, private readonly ProgramAssignmentService $assignments) {}

    /** @return array{bot:Bot,locale:string,knowledge:string,sources:array<string,string>,catalog:?string,history:list<array{direction:string,text:string}>,facts:array<string,mixed>,fingerprint:string} */
    public function build(FollowUpAction $action): array
    {
        $opportunity = $action->opportunity()->with(['channel', 'socialConversation', 'contact', 'lead'])->firstOrFail();
        $bot = Bot::query()->findOrFail($opportunity->bot_id);
        abort_unless((int) $opportunity->channel?->advisor_bot_id === (int) $bot->id && $bot->status === 'active', 422, 'advisor_changed');
        $messages = SocialMessage::query()->where('social_conversation_id', $opportunity->social_conversation_id)->latest('id')->limit(20)->get()->reverse();
        $history = $messages->map(fn (SocialMessage $message): array => ['direction' => $message->direction, 'text' => mb_substr((string) $message->body, 0, 1000)])->values()->all();
        $inbound = $messages->where('direction', 'inbound')->pluck('body')->filter()->values()->all();
        $question = implode("\n", $inbound);
        $locale = TextLanguage::detect($question) ?? ($bot->default_language === 'en' ? 'en' : 'es');
        $retrieved = $this->knowledge->retrieveWithSources((int) $bot->id, mb_substr($question, -4000), $locale, 4);
        $sources = KnowledgeSource::query()->whereIn('code', $retrieved['sources'])->where('status', 'active')
            ->whereHas('bots', fn ($query) => $query->where('bots.id', $bot->id)->where('bot_knowledge_source.is_active', true))->orderBy('id')->get();
        $references = [];
        foreach ($sources as $source) {
            if ($source->program_id !== null && ! Program::query()->whereKey($source->program_id)->where('status', 'active')->whereIn('id', $this->assignments->assignedIdsQuery((int) $bot->id))->exists()) {
                throw \Illuminate\Validation\ValidationException::withMessages(['draft' => 'academic_source_unavailable']);
            }
            $references[$source->code] = (string) $source->translate('content', $locale);
        }
        $programs = Program::query()->whereIn('id', $this->assignments->assignedIdsQuery((int) $bot->id))->where('status', 'active')->orderBy('id')->get();
        $previous = FollowUpAction::query()->where('follow_up_opportunity_id', $opportunity->id)->whereKeyNot($action->id)->whereNotNull('draft_payload')->latest('id')->limit(5)->get();
        $facts = [
            'name' => $opportunity->contact->first_name ?? $opportunity->socialConversation?->contact_name,
            'interest_level' => $opportunity->lead?->interest_level->value,
            'program_id' => $opportunity->lead?->program_id,
            'whatsapp_cadence' => $opportunity->provider === 'whatsapp' ? [
                'slot' => $action->cadence_slot,
                'window_expires_at' => app(WhatsAppWindow::class)->inspect((int) $opportunity->social_conversation_id)['expires_at']?->toIso8601String(),
                'instruction' => 'Cada seguimiento debe aportar valor nuevo y verificable. No insistir por cumplir una cuota. Recomendar no contactar o intervención humana si no existe valor adicional.',
            ] : null,
            'classification' => $opportunity->classification,
            'priority' => $opportunity->priority,
            'reason_codes' => $opportunity->reason_codes,
            'last_activity' => $opportunity->source_last_message_at->toIso8601String(),
            'lead_status' => $opportunity->lead?->status->value,
            'previous_drafts' => $previous->map(fn (FollowUpAction $item) => $item->draft_payload['draft_text'] ?? null)->filter()->values()->all(),
            'inferences' => 'Las clasificaciones son señales deterministas. Las necesidades, objeciones y compromisos solo son hechos si aparecen en el historial.',
        ];
        $subject = ConsentService::subjectKey($opportunity->contact_id, $opportunity->contact_id === null ? $opportunity->social_conversation_id : null);
        $version = [
            $opportunity->getAttributes(), $opportunity->channel?->getAttributes(), $opportunity->socialConversation?->getAttributes(),
            $opportunity->contact?->getAttributes(), $opportunity->lead?->getAttributes(), $bot->getAttributes(),
            $messages->map->getAttributes()->all(), $sources->map->getAttributes()->all(), $programs->map->getAttributes()->all(),
            FollowUpSetting::query()->first()?->getAttributes(), FollowUpAdvisorSetting::query()->where('bot_id', $bot->id)->first()?->getAttributes(),
            CommunicationConsent::query()->where('subject_key', $subject)->where('channel', $action->channel)->where('purpose', $action->purpose)->first()?->getAttributes(),
            $action->id, $action->channel, $action->purpose, $action->eligibility_version, $action->expires_at?->toIso8601String(),
            $facts['previous_drafts'],
        ];

        return ['bot' => $bot, 'locale' => $locale, 'knowledge' => mb_substr($retrieved['text'], 0, 16000), 'sources' => $references,
            'catalog' => $this->catalog->promptIndex((int) $bot->id, $locale), 'history' => $history, 'facts' => $facts,
            'fingerprint' => hash('sha256', json_encode($version, JSON_THROW_ON_ERROR))];
    }
}
