<?php

declare(strict_types=1);

namespace Modules\Crm\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Crm\Models\Contact;

/**
 * @extends Factory<Contact>
 */
class ContactFactory extends Factory
{
    protected $model = Contact::class;

    public function definition(): array
    {
        return [
            'first_name' => $this->faker->firstName(),
            'last_name' => $this->faker->lastName(),
            'email' => $this->faker->unique()->safeEmail(),
            'phone' => null,
            'country' => $this->faker->randomElement(['ES', 'US', 'MX', 'CO']),
            'preferred_language' => $this->faker->randomElement(['es', 'en']),
            'consent_at' => now(),
            'consent_source' => 'widget',
        ];
    }
}
