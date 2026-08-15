<?php

declare(strict_types=1);

namespace Modules\Crm\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Tenancy\Concerns\BelongsToInstitution;
use Modules\Crm\Database\Factories\LeadFactory;

/**
 * Lead: consolida la senal comercial. Un lead por conversacion con intencion
 * (D4): uno nuevo tras N dias de inactividad o cambio de producto de interes.
 */
class Lead extends Model
{
    use BelongsToInstitution;

    /** @use HasFactory<LeadFactory> */
    use HasFactory;

    protected $fillable = [
        'institution_id',
        'contact_id',
        'bot_id',
        'product_type',
        'program_id',
        'area',
        'goal',
        'level',
        'source',
        'status',
        'interest_level',
    ];

    protected $attributes = [
        'product_type' => 'microcredential',
        'status' => 'new',
        'interest_level' => 'low',
    ];

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    protected static function newFactory(): LeadFactory
    {
        return LeadFactory::new();
    }
}
