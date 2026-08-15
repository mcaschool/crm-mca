<?php

declare(strict_types=1);

namespace Modules\Crm\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Catalog\Models\Program;
use Modules\Crm\Models\Contact;
use Modules\Crm\Models\ProgramInterest;
use Modules\Institutions\Models\Bot;

/**
 * @extends Factory<ProgramInterest>
 */
class ProgramInterestFactory extends Factory
{
    protected $model = ProgramInterest::class;

    public function definition(): array
    {
        return [
            'contact_id' => Contact::factory(),
            'program_id' => Program::factory(),
            'bot_id' => Bot::factory(),
            'source' => 'matcher',
        ];
    }
}
