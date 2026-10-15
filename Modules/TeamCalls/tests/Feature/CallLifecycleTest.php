<?php

declare(strict_types=1);

use Modules\TeamCalls\Enums\CallMedia;
use Modules\TeamCalls\Events\CallEnded;
use Modules\TeamCalls\Events\CallMissed;
use Modules\TeamCalls\Events\CallStarted;
use Modules\TeamCalls\Exceptions\CallException;
use Modules\TeamCalls\Models\Call;
use Modules\TeamCalls\Models\CallEvent;
use Modules\TeamCalls\Tests\Support\TC;

/*
| Ciclo de vida de las llamadas internas: 1:1 y grupales, aceptar, rechazar, cancelar, finalizar,
| invitar, estados individuales, llamadas perdidas e historial. CollaborationCore y el servidor de
| medios son dobles que registran lo pedido (sin WebSocket, LiveKit ni push reales).
*/

beforeEach(function () {
    TC::fakes();
    $this->inst = TC::institution();
    [$this->ana, $this->beto, $this->carla, $this->dani] = [TC::user($this->inst), TC::user($this->inst), TC::user($this->inst), TC::user($this->inst)];
});

it('inicia una llamada individual: suena para la otra persona y queda registrada', function () {
    \Illuminate\Support\Facades\Event::fake([CallStarted::class]);
    $call = TC::call($this->ana, [$this->beto->id], 'key-ab-001', CallMedia::Audio);

    $call = TC::fresh($call);
    expect($call->status->value)->toBe('ringing')->and($call->scope->value)->toBe('direct')->and($call->media->value)->toBe('audio')
        ->and($call->institution_id)->toBe($this->inst->id)->and($call->room_name)->toStartWith('tc-')->and($call->ringing_at)->not->toBeNull()
        ->and(TC::states($call))->toBe([$this->ana->id => 'accepted', $this->beto->id => 'ringing'])
        ->and($call->participantFor($this->ana->id)->role->value)->toBe('host')
        ->and($call->participantFor($this->beto->id)->ring_expires_at->diffInSeconds(now()->addSeconds(45), true))->toBeLessThan(3);

    // Avisos: suena SOLO para Beto (tiempo real y push); traza persistente del inicio.
    expect(TC::$rec->ofType('call.ringing'))->toHaveCount(1)->and(TC::$rec->ofType('call.ringing')[0]->recipients)->toBe([$this->beto->id])
        ->and(TC::$rec->incoming)->toBe([['users' => [$this->beto->id], 'call' => $call->id]])
        ->and(CallEvent::query()->where('call_id', $call->id)->pluck('type')->all())->toBe(['call.started', 'call.ringing']);
    \Illuminate\Support\Facades\Event::assertDispatched(CallStarted::class, fn ($e) => $e->callId === $call->id && $e->actorId === $this->ana->id);
});

it('contestar, conectarse ambos y colgar: conectando → activa → finalizada', function () {
    $call = TC::call($this->ana, [$this->beto->id]);
    TC::service()->accept($this->beto, $call->id, 'phone-b');
    expect(TC::fresh($call)->status->value)->toBe('connecting')->and(TC::fresh($call)->answered_at)->not->toBeNull();

    // Los demás dispositivos de Beto dejan de sonar (excepto el que contestó).
    $elsewhere = TC::$rec->ofType('call.answered_elsewhere');
    expect($elsewhere)->toHaveCount(1)->and($elsewhere[0]->recipients)->toBe([$this->beto->id])->and($elsewhere[0]->exceptDeviceId)->toBe('phone-b')
        ->and(collect(TC::$rec->cancelled)->firstWhere('reason', 'answered_elsewhere')['except'])->toBe('phone-b');

    TC::service()->join($this->ana, $call->id, 'pc-'.$this->ana->id);
    expect(TC::fresh($call)->status->value)->toBe('connecting');
    TC::service()->join($this->beto, $call->id, 'phone-b');
    expect(TC::fresh($call)->status->value)->toBe('active')->and(TC::fresh($call)->connected_at)->not->toBeNull()
        ->and(TC::$rec->types())->toContain('call.connected')
        ->and(TC::$rec->inCall)->toHaveKeys([$this->ana->id.'|'.$call->id, $this->beto->id.'|'.$call->id]);

    TC::service()->leave($this->beto, $call->id, 'phone-b');
    $call = TC::fresh($call);
    expect($call->status->value)->toBe('ended')->and($call->end_reason)->toBe('everyone_left')->and($call->ended_at)->not->toBeNull()
        ->and(TC::states($call))->toBe([$this->ana->id => 'left', $this->beto->id => 'left'])
        ->and(TC::$rec->inCall)->toBe([])
        ->and(TC::$rec->closed)->toBe([$call->room_name])
        ->and(TC::$rec->removed)->toContain($call->room_name.'|u'.$this->beto->id.'.phone-b')
        ->and(TC::$rec->ofType('call.ended')[0]->data)->toMatchArray(['status' => 'ended', 'reason' => 'everyone_left']);
});

it('rechazar una llamada individual la termina como rechazada', function () {
    \Illuminate\Support\Facades\Event::fake([CallEnded::class]);
    $call = TC::call($this->ana, [$this->beto->id]);
    TC::service()->decline($this->beto, $call->id);

    expect(TC::fresh($call)->status->value)->toBe('declined')->and(TC::states($call))->toBe([$this->ana->id => 'left', $this->beto->id => 'declined'])
        ->and(collect(TC::$rec->cancelled)->pluck('reason')->all())->toContain('declined');
    \Illuminate\Support\Facades\Event::assertDispatched(CallEnded::class, fn ($e) => $e->data['status'] === 'declined');
});

it('quien llama puede cancelar mientras suena; nadie más puede', function () {
    $call = TC::call($this->ana, [$this->beto->id]);
    expect(fn () => TC::service()->cancel($this->beto, $call->id))->toThrow(CallException::class, 'Only the caller can cancel.');

    TC::service()->cancel($this->ana, $call->id);
    TC::service()->cancel($this->ana, $call->id); // idempotente
    expect(TC::fresh($call)->status->value)->toBe('cancelled')->and(TC::states($call)[$this->beto->id])->toBe('cancelled')
        ->and(collect(TC::$rec->cancelled)->firstWhere('reason', 'cancelled')['users'])->toBe([$this->beto->id])
        ->and(CallEvent::query()->where('call_id', $call->id)->where('type', 'call.ended')->count())->toBe(1);

    // Ya cancelada: contestar no la revive.
    expect(fn () => TC::service()->accept($this->beto, $call->id, 'phone-b'))->toThrow(CallException::class);
});

it('el anfitrión finaliza para todos; otra persona que «finaliza» solo sale', function () {
    $call = TC::call($this->ana, [$this->beto->id, $this->carla->id]);
    foreach ([[$this->beto, 'b1'], [$this->carla, 'c1']] as [$u, $d]) {
        TC::service()->accept($u, $call->id, $d);
        TC::service()->join($u, $call->id, $d);
    }
    TC::service()->join($this->ana, $call->id, 'pc-'.$this->ana->id);
    expect(TC::fresh($call)->status->value)->toBe('active');

    TC::service()->end($this->carla, $call->id);
    expect(TC::fresh($call)->status->value)->toBe('active')->and(TC::states($call)[$this->carla->id])->toBe('left');

    TC::service()->end($this->ana, $call->id);
    expect(TC::fresh($call)->status->value)->toBe('ended')->and(TC::fresh($call)->end_reason)->toBe('ended_by_host')
        ->and(TC::states($call))->toBe([$this->ana->id => 'left', $this->beto->id => 'left', $this->carla->id => 'left']);
});

it('grupal: un rechazo o una ausencia no terminan la llamada; cada persona tiene su estado', function () {
    $call = TC::call($this->ana, [$this->beto->id, $this->carla->id, $this->dani->id]);
    expect(TC::fresh($call)->scope->value)->toBe('group');

    TC::service()->decline($this->beto, $call->id);
    expect(TC::fresh($call)->status->value)->toBe('ringing');

    TC::service()->accept($this->carla, $call->id, 'c1');
    TC::service()->join($this->carla, $call->id, 'c1');
    TC::service()->join($this->ana, $call->id, 'pc-'.$this->ana->id);
    expect(TC::fresh($call)->status->value)->toBe('active');

    // Dani no contesta a tiempo: perdida para él, la llamada sigue.
    $this->travel(46)->seconds();
    TC::service()->find($this->ana, $call->id); // cualquier consulta aplica lo vencido
    $call = TC::fresh($call);
    expect(TC::states($call))->toBe([$this->ana->id => 'joined', $this->beto->id => 'declined', $this->carla->id => 'joined', $this->dani->id => 'missed'])
        ->and($call->status->value)->toBe('active');

    // Beto cambia de idea y se incorpora a la llamada que sigue viva.
    TC::service()->join($this->beto, $call->id, 'b1');
    expect(TC::states($call)[$this->beto->id])->toBe('joined');
});

it('grupal: si todos rechazan queda rechazada; si nadie contesta, perdida', function () {
    $declined = TC::call($this->ana, [$this->beto->id, $this->carla->id], 'key-grp-001');
    TC::service()->decline($this->beto, $declined->id);
    TC::service()->decline($this->carla, $declined->id);
    expect(TC::fresh($declined)->status->value)->toBe('declined');

    \Illuminate\Support\Facades\Event::fake([CallMissed::class]);
    $missed = TC::call($this->dani, [$this->beto->id, $this->carla->id], 'key-grp-002');
    TC::service()->decline($this->beto, $missed->id);
    $this->travel(50)->seconds();
    $this->artisan('teamcalls:expire-stale')->assertSuccessful();
    expect(TC::fresh($missed)->status->value)->toBe('missed')->and(TC::fresh($missed)->end_reason)->toBe('no_answer')
        ->and(TC::states($missed)[$this->carla->id])->toBe('missed');
    \Illuminate\Support\Facades\Event::assertDispatched(CallMissed::class, fn ($e) => $e->subjectId === $this->carla->id);
});

it('invitar a más personas a una llamada en curso: suena solo para las nuevas', function () {
    $call = TC::call($this->ana, [$this->beto->id]);
    TC::service()->accept($this->beto, $call->id, 'b1');
    TC::service()->invite($this->beto, $call->id, [$this->carla->id, $this->dani->id]);

    $call = TC::fresh($call);
    expect($call->scope->value)->toBe('group')->and(TC::states($call))->toMatchArray([$this->carla->id => 'ringing', $this->dani->id => 'ringing'])
        ->and($call->participantFor($this->carla->id)->invited_by)->toBe($this->beto->id)
        ->and(collect(TC::$rec->ofType('call.ringing'))->pluck('recipients')->flatten()->all())->toBe([$this->beto->id, $this->carla->id, $this->dani->id]);

    // Repetir la invitación no vuelve a hacer sonar a quien ya suena.
    TC::service()->invite($this->beto, $call->id, [$this->carla->id]);
    expect(TC::$rec->ofType('call.ringing'))->toHaveCount(3);

    // Quien aún no está en la llamada no puede invitar.
    expect(fn () => TC::service()->invite($this->dani, $call->id, [$this->carla->id]))->toThrow(CallException::class, 'Only people in the call can invite.');
});

it('una invitación sin respuesta pasa a perdida y aparece en sus llamadas perdidas e historial', function () {
    $call = TC::call($this->ana, [$this->beto->id]);
    $this->travel(46)->seconds();

    $missed = TC::service()->missed($this->beto);
    expect($missed)->toHaveCount(1)->and($missed[0]->id)->toBe($call->id)->and($missed[0]->viewerState)->toBe('missed')->and($missed[0]->status)->toBe('missed')
        ->and(TC::service()->missed($this->ana))->toBe([])
        ->and(collect(TC::service()->history($this->ana))->pluck('id')->all())->toBe([$call->id])
        ->and(TC::service()->history($this->carla))->toBe([]);
});

it('si tras contestar nadie llega a conectarse, la llamada falla por tiempo de conexión', function () {
    $call = TC::call($this->ana, [$this->beto->id]);
    TC::service()->accept($this->beto, $call->id, 'b1');
    $this->travel(91)->seconds();
    $this->artisan('teamcalls:expire-stale')->assertSuccessful();

    expect(TC::fresh($call)->status->value)->toBe('failed')->and(TC::fresh($call)->end_reason)->toBe('connect_timeout');
});

it('el gateway para otros módulos devuelve resúmenes sin datos internos', function () {
    $gateway = app(\Modules\TeamCalls\Contracts\CallsGateway::class);
    $summary = $gateway->start($this->ana, \Modules\TeamCalls\Support\CallTarget::users([$this->beto->id]), CallMedia::Video, 'key-gw-0001');
    $array = $summary->toArray();

    expect($array['status'])->toBe('ringing')->and($array['viewer_state'])->toBe('accepted')
        ->and(array_keys($array))->not->toContain('idempotency_key')->not->toContain('room_name')
        ->and($gateway->find($summary->id, $this->beto)->viewerState)->toBe('ringing')
        ->and(collect($gateway->recentFor($this->beto))->pluck('id')->all())->toBe([$summary->id])
        ->and(Call::query()->count())->toBe(1);
});
