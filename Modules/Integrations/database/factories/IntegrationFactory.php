<?php

declare(strict_types=1);

namespace Modules\Integrations\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Integrations\Models\Integration;

/**
 * @extends Factory<Integration>
 *
 * `config` NO es asignable en masa (barrera anti-mass-assignment), asi que se
 * fija fuera de fill(), via afterMaking, usando el punto de acceso del modelo.
 */
class IntegrationFactory extends Factory
{
    protected $model = Integration::class;

    public function definition(): array
    {
        return [
            'type' => 'ai_provider',
            'provider' => 'openai',
            'name' => 'OpenAI '.$this->faker->unique()->word(),
            'status' => 'active',
        ];
    }

    /**
     * Secreto por defecto. Se sobreescribe con withSecrets().
     */
    public function configure(): static
    {
        return $this->afterMaking(function (Integration $integration): void {
            // Solo si aun no tiene secreto (withSecrets ya lo habra puesto).
            if ($integration->secret('api_key') === null) {
                $integration->replaceSecrets(['api_key' => 'sk-test-0123456789ABCDEF']);
            }
        });
    }

    /**
     * @param  array<string,mixed>  $secrets
     */
    public function withSecrets(array $secrets): static
    {
        // Se captura $secrets POR VALOR en el closure (no via $this, que al clonar
        // seguiria apuntando a la factory original).
        return $this->afterMaking(function (Integration $integration) use ($secrets): void {
            $integration->replaceSecrets($secrets);
        });
    }
}
