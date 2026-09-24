<?php

declare(strict_types=1);

namespace Modules\Ai\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Tenancy\Concerns\BelongsToInstitution;

/**
 * Evento de uso de IA (telemetría global, append-only). Fuente única para métricas,
 * coste/caché y auditoría de llamadas. NUNCA contiene prompts, respuestas ni secretos.
 *
 * @property int $institution_id
 * @property string $process
 * @property int|null $agent_id
 * @property int|null $bot_id
 * @property int $integration_id
 * @property string $provider
 * @property string $model
 * @property int $input_tokens
 * @property int $cached_input_tokens
 * @property int $uncached_input_tokens
 * @property int $output_tokens
 * @property int $reasoning_tokens
 * @property int $latency_ms
 * @property string $status
 * @property string|null $error_category
 */
class AiUsageEvent extends Model
{
    use BelongsToInstitution;

    public $timestamps = false; // append-only: solo created_at (se fija al insertar)

    protected $fillable = [
        'institution_id',
        'process',
        'agent_id',
        'bot_id',
        'integration_id',
        'provider',
        'model',
        'input_tokens',
        'cached_input_tokens',
        'uncached_input_tokens',
        'output_tokens',
        'reasoning_tokens',
        'latency_ms',
        'status',
        'error_category',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'input_tokens' => 'integer',
            'cached_input_tokens' => 'integer',
            'uncached_input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'reasoning_tokens' => 'integer',
            'latency_ms' => 'integer',
            'created_at' => 'datetime',
        ];
    }
}
