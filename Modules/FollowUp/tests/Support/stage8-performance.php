<?php

declare(strict_types=1);

require dirname(__DIR__, 4).'/vendor/autoload.php';
$app = require dirname(__DIR__, 4).'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
set_exception_handler(function (Throwable $error): never {
    fwrite(STDERR, $error->getMessage().PHP_EOL);
    exit(1);
});
use Illuminate\Support\Facades\DB;
use Modules\FollowUp\Models\FollowUpOpportunity;
use Modules\FollowUp\Services\FollowUpAnalyticsAggregator;
use Modules\FollowUp\Services\FollowUpMetricsService;

if (! $app->environment('testing') || DB::connection()->getDatabaseName() !== 'crm_mca_test') {
    throw new RuntimeException('Test database required');
}
\Illuminate\Support\Facades\Http::preventStrayRequests();
\Illuminate\Support\Facades\Mail::fake();
\Illuminate\Support\Facades\Notification::fake();
\Illuminate\Support\Facades\Queue::fake();
config(['followup.execution_enabled' => false]);
$queries = 0;
DB::listen(function () use (&$queries): void {
    $queries++;
});
foreach ([10, 100, 500] as $size) {
    DB::beginTransaction();
    try {
        $institution = \Modules\Institutions\Models\Institution::factory()->create();
        app(\Modules\Core\Tenancy\CurrentInstitution::class)->set($institution->id);
        $opportunities = FollowUpOpportunity::factory()->count($size)->create(['detected_at' => now()->subDays(2), 'contact_id' => null, 'lead_id' => null]);
        foreach ($opportunities as $op) {
            $cycle = \Modules\FollowUp\Models\FollowUpCycle::query()->create(['follow_up_opportunity_id' => $op->id, 'sequence' => 1, 'classification' => 'general_interest', 'reason_codes' => [], 'started_at' => now(), 'detected_at' => now()]);
            \Modules\FollowUp\Models\FollowUpAction::query()->create(['follow_up_opportunity_id' => $op->id, 'follow_up_cycle_id' => $cycle->id, 'channel' => 'whatsapp', 'purpose' => 'requested_follow_up', 'status' => 'cancelled', 'policy_status' => 'unknown', 'policy_reason_code' => 'fixture', 'policy_version' => '2.0', 'idempotency_key' => hash('sha256', 'benchmark'.$op->id), 'attempt_number' => 1, 'cycle_attempt_number' => 1]);
        }
        $queries = 0;
        $start = hrtime(true);
        $facts = app(FollowUpAnalyticsAggregator::class)->reconcile();
        $rebuildMs = (hrtime(true) - $start) / 1e6;
        $rebuildQueries = $queries;
        $queries = 0;
        $start = hrtime(true);
        $report = app(FollowUpMetricsService::class)->report(now()->subDays(5)->toDateString(), now()->toDateString());
        $reportMs = (hrtime(true) - $start) / 1e6;
        if ($report['counts']['detected'] !== $size || $facts !== 2 * $size) {
            throw new RuntimeException('Incorrect benchmark projection');
        }
        echo json_encode(['opportunities' => $size, 'facts' => $facts, 'rebuild_ms' => round($rebuildMs, 2), 'rebuild_queries' => $rebuildQueries, 'report_ms' => round($reportMs, 2), 'report_queries' => $queries, 'peak_mib' => round(memory_get_peak_usage(true) / 1048576, 2)])."\n";
    } finally {
        DB::rollBack();
    }
}
