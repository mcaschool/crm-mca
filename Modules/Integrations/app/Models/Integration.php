<?php

declare(strict_types=1);

namespace Modules\Integrations\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Modules\Core\Concerns\HasEncryptedConfig;
use Modules\Core\Tenancy\Concerns\BelongsToInstitution;
use Modules\Integrations\Database\Factories\IntegrationFactory;

/**
 * Credencial de integracion de un tercero (proveedor de IA, n8n, SMTP, Stripe...).
 *
 * `config` guarda los secretos CIFRADOS (encrypted:array) y esta oculto de toda
 * serializacion. `config_preview` guarda la version enmascarada, sin cifrar,
 * que es lo unico que el panel muestra. Ver HasEncryptedConfig y las 7 barreras.
 *
 * @property int $institution_id
 * @property string $type
 * @property string|null $provider
 * @property string $name
 * @property string $status
 * @property \Illuminate\Support\Carbon|null $last_tested_at
 * @property bool|null $last_test_ok
 * @property string|null $last_test_message
 */
class Integration extends Model
{
    use BelongsToInstitution;
    use HasEncryptedConfig;

    /** @use HasFactory<IntegrationFactory> */
    use HasFactory;

    protected $fillable = [
        'institution_id',
        'type',
        'provider',
        'name',
        // OJO: `config` NO es asignable en masa; se escribe con replaceSecrets().
        'status',
        'last_tested_at',
        'last_test_ok',
        'last_test_message',
    ];

    /** Barrera 1: el secreto nunca sale por serializacion. */
    protected $hidden = [
        'config',
    ];

    protected function casts(): array
    {
        return [
            'config' => 'encrypted:array',
            'config_preview' => 'array',
            'last_tested_at' => 'datetime',
            'last_test_ok' => 'boolean',
        ];
    }

    protected static function newFactory(): IntegrationFactory
    {
        return IntegrationFactory::new();
    }
}
