<?php

declare(strict_types=1);

namespace Modules\Social\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Social\Models\SocialWhatsAppTemplate;

/**
 * @extends Factory<SocialWhatsAppTemplate>
 */
class SocialWhatsAppTemplateFactory extends Factory
{
    protected $model = SocialWhatsAppTemplate::class;

    public function definition(): array
    {
        return [
            'meta_template_id' => (string) $this->faker->unique()->numerify('9########'),
            'name' => 'tpl_'.$this->faker->unique()->numerify('####'),
            'language' => 'es',
            'category' => 'UTILITY',
            'status' => 'APPROVED',
            'quality_score' => null,
            'parameter_format' => 'POSITIONAL',
            'components' => [
                ['type' => 'BODY', 'text' => 'Hola {{1}}, tu solicitud fue recibida.', 'example' => ['body_text' => [['Carlos']]]],
            ],
            'last_synced_at' => now(),
        ];
    }

    public function approved(): static
    {
        return $this->state(['status' => 'APPROVED']);
    }

    public function pending(): static
    {
        return $this->state(['status' => 'PENDING']);
    }
}
