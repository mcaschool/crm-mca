<?php

declare(strict_types=1);

namespace Modules\Social\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Social\Models\SocialPost;
use Modules\Social\Models\SocialPostTarget;

/**
 * @extends Factory<SocialPostTarget>
 */
class SocialPostTargetFactory extends Factory
{
    protected $model = SocialPostTarget::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'social_post_id' => SocialPost::factory(),
            'network' => $this->faker->randomElement(SocialPostTarget::NETWORKS),
            'social_channel_id' => null,
            'status' => 'pending',
            'external_post_id' => null,
            'container_id' => null,
            'error_message' => null,
        ];
    }
}
