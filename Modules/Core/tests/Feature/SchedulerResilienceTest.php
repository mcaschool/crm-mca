<?php

declare(strict_types=1);

use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Modules\Core\Scheduling\SchedulerHeartbeat;
use Modules\Core\Scheduling\SchedulerTick;
use Modules\Institutions\Models\Institution;
use Modules\Social\Support\AdvisorDispatcher;

/**
 * Resistencia del planificador: fallos y lentitud en un paso del tick, solapamiento de pasadas,
 * worker que sigue en marcha cuando llega el siguiente minuto.
 */
function sresEvent(string $needle): Event
{
    $event = collect(app(Schedule::class)->events())->first(fn (Event $e) => $e instanceof CallbackEvent
        ? str_contains((string) $e->description, $needle)
        : str_contains((string) $e->command, $needle));
    expect($event)->not->toBeNull();

    return $event;
}

/**
 * @param  array<string, array{0: array<string, mixed>, 1: bool}>  $steps
 * @return array<string, string>
 */
function sresRun(array $steps): array
{
    return (new ReflectionClass(SchedulerTick::class))->getMethod('run')->invoke(app(SchedulerTick::class), $steps);
}

beforeEach(function () {
    SchedulerHeartbeat::forget();
    Institution::factory()->create();

    // Comandos de prueba que simulan un paso roto, uno lento y uno con salida de error.
    Artisan::registerCommand(new class extends Command
    {
        protected $signature = 'valtest:boom';

        public function handle(): int
        {
            throw new RuntimeException('paso roto a propósito');
        }
    });
    Artisan::registerCommand(new class extends Command
    {
        protected $signature = 'valtest:slow {--seconds=2}';

        public function handle(): int
        {
            usleep((int) $this->option('seconds') * 1_000_000);

            return self::SUCCESS;
        }
    });
    Artisan::registerCommand(new class extends Command
    {
        protected $signature = 'valtest:fail';

        public function handle(): int
        {
            return self::FAILURE;
        }
    });
});

it('una excepción en un paso no impide los siguientes y la pasada siguiente vuelve a la normalidad', function () {
    $results = sresRun([
        'valtest:boom' => [[], true],
        'social:reconcile-deliveries' => [[], true],
        'followup:maintain' => [['--all-institutions' => true], true],
    ]);

    expect($results)->toBe(['valtest:boom' => 'error', 'social:reconcile-deliveries' => 'ok', 'followup:maintain' => 'ok']);

    // Siguiente pasada: todo normal y con latido.
    expect(app(SchedulerTick::class)->minute())->toBe(['social:reconcile-deliveries' => 'ok', 'followup:dispatch' => 'skipped'])
        ->and(SchedulerHeartbeat::running())->toBeTrue();
});

it('un paso lento de FollowUp retrasa pero no pierde los pasos posteriores', function () {
    $t0 = microtime(true);
    $results = sresRun([
        'valtest:slow' => [['--seconds' => 2], true],
        'followup:commercial-sync' => [['--all-institutions' => true], true],
        'social:reconcile-deliveries' => [[], true],
    ]);

    expect($results)->toBe(['valtest:slow' => 'ok', 'followup:commercial-sync' => 'ok', 'social:reconcile-deliveries' => 'ok'])
        ->and(microtime(true) - $t0)->toBeGreaterThanOrEqual(2.0);
});

it('una salida distinta de cero se registra sin abortar el resto', function () {
    expect(sresRun(['valtest:fail' => [[], true], 'social:reconcile-deliveries' => [[], true]]))
        ->toBe(['valtest:fail' => 'exit:1', 'social:reconcile-deliveries' => 'ok']);
});

it('el tick no se solapa consigo mismo: con el mutex tomado se omite y no deja latido; al liberarlo vuelve a correr', function () {
    $tick = sresEvent('latido del planificador');

    expect($tick->filtersPass(app()))->toBeTrue();
    expect($tick->mutex->create($tick))->toBeTrue();
    expect($tick->filtersPass(app()))->toBeFalse();

    Artisan::call('schedule:run');
    expect(SchedulerHeartbeat::lastSeen())->toBeNull('con el mutex tomado la pasada anterior "sigue en curso": no debe correr otra');

    $tick->mutex->forget($tick);
    Artisan::call('schedule:run');
    expect(SchedulerHeartbeat::running())->toBeTrue()
        ->and($tick->mutex->exists($tick))->toBeFalse('el mutex se libera al terminar');
});

it('el tick libera su mutex al terminar aunque un paso falle dentro', function () {
    $tick = sresEvent('latido del planificador');
    config(['followup.execution_enabled' => true]);

    $tick->run(app());

    expect($tick->mutex->exists($tick))->toBeFalse()->and(SchedulerHeartbeat::running())->toBeTrue();
});

it('mientras un worker sigue en marcha el planificador no lanza otro aunque haya trabajos', function () {
    config(['queue.default' => 'database']);
    DB::table('jobs')->insert(['queue' => AdvisorDispatcher::queue(), 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null, 'available_at' => now()->getTimestamp(), 'created_at' => now()->getTimestamp()]);
    $worker = sresEvent('social:advisor-worker');

    expect($worker->filtersPass(app()))->toBeTrue();
    $worker->mutex->create($worker);
    expect($worker->filtersPass(app()))->toBeFalse('withoutOverlapping(5): un solo worker a la vez');
    $worker->mutex->forget($worker);
    expect($worker->filtersPass(app()))->toBeTrue();
});

it('una comprobación when() que lanza excepción no aborta la pasada: se reporta y el comando arranca por precaución', function () {
    expect(\Modules\Core\Scheduling\SchedulerGuards::safe('prueba', fn () => throw new RuntimeException('BD caída')))->toBeTrue()
        ->and(\Modules\Core\Scheduling\SchedulerGuards::safe('prueba', fn () => false))->toBeFalse()
        ->and(\Modules\Core\Scheduling\SchedulerGuards::safe('prueba', fn () => 1))->toBeTrue();
});

it('los procesos bajo demanda se evalúan y lanzan antes que los ticks, para que un paso lento no los retrase', function () {
    $events = collect(app(Schedule::class)->events())->values();
    $firstTick = $events->search(fn (Event $e) => $e instanceof CallbackEvent);
    foreach (['social:advisor-worker', 'social:publish-scheduled', 'crm:agenda-reminders', 'social:meta-leads-poll', 'social:refresh-contact-profiles'] as $command) {
        $index = $events->search(fn (Event $e) => ! $e instanceof CallbackEvent && str_contains((string) $e->command, $command));
        expect($index)->toBeLessThan($firstTick, "{$command} debe definirse antes que los ticks");
    }
    // El TTL del mutex del tick de 5 min cubre su cadencia; el del worker cubre 55 s + un trabajo.
    $fiveMin = $events->first(fn (Event $e) => $e instanceof CallbackEvent && str_contains((string) $e->description, 'reconciliación comercial'));
    $worker = sresEvent('social:advisor-worker');
    expect($fiveMin->expiresAt)->toBeGreaterThanOrEqual(5)->and($worker->expiresAt)->toBe(3);
});

it('invariantes que impiden dos workers sobre el mismo trabajo: escucha ≤ 55 s y retry_after ≥ 90 s', function () {
    $definition = Artisan::all()['social:advisor-worker']->getDefinition();

    expect((int) $definition->getOption('max-time')->getDefault())->toBeLessThanOrEqual(55)
        ->and((int) config('queue.connections.database.retry_after'))->toBeGreaterThanOrEqual(90);
});
