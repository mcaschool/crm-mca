<?php

declare(strict_types=1);

namespace Modules\Social\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Tenancy\Concerns\BelongsToInstitution;

/**
 * Página de Facebook que la conexión de Meta de una empresa puede usar para formularios
 * publicitarios. Su token de Página va cifrado y oculto. Separada por completo de los canales de
 * Messenger: configurar formularios nunca toca la credencial de Messenger.
 *
 * Recepción de contactos: APAGADA hasta que «Comprobar acceso» verifique la Página, los
 * formularios y la lectura real de contactos (access_status = verified).
 *
 * @property int $institution_id
 * @property int $meta_connection_id
 * @property string $page_id
 * @property string $name
 * @property string $page_token
 * @property array<int, string>|null $tasks
 * @property bool $available
 * @property bool $selected
 * @property string $access_status
 * @property array<string, mixed>|null $access_result
 * @property \Illuminate\Support\Carbon|null $access_checked_at
 * @property bool $receiving_enabled
 * @property \Illuminate\Support\Carbon|null $last_polled_at
 * @property string|null $last_error
 */
class MetaLeadPage extends Model
{
    use BelongsToInstitution;

    protected $fillable = [
        'institution_id', 'meta_connection_id', 'page_id', 'name', 'page_token', 'tasks', 'available', 'selected',
        'access_status', 'access_result', 'access_checked_at', 'receiving_enabled', 'last_polled_at', 'last_error',
    ];

    protected $hidden = ['page_token'];

    protected function casts(): array
    {
        return [
            'page_token' => 'encrypted',
            'tasks' => 'array',
            'available' => 'boolean',
            'selected' => 'boolean',
            'access_result' => 'array',
            'access_checked_at' => 'datetime',
            'receiving_enabled' => 'boolean',
            'last_polled_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<MetaConnection, $this> */
    public function metaConnection(): BelongsTo
    {
        return $this->belongsTo(MetaConnection::class, 'meta_connection_id');
    }

    /** @return HasMany<MetaLeadForm, $this> */
    public function forms(): HasMany
    {
        return $this->hasMany(MetaLeadForm::class);
    }

    public function verified(): bool
    {
        return $this->access_status === 'verified';
    }

    /** ¿Recibe contactos ahora? Activada, verificada, disponible y con la conexión utilizable. */
    public function receiving(): bool
    {
        return $this->receiving_enabled && $this->selected && $this->available && $this->verified()
            && ($this->metaConnection?->usable() ?? false);
    }
}
