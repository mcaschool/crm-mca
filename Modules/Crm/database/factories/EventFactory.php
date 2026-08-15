<?php

declare(strict_types=1);

namespace Modules\Crm\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Crm\Models\Event;

/**
 * @extends Factory<Event>
 */
class EventFactory extends Factory
{
    protected $model = Event::class;

    public function definition(): array
    {
        return [
            'contact_id' => null,
            'conversation_id' => null,
            'bot_id' => null,
            'event_type' => 'program.viewed',
            'event_data' => ['source' => 'test'],
        ];
    }
}
