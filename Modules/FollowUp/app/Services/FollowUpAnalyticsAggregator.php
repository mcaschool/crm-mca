<?php

declare(strict_types=1);

namespace Modules\FollowUp\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Ai\Models\AiUsageEvent;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\FollowUp\Models\AnalyticsFact;
use Modules\FollowUp\Models\CommunicationConsentEvent;
use Modules\FollowUp\Models\FollowUpAction;
use Modules\FollowUp\Models\FollowUpEvent;
use Modules\FollowUp\Models\FollowUpOpportunity;
use Modules\Social\Models\SocialMessage;

/** Rebuildable projection. Never invokes commercial services or external providers. */
final class FollowUpAnalyticsAggregator
{
    public const RESPONSE_DAYS = 7;

    public const CONVERSION_DAYS = 30;

    public function reconcile(): int
    {
        $tenant = app(CurrentInstitution::class)->idOrFail();
        DB::table('follow_up_analytics_runs')->insertOrIgnore(['institution_id' => $tenant]);

        return DB::transaction(function () use ($tenant): int {
            DB::table('follow_up_analytics_runs')->where('institution_id', $tenant)->lockForUpdate()->first();
            $generation = (string) Str::uuid();
            FollowUpOpportunity::query()->with(['lead', 'contact', 'commercialActivity'])->chunkById(100, function ($rows) use ($generation): void {
                foreach ($rows as $op) {
                    $this->put('opportunity', (string) $op->id, $generation, $this->dimensions($op) + [
                        'occurred_at' => $op->detected_at, 'status' => $op->status,
                        'data' => ['cycle' => $op->cycle, 'human' => $op->commercial_hold, 'close' => $op->commercial_close_reason,
                            'identity_verified' => $this->verified($op), 'recommendation' => $op->commercial_recommendation['action'] ?? null,
                            'quality' => $op->lead_id && ! $this->verified($op) ? 'ambiguous_identity' : null],
                    ]);
                }
            });
            FollowUpAction::query()->with(['opportunity.lead', 'opportunity.contact'])->chunkById(100, function ($actions) use ($generation): void {
                $messages = SocialMessage::query()->whereIn('id', $actions->pluck('social_message_id')->filter())
                    ->get(['id', 'social_conversation_id', 'direction', 'external_message_id', 'status'])->keyBy('id');
                foreach ($actions as $action) {
                    $op = $action->getRelation('opportunity');
                    if (! $op instanceof FollowUpOpportunity) {
                        continue;
                    }
                    $message = $messages->get($action->social_message_id);
                    $confirmed = $message && $message->direction === 'outbound' && $message->social_conversation_id === $op->social_conversation_id
                        && $message->external_message_id && in_array($message->status, ['sent', 'delivered', 'read'], true)
                        && in_array($action->status, ['sent', 'delivered', 'read'], true) && $action->dispatch_at && $action->dispatch_at->lte(now());
                    $response = null;
                    if ($confirmed) {
                        // Indexed conversation lookup; only the first timestamp/id, never message content.
                        $response = SocialMessage::query()->where('social_conversation_id', $op->social_conversation_id)->where('direction', 'inbound')
                            ->whereRaw('COALESCE(provider_timestamp, created_at) > ?', [$action->dispatch_at])
                            ->whereRaw('COALESCE(provider_timestamp, created_at) <= ?', [$action->dispatch_at->copy()->addDays(self::RESPONSE_DAYS)])
                            ->whereRaw('COALESCE(provider_timestamp, created_at) <= ?', [now()])
                            ->orderByRaw('COALESCE(provider_timestamp, created_at)')->orderBy('id')->first(['id', 'provider_timestamp', 'created_at']);
                    }
                    $this->put('action', (string) $action->id, $generation, $this->dimensions($op) + [
                        'action_id' => $action->id, 'action_type' => $action->type, 'status' => $action->status, 'occurred_at' => $action->created_at,
                        'eligible_at' => $confirmed ? $action->dispatch_at : null,
                        'response_at' => $response ? ($response->provider_timestamp ?? $response->created_at) : null,
                        'evidence_id' => $message?->id,
                        'data' => ['lead_id' => $this->verified($op) ? $op->lead_id : null, 'conversation_id' => $op->social_conversation_id, 'purpose' => $action->purpose, 'attempted' => $action->dispatch_at !== null, 'blocked' => $action->status === 'suppressed' || $action->block_reason !== null,
                            'block_reason' => $action->block_reason ?: $action->policy_reason_code, 'error' => $action->execution_error,
                            'delivered' => $confirmed && in_array($message->status, ['delivered', 'read']), 'read' => $confirmed && $message->status === 'read',
                            'response_id' => $response?->id, 'draft_state' => $action->draft_state,
                            'approved' => $action->approved_at !== null && $action->approved_draft_version === $action->draft_version,
                            'quality' => ! $confirmed && in_array($action->status, ['sent', 'delivered', 'read']) ? 'execution_without_evidence' : null],
                    ]);
                }
            });
            // Remove obsolete action facts before attribution: corrected/deleted evidence cannot survive.
            AnalyticsFact::query()->whereIn('kind', ['action', 'opportunity'])->where('generation', '!=', $generation)->delete();
            $this->outcomes($generation);
            $this->withdrawals($generation);
            $this->responses($generation);
            $this->generations($generation);
            AiUsageEvent::query()->where('process', 'followup_draft')->chunkById(100, function ($rows) use ($generation): void {
                foreach ($rows as $row) {
                    $this->put('usage', (string) $row->id, $generation, ['bot_id' => $row->bot_id, 'occurred_at' => $row->getAttribute('created_at'), 'status' => $row->status,
                        'data' => ['input_tokens' => $row->input_tokens, 'output_tokens' => $row->output_tokens, 'latency_ms' => $row->latency_ms, 'error' => $row->error_category]]);
                }
            });
            AnalyticsFact::query()->where('generation', '!=', $generation)->delete();
            $count = AnalyticsFact::query()->count();
            DB::table('follow_up_analytics_runs')->where('institution_id', $tenant)->update(['generation' => $generation, 'completed_at' => now(), 'facts' => $count, 'version' => '7.1']);

            return $count;
        }, 3);
    }

    private function outcomes(string $generation): void
    {
        FollowUpOpportunity::query()->with(['lead', 'contact', 'commercialActivity'])->orderBy('id')->chunkById(100, function ($rows) use ($generation): void {
            $events = FollowUpEvent::query()->whereIn('follow_up_opportunity_id', $rows->pluck('id'))->where('event_type', 'commercial.synchronized')->where('occurred_at', '<=', now())
                ->where('safe_data->basis', 'confirmed_crm')->where('safe_data->result', 'converted')->orderBy('occurred_at')->get()->groupBy('follow_up_opportunity_id');
            foreach ($rows as $op) {
                if (! $this->verified($op)) {
                    continue;
                }
                $event = ($events->get($op->id) ?? collect())->first(fn ($e) => (int) ($e->safe_data['lead_id'] ?? 0) === $op->lead_id);
                if ($op->lead?->status->value === 'enrolled') {
                    $this->outcome('conversion', (string) $op->lead_id, $op, $event?->occurred_at, $event?->id, $generation);
                }
                $activity = $op->commercialActivity;
                if ($activity && $activity->contact_id === $op->contact_id && in_array($activity->type->value, ['video_call', 'in_person_meeting'], true)) {
                    $this->outcome('meeting', (string) $activity->id, $op, $activity->created_at, $activity->id, $generation,
                        ['state' => $activity->status->value, 'completed_at' => $activity->completed_at?->toIso8601String()]);
                }
                if ($op->contact?->unsubscribed_at) {
                    $this->outcome('exclusion', (string) $op->contact_id, $op, $op->contact->unsubscribed_at, $op->contact_id, $generation);
                }
            }
        });
    }

    /** @param array<string,mixed> $extra */
    private function outcome(string $kind, string $key, FollowUpOpportunity $op, ?\Carbon\CarbonInterface $at, ?int $evidence, string $generation, array $extra = []): void
    {
        if ($at?->isFuture()) {
            return;
        }
        $subject = 'contact:'.$op->contact_id;
        $action = $at ? AnalyticsFact::query()->where('kind', 'action')->where('subject', $subject)->whereNotNull('eligible_at')
            ->when($kind === 'conversion', fn ($q) => $q->where('data->lead_id', $op->lead_id))
            ->where('eligible_at', '<', $at)->where('eligible_at', '>=', $at->copy()->subDays(self::CONVERSION_DAYS))
            ->orderByDesc('eligible_at')->orderByDesc('action_id')->first() : null;
        // One confirmed CRM entity, even when several opportunities/channels point at it.
        $existing = AnalyticsFact::query()->where('kind', $kind)->where('source_key', $key)->where('generation', $generation)->first();
        if ($existing && $existing->occurred_at !== null && ($at === null || $existing->occurred_at->lte($at))) {
            return;
        }
        $dimensions = $action ? array_intersect_key($action->getAttributes(), array_flip(['opportunity_id', 'bot_id', 'program_id', 'owner_id', 'subject', 'channel', 'priority', 'stage', 'opportunity_status'])) : $this->dimensions($op);
        $this->put($kind, $key, $generation, $dimensions + ['occurred_at' => $at, 'action_id' => $action?->action_id,
            'eligible_at' => $action?->eligible_at, 'evidence_id' => $evidence,
            'data' => $extra + ['associated' => $action !== null, 'causal' => false, 'quality' => $at === null ? 'outcome_date_unknown' : null]]);
    }

    private function generations(string $generation): void
    {
        FollowUpEvent::query()->whereIn('event_type', ['draft.generated', 'execution.approved'])->where('occurred_at', '<=', now())
            ->with(['opportunity.lead', 'opportunity.contact'])->chunkById(100, function ($rows) use ($generation): void {
                foreach ($rows as $event) {
                    $op = $event->opportunity;
                    if (! $op) {
                        continue;
                    }
                    $version = $event->safe_data['version'] ?? $event->safe_data['draft_version'] ?? null;
                    if ($version === null || $event->follow_up_action_id === null) {
                        continue;
                    }
                    $kind = $event->event_type === 'draft.generated' ? 'generation' : 'approval';
                    $key = $event->follow_up_action_id.':'.$version;
                    $existing = AnalyticsFact::query()->where('kind', $kind)->where('source_key', $key)->where('generation', $generation)->first();
                    if ($existing?->occurred_at?->lte($event->occurred_at)) {
                        continue;
                    }
                    $this->put($kind, $key, $generation,
                        $this->dimensions($op) + ['action_id' => $event->follow_up_action_id, 'occurred_at' => $event->occurred_at, 'evidence_id' => $event->id, 'data' => ['version' => $version]]);
                }
            });
    }

    private function withdrawals(string $generation): void
    {
        CommunicationConsentEvent::query()->where('to_state', 'withdrawn')->where('occurred_at', '<=', now())
            ->with('consent')->chunkById(100, function ($events) use ($generation): void {
                foreach ($events as $event) {
                    $consent = $event->consent;
                    $at = $event->getAttribute('occurred_at');
                    if (! $consent || ! $at) {
                        continue;
                    }
                    $action = AnalyticsFact::query()->where('kind', 'action')->where('channel', $consent->channel)
                        ->where('data->purpose', $consent->purpose)
                        ->when($consent->contact_id !== null, fn ($q) => $q->where('subject', 'contact:'.$consent->contact_id),
                            fn ($q) => $q->whereIn('opportunity_id', FollowUpOpportunity::query()->where('social_conversation_id', $consent->social_conversation_id)->select('id')))
                        ->where('eligible_at', '<', $at)->where('eligible_at', '>=', $at->copy()->subDays(self::CONVERSION_DAYS))
                        ->orderByDesc('eligible_at')->orderByDesc('action_id')->first();
                    if (! $action) {
                        continue;
                    }
                    $dimensions = array_intersect_key($action->getAttributes(), array_flip(['opportunity_id', 'bot_id', 'program_id', 'owner_id', 'subject', 'channel', 'priority', 'stage', 'opportunity_status', 'action_id', 'eligible_at']));
                    $this->put('exclusion', 'consent:'.$event->id, $generation, $dimensions + [
                        'occurred_at' => $at, 'evidence_id' => $event->id,
                        'data' => ['associated' => true, 'causal' => false, 'source' => 'consent_withdrawal'],
                    ]);
                }
            });
    }

    private function responses(string $generation): void
    {
        FollowUpOpportunity::query()->with(['lead', 'contact'])->chunkById(100, function ($rows) use ($generation): void {
            foreach ($rows as $op) {
                $reply = SocialMessage::query()->where('social_conversation_id', $op->social_conversation_id)->where('direction', 'inbound')
                    ->whereRaw('COALESCE(provider_timestamp, created_at) > ?', [$op->detected_at])
                    ->whereRaw('COALESCE(provider_timestamp, created_at) <= ?', [now()])
                    ->orderByRaw('COALESCE(provider_timestamp, created_at)')->orderBy('id')->first(['id', 'provider_timestamp', 'created_at']);
                if (! $reply) {
                    continue;
                }
                $at = $reply->provider_timestamp ?? $reply->created_at;
                $action = AnalyticsFact::query()->where('kind', 'action')->where('opportunity_id', $op->id)
                    ->where('eligible_at', '<', $at)->where('eligible_at', '>=', $at->copy()->subDays(self::RESPONSE_DAYS))
                    ->orderByDesc('eligible_at')->orderByDesc('action_id')->first();
                $this->put('response', (string) $op->social_conversation_id, $generation, $this->dimensions($op) + [
                    'occurred_at' => $at, 'evidence_id' => $reply->id, 'action_id' => $action?->action_id,
                    'data' => ['associated' => $action !== null, 'causal' => false],
                ]);
            }
        });
    }

    private function verified(FollowUpOpportunity $op): bool
    {
        return $op->contact !== null && in_array($op->commercial_link_source, ['manual', 'verified_phone'], true)
            && ($op->lead_id === null || ($op->lead !== null && $op->lead->contact_id === $op->contact_id));
    }

    /** @return array<string,mixed> */
    private function dimensions(FollowUpOpportunity $op): array
    {
        return ['opportunity_id' => $op->id, 'bot_id' => $op->bot_id, 'program_id' => $op->commercial_program_id,
            'owner_id' => $op->commercial_owner_id, 'channel' => $op->provider, 'priority' => $op->priority,
            'stage' => $op->lead?->status->value, 'opportunity_status' => $op->status, 'subject' => $this->verified($op) ? 'contact:'.$op->contact_id : 'conversation:'.$op->social_conversation_id];
    }

    /** @param array<string,mixed> $values */
    private function put(string $kind, string $key, string $generation, array $values): void
    {
        AnalyticsFact::query()->updateOrCreate(['kind' => $kind, 'source_key' => $key], $values + ['generation' => $generation]);
    }
}
