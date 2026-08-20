<?php

declare(strict_types=1);

namespace Modules\Notifications\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Tenancy\Concerns\BelongsToInstitution;

/**
 * Imagen inline persistida de una plantilla. El cuerpo la referencia por `content_id`
 * (<img data-cid="…">) y el archivo vive en el disco privado. Se rehidrata al pipeline
 * de embebido por CID al cargar la plantilla en el compositor.
 *
 * @property int $institution_id
 * @property int $email_template_id
 * @property string $content_id
 * @property string $mime
 * @property int $size
 * @property string|null $path
 */
class EmailTemplateImage extends Model
{
    use BelongsToInstitution;

    protected $fillable = [
        'institution_id',
        'email_template_id',
        'content_id',
        'mime',
        'size',
        'path',
    ];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<EmailTemplate, $this>
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(EmailTemplate::class, 'email_template_id');
    }
}
