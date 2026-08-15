<?php

declare(strict_types=1);

namespace Modules\Ai\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Ai\Models\KnowledgeSource;
use Modules\Institutions\Models\Bot;

/**
 * @extends Factory<KnowledgeSource>
 */
class KnowledgeSourceFactory extends Factory
{
    protected $model = KnowledgeSource::class;

    public function definition(): array
    {
        return [
            'bot_id' => Bot::factory(),
            'name' => $this->faker->sentence(3),
            'code' => $this->faker->unique()->bothify('KB-###'),
            'type' => 'general',
            'category' => null,
            'program_id' => null,
            'url' => null,
            'content_es' => $this->faker->paragraph(),
            'content_en' => null,
            'priority' => 0,
            'status' => 'active',
        ];
    }
}
