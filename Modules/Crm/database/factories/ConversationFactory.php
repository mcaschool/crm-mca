<?php

declare(strict_types=1);

namespace Modules\Crm\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Crm\Models\Conversation;
use Modules\Institutions\Models\Bot;

/**
 * @extends Factory<Conversation>
 */
class ConversationFactory extends Factory
{
    protected $model = Conversation::class;

    public function definition(): array
    {
        return [
            'contact_id' => null,
            'bot_id' => Bot::factory(),
            'session_id' => (string) Str::uuid(),
            'channel' => 'web',
            'mode' => 'guided',
            'language' => 'es',
            'status' => 'open',
            'current_node_id' => null,
            'started_at' => now(),
            'last_activity_at' => now(),
        ];
    }
}
