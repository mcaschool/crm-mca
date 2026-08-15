<?php

declare(strict_types=1);

namespace Modules\Catalog\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Catalog\Models\Program;
use Modules\Catalog\Models\ProgramTag;

/**
 * @extends Factory<ProgramTag>
 */
class ProgramTagFactory extends Factory
{
    protected $model = ProgramTag::class;

    public function definition(): array
    {
        return [
            'program_id' => Program::factory(),
            'tag' => $this->faker->unique()->word(),
        ];
    }
}
