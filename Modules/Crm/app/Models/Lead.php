<?php

declare(strict_types=1);

namespace Modules\Crm\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Catalog\Models\Program;
use Modules\Core\Tenancy\Concerns\BelongsToInstitution;
use Modules\Crm\Database\Factories\LeadFactory;
use Modules\Crm\Enums\InterestLevel;
use Modules\Crm\Enums\LeadStatus;
use Modules\Institutions\Models\Bot;

/**
 * Lead: consolida la senal comercial. Un lead por conversacion con intencion
 * (D4): uno nuevo tras N dias de inactividad o cambio de producto de interes.
 *
 * @property int $institution_id
 * @property int $contact_id
 * @property int $bot_id
 * @property string $product_type
 * @property int|null $program_id
 * @property string|null $area
 * @property string|null $goal
 * @property string|null $level
 * @property string|null $source
 * @property LeadStatus $status
 * @property InterestLevel $interest_level
 * @property int|null $assigned_to_user_id
 * @property string|null $assigned_to_department
 * @property string|null $notes
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
class Lead extends Model
{
    use BelongsToInstitution;

    /** @use HasFactory<LeadFactory> */
    use HasFactory;

    protected $fillable = [
        'institution_id',
        'contact_id',
        'bot_id',
        'product_type',
        'program_id',
        'area',
        'goal',
        'level',
        'source',
        'status',
        'interest_level',
        'assigned_to_user_id',
        'assigned_to_department',
        'notes',
    ];

    protected $attributes = [
        'product_type' => 'microcredential',
        'status' => 'new',
        'interest_level' => 'low',
    ];

    protected function casts(): array
    {
        return [
            'status' => LeadStatus::class,
            'interest_level' => InterestLevel::class,
        ];
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * ¿Es un lead de la categoria propia CORPORATIVO / InCompany? Marca de primer
     * nivel (columna `area`), al mismo nivel que las 5 areas academicas.
     */
    public function isCorporate(): bool
    {
        return $this->area !== null
            && $this->area === (string) config('crm.lead.corporate_area', 'Corporativo');
    }

    /**
     * Motivo/disparador legible por el que el contacto se convirtio en lead
     * (regla de conversion). Null si no hay `source`. Bilingue; los codigos no
     * mapeados se humanizan.
     */
    public function sourceLabel(?string $locale = null): ?string
    {
        if ($this->source === null || $this->source === '') {
            return null;
        }

        $key = 'lead_labels.source.'.$this->source;
        if (trans()->has($key, $locale)) {
            return (string) trans($key, [], $locale);
        }

        return \Illuminate\Support\Str::of($this->source)->replace(['_', '-'], ' ')->ucfirst()->toString();
    }

    /**
     * Quién CAPTÓ el lead, según la fuente real (no el bot al que se atribuye por esquema).
     * Los leads del Recomendador InCompany (source=incompany_web) llegan por formulario/n8n
     * y NUNCA hablaron con un bot: su origen es el "Recomendador InCompany". El resto se
     * atribuye al asesor (bot) que de verdad lo captó (p. ej. una conversación con Celia).
     */
    public function capturedByLabel(): string
    {
        if ($this->source === 'incompany_web' || $this->product_type === 'incompany') {
            return 'Recomendador InCompany';
        }

        return $this->bot->assistant_name ?? '—';
    }

    /**
     * @return BelongsTo<Program, $this>
     */
    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    /**
     * Perfil InCompany (formación corporativa) asociado, si el lead entró por el
     * endpoint InCompany. Null en un lead normal.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasOne<IncompanyLead, $this>
     */
    public function incompany(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(IncompanyLead::class, 'lead_id');
    }

    /**
     * @return BelongsTo<Bot, $this>
     */
    public function bot(): BelongsTo
    {
        return $this->belongsTo(Bot::class);
    }

    /**
     * @return BelongsTo<\App\Models\User, $this>
     */
    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'assigned_to_user_id');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<LeadNote, $this>
     */
    public function leadNotes(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(LeadNote::class)->latest();
    }

    protected static function newFactory(): LeadFactory
    {
        return LeadFactory::new();
    }
}
