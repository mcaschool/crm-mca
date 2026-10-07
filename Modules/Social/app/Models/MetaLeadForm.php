<?php

declare(strict_types=1);

namespace Modules\Social\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Tenancy\Concerns\BelongsToInstitution;

/**
 * Formulario publicitario (Meta Lead Ads) de una Página, con el programa y el asesor responsable
 * con los que entran sus contactos. Desactivado hasta que el equipo lo active.
 *
 * @property int $institution_id
 * @property int|null $social_channel_id heredado; los formularios nuevos cuelgan de meta_lead_page_id
 * @property int|null $meta_lead_page_id
 * @property string $form_id
 * @property string $name
 * @property int|null $program_id
 * @property int|null $bot_id
 * @property bool $is_active
 * @property \Illuminate\Support\Carbon|null $receiving_since contactos creados desde aquí (al activarlo)
 * @property \Illuminate\Support\Carbon|null $last_synced_at
 */
class MetaLeadForm extends Model
{
    use BelongsToInstitution;

    protected $fillable = [
        'institution_id', 'social_channel_id', 'meta_lead_page_id', 'form_id', 'name', 'program_id', 'bot_id', 'is_active', 'receiving_since', 'last_synced_at',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'receiving_since' => 'datetime', 'last_synced_at' => 'datetime'];
    }

    /** @return BelongsTo<MetaLeadPage, $this> */
    public function page(): BelongsTo
    {
        return $this->belongsTo(MetaLeadPage::class, 'meta_lead_page_id');
    }

    /** @return BelongsTo<SocialChannel, $this> */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(SocialChannel::class, 'social_channel_id');
    }
}
