<?php

declare(strict_types=1);

require dirname(__DIR__, 4).'/vendor/autoload.php';
$app = require dirname(__DIR__, 4).'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
set_exception_handler(function (Throwable $error): never {
    fwrite(STDERR, $error->getMessage().PHP_EOL);
    exit(1);
});
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

if (! $app->environment('testing') || DB::connection()->getDatabaseName() !== 'crm_mca_test') {
    throw new RuntimeException('Disposable crm_mca_test required');
}
Http::preventStrayRequests();
\Illuminate\Support\Facades\Mail::fake();
\Illuminate\Support\Facades\Notification::fake();
\Illuminate\Support\Facades\Queue::fake();
config(['followup.execution_enabled' => false]);
if (Artisan::call('migrate:fresh', ['--force' => true]) !== 0) {
    throw new RuntimeException(Artisan::output());
}
$institution = \Modules\Institutions\Models\Institution::factory()->create();
app(\Modules\Core\Tenancy\CurrentInstitution::class)->set($institution->id);
$contact = \Modules\Crm\Models\Contact::factory()->create(['first_name' => 'Migración ficticia']);
$before = $contact->fresh()->getAttributes();
$files = glob(base_path('Modules/FollowUp/database/migrations/*.php'));
sort($files);
foreach (array_reverse($files) as $file) {
    (require $file)->down();
    DB::table('migrations')->where('migration', basename($file, '.php'))->delete();
}
if (Schema::hasTable('follow_up_opportunities') || $contact->fresh()->getAttributes() !== $before) {
    throw new RuntimeException('Rollback or unrelated CRM preservation failed');
}
foreach ($files as $file) {
    if (Artisan::call('migrate', ['--path' => $file, '--realpath' => true, '--force' => true]) !== 0) {
        throw new RuntimeException(Artisan::output());
    }
    if ($contact->fresh()->getAttributes() !== $before) {
        throw new RuntimeException('CRM data changed');
    }
    echo basename($file)." OK\n";
}
if (! Schema::hasColumn('follow_up_actions', 'approved_content_hash') || ! Schema::hasColumn('follow_up_opportunities', 'commercial_version') || ! Schema::hasTable('follow_up_analytics_facts')) {
    throw new RuntimeException('Incomplete schema');
}
if (DB::table('follow_up_execution_switches')->count() !== 0) {
    throw new RuntimeException('Unsafe enabled switches');
}
if (! Schema::hasColumn('follow_up_settings', 'whatsapp_cadence') || ! Schema::hasColumn('follow_up_advisor_settings', 'whatsapp_cadence') || ! Schema::hasColumn('follow_up_actions', 'cadence_key')) {
    throw new RuntimeException('Incomplete WhatsApp cadence schema');
}
echo "Clean install, reverse rollback, incremental reinstall and existing CRM preservation: PASS\n";
