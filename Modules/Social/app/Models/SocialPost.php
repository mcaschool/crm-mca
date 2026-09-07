<?php

declare(strict_types=1);

namespace Modules\Social\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Tenancy\Concerns\BelongsToInstitution;
use Modules\Social\Database\Factories\SocialPostFactory;

/**
 * Publicación de contenido (imagen + descripción) hacia Facebook Página + Instagram.
 * Acotada por institución. El estado agrega el resultado de sus targets.
 *
 * @property int $institution_id
 * @property int|null $created_by
 * @property string|null $caption
 * @property string $image_path
 * @property string $image_public_url
 * @property string $status
 */
class SocialPost extends Model
{
    use BelongsToInstitution;

    /** @use HasFactory<SocialPostFactory> */
    use HasFactory;

    /** pending: recién creada · partial: unas redes sí, otras no · published: todas ok · failed: todas fallaron. */
    public const STATUSES = ['pending', 'partial', 'published', 'failed'];

    protected $fillable = [
        'institution_id',
        'created_by',
        'caption',
        'image_path',
        'image_public_url',
        'status',
    ];

    /**
     * @return HasMany<SocialPostTarget, $this>
     */
    public function targets(): HasMany
    {
        return $this->hasMany(SocialPostTarget::class, 'social_post_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    protected static function newFactory(): SocialPostFactory
    {
        return SocialPostFactory::new();
    }
}
