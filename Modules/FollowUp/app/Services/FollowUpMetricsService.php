<?php

declare(strict_types=1);

namespace Modules\FollowUp\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\FollowUp\Models\AnalyticsFact;

final class FollowUpMetricsService
{
    public const DIMENSIONS = ['bot_id', 'channel', 'program_id', 'priority', 'stage', 'opportunity_status', 'owner_id'];

    /**
     * @param  array<string,string>  $filters
     * @return Builder<AnalyticsFact>
     */
    public function query(array $filters): Builder
    {
        $query = AnalyticsFact::query();
        foreach (self::DIMENSIONS as $column) {
            if (($filters[$column] ?? '') !== '') {
                $query->where($column, $filters[$column]);
            }
        }
        if (($filters['action_type'] ?? '') !== '') {
            $query->where(fn ($q) => $q->where('action_type', $filters['action_type'])->orWhere('kind', '!=', 'action'));
        }
        if (($filters['result'] ?? '') !== '') {
            $query->where(fn ($q) => $q->where('status', $filters['result'])->orWhere('kind', '!=', 'action'));
        }

        return $query;
    }

    /** @return array{0:CarbonImmutable,1:CarbonImmutable} */
    public function period(string $from, string $to): array
    {
        try {
            $start = CarbonImmutable::createFromFormat('!Y-m-d', $from, 'UTC');
            $end = CarbonImmutable::createFromFormat('!Y-m-d', $to, 'UTC');
            if (! $start || ! $end || $start->format('Y-m-d') !== $from || $end->format('Y-m-d') !== $to || $end->lt($start) || $start->diffInDays($end) > 365) {
                throw new \RuntimeException;
            }

            return [$start, $end->endOfDay()];
        } catch (\Throwable) {
            throw ValidationException::withMessages(['period' => 'Selecciona fechas válidas en orden, con un máximo de 366 días.']);
        }
    }

    /**
     * @param  array<string,string>  $filters
     * @return array<string,mixed>
     */
    public function report(string $from, string $to, array $filters = []): array
    {
        [$start, $end] = $this->period($from, $to);
        $base = $this->query($filters);
        $period = (clone $base)->whereBetween('occurred_at', [$start, $end]);
        $eligible = (clone $base)->where('kind', 'action')->whereBetween('eligible_at', [$start, $end]);
        $subjects = (clone $eligible)->distinct()->count('subject');
        $responders = (clone $eligible)->whereNotNull('response_at')->distinct()->count('subject');
        $opportunities = (clone $eligible)->distinct()->count('opportunity_id');
        $reactivated = (clone $eligible)->whereNotNull('response_at')->distinct()->count('opportunity_id');
        $associated = (clone $base)->where('kind', 'conversion')->whereIn('action_id', (clone $eligible)->select('action_id'));
        $exclusions = (clone $base)->where('kind', 'exclusion')->whereIn('action_id', (clone $eligible)->select('action_id'))->distinct()->count('subject');
        $attempted = (clone $period)->where('kind', 'action')->where('data->attempted', true)->count();
        $failed = (clone $period)->where('kind', 'action')->where('data->attempted', true)->where('status', 'failed')->count();
        $generation = (clone $period)->where('kind', 'generation');
        $approved = (clone $base)->where('kind', 'approval')->whereIn('source_key', (clone $generation)->select('source_key'))->count();
        $counts = [
            'detected' => (clone $period)->where('kind', 'opportunity')->count(),
            'proposed' => (clone $period)->where('kind', 'action')->count(),
            'generated' => (clone $generation)->count(), 'approved' => $approved,
            'regenerated' => max(0, (clone $generation)->count() - (clone $generation)->distinct()->count('action_id')),
            'executed' => (clone $eligible)->count(), 'subjects' => $subjects,
            'responders' => $responders, 'reactivated' => $reactivated,
            'unassociated_responses' => (clone $period)->where('kind', 'response')->whereNull('action_id')->count(),
            'conversions_observed' => (clone $period)->where('kind', 'conversion')->count(),
            'conversions_associated' => (clone $associated)->count(),
            'meetings' => (clone $period)->where('kind', 'meeting')->whereIn('data->state', ['scheduled', 'completed'])->count(),
            'meetings_completed' => (clone $period)->where('kind', 'meeting')->where('data->state', 'completed')->count(),
            'blocked' => (clone $period)->where('kind', 'action')->where('data->blocked', true)->count(),
            'unknown' => (clone $period)->where('kind', 'action')->where('status', 'delivery_unknown')->count(),
            'failed' => $failed, 'attempted' => $attempted, 'excluded' => $exclusions,
            'delivered' => (clone $eligible)->where('data->delivered', true)->count(),
            'read' => (clone $eligible)->where('data->read', true)->count(),
            'human' => (clone $period)->where('kind', 'opportunity')->where('data->human', true)->count(),
            'qualified' => (clone $period)->where('kind', 'opportunity')->where('stage', 'qualified')->count(),
            'lost' => (clone $period)->where('kind', 'opportunity')->where('data->close', 'discarded')->count(),
            'meeting_proposals' => (clone $period)->where('kind', 'opportunity')->where('data->recommendation', 'meeting_proposal')->count(),
            'enrollment_requests' => (clone $period)->where('kind', 'opportunity')->where('data->recommendation', 'admissions_task')->count(),
        ];
        $groups = [];
        foreach (['channel', 'bot_id', 'program_id'] as $dimension) {
            $groups[$dimension] = (clone $eligible)->select($dimension)->selectRaw('COUNT(*) as volume, COUNT(DISTINCT subject) as subjects, COUNT(DISTINCT CASE WHEN response_at IS NOT NULL THEN subject END) as responses')
                ->groupBy($dimension)->orderByDesc('volume')->limit(30)->get()->map(fn ($row) => ['key' => $row->getAttribute($dimension), 'volume' => (int) $row->getAttribute('volume'), 'subjects' => (int) $row->getAttribute('subjects'), 'responses' => (int) $row->getAttribute('responses')])->all();
        }
        $usage = (clone $period)->where('kind', 'usage')->selectRaw("COUNT(*) as calls, SUM(CAST(JSON_UNQUOTE(JSON_EXTRACT(data, '$.input_tokens')) AS UNSIGNED)) as input_tokens, SUM(CAST(JSON_UNQUOTE(JSON_EXTRACT(data, '$.output_tokens')) AS UNSIGNED)) as output_tokens, AVG(CAST(JSON_UNQUOTE(JSON_EXTRACT(data, '$.latency_ms')) AS UNSIGNED)) as latency_ms")->first();
        $run = DB::table('follow_up_analytics_runs')->where('institution_id', app(CurrentInstitution::class)->idOrFail())->first();
        $quality = (clone $base)->whereNotNull('data->quality')->selectRaw("JSON_UNQUOTE(JSON_EXTRACT(data, '$.quality')) as reason, COUNT(*) as total")->groupBy('reason')->get()->filter(fn ($row) => $row->getAttribute('reason') !== 'null')->pluck('total', 'reason')->all();

        return [
            'from' => $from, 'to' => $to, 'counts' => $counts, 'groups' => $groups, 'run' => $run,
            'rates' => ['response' => self::rate($responders, $subjects), 'reactivation' => self::rate($reactivated, $opportunities),
                'conversion' => self::rate((clone $associated)->distinct()->count('subject'), $subjects),
                'approval' => self::rate($approved, $counts['generated']), 'error' => self::rate($failed, $attempted), 'exclusion' => self::rate($exclusions, $subjects)],
            'quality' => $quality, 'open_windows' => (clone $eligible)->where('eligible_at', '>', now()->subDays(FollowUpAnalyticsAggregator::RESPONSE_DAYS))->count(),
            'response_seconds' => (clone $eligible)->whereNotNull('response_at')->selectRaw('AVG(TIMESTAMPDIFF(SECOND, eligible_at, response_at)) as seconds')->value('seconds'),
            'daily' => (clone $period)->where('kind', 'opportunity')->selectRaw('DATE(occurred_at) as day, COUNT(*) as total')->groupBy('day')->orderBy('day')->get()->pluck('total', 'day')->all(),
            'conversion_daily' => (clone $period)->where('kind', 'conversion')->whereNotNull('action_id')->selectRaw('DATE(occurred_at) as day, COUNT(*) as total')->groupBy('day')->orderBy('day')->get()->pluck('total', 'day')->all(),
            'states' => (clone $period)->where('kind', 'action')->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status')->all(),
            'priorities' => (clone $period)->where('kind', 'opportunity')->selectRaw('priority, COUNT(*) as total')->groupBy('priority')->pluck('total', 'priority')->all(),
            'blocks' => (clone $period)->where('kind', 'action')->where('data->blocked', true)->selectRaw("JSON_UNQUOTE(JSON_EXTRACT(data, '$.block_reason')) as reason, COUNT(*) as total")->groupBy('reason')->limit(20)->pluck('total', 'reason')->all(),
            'errors' => (clone $period)->where('kind', 'action')->whereIn('status', ['failed', 'delivery_unknown'])->selectRaw("JSON_UNQUOTE(JSON_EXTRACT(data, '$.error')) as reason, COUNT(*) as total")->groupBy('reason')->limit(20)->pluck('total', 'reason')->all(),
            'hours' => (clone $eligible)->selectRaw('HOUR(eligible_at) as hour, COUNT(DISTINCT subject) as volume, COUNT(DISTINCT CASE WHEN response_at IS NOT NULL THEN subject END) as responses')->groupBy('hour')->orderBy('hour')->get()->toArray(),
            'usage' => ['calls' => (int) $usage?->getAttribute('calls'), 'input_tokens' => (int) $usage?->getAttribute('input_tokens'), 'output_tokens' => (int) $usage?->getAttribute('output_tokens'), 'latency_ms' => $usage?->getAttribute('latency_ms'), 'cost' => null],
        ];
    }

    public static function rate(int $numerator, int $denominator): ?float
    {
        return $denominator > 0 ? round(100 * $numerator / $denominator, 2) : null;
    }
}
