<?php

declare(strict_types=1);

namespace Modules\Crm\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Modules\Crm\Enums\InterestLevel;
use Modules\Crm\Enums\LeadStatus;
use Modules\Crm\Models\Activity;
use Modules\Crm\Models\Contact;
use Modules\Crm\Models\Lead;
use Modules\Crm\Policies\ActivityPolicy;

/**
 * Consultas del Pipeline Comercial: los mismos leads que el listado (scope de institución y
 * LeadPolicy), con filtros, contadores del TOTAL filtrado por etapa, carga incremental por
 * columna y datos del Calendario por lead en consultas agregadas (sin N+1). Las actividades
 * respetan ActivityPolicy::visibleQuery: cada usuario solo ve las que ya podía ver.
 *
 * Filtros: scope (mine|team) · advisor (assigned_to_user_id) · program · product · interest ·
 * period (7|30|90 días desde la captación) · search (nombre o correo del contacto).
 */
class PipelineService
{
    public const PAGE = 20;

    public const PERIODS = ['7', '30', '90'];

    /**
     * @param  array<string,mixed>  $f
     * @return Builder<Lead>
     */
    public function query(User $actor, array $f): Builder
    {
        $term = trim((string) ($f['search'] ?? ''));
        $advisor = (int) ($f['advisor'] ?? 0);
        $program = (int) ($f['program'] ?? 0);
        $product = (string) ($f['product'] ?? '');
        $interest = (string) ($f['interest'] ?? '');
        $period = (string) ($f['period'] ?? '');

        return Lead::query()
            ->when(($f['scope'] ?? 'team') === 'mine', fn (Builder $q) => $q->where('assigned_to_user_id', $actor->id))
            ->when($advisor > 0, fn (Builder $q) => $q->where('assigned_to_user_id', $advisor))
            ->when($program > 0, fn (Builder $q) => $q->where('program_id', $program))
            ->when($product !== '', fn (Builder $q) => $q->where('product_type', $product))
            ->when(in_array($interest, InterestLevel::values(), true), fn (Builder $q) => $q->where('interest_level', $interest))
            ->when(in_array($period, self::PERIODS, true), fn (Builder $q) => $q->where('created_at', '>=', now()->subDays((int) $period)))
            ->when($term !== '', fn (Builder $q) => $q->whereIn('contact_id', Contact::query()->select('id')
                ->where(fn (Builder $c) => $c->where('first_name', 'like', '%'.$term.'%')
                    ->orWhere('last_name', 'like', '%'.$term.'%')
                    ->orWhere('email', 'like', '%'.$term.'%'))));
    }

    /**
     * Total filtrado por etapa (no solo las tarjetas cargadas), en una sola consulta.
     *
     * @param  array<string,mixed>  $f
     * @return array<string,int>
     */
    public function counts(User $actor, array $f): array
    {
        $rows = $this->query($actor, $f)->toBase()->selectRaw('status, COUNT(*) AS n')->groupBy('status')->pluck('n', 'status');
        $out = [];
        foreach (LeadStatus::cases() as $s) {
            $out[$s->value] = (int) ($rows[$s->value] ?? 0);
        }

        return $out;
    }

    /**
     * Tarjetas de una columna: las más recientemente movidas/actualizadas primero.
     *
     * @param  array<string,mixed>  $f
     * @return Collection<int,Lead>
     */
    public function column(User $actor, array $f, LeadStatus $status, int $limit): Collection
    {
        return $this->query($actor, $f)
            ->where('status', $status->value)
            ->with(['contact:id,first_name,last_name,email', 'program', 'assignedUser:id,name', 'bot:id,assistant_name'])
            ->orderByDesc('updated_at')->orderByDesc('id')
            ->limit(max(1, $limit))
            ->get();
    }

    /**
     * Calendario de cada lead (solo actividades visibles para el actor): pendientes, vencidas
     * (programadas cuyo fin ya pasó y nadie cerró), próxima actividad y última actividad pasada.
     *
     * @param  array<int,int>  $leadIds
     * @return array<int,array{pending:int,overdue:int,next:Activity|null,last_at:Carbon|null}>
     */
    public function calendar(User $actor, array $leadIds): array
    {
        if ($leadIds === []) {
            return [];
        }
        $now = now()->utc()->format('Y-m-d H:i:s');
        $rows = ActivityPolicy::visibleQuery(Activity::query(), $actor)->whereIn('lead_id', $leadIds)->toBase()
            ->selectRaw("lead_id,
                SUM(CASE WHEN status = 'scheduled' THEN 1 ELSE 0 END) AS pending,
                SUM(CASE WHEN status = 'scheduled' AND ends_at < ? THEN 1 ELSE 0 END) AS overdue,
                MIN(CASE WHEN status = 'scheduled' AND starts_at >= ? THEN starts_at END) AS next_at,
                MAX(CASE WHEN status IN ('completed', 'no_show') OR (status = 'scheduled' AND starts_at < ?) THEN starts_at END) AS last_at", [$now, $now, $now])
            ->groupBy('lead_id')
            ->get();

        $nextAt = $rows->filter(fn ($r) => $r->next_at !== null)->mapWithKeys(fn ($r) => [(int) $r->lead_id => (string) $r->next_at]);
        $next = [];
        if ($nextAt->isNotEmpty()) {
            $candidates = ActivityPolicy::visibleQuery(Activity::query(), $actor)->scheduled()
                ->whereIn('lead_id', $nextAt->keys()->all())
                ->whereIn('starts_at', $nextAt->values()->unique()->all())
                ->with(['contact', 'lead.contact'])
                ->orderBy('starts_at')->orderBy('id')
                ->get();
            foreach ($candidates as $a) {
                $leadId = (int) $a->lead_id;
                if (! isset($next[$leadId]) && $a->starts_at->format('Y-m-d H:i:s') === ($nextAt[$leadId] ?? null)) {
                    $next[$leadId] = $a;
                }
            }
        }

        $out = [];
        foreach ($rows as $r) {
            $id = (int) $r->lead_id;
            $out[$id] = [
                'pending' => (int) $r->pending,
                'overdue' => (int) $r->overdue,
                'next' => $next[$id] ?? null,
                'last_at' => $r->last_at !== null ? Carbon::parse((string) $r->last_at, 'UTC') : null,
            ];
        }

        return $out;
    }
}
