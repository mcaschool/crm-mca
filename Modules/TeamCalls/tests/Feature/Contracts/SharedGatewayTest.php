<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Modules\CollaborationCore\Authorization\Capabilities;
use Modules\CollaborationCore\Contracts\CallsGateway;
use Modules\CollaborationCore\Contracts\CollaborationAccess;
use Modules\CollaborationCore\Contracts\DeviceRegistry;
use Modules\CollaborationCore\Data\CallTarget;
use Modules\CollaborationCore\Data\CommandMetadata;
use Modules\CollaborationCore\Data\ConversationRef;
use Modules\CollaborationCore\Data\PageRequest;
use Modules\CollaborationCore\Enums\AuthenticationLevel;
use Modules\CollaborationCore\Enums\CallMedia;
use Modules\CollaborationCore\Enums\CallStatus;
use Modules\CollaborationCore\Enums\ConversationType;
use Modules\CollaborationCore\Enums\ParticipantState;
use Modules\CollaborationCore\Exceptions\ContractException;
use Modules\TeamCalls\Integration\Collaboration\SharedSummaries;
use Modules\TeamCalls\Support\ConversationRef as InternalRef;
use Modules\TeamCalls\Support\JoinCredentials;
use Modules\TeamCalls\Tests\Support\FakeCollaborationCore;
use Modules\TeamCalls\Tests\Support\TC;

/*
| Fase 4A · CallsGateway del contrato común sobre TeamCalls. Core se sustituye por un doble de sus
| interfaces oficiales (FakeCollaborationCore): se prueba el adaptador, no un Core real.
*/

function code(callable $fn): string
{
    try {
        $fn();
    } catch (ContractException $e) {
        return $e->errorCode;
    }

    return 'sin-error';
}

function cmd(string $key, string $correlation = 'corr-test-0001'): CommandMetadata
{
    return new CommandMetadata($key, $correlation);
}

beforeEach(function () {
    TC::fakes();
    $this->core = FakeCollaborationCore::bind();
    $this->inst = TC::institution();
    [$this->ana, $this->beto, $this->carla, $this->dani] = [TC::user($this->inst), TC::user($this->inst), TC::user($this->inst), TC::user($this->inst)];
    $this->a = FakeCollaborationCore::actor($this->ana, 'dev-ana-1');
    $this->b = FakeCollaborationCore::actor($this->beto, 'dev-beto-1');
    $this->c = FakeCollaborationCore::actor($this->carla, 'dev-carla-1');
    $this->gw = app(CallsGateway::class);
});

it('falla cerrado sin CollaborationCore: sin registro de dispositivos ni acceso, nadie entra', function () {
    app()->forgetInstance(DeviceRegistry::class);
    app()->offsetUnset(DeviceRegistry::class);
    expect(code(fn () => $this->gw->recentFor($this->a, new PageRequest)))->toBe('unauthenticated');

    FakeCollaborationCore::bind();
    app()->offsetUnset(CollaborationAccess::class);
    expect(code(fn () => $this->gw->recentFor($this->a, new PageRequest)))->toBe('unauthenticated');
});

it('identidad del servidor: valida institución, segundo factor, collaboration.use y dispositivo', function () {
    $other = TC::institution();
    app(\Modules\Core\Tenancy\CurrentInstitution::class)->set($this->inst->id);
    $foreign = FakeCollaborationCore::actor($this->ana, 'dev-ana-1', institutionId: $other->id);
    expect(code(fn () => $this->gw->recentFor($foreign, new PageRequest)))->toBe('forbidden')
        // La fábrica crea personas con 2FA: una sesión que solo superó la contraseña no basta.
        ->and(code(fn () => $this->gw->recentFor(FakeCollaborationCore::actor($this->ana, 'dev-ana-1', AuthenticationLevel::Password), new PageRequest)))->toBe('two_factor_required')
        ->and(code(fn () => $this->gw->recentFor(new \Modules\CollaborationCore\Data\ActorContext($this->inst->id, 999999, 'dev-x', 'sess-x', 'req-x', AuthenticationLevel::Mfa), new PageRequest)))->toBe('unauthenticated');

    $this->core->denied[$this->ana->id] = true;
    expect(code(fn () => $this->gw->recentFor($this->a, new PageRequest)))->toBe('forbidden');
    unset($this->core->denied[$this->ana->id]);

    $this->core->revokedDevices['dev-ana-1'] = true;
    expect(code(fn () => $this->gw->recentFor($this->a, new PageRequest)))->toBe('device_revoked');
    unset($this->core->revokedDevices['dev-ana-1']);

    $this->gw->recentFor($this->a, new PageRequest);
    expect(collect($this->core->authorized)->last())->toBe(['user' => $this->ana->id, 'capability' => Capabilities::USE]);

    // Las reglas propias de TeamCalls siguen: una cuenta inactiva no usa llamadas aunque Core la deje.
    $this->ana->forceFill(['status' => 'inactive'])->save();
    expect(code(fn () => $this->gw->recentFor($this->a, new PageRequest)))->toBe('forbidden');
});

it('ciclo completo con los tipos del contrato y sin datos internos (sin dispositivos ni sala)', function () {
    $s = $this->gw->start($this->a, CallTarget::users([$this->beto->id]), CallMedia::Audio, cmd('start-key-001'));
    expect($s)->toBeInstanceOf(\Modules\CollaborationCore\Data\CallSummary::class)
        ->and($s->status)->toBe(CallStatus::Ringing)->and($s->media)->toBe(CallMedia::Audio)
        ->and($s->viewerState)->toBe(ParticipantState::Accepted)
        ->and(collect($s->participants)->pluck('deviceId')->filter()->all())->toBe([]);
    expect($this->gw->start($this->a, CallTarget::users([$this->beto->id]), CallMedia::Audio, cmd('start-key-001'))->id)->toBe($s->id)
        ->and(code(fn () => $this->gw->start($this->a, CallTarget::users([$this->carla->id]), CallMedia::Audio, cmd('start-key-001'))))->toBe('idempotency_conflict');

    $this->gw->accept($this->b, $s->id, cmd('accept-key-01'));
    $this->gw->join($this->b, $s->id, cmd('join-key-0001'));
    $active = $this->gw->join($this->a, $s->id, cmd('join-key-0002'));
    expect($active->status)->toBe(CallStatus::Active)
        ->and($this->gw->credentials($this->b, $s->id)->serverUrl)->toBe('wss://media.test')
        ->and(code(fn () => $this->gw->credentials($this->c, $s->id)))->toBe('not_found')
        ->and($this->gw->find($this->b, $s->id)->id)->toBe($s->id)
        ->and(code(fn () => $this->gw->find($this->c, $s->id)))->toBe('not_found');

    $left = $this->gw->leave($this->b, $s->id, cmd('leave-key-001'));
    expect($left->viewerState)->toBe(ParticipantState::Left);
});

it('end = finalizar PARA TODOS: el anfitrión la termina; un miembro recibe forbidden y NO sale', function () {
    $s = $this->gw->start($this->a, CallTarget::users([$this->beto->id, $this->carla->id]), CallMedia::Video, cmd('start-key-002'));
    foreach ([$this->b, $this->c] as $actor) {
        $this->gw->accept($actor, $s->id, cmd('accept-'.$actor->userId.'-k'));
        $this->gw->join($actor, $s->id, cmd('join-'.$actor->userId.'-key'));
    }
    $this->gw->join($this->a, $s->id, cmd('join-host-key'));

    expect(code(fn () => $this->gw->end($this->b, $s->id, cmd('end-key-00001'))))->toBe('forbidden');
    $now = $this->gw->find($this->a, $s->id);
    expect($now->status)->toBe(CallStatus::Active)
        ->and(collect($now->participants)->firstWhere('userId', $this->beto->id)->state)->toBe(ParticipantState::Joined);

    $ended = $this->gw->end($this->a, $s->id, cmd('end-key-00002'));
    expect($ended->status)->toBe(CallStatus::Ended)->and($ended->endReason)->toBe('ended_by_host')
        // repetir es idempotente
        ->and($this->gw->end($this->a, $s->id, cmd('end-key-00002'))->status)->toBe(CallStatus::Ended);
});

it('invite: repetir el MISMO comando no vuelve a sonar a quien rechazó; la misma clave con otros invitados es conflicto', function () {
    $s = $this->gw->start($this->a, CallTarget::users([$this->beto->id]), CallMedia::Video, cmd('start-key-003'));
    $this->gw->accept($this->b, $s->id, cmd('accept-key-03'));
    $this->gw->join($this->b, $s->id, cmd('join-key-0003'));
    $this->gw->join($this->a, $s->id, cmd('join-key-0004'));

    $this->gw->invite($this->a, $s->id, [$this->carla->id], cmd('invite-key-01'));
    $this->gw->decline($this->c, $s->id, cmd('decline-key-1'));
    $ringsBefore = count(TC::$rec->incoming);

    $replay = $this->gw->invite($this->a, $s->id, [$this->carla->id], cmd('invite-key-01'));
    expect(collect($replay->participants)->firstWhere('userId', $this->carla->id)->state)->toBe(ParticipantState::Declined)
        ->and(count(TC::$rec->incoming))->toBe($ringsBefore)
        ->and(code(fn () => $this->gw->invite($this->a, $s->id, [$this->dani->id], cmd('invite-key-01'))))->toBe('idempotency_conflict');

    // Un comando NUEVO sí vuelve a invitar.
    $again = $this->gw->invite($this->a, $s->id, [$this->carla->id], cmd('invite-key-02'));
    expect(collect($again->participants)->firstWhere('userId', $this->carla->id)->state)->toBe(ParticipantState::Ringing);

    // Repetir revalida la autorización: si Core retira el permiso, la repetición también se deniega.
    $this->core->denied[$this->ana->id] = true;
    expect(code(fn () => $this->gw->invite($this->a, $s->id, [$this->carla->id], cmd('invite-key-02'))))->toBe('forbidden');
});

it('historial por cursor: páginas estables sin repetir filas; cursores manipulados o ajenos se rechazan', function () {
    $ids = [];
    foreach (range(1, 5) as $n) {
        $ids[] = $this->gw->start($this->a, CallTarget::users([$this->beto->id]), CallMedia::Audio, cmd('hist-key-00'.$n))->id;
        $this->gw->cancel($this->a, end($ids), cmd('cancel-key-0'.$n));
        $this->travel(1)->seconds();
    }

    $seen = [];
    $cursor = null;
    $pages = 0;
    do {
        $page = $this->gw->recentFor($this->a, new PageRequest($cursor, 2));
        $seen = array_merge($seen, array_map(fn ($s) => $s->id, $page->items));
        $cursor = $page->nextCursor;
        $pages++;
    } while ($cursor !== null && $pages < 10);
    expect($pages)->toBe(3)->and($seen)->toBe(array_reverse($ids));

    $first = $this->gw->recentFor($this->a, new PageRequest(null, 2));
    $tampered = substr($first->nextCursor, 0, -2).(str_ends_with($first->nextCursor, 'AA') ? 'BB' : 'AA');
    expect(code(fn () => $this->gw->recentFor($this->a, new PageRequest($tampered, 2))))->toBe('invalid')
        // de otra persona, o de otro listado: también inválido
        ->and(code(fn () => $this->gw->recentFor($this->b, new PageRequest($first->nextCursor, 2))))->toBe('invalid')
        ->and(code(fn () => $this->gw->missedFor($this->a, new PageRequest($first->nextCursor, 2))))->toBe('invalid')
        ->and(code(fn () => $this->gw->recentFor($this->a, new PageRequest('basura', 2))))->toBe('invalid');
    // Beto ve las mismas llamadas (participó), Carla ninguna.
    expect(count($this->gw->recentFor($this->b, new PageRequest(null, 10))->items))->toBe(5)
        ->and($this->gw->recentFor($this->c, new PageRequest(null, 10))->items)->toBe([]);
});

it('perdidas por cursor: solo las de la persona', function () {
    $s = $this->gw->start($this->a, CallTarget::users([$this->beto->id]), CallMedia::Audio, cmd('missed-key-01'));
    $this->travel(46)->seconds();
    $missed = $this->gw->missedFor($this->b, new PageRequest(null, 5));
    expect(array_map(fn ($x) => $x->id, $missed->items))->toBe([$s->id])->and($missed->nextCursor)->toBeNull()
        ->and($this->gw->missedFor($this->a, new PageRequest(null, 5))->items)->toBe([]);
});

it('conversación de grupo: solo suenan los invitados ELEGIDOS, validados todos o ninguno', function () {
    $ref = new InternalRef('group', 'grp-001');
    TC::$conversations->add($ref, $this->inst->id, [$this->ana->id, $this->beto->id, $this->carla->id, $this->dani->id]);
    $shared = new ConversationRef(ConversationType::Group, 'grp-001');

    $s = $this->gw->start($this->a, CallTarget::conversation($shared, [$this->carla->id]), CallMedia::Video, cmd('group-key-01'));
    expect(collect($s->participants)->pluck('userId')->sort()->values()->all())->toBe([$this->ana->id, $this->carla->id])
        ->and($s->conversation?->id)->toBe('grp-001');
    $this->gw->cancel($this->a, $s->id, cmd('group-cancel1'));

    $outsider = TC::user($this->inst);
    expect(code(fn () => $this->gw->start($this->a, CallTarget::conversation($shared, [$this->beto->id, $outsider->id]), CallMedia::Video, cmd('group-key-02'))))->toBe('forbidden')
        ->and(\Modules\TeamCalls\Models\Call::query()->where('idempotency_key', 'group-key-02')->exists())->toBeFalse();
    // El contrato no admite una conversación de grupo sin invitados elegidos.
    expect(fn () => CallTarget::conversation($shared, []))->toThrow(InvalidArgumentException::class);
});

it('credenciales: el contrato exige https/wss; un servidor de desarrollo ws:// no se rebaja (media_unavailable)', function () {
    $dev = new JoinCredentials('ws://127.0.0.1:7880', 'tok', 'u1.dev', Carbon::now()->addMinutes(5));
    expect(code(fn () => SharedSummaries::credentials($dev)))->toBe('media_unavailable')
        ->and(SharedSummaries::credentials(new JoinCredentials('wss://rtc.test', 'tok', 'u1.dev', Carbon::now()->addMinutes(5)))->serverUrl)->toBe('wss://rtc.test');
});

it('errores del motor → solo códigos públicos del contrato, con su estado HTTP fijo', function () {
    $e = null;
    try {
        $this->gw->find($this->a, '01JZZZZZZZZZZZZZZZZZZZZZZZ');
    } catch (ContractException $e) {
    }
    expect($e?->errorCode)->toBe('not_found')->and($e?->httpStatus)->toBe(404)
        ->and(code(fn () => $this->gw->start($this->a, CallTarget::users([$this->ana->id]), CallMedia::Audio, cmd('self-key-001'))))->toBe('invalid');
});
