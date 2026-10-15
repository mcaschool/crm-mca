<?php

declare(strict_types=1);

use Modules\TeamCalls\Enums\CallMedia;
use Modules\TeamCalls\Exceptions\CallException;
use Modules\TeamCalls\Models\Call;
use Modules\TeamCalls\Models\CallEvent;
use Modules\TeamCalls\Models\CallParticipant;
use Modules\TeamCalls\Support\CallTarget;
use Modules\TeamCalls\Tests\Support\TC;

/*
| Concurrencia y recuperación: varios dispositivos de la misma persona, reintentos por errores de red,
| eventos repetidos del servidor de medios y estados coherentes tras operaciones repetidas.
*/

beforeEach(function () {
    TC::fakes();
    $this->inst = TC::institution();
    [$this->ana, $this->beto, $this->carla] = [TC::user($this->inst), TC::user($this->inst), TC::user($this->inst)];
});

it('contesta en el celular: la respuesta posterior desde la PC no crea una segunda aceptación', function () {
    $call = TC::call($this->ana, [$this->beto->id]);
    TC::service()->accept($this->beto, $call->id, 'beto-iphone');

    try {
        TC::service()->accept($this->beto, $call->id, 'beto-pc');
        $this->fail('debía rechazar la segunda aceptación');
    } catch (CallException $e) {
        expect($e->reason)->toBe('answered_elsewhere')->and($e->status)->toBe(409);
    }
    // Tampoco puede conectarse desde la PC mientras la llamada está en el celular.
    expect(fn () => TC::service()->join($this->beto, $call->id, 'beto-pc'))->toThrow(CallException::class);

    $p = CallParticipant::query()->where('call_id', $call->id)->where('user_id', $this->beto->id)->sole();
    expect($p->state->value)->toBe('accepted')->and($p->device_id)->toBe('beto-iphone')
        ->and(CallEvent::query()->where('call_id', $call->id)->where('type', 'call.accepted')->count())->toBe(1);
});

it('el mismo dispositivo repitiendo la respuesta (reintento) obtiene el mismo resultado sin eventos nuevos', function () {
    $call = TC::call($this->ana, [$this->beto->id]);
    foreach (range(1, 3) as $i) {
        TC::service()->accept($this->beto, $call->id, 'beto-iphone');
        TC::service()->join($this->beto, $call->id, 'beto-iphone');
    }
    expect(CallEvent::query()->where('call_id', $call->id)->where('type', 'call.accepted')->count())->toBe(1)
        ->and(CallEvent::query()->where('call_id', $call->id)->where('type', 'call.participant.joined')->count())->toBe(1);

    // Rechazar después de conectarse no es una transición válida.
    try {
        TC::service()->decline($this->beto, $call->id);
        $this->fail('debía rechazar la transición');
    } catch (CallException $e) {
        expect($e->reason)->toBe('invalid_transition');
    }
});

it('reintentos de inicio por errores de red: misma clave, una sola llamada y un solo aviso', function () {
    $first = TC::call($this->ana, [$this->beto->id], 'retry-key-0001');
    $again = TC::call($this->ana, [$this->beto->id], 'retry-key-0001');
    $third = TC::service()->start($this->ana, CallTarget::users([$this->beto->id]), CallMedia::Video, 'retry-key-0001');

    expect($again->id)->toBe($first->id)->and($third->id)->toBe($first->id)
        ->and(Call::query()->count())->toBe(1)
        ->and(CallEvent::query()->where('type', 'call.started')->count())->toBe(1)
        ->and(TC::$rec->incoming)->toHaveCount(1);

    // La misma clave para OTRA llamada es un conflicto, no una llamada nueva ni la anterior.
    try {
        TC::call($this->ana, [$this->carla->id], 'retry-key-0001');
        $this->fail('debía detectar el conflicto');
    } catch (CallException $e) {
        expect($e->reason)->toBe('idempotency_conflict');
    }
    expect(fn () => TC::service()->start($this->ana, CallTarget::users([$this->beto->id]), CallMedia::Audio, 'retry-key-0001'))
        ->toThrow(CallException::class, 'idempotency key');
    expect(Call::query()->count())->toBe(1);
});

it('quien ya está en una llamada no inicia otra en paralelo (ocupado)', function () {
    TC::call($this->ana, [$this->beto->id], 'busy-key-0001');
    expect(fn () => TC::call($this->ana, [$this->carla->id], 'busy-key-0002'))->toThrow(CallException::class, 'already in another call');
    expect(Call::query()->count())->toBe(1);
});

it('eventos repetidos del servidor de medios se aplican una sola vez', function () {
    $call = TC::call($this->ana, [$this->beto->id]);
    TC::service()->accept($this->beto, $call->id, 'b1');

    foreach (range(1, 3) as $i) {
        TC::service()->applyMediaEvent($call->id, 'evt-join-beto-1', 'joined', $this->beto->id, 'b1');
        TC::service()->applyMediaEvent($call->id, 'evt-join-ana-1', 'joined', $this->ana->id, 'pc-'.$this->ana->id);
    }
    expect(TC::fresh($call)->status->value)->toBe('active')
        ->and(CallEvent::query()->where('call_id', $call->id)->where('type', 'call.media.joined')->count())->toBe(2)
        ->and(CallEvent::query()->where('call_id', $call->id)->where('type', 'call.connected')->count())->toBe(1);

    // Un evento de un dispositivo que no es el de la llamada no saca a nadie.
    TC::service()->applyMediaEvent($call->id, 'evt-left-ajeno', 'left', $this->beto->id, 'dispositivo-ajeno');
    expect(TC::fresh($call)->status->value)->toBe('active');

    foreach (range(1, 2) as $i) {
        TC::service()->applyMediaEvent($call->id, 'evt-left-beto-1', 'left', $this->beto->id, 'b1');
    }
    expect(TC::fresh($call)->status->value)->toBe('ended')
        ->and(CallEvent::query()->where('call_id', $call->id)->where('type', 'call.ended')->count())->toBe(1);
});

it('estados coherentes al recuperar: lo vencido se aplica al consultar, sin procesos en segundo plano', function () {
    $call = TC::call($this->ana, [$this->beto->id, $this->carla->id]);
    TC::service()->accept($this->beto, $call->id, 'b1');
    $this->travel(60)->seconds();

    // Sin comando ni worker: la propia consulta deja a Carla como perdida y la llamada sigue conectando.
    $summary = TC::service()->find($this->beto, $call->id);
    expect($summary->status)->toBe('connecting')
        ->and(collect($summary->participants)->firstWhere('userId', $this->carla->id)->state)->toBe('missed');

    // Operaciones repetidas sobre una llamada terminada no cambian nada.
    TC::service()->end($this->ana, $call->id);
    $events = CallEvent::query()->where('call_id', $call->id)->count();
    TC::service()->end($this->ana, $call->id);
    TC::service()->leave($this->beto, $call->id, 'b1');
    // Cancelar una llamada que ya se contestó y terminó es inválido (y no cambia nada).
    expect(fn () => TC::service()->cancel($this->ana, $call->id))->toThrow(CallException::class, 'cancel on ended');
    expect(TC::fresh($call)->status->value)->toBe('ended')
        ->and(CallEvent::query()->where('call_id', $call->id)->count())->toBe($events);
});

it('un fallo al publicar en tiempo real no deshace la llamada', function () {
    app()->instance(\Modules\TeamCalls\Contracts\CallEventPublisher::class, new class implements \Modules\TeamCalls\Contracts\CallEventPublisher
    {
        public function publish(\Modules\TeamCalls\Support\CallEnvelope $envelope): void
        {
            throw new RuntimeException('websocket caído');
        }
    });
    $call = app(\Modules\TeamCalls\Services\CallService::class)->start($this->ana, CallTarget::users([$this->beto->id]), CallMedia::Audio, 'key-pubfail-01');
    expect(TC::fresh($call)->status->value)->toBe('ringing')->and(Call::query()->count())->toBe(1);
});
