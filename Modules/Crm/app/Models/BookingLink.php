<?php

declare(strict_types=1);

namespace Modules\Crm\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\Core\Tenancy\Concerns\BelongsToInstitution;

/**
 * Enlace de reserva contextual generado desde la ficha de un lead: la reserva hecha con él queda
 * vinculada a ese lead sin exponer su id ni sus datos (solo se guarda el hash del token).
 *
 * @property int $id
 * @property int $institution_id
 * @property int $lead_id
 * @property int $user_id
 * @property int|null $created_by_user_id
 * @property string $token_hash
 * @property Carbon $expires_at
 */
class BookingLink extends Model
{
    use BelongsToInstitution;

    public const TTL_DAYS = 60;

    protected $table = 'crm_booking_links';

    protected $fillable = ['institution_id', 'lead_id', 'user_id', 'created_by_user_id', 'token_hash', 'expires_at'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime'];
    }

    /** @return BelongsTo<Lead, $this> */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }
}
