<?php

declare(strict_types=1);

namespace Modules\Social\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Social\Models\SocialConversation;
use Modules\Social\Models\SocialMessage;

/**
 * @extends Factory<SocialMessage>
 */
class SocialMessageFactory extends Factory
{
    protected $model = SocialMessage::class;

    public function definition(): array
    {
        return [
            'social_conversation_id' => SocialConversation::factory(),
            'external_message_id' => (string) $this->faker->unique()->numerify('msg_##########'),
            'direction' => 'inbound',
            'type' => 'text',
            'body' => $this->faker->sentence(),
            'attachments' => null,
            'status' => 'received',
            'sender_type' => 'contact',
            'sent_by' => null,
            'provider_timestamp' => now(),
        ];
    }
}
