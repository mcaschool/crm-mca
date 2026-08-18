<?php

declare(strict_types=1);

namespace Modules\Crm\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Tenancy\Concerns\BelongsToInstitution;

/**
 * Nota interna de un lead (autor + fecha). El nombre del autor se copia para que
 * el historico se conserve aunque el usuario cambie o se elimine.
 *
 * @property int $institution_id
 * @property int $lead_id
 * @property int|null $user_id
 * @property string|null $author_name
 * @property string $body
 * @property \Illuminate\Support\Carbon $created_at
 */
class LeadNote extends Model
{
    use BelongsToInstitution;

    protected $fillable = ['institution_id', 'lead_id', 'user_id', 'author_name', 'body'];

    /**
     * @return BelongsTo<Lead, $this>
     */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }
}
