<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Core\Scheduling\SchedulerHeartbeat;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Institutions\Models\Bot;
use Modules\Institutions\Models\Institution;
use Modules\Social\Livewire\AdvisorChannels;
use Modules\Social\Support\AdvisorDispatcher;
use Modules\Social\Support\AdvisorQueuePlan;
use Modules\Social\Support\AdvisorServiceStatus;

/** Fila cruda en `jobs` de la cola del asesor (no se ejecuta: solo se inspecciona). */
function aqpJob(int $availableIn, ?int $reservedAgo = null): void
{
    DB::table('jobs')->insert([
        'queue' => AdvisorDispatcher::queue(),
        'payload' => '{}',
        'attempts' => 0,
        'reserved_at' => $reservedAgo === null ? null : now()->subSeconds($reservedAgo)->getTimestamp(),
        'available_at' => now()->addSeconds($availableIn)->getTimestamp(),
        'created_at' => now()->getTimestamp(),
    ]);
}

beforeEach(function () {
    SchedulerHeartbeat::forget();
    config(['queue.default' => 'database', 'queue.connections.database.retry_after' => 90]);
});

it('con la cola vacía no hace falta worker', function () {
    $plan = AdvisorQueuePlan::inspect();

    expect($plan->total())->toBe(0)
        ->and($plan->needsWorker())->toBeFalse()
        ->and($plan->nextAvailableIn)->toBeNull()
        ->and($plan->maxTime(50))->toBe(50);
});

it('con trabajos ya disponibles el worker arranca y termina al vaciar la cola', function () {
    aqpJob(-30);
    aqpJob(0);
    $plan = AdvisorQueuePlan::inspect();

    expect($plan->ready)->toBe(2)
        ->and($plan->needsWorker())->toBeTrue()
        ->and($plan->stopWhenEmpty())->toBeTrue()
        ->and($plan->nextAvailableIn)->toBe(0)
        ->and($plan->maxTime(50))->toBe(50);
});

it('un trabajo retrasado dentro de la ventana fija la escucha justo hasta que venza más el margen', function () {
    aqpJob(20);
    $plan = AdvisorQueuePlan::inspect();

    expect($plan->delayed)->toBe(1)
        ->and($plan->needsWorker())->toBeTrue()
        ->and($plan->stopWhenEmpty())->toBeFalse()
        ->and($plan->lastInWindowIn)->toBeBetween(19, 20)
        ->and($plan->maxTime(50))->toBeBetween(24, 25)
        ->and($plan->maxTime(10))->toBe(10);
});

it('un trabajo retrasado más allá de la ventana no arranca el worker ahora', function () {
    aqpJob(600);
    $plan = AdvisorQueuePlan::inspect();

    expect($plan->delayed)->toBe(1)
        ->and($plan->needsWorker())->toBeFalse()
        ->and($plan->nextAvailableIn)->toBeBetween(599, 600);
});

it('un trabajo reservado por un worker caído cuenta a partir de retry_after', function () {
    aqpJob(-100, reservedAgo: 80); // vuelve a estar disponible en ~10 s
    $plan = AdvisorQueuePlan::inspect();

    expect($plan->reserved)->toBe(1)
        ->and($plan->needsWorker())->toBeTrue()
        ->and($plan->stopWhenEmpty())->toBeFalse()
        ->and($plan->lastInWindowIn)->toBeBetween(9, 10);

    DB::table('jobs')->delete();
    aqpJob(-100, reservedAgo: 5); // sigue reservado 85 s más: fuera de la ventana
    expect(AdvisorQueuePlan::inspect()->needsWorker())->toBeFalse();
});

it('el worker no arranca con la cola vacía y no deja latido propio; con --stop-when-empty conserva el comportamiento clásico', function () {
    Artisan::call('social:advisor-worker', ['--memory' => 0]);

    expect(Artisan::output())->toContain('no arranca')
        ->and(AdvisorDispatcher::workerRunning())->toBeFalse();

    Artisan::call('social:advisor-worker', ['--stop-when-empty' => true, '--memory' => 0]);
    expect(AdvisorDispatcher::workerRunning())->toBeTrue();
});

it('el despacho está listo con el latido del planificador aunque el worker no haya corrido', function () {
    config(['social.advisor.autoreply_enabled' => true]);
    expect(AdvisorDispatcher::ready())->toBeFalse()->and(AdvisorDispatcher::pendingReason())->toContain('todavía no está funcionando');

    SchedulerHeartbeat::touch();
    expect(AdvisorDispatcher::ready())->toBeTrue()->and(AdvisorDispatcher::pendingReason())->toBeNull()->and(AdvisorDispatcher::workerRunning())->toBeFalse();

    config(['social.advisor.autoreply_enabled' => false]);
    expect(AdvisorDispatcher::ready())->toBeFalse()->and(AdvisorDispatcher::pendingReason())->toContain('aún no están habilitadas');
});

it('el estado del servicio separa planificador, worker, interruptor y trabajos', function () {
    config(['social.advisor.autoreply_enabled' => false]);
    aqpJob(0);
    aqpJob(30);
    DB::table('failed_jobs')->insert(['uuid' => (string) \Illuminate\Support\Str::uuid(), 'connection' => 'database', 'queue' => AdvisorDispatcher::queue(), 'payload' => '{}', 'exception' => 'x', 'failed_at' => now()]);
    SchedulerHeartbeat::touch();

    $status = AdvisorServiceStatus::snapshot();

    expect($status['scheduler']['running'])->toBeTrue()
        ->and($status['worker']['recent'])->toBeFalse()
        ->and($status['worker']['listening'])->toBeFalse()
        ->and($status['worker']['stalled'])->toBeTrue('hay un trabajo listo y ningún worker reciente')
        ->and($status['autoreply_enabled'])->toBeFalse()
        ->and($status['ready'])->toBeFalse()
        ->and($status['jobs'])->toBe(['ready' => 1, 'delayed' => 1, 'reserved' => 0, 'failed' => 1, 'total' => 2]);

    AdvisorDispatcher::touch();
    expect(AdvisorServiceStatus::snapshot()['worker']['stalled'])->toBeFalse();
});

it('el worker escucha de forma continua solo con el interruptor general y alguna cuenta activa con el asesor', function () {
    $inst = Institution::factory()->create();
    app(CurrentInstitution::class)->set($inst->id);
    config(['social.advisor.autoreply_enabled' => true]);
    expect(AdvisorDispatcher::listeningRequired())->toBeFalse('sin cuentas');

    $bot = Bot::factory()->create(['status' => 'active']);
    $channel = \Modules\Social\Models\SocialChannel::factory()->create(['advisor_enabled' => true, 'advisor_bot_id' => $bot->id, 'is_active' => true]);
    expect(AdvisorDispatcher::listeningRequired())->toBeTrue();

    // El planificador lanza el worker cada minuto aunque la cola esté vacía…
    $event = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
        ->first(fn ($e) => ! $e instanceof \Illuminate\Console\Scheduling\CallbackEvent && str_contains((string) $e->command, 'social:advisor-worker'));
    expect($event->filtersPass(app()))->toBeTrue();

    // …y deja de hacerlo si la cuenta se desactiva, se apaga su asesor o se apaga el interruptor general.
    $channel->forceFill(['is_active' => false])->save();
    expect(AdvisorDispatcher::listeningRequired())->toBeFalse()->and($event->filtersPass(app()))->toBeFalse();
    $channel->forceFill(['is_active' => true, 'advisor_enabled' => false])->save();
    expect(AdvisorDispatcher::listeningRequired())->toBeFalse();
    $channel->forceFill(['advisor_enabled' => true])->save();
    config(['social.advisor.autoreply_enabled' => false]);
    expect(AdvisorDispatcher::listeningRequired())->toBeFalse()->and($event->filtersPass(app()))->toBeFalse();
    config(['queue.default' => 'sync', 'social.advisor.autoreply_enabled' => true]);
    expect(AdvisorDispatcher::listeningRequired())->toBeFalse('con cola sync no hay worker');
});

it('el panel de canales del asesor muestra el estado del servicio sin exigir un worker activo', function () {
    $inst = Institution::factory()->create();
    $admin = User::factory()->create(['institution_id' => $inst->id, 'role' => 'admin']);
    app(CurrentInstitution::class)->set($inst->id);
    $bot = Bot::factory()->create(['status' => 'active']);
    config(['social.advisor.autoreply_enabled' => true]);
    SchedulerHeartbeat::touch();

    Livewire::actingAs($admin)->test(AdvisorChannels::class, ['botId' => $bot->id])
        ->assertSee('Estado del servicio')
        ->assertSee('Planificador del servidor')
        ->assertSee('En marcha')
        ->assertSee('Arranca solo cuando hay trabajos en la cola.')
        ->assertSee('Habilitado')
        ->assertDontSee('advisor-channels-not-ready');
});
