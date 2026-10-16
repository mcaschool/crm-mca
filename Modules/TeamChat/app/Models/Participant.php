<?php

declare(strict_types=1);

namespace Modules\TeamChat\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Tenancy\Concerns\BelongsToInstitution;

/** Writes go through ChatCommands to preserve authorization, sequence and outbox atomicity. */
final class Participant extends Model
{
    use BelongsToInstitution;

    protected $table = 'collab_chat_participants';

    protected $guarded = ['*'];

    public $timestamps = true;
}
