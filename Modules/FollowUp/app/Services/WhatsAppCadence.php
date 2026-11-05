<?php

declare(strict_types=1);

namespace Modules\FollowUp\Services;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Modules\FollowUp\Data\EffectiveFollowUpSettings;
use Modules\FollowUp\Data\PolicyRequest;
use Modules\FollowUp\Models\FollowUpAction;
use Modules\FollowUp\Models\FollowUpOpportunity;
use Modules\Social\Models\SocialMessage;

final class WhatsAppCadence
{
    public const DEFAULTS = ['enabled' => false, 'first' => 5, 'second' => 14, 'third' => 21, 'minimum' => 4, 'margin' => 60, 'maximum' => 3];

    /**
     * @param  array<string,mixed>|null  $institution
     * @param  array<string,mixed>|null  $advisor
     * @return array<string,mixed>
     */
    public static function effective(?array $institution, ?array $advisor): array
    {
        $base = array_replace(self::DEFAULTS, $institution ?? []);
        if ($advisor === null) {
            return $base;
        }
        $base['enabled'] = $base['enabled'] && ($advisor['enabled'] ?? false);
        foreach (['first', 'second', 'third', 'minimum', 'margin'] as $key) {
            $base[$key] = max((int) $base[$key], (int) ($advisor[$key] ?? $base[$key]));
        }
        $base['maximum'] = min((int) $base['maximum'], (int) ($advisor['maximum'] ?? $base['maximum']));

        return $base;
    }

    /**
     * Lifetime recipient scope, shared across opportunities, channels, advisors and reopenings.
     *
     * @return Builder<FollowUpAction>
     */
    public function related(FollowUpOpportunity $op): Builder
    {
        return FollowUpAction::query()->whereHas('opportunity', function ($q) use ($op): void {
            $q->where(function ($q) use ($op): void {
                $q->where('social_conversation_id', $op->social_conversation_id);
                if ($op->contact_id !== null) {
                    $q->orWhere('contact_id', $op->contact_id);
                }
            });
        });
    }

    /**
     * @param  array<string,mixed>  $cadence
     */
    public function slot(CarbonInterface $anchor, CarbonInterface $at, array $cadence): int
    {
        $slot = 0;
        foreach (['first', 'second', 'third'] as $index => $key) {
            if ($at->gte($anchor->copy()->addHours((int) $cadence[$key]))) {
                $slot = $index + 1;
            }
        }

        return $slot;
    }

    public function reason(PolicyRequest $request, EffectiveFollowUpSettings $settings): ?string
    {
        if ($request->channel !== 'whatsapp' || ! ($settings->cadence['enabled'] ?? false) || $request->stage === 'prepare') {
            return null;
        }
        $op = $request->opportunity;
        $window = app(WhatsAppWindow::class)->inspect((int) $op->social_conversation_id, (int) $settings->cadence['margin']);
        $anchor = $window['last_customer_message_at'];
        $at = $request->scheduledAt;
        if ($request->whatsappTemplateId !== null) {
            return $this->recipientReason($request, $settings, $anchor);
        }
        if ($anchor === null || $at === null || $window['expires_at'] === null) {
            return 'cadence_window_unknown';
        }
        if ($at->gte($window['expires_at']->subMinutes((int) $settings->cadence['margin']))) {
            // Templates remain a separate, explicitly reviewed policy outside this sequence.
            return 'cadence_window_closing';
        }
        $slot = $this->slot($anchor, $at, $settings->cadence);
        if ($slot === 0) {
            return 'cadence_not_due';
        }
        if ($request->action !== null && $request->action->cadence_key === null) {
            return 'cadence_reservation_required';
        }
        if ($request->action?->cadence_anchor_id !== null && $request->action->cadence_anchor_id !== $window['message_id']) {
            return 'cadence_anchor_changed';
        }
        if ($request->action?->cadence_slot !== null && $request->action->cadence_slot !== $slot) {
            return 'cadence_slot_missed';
        }
        $key = $this->key($op, (int) $window['message_id'], $slot);
        if (FollowUpAction::query()->where('cadence_key', $key)->when($request->action, fn ($q) => $q->whereKeyNot($request->action->id))->exists()) {
            return 'cadence_slot_used';
        }

        return $this->recipientReason($request, $settings, $anchor);
    }

    private function recipientReason(PolicyRequest $request, EffectiveFollowUpSettings $settings, ?CarbonInterface $anchor): ?string
    {
        $op = $request->opportunity;
        $at = $request->scheduledAt;
        if ($at === null) {
            return 'invalid_action_validity';
        }
        $related = $this->related($op)->when($request->action, fn ($q) => $q->whereKeyNot($request->action->id));
        if ((clone $related)->whereIn('status', ['claimed', 'sending', 'delivery_unknown'])->exists()) {
            return 'recipient_execution_unresolved';
        }
        $committed = (clone $related)->where(fn ($q) => $q->whereNotNull('dispatch_at')->orWhereIn('status', FollowUpAction::ACTIVE_STATUSES));
        if ((clone $committed)->count() >= $settings->maximumAttempts) {
            return 'recipient_attempt_limit';
        }
        if ($anchor !== null && $at->lt($anchor->copy()->addHours(24)) && (clone $committed)->where('created_at', '>=', $anchor)->count() >= (int) $settings->cadence['maximum']) {
            return 'cadence_maximum_reached';
        }
        if ((clone $related)->whereIn('status', FollowUpAction::ACTIVE_STATUSES)->exists()) {
            return 'duplicate_active_action';
        }
        $latestDispatch = (clone $related)->max('dispatch_at');
        if ($latestDispatch !== null && $at->lt(CarbonImmutable::parse($latestDispatch)->addHours((int) $settings->cadence['minimum']))) {
            return 'cadence_minimum_interval';
        }
        $lastOutbound = SocialMessage::query()->where('social_conversation_id', $op->social_conversation_id)->where('direction', 'outbound')
            ->when($request->action?->social_message_id, fn ($q) => $q->whereKeyNot($request->action->social_message_id))
            ->whereNotIn('status', ['failed', 'failed_window'])->max('provider_timestamp');
        if ($lastOutbound !== null && $at->lt(CarbonImmutable::parse($lastOutbound)->addHours((int) $settings->cadence['minimum']))) {
            return 'cadence_minimum_interval';
        }

        return null;
    }

    public function key(FollowUpOpportunity $op, int $anchorId, int $slot): string
    {
        return hash('sha256', $op->institution_id.'|'.$op->social_conversation_id.'|'.$anchorId.'|'.$slot);
    }

    /**
     * @return array<string,mixed>
     */
    public function summary(FollowUpOpportunity $op): array
    {
        $settings = app(FollowUpSettingsResolver::class)->effective((int) $op->institution_id, (int) $op->bot_id);
        $cadence = array_replace(self::DEFAULTS, $settings->cadence);
        $window = app(WhatsAppWindow::class)->inspect((int) $op->social_conversation_id, (int) $cadence['margin']);
        $related = $this->related($op);
        $next = null;
        if ($cadence['enabled'] && $window['state'] === 'open' && $window['last_customer_message_at'] !== null) {
            foreach (['first', 'second', 'third'] as $key) {
                $candidate = $window['last_customer_message_at']->addHours((int) $cadence[$key]);
                if ($candidate->isFuture()) {
                    $next = $candidate;
                    break;
                }
            }
        }

        $messages = SocialMessage::query()->where('social_conversation_id', $op->social_conversation_id);
        $messageCounts = [
            'customer' => (clone $messages)->where('direction', 'inbound')->count(),
            'reactive' => (clone $messages)->where('direction', 'outbound')->where('sender_type', 'bot')->whereNull('ai_meta->follow_up_action_id')->count(),
            'human' => (clone $messages)->where('direction', 'outbound')->where('sender_type', 'agent')->count(),
            'app' => (clone $messages)->where('direction', 'outbound')->where('sender_type', 'app')->count(),
        ];

        return $window + ['timezone' => $settings->timezone, 'message_counts' => $messageCounts, 'enabled' => $cadence['enabled'], 'sent' => (clone $related)->whereIn('status', ['sent', 'delivered', 'read'])->count(),
            'reserved' => (clone $related)->whereIn('status', FollowUpAction::ACTIVE_STATUSES)->count(),
            'unknown' => (clone $related)->where('status', 'delivery_unknown')->count(), 'maximum' => $settings->maximumAttempts,
            'next' => $next, 'margin' => $cadence['margin']];
    }
}
