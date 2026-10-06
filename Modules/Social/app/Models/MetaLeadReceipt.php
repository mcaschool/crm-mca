<?php

declare(strict_types=1);

namespace Modules\Social\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Tenancy\Concerns\BelongsToInstitution;

/**
 * Registro de cada contacto recibido de un formulario publicitario (idempotencia por el id de
 * Meta, estado y error legible). Sin datos personales: viven en el contacto/lead creado.
 *
 * @property int $institution_id
 * @property string $leadgen_id
 * @property string|null $form_id
 * @property string|null $page_id
 * @property string $status skipped | processed | failed
 * @property string|null $error
 * @property int|null $lead_id
 * @property array<string, mixed>|null $attribution
 */
class MetaLeadReceipt extends Model
{
    use BelongsToInstitution;

    public const STATUS_LABELS = [
        'processed' => 'Registrado en el CRM',
        'skipped' => 'Omitido',
        'failed' => 'No se pudo registrar',
    ];

    protected $fillable = [
        'institution_id', 'leadgen_id', 'form_id', 'page_id', 'status', 'error', 'lead_id', 'attribution',
    ];

    protected function casts(): array
    {
        return ['attribution' => 'array'];
    }
}
