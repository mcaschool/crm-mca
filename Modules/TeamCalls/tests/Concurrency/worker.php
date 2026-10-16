<?php

declare(strict_types=1);

/*
| Proceso PHP independiente, con su PROPIA conexión a MySQL, para las pruebas de concurrencia real de
| TeamCalls (tests/Feature/CallRealConcurrencyTest.php). Arranca la aplicación, espera a la barrera
| («at») para actuar a la vez que los demás procesos y escribe una línea JSON con el resultado.
|
| Uso: php worker.php <operación> '<json>'. Se niega a funcionar fuera de APP_ENV=testing o contra una
| base de datos que no termine en «_test».
*/

use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Institutions\Models\Institution;
use Modules\TeamCalls\Enums\CallMedia;
use Modules\TeamCalls\Exceptions\CallException;
use Modules\TeamCalls\Services\CallService;
use Modules\TeamCalls\Support\CallTarget;

$root = dirname(__DIR__, 4);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$op = (string) ($argv[1] ?? '');
/** @var array<string,mixed> $args */
$args = json_decode((string) ($argv[2] ?? '{}'), true) ?: [];

$database = (string) config('database.connections.'.config('database.default').'.database');
if (! app()->environment('testing') || ! str_ends_with($database, '_test')) {
    fwrite(STDERR, "refused: not a test environment/database\n");
    exit(2);
}
// Motor aislado como en TC::fakes(): los call.* (dispositivos ficticios) a un outbox de prueba; el resto, al real.
\Modules\TeamCalls\Tests\Support\FakeOutbox::bind();

$late = false;
if (isset($args['at'])) {
    $late = microtime(true) > (float) $args['at'];
    while (microtime(true) < (float) $args['at']) {
        usleep(100);
    }
}
$actedAt = microtime(true);

$service = fn (): CallService => app(CallService::class);
$actor = function (array $args): User {
    app(CurrentInstitution::class)->set((int) $args['inst']);

    return User::query()->findOrFail((int) $args['user']);
};

$extra = [];
try {
    $result = match ($op) {
        'ping' => ['ok' => true],
        'seed' => (function () use ($args): array {
            $institution = Institution::factory()->create();
            app(CurrentInstitution::class)->set($institution->id);
            $ids = [];
            foreach (range(1, (int) ($args['users'] ?? 2)) as $i) {
                $ids[] = User::factory()->create(['institution_id' => $institution->id, 'role' => 'admissions',
                    'department' => 'Admisiones', 'status' => 'active'])->id;
            }

            return ['ok' => true, 'inst' => $institution->id, 'users' => $ids];
        })(),
        'start' => ['ok' => true, 'call' => $service()->start($actor($args), CallTarget::users(array_map('intval', (array) $args['to'])),
            CallMedia::Audio, (string) $args['key'], 'dev-'.$args['user'])->id],
        // Entrelazado DETERMINISTA: justo después de que este inicio lea la clave de idempotencia sin bloqueo,
        // otro proceso (otra conexión MySQL) ejecuta y CONFIRMA un inicio de la misma persona (misma clave o
        // `competing_key`); luego este continúa. Reproduce la ventana entre esa lectura y la decisión «busy».
        'start_interleaved' => (function () use ($args, $service, $actor, &$extra): array {
            $user = $actor($args);
            $extra = ['interleaved' => false, 'competing' => null];
            DB::listen(function ($query) use ($args, &$extra): void {
                if ($extra['interleaved'] || ! str_contains($query->sql, 'idempotency_key') || stripos($query->sql, 'for update') !== false) {
                    return;
                }
                $extra['interleaved'] = true;
                $payload = json_encode(['inst' => $args['inst'], 'user' => $args['user'], 'to' => $args['to'],
                    'key' => $args['competing_key'] ?? $args['key']]);
                $proc = proc_open([PHP_BINARY, __FILE__, 'start', $payload], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, getcwd());
                $out = (string) stream_get_contents($pipes[1]);
                stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($proc);
                $lines = array_values(array_filter(array_map('trim', explode("\n", $out))));
                $extra['competing'] = json_decode((string) end($lines), true);
            });

            return ['ok' => true, 'call' => $service()->start($user, CallTarget::users(array_map('intval', (array) $args['to'])),
                CallMedia::Audio, (string) $args['key'], 'dev-'.$args['user'])->id];
        })(),
        'accept' => ['ok' => true, 'call' => $service()->accept($actor($args), (string) $args['call'], (string) $args['device'])->id],
        'decline' => ['ok' => true, 'call' => $service()->decline($actor($args), (string) $args['call'])->id],
        'cancel' => ['ok' => true, 'call' => $service()->cancel($actor($args), (string) $args['call'])->id],
        // Webhook ya verificado del servidor de medios (lo que haría MediaEventIngestor).
        'media' => (function () use ($args, $service): array {
            app(CurrentInstitution::class)->set((int) $args['inst']);
            $service()->applyMediaEvent((string) $args['call'], (string) $args['event'], (string) $args['kind'],
                (int) $args['user'], (string) $args['device'], (string) $args['session']);

            return ['ok' => true];
        })(),
        'cleanup' => (function () use ($args): array {
            $inst = (int) $args['inst'];
            foreach (['collab_call_events', 'collab_call_participants', 'collab_call_calls', 'users'] as $table) {
                DB::table($table)->where('institution_id', $inst)->delete();
            }
            DB::table('institutions')->where('id', $inst)->delete();

            return ['ok' => true];
        })(),
        default => throw new InvalidArgumentException('unknown op '.$op),
    };
} catch (CallException $e) {
    $result = ['ok' => false, 'reason' => $e->reason];
} catch (Throwable $e) {
    $result = ['ok' => false, 'reason' => 'error', 'error' => class_basename($e).': '.mb_substr($e->getMessage(), 0, 300)];
}

echo json_encode($result + ($extra ?? []) + ['late' => $late, 'acted_at' => $actedAt]).PHP_EOL;
