<?php

declare(strict_types=1);

namespace Modules\Chat\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Chat\Models\ConversationNode;
use Modules\Chat\Models\ConversationOption;

/**
 * @extends Factory<ConversationOption>
 */
class ConversationOptionFactory extends Factory
{
    protected $model = ConversationOption::class;

    public function definition(): array
    {
        return [
            'node_id' => ConversationNode::factory(),
            'label_es' => $this->faker->words(2, true),
            'label_en' => null,
            'target_node_id' => null,
            'action' => null,
            'event_type' => null,
            'display_order' => 0,
        ];
    }
}
