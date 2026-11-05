<?php

declare(strict_types=1);

require dirname(__DIR__, 4).'/vendor/autoload.php';
$app = require dirname(__DIR__, 4).'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (! $app->environment('testing') || \Illuminate\Support\Facades\DB::connection()->getDatabaseName() !== 'crm_mca_test') {
    throw new RuntimeException('Test database required');
}
\Illuminate\Support\Facades\Http::preventStrayRequests();
\Illuminate\Support\Facades\Queue::fake();
config(['followup.execution_enabled' => false]);
app(\Modules\Core\Tenancy\CurrentInstitution::class)->set((int) $argv[1]);
\Carbon\Carbon::setTestNow('2026-11-04 00:00:00');
echo app(\Modules\FollowUp\Services\FollowUpAnalyticsAggregator::class)->reconcile();
