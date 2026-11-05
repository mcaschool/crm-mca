<?php

declare(strict_types=1);

require dirname(__DIR__, 4).'/vendor/autoload.php';
$app = require dirname(__DIR__, 4).'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (! $app->environment('testing') || \Illuminate\Support\Facades\DB::connection()->getDatabaseName() !== 'crm_mca_test') {
    throw new RuntimeException('Only the disposable test database is permitted');
}
\Illuminate\Support\Facades\Http::preventStrayRequests();
\Illuminate\Support\Facades\Http::fake(['*' => \Illuminate\Support\Facades\Http::response(['messages' => [['id' => 'wamid.stage8.fake']]], 200)]);
\Illuminate\Support\Facades\Mail::fake();
\Illuminate\Support\Facades\Notification::fake();
\Illuminate\Support\Facades\Queue::fake();
config(['followup.policy_enabled' => true, 'followup.execution_enabled' => true, 'social.advisor.autoreply_enabled' => false]);
app(\Modules\Core\Tenancy\CurrentInstitution::class)->set((int) $argv[1]);
$actor = \App\Models\User::query()->findOrFail((int) $argv[2]);
auth()->setUser($actor);
\Carbon\Carbon::setTestNow($argv[6]);
while (microtime(true) < (float) $argv[5]) {
    usleep(10000);
}
$id = (int) $argv[3];
$executor = app(\Modules\FollowUp\Services\FollowUpExecutor::class);
$aiCalls = 0;
$client = \Mockery::mock(\Modules\Ai\Services\AiChatClient::class);
$client->shouldReceive('chat')->andReturnUsing(function () use (&$aiCalls) {
    $aiCalls++;
    usleep(150000);

    return new \Modules\Ai\Services\AiChatResponse(json_encode([
        'recommended_action' => 'draft_message', 'commercial_objective' => 'Aclarar dudas', 'draft_text' => '¿Qué dudas te gustaría resolver?',
        'language' => 'es', 'reason_codes' => ['pending_question'], 'context_summary' => 'Consulta pendiente', 'source_references' => [], 'warnings' => [], 'requires_human_review' => true,
    ], JSON_THROW_ON_ERROR), 'qwen', 'qwen-plus', 40, 20, 10);
});
app()->instance(\Modules\Ai\Services\AiChatClient::class, $client);
switch ($argv[4]) {
    case 'request':
        app(\Modules\FollowUp\Services\FollowUpDraftService::class)->request($actor, $id, 0);
        break;
    case 'generate':
        $action = \Modules\FollowUp\Models\FollowUpAction::query()->findOrFail($id);
        if ($action->draft_reservation !== null) {
            app(\Modules\FollowUp\Services\FollowUpDraftService::class)->generate($id, $action->draft_reservation);
        }
        break;
    case 'approve':
        $executor->approve($actor, $id, 1);
        break;
    case 'execute':
        $executor->execute($id);
        break;
    case 'detect':
        app(\Modules\FollowUp\Services\OpportunityDetector::class)->detectConversation($id, now());
        break;
    case 'plan':
        app(\Modules\FollowUp\Services\FollowUpActionPlanner::class)->propose($actor, $id, 'whatsapp', 'requested_follow_up', now()->subMinute(), now()->addDay());
        break;
    case 'reconcile':
        $executor->reconcile($id);
        break;
    default:
        throw new RuntimeException('Unsupported test operation');
}
echo json_encode(['http_calls' => \Illuminate\Support\Facades\Http::recorded()->count(), 'ai_calls' => $aiCalls], JSON_THROW_ON_ERROR);
