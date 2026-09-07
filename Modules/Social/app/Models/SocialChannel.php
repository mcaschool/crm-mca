<?php

declare(strict_types=1);

namespace Modules\Social\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Tenancy\Concerns\BelongsToInstitution;
use Modules\Social\Database\Factories\SocialChannelFactory;

/**
 * Canal social conectado (una cuenta de WhatsApp / Instagram / Messenger). Las
 * credenciales se guardan CIFRADAS (cast encrypted:array; nunca en claro). Acotado por
 * institución (trait BelongsToInstitution sella institution_id).
 *
 * @property int $institution_id
 * @property string $provider
 * @property string $display_name
 * @property string|null $external_id
 * @property array<string,mixed>|null $credentials
 * @property bool $is_active
 */
class SocialChannel extends Model
{
    use BelongsToInstitution;

    /** @use HasFactory<SocialChannelFactory> */
    use HasFactory;

    /** Proveedores soportados => etiqueta legible. */
    public const PROVIDERS = [
        'whatsapp' => 'WhatsApp',
        'instagram' => 'Instagram',
        'messenger' => 'Facebook Messenger',
    ];

    protected $fillable = [
        'institution_id',
        'provider',
        'display_name',
        'external_id',
        'credentials',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array',
            'is_active' => 'boolean',
        ];
    }

    /** Etiqueta legible del proveedor de este canal. */
    public function providerLabel(): string
    {
        return self::PROVIDERS[$this->provider] ?? $this->provider;
    }

    /**
     * @return HasMany<SocialConversation, $this>
     */
    public function conversations(): HasMany
    {
        return $this->hasMany(SocialConversation::class);
    }

    protected static function newFactory(): SocialChannelFactory
    {
        return SocialChannelFactory::new();
    }
}
