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

    protected static function newFactory(): KnowledgeSourceFactory
    {
        return KnowledgeSourceFactory::new();
    }
}
