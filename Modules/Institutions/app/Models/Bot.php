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
        'type',
        'avatar_path',
        'landing_url',
        'public_key',
        'allowed_origins',
        'default_language',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'allowed_origins' => 'array',
        ];
    }

    /** ¿Es un asesor de IA (el que opera hoy)? 'human' queda como etiqueta futura. */
    public function isAi(): bool
    {
        return $this->type !== 'human';
    }

    /**
     * Fuentes de conocimiento del asesor (por bot_id).
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<\Modules\Ai\Models\KnowledgeSource, $this>
     */
    public function knowledgeSources(): \Illuminate\Database\Eloquent\Relations\HasMany
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
