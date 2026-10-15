<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Crm\Models\Contact;
use Modules\Crm\Models\Lead;
use Modules\Crm\Services\SidebarBadges;
use Modules\Social\Models\SocialConversation;

/**
 * Indicadores numéricos del menú lateral, calculados en UN solo sitio y siempre del estado real
 * de la base de datos (nunca incrementos del navegador):
 *  - leads / contacts: altas posteriores a la última visita del usuario al módulo (SidebarBadges,
 *    marca por usuario). Una actualización o un duplicado no crea filas, así que no cuenta.
 *  - social: mensajes entrantes sin leer de la Bandeja de la institución (unread_count, que solo
 *    suben los entrantes nuevos: ni salientes, ni ecos, ni respuestas del asesor, ni reintentos).
 *
 * Solo devuelve los indicadores que el usuario puede ver (sin permiso, la clave no aparece) y solo
 * números: nunca nombres, mensajes ni datos personales. El aislamiento por institución lo aplica
 * el scope global de cada modelo con la institución activa de la sesión.
 */
final class PanelBadges
{
    public function __construct(
        private readonly SidebarBadges $crm,
        private readonly CurrentInstitution $context,
    ) {}

    /** @return array{leads?: int, contacts?: int, social?: int} */
    public function for(?User $user): array
    {
        if ($user === null || ! $this->context->has()) {
            return [];
        }

        $out = [];
        $canLeads = $user->can('viewAny', Lead::class);
        $canContacts = $user->can('viewAny', Contact::class);
        if ($canLeads || $canContacts) {
            $crm = $this->crm->counts($user);
            if ($canLeads) {
                $out['leads'] = $crm['leads'];
            }
            if ($canContacts) {
                $out['contacts'] = $crm['contacts'];
            }
        }
        if ($user->canWorkCrm()) {
            $out['social'] = (int) SocialConversation::query()->sum('unread_count');
        }

        return $out;
    }
}
