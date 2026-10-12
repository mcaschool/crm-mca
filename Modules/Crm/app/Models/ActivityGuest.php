<?php

declare(strict_types=1);

namespace Modules\Crm\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\Core\Tenancy\Concerns\BelongsToInstitution;

/**
 * Invitado de una actividad: un contacto existente o un nombre+correo manual. Un
 * invitado externo NUNCA crea contacto ni lead. Correo único por actividad: quitar y
 * volver a añadir reactiva la misma fila (no duplica).
 *
 * @property int $id
 * @property int $institution_id
 * @property int $activity_id
 * @property int|null $contact_id
 * @property string $name
 * @property string $email
 * @property string $language
 * @property string $status pending|sent|failed|removed
 * @property string|null $last_email_kind
 * @property Carbon|null $last_emailed_at
 * @property Carbon|null $removed_at
 */
class ActivityGuest extends Model
{
    use BelongsToInstitution;

    protected $table = 'crm_activity_guests';

    protected $fillable = [
        'institution_id', 'activity_id', 'contact_id', 'name', 'email', 'language',
        'status', 'last_email_kind', 'last_emailed_at', 'removed_at',
    ];

    protected $attributes = [
        'status' => 'pending',
        'language' => 'es',
    ];

    protected function casts(): array
    {
        return [
            'last_emailed_at' => 'datetime',
            'removed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Activity, $this> */
    public function activity(): BelongsTo
    {
        return $this->belongsTo(Activity::class);
    }

    /** @return BelongsTo<Contact, $this> */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function isActive(): bool
    {
        return $this->removed_at === null;
    }
}
