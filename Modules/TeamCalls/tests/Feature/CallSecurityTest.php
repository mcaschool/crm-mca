<?php

declare(strict_types=1);

use Modules\Core\Tenancy\CurrentInstitution;
use Modules\TeamCalls\Contracts\ConversationAccess;
use Modules\TeamCalls\Enums\CallMedia;
use Modules\TeamCalls\Exceptions\CallException;
use Modules\TeamCalls\Integration\Provisional\DenyAllConversationAccess;
use Modules\TeamCalls\Models\Call;
use Modules\TeamCalls\Services\CallService;
use Modules\TeamCalls\Support\CallTarget;
use Modules\TeamCalls\Support\ConversationRef;
use Modules\TeamCalls\Tests\Support\TC;

/*
| Seguridad: institución, usuario activo y con permiso, pertenencia a la conversación, autorización
| para participar, aislamiento entre instituciones y manipulación de identificadores. El servidor
| decide siempre quién participa.
*/

beforeEach(function () {
    TC::fakes();
    $this->inst = TC::institution();
    [$this->ana, $this->beto, $this->carla] = [TC::user($this->inst), TC::user($this->inst), TC::user($this->inst)];
});

function tcReason(callable $fn): string
{
    try {
        $fn();
    } catch (CallException $e) {
        return $e->reason;
    }

    return 'sin-error';
}

it('una institución no ve ni toca llamadas de otra (como si no existieran)', function () {
    $call = TC::call($this->ana, [$this->beto->id]);

    $other = TC::institution();
    $intruder = TC::user($other);
    foreach ([
        fn () => TC::service()->find($intruder, $call->id),
        fn () => TC::service()->accept($intruder, $call->id, 'x1'),
        fn () => TC::service()->join($intruder, $call->id, 'x1'),
        fn () => TC::service()->cancel($intruder, $call->id),
        fn () => TC::service()->invite($intruder, $call->id, [$intruder->id]),
    ] as $attempt) {
        expect(tcReason($attempt))->toBe('not_found');
    }

    // Tampoco puede llamar a personas de otra institución (todas o ninguna, sin decir cuál falla).
    expect(tcReason(fn () => TC::call($intruder, [$this->ana->id], 'key-x-0001')))->toBe('forbidden');

    // Con la institución activa equivocada, ni siquiera quien participa puede operar.
    app(CurrentInstitution::class)->set($other->id);
    expect(tcReason(fn () => TC::service()->find($this->beto, $call->id)))->toBe('forbidden');
    app(CurrentInstitution::class)->set($this->inst->id);
    expect(Call::query()->count())->toBe(1);
});

it('usuarios inactivos o sin permiso no inician, no reciben ni se incorporan', function () {
    $inactive = TC::user($this->inst, ['status' => 'inactive']);
    expect(tcReason(fn () => TC::call($inactive, [$this->ana->id])))->toBe('forbidden')
        ->and(tcReason(fn () => TC::call($this->ana, [$inactive->id])))->toBe('forbidden')
        ->and(tcReason(fn () => TC::call($this->ana, [$this->beto->id, $inactive->id])))->toBe('forbidden');

    $noRole = TC::user($this->inst);
    $noRole->forceFill(['role' => null]); // sin acceso al CRM (sin guardar: solo en memoria)
    expect(tcReason(fn () => TC::service()->history($noRole)))->toBe('forbidden');

    // Un usuario que se desactiva durante la llamada ya no puede contestar.
    $call = TC::call($this->ana, [$this->beto->id]);
    $this->beto->forceFill(['status' => 'inactive'])->save();
    expect(tcReason(fn () => TC::service()->accept($this->beto->fresh(), $call->id, 'b1')))->toBe('forbidden')
        ->and(Call::query()->count())->toBe(1);
});

it('desde una conversación los participantes los decide el servidor con sus miembros', function () {
    $ref = new ConversationRef('group', '01JTEAMCHATGROUP000000001');
    TC::$conversations->add($ref, $this->inst->id, [$this->ana->id, $this->beto->id]);

    $call = TC::service()->start($this->ana, CallTarget::conversation($ref), CallMedia::Audio, 'key-conv-0001');
    expect(array_keys(TC::states($call)))->toBe([$this->ana->id, $this->beto->id]) // Carla no es miembro: no suena
        ->and(TC::fresh($call)->context_type)->toBe('group')->and(TC::fresh($call)->context_id)->toBe($ref->id);

    // Invitar a quien no es miembro de la conversación: prohibido aunque sea de la institución.
    TC::service()->accept($this->beto, $call->id, 'b1');
    expect(tcReason(fn () => TC::service()->invite($this->beto, $call->id, [$this->carla->id])))->toBe('forbidden')
        ->and(tcReason(fn () => TC::service()->join($this->carla, $call->id, 'c1')))->toBe('not_found');

    // Un nuevo miembro de la conversación sí puede incorporarse a la llamada en curso.
    TC::$conversations->add($ref, $this->inst->id, [$this->ana->id, $this->beto->id, $this->carla->id]);
    TC::service()->join($this->carla, $call->id, 'c1');
    expect(TC::states($call)[$this->carla->id])->toBe('joined')
        ->and(TC::service()->activeForConversation($this->carla, 'group', $ref->id)?->id)->toBe($call->id);

    // No miembro o sin permiso para llamar desde la conversación: prohibido.
    $private = new ConversationRef('channel', '01JTEAMCHATCHANNEL0000001');
    TC::$conversations->add($private, $this->inst->id, [$this->ana->id, $this->beto->id], [$this->ana->id]);
    expect(tcReason(fn () => TC::service()->start($this->beto, CallTarget::conversation($private), CallMedia::Audio, 'key-conv-0002')))->toBe('forbidden')
        ->and(tcReason(fn () => TC::service()->start($this->carla, CallTarget::conversation($private), CallMedia::Audio, 'key-conv-0003')))->toBe('forbidden');
});

it('sin TeamChat (adaptador provisional) no se puede llamar desde ninguna conversación', function () {
    app()->instance(ConversationAccess::class, new DenyAllConversationAccess);
    $ref = new ConversationRef('direct', '01JTEAMCHATDIRECT00000001');
    expect(tcReason(fn () => app(CallService::class)->start($this->ana, CallTarget::conversation($ref), CallMedia::Audio, 'key-conv-0009')))->toBe('forbidden');
});

it('identificadores manipulados no exponen nada', function (string $id) {
    expect(tcReason(fn () => TC::service()->find($this->ana, $id)))->toBe('not_found');
})->with(['01JZZZZZZZZZZZZZZZZZZZZZZZ', '1 OR 1=1', '../../etc', '']);

it('quien no participa no puede ver, contestar ni conectarse a una llamada de su institución', function () {
    $call = TC::call($this->ana, [$this->beto->id]);
    expect(tcReason(fn () => TC::service()->find($this->carla, $call->id)))->toBe('not_found')
        ->and(tcReason(fn () => TC::service()->accept($this->carla, $call->id, 'c1')))->toBe('not_found')
        ->and(tcReason(fn () => TC::service()->join($this->carla, $call->id, 'c1')))->toBe('not_found')
        ->and(tcReason(fn () => TC::service()->credentials($this->carla, $call->id, 'c1')))->toBe('not_found');
});

it('las credenciales de la sala solo se emiten para quien está en la llamada y con los permisos del backend', function () {
    $call = TC::call($this->ana, [$this->beto->id], 'key-cred-001', CallMedia::Audio);
    expect(tcReason(fn () => TC::service()->credentials($this->beto, $call->id, 'b1')))->toBe('forbidden'); // aún suena

    TC::service()->accept($this->beto, $call->id, 'b1');
    $credentials = TC::service()->credentials($this->beto, $call->id, 'b1');
    $grant = TC::$rec->grants[0];
    expect($credentials->identity)->toBe('u'.$this->beto->id.'.b1')
        ->and($grant->room)->toBe(TC::fresh($call)->room_name)->and($grant->canPublishVideo)->toBeFalse() // llamada de voz
        ->and($grant->isHost)->toBeFalse()->and($grant->ttlSeconds)->toBe(300)
        ->and(tcReason(fn () => TC::service()->credentials($this->beto, $call->id, 'otro-dispositivo')))->toBe('answered_elsewhere');

    // Sin servidor de medios configurado (adaptador provisional), falla de forma explícita.
    app()->instance(\Modules\TeamCalls\Contracts\MediaRoomService::class, new \Modules\TeamCalls\Integration\Provisional\UnconfiguredMediaRooms);
    expect(tcReason(fn () => app(CallService::class)->credentials($this->beto, $call->id, 'b1')))->toBe('media_unavailable');

    // Nada de la sala ni de las credenciales se guarda.
    expect(json_encode(\Modules\TeamCalls\Models\CallEvent::query()->get()))->not->toContain('fake.u');
});

it('valida entradas: autollamada, claves y dispositivos inválidos, límite de participantes', function () {
    expect(tcReason(fn () => TC::call($this->ana, [$this->ana->id])))->toBe('invalid')
        ->and(tcReason(fn () => TC::service()->start($this->ana, CallTarget::users([$this->beto->id]), CallMedia::Audio, 'corta')))->toBe('invalid')
        ->and(tcReason(fn () => TC::service()->start($this->ana, CallTarget::users([$this->beto->id]), CallMedia::Audio, 'key-valid-01', 'disp<script>')))->toBe('invalid');

    config(['teamcalls.max_participants' => 3]);
    $d = TC::user($this->inst);
    expect(tcReason(fn () => TC::call($this->ana, [$this->beto->id, $this->carla->id, $d->id], 'key-max-0001')))->toBe('too_many_participants');
});
