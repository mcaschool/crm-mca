<?php

declare(strict_types=1);

namespace Modules\Crm\Services;

use App\Models\User;
use Carbon\CarbonInterface;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Crm\Models\Contact;
use Modules\Crm\Models\CrmModuleRead;
use Modules\Crm\Models\Lead;

/**
 * Contadores de "nuevos" del menú lateral (Leads/Contactos), mismo patrón que el
 * badge de la Bandeja social pero con estado de lectura POR USUARIO (watermark en
 * crm_module_reads): "nuevo" = created_at > last_seen_at del usuario.
 *
 * Único punto de cálculo para el sidebar (nada de queries dispersas por vistas):
 *  - counts(): un COUNT indexado por módulo, SOLO si el usuario tiene viewAny del
 *    modelo y hay institución en contexto (fuera del panel: ceros, cero queries).
 *  - markSeen(): entrar al módulo avanza la marca → el contador cae a 0.
 *  - Línea base perezosa: la PRIMERA vez que se consulta un módulo sin marca, se
 *    crea con now() — lo histórico previo al estreno de la feature no aparece como
 *    "nuevo" (misma filosofía que la línea base del NewLeadNotifier).
 */
final class SidebarBadges
{
    /** Módulo del sidebar => modelo cuyo viewAny lo protege y cuyos altas cuentan. */
    public const MODULES = [
        'leads' => Lead::class,
        'contacts' => Contact::class,
    ];

    public function __construct(private readonly CurrentInstitution $context) {}

    /**
     * @return array{leads: int, contacts: int}
     */
    public function counts(?User $user): array
    {
        $counts = ['leads' => 0, 'contacts' => 0];
        if ($user === null || ! $this->context->has()) {
            return $counts; // fuera del panel (p. ej. Mi perfil): sin queries
        }

        foreach (self::MODULES as $module => $model) {
            if (! $user->can('viewAny', $model)) {
                continue; // sin permiso: ni consulta ni información del contador
            }

            $counts[$module] = (int) $model::query()
                ->where('created_at', '>', $this->lastSeenAt($user, $module))
                ->count();
        }

        return $counts;
    }

    /** Marca el módulo como visto AHORA para este usuario (los demás no se afectan). */
    public function markSeen(User $user, string $module): void
    {
        if (! isset(self::MODULES[$module])) {
            return;
        }

        CrmModuleRead::query()->updateOrCreate(
            ['user_id' => $user->id, 'module' => $module],
            ['last_seen_at' => now()],
        );
    }

    private function lastSeenAt(User $user, string $module): CarbonInterface
    {
        $read = CrmModuleRead::query()
            ->where('user_id', $user->id)
            ->where('module', $module)
            ->first();
        if ($read !== null) {
            return $read->last_seen_at;
        }

        // Línea base perezosa (una sola vez por usuario/módulo): lo ya existente
        // queda "visto"; desde aquí solo cuentan las altas nuevas.
        $now = now();
        CrmModuleRead::query()->create([
            'user_id' => $user->id,
            'module' => $module,
            'last_seen_at' => $now,
        ]);

        return $now;
    }
}
