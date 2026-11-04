<?php

declare(strict_types=1);

namespace Modules\FollowUp\Services;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Modules\Crm\Models\Lead;
use Modules\FollowUp\Models\FollowUpCycle;
use Modules\FollowUp\Models\FollowUpOpportunity;
use Modules\Social\Models\SocialConversation;
use Modules\Social\Models\SocialMessage;
use Modules\Social\Support\ConversationContactLink;

final class OpportunityDetector
{
    public function __construct(private readonly OpportunityClassifier $classifier, private readonly ActionInvalidator $invalidator) {}

    /** @return array{detected:int,updated:int,cancelled:int,skipped:int} */
    public function detectDue(?CarbonInterface $at = null): array
    {
        $result = ['detected' => 0, 'updated' => 0, 'cancelled' => 0, 'skipped' => 0];

        if (! (bool) config('followup.detection_enabled', false)) {
            return $result;
        }

        $at ??= now();
        $batch = max(1, min(500, (int) config('followup.batch_size', 100)));
        $result['cancelled'] = $this->reconcileProspectResponses($batch);

        foreach ($this->candidateIds($at, $batch) as $conversationId) {
            $outcome = $this->detectConversation((int) $conversationId, $at);
            $result[$outcome]++;
        }

        return $result;
    }

    /** @return array<int,int> */
    private function candidateIds(CarbonInterface $at, int $batch): array
    {
        $cutoff = $at->copy()->subHours(max(1, (int) config('followup.silence_hours', 24)));
        $floor = $at->copy()->subDays(max(1, (int) config('followup.lookback_days', 90)));
        $latestId = $this->latestMessageSubquery('sm_latest.id');
        $latestDirection = $this->latestMessageSubquery('sm_latest.direction');

        return SocialConversation::query()
            ->select('social_conversations.id')
            ->leftJoin('follow_up_opportunities as fu', function ($join): void {
                $join->on('fu.social_conversation_id', '=', 'social_conversations.id')
                    ->on('fu.institution_id', '=', 'social_conversations.institution_id');
            })
            ->where('social_conversations.status', 'open')
            ->where('social_conversations.automation_state', 'bot')
            ->whereNull('social_conversations.assigned_to')
            ->whereBetween('social_conversations.last_message_at', [$floor, $cutoff])
            ->whereHas('channel', fn ($q) => $q->whereNotNull('advisor_bot_id')->where('is_active', true))
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('social_messages as sm_in')
                ->whereColumn('sm_in.social_conversation_id', 'social_conversations.id')
                ->where('sm_in.direction', 'inbound'))
            ->whereRaw("{$latestDirection} = 'outbound'")
            ->where(fn ($q) => $q->whereNull('fu.id')->orWhereRaw("fu.source_last_message_id < {$latestId}"))
            ->orderBy('social_conversations.last_message_at')
            ->limit($batch)
            ->pluck('social_conversations.id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /** @return 'detected'|'updated'|'skipped' */
    public function detectConversation(int $conversationId, CarbonInterface $at): string
    {
        return DB::transaction(function () use ($conversationId, $at): string {
            $conversation = SocialConversation::query()->with('channel')->lockForUpdate()->find($conversationId);
            $channel = $conversation?->channel;

            if ($conversation === null || $channel === null || $conversation->status !== 'open'
                || ! $conversation->advisorMayReply() || ! $channel->is_active || $channel->advisor_bot_id === null) {
                return 'skipped';
            }

            $messages = SocialMessage::query()
                ->where('social_conversation_id', $conversation->id)
                ->orderByDesc(DB::raw('COALESCE(provider_timestamp, created_at)'))
                ->orderByDesc('id')
                ->limit(20)
                ->get()
                ->reverse()
                ->values();
            $latest = $messages->last();
            $inbound = $messages->where('direction', 'inbound');
            $outbound = $messages->where('direction', 'outbound');

            if ($latest === null || $latest->direction !== 'outbound' || $inbound->isEmpty() || $outbound->isEmpty()) {
                return 'skipped';
            }

            $link = ConversationContactLink::resolve($conversation);
            $contact = $link['contact'];
            $lead = $contact === null ? null : Lead::query()->where('contact_id', $contact->id)->latest('updated_at')->first();
            $classification = $this->classifier->classify($messages, $contact, $lead);
            $opportunity = FollowUpOpportunity::query()
                ->where('social_conversation_id', $conversation->id)
                ->lockForUpdate()
                ->first();
            $created = $opportunity === null;
            $opportunity ??= new FollowUpOpportunity;

            $newCycle = $created || ($opportunity->status !== 'open' && (int) $opportunity->source_last_message_id !== (int) $latest->id);
            if ($newCycle && ! $created) {
                $opportunity->cycle = max(1, (int) $opportunity->cycle) + 1;
            }

            $lastInbound = $inbound->last();
            $lastOutbound = $outbound->last();
            $opportunity->bot_id = $channel->advisor_bot_id;
            $opportunity->social_channel_id = $channel->id;
            $opportunity->social_conversation_id = $conversation->id;
            $opportunity->contact_id = $contact?->id;
            $opportunity->lead_id = $lead?->id;
            $opportunity->source_last_message_id = $latest->id;
            $opportunity->provider = $conversation->provider;
            $opportunity->status = $classification['status'];
            $opportunity->classification = $classification['classification'];
            $opportunity->priority = $classification['priority'];
            $opportunity->score = $classification['score'];
            $opportunity->reason_codes = $classification['reasons'];
            $opportunity->context_snapshot = $classification['context'];
            $opportunity->cycle = max(1, (int) ($opportunity->cycle ?: 1));
            $opportunity->last_inbound_at = $this->messageTime($lastInbound);
            $opportunity->last_outbound_at = $this->messageTime($lastOutbound);
            $opportunity->source_last_message_at = $this->messageTime($latest);
            $opportunity->detected_at ??= $at;
            $opportunity->last_evaluated_at = $at;
            $opportunity->next_evaluation_at = null;
            $opportunity->cancelled_at = $classification['status'] === 'suppressed' ? $at : null;
            $opportunity->cancellation_reason = $classification['status'] === 'suppressed'
                ? $classification['reasons'][0]
                : null;
            $opportunity->save();

            $previousAttempts = $created ? 0 : (int) $opportunity->actions()->whereNotIn('status', ['cancelled', 'suppressed'])->count();
            $cycle = FollowUpCycle::query()->firstOrNew([
                'follow_up_opportunity_id' => $opportunity->id,
                'sequence' => $opportunity->cycle,
            ]);
            $cycle->fill([
                'institution_id' => $opportunity->institution_id,
                'status' => $opportunity->status,
                'classification' => $opportunity->classification,
                'priority' => $opportunity->priority,
                'score' => $opportunity->score,
                'reason_codes' => $opportunity->reason_codes,
                'context_snapshot' => $opportunity->context_snapshot,
                'source_first_message_id' => $cycle->source_first_message_id ?? $latest->id,
                'source_last_message_id' => $latest->id,
                'previous_attempts' => $cycle->exists ? $cycle->previous_attempts : $previousAttempts,
                'started_at' => $cycle->started_at ?? $this->messageTime($latest),
                'detected_at' => $cycle->detected_at ?? $at,
                'ended_at' => $opportunity->status === 'open' ? null : $at,
                'end_reason' => $opportunity->cancellation_reason,
            ]);
            $cycle->save();

            return $created ? 'detected' : 'updated';
        }, 3);
    }

    public function recordProspectResponse(int $conversationId, int $messageId): bool
    {
        return DB::transaction(function () use ($conversationId, $messageId): bool {
            $message = SocialMessage::query()->where('social_conversation_id', $conversationId)->find($messageId);
            if ($message === null || $message->direction !== 'inbound') {
                return false;
            }

            $opportunity = FollowUpOpportunity::query()
                ->where('social_conversation_id', $conversationId)
                ->lockForUpdate()
                ->first();

            if ($opportunity === null || (int) $message->id <= (int) ($opportunity->source_last_message_id ?? 0)) {
                return false;
            }

            $opportunity->source_last_message_id = $message->id;
            $opportunity->source_last_message_at = $this->messageTime($message);
            $opportunity->last_inbound_at = $this->messageTime($message);
            $opportunity->last_evaluated_at = now();
            $opportunity->next_evaluation_at = null;
            $opportunity->status = 'cancelled';
            $opportunity->cancelled_at = now();
            $opportunity->cancellation_reason = 'prospect_replied';
            $opportunity->reason_codes = array_values(array_unique([
                ...((array) $opportunity->reason_codes),
                'prospect_replied',
            ]));
            $opportunity->save();

            FollowUpCycle::query()
                ->where('follow_up_opportunity_id', $opportunity->id)
                ->where('sequence', $opportunity->cycle)
                ->whereNull('ended_at')
                ->update(['status' => 'cancelled', 'ended_at' => now(), 'end_reason' => 'prospect_replied', 'source_last_message_id' => $message->id, 'updated_at' => now()]);
            $this->invalidator->forOpportunity((int) $opportunity->id, 'prospect_replied');

            return true;
        }, 3);
    }

    private function reconcileProspectResponses(int $batch): int
    {
        $latestId = $this->latestMessageSubquery('sm_latest.id', 'follow_up_opportunities.social_conversation_id');
        $latestDirection = $this->latestMessageSubquery('sm_latest.direction', 'follow_up_opportunities.social_conversation_id');
        $rows = FollowUpOpportunity::query()
            ->where('status', 'open')
            ->whereRaw("{$latestDirection} = 'inbound'")
            ->whereRaw("source_last_message_id < {$latestId}")
            ->limit($batch)
            ->get(['social_conversation_id']);
        $cancelled = 0;

        foreach ($rows as $row) {
            $messageId = (int) SocialMessage::query()
                ->where('social_conversation_id', $row->social_conversation_id)
                ->orderByDesc(DB::raw('COALESCE(provider_timestamp, created_at)'))
                ->orderByDesc('id')
                ->value('id');
            $cancelled += $this->recordProspectResponse((int) $row->social_conversation_id, $messageId) ? 1 : 0;
        }

        return $cancelled;
    }

    private function latestMessageSubquery(string $column, string $conversationColumn = 'social_conversations.id'): string
    {
        return "(select {$column} from social_messages as sm_latest where sm_latest.social_conversation_id = {$conversationColumn} order by COALESCE(sm_latest.provider_timestamp, sm_latest.created_at) desc, sm_latest.id desc limit 1)";
    }

    private function messageTime(SocialMessage $message): CarbonInterface
    {
        return $message->provider_timestamp ?? $message->created_at ?? now();
    }
}
