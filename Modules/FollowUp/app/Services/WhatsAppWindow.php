<?php

declare(strict_types=1);

namespace Modules\FollowUp\Services;

use Carbon\CarbonImmutable;
use Modules\Social\Models\SocialMessage;

/** Derived from original provider evidence, never from receipt time or outbound messages. */
final class WhatsAppWindow
{
    /** @return array{message_id:?int,last_customer_message_at:?CarbonImmutable,expires_at:?CarbonImmutable,state:string,remaining_minutes:int,evaluated_at:CarbonImmutable} */
    public function inspect(int $conversationId, int $margin = 60): array
    {
        $now = CarbonImmutable::now('UTC');
        $message = SocialMessage::query()->where('social_conversation_id', $conversationId)
            ->where('direction', 'inbound')->where('sender_type', 'contact')
            ->whereNotNull('provider_timestamp')->orderByDesc('provider_timestamp')->orderByDesc('id')->first();
        $at = $message?->provider_timestamp ? CarbonImmutable::instance($message->provider_timestamp)->utc() : null;
        if ($at?->isFuture() || ! ($message?->ai_meta['provider_timestamp_verified'] ?? false)) {
            $at = null;
        }
        $expires = $at?->addHours(24);

        return ['message_id' => $at ? (int) $message->id : null, 'last_customer_message_at' => $at,
            'expires_at' => $expires, 'state' => $expires === null ? 'unknown' : ($expires->lte($now) ? 'expired' : ($expires->subMinutes($margin)->lte($now) ? 'closing' : 'open')),
            'remaining_minutes' => $expires ? max(0, (int) $now->diffInMinutes($expires, false)) : 0, 'evaluated_at' => $now];
    }
}
