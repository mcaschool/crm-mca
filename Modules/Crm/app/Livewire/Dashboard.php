<?php

declare(strict_types=1);

namespace Modules\Crm\Livewire;

use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Component;
use Modules\Crm\Models\Conversation;
use Modules\Crm\Models\Event;
use Modules\Crm\Models\Lead;

/**
 * Dashboard / inicio del panel. Pantalla de entrada, adaptada por PERSONA (ver
 * User::crmPersona): Admin, Admisiones, Academico, Marketing (solo lectura, con
 * graficas) y Soporte (referidos en primer plano). TODOS ven la informacion; lo
 * que cambia es que pueden HACER (los accesos de accion se ocultan a solo-lectura).
 * Los conteos usan indices existentes (leads/events/conversations).
 */
#[Layout('layouts.app')]
class Dashboard extends Component
{
    private const STATUS_LABELS = [
        'new' => 'Nuevo',
        'contacted' => 'Contactado',
        'qualified' => 'En seguimiento',
        'enrolled' => 'Matriculado',
        'discarded' => 'Descartado',
    ];

    /**
     * El notificador de leads (topbar) emitió que llegaron leads nuevos: basta con
     * re-renderizar para que los conteos (p. ej. "Nuevos sin contactar") se refresquen
     * en vivo sin recargar la página. El cuerpo vacío ya provoca el re-render.
     */
    #[On('crm-new-leads')]
    public function refreshOnNewLeads(): void
    {
        // no-op: el re-render recalcula counts() con los datos frescos.
    }

    /**
     * Conteos comunes (COUNT con indices).
     *
     * @return array<string, int>
     */
    private function counts(User $user): array
    {
        $startOfMonth = Carbon::now()->startOfMonth();
        $startOfPrevMonth = (clone $startOfMonth)->subMonthNoOverflow();

        return [
            'new' => Lead::query()->where('status', 'new')->count(),
            'contacted' => Lead::query()->where('status', 'contacted')->count(),
            'followup' => Lead::query()->where('status', 'qualified')->count(),
            'enrolledMonth' => Lead::query()->where('status', 'enrolled')->where('updated_at', '>=', $startOfMonth)->count(),
            'leadsMonth' => Lead::query()->where('created_at', '>=', $startOfMonth)->count(),
            'leadsPrevMonth' => Lead::query()->whereBetween('created_at', [$startOfPrevMonth, $startOfMonth])->count(),
            'conversationsToday' => Conversation::query()->whereDate('last_activity_at', Carbon::today())->count(),
            'conversationsMonth' => Conversation::query()->where('started_at', '>=', $startOfMonth)->count(),
            'conversationsPrevMonth' => Conversation::query()->whereBetween('started_at', [$startOfPrevMonth, $startOfMonth])->count(),
            'corporate' => Event::query()->where('event_type', 'corporate_interest')->distinct()->count('contact_id'),
            'referredToMe' => Lead::query()->where('assigned_to_user_id', $user->getKey())->count(),
            'referredPending' => Lead::query()->where('assigned_to_user_id', $user->getKey())
                ->whereNotIn('status', ['enrolled', 'discarded'])->count(),
        ];
    }

    /**
     * Contactos con interes corporativo entre los leads mostrados (para la etiqueta
     * "Empresa"). Una sola consulta.
     *
     * @param  Collection<int, Lead>  $leads
     * @return array<int, bool>
     */
    private function corporateMap(Collection $leads): array
    {
        $contactIds = $leads->pluck('contact_id')->filter()->unique()->all();
        if ($contactIds === []) {
            return [];
        }

        return Event::query()
            ->where('event_type', 'corporate_interest')
            ->whereIn('contact_id', $contactIds)
            ->pluck('contact_id')
            ->mapWithKeys(fn ($id) => [(int) $id => true])
            ->all();
    }

    /**
     * Leads para una lista corta de la ficha del dashboard (con contacto).
     *
     * @param  callable(\Illuminate\Database\Eloquent\Builder<Lead>):mixed  $filter
     * @return Collection<int, Lead>
     */
    private function leadList(callable $filter, string $orderColumn, string $dir, int $limit): Collection
    {
        $q = Lead::query()->with('contact');
        $filter($q);

        return $q->orderBy($orderColumn, $dir)->limit($limit)->get();
    }

    /**
     * Etiqueta "referido por" de un lead: se lee del ultimo evento lead_transferred
     * del contacto (by_dept o by_name), si existe.
     *
     * @param  Collection<int, Lead>  $leads
     * @return array<int, array{by:string, at:?Carbon}>
     */
    private function referralMeta(Collection $leads): array
    {
        $contactIds = $leads->pluck('contact_id')->filter()->unique()->all();
        if ($contactIds === []) {
            return [];
        }

        $events = Event::query()
            ->where('event_type', 'lead_transferred')
            ->whereIn('contact_id', $contactIds)
            ->orderByDesc('created_at')
            ->get(['contact_id', 'event_data', 'created_at']);

        $map = [];
        foreach ($events as $ev) {
            $cid = (int) $ev->contact_id;
            if (isset($map[$cid])) {
                continue; // ya tenemos el mas reciente
            }
            $data = is_array($ev->event_data) ? $ev->event_data : [];
            $by = $data['by_dept'] ?? $data['by_name'] ?? null;
            $map[$cid] = ['by' => is_string($by) ? $by : 'Equipo', 'at' => $ev->created_at];
        }

        return $map;
    }

    /**
     * Actividad reciente del sistema (eventos), con el contacto para dar contexto.
     *
     * @return Collection<int, Event>
     */
    private function recentActivity(int $limit = 6): Collection
    {
        return Event::query()
            ->with('contact')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /**
     * Leads por area (las areas configurables). Para x-ui.chart-bars (label/value).
     * Scoping: el InstitutionScope global + linea activa (product_type microcredential)
     * ya acotan; el filtro opcional aplica la visibilidad del rol (p. ej. referidos).
     *
     * @param  \Closure(\Illuminate\Database\Eloquent\Builder<Lead>):mixed|null  $filter
     * @return array<int, array{label:string, value:int}>
     */
    private function leadsByArea(?callable $filter = null): array
    {
        /** @var array<int,string> $areas */
        $areas = (array) config('crm.microcredential_areas', []);

        $rows = [];
        foreach ($areas as $area) {
            $q = Lead::query()->where('area', $area);
            if ($filter !== null) {
                $filter($q);
            }
            $rows[] = ['label' => $area, 'value' => $q->count()];
        }

        return $rows;
    }

    /**
     * Embudo de estados (conteo real por estado) para x-ui.funnel.
     *
     * @param  \Closure(\Illuminate\Database\Eloquent\Builder<Lead>):mixed|null  $filter
     * @return array<int, array{label:string, value:int, class:string}>
     */
    private function statusFunnel(?callable $filter = null): array
    {
        $classes = [
            'new' => 'st-new', 'contacted' => 'st-con', 'qualified' => 'st-seg',
            'enrolled' => 'st-mat', 'discarded' => 'st-des',
        ];

        $q = Lead::query()->selectRaw('status, COUNT(*) as c')->groupBy('status');
        if ($filter !== null) {
            $filter($q);
        }
        $byStatus = $q->pluck('c', 'status');

        $segments = [];
        foreach (self::STATUS_LABELS as $status => $label) {
            $segments[] = [
                'label' => $label,
                'value' => (int) ($byStatus[$status] ?? 0),
                'class' => $classes[$status],
            ];
        }

        return $segments;
    }

    public function render(): View
    {
        /** @var User $user */
        $user = auth()->user();
        $persona = $user->crmPersona();
        $counts = $this->counts($user);

        $data = [
            'user' => $user,
            'persona' => $persona,
            'readOnly' => $user->crmReadOnly(),
            'canCreate' => $user->can('create', Lead::class),
            'counts' => $counts,
        ];

        // Datos especificos por persona (solo se consulta lo que la vista usara).
        if ($persona === 'academico' || $persona === 'soporte') {
            // Referidos en primer plano; sin graficas globales (respetar su visibilidad).
            $referrals = $this->leadList(
                fn ($q) => $q->where('assigned_to_user_id', $user->getKey()),
                'updated_at',
                'desc',
                6,
            );
            $data['referrals'] = $referrals;
            $data['referralMeta'] = $this->referralMeta($referrals);
            $data['corporate'] = $this->corporateMap($referrals);
            $data['activity'] = $this->recentActivity();
        } elseif ($persona === 'marketing') {
            // Solo lectura, analitica: graficas con datos reales globales.
            $data['leadsByArea'] = $this->leadsByArea();
            $data['funnel'] = $this->statusFunnel();
        } else { // admin / admisiones (operativo, actua): igual que el v4 de referencia
            $newLeads = $this->leadList(fn ($q) => $q->where('status', 'new'), 'created_at', 'desc', 6);
            $data['newLeads'] = $newLeads;
            $data['corporate'] = $this->corporateMap($newLeads);
            $data['activity'] = $this->recentActivity();
            $data['leadsByArea'] = $this->leadsByArea();
            $data['funnel'] = $this->statusFunnel();
        }

        return view('crm::livewire.dashboard', $data);
    }
}
