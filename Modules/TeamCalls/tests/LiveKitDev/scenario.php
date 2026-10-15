<?php

declare(strict_types=1);

/*
| Fase 3B: maneja el MOTOR REAL de TeamCalls (CallService) contra el LiveKit efímero local.
| Uso (con el entorno aislado de README.md): php Modules/TeamCalls/tests/LiveKitDev/scenario.php <op> [json]
| Se niega a correr fuera de la BD crm_mca_teamcalls_lk.
*/

use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Institutions\Models\Institution;
use Modules\TeamCalls\Contracts\MediaRoomService;
use Modules\TeamCalls\Enums\CallMedia;
use Modules\TeamCalls\Exceptions\CallException;
use Modules\TeamCalls\Models\Call;
use Modules\TeamCalls\Models\CallEvent;
use Modules\TeamCalls\Models\CallParticipant;
use Modules\TeamCalls\Services\CallService;
use Modules\TeamCalls\Support\CallTarget;

$root = getcwd();
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (config('database.connections.mysql.database') !== 'crm_mca_teamcalls_lk') {
    fwrite(STDERR, "refused: wrong database\n");
    exit(2);
}
$op = $argv[1] ?? 'status';
$a = json_decode($argv[2] ?? '{}', true) ?: [];
$stateFile = __DIR__.'/client/tokens/state.json';
@mkdir(dirname($stateFile), 0700, true);
$state = is_file($stateFile) ? json_decode((string) file_get_contents($stateFile), true) : [];
$tokensDir = __DIR__.'/client/tokens';
@mkdir($tokensDir, 0700, true);
$svc = fn (): CallService => app(CallService::class);
$user = function (string $who) use ($state): User {
    app(CurrentInstitution::class)->set((int) $state['inst']);

    return User::query()->findOrFail((int) $state['users'][$who]);
};
$call = fn () => (string) ($a['call'] ?? $state['call']);

try {
    switch ($op) {
        case 'seed':
            $inst = Institution::factory()->create(['name' => 'LiveKit 3B (pruebas)']);
            app(CurrentInstitution::class)->set($inst->id);
            $users = [];
            foreach (['ana', 'beto', 'carla'] as $who) {
                $users[$who] = User::factory()->create(['institution_id' => $inst->id, 'role' => 'admissions', 'department' => 'Pruebas',
                    'status' => 'active', 'name' => ucfirst($who).' Prueba', 'email' => $who.'.'.uniqid().'@pruebas.invalid'])->id;
            }
            $state = ['inst' => $inst->id, 'users' => $users];
            $out = $state;
            break;
        case 'start':
            $to = array_map(fn ($w) => $state['users'][$w], (array) ($a['to'] ?? ['beto']));
            $c = $svc()->start($user($a['from'] ?? 'ana'), CallTarget::users($to), CallMedia::from($a['media'] ?? 'video'),
                'lk3b-'.bin2hex(random_bytes(6)), 'dev-'.($a['from'] ?? 'ana'));
            $state['call'] = $c->id;
            $out = ['call' => $c->id, 'room' => $c->room_name, 'status' => $c->status->value];
            break;
        case 'accept':
        case 'join':
            $who = $a['who'];
            $c = $svc()->{$op}($user($who), $call(), $a['device'] ?? 'dev-'.$who);
            $out = ['status' => $c->status->value];
            break;
        case 'credentials':
            $who = $a['who'];
            $device = $a['device'] ?? 'dev-'.$who;
            $cr = $svc()->credentials($user($who), $call(), $device);
            $file = $a['file'] ?? $who;
            file_put_contents($tokensDir.'/'.$file.'.json', json_encode(['url' => $cr->serverUrl, 'token' => $cr->token]));
            $out = ['identity' => $cr->identity, 'expires_at' => $cr->expiresAt->toIso8601String(), 'file' => $file];
            break;
        case 'revoke':
            $c = $svc()->revoke($user($a['by'] ?? 'ana'), $call(), (int) $state['users'][$a['who']]);
            $out = ['status' => $c->status->value];
            break;
        case 'leave':
        case 'end':
            $c = $svc()->{$op}($user($a['who']), $call());
            $out = ['status' => $c->status->value];
            break;
        case 'invite':
            $c = $svc()->invite($user($a['by'] ?? 'ana'), $call(), [(int) $state['users'][$a['who']]]);
            $out = ['status' => $c->status->value];
            break;
        case 'media':
            $out = $svc()->mediaParticipants($user($a['who'] ?? 'ana'), $call());
            break;
        case 'sweep':
            $out = app(\Modules\TeamCalls\Services\MediaSweeper::class)->sweep();
            break;
        case 'remove-raw':
            app(MediaRoomService::class)->removeParticipant((string) $a['room'], (string) $a['identity']);
            $out = ['ok' => true];
            break;
        case 'status':
        default:
            app(CurrentInstitution::class)->set((int) $state['inst']);
            $c = Call::query()->with('participants')->findOrFail($call());
            $out = [
                'status' => $c->status->value, 'end_reason' => $c->end_reason, 'room' => $c->room_name,
                'participants' => $c->participants->map(fn (CallParticipant $p) => [
                    'who' => array_search($p->user_id, $state['users'], true), 'state' => $p->state->value,
                    'media' => $p->media_state?->value, 'session' => $p->media_session, 'device' => $p->device_id,
                    'revoked' => $p->revoked_at !== null])->all(),
                'events' => CallEvent::query()->where('call_id', $c->id)->orderBy('created_at')->get()
                    ->map(fn (CallEvent $e) => $e->type.(isset($e->data['applied']) ? ($e->data['applied'] ? '+' : '-') : '').(! empty($e->data['rejected']) ? '!' : ''))->all(),
            ];
    }
} catch (CallException $e) {
    $out = ['error' => $e->reason, 'message' => $e->getMessage()];
}
file_put_contents($stateFile, json_encode($state));
echo json_encode($out, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), PHP_EOL;
