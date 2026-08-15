<?php

declare(strict_types=1);

namespace Modules\Institutions\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Institutions\Models\Institution;

/**
 * @extends Factory<Institution>
 */
class InstitutionFactory extends Factory
{
    protected $model = Institution::class;

    public function definition(): array
    {
        $name = $this->faker->unique()->company();

        return [
            'name' => $name,
            'slug' => $this->faker->unique()->slug(2),
            'status' => 'active',
            'timezone' => 'America/New_York',
            'default_language' => 'es',
        ];
    }
}
