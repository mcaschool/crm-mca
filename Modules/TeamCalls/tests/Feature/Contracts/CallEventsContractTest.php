<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Modules\CollaborationCore\Contracts\CallsGateway;
use Modules\CollaborationCore\Data\CallTarget;
use Modules\CollaborationCore\Data\CommandMetadata;
use Modules\CollaborationCore\Enums\CallMedia;
use Modules\CollaborationRealtime\Data\EventEnvelope;
use Modules\TeamCalls\Enums\CallMedia as InternalMedia;
use Modules\TeamCalls\Integration\Collaboration\CallEventAccess;
use Modules\TeamCalls\Integration\Collaboration\CallEventEnvelopes;
use Modules\TeamCalls\Models\Call;
use Modules\TeamCalls\Models\CallEvent;
use Modules\TeamCalls\Support\CallTarget as InternalTarget;
use Modules\TeamCalls\Tests\Support\FakeCollaborationCore;
use Modules\TeamCalls\Tests\Support\FakeOutbox;
use Modules\TeamCalls\Tests\Support\TC;

/*
| Fase 4A · eventos call.* compatibles con CollaborationRealtime: id único, institución, agregado con
| versión secuencial, correlación/causalidad, audiencia autorizada y anotación TRANSACCIONAL en el outbox
| (doble de su interfaz oficial). Sin outbox enlazado sigue el puerto propio de TeamCalls; nunca ambos.
*/

beforeEach(function () {
    TC::fakes();
    $this->core = FakeCollaborationCore::bind();
    $this->inst = TC::institution();
    [$this->ana, $this->beto, $this->carla] = [TC::user($this->inst), TC::user($this->inst), TC::user($this->inst)];
    $this->a = FakeCollaborationCore::actor($this->ana, 'dev-ana-1');
    $this->b = FakeCollaborationCore::actor($this->beto, 'dev-beto-1');
    $this->gw = app(CallsGateway::class);
});

it('con outbox: cada hecho va al outbox DENTRO de la transacción, con versión contigua y la correlación del comando; sin publicación duplicada', function () {
    $outbox = FakeOutbox::bind();
    $s = $this->gw->start($this->a, CallTarget::users([$this->beto->id]), CallMedia::Video, new CommandMetadata('start-key-ev1', 'corr-start-1'));
    $this->gw->accept($this->b, $s->id, new CommandMetadata('accept-key-e1', 'corr-accept-1'));

    $events = $outbox->events($s->id);
    expect(count($events))->toBeGreaterThanOrEqual(3)
        ->and(array_map(fn (EventEnvelope $e) => $e->aggregateVersion, $events))->toBe(range(1, count($events)))
        ->and(collect($events)->every(fn (EventEnvelope $e) => $e->aggregateType === 'call' && $e->aggregateId === $s->id
            && $e->institutionId === $this->inst->id && $e->version === 1))->toBeTrue()
        ->and(count(array_unique(array_map(fn (EventEnvelope $e) => $e->id, $events))))->toBe(count($events))
        ->and(collect($outbox->appended)->every(fn (array $a) => $a['level'] > 0))->toBeTrue()
        // nada por el puerto propio: sin duplicados
        ->and(TC::$rec->published)->toBe([]);

    $byType = collect($events)->groupBy(fn (EventEnvelope $e) => $e->type);
    expect($byType['call.started'][0]->correlationId)->toBe('corr-start-1')
        ->and($byType['call.started'][0]->causationId)->toBe('req-dev-ana-1')
        ->and($byType['call.accepted'][0]->correlationId)->toBe('corr-accept-1')
        ->and($byType['call.accepted'][0]->actorId)->toBe($this->beto->id);

    // Audiencia: el timbre solo a quien suena y caduca con la invitación; «contestada en otro sitio», a la
    // misma persona salvo el dispositivo que contestó; el resto, a los participantes.
    $ringing = collect($outbox->appended)->first(fn (array $a) => $a['event']->type === 'call.ringing');
    $started = collect($outbox->appended)->first(fn (array $a) => $a['event']->type === 'call.started');
    expect($started['audience']->userIds)->toBe(collect([$this->ana->id, $this->beto->id])->sort()->values()->all())
        ->and($ringing['audience']->userIds)->toBe([$this->beto->id])
        ->and($ringing['audience']->expiresAt)->not->toBeNull();
    $elsewhere = collect($outbox->appended)->first(fn (array $a) => $a['event']->type === 'call.answered_elsewhere');
    expect($elsewhere['audience']->userIds)->toBe([$this->beto->id])->and($elsewhere['audience']->exceptDeviceId)->toBe('dev-beto-1')
        ->and(app(CallEventAccess::class)->ephemeral($elsewhere['event']))->toBeTrue();

    // La traza propia guarda la misma versión y correlación (las efímeras no se persisten).
    $rows = CallEvent::query()->where('call_id', $s->id)->whereNotNull('sequence')->orderBy('sequence')->get();
    expect($rows->pluck('correlation_id')->unique()->values()->all())->toBe(['corr-start-1', 'corr-accept-1'])
        ->and(Call::query()->findOrFail($s->id)->event_sequence)->toBe(count($events));
});

it('con outbox: si la transacción se revierte, no queda ni el cambio ni el evento', function () {
    $outbox = FakeOutbox::bind();
    try {
        DB::transaction(function () {
            TC::service()->start($this->ana, InternalTarget::users([$this->beto->id]), InternalMedia::Audio, 'rollback-key1', 'pc-1');
            throw new RuntimeException('falla después');
        });
    } catch (RuntimeException) {
    }
    expect(Call::query()->where('idempotency_key', 'rollback-key1')->exists())->toBeFalse()
        ->and($outbox->appended)->toBe([]);
});

it('sin outbox (hoy): el puerto propio tras confirmar, con la misma versión contigua; los datos internos no salen', function () {
    $call = TC::call($this->ana, [$this->beto->id], 'legacy-key-01');
    TC::service()->accept($this->beto, $call->id, 'b1');
    TC::service()->join($this->beto, $call->id, 'b1');

    $rows = CallEvent::query()->where('call_id', $call->id)->whereNotNull('sequence')->orderBy('sequence')->pluck('sequence')->all();
    $max = Call::query()->findOrFail($call->id)->event_sequence;
    // Persistidas en orden y sin repetir; los huecos solo son avisos efímeros (p. ej. answered_elsewhere).
    expect($rows[0])->toBe(1)->and(count(array_unique($rows)))->toBe(count($rows))
        ->and($rows)->toBe(collect($rows)->sort()->values()->all())
        ->and(end($rows))->toBeLessThanOrEqual($max)
        ->and(TC::$rec->published)->not->toBe([]);

    // El sobre del contrato solo lleva datos públicos: ni dispositivos ni sesiones de medios.
    $public = CallEventEnvelopes::publicData($call->id, ['device_id' => 'b1', 'session' => 'PA_x', 'reason' => 'x', 'user_id' => 2]);
    expect($public)->toBe(['call_id' => $call->id, 'user_id' => 2, 'reason' => 'x']);
});

it('marcas internas (comandos ya aplicados) no ocupan versión ni se publican', function () {
    $outbox = FakeOutbox::bind();
    $s = $this->gw->start($this->a, CallTarget::users([$this->beto->id]), CallMedia::Video, new CommandMetadata('start-key-ev2', 'corr-0001'));
    $before = count($outbox->events($s->id));
    $this->gw->invite($this->a, $s->id, [$this->beto->id], new CommandMetadata('invite-key-e2', 'corr-0002')); // ya invitado: no-op
    expect(count($outbox->events($s->id)))->toBe($before)
        ->and(CallEvent::query()->where('call_id', $s->id)->where('type', 'call.command.invite')->whereNull('sequence')->count())->toBe(1);
});

it('autorización de eventos call.*: solo participantes no expulsados, misma institución y tipos de TeamCalls', function () {
    $outbox = FakeOutbox::bind();
    $s = $this->gw->start($this->a, CallTarget::users([$this->beto->id]), CallMedia::Video, new CommandMetadata('start-key-ev3', 'corr-0003'));
    $event = $outbox->events($s->id)[0];
    $policy = app(CallEventAccess::class);

    $other = TC::institution();
    $stranger = TC::user($other);
    expect($policy->supports($event))->toBeTrue()
        ->and($policy->allows($this->b, $event))->toBeTrue()
        ->and($policy->allows(FakeCollaborationCore::actor($this->carla, 'dev-carla-1'), $event))->toBeFalse()
        ->and($policy->allows(FakeCollaborationCore::actor($stranger, 'dev-x-1'), $event))->toBeFalse();

    $foreign = new EventEnvelope('01JX0000000000000000000000', 'chat.message.created', 1, $this->inst->id, 'conversation', 'c1', 1, null,
        new DateTimeImmutable, 'corr-x', null, []);
    expect($policy->supports($foreign))->toBeFalse()->and($policy->allows($this->a, $foreign))->toBeFalse();

    app(\Modules\Core\Tenancy\CurrentInstitution::class)->set($this->inst->id);
    TC::service()->revokeAccess($this->beto->id);
    expect($policy->allows($this->b, $event))->toBeFalse();
});
