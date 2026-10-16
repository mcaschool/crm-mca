<?php

declare(strict_types=1);

use Modules\CollaborationCore\Contracts\CallsGateway;
use Modules\CollaborationCore\Data\CallTarget;
use Modules\CollaborationCore\Data\CommandMetadata;
use Modules\CollaborationCore\Data\ConversationRef;
use Modules\CollaborationCore\Enums\CallMedia;
use Modules\CollaborationCore\Enums\ConversationType;
use Modules\CollaborationCore\Exceptions\ContractException;
use Modules\TeamCalls\Models\CallParticipant;
use Modules\TeamCalls\Tests\Support\RealCollaboration;
use Modules\TeamCalls\Tests\Support\TC;
use Modules\TeamChat\Services\SpaceCommands;

/*
| Fase 4D · llamadas desde CANALES de equipos de TeamChat (servicios reales de Core, Realtime y TeamChat):
| selección explícita validada contra la membresía del canal (no la del equipo) y expulsión al perder
| el canal o el equipo (revocaciones durables entregadas al consumidor de TeamCalls).
*/

function ch_cmd(string $key): CommandMetadata
{
    return new CommandMetadata(str_pad($key, 12, '0'), 'corr-'.substr(md5($key), 0, 10));
}

function ch_code(callable $fn): string
{
    try {
        $fn();
    } catch (ContractException $e) {
        return $e->errorCode;
    }

    return 'sin-error';
}

beforeEach(function () {
    RealCollaboration::setUp();
    $this->inst = TC::institution();
    [$this->ana, $this->beto, $this->carla] = [TC::user($this->inst), TC::user($this->inst), TC::user($this->inst)];
    $this->gw = app(CallsGateway::class);
    $this->a = RealCollaboration::actor($this->ana);
    $spaces = app(SpaceCommands::class);
    $this->team = $spaces->create($this->a, 'team', 'team-cmd-0001', 'Admisiones', '', [$this->beto->id, $this->carla->id]);
    // Canal PRIVADO del equipo solo con Ana y Beto: Carla es del equipo, pero no del canal.
    $this->channel = $spaces->create($this->a, 'channel', 'chan-cmd-0001', 'Coordinación', '', [$this->beto->id], $this->team, 'private');
    $this->ref = new ConversationRef(ConversationType::Channel, $this->channel);
});

it('canal: solo suenan los elegidos del canal; un miembro del equipo ajeno al canal no puede ser invitado', function () {
    expect(ch_code(fn () => $this->gw->start($this->a, CallTarget::conversation($this->ref, [$this->carla->id]), CallMedia::Audio, ch_cmd('ch-start-01'))))
        ->toBe('forbidden');
    $s = $this->gw->start($this->a, CallTarget::conversation($this->ref, [$this->beto->id]), CallMedia::Audio, ch_cmd('ch-start-02'));
    expect(collect($s->participants)->pluck('userId')->sort()->values()->all())->toBe(collect([$this->ana->id, $this->beto->id])->sort()->values()->all())
        ->and($s->conversation?->type)->toBe(ConversationType::Channel);
    // Carla tampoco puede ver ni unirse a la llamada del canal.
    $c = RealCollaboration::actor($this->carla);
    expect(ch_code(fn () => $this->gw->find($c, $s->id)))->toBe('not_found')
        ->and(ch_code(fn () => $this->gw->join($c, $s->id, ch_cmd('ch-join-cc'))))->not->toBe('sin-error');
});

it('salir del canal o del equipo expulsa de la llamada del canal (revocación durable entregada a TeamCalls)', function () {
    $s = $this->gw->start($this->a, CallTarget::conversation($this->ref, [$this->beto->id]), CallMedia::Audio, ch_cmd('ch-start-03'));
    $b = RealCollaboration::actor($this->beto);
    $this->gw->accept($b, $s->id, ch_cmd('ch-acc-0001'));

    // Ana retira a Beto del EQUIPO: TeamChat emite la revocación del equipo y la de cada canal afectado.
    app(SpaceCommands::class)->member($this->a, 'team', $this->team, 'team-cmd-0002', $this->beto->id, 'remove');
    RealCollaboration::deliver($this->inst->id);

    $p = CallParticipant::query()->where('call_id', $s->id)->where('user_id', $this->beto->id)->firstOrFail();
    expect($p->revoked_at)->not->toBeNull()
        ->and(ch_code(fn () => $this->gw->credentials($b, $s->id)))->not->toBe('sin-error');
});
