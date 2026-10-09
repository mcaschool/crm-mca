<?php

declare(strict_types=1);

namespace Modules\Social\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
 * @property bool $advisor_enabled asesor inteligente activado en este canal (apagado por defecto)
 * @property int|null $advisor_bot_id asesor asignado
 * @property int $advisor_reply_delay espera antes de responder (segundos)
 * @property array{days?: array<int,int>, from?: string, to?: string}|null $advisor_schedule horario (null = siempre)
 * @property string|null $advisor_off_hours_message
 * @property bool $advisor_handoff_enabled transferir a una persona cuando lo pida
 * @property string|null $advisor_handoff_message
 * @property bool $advisor_pause_on_human (histórico: ahora SIEMPRE se pausa cuando responde una persona)
 * @property int|null $advisor_assigned_by quién asignó/activó/desactivó el asesor por última vez
 * @property \Illuminate\Support\Carbon|null $advisor_assigned_at
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
        'advisor_enabled',
        'advisor_bot_id',
        'advisor_reply_delay',
        'advisor_schedule',
        'advisor_off_hours_message',
        'advisor_handoff_enabled',
        'advisor_handoff_message',
        'advisor_pause_on_human',
        'advisor_assigned_by',
        'advisor_assigned_at',
    ];

    /** Esperas permitidas antes de responder (segundos). Se aplican tras confirmar el webhook. */
    public const ADVISOR_DELAYS = [0, 5, 10, 20, 30];

    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array',
            'is_active' => 'boolean',
            'connection_meta' => 'array',
            'advisor_enabled' => 'boolean',
            'advisor_reply_delay' => 'integer',
            'advisor_schedule' => 'array',
            'advisor_handoff_enabled' => 'boolean',
            'advisor_pause_on_human' => 'boolean',
            'advisor_assigned_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<\Modules\Institutions\Models\Bot, $this>
     */
    public function advisorBot(): BelongsTo
    {
        return $this->belongsTo(\Modules\Institutions\Models\Bot::class, 'advisor_bot_id');
    }

    /**
     * ¿Está dentro del horario de atención automática? Sin horario = siempre. El horario es
     * días ISO (1 = lunes … 7 = domingo) y franja «desde/hasta» en la zona horaria de la app.
     */
    public function withinAdvisorSchedule(?\Carbon\CarbonInterface $at = null): bool
    {
        $schedule = $this->advisor_schedule;
        if (! is_array($schedule) || $schedule === []) {
            return true;
        }

        $at ??= now();
        $days = array_map('intval', (array) ($schedule['days'] ?? []));
        if ($days !== [] && ! in_array((int) $at->isoWeekday(), $days, true)) {
            return false;
        }

        $now = $at->format('H:i');
        $from = (string) ($schedule['from'] ?? '00:00');
        $to = (string) ($schedule['to'] ?? '23:59');

        return $from <= $to ? ($now >= $from && $now <= $to) : ($now >= $from || $now <= $to);
    }

    /** ¿Tiene con qué enviar (credencial guardada y conexión utilizable)? */
    public function hasSender(): bool
    {
        return (string) ($this->credentials['token'] ?? '') !== '' && $this->canSendViaApi();
    }

    /**
     * ¿Puede la atención automática enviar por este canal? Activo, con credencial y con una
     * conexión utilizable (desconectado, sin terminar de configurar u offboarded: no).
     */
    public function automationCanSend(): bool
    {
        return $this->is_active && $this->hasSender()
            && ! in_array($this->connection_status, ['disconnected', 'pending_setup', 'offboarded'], true);
    }

    /** @return BelongsTo<User, $this> */
    public function advisorAssigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'advisor_assigned_by');
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
