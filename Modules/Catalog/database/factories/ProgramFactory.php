<?php

declare(strict_types=1);

namespace Modules\Catalog\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Catalog\Models\Program;

/**
 * @extends Factory<Program>
 */
class ProgramFactory extends Factory
{
    protected $model = Program::class;

    public function definition(): array
    {
        $name = $this->faker->unique()->sentence(3);

        return [
            'code' => strtoupper($this->faker->unique()->bothify('MC-###??')),
            'name_es' => $name,
            'name_en' => null, // se completa despues; fallback a _es
            'credential_en' => null,
            'category_id' => null,
            'level' => $this->faker->randomElement(['basico', 'intermedio', 'avanzado']),
            'goal' => $this->faker->randomElement(['empleo', 'ascenso', 'reconversion']),
            'profile' => null,
            'duration_es' => '6 semanas',
            'duration_en' => null,
            'modality_es' => 'en linea',
            'modality_en' => null,
            'short_description_es' => $this->faker->sentence(),
            'short_description_en' => null,
            'url' => $this->faker->url(),
            'status' => 'active',
            'display_order' => 0,
        ];
    }
}
