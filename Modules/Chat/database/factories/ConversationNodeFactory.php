<?php

declare(strict_types=1);

namespace Modules\Chat\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Chat\Models\ConversationNode;
use Modules\Institutions\Models\Bot;

/**
 * @extends Factory<ConversationNode>
 */
class ConversationNodeFactory extends Factory
{
    protected $model = ConversationNode::class;

    public function definition(): array
    {
        return [
            'bot_id' => Bot::factory(),
            'key' => $this->faker->unique()->slug(2),
            'type' => 'message',
            'content_es' => $this->faker->sentence(),
            'content_en' => null,
            'config' => null,
            'display_order' => 0,
            'status' => 'active',
        ];
    }
}
