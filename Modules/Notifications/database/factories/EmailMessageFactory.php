<?php

declare(strict_types=1);

namespace Modules\Notifications\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Notifications\Models\EmailMessage;

/**
 * @extends Factory<EmailMessage>
 */
class EmailMessageFactory extends Factory
{
    protected $model = EmailMessage::class;

    public function definition(): array
    {
        return [
            'from_address' => 'finanzas@mcaschool.education',
            'from_name' => 'Finanzas MCA',
            'to_address' => $this->faker->safeEmail(),
            'subject' => $this->faker->sentence(),
            'body' => $this->faker->paragraph(),
            'status' => 'sent',
            'sent_at' => now(),
        ];
    }
}
