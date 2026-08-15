<?php

declare(strict_types=1);

namespace Modules\Crm\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Tenancy\Concerns\BelongsToInstitution;
use Modules\Crm\Database\Factories\ContactFactory;

/**
 * Contacto (prospecto). Identidad unica por (institution_id, email) — invariante.
 * Captura minima inicial: nombre + correo. Consentimiento RGPD desde el dia 1 (D2).
 *
 * @property int $institution_id
 * @property string $first_name
 * @property string|null $last_name
 * @property string $email
 * @property string|null $phone
 * @property string|null $country
 * @property string $preferred_language
 * @property \Illuminate\Support\Carbon|null $consent_at
 * @property string|null $consent_source
 * @property \Illuminate\Support\Carbon|null $unsubscribed_at
 */
class Contact extends Model
{
    use BelongsToInstitution;

    /** @use HasFactory<ContactFactory> */
    use HasFactory;

    protected $fillable = [
        'institution_id',
        'first_name',
        'last_name',
        'email',
        'phone',
        'country',
        'preferred_language',
        'consent_at',
        'consent_source',
        'unsubscribed_at',
    ];

    protected function casts(): array
    {
        return [
            'consent_at' => 'datetime',
            'unsubscribed_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<Lead, $this>
     */
    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class);
    }

    /**
     * @return HasMany<Conversation, $this>
     */
    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    /**
     * @return HasMany<Event, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }

    /**
     * @return HasMany<ProgramInterest, $this>
     */
    public function programInterests(): HasMany
    {
        return $this->hasMany(ProgramInterest::class);
    }

    public function fullName(): string
    {
        return trim($this->first_name.' '.(string) $this->last_name);
    }

    protected static function newFactory(): ContactFactory
    {
        return ContactFactory::new();
    }
}
