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

    public function reel(): static
    {
        return $this->state(fn (): array => [
            'content_type' => 'reel',
            'media_type' => 'video',
            'image_path' => null,
            'image_public_url' => null,
            'media_path' => 'social-posts/'.$this->faker->uuid().'.mp4',
            'media_public_url' => 'https://cdn.example.test/reel.mp4',
            'media_mime' => 'video/mp4',
        ]);
    }

    public function storyImage(): static
    {
        return $this->state(fn (): array => [
            'content_type' => 'story',
            'media_type' => 'image',
            'caption' => null,
            'image_path' => null,
            'image_public_url' => null,
            'media_path' => 'social-posts/'.$this->faker->uuid().'.jpg',
            'media_public_url' => 'https://cdn.example.test/story.jpg',
            'media_mime' => 'image/jpeg',
        ]);
    }

    public function storyVideo(): static
    {
        return $this->state(fn (): array => [
            'content_type' => 'story',
            'media_type' => 'video',
            'caption' => null,
            'image_path' => null,
            'image_public_url' => null,
            'media_path' => 'social-posts/'.$this->faker->uuid().'.mp4',
            'media_public_url' => 'https://cdn.example.test/story.mp4',
            'media_mime' => 'video/mp4',
        ]);
    }
}
