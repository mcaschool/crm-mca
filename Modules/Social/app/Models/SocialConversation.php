<?php

declare(strict_types=1);

namespace Modules\Social\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Tenancy\Concerns\BelongsToInstitution;
use Modules\Social\Database\Factories\SocialConversationFactory;

/**
 * Conversación (hilo) de un canal social con un contacto. `provider` está denormalizado
 * para ícono/filtro sin join. Acotada por institución.
 *
 * @property int $institution_id
 * @property int $social_channel_id
 * @property string $provider
 * @property string $external_conversation_id
 * @property string|null $contact_name
 * @property string|null $contact_external_id
 * @property string|null $contact_avatar_url
 * @property string $status
 * @property int|null $assigned_to
 * @property int $unread_count
 * @property string|null $last_message_preview
 * @property \Illuminate\Support\Carbon|null $last_message_at
 * @property string $automation_state bot | waiting_human | human | paused | error
 * @property string|null $automation_reason motivo visible del estado (ver AUTOMATION_REASONS)
 * @property int|null $automation_changed_by quién cambió el estado (null = el sistema)
 * @property \Illuminate\Support\Carbon|null $automation_changed_at
 * @property \Illuminate\Support\Carbon|null $advisor_lease_until turno persistente de un worker sobre la conversación
 * @property \Illuminate\Support\Carbon|null $advisor_off_hours_notified_at
 */
class SocialConversation extends Model
{
    use BelongsToInstitution;

    /** @use HasFactory<SocialConversationFactory> */
    use HasFactory;

    protected $fillable = [
        'institution_id',
        'social_channel_id',
        'provider',
        'external_conversation_id',
        'contact_name',
        'contact_external_id',
        'contact_avatar_url',
        'status',
        'assigned_to',
        'unread_count',
        'last_message_preview',
        'last_message_at',
        'automation_state',
        'automation_reason',
        'automation_changed_by',
        'automation_changed_at',
        'advisor_off_hours_notified_at',
    ];

    /** Estados de atención (asesor inteligente ↔ personas del equipo) => etiqueta visible. */
    public const AUTOMATION_STATES = [
        'bot' => 'Asesor inteligente atendiendo',
        'waiting_human' => 'Esperando a una persona',
        'human' => 'En atención humana',
        'paused' => 'Automatización pausada',
        'error' => 'Error de automatización',
    ];

    /**
     * Motivos visibles del estado => [etiqueta, acción recomendada]. Nunca contienen detalles
     * técnicos, nombres de modelos ni errores del proveedor (eso queda en el registro interno).
     */
    public const AUTOMATION_REASONS = [
        'taken_over' => ['Una persona tomó la conversación', null],
        'human_replied' => ['Una persona del equipo respondió', null],
        'replied_from_app' => ['Se respondió desde la app de WhatsApp del teléfono', null],
        'replied_externally' => ['Se respondió desde fuera del CRM (Meta Business Suite, Messenger o Instagram)', null],
        'paused_by_user' => ['Pausada por una persona del equipo', null],
        'reactivated' => ['Asesor reactivado', null],
        'contact_requested_person' => ['La persona pidió hablar con alguien del equipo', 'Responde desde la bandeja.'],
        'advisor_handoff' => ['El asesor transfirió la conversación', 'Responde desde la bandeja.'],
        'unresolved' => ['Consulta fuera de las fuentes del asesor', 'Responde desde la bandeja y, si procede, añade la información a su conocimiento.'],
        'limit_reached' => ['Se alcanzó el límite de mensajes con IA', 'Responde desde la bandeja.'],
        'unsupported_attachment' => ['Adjunto que el asesor no puede analizar', 'Revisa el adjunto y responde desde la bandeja.'],
        'ai_not_configured' => ['El asesor no tiene un servicio de IA configurado', 'Revisa la configuración de IA del asesor.'],
        'ai_model_unavailable' => ['El modelo de IA configurado no existe o no está disponible', 'Revisa el modelo asignado al asesor.'],
        'ai_auth' => ['La integración de IA no es válida o está inactiva', 'Revisa la integración de IA en Integraciones.'],
        'ai_quota' => ['Se agotó la cuota del servicio de IA', 'Revisa el plan del proveedor de IA.'],
        'ai_timeout' => ['El servicio de IA tardó demasiado', 'Responde desde la bandeja; el asesor volverá a intentarlo en los siguientes mensajes si lo reactivas.'],
        'ai_rate_limited' => ['El servicio de IA rechazó por exceso de solicitudes', 'Responde desde la bandeja y reactiva el asesor más tarde.'],
        'ai_error' => ['El servicio de IA no respondió', 'Responde desde la bandeja y reactiva el asesor más tarde.'],
        'advisor_inactive' => ['El asesor asignado está inactivo o no existe', 'Activa el asesor o asigna otro al canal.'],
        'internal_error' => ['Error interno al preparar la respuesta', 'Responde desde la bandeja y reactiva el asesor más tarde.'],
        'conversation_busy' => ['La conversación siguió ocupada por otro proceso demasiado tiempo', 'Responde desde la bandeja y reactiva el asesor más tarde.'],
        'channel_disconnected' => ['El canal está desconectado o sin credencial', 'Reconecta el canal en Canales.'],
        'social_token_invalid' => ['La credencial del canal caducó o no es válida', 'Reconecta el canal en Canales.'],
        'window_closed' => ['El canal no permite responder fuera de su ventana de 24 h', 'Responde con una plantilla aprobada (WhatsApp) o espera a que la persona escriba.'],
        'send_failed' => ['El canal rechazó la respuesta automática', 'Responde desde la bandeja.'],
        'delivery_unknown' => ['No se pudo confirmar si la respuesta automática llegó', 'Revisa la conversación en la app del canal antes de responder: no se reenvió para no duplicarla.'],
    ];

    /** Estado de error: la automatización se detuvo por un fallo (con motivo visible). */
    public const ERROR_STATE = 'error';

    public function automationLabel(): string
    {
        return __(self::AUTOMATION_STATES[$this->automation_state ?? 'bot'] ?? (string) $this->automation_state);
    }

    public function automationReasonLabel(): ?string
    {
        $reason = (string) $this->automation_reason;

        return $reason !== '' ? __(self::AUTOMATION_REASONS[$reason][0] ?? $reason) : null;
    }

    public function automationAction(): ?string
    {
        $action = self::AUTOMATION_REASONS[(string) $this->automation_reason][1] ?? null;

        return $action !== null ? __($action) : null;
    }

    /** @return BelongsTo<User, $this> */
    public function automationChanger(): BelongsTo
    {
        return $this->belongsTo(User::class, 'automation_changed_by');
    }

    /** ¿Puede responder el asesor inteligente? (solo si nadie del equipo la atiende) */
    public function advisorMayReply(): bool
    {
        return ($this->automation_state ?? 'bot') === 'bot' && $this->assigned_to === null;
    }

    protected function casts(): array
    {
        return [
            'unread_count' => 'integer',
            'last_message_at' => 'datetime',
            'advisor_off_hours_notified_at' => 'datetime',
            'automation_changed_at' => 'datetime',
            'advisor_lease_until' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<SocialChannel, $this>
     */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(SocialChannel::class, 'social_channel_id');
    }

    /**
     * @return HasMany<SocialMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(SocialMessage::class);
    }

    /**
     * Agente del panel asignado a la conversación.
     *
     * @return BelongsTo<User, $this>
     */
    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    protected static function newFactory(): SocialConversationFactory
    {
        return SocialConversationFactory::new();
    }
}
