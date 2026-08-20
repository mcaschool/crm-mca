<?php

declare(strict_types=1);

namespace Modules\Notifications\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Modules\Core\Concerns\HasEncryptedConfig;
use Modules\Core\Tenancy\Concerns\BelongsToInstitution;
use Modules\Notifications\Database\Factories\EmailSenderFactory;

/**
 * Remitente de correo saliente. Nombre visible + direccion (@mcaschool.education) +
 * sus PROPIAS credenciales SMTP. Los secretos (host/port/username/password/
 * encryption) viven en `config` CIFRADO (encrypted:array) y oculto de toda
 * serializacion; `config_preview` guarda la version enmascarada que ve el panel.
 * Mismo patron de secretos que Integration (ver HasEncryptedConfig, 7 barreras).
 *
 * @property int $institution_id
 * @property string $name
 * @property string $from_address
 * @property string $status
 * @property \Illuminate\Support\Carbon|null $last_tested_at
 * @property bool|null $last_test_ok
 * @property string|null $last_test_message
 */
class EmailSender extends Model
{
    use BelongsToInstitution;
    use HasEncryptedConfig;

    /** @use HasFactory<EmailSenderFactory> */
    use HasFactory;

    protected $fillable = [
        'institution_id',
        'name',
        'from_address',
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

    /** Etiqueta legible del remitente para selectores/listados: "Nombre <correo>". */
    public function label(): string
    {
        return $this->name.' <'.$this->from_address.'>';
    }

    protected static function newFactory(): EmailSenderFactory
    {
        return EmailSenderFactory::new();
    }
}
