<?php

declare(strict_types=1);

require dirname(__DIR__, 4).'/vendor/autoload.php';
$app = require dirname(__DIR__, 4).'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (! $app->environment('testing') || config('database.connections.mysql.database') !== 'crm_mca_test') {
    throw new RuntimeException('Test database required');
}
\Illuminate\Support\Facades\Http::preventStrayRequests();
\Illuminate\Support\Facades\Mail::fake();
\Illuminate\Support\Facades\Notification::fake();
\Illuminate\Support\Facades\Queue::fake();
config(['followup.execution_enabled' => false]);
app(\Modules\Core\Tenancy\CurrentInstitution::class)->set((int) $argv[1]);
$actor = \App\Models\User::query()->findOrFail((int) $argv[2]);
auth()->setUser($actor);
while (microtime(true) < (float) $argv[5]) {
    usleep(10000);
}
$service = app(\Modules\FollowUp\Services\FollowUpCommercialCoordinator::class);
if ($argv[6] === 'sync') {
    echo $service->sync((int) $argv[3])->commercial_version;
} elseif ($argv[6] === 'convert') {
    $op = \Modules\FollowUp\Models\FollowUpOpportunity::query()->findOrFail((int) $argv[3]);
    app(\Modules\Crm\Services\LeadService::class)->changeStatus($op->lead, \Modules\Crm\Enums\LeadStatus::Enrolled);
    echo 'converted';
} else {
    try {
        echo $service->createTask($actor, (int) $argv[3], (int) $argv[4], (int) $actor->id, 'task', now()->addDays(2)->toDateTimeString())->id;
    } catch (\Illuminate\Validation\ValidationException $exception) {
        if ($argv[6] !== 'racing_task') {
            throw $exception;
        }
        echo 'blocked';
    }
}
