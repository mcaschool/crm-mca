<?php

declare(strict_types=1);

namespace Modules\TeamCalls\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Modules\Core\Tenancy\Concerns\BelongsToInstitution;

/**
 * Traza mínima de una llamada (tipo con la nomenclatura de eventos acordada: call.*). Sin contenido
 * de la llamada ni datos personales: solo ids y metadatos técnicos.
 *
 * @property string $id ULID
 * @property int $institution_id
 * @property string $call_id
 * @property string $type
 * @property int|null $actor_id
 * @property int|null $subject_id
 * @property array<string,mixed>|null $data
 * @property string|null $dedupe_key
 * @property Carbon $created_at
 */
class CallEvent extends Model
{
    use BelongsToInstitution;
    use HasUlids;

    public const UPDATED_AT = null;

    protected $table = 'collab_call_events';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['data' => 'array', 'created_at' => 'datetime'];
    }
}
