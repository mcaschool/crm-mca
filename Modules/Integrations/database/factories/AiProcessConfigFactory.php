<?php

declare(strict_types=1);

namespace Modules\Integrations\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Integrations\Models\AiProcessConfig;
use Modules\Integrations\Models\Integration;

/**
 * @extends Factory<AiProcessConfig>
 */
class AiProcessConfigFactory extends Factory
{
    protected $model = AiProcessConfig::class;

    public function definition(): array
    {
        return [
            'bot_id' => null,
            'process' => 'conversation',
            'integration_id' => Integration::factory(),
            'model' => 'gpt-5-mini',
            'params' => ['timeout' => 25],
            'status' => 'active',
        ];
    }
}
