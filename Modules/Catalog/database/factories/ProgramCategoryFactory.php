<?php

declare(strict_types=1);

namespace Modules\Catalog\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Catalog\Models\ProgramCategory;

/**
 * @extends Factory<ProgramCategory>
 */
class ProgramCategoryFactory extends Factory
{
    protected $model = ProgramCategory::class;

    public function definition(): array
    {
        $word = $this->faker->unique()->word();

        return [
            'name_es' => 'Area '.$word,
            'name_en' => 'Area '.$word.' (EN)',
            'slug' => $this->faker->unique()->slug(2),
            'display_order' => 0,
            'status' => 'active',
        ];
    }
}
