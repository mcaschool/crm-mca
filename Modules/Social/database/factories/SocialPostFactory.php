<?php

declare(strict_types=1);

namespace Modules\Social\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Social\Models\SocialPost;

/**
 * @extends Factory<SocialPost>
 */
class SocialPostFactory extends Factory
{
    protected $model = SocialPost::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'caption' => $this->faker->sentence(),
            'image_path' => 'social-posts/'.$this->faker->uuid().'.jpg',
            'image_public_url' => 'https://cdn.example.test/'.$this->faker->uuid().'.jpg',
            'status' => 'pending',
        ];
    }
}
