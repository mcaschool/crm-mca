<?php

declare(strict_types=1);

namespace Modules\FollowUp\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\FollowUp\Models\FollowUpOpportunity;
use Modules\Institutions\Models\Bot;
use Modules\Social\Models\SocialChannel;
use Modules\Social\Models\SocialConversation;

/** @extends Factory<FollowUpOpportunity> */
class FollowUpOpportunityFactory extends Factory
{
    protected $model = FollowUpOpportunity::class;

    public function definition(): array
    {
        return [
            'bot_id' => Bot::factory(),
            'social_channel_id' => fn (array $attributes) => SocialChannel::factory()->create([
                'advisor_bot_id' => $attributes['bot_id'],
            ])->id,
            'social_conversation_id' => fn (array $attributes) => SocialConversation::factory()->create([
                'social_channel_id' => $attributes['social_channel_id'],
            ])->id,
            'provider' => 'whatsapp',
            'status' => 'open',
            'classification' => 'general_interest',
            'priority' => 'low',
            'score' => 25,
            'reason_codes' => ['silent_after_response'],
            'context_snapshot' => ['inbound_messages' => 1, 'outbound_messages' => 1],
            'cycle' => 1,
            'source_last_message_at' => now()->subDay(),
            'detected_at' => now(),
            'last_evaluated_at' => now(),
        ];
    }
}
