<?php

declare(strict_types=1);

namespace Modules\TeamChat\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Tenancy\Concerns\BelongsToInstitution;

/** Writes go through ChatCommands to preserve authorization, sequence and outbox atomicity. */
final class Conversation extends Model
{
    use BelongsToInstitution;

    protected $table = 'collab_chat_conversations';

    protected $guarded = ['*'];

    public $timestamps = true;

    public $incrementing = false;

    protected $keyType = 'string';
}
