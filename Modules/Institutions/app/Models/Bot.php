<?php

declare(strict_types=1);

namespace Modules\Institutions\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Core\Tenancy\Concerns\BelongsToInstitution;
use Modules\Institutions\Database\Factories\BotFactory;

/**
 * Bot: entidad configurable de primera clase (no codigo). De el cuelgan su
 * conocimiento, su arbol conversacional y su configuracion de IA, por bot_id.
 *
 * Tambien es la FICHA DE ASESOR: `assistant_name` (nombre visible) y `avatar_path`
 * (foto de perfil). Estructura pensada para reutilizarse con futuros asesores
 * (p. ej. Sofia) creando otro registro, sin tocar codigo.
 *
 * `public_key` es el token opaco que el <script> del widget lleva embebido; el
 * servidor deduce la institucion desde ahi (nunca la envia el cliente).
 *
 * @property int $institution_id
 * @property string $name
 * @property string $slug
 * @property string $assistant_name
 * @property string $type
 * @property string|null $avatar_path
 * @property string|null $landing_url
 * @property string $public_key
 * @property string $default_language
 * @property string|null $role_description función o presentación del asesor
 * @property string|null $instructions instrucciones del asesor
 * @property string|null $tone tono de comunicación
 * @property string|null $restrictions límites / asuntos que no debe responder
 * @property string|null $not_found_message mensaje para información no encontrada
 * @property string|null $handoff_rules cuándo transferir a una persona
 * @property bool $uses_legacy_prompt asesor previo a «Identidad e instrucciones» (prompt global)
 * @property string $knowledge_retrieval búsqueda en el conocimiento: classic (la de siempre) | precise
 * @property int|null $ai_message_limit respuestas de IA por conversación (null = crm.celia.message_limit)
 * @property string|null $widget_welcome_es
 * @property string|null $widget_welcome_en
 * @property string|null $widget_button_es
 * @property string|null $widget_button_en
 * @property string|null $greeting_es saludo inicial de la conversación (vacío = el de por defecto)
 * @property string|null $greeting_en
 * @property string|null $preview_token_hash
 * @property string|null $preview_token
 * @property \Illuminate\Support\Carbon|null $preview_token_created_at
 * @property string $status
 */
class Bot extends Model
{
    use BelongsToInstitution;

    /** @use HasFactory<BotFactory> */
    use HasFactory;

    protected $fillable = [
        'institution_id',
        'name',
        'slug',
        'assistant_name',
        'role_description',
        'instructions',
        'tone',
        'restrictions',
        'not_found_message',
        'handoff_rules',
        'uses_legacy_prompt',
        'knowledge_retrieval',
        'ai_message_limit',
        'type',
        'avatar_path',
        'landing_url',
        'public_key',
        'allowed_origins',
        'default_language',
        'widget_welcome_es',
        'widget_welcome_en',
        'widget_button_es',
        'widget_button_en',
        'greeting_es',
        'greeting_en',
        'status',
    ];

    /** El enlace de prueba nunca viaja en serializaciones (solo se muestra en la ficha). */
    protected $hidden = ['preview_token', 'preview_token_hash'];

    protected function casts(): array
    {
        return [
            'allowed_origins' => 'array',
            'uses_legacy_prompt' => 'boolean',
            'ai_message_limit' => 'integer',
            'preview_token' => 'encrypted',
            'preview_token_created_at' => 'datetime',
        ];
    }

    /** Saludo inicial propio de la conversación en ese idioma (null = el de por defecto). */
    public function greeting(string $locale): ?string
    {
        $text = trim((string) $this->{'greeting_'.($locale === 'en' ? 'en' : 'es')});

        return $text !== '' ? $text : null;
    }

    /** Búsqueda en el conocimiento de siempre (Celia y todo asesor que no la cambie). */
    public const RETRIEVAL_CLASSIC = 'classic';

    /** Búsqueda precisa (PreciseKnowledgeRanker): rareza, variantes, programa nombrado y tema activo. */
    public const RETRIEVAL_PRECISE = 'precise';

    /** ¿Usa la búsqueda precisa? Activa también el filtro de enlaces y el diagnóstico de la prueba. */
    public function usesPreciseRetrieval(): bool
    {
        return $this->knowledge_retrieval === self::RETRIEVAL_PRECISE;
    }

    /** Respuestas de IA por conversación: la del asesor, o la general (crm.celia.message_limit). */
    public function aiMessageLimit(): int
    {
        return $this->ai_message_limit !== null && $this->ai_message_limit > 0
            ? (int) $this->ai_message_limit
            : (int) config('crm.celia.message_limit', 15);
    }

    /** Campos de «Identidad e instrucciones» (para saber si el asesor tiene identidad propia). */
    public const IDENTITY_FIELDS = ['role_description', 'instructions', 'tone', 'restrictions', 'not_found_message', 'handoff_rules'];

    /** ¿Tiene alguna instrucción o rasgo de identidad propio? */
    public function hasOwnIdentity(): bool
    {
        foreach (self::IDENTITY_FIELDS as $field) {
            if (is_string($this->{$field}) && trim($this->{$field}) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * ¿Conversa con el prompt GLOBAL de siempre? Solo un asesor previo (Celia) que aún no tiene
     * identidad propia: así conserva exactamente su comportamiento. Un asesor nuevo, nunca.
     */
    public function usesGlobalPrompt(): bool
    {
        return (bool) $this->uses_legacy_prompt && ! $this->hasOwnIdentity();
    }

    /**
     * «Presentación del widget» configurada para este asesor: solo los textos guardados (los
     * vacíos no aparecen; el widget conserva entonces su texto por defecto).
     *
     * @return array<string, array{welcome?: string, button?: string}> idioma => textos
     */
    public function widgetTexts(): array
    {
        $texts = [];
        foreach (['es', 'en'] as $lang) {
            $set = array_filter([
                'welcome' => $this->{'widget_welcome_'.$lang},
                'button' => $this->{'widget_button_'.$lang},
            ], fn ($v): bool => is_string($v) && trim($v) !== '');
            if ($set !== []) {
                $texts[$lang] = $set;
            }
        }

        return $texts;
    }

    /**
     * Textos EFECTIVOS del widget en un idioma: los del asesor o, si no tiene, los por defecto
     * (config crm.widget.default_texts, idénticos a los de chat-widget.js).
     *
     * @return array{welcome: string, button: string}
     */
    public function effectiveWidgetTexts(string $lang): array
    {
        $lang = in_array($lang, ['es', 'en'], true) ? $lang : 'es';
        $defaults = (array) config('crm.widget.default_texts.'.$lang, []);
        $own = $this->widgetTexts()[$lang] ?? [];

        return [
            'welcome' => (string) ($own['welcome'] ?? $defaults['welcome'] ?? ''),
            'button' => (string) ($own['button'] ?? $defaults['button'] ?? ''),
        ];
    }

    /** ¿Es un asesor de IA (el que opera hoy)? 'human' queda como etiqueta futura. */
    public function isAi(): bool
    {
        return $this->type !== 'human';
    }

    /**
     * Fuentes de conocimiento ASIGNADAS al asesor (Centro de Conocimiento, N:N vía pivote
     * bot_knowledge_source). El pivote lleva is_active para activar/desactivar por bot.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany<\Modules\Ai\Models\KnowledgeSource, $this>
     */
    public function knowledgeSources(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(\Modules\Ai\Models\KnowledgeSource::class, 'bot_knowledge_source')
            ->withPivot('is_active')
            ->withTimestamps();
    }

    /**
     * Programas del catálogo que el asesor PUEDE RECOMENDAR (Centro de Conocimiento, N:N vía
     * bot_program). Los decide el administrador; el emparejador solo usa estos.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany<\Modules\Catalog\Models\Program, $this>
     */
    public function programs(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(\Modules\Catalog\Models\Program::class, 'bot_program')->withTimestamps();
    }

    /**
     * Fuentes creadas por/atribuidas a este bot por la columna legada bot_id (compatibilidad
     * con la UI actual, Ajuste 2). La lógica nueva usa knowledgeSources() (pivote).
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<\Modules\Ai\Models\KnowledgeSource, $this>
     */
    public function ownedKnowledgeSources(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(\Modules\Ai\Models\KnowledgeSource::class);
    }

    /**
     * Carpeta estable del asesor (para avatar y conocimiento), derivada del slug.
     * Al crear otro asesor (Sofia) cada uno tiene su propia carpeta aislada.
     */
    public function advisorFolder(): string
    {
        return Str::slug($this->slug !== '' ? $this->slug : (string) $this->getKey());
    }

    /** URL publica del avatar (o null si no hay foto: el widget usa el icono por defecto). */
    public function avatarUrl(): ?string
    {
        if ($this->avatar_path === null || $this->avatar_path === '') {
            return null;
        }

        return Storage::disk('public')->url($this->avatar_path);
    }

    protected static function newFactory(): BotFactory
    {
        return BotFactory::new();
    }
}
