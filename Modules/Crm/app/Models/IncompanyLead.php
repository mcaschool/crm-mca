<?php

declare(strict_types=1);

namespace Modules\Crm\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Catalog\Models\Program;
use Modules\Core\Tenancy\Concerns\BelongsToInstitution;

/**
 * Perfil InCompany (formación corporativa) de un lead. Tabla plana 1:1 con el lead:
 * empresa, calificación, diagnóstico y la ruta de hasta 3 programas. Lo crea el
 * endpoint público InCompany a partir de lo que arma n8n.
 *
 * @property int $institution_id
 * @property int $lead_id
 * @property string $nombre_empresa
 * @property string $nombre_contacto
 * @property string $email
 * @property string|null $whatsapp
 * @property string|null $sector
 * @property string|null $tamano_empresa
 * @property string|null $modalidad
 * @property int $cantidad_personas
 * @property string|null $programa_1_code
 * @property int|null $programa_1_program_id
 * @property string|null $programa_2_code
 * @property int|null $programa_2_program_id
 * @property string|null $programa_3_code
 * @property int|null $programa_3_program_id
 * @property string|null $area_desarrollo
 * @property string $origen
 */
class IncompanyLead extends Model
{
    use BelongsToInstitution;

    protected $fillable = [
        'institution_id',
        'lead_id',
        'nombre_empresa',
        'nombre_contacto',
        'email',
        'whatsapp',
        'sector',
        'tamano_empresa',
        'modalidad',
        'cantidad_personas',
        'programa_1_code',
        'programa_1_program_id',
        'programa_2_code',
        'programa_2_program_id',
        'programa_3_code',
        'programa_3_program_id',
        'area_desarrollo',
        'origen',
    ];

    protected function casts(): array
    {
        return [
            'cantidad_personas' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Lead, $this>
     */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class, 'lead_id');
    }

    /**
     * @return BelongsTo<Program, $this>
     */
    public function programa1(): BelongsTo
    {
        return $this->belongsTo(Program::class, 'programa_1_program_id');
    }

    /**
     * @return BelongsTo<Program, $this>
     */
    public function programa2(): BelongsTo
    {
        return $this->belongsTo(Program::class, 'programa_2_program_id');
    }

    /**
     * @return BelongsTo<Program, $this>
     */
    public function programa3(): BelongsTo
    {
        return $this->belongsTo(Program::class, 'programa_3_program_id');
    }

    /**
     * Ruta de programas EN ORDEN (1→3), solo los presentes. Cada entrada trae el code
     * tal como llegó, el programa del catálogo (si enlazó) y una marca `linked`. Los
     * no enlazados se muestran con su code para poder detectarlos.
     *
     * @return array<int, array{position: int, code: string, program: ?Program, linked: bool}>
     */
    public function programRoute(): array
    {
        $out = [];
        foreach ([1, 2, 3] as $i) {
            $code = trim((string) $this->{'programa_'.$i.'_code'});
            if ($code === '') {
                continue;
            }
            /** @var Program|null $program */
            $program = $this->{'programa'.$i};
            $out[] = [
                'position' => $i,
                'code' => $code,
                'program' => $program,
                'linked' => $program !== null,
            ];
        }

        return $out;
    }
}
