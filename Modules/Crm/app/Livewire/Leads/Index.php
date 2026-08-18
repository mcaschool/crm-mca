<?php

declare(strict_types=1);

namespace Modules\Crm\Livewire\Leads;

use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Response;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Modules\Crm\Enums\LeadStatus;
use Modules\Crm\Models\Event;
use Modules\Crm\Models\Lead;
use Modules\Institutions\Models\Bot;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Reemplazo directo del Google Sheets: donde Marketing y Admisiones ven y
 * trabajan los prospectos. Lista con busqueda, filtros (Estado, Asesor de origen
 * y Area) y exportacion CSV que respeta los filtros activos. Aislada por el scope
 * global de institucion. Nunca borra leads ni conversaciones (regla de seguridad).
 */
#[Layout('layouts.app')]
class Index extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $status = '';

    /** Asesor de origen (bot_id). */
    #[Url]
    public string $advisor = '';

    /** Area del emparejador/interes (lista configurable en config/crm.php). */
    #[Url]
    public string $area = '';

    /** '1' = solo los leads referidos al usuario actual (assigned_to_user_id). */
    #[Url]
    public string $mine = '';

    public function mount(): void
    {
        $this->authorize('viewAny', Lead::class);
    }

    public function updated(string $property): void
    {
        // Cualquier cambio de filtro vuelve a la primera pagina.
        if (in_array($property, ['search', 'status', 'advisor', 'area', 'mine'], true)) {
            $this->resetPage();
        }
    }

    /**
     * Consulta base con los filtros activos. La comparten el listado y la
     * exportacion para garantizar que exportas EXACTAMENTE lo que ves filtrado.
     *
     * @return Builder<Lead>
     */
    private function baseQuery(): Builder
    {
        $term = trim($this->search);

        return Lead::query()
            ->with(['contact', 'program', 'bot'])
            ->when($term !== '', function (Builder $q) use ($term): void {
                $q->whereHas('contact', function (Builder $c) use ($term): void {
                    $c->where('first_name', 'like', '%'.$term.'%')
                        ->orWhere('last_name', 'like', '%'.$term.'%')
                        ->orWhere('email', 'like', '%'.$term.'%');
                });
            })
            ->when($this->status !== '', fn (Builder $q) => $q->where('status', $this->status))
            ->when($this->advisor !== '', fn (Builder $q) => $q->where('bot_id', (int) $this->advisor))
            ->when($this->area !== '', fn (Builder $q) => $q->where('area', $this->area))
            ->when($this->mine === '1', fn (Builder $q) => $q->where('assigned_to_user_id', auth()->id()))
            ->orderByDesc('created_at');
    }

    /**
     * Contactos con interes corporativo detectado (evento del widget/Celia). Se
     * usa para pintar la etiqueta "Empresa" solo si REALMENTE existio ese interes.
     *
     * @param  Collection<int, Lead>  $leads
     * @return array<int, bool>
     */
    private function corporateContactMap(Collection $leads): array
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
     * Exporta a CSV los leads que cumplen los filtros activos (sin paginar). No
     * incluye datos sensibles en la URL; la descarga va por streaming. El telefono
     * NO se exporta (dato personal sensible; se accede auditado desde la ficha).
     */
    public function export(): StreamedResponse
    {
        $this->authorize('viewAny', Lead::class);

        $leads = $this->baseQuery()->get();
        $corporate = $this->corporateContactMap($leads);
        $filename = 'leads_'.now()->format('Y-m-d_His').'.csv';

        return Response::streamDownload(function () use ($leads, $corporate): void {
            $out = fopen('php://output', 'w');
            // BOM UTF-8 para que Excel respete acentos.
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Nombre', 'Correo', 'Asesor de origen', 'Estado', 'Meta', 'Area', 'Empresa', 'Fecha']);

            foreach ($leads as $lead) {
                fputcsv($out, [
                    trim(($lead->contact->first_name ?? '').' '.($lead->contact->last_name ?? '')),
                    $lead->contact->email ?? '',
                    $lead->bot->assistant_name ?? '',
                    $lead->status->label(),
                    $lead->goal ?? '',
                    $lead->area ?? '',
                    isset($corporate[(int) $lead->contact_id]) ? 'Si' : '',
                    $lead->created_at->format('Y-m-d'),
                ]);
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function render(): View
    {
        $leads = $this->baseQuery()->paginate(20);

        /** @var Collection<int, Lead> $items */
        $items = collect($leads->items());

        return view('crm::livewire.leads.index', [
            'leads' => $leads,
            'corporate' => $this->corporateContactMap($items),
            'statuses' => LeadStatus::cases(),
            'advisors' => Bot::query()->orderBy('assistant_name')->get(['id', 'assistant_name']),
            'areas' => config('crm.microcredential_areas', []),
        ]);
    }
}
