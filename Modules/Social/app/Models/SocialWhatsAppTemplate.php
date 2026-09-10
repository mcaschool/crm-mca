<?php

declare(strict_types=1);

namespace Modules\Social\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Tenancy\Concerns\BelongsToInstitution;
use Modules\Social\Database\Factories\SocialWhatsAppTemplateFactory;

/**
 * Plantilla de WhatsApp (message template) de un canal provider=whatsapp. La fuente de
 * verdad del estado es Meta (sync + webhooks); aquí se refleja como STRING FLEXIBLE
 * (Meta añade estados sin previo aviso). No guarda tokens ni secretos.
 *
 * @property int $institution_id
 * @property int $social_channel_id
 * @property string|null $meta_template_id
 * @property string $name
 * @property string $language
 * @property string $category
 * @property string $status
 * @property string|null $quality_score
 * @property array<string,mixed>|null $quality_details
 * @property string|null $parameter_format
 * @property array<int,array<string,mixed>> $components
 * @property string|null $rejection_reason
 * @property array<string,mixed>|null $rejection_details
 * @property \Illuminate\Support\Carbon|null $last_synced_at
 */
class SocialWhatsAppTemplate extends Model
{
    use BelongsToInstitution;

    /** @use HasFactory<SocialWhatsAppTemplateFactory> */
    use HasFactory;

    /** Explícito: la convención derivaría "social_whats_app_templates". */
    protected $table = 'social_whatsapp_templates';

    /** Categorías de Meta. AUTHENTICATION se sincroniza pero NO se crea desde la UI. */
    public const CATEGORIES = ['MARKETING', 'UTILITY', 'AUTHENTICATION'];

    /** Estados conocidos (referencia; el campo admite CUALQUIER string que Meta envíe). */
    public const KNOWN_STATUSES = [
        'PENDING', 'APPROVED', 'REJECTED', 'PAUSED', 'DISABLED', 'FLAGGED',
        'ARCHIVED', 'DELETED', 'PENDING_DELETION', 'IN_APPEAL', 'LOCKED', 'LIMIT_EXCEEDED',
    ];

    /** Solo una plantilla APPROVED puede enviarse desde la bandeja. */
    public const SENDABLE_STATUS = 'APPROVED';

    protected $fillable = [
        'institution_id',
        'social_channel_id',
        'meta_template_id',
        'name',
        'language',
        'category',
        'status',
        'quality_score',
        'quality_details',
        'parameter_format',
        'components',
        'rejection_reason',
        'rejection_details',
        'last_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'components' => 'array',
            'quality_details' => 'array',
            'rejection_details' => 'array',
            'last_synced_at' => 'datetime',
        ];
    }

    public function isApproved(): bool
    {
        return $this->status === self::SENDABLE_STATUS;
    }

    /**
     * Componente por tipo (BODY, HEADER, FOOTER, BUTTONS) o null.
     *
     * @return array<string, mixed>|null
     */
    public function component(string $type): ?array
    {
        foreach ($this->components as $component) {
            if (strtoupper((string) ($component['type'] ?? '')) === $type) {
                return $component;
            }
        }

        return null;
    }

    /** El HEADER (si existe) es de media (IMAGE/VIDEO/DOCUMENT), no de texto. */
    public function hasMediaHeader(): bool
    {
        $header = $this->component('HEADER');

        return $header !== null
            && in_array(strtoupper((string) ($header['format'] ?? 'TEXT')), ['IMAGE', 'VIDEO', 'DOCUMENT'], true);
    }

    /**
     * Variables posicionales {{n}} del BODY (y del HEADER de texto), ordenadas.
     *
     * @return array<int, int>
     */
    public function positionalVariables(): array
    {
        $text = (string) ($this->component('BODY')['text'] ?? '');
        $header = $this->component('HEADER');
        if ($header !== null && strtoupper((string) ($header['format'] ?? 'TEXT')) === 'TEXT') {
            $text .= ' '.(string) ($header['text'] ?? '');
        }

        preg_match_all('/\{\{(\d+)\}\}/', $text, $matches);
        $vars = array_map(intval(...), $matches[1]);
        $vars = array_values(array_unique($vars));
        sort($vars);

        return $vars;
    }

    /**
     * @return BelongsTo<SocialChannel, $this>
     */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(SocialChannel::class, 'social_channel_id');
    }

    protected static function newFactory(): SocialWhatsAppTemplateFactory
    {
        return SocialWhatsAppTemplateFactory::new();
    }
}
