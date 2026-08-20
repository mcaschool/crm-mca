<?php

declare(strict_types=1);

namespace Modules\Notifications\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Tenancy\Concerns\BelongsToInstitution;
use Modules\Notifications\Database\Factories\EmailTemplateFactory;

/**
 * Plantilla de correo reutilizable: nombre + asunto + cuerpo (HTML del editor, con
 * etiquetas dinámicas que se resuelven al enviar). Dos tipos según `user_id`:
 *   - COMPARTIDA (user_id = null): del equipo. Solo el Administrador la gestiona; la
 *     usa cualquiera que pueda enviar correo.
 *   - PROPIA     (user_id = X): privada de su dueño; solo él la ve y gestiona.
 * Siempre acotada por institución (motor multi-institución dormante).
 *
 * @property int $institution_id
 * @property int|null $user_id
 * @property string $name
 * @property string $subject
 * @property string $body
 * @property string $status
 */
class EmailTemplate extends Model
{
    use BelongsToInstitution;

    /** @use HasFactory<EmailTemplateFactory> */
    use HasFactory;

    protected $fillable = [
        'institution_id',
        'user_id',
        'name',
        'subject',
        'body',
        'status',
    ];

    /** ¿Es compartida (del equipo)? Las compartidas no tienen dueño. */
    public function isShared(): bool
    {
        return $this->user_id === null;
    }

    /**
     * Compartidas: sin dueño, visibles/usables por todo el equipo.
     *
     * @param  Builder<EmailTemplate>  $query
     * @return Builder<EmailTemplate>
     */
    public function scopeShared(Builder $query): Builder
    {
        return $query->whereNull('user_id');
    }

    /**
     * Propias de un usuario concreto (privadas).
     *
     * @param  Builder<EmailTemplate>  $query
     * @return Builder<EmailTemplate>
     */
    public function scopeOwnedBy(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Disponibles para redactar (usar) para un usuario: las compartidas + las propias
     * de ese usuario. Nunca las propias de otro.
     *
     * @param  Builder<EmailTemplate>  $query
     * @return Builder<EmailTemplate>
     */
    public function scopeUsableBy(Builder $query, int $userId): Builder
    {
        return $query->where(fn (Builder $q) => $q->whereNull('user_id')->orWhere('user_id', $userId));
    }

    /**
     * @return HasMany<EmailTemplateImage, $this>
     */
    public function images(): HasMany
    {
        return $this->hasMany(EmailTemplateImage::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    protected static function newFactory(): EmailTemplateFactory
    {
        return EmailTemplateFactory::new();
    }
}
