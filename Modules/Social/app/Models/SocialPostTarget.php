<?php

declare(strict_types=1);

namespace Modules\Social\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Tenancy\Concerns\BelongsToInstitution;
use Modules\Social\Database\Factories\SocialPostTargetFactory;

/**
 * Resultado de publicar en UNA red. Acotado por institución.
 *
 * @property int $institution_id
 * @property int $social_post_id
 * @property string $network
 * @property int|null $social_channel_id
 * @property string $status
 * @property string|null $external_post_id
 * @property string|null $container_id
 * @property string|null $error_message
 */
class SocialPostTarget extends Model
{
    use BelongsToInstitution;

    /** @use HasFactory<SocialPostTargetFactory> */
    use HasFactory;

    public const NETWORKS = ['facebook', 'instagram'];

    protected $fillable = [
        'institution_id',
        'social_post_id',
        'network',
        'social_channel_id',
        'status',
        'external_post_id',
        'container_id',
        'error_message',
    ];

    /**
     * @return BelongsTo<SocialPost, $this>
     */
    public function post(): BelongsTo
    {
        return $this->belongsTo(SocialPost::class, 'social_post_id');
    }

    /**
     * @return BelongsTo<SocialChannel, $this>
     */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(SocialChannel::class, 'social_channel_id');
    }

    protected static function newFactory(): SocialPostTargetFactory
    {
        return SocialPostTargetFactory::new();
    }
}
