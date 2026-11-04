<?php

declare(strict_types=1);

namespace Modules\FollowUp\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Tenancy\Concerns\BelongsToInstitution;

class CommunicationConsentEvent extends Model
{
    use BelongsToInstitution;

    public const UPDATED_AT = null;

    protected $fillable = ['institution_id', 'communication_consent_id', 'from_state', 'to_state', 'source', 'evidence', 'actor_user_id', 'policy_version', 'occurred_at'];

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime'];
    }

    /** @return BelongsTo<CommunicationConsent, $this> */
    public function consent(): BelongsTo
    {
        return $this->belongsTo(CommunicationConsent::class, 'communication_consent_id');
    }
}
