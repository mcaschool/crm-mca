<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Modules\Core\Scheduling\SchedulerHeartbeat;
use Modules\Core\Scheduling\SchedulerTick;
use Modules\Institutions\Models\Institution;
use Modules\Social\Support\AdvisorDispatcher;

/**
 * Planificador «un cron, un proceso por minuto, trabajo pesado bajo demanda»:
 *  - el tick corre en el proceso de schedule:run, deja el latido y respeta los interruptores;
 *  - los procesos propios (worker, publicador, agenda, Meta) solo se lanzan si hay trabajo.
 */
function stickEvents(): \Illuminate\Support\Collection
{
    return collect(app(Schedule::class)->events());
}

function stickCommand(string $needle): Event
{
    $event = stickEvents()->first(fn (Event $e) => ! $e instanceof CallbackEvent && str_contains((string) $e->command, $needle));
    expect($event)->not->toBeNull();

    return $event;
}

beforeEach(function () {
    SchedulerHeartbeat::forget();
    Institution::factory()->create();
});

it('el latido del planificador caduca a los 3 minutos y se puede renovar', function () {
    expect(SchedulerHeartbeat::running())->toBeFalse()->and(SchedulerHeartbeat::lastSeen())->toBeNull();

    SchedulerHeartbeat::touch();
    expect(SchedulerHeartbeat::running())->toBeTrue();

    $this->travel(4)->minutes();
    expect(SchedulerHeartbeat::running())->toBeFalse()->and(SchedulerHeartbeat::lastSeen())->not->toBeNull();
});

it('el tick de cada minuto deja el latido, reconcilia salientes y respeta el interruptor de ejecución de seguimientos', function () {
    config(['followup.execution_enabled' => false]);

    $results = app(SchedulerTick::class)->minute();

    expect(SchedulerHeartbeat::running())->toBeTrue()
        ->and($results)->toBe(['social:reconcile-deliveries' => 'ok', 'followup:dispatch' => 'skipped']);

    config(['followup.execution_enabled' => true]);
    expect(app(SchedulerTick::class)->minute()['followup:dispatch'])->toBe('ok');
});

it('el tick de cinco minutos mantiene FollowUp con la misma cadencia y solo detecta si está habilitado', function () {
    config(['followup.detection_enabled' => false]);
    expect(app(SchedulerTick::class)->fiveMinutes())->toBe([
        'followup:detect' => 'skipped',
        'followup:maintain' => 'ok',
        'followup:commercial-sync' => 'ok',
    ]);

    config(['followup.detection_enabled' => true]);
    expect(app(SchedulerTick::class)->fiveMinutes()['followup:detect'])->toBe('ok');
});

it('un paso que falla se reporta y no impide los siguientes', function () {
    $run = (new ReflectionClass(SchedulerTick::class))->getMethod('run');
    $results = $run->invoke(app(SchedulerTick::class), [
        'comando:inexistente' => [[], true],
        'social:reconcile-deliveries' => [[], true],
    ]);

    expect($results)->toBe(['comando:inexistente' => 'error', 'social:reconcile-deliveries' => 'ok']);
});

it('la definición del planificador tiene los ticks en proceso y los procesos propios condicionados', function () {
    $callbacks = stickEvents()->filter(fn (Event $e) => $e instanceof CallbackEvent)->map(fn (CallbackEvent $e) => (string) $e->description)->values();

    expect($callbacks->filter(fn (string $d) => str_contains($d, 'latido del planificador'))->count())->toBe(1)
        ->and($callbacks->filter(fn (string $d) => str_contains($d, 'reconciliación comercial'))->count())->toBe(1);

    foreach (['social:advisor-worker', 'social:publish-scheduled', 'crm:agenda-reminders', 'social:meta-leads-poll', 'social:refresh-contact-profiles'] as $command) {
        expect(stickCommand($command)->filtersPass(app()))->toBeFalse("{$command} no debe arrancar sin trabajo");
    }

    // Los comandos de FollowUp y la reconciliación ya no son procesos propios.
    foreach (['followup:dispatch', 'followup:detect', 'followup:maintain', 'followup:commercial-sync', 'social:reconcile-deliveries'] as $command) {
        expect(stickEvents()->first(fn (Event $e) => ! $e instanceof CallbackEvent && str_contains((string) $e->command, $command)))->toBeNull("{$command} no debe tener proceso propio");
    }
});

it('el worker solo se lanza cuando la cola del asesor tiene trabajos ejecutables en la ventana', function () {
    config(['queue.default' => 'database']);
    $event = stickCommand('social:advisor-worker');

    expect($event->filtersPass(app()))->toBeFalse();

    DB::table('jobs')->insert(['queue' => AdvisorDispatcher::queue(), 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null, 'available_at' => now()->addMinutes(10)->getTimestamp(), 'created_at' => now()->getTimestamp()]);
    expect($event->filtersPass(app()))->toBeFalse('un trabajo retrasado 10 minutos no justifica arrancar ahora');

    DB::table('jobs')->insert(['queue' => AdvisorDispatcher::queue(), 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null, 'available_at' => now()->addSeconds(20)->getTimestamp(), 'created_at' => now()->getTimestamp()]);
    expect($event->filtersPass(app()))->toBeTrue('un trabajo que vence dentro de la ventana sí');

    config(['queue.default' => 'sync']);
    expect($event->filtersPass(app()))->toBeFalse('con cola sync no hay despacho persistente');
});
