<?php

declare(strict_types=1);

namespace Modules\Crm\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Crm\Models\Conversation;
use Modules\Crm\Models\Message;

/**
 * @extends Factory<Message>
 */
class MessageFactory extends Factory
{
    protected $model = Message::class;

    public function definition(): array
    {
        return [
            'conversation_id' => Conversation::factory(),
            'sender_type' => 'user',
            'content' => $this->faker->sentence(),
            'message_type' => 'text',
            'meta' => null, // sin provider = resuelto sin IA (deflection)
        ];
    }
}
