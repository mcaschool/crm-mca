<?php

declare(strict_types=1);

namespace Modules\Social\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Social\Models\SocialChannel;

/**
 * @extends Factory<SocialChannel>
 */
class SocialChannelFactory extends Factory
{
    protected $model = SocialChannel::class;

    public function definition(): array
    {
        $provider = $this->faker->randomElement(['whatsapp', 'instagram', 'messenger']);

        return [
            'provider' => $provider,
            'display_name' => ucfirst($provider).' · '.$this->faker->company(),
            'external_id' => (string) $this->faker->unique()->numerify('##############'),
            'credentials' => ['token' => 'tok_'.Str::random(20)],
            'is_active' => true,
        ];
    }
}
