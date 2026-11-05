<?php

declare(strict_types=1);

namespace Modules\FollowUp\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Tenancy\Concerns\BelongsToInstitution;

/**
 * @property array<string,mixed> $data
 * @property \Illuminate\Support\Carbon|null $occurred_at
 * @property \Illuminate\Support\Carbon|null $eligible_at
 * @property \Illuminate\Support\Carbon|null $response_at
 * @property string $source_key
 * @property string|null $subject
 * @property int|null $opportunity_id
 * @property int|null $action_id
 */
final class AnalyticsFact extends Model
{
    use BelongsToInstitution;

    protected $table = 'follow_up_analytics_facts';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['data' => 'array', 'occurred_at' => 'datetime', 'eligible_at' => 'datetime', 'response_at' => 'datetime'];
    }
}
