<?php

declare(strict_types=1);

namespace Modules\Crm\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Tenancy\Concerns\BelongsToInstitution;

/**
 * Plantilla personalizada de un correo del Calendario (por institución, clave e idioma). Sin fila,
 * se usa el texto predeterminado. Texto plano con variables autorizadas: nunca HTML ni código.
 *
 * @property int $id
 * @property int $institution_id
 * @property string $key
 * @property string $locale
 * @property string $subject
 * @property string $body
 * @property int|null $updated_by_user_id
 */
class CalendarTemplate extends Model
{
    use BelongsToInstitution;

    protected $table = 'crm_calendar_templates';

    protected $fillable = ['institution_id', 'key', 'locale', 'subject', 'body', 'updated_by_user_id'];
}
