<?php

declare(strict_types=1);

namespace Modules\Social\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Tenancy\Concerns\BelongsToInstitution;

/**
 * Autorización de Meta de UNA empresa («Conectar Meta»). El token vive cifrado y nunca sale al
 * navegador ni a los registros. Reconectar solo la sustituye si la nueva autorización funciona.
 *
 * @property int $institution_id
 * @property string $token
 * @property string|null $token_type
 * @property string|null $meta_user_id
 * @property array<int, string>|null $scopes
 * @property \Illuminate\Support\Carbon|null $expires_at
 * @property \Illuminate\Support\Carbon|null $data_access_expires_at
 * @property string $status active | expired | invalid | disconnected
 * @property string|null $last_error
 * @property \Illuminate\Support\Carbon|null $last_checked_at
 * @property int|null $connected_by
 * @property \Illuminate\Support\Carbon|null $connected_at
 */
class MetaConnection extends Model
{
    use BelongsToInstitution;

    /** Avisar para renovar con esta antelación (días). */
    public const RENEW_WARNING_DAYS = 10;

    protected $fillable = [
        'institution_id', 'token', 'token_type', 'meta_user_id', 'scopes', 'expires_at', 'data_access_expires_at',
        'status', 'last_error', 'last_checked_at', 'connected_by', 'connected_at',
    ];

    protected $hidden = ['token'];

    protected function casts(): array
    {
        return [
            'token' => 'encrypted',
            'scopes' => 'array',
            'expires_at' => 'datetime',
            'data_access_expires_at' => 'datetime',
            'last_checked_at' => 'datetime',
            'connected_at' => 'datetime',
        ];
    }

    /** @return HasMany<MetaLeadPage, $this> */
    public function pages(): HasMany
    {
        return $this->hasMany(MetaLeadPage::class);
    }

    /** @return BelongsTo<User, $this> */
    public function connectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'connected_by');
    }

    /** ¿Se puede usar ahora mismo? (no caducada ni invalidada) */
    public function usable(): bool
    {
        if (in_array($this->status, ['expired', 'invalid', 'disconnected'], true) || $this->token === '') {
            return false;
        }

        return ($this->expires_at === null || $this->expires_at->isFuture())
            && ($this->data_access_expires_at === null || $this->data_access_expires_at->isFuture());
    }

    /** Fecha a partir de la cual hay que volver a conectar (la más próxima de las dos caducidades). */
    public function renewBy(): ?\Illuminate\Support\Carbon
    {
        $dates = array_filter([$this->expires_at, $this->data_access_expires_at]);

        return $dates === [] ? null : min($dates);
    }

    /** Estado efectivo para la pantalla: active | expiring | expired | invalid | disconnected. */
    public function effectiveStatus(): string
    {
        if (! $this->usable()) {
            return in_array($this->status, ['invalid', 'disconnected'], true) ? $this->status : 'expired';
        }
        $renew = $this->renewBy();

        return $renew !== null && $renew->lt(now()->addDays(self::RENEW_WARNING_DAYS)) ? 'expiring' : 'active';
    }

    public function hasScope(string $scope): ?bool
    {
        return $this->scopes === null ? null : in_array($scope, $this->scopes, true);
    }
}
