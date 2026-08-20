<?php

declare(strict_types=1);

namespace Modules\Notifications\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Notifications\Models\EmailTemplate;

/**
 * @extends Factory<EmailTemplate>
 *
 * Por defecto crea una plantilla COMPARTIDA (user_id = null). Usa ->propia($userId)
 * para una plantilla privada de un usuario concreto. `institution_id` lo fija el
 * trait BelongsToInstitution al crear (contexto de institución activo).
 */
class EmailTemplateFactory extends Factory
{
    protected $model = EmailTemplate::class;

    public function definition(): array
    {
        return [
            'user_id' => null,
            'name' => 'Plantilla '.$this->faker->unique()->words(2, true),
            'subject' => 'Hola [Nombre], información sobre [Área]',
            'body' => '<p>Hola <strong>[Nombre]</strong>, gracias por tu interés en [Programa].</p>',
            'status' => 'active',
        ];
    }

    /** Plantilla PROPIA (privada) de un usuario. */
    public function propia(int $userId): static
    {
        return $this->state(fn (): array => ['user_id' => $userId]);
    }
}
