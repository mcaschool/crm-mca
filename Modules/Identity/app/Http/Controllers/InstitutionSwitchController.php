<?php

declare(strict_types=1);

namespace Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Institutions\Models\Institution;

/**
 * Cambiador de institucion activa (D1): solo el super-admin puede ver y operar
 * varias instituciones. Un usuario NO super-admin queda FIJADO a la suya y no
 * puede cambiarla por ninguna via (barandilla en el backend, no solo en la UI).
 *
 * El id activo se guarda en la sesion; el middleware ResolveInstitutionFromUser
 * lo lee en las siguientes peticiones.
 */
class InstitutionSwitchController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        // Superficie multi-institucion: deshabilitada mientras el flag este en false.
        abort_unless((bool) config('crm.multi_institution'), 403);

        $user = $request->user();

        // Barandilla: solo super-admin. Cualquier otro -> 403.
        abort_unless($user !== null && $user->isSuperAdmin(), 403);

        $validated = $request->validate([
            'institution_id' => ['required', 'integer'],
        ]);

        // La institucion debe existir (modo global: el super-admin ve todas).
        $exists = app(CurrentInstitution::class)->runGlobally(
            fn (): bool => Institution::query()->whereKey($validated['institution_id'])->exists()
        );

        abort_unless($exists, 404);

        $request->session()->put('active_institution_id', (int) $validated['institution_id']);

        return redirect()->route('dashboard');
    }
}
