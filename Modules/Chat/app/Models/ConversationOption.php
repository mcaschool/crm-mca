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
 *
 * @property int $institution_id
 * @property int $node_id
 * @property string $label_es
 * @property string|null $label_en
 * @property int|null $target_node_id
 * @property string|null $action
 * @property string|null $event_type
 * @property string|null $url
 * @property int $display_order
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
        'url',
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
