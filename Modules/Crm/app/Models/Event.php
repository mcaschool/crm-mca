<?php

declare(strict_types=1);

namespace Modules\Crm\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Modules\Catalog\Models\Program;
use Modules\Core\Tenancy\Concerns\BelongsToInstitution;
use Modules\Crm\Database\Factories\EventFactory;

/**
 * Evento: todo comportamiento deja rastro, aunque la respuesta sea un enlace.
 * Append-only, alto volumen: solo created_at.
 *
 * Las "preguntas no resueltas" de Celia se registran como event_type
 * = 'celia.unresolved' (sin tabla propia en el dia 1).
 *
 * @property int $institution_id
 * @property int|null $contact_id
 * @property int|null $conversation_id
 * @property int|null $bot_id
 * @property string $event_type
 * @property array<string,mixed>|null $event_data
 * @property \Illuminate\Support\Carbon|null $created_at
 */
class Event extends Model
{
    use BelongsToInstitution;

    /** @use HasFactory<EventFactory> */
    use HasFactory;

    /** Append-only: sin updated_at. */
    public const UPDATED_AT = null;

    protected $fillable = [
        'institution_id',
        'contact_id',
        'conversation_id',
        'bot_id',
        'event_type',
        'event_data',
    ];

    protected function casts(): array
    {
        return [
            'event_data' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * Icono (Lucide) del evento para el historial. Centralizado para que todas las
     * vistas (ficha de lead/contacto, conversacion, actividad del panel) coincidan.
     */
    public function icon(): string
    {
        return match ($this->event_type) {
            'widget_opened', 'started_celia' => 'message-circle',
            'contact_created' => 'user-plus',
            'lead_captured', 'lead_created' => 'user',
            'used_matcher' => 'sparkles',
            'program_interest', 'viewed_program' => 'graduation-cap',
            'viewed_catalog' => 'book-open',
            'viewed_certification' => 'graduation-cap',
            'viewed_duration' => 'clock',
            'viewed_price' => 'percent',
            'viewed_microcredential_definition' => 'file-text',
            'clicked_enrollment' => 'download',
            'corporate_interest', 'corporate_contact', 'corporate_form' => 'building-2',
            'unresolved_question' => 'sticky-note',
            'lead_transferred' => 'arrow-right-left',
            'recontacted' => 'bell',
            'email_sent' => 'mail',
            default => 'activity',
        };
    }

    /**
     * Etiqueta legible y bilingue del evento (nunca el codigo crudo). Si el tipo no
     * esta en lang/{locale}/events.php, se humaniza el codigo como ultimo recurso.
     */
    public function label(?string $locale = null): string
    {
        $key = 'events.'.$this->event_type;

        if (trans()->has($key, $locale)) {
            return (string) trans($key, [], $locale);
        }

        return Str::of($this->event_type)->replace('_', ' ')->ucfirst()->toString();
    }

    /**
     * Detalle especifico del evento (clave para clasificar al prospecto): el
     * programa, el enlace/recurso visitado o la pregunta sin resolver. Null si el
     * evento no tiene un detalle concreto que mostrar.
     */
    public function detail(?string $locale = null): ?string
    {
        $data = $this->event_data ?? [];

        // Interes en un programa: nombre del programa.
        if (isset($data['program_id'])) {
            $name = Program::query()->find($data['program_id'])?->translate('name', $locale);
            if (is_string($name) && $name !== '') {
                return $name;
            }
        }

        // Enlace/recurso visitado: URL si la hay, si no la etiqueta de lo visitado.
        if (! empty($data['url']) && is_string($data['url'])) {
            return $data['url'];
        }
        if (! empty($data['label']) && is_string($data['label'])) {
            return $data['label'];
        }

        // Pregunta sin resolver: la pregunta textual (muy util para seguimiento).
        if (! empty($data['question']) && is_string($data['question'])) {
            return $data['question'];
        }

        // Correo enviado: el asunto.
        if (! empty($data['subject']) && is_string($data['subject'])) {
            return $data['subject'];
        }

        return null;
    }

    protected static function newFactory(): EventFactory
    {
        return EventFactory::new();
    }
}
