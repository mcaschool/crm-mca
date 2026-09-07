<?php

declare(strict_types=1);

namespace Modules\Social\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Social\Models\SocialChannel;
use Modules\Social\Models\SocialConversation;

/**
 * @extends Factory<SocialConversation>
 */
class SocialConversationFactory extends Factory
{
    protected $model = SocialConversation::class;

    public function definition(): array
    {
        return [
            'social_channel_id' => SocialChannel::factory(),
            'provider' => 'whatsapp',
            'external_conversation_id' => (string) $this->faker->unique()->numerify('wa_##########'),
            'contact_name' => $this->faker->name(),
            'contact_external_id' => (string) $this->faker->unique()->numerify('##########'),
            'contact_avatar_url' => null,
            'status' => 'open',
            'unread_count' => 0,
            'last_message_preview' => $this->faker->sentence(),
            'last_message_at' => now(),
        ];
    }
}
