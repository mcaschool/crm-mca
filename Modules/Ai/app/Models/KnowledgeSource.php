<?php

declare(strict_types=1);

namespace Modules\Ai\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Modules\Ai\Database\Factories\KnowledgeSourceFactory;
use Modules\Core\Concerns\HasTranslatedColumns;
use Modules\Core\Tenancy\Concerns\BelongsToInstitution;

/**
 * Fuente de conocimiento de Celia (retrieval Forma A: cuerpo pequeno y estable
 * que se entrega compacto al modelo). Contenido bilingue por columnas _es/_en.
 *
 * @property int $institution_id
 * @property int $bot_id
 * @property string $name
 * @property string $code
 * @property string|null $source_file
 * @property string $type
 * @property string|null $category
 * @property int|null $program_id
 * @property string|null $url
 * @property string|null $content_es
 * @property string|null $content_en
 * @property int $priority
 * @property string $status
 * @property \Illuminate\Support\Carbon|null $last_synced_at
 */
class KnowledgeSource extends Model
{
    use BelongsToInstitution;

    /** @use HasFactory<KnowledgeSourceFactory> */
    use HasFactory;

    use HasTranslatedColumns;

    /** @var array<int,string> */
    protected array $translatable = ['content'];

    protected $fillable = [
        'institution_id',
        'bot_id',
        'name',
        'code',
        'source_file',
        'type',
        'category',
        'program_id',
        'url',
        'content_es',
        'content_en',
        'priority',
        'status',
        'last_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'last_synced_at' => 'datetime',
        ];
    }

    /**
     * Bots a los que está asignada esta fuente (biblioteca central compartida). El pivote
     * lleva is_active para activar/desactivar la fuente por bot sin desasignarla.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany<\Modules\Institutions\Models\Bot, $this>
     */
    public function bots(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(\Modules\Institutions\Models\Bot::class, 'bot_knowledge_source')
            ->withPivot('is_active')
            ->withTimestamps();
    }

    protected static function newFactory(): KnowledgeSourceFactory
    {
        return KnowledgeSourceFactory::new();
    }
}
