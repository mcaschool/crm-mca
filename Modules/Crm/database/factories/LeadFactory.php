<?php

declare(strict_types=1);

namespace Modules\Crm\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Crm\Models\Contact;
use Modules\Crm\Models\Lead;
use Modules\Institutions\Models\Bot;

/**
 * @extends Factory<Lead>
 */
class LeadFactory extends Factory
{
    protected $model = Lead::class;

    public function definition(): array
    {
        return [
            'contact_id' => Contact::factory(),
            'bot_id' => Bot::factory(),
            'product_type' => 'microcredential',
            'program_id' => null,
            'area' => null,
            'goal' => null,
            'level' => null,
            'source' => 'widget_microcredenciales',
            'status' => 'new',
            'interest_level' => 'low',
        ];
    }
}
