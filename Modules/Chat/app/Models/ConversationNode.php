<?php

declare(strict_types=1);

namespace Modules\Chat\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Chat\Database\Factories\ConversationNodeFactory;
use Modules\Core\Concerns\HasTranslatedColumns;
use Modules\Core\Tenancy\Concerns\BelongsToInstitution;

/**
 * Nodo del arbol conversacional (constructor). Contenido bilingue _es/_en.
 * `key` es el identificador estable que usa el codigo (welcome, main_menu...).
 * `config` guarda parametros del nodo (filtros de program_list, campos de form).
 */
class ConversationNode extends Model
{
    use BelongsToInstitution;

    /** @use HasFactory<ConversationNodeFactory> */
    use HasFactory;

    use HasTranslatedColumns;

    /** @var array<int,string> */
    protected array $translatable = ['content'];

    protected $fillable = [
        'institution_id',
        'bot_id',
        'key',
        'type',
        'content_es',
        'content_en',
        'config',
        'display_order',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'config' => 'array',
        ];
    }

    /**
     * @return HasMany<ConversationOption, $this>
     */
    public function options(): HasMany
    {
        return $this->hasMany(ConversationOption::class, 'node_id');
    }

    protected static function newFactory(): ConversationNodeFactory
    {
        return ConversationNodeFactory::new();
    }
}
