<?php

declare(strict_types=1);

namespace Modules\Notifications\Policies;

use App\Models\User;
use Modules\Notifications\Models\EmailTemplate;

/**
 * Permisos ESTRICTOS de las plantillas de correo (definidos explícitamente):
 *
 *   COMPARTIDAS (del equipo, user_id = null):
 *     - Crear / editar / borrar: SOLO Administrador (canManageSettings).
 *     - Ver / usar: cualquiera que pueda enviar correo (canSendEmail).
 *
 *   PROPIAS (privadas, user_id = usuario):
 *     - CRUD completo: SOLO su dueño. Nadie más la ve.
 *
 * La institución la acota el scope global del modelo; además, defensa en profundidad:
 * el actor y la plantilla deben ser de la misma institución.
 */
class EmailTemplatePolicy
{
    /** Puede entrar al repositorio (para usar/gestionar sus plantillas). */
    public function viewAny(User $actor): bool
    {
        return $actor->canSendEmail();
    }

    /** Ver/usar una plantilla concreta: compartida (cualquiera que envía) o la propia. */
    public function view(User $actor, EmailTemplate $template): bool
    {
        if (! $this->sameInstitution($actor, $template)) {
            return false;
        }

        return $template->isShared()
            ? $actor->canSendEmail()
            : $template->user_id === $actor->getKey();
    }

    /** Crear una plantilla COMPARTIDA (del equipo): solo Administrador. */
    public function createShared(User $actor): bool
    {
        return $actor->canManageSettings();
    }

    /** Crear una plantilla PROPIA (privada): cualquiera que pueda enviar correo. */
    public function createOwn(User $actor): bool
    {
        return $actor->canSendEmail();
    }

    /** Editar: compartida → solo Admin; propia → solo su dueño. */
    public function update(User $actor, EmailTemplate $template): bool
    {
        if (! $this->sameInstitution($actor, $template)) {
            return false;
        }

        return $template->isShared()
            ? $actor->canManageSettings()
            : ($actor->canSendEmail() && $template->user_id === $actor->getKey());
    }

    /** Borrar: mismo criterio que editar. */
    public function delete(User $actor, EmailTemplate $template): bool
    {
        return $this->update($actor, $template);
    }

    private function sameInstitution(User $actor, EmailTemplate $template): bool
    {
        return (int) $actor->institution_id === (int) $template->institution_id;
    }
}
