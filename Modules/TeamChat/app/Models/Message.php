<?php

declare(strict_types=1);

namespace Modules\TeamChat\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Tenancy\Concerns\BelongsToInstitution;

/** Writes go through ChatCommands to preserve authorization, sequence and outbox atomicity. */
final class Message extends Model
{
    use BelongsToInstitution;

    protected $table = 'collab_chat_messages';

    protected $guarded = ['*'];

    public $timestamps = false;

    public $incrementing = false;

    protected $keyType = 'string';
}
