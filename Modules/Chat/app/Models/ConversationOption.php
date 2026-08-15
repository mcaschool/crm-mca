<?php

declare(strict_types=1);

namespace Modules\Chat\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Chat\Database\Factories\ConversationOptionFactory;
use Modules\Core\Concerns\HasTranslatedColumns;
use Modules\Core\Tenancy\Concerns\BelongsToInstitution;

/**
 * Opcion de un nodo (boton). Etiqueta bilingue _es/_en. Lleva institution_id
 * aunque cuelgue del nodo: aislamiento universal.
 */
class ConversationOption extends Model
{
    use BelongsToInstitution;

    /** @use HasFactory<ConversationOptionFactory> */
    use HasFactory;

    use HasTranslatedColumns;

    /** @var array<int,string> */
    protected array $translatable = ['label'];

    protected $fillable = [
        'institution_id',
        'node_id',
        'label_es',
        'label_en',
        'target_node_id',
        'action',
        'event_type',
        'display_order',
    ];

    /**
     * @return BelongsTo<ConversationNode, $this>
     */
    public function node(): BelongsTo
    {
        return $this->belongsTo(ConversationNode::class, 'node_id');
    }

    protected static function newFactory(): ConversationOptionFactory
    {
        return ConversationOptionFactory::new();
    }
}
