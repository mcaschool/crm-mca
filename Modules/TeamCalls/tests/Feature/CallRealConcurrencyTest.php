<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

/*
| Concurrencia REAL: varios procesos PHP independientes (cada uno con su propia conexión MySQL) actúan
| sobre la misma llamada en el mismo instante (barrera de tiempo). Los datos se crean y se confirman
| desde un proceso aparte, porque la transacción de RefreshDatabase de esta prueba no es visible para
| otras conexiones; la verificación se hace con una conexión nueva («tc_probe») y al final se borra
| lo creado. Si el entorno no permite lanzar procesos o no es MySQL, la prueba se OMITE (no se da por
| validada).
*/

const TC_WORKER = __DIR__.'/../Concurrency/worker.php';

beforeEach(function () {
    if (! function_exists('proc_open') || DB::connection()->getDriverName() !== 'mysql') {
        $this->markTestSkipped('Concurrencia real no disponible en este entorno (requiere proc_open y MySQL).');
    }
    config(['database.connections.tc_probe' => config('database.connections.'.config('database.default'))]);
    $this->seeded = [];
});

afterEach(function () {
    foreach ($this->seeded ?? [] as $inst) {
        tcRun([['cleanup', ['inst' => $inst]]], barrier: false);
    }
    DB::purge('tc_probe');
});

/**
 * Lanza los procesos A LA VEZ y devuelve el resultado de cada uno (en el orden de $jobs).
 *
 * @param  list<array{0:string, 1:array<string,mixed>}>  $jobs
 * @return list<array<string,mixed>>
 */
function tcRun(array $jobs, bool $barrier = true): array
{
    $connection = config('database.connections.'.config('database.default'));
    $env = array_merge(getenv(), [
        'APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_URL' => '',
        'DB_HOST' => (string) $connection['host'], 'DB_PORT' => (string) $connection['port'],
        'DB_DATABASE' => (string) $connection['database'], 'DB_USERNAME' => (string) $connection['username'],
        'DB_PASSWORD' => (string) $connection['password'], 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array',
        'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array', 'BROADCAST_CONNECTION' => 'null',
    ]);
    // Margen para que TODOS los procesos terminen de arrancar antes de la barrera.
    $at = microtime(true) + 4 + count($jobs) * 0.6;

    $procs = [];
    foreach ($jobs as $i => [$op, $args]) {
        $payload = json_encode($barrier ? $args + ['at' => $at] : $args);
        $procs[$i] = [proc_open([PHP_BINARY, TC_WORKER, $op, $payload], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $env), $pipes];
    }
    $results = [];
    foreach ($procs as $i => [$proc, $pipes]) {
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);
        $lines = array_values(array_filter(array_map('trim', explode("\n", $out))));
        $results[$i] = json_decode((string) end($lines), true) ?? ['ok' => false, 'reason' => 'crash', 'error' => mb_substr($err.$out, 0, 500)];
    }
    if ($barrier) {
        expect(array_column($results, 'late'))->each->toBeFalse(); // todos esperaron a la barrera: actuaron a la vez
    }

    return $results;
}

/** @return array{inst:int, users:list<int>} */
function tcSeed(object $test, int $users): array
{
    $seed = tcRun([['seed', ['users' => $users]]], barrier: false)[0];
    expect($seed['ok'])->toBeTrue(json_encode($seed));
    $test->seeded[] = $seed['inst'];

    return ['inst' => (int) $seed['inst'], 'users' => array_map('intval', $seed['users'])];
}

function tcProbe(string $table): \Illuminate\Database\Query\Builder
{
    return DB::connection('tc_probe')->table($table);
}

/** @return array<string,int> */
function tcReasons(array $results): array
{
    $reasons = array_map(fn (array $r) => $r['ok'] ? 'ok' : (string) $r['reason'], $results);
    $counts = array_count_values($reasons);
    ksort($counts);

    return $counts;
}

it('reintentos simultáneos con la misma clave: una sola llamada para todos', function () {
    ['inst' => $inst, 'users' => [$ana, $beto]] = tcSeed($this, 2);

    $results = tcRun(array_fill(0, 6, ['start', ['inst' => $inst, 'user' => $ana, 'to' => [$beto], 'key' => 'race-same-key-01']]));

    expect(tcReasons($results))->toBe(['ok' => 6])
        ->and(array_unique(array_column($results, 'call')))->toHaveCount(1)
        ->and(tcProbe('collab_call_calls')->where('institution_id', $inst)->count())->toBe(1)
        ->and(tcProbe('collab_call_events')->where('institution_id', $inst)->where('type', 'call.started')->count())->toBe(1)
        ->and(tcProbe('collab_call_participants')->where('institution_id', $inst)->count())->toBe(2);
});

it('inicios simultáneos de la misma persona hacia distintas personas: solo uno gana, el resto «busy»', function () {
    ['inst' => $inst, 'users' => $u] = tcSeed($this, 5);
    $jobs = [];
    foreach ([1, 2, 3, 4] as $i) {
        $jobs[] = ['start', ['inst' => $inst, 'user' => $u[0], 'to' => [$u[$i]], 'key' => 'race-busy-key-0'.$i]];
    }

    $results = tcRun($jobs);

    expect(tcReasons($results))->toBe(['busy' => 3, 'ok' => 1])
        ->and(tcProbe('collab_call_calls')->where('institution_id', $inst)->count())->toBe(1);
});

it('contestar a la vez desde varios dispositivos: una sola aceptación, el resto answered_elsewhere', function () {
    ['inst' => $inst, 'users' => [$ana, $beto]] = tcSeed($this, 2);
    $call = tcRun([['start', ['inst' => $inst, 'user' => $ana, 'to' => [$beto], 'key' => 'race-devices-01']]], barrier: false)[0]['call'];

    $jobs = [];
    foreach (['movil', 'pc', 'tablet', 'portatil', 'reloj'] as $device) {
        $jobs[] = ['accept', ['inst' => $inst, 'user' => $beto, 'call' => $call, 'device' => 'beto-'.$device]];
    }
    $results = tcRun($jobs);

    $winner = array_values(array_filter($results, fn (array $r) => $r['ok']));
    $participant = tcProbe('collab_call_participants')->where('call_id', $call)->where('user_id', $beto)->first();
    expect(tcReasons($results))->toBe(['answered_elsewhere' => 4, 'ok' => 1])
        ->and($participant->state)->toBe('accepted')
        ->and($jobs[array_search($winner[0], $results, true)][1]['device'])->toBe($participant->device_id)
        ->and(tcProbe('collab_call_events')->where('call_id', $call)->where('type', 'call.accepted')->count())->toBe(1)
        ->and(tcProbe('collab_call_calls')->where('id', $call)->value('status'))->toBe('connecting');
});

it('cancelar y contestar en el mismo instante: gana uno y el estado queda coherente', function () {
    ['inst' => $inst, 'users' => $u] = tcSeed($this, 8);

    foreach ([[0, 1], [2, 3], [4, 5], [6, 7]] as $round => [$caller, $callee]) {
        $call = tcRun([['start', ['inst' => $inst, 'user' => $u[$caller], 'to' => [$u[$callee]], 'key' => 'race-cancel-0'.$round]]], barrier: false)[0]['call'];

        [$cancel, $accept] = tcRun([
            ['cancel', ['inst' => $inst, 'user' => $u[$caller], 'call' => $call]],
            ['accept', ['inst' => $inst, 'user' => $u[$callee], 'call' => $call, 'device' => 'callee-dev']],
        ]);

        $status = tcProbe('collab_call_calls')->where('id', $call)->value('status');
        $calleeState = tcProbe('collab_call_participants')->where('call_id', $call)->where('user_id', $u[$callee])->value('state');
        expect([$cancel['ok'], $accept['ok']])->toContain(true)->and($cancel['ok'] && $accept['ok'])->toBeFalse();
        if ($cancel['ok']) {
            expect($accept['reason'])->toBe('invalid_transition')->and($status)->toBe('cancelled')->and($calleeState)->toBe('cancelled');
        } else {
            expect($cancel['reason'])->toBe('invalid_transition')->and($status)->toBe('connecting')->and($calleeState)->toBe('accepted');
        }
        expect(tcProbe('collab_call_events')->where('call_id', $call)->whereIn('type', ['call.accepted', 'call.ended'])->count())->toBe(1);
    }
});

it('en una llamada grupal, contestar y rechazar a la vez no pierde ninguna respuesta', function () {
    ['inst' => $inst, 'users' => $u] = tcSeed($this, 5);
    $call = tcRun([['start', ['inst' => $inst, 'user' => $u[0], 'to' => [$u[1], $u[2], $u[3], $u[4]], 'key' => 'race-group-01']]], barrier: false)[0]['call'];

    $results = tcRun([
        ['accept', ['inst' => $inst, 'user' => $u[1], 'call' => $call, 'device' => 'd1']],
        ['accept', ['inst' => $inst, 'user' => $u[2], 'call' => $call, 'device' => 'd2']],
        ['decline', ['inst' => $inst, 'user' => $u[3], 'call' => $call]],
        ['decline', ['inst' => $inst, 'user' => $u[4], 'call' => $call]],
    ]);

    $states = tcProbe('collab_call_participants')->where('call_id', $call)->pluck('state', 'user_id')->all();
    expect(tcReasons($results))->toBe(['ok' => 4])
        ->and([$states[$u[1]], $states[$u[2]], $states[$u[3]], $states[$u[4]]])->toBe(['accepted', 'accepted', 'declined', 'declined'])
        ->and(tcProbe('collab_call_calls')->where('id', $call)->value('status'))->toBe('connecting')
        ->and(tcProbe('collab_call_events')->where('call_id', $call)->where('type', 'call.accepted')->count())->toBe(2)
        ->and(tcProbe('collab_call_events')->where('call_id', $call)->where('type', 'call.declined')->count())->toBe(2);
});
