<?php

declare(strict_types=1);

namespace Modules\Notifications\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Tenancy\Concerns\BelongsToInstitution;

/**
 * Metadatos de un adjunto enviado (nombre, tipo, tamaño), para el historial.
 *
 * @property int $institution_id
 * @property int $email_message_id
 * @property string $disposition
 * @property string|null $content_id
 * @property string $filename
 * @property string $mime
 * @property int $size
 * @property string|null $path
 */
class EmailAttachment extends Model
{
    use BelongsToInstitution;

    public const UPDATED_AT = null;

    protected $fillable = [
        'institution_id',
        'email_message_id',
        'disposition',
        'content_id',
        'filename',
        'mime',
        'size',
        'path',
    ];

    public function isInline(): bool
    {
        return $this->disposition === 'inline';
    }

    protected function casts(): array
    {
        return [
            'size' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<EmailMessage, $this>
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(EmailMessage::class, 'email_message_id');
    }
}
