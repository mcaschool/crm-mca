<?php

declare(strict_types=1);

namespace Modules\Catalog\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Catalog\Models\ProgramLine;

/**
 * @extends Factory<ProgramLine>
 */
class ProgramLineFactory extends Factory
{
    protected $model = ProgramLine::class;

    public function definition(): array
    {
        $word = $this->faker->unique()->words(2, true);

        return [
            'name_es' => 'Categoría '.$word,
            'name_en' => 'Category '.$word.' (EN)',
            'slug' => $this->faker->unique()->slug(2),
            'display_order' => 0,
            'status' => 'active',
        ];
    }
}
