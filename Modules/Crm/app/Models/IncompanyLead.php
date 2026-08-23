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
 * @property string $stage
 * @property \Illuminate\Support\Carbon|null $diagnostico_at
 * @property \Illuminate\Support\Carbon|null $solicita_contacto_at
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
        'stage',
        'diagnostico_at',
        'solicita_contacto_at',
        'origen',
    ];

    /** Estados del embudo InCompany (orden: cuanto mayor, más caliente). */
    public const STAGE_DIAGNOSTICO = 'diagnostico';

    public const STAGE_SOLICITA_CONTACTO = 'solicita_contacto';

    /** Rango de cada stage para no degradar nunca en el upsert. */
    private const STAGE_RANK = [
        self::STAGE_DIAGNOSTICO => 1,
        self::STAGE_SOLICITA_CONTACTO => 2,
    ];

    protected function casts(): array
    {
        return [
            'cantidad_personas' => 'integer',
            'diagnostico_at' => 'datetime',
            'solicita_contacto_at' => 'datetime',
        ];
    }

    /** Rango numérico de un stage (0 si desconocido); mayor = más avanzado. */
    public static function stageRank(?string $stage): int
    {
        return self::STAGE_RANK[$stage] ?? 0;
    }

    /** ¿Este lead ya pidió contacto? (el más caliente). */
    public function hasRequestedContact(): bool
    {
        return $this->stage === self::STAGE_SOLICITA_CONTACTO;
    }

    /** Etiqueta legible del stage para la ficha. */
    public function stageLabel(): string
    {
        return match ($this->stage) {
            self::STAGE_SOLICITA_CONTACTO => 'Solicitó contacto',
            self::STAGE_DIAGNOSTICO => 'Diagnóstico',
            default => $this->stage,
        };
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
     * tal como llegó, el programa del catálogo (si enlaza) y una marca `linked`. Los
     * no enlazados se muestran con su code para poder detectarlos.
     *
     * El enlace se RESUELVE EN VIVO: primero el FK guardado en la ingesta y, si está
     * vacío, una búsqueda por code/course_idnumber sobre el catálogo actual. Así los
     * leads ingresados por versiones viejas (que guardaron program_id nulo porque solo
     * comparaban course_idnumber) muestran el NOMBRE igual, sin re-procesarlos.
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
            $program = $this->{'programa'.$i} ?? Program::findByCourseIdnumberOrCode($code);
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
