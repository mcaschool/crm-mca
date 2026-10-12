<?php

declare(strict_types=1);

namespace Modules\Crm\Services\Agenda;

use App\Models\User;
use Illuminate\Support\Collection;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Crm\Models\MeetingProfile;

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

    public function timezoneFor(int $userId): string
    {
        return MeetingProfile::forUser($userId)->timezone ?: 'UTC';
    }
}
