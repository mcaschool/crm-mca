<?php

declare(strict_types=1);

namespace Modules\Notifications\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Notifications\Models\EmailSender;

/**
 * @extends Factory<EmailSender>
 *
 * `config` (SMTP cifrado) NO es asignable en masa; se fija via afterMaking con el
 * punto de acceso del modelo (replaceSecrets), igual que IntegrationFactory.
 */
class EmailSenderFactory extends Factory
{
    protected $model = EmailSender::class;

    public function definition(): array
    {
        $slug = $this->faker->unique()->userName();

        return [
            'name' => 'Remitente '.$this->faker->word(),
            'from_address' => $slug.'@mcaschool.education',
            'status' => 'active',
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (EmailSender $sender): void {
            if ($sender->secret('host') === null) {
                $sender->replaceSecrets([
                    'host' => 'smtp.hostinger.com',
                    'port' => '465',
                    'username' => $sender->from_address,
                    'password' => 'smtp-secret-0123456789',
                    'encryption' => 'ssl',
                ]);
            }
        });
    }

    /**
     * @param  array<string,mixed>  $secrets
     */
    public function withSecrets(array $secrets): static
    {
        return $this->afterMaking(function (EmailSender $sender) use ($secrets): void {
            $sender->replaceSecrets($secrets);
        });
    }
}
