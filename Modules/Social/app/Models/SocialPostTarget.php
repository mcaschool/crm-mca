<?php

declare(strict_types=1);

namespace Modules\Social\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\Core\Tenancy\Concerns\BelongsToInstitution;
use Modules\Social\Database\Factories\SocialPostTargetFactory;

/**
 * Resultado de publicar en UNA red. Acotado por institución. Cada red conserva su propio
 * resultado: un Facebook publicado nunca se vuelve a publicar al reintentar Instagram.
 *
 * @property int $institution_id
 * @property int $social_post_id
 * @property string $network
 * @property int|null $social_channel_id
 * @property string $status pending | processing | published | failed
 * @property string|null $external_post_id
 * @property string|null $container_id
 * @property string|null $error_message
 * @property Carbon|null $published_at
 * @property int $attempts
 * @property Carbon|null $last_attempt_at
 * @property string|null $permalink
 * @property Carbon|null $remote_deleted_at
 */
class SocialPostTarget extends Model
{
    use BelongsToInstitution;

    /** @use HasFactory<SocialPostTargetFactory> */
    use HasFactory;

    public const NETWORKS = ['facebook', 'instagram'];

    public const NETWORK_LABELS = ['facebook' => 'Facebook', 'instagram' => 'Instagram'];

    public const STATUS_LABELS = [
        'pending' => 'En preparación',
        'processing' => 'Procesando',
        'published' => 'Publicado',
        'failed' => 'Fallido',
    ];

    public const STATUS_TONES = ['pending' => 'teal', 'processing' => 'warning', 'published' => 'success', 'failed' => 'danger'];

    protected $fillable = [
        'institution_id',
        'social_post_id',
        'network',
        'social_channel_id',
        'status',
        'external_post_id',
        'container_id',
        'error_message',
        'published_at',
        'permalink',
    ];

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'last_attempt_at' => 'datetime',
            'remote_deleted_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }

    public function networkLabel(): string
    {
        return self::NETWORK_LABELS[$this->network] ?? $this->network;
    }

    public function statusLabel(): string
    {
        return $this->remote_deleted_at !== null ? __('Eliminado de la red') : __(self::STATUS_LABELS[$this->status] ?? $this->status);
    }

    public function statusTone(): string
    {
        return $this->remote_deleted_at !== null ? 'neutral' : (self::STATUS_TONES[$this->status] ?? 'neutral');
    }

    /** Enlace a la publicación en la red, si existe (permalink real o, en Facebook, por su id). */
    public function url(): ?string
    {
        if ($this->remote_deleted_at !== null || $this->status !== 'published') {
            return null;
        }
        if ((string) $this->permalink !== '') {
            return $this->permalink;
        }
        $id = (string) $this->external_post_id;
        if ($id === '') {
            return null;
        }

        return $this->network === 'facebook' ? 'https://www.facebook.com/'.$id : null;
    }

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
