<?php

declare(strict_types=1);

namespace Modules\Crm\Livewire\Agenda\Concerns;

use App\Models\User;
use Livewire\Attributes\Locked;
use Modules\Crm\Models\Activity;
use Modules\Crm\Policies\ActivityPolicy;
use Modules\Crm\Services\Agenda\AgendaDirectory;

/**
 * ¿De quién es el perfil/disponibilidad que se edita? Cada usuario operativo edita el suyo;
 * solo la administración puede editar el de otra persona de SU institución.
 */
trait ResolvesAgendaOwner
{
    #[Locked]
    public int $ownerId = 0;

    protected function resolveOwner(): void
    {
        $actor = auth()->user();
        $this->authorize('create', Activity::class);

        $requested = request()->query('user');
        $this->ownerId = $actor->id;
        if (is_string($requested) && ctype_digit($requested) && (int) $requested !== $actor->id) {
            abort_unless((new ActivityPolicy)->manageOthers($actor), 403);
            abort_unless($this->assignable()->contains('id', (int) $requested), 404);
            $this->ownerId = (int) $requested;
        }
    }

    /** Re-autoriza en cada acción (el id viaja por el cliente). */
    protected function guardOwner(): void
    {
        $actor = auth()->user();
        $this->authorize('create', Activity::class);
        if ($this->ownerId !== $actor->id) {
            abort_unless((new ActivityPolicy)->manageOthers($actor), 403);
            abort_unless($this->assignable()->contains('id', $this->ownerId), 404);
        }
    }

    /** @return \Illuminate\Support\Collection<int,User> */
    protected function assignable(): \Illuminate\Support\Collection
    {
        return app(AgendaDirectory::class)->assignableUsers();
    }
}
