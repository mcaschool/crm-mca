<?php

declare(strict_types=1);

namespace Modules\Institutions\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Institutions\Models\Bot;

/**
 * @extends Factory<Bot>
 *
 * institution_id se rellena solo desde el contexto activo (BelongsToInstitution).
 * Para fijarlo explicito: Bot::factory()->for($institution).
 */
class BotFactory extends Factory
{
    protected $model = Bot::class;

    public function definition(): array
    {
        return [
            'name' => 'Bot '.$this->faker->word(),
            'slug' => $this->faker->unique()->slug(2),
            'assistant_name' => $this->faker->firstName(),
            'landing_url' => $this->faker->url(),
            'public_key' => Str::random(32),
            'allowed_origins' => [$this->faker->url()],
            'default_language' => 'es',
            'status' => 'active',
            // Como los asesores existentes antes de «Identidad e instrucciones» (prompt global).
            'uses_legacy_prompt' => true,
        ];
    }

    /**
     * Asesor nuevo con identidad propia (sin el prompt global heredado de Celia).
     *
     * @param  array<string, mixed>  $identity
     */
    public function withOwnIdentity(array $identity = []): static
    {
        return $this->state(fn () => $identity + ['uses_legacy_prompt' => false]);
    }
}
