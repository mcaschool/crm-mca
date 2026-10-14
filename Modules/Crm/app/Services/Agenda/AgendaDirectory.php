<?php

declare(strict_types=1);

namespace Modules\Crm\Services\Agenda;

use App\Models\User;
use Illuminate\Support\Collection;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Crm\Models\MeetingProfile;
use Modules\Crm\Support\UserTimezone;

/**
 * Usuarios de la institución activa que pueden tener agenda (operativos, no de solo
 * lectura), y la zona horaria de presentación de cada usuario. Sin nombres ni
 * departamentos fijos: se decide por persona.
 */
class AgendaDirectory
{
    public function __construct(private readonly CurrentInstitution $context) {}

    /** @return Collection<int,User> */
    public function assignableUsers(): Collection
    {
        return User::query()
            ->where('institution_id', $this->context->idOrFail('agenda'))
            ->where('status', 'active')
            ->orderBy('name')
            ->get()
            ->filter(fn (User $u) => $u->canWorkCrm() && ! $u->crmReadOnly())
            ->values();
    }

    /**
     * Usuarios que pueden tener una sala permanente de videoconferencia: todos los ACTIVOS de la
     * institución con acceso al módulo (el Calendario), no solo quienes reciben actividades.
     *
     * @return Collection<int,User>
     */
    public function roomUsers(): Collection
    {
        return User::query()
            ->where('institution_id', $this->context->idOrFail('videoconferencias'))
            ->where('status', 'active')
            ->orderBy('name')
            ->get()
            ->filter(fn (User $u) => $u->canWorkCrm())
            ->values();
    }

    /**
     * Zona de presentación de un usuario: la MISMA regla que el resto del panel (UserTimezone:
     * perfil de reuniones → institución → agenda → aplicación). Antes, sin perfil, el Calendario
     * caía en la zona por defecto de la agenda (UTC) mientras la Bandeja y las fichas usaban la de
     * la institución, y una misma persona veía dos horas distintas.
     */
    public function timezoneFor(int $userId): string
    {
        $profile = MeetingProfile::query()->where('user_id', $userId)->value('timezone');
        if (UserTimezone::isValid($profile)) {
            return $profile;
        }

        return UserTimezone::for(User::query()->find($userId));
    }
}
