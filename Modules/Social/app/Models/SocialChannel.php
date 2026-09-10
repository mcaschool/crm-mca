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
 * @property string|null $connection_status
 * @property array<string,mixed>|null $connection_meta
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

    /**
     * Estados de conexión del canal (Coexistence-ready) => etiqueta legible.
     * null = canal legado: se asume conectado por Cloud API.
     */
    public const CONNECTION_STATUSES = [
        'disconnected' => 'Desconectado',
        'pending_setup' => 'Configuración pendiente',
        'onboarding' => 'Onboarding en curso (sin confirmar)',
        'connected_cloud_api' => 'Conectado (Cloud API)',
        'connected_coexistence' => 'Conectado (Coexistence)',
        'offboarded' => 'Desconectado del teléfono (offboarded)',
        'reconnecting' => 'Reconectando',
        'error' => 'Error de conexión',
    ];

    protected $fillable = [
        'institution_id',
        'provider',
        'display_name',
        'external_id',
        'credentials',
        'is_active',
        'connection_status',
        'connection_meta',
    ];

    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array',
            'is_active' => 'boolean',
            'connection_meta' => 'array',
        ];
    }

    /** El canal puede enviar por la API (un canal offboarded NO envía hasta reconectar). */
    public function canSendViaApi(): bool
    {
        return $this->connection_status !== 'offboarded';
    }

    /** Etiqueta legible del estado de conexión (null = legado, Cloud API). */
    public function connectionLabel(): string
    {
        return self::CONNECTION_STATUSES[$this->connection_status ?? 'connected_cloud_api']
            ?? (string) $this->connection_status;
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

    /**
     * @return HasMany<SocialWhatsAppTemplate, $this>
     */
    public function whatsappTemplates(): HasMany
    {
        return $this->hasMany(SocialWhatsAppTemplate::class);
    }

    protected static function newFactory(): SocialChannelFactory
    {
        return SocialChannelFactory::new();
    }
}
