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

    protected static function newFactory(): ContactFactory
    {
        return ContactFactory::new();
    }
}
