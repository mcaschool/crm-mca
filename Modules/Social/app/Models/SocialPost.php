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
 * Publicación de contenido multiformato (Post | Reel | Historia) hacia Facebook Página +
 * Instagram. Acotada por institución. El estado agrega el resultado de sus targets.
 * Los campos image_* son los históricos del flujo de POST (se conservan); Reel/Historia
 * guardan su medio en los campos generales media_* (mediaUrl() resuelve con fallback).
 *
 * @property int $institution_id
 * @property int|null $created_by
 * @property string $content_type
 * @property string $media_type
 * @property string|null $caption
 * @property string|null $image_path
 * @property string|null $image_public_url
 * @property string|null $media_path
 * @property string|null $media_public_url
 * @property string|null $media_mime
 * @property string $status
 */
class SocialPost extends Model
{
    use BelongsToInstitution;

    /** @use HasFactory<SocialPostFactory> */
    use HasFactory;

    /**
     * pending: recién creada · processing: algún target de video sigue procesándose en Meta
     * (recuperable con "Continuar") · partial: unas redes sí, otras no (sin nada en curso) ·
     * published: todas ok · failed: todas fallaron.
     */
    public const STATUSES = ['pending', 'processing', 'partial', 'published', 'failed'];

    public const CONTENT_TYPES = ['post', 'reel', 'story'];

    public const MEDIA_TYPES = ['image', 'video'];

    protected $fillable = [
        'institution_id',
        'created_by',
        'content_type',
        'media_type',
        'caption',
        'image_path',
        'image_public_url',
        'media_path',
        'media_public_url',
        'media_mime',
        'status',
    ];

    /** URL pública del medio a publicar (media_* nuevos, con fallback a los image_* históricos). */
    public function mediaUrl(): ?string
    {
        return $this->media_public_url ?? $this->image_public_url;
    }

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
