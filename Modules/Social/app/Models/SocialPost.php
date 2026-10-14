<?php

declare(strict_types=1);

namespace Modules\Social\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\Core\Tenancy\Concerns\BelongsToInstitution;
use Modules\Social\Database\Factories\SocialPostFactory;

/**
 * Publicación de contenido multiformato (Post | Reel | Historia) hacia Facebook Página +
 * Instagram. Acotada por institución. El estado agrega el resultado de sus targets.
 * Los campos image_* son los históricos del flujo de POST (se conservan); Reel/Historia
 * guardan su medio en los campos generales media_* (mediaUrl() resuelve con fallback).
 *
 * Ciclo de vida: draft → scheduled → preparing (reclamada por una ejecución) → processing
 * (algún video sigue en Meta) → published | partial | failed. Cancelada: cancelled.
 * 'pending' es el estado histórico de la publicación inmediata en curso.
 *
 * @property int $institution_id
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property string $content_type
 * @property string $media_type
 * @property string|null $caption
 * @property string|null $image_path
 * @property string|null $image_public_url
 * @property string|null $media_path
 * @property string|null $media_public_url
 * @property string|null $media_mime
 * @property string|null $media_name
 * @property int|null $media_size
 * @property string $status
 * @property array<int, string>|null $networks
 * @property Carbon|null $scheduled_at
 * @property string|null $timezone
 * @property Carbon|null $published_at
 * @property string|null $run_id
 * @property Carbon|null $locked_at
 * @property int $attempts
 * @property string|null $last_error
 * @property Carbon|null $cancelled_at
 * @property int|null $cancelled_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class SocialPost extends Model
{
    use BelongsToInstitution;

    /** @use HasFactory<SocialPostFactory> */
    use HasFactory;

    public const DRAFT = 'draft';

    public const SCHEDULED = 'scheduled';

    public const PREPARING = 'preparing';

    public const PROCESSING = 'processing';

    public const PUBLISHED = 'published';

    public const PARTIAL = 'partial';

    public const FAILED = 'failed';

    public const CANCELLED = 'cancelled';

    /** Histórico: publicación inmediata en curso (equivale a «En preparación»). */
    public const PENDING = 'pending';

    public const STATUSES = [self::DRAFT, self::SCHEDULED, self::PREPARING, self::PROCESSING, self::PUBLISHED, self::PARTIAL, self::FAILED, self::CANCELLED, self::PENDING];

    /** Estados en los que el contenido aún se puede editar (nada ha salido hacia Meta). */
    public const EDITABLE = [self::DRAFT, self::SCHEDULED];

    /** Estados en los que una ejecución ya trabaja o trabajó con Meta. */
    public const IN_FLIGHT = [self::PREPARING, self::PROCESSING, self::PENDING];

    public const CONTENT_TYPES = ['post', 'reel', 'story'];

    public const MEDIA_TYPES = ['image', 'video'];

    /** Etiqueta visible por estado (se traduce al presentarla). */
    public const STATUS_LABELS = [
        self::DRAFT => 'Borrador',
        self::SCHEDULED => 'Programada',
        self::PREPARING => 'En preparación',
        self::PENDING => 'En preparación',
        self::PROCESSING => 'Procesando',
        self::PUBLISHED => 'Publicada',
        self::PARTIAL => 'Parcialmente publicada',
        self::FAILED => 'Fallida',
        self::CANCELLED => 'Cancelada',
    ];

    /** Tono de la insignia por estado (solo comunica estado). */
    public const STATUS_TONES = [
        self::DRAFT => 'neutral',
        self::SCHEDULED => 'blue',
        self::PREPARING => 'teal',
        self::PENDING => 'teal',
        self::PROCESSING => 'warning',
        self::PUBLISHED => 'success',
        self::PARTIAL => 'warning',
        self::FAILED => 'danger',
        self::CANCELLED => 'neutral',
    ];

    public const CONTENT_LABELS = ['post' => 'Post', 'reel' => 'Reel', 'story' => 'Historia'];

    protected $fillable = [
        'institution_id',
        'created_by',
        'updated_by',
        'content_type',
        'media_type',
        'caption',
        'image_path',
        'image_public_url',
        'media_path',
        'media_public_url',
        'media_mime',
        'media_name',
        'media_size',
        'status',
        'networks',
        'scheduled_at',
        'timezone',
        'published_at',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'networks' => 'array',
            'scheduled_at' => 'datetime',
            'published_at' => 'datetime',
            'locked_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'attempts' => 'integer',
            'media_size' => 'integer',
        ];
    }

    /** URL pública del medio a publicar (media_* nuevos, con fallback a los image_* históricos). */
    public function mediaUrl(): ?string
    {
        return $this->media_public_url ?? $this->image_public_url;
    }

    /** Ruta del medio en el disco público (media_* nuevos, con fallback a image_*). */
    public function mediaPath(): ?string
    {
        return $this->media_path ?? $this->image_path;
    }

    public function hasMedia(): bool
    {
        return (string) $this->mediaUrl() !== '';
    }

    public function isEditable(): bool
    {
        return in_array($this->status, self::EDITABLE, true);
    }

    public function isInFlight(): bool
    {
        return in_array($this->status, self::IN_FLIGHT, true);
    }

    public function statusLabel(): string
    {
        return __(self::STATUS_LABELS[$this->status] ?? $this->status);
    }

    public function statusTone(): string
    {
        return self::STATUS_TONES[$this->status] ?? 'neutral';
    }

    public function contentLabel(): string
    {
        return __(self::CONTENT_LABELS[$this->content_type] ?? $this->content_type);
    }

    /**
     * Redes de destino: las pedidas (borradores y programadas) o, en las publicaciones
     * históricas sin ese dato, las de sus resultados.
     *
     * @return array<int, string>
     */
    public function destinationNetworks(): array
    {
        $networks = is_array($this->networks) && $this->networks !== []
            ? $this->networks
            : $this->targets->pluck('network')->all();

        return array_values(array_intersect(SocialPostTarget::NETWORKS, $networks));
    }

    /** Momento de referencia para ordenar y situar en el calendario. */
    public function referenceAt(): ?Carbon
    {
        return $this->published_at ?? $this->scheduled_at ?? $this->created_at;
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

    /**
     * @return BelongsTo<User, $this>
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    protected static function newFactory(): SocialPostFactory
    {
        return SocialPostFactory::new();
    }
}
