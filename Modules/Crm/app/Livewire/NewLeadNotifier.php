<?php

declare(strict_types=1);

namespace Modules\Crm\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Crm\Models\Lead;

/**
 * Aviso in-app de LEAD NUEVO mientras el panel está abierto (sin correo, sin push,
 * sin WebSockets). Vive en la topbar de forma persistente y sondea cada ~10s con el
 * polling de Livewire. Detecta leads entrantes del widget creados desde el último id
 * visto EN LA SESIÓN (no marca nada como "leído" en BD: cada panel abierto recibe su
 * aviso). Al detectar uno o varios, dispara un evento de navegador para el pop-up +
 * sonido y un evento Livewire para refrescar el contador del dashboard si está visible.
 *
 * Scoping: el InstitutionScope global acota por institución; la "línea activa"
 * (Microcredenciales) se acota por product_type. Consulta ligera (por PK, con índice).
 */
class NewLeadNotifier extends Component
{
    /** Clave de sesión: último id de lead-widget ya visto por ESTA sesión. */
    private const SEEN_KEY = 'crm.newlead.last_id';

    /** Clave de sesión: preferencia de sonido (el usuario la activa una vez por sesión). */
    private const SOUND_KEY = 'crm.newlead.sound';

    /** ¿El sonido está activado en esta sesión? (el pop-up funciona igual sin sonido). */
    public bool $soundEnabled = false;

    public function mount(): void
    {
        $this->soundEnabled = (bool) session(self::SOUND_KEY, false);

        // Widget pasivo de la topbar: si por lo que sea no hay institución activa en
        // el contexto, no consulta nada (no debe romper ninguna página del panel).
        if (! $this->hasInstitutionContext()) {
            return;
        }

        // Línea base: al abrir el panel, todo lo existente queda "ya visto" para no
        // avisar de leads viejos. Solo se inicializa una vez por sesión.
        if (! session()->has(self::SEEN_KEY)) {
            session()->put(self::SEEN_KEY, $this->currentMaxWidgetLeadId());
        }
    }

    /** Activa/silencia el sonido; persiste la preferencia en la sesión. */
    public function toggleSound(): void
    {
        $this->soundEnabled = ! $this->soundEnabled;
        session()->put(self::SOUND_KEY, $this->soundEnabled);
    }

    /**
     * Sondeo (~10s): ¿hay leads-widget nuevos desde el último visto? Si los hay,
     * avanza la marca de sesión y emite el aviso (agrupado si son varios).
     */
    public function check(): void
    {
        if (! $this->hasInstitutionContext()) {
            return;
        }

        $lastId = (int) session(self::SEEN_KEY, 0);

        $new = Lead::query()
            ->where('id', '>', $lastId)
            ->where('source', 'like', 'widget%')
            ->where('product_type', 'microcredential')
            ->with('contact:id,first_name,last_name')
            ->orderBy('id')
            ->get(['id', 'contact_id', 'area', 'source']);

        if ($new->isEmpty()) {
            return;
        }

        session()->put(self::SEEN_KEY, (int) $new->max('id'));

        $count = $new->count();
        $latest = $new->last();

        if ($count === 1) {
            $name = $latest->contact?->fullName() ?: __('Nuevo lead');
            $area = $latest->area;
            $title = $area ? $name.' · '.$area : $name;
            $url = route('crm.leads.show', $latest);
        } else {
            $title = trans_choice(':count leads nuevos|:count leads nuevos', $count, ['count' => $count]);
            $url = route('crm.leads.index');
        }

        // Un solo dispatch: el navegador (Alpine) lo oye para pop-up+sonido y el
        // Dashboard (si está en pantalla) lo oye para refrescar su contador en vivo.
        $this->dispatch('crm-new-leads', count: $count, title: $title, url: $url);
    }

    /** ¿Hay institución activa en el contexto? (el panel siempre la fija; guard defensivo). */
    private function hasInstitutionContext(): bool
    {
        return app(CurrentInstitution::class)->has();
    }

    /** Id máximo actual de lead entrante del widget (para la línea base de sesión). */
    private function currentMaxWidgetLeadId(): int
    {
        return (int) Lead::query()
            ->where('source', 'like', 'widget%')
            ->where('product_type', 'microcredential')
            ->max('id');
    }

    public function render(): View
    {
        return view('crm::livewire.new-lead-notifier');
    }
}
