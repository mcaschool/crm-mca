<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Modules\CollaborationCore\Contracts\CallsGateway;
use Modules\CollaborationCore\Contracts\ConversationDirectory;
use Modules\CollaborationCore\Contracts\PresenceService;
use Modules\CollaborationCore\Data\ActorContext;
use Modules\CollaborationCore\Data\CallTarget;
use Modules\CollaborationCore\Data\CommandMetadata;
use Modules\CollaborationCore\Data\ConversationDescriptor;
use Modules\CollaborationCore\Data\ConversationRef;
use Modules\CollaborationCore\Data\InstitutionContext;
use Modules\CollaborationCore\Data\MembershipSnapshot;
use Modules\CollaborationCore\Data\PresenceSnapshot;
use Modules\CollaborationCore\Enums\CallMedia;
use Modules\CollaborationCore\Enums\ConversationAction;
use Modules\CollaborationCore\Enums\ConversationType;
use Modules\CollaborationCore\Enums\ManualPresence;
use Modules\CollaborationCore\Enums\ParticipantState;
use Modules\CollaborationCore\Exceptions\ContractException;
use Modules\TeamCalls\Contracts\ConversationAccess;
use Modules\TeamCalls\Contracts\MediaRoomService;
use Modules\TeamCalls\Contracts\Presence;
use Modules\TeamCalls\Contracts\UserDirectory;
use Modules\TeamCalls\Enums\CallMedia as InternalMedia;
use Modules\TeamCalls\Integration\Collaboration\CallAccessRevocations;
use Modules\TeamCalls\Integration\Collaboration\CollaborationPresence;
use Modules\TeamCalls\Integration\Collaboration\CollaborationUserDirectory;
use Modules\TeamCalls\Integration\Collaboration\ConversationDirectoryAccess;
use Modules\TeamCalls\Integration\Collaboration\OperationContext;
use Modules\TeamCalls\Integration\LiveKit\LiveKitConfig;
use Modules\TeamCalls\Integration\Provisional\DenyAllConversationAccess;
use Modules\TeamCalls\Integration\Provisional\NoopPresence;
use Modules\TeamCalls\Integration\Provisional\PanelUserDirectory;
use Modules\TeamCalls\Models\CallEvent;
use Modules\TeamCalls\Services\CallService;
use Modules\TeamCalls\Support\CallTarget as InternalTarget;
use Modules\TeamCalls\Support\ConversationRef as InternalRef;
use Modules\TeamCalls\Tests\Support\FakeCollaborationCore;
use Modules\TeamCalls\Tests\Support\TC;

/*
| Fase 4A · adaptadores hacia los contratos de CollaborationCore (directorio de conversaciones,
| presencia, permiso collaboration.use) y puntos de revocación. Core se sustituye por dobles de sus
| interfaces oficiales; ninguno de estos adaptadores está activo sin que Core los registre.
*/

/** Doble del ConversationDirectory oficial: miembros por conversación y quién puede llamar. */
function fakeDirectory(int $institutionId, array $members): ConversationDirectory
{
    return new class($institutionId, $members) implements ConversationDirectory
    {
        /** @param list<int> $members */
        public function __construct(private int $institutionId, public array $members) {}

        public function resolve(ActorContext $context, ConversationRef $ref): ConversationDescriptor
        {
            return new ConversationDescriptor($ref, $this->institutionId, true, 'v1');
        }

        public function authorize(ActorContext $context, ConversationRef $ref, ConversationAction $action): void
        {
            if (! in_array($context->userId, $this->members, true)) {
                throw new ContractException('forbidden', 403);
            }
        }

        public function eligibleInvitees(ActorContext $context, ConversationRef $ref, array $selection): array
        {
            return [];
        }

        public function membership(ActorContext $context, ConversationRef $ref, int $userId): MembershipSnapshot
        {
            return new MembershipSnapshot($this->institutionId, $ref, $userId, in_array($userId, $this->members, true), 'v1');
        }

        public function accessVersion(ActorContext $context, ConversationRef $ref): string
        {
            return 'v1';
        }
    };
}

beforeEach(function () {
    $this->inst = TC::institution();
    [$this->ana, $this->beto, $this->carla, $this->dani] = [TC::user($this->inst), TC::user($this->inst), TC::user($this->inst), TC::user($this->inst)];
});

it('enlaces: sin contratos de Core siguen los provisionales; con ellos, los adaptadores (y collaboration.use solo si se elige)', function () {
    expect(app(ConversationAccess::class))->toBeInstanceOf(DenyAllConversationAccess::class)
        ->and(app(Presence::class))->toBeInstanceOf(NoopPresence::class)
        ->and(app(UserDirectory::class))->toBeInstanceOf(PanelUserDirectory::class)
        ->and(app(CallsGateway::class))->toBeInstanceOf(\Modules\TeamCalls\Integration\Collaboration\SharedCallsGateway::class)
        ->and(app(OperationContext::class))->toBe(app(OperationContext::class));

    app()->instance(ConversationDirectory::class, fakeDirectory($this->inst->id, []));
    app()->instance(PresenceService::class, Mockery::mock(PresenceService::class));
    config(['teamcalls.authorization.driver' => 'collaboration']);
    expect(app(ConversationAccess::class))->toBeInstanceOf(ConversationDirectoryAccess::class)
        ->and(app(Presence::class))->toBeInstanceOf(CollaborationPresence::class)
        ->and(app(UserDirectory::class))->toBeInstanceOf(CollaborationUserDirectory::class);
});

it('collaboration.use: falla cerrado mientras Core no defina la habilidad; después, solo quien la tiene', function () {
    $dir = new CollaborationUserDirectory;
    expect($dir->canUseCalls($this->ana))->toBeFalse();

    Gate::define('collaboration.use', fn ($user) => in_array($user->id, [$this->ana->id, $this->beto->id], true));
    expect($dir->canUseCalls($this->ana))->toBeTrue()
        ->and($dir->canUseCalls($this->carla))->toBeFalse()
        ->and($dir->canContact($this->ana, $this->beto))->toBeTrue()
        ->and($dir->canContact($this->ana, $this->carla))->toBeFalse()
        ->and($dir->canContact($this->ana, $this->ana))->toBeFalse();
    $this->beto->forceFill(['status' => 'inactive'])->save();
    expect($dir->canUseCalls($this->beto->fresh()))->toBeFalse();
});

it('conversaciones vía ConversationDirectory: por cuenta del actor; sin actor (API interna) se deniega todo', function () {
    TC::fakes();
    $core = FakeCollaborationCore::bind();
    $dir = fakeDirectory($this->inst->id, [$this->ana->id, $this->beto->id, $this->carla->id]);
    app()->instance(ConversationAccess::class, new ConversationDirectoryAccess($dir, app(OperationContext::class)));
    app()->forgetInstance(CallService::class);
    $gw = app(CallsGateway::class);
    $ref = new ConversationRef(ConversationType::Group, 'grp-dir-1');
    $a = FakeCollaborationCore::actor($this->ana, 'dev-ana-1');

    $s = $gw->start($a, CallTarget::conversation($ref, [$this->beto->id, $this->carla->id]), CallMedia::Video, new CommandMetadata('dir-key-0001', 'corr-1'));
    expect(collect($s->participants)->pluck('userId')->sort()->values()->all())->toBe([$this->ana->id, $this->beto->id, $this->carla->id]);
    $gw->cancel($a, $s->id, new CommandMetadata('dir-cancel-1', 'corr-2'));

    expect(TC::reason(fn () => TC::service()->start($this->ana, InternalTarget::conversation(new InternalRef('group', 'grp-dir-1'), [$this->beto->id]),
        InternalMedia::Video, 'dir-key-0002', 'pc-1')))->toBe('forbidden');
    expect(fn () => $gw->start($a, CallTarget::conversation($ref, [$this->dani->id]), CallMedia::Video, new CommandMetadata('dir-key-0003', 'corr-3')))
        ->toThrow(ContractException::class);
    $d = FakeCollaborationCore::actor($this->dani, 'dev-dani-1');
    expect(fn () => $gw->start($d, CallTarget::conversation($ref, [$this->ana->id]), CallMedia::Video, new CommandMetadata('dir-key-0004', 'corr-4')))
        ->toThrow(ContractException::class);
});

it('revocaciones: dispositivo (sale solo ese dispositivo), membresía (expulsión de ESA conversación) y cuenta', function () {
    TC::fakes();
    FakeCollaborationCore::bind();
    $gw = app(CallsGateway::class);
    $actors = [];
    foreach ([$this->ana, $this->beto, $this->carla] as $u) {
        $actors[$u->id] = FakeCollaborationCore::actor($u, 'dev-'.$u->id);
    }
    $s = $gw->start($actors[$this->ana->id], CallTarget::users([$this->beto->id, $this->carla->id]), CallMedia::Video, new CommandMetadata('rev-key-0001', 'corr-1'));
    foreach ([$this->beto, $this->carla] as $u) {
        $gw->accept($actors[$u->id], $s->id, new CommandMetadata('rev-acc-'.$u->id.'x', 'corr-2'));
        $gw->join($actors[$u->id], $s->id, new CommandMetadata('rev-join-'.$u->id.'x', 'corr-3'));
    }
    $gw->join($actors[$this->ana->id], $s->id, new CommandMetadata('rev-join-host', 'corr-4'));
    $revocations = app(CallAccessRevocations::class);
    $ctx = new InstitutionContext($this->inst->id);

    // Dispositivo: Beto sale con ESE dispositivo; otro dispositivo suyo es otro asunto. Repetir no hace nada.
    expect($revocations->deviceRevoked($ctx, $this->beto->id, 'dev-other'))->toBe(0)
        ->and($revocations->deviceRevoked($ctx, $this->beto->id, 'dev-'.$this->beto->id))->toBe(1)
        ->and($revocations->deviceRevoked($ctx, $this->beto->id, 'dev-'.$this->beto->id))->toBe(0);
    $now = $gw->find($actors[$this->ana->id], $s->id);
    expect(collect($now->participants)->firstWhere('userId', $this->beto->id)->state)->toBe(ParticipantState::Left)
        ->and(CallEvent::query()->where('call_id', $s->id)->where('type', 'call.participant.revoked')->where('subject_id', $this->beto->id)->first()?->data)
        ->toBe(['reason' => 'device_revoked']);
    // No es una expulsión: puede volver desde otro dispositivo válido.
    $back = $gw->join(FakeCollaborationCore::actor($this->beto, 'dev-beto-2'), $s->id, new CommandMetadata('rev-rejoin-01', 'corr-5'));
    expect($back->viewerState)->toBe(ParticipantState::Joined);

    // Cuenta revocada: fuera de todas sus llamadas vivas.
    expect($revocations->userRevoked($ctx, $this->carla->id))->toBe(1);
    expect(collect($gw->find($actors[$this->ana->id], $s->id)->participants)->firstWhere('userId', $this->carla->id)->state)->toBe(ParticipantState::Left);
});

it('revocación de membresía: expulsa de las llamadas vivas de esa conversación', function () {
    TC::fakes();
    $ref = new InternalRef('group', 'grp-rev-1');
    TC::$conversations->add($ref, $this->inst->id, [$this->ana->id, $this->beto->id, $this->carla->id]);
    $call = TC::service()->start($this->ana, InternalTarget::conversation($ref, [$this->beto->id, $this->carla->id]), InternalMedia::Video, 'mem-key-0001', 'pc-a');
    TC::service()->accept($this->beto, $call->id, 'b1');

    $n = app(CallAccessRevocations::class)->membershipRemoved(new InstitutionContext($this->inst->id),
        new ConversationRef(ConversationType::Group, 'grp-rev-1'), $this->beto->id);
    $beto = TC::fresh($call)->participantFor($this->beto->id);
    expect($n)->toBe(1)->and($beto->revoked_at)->not->toBeNull()
        ->and(TC::reason(fn () => TC::service()->join($this->beto, $call->id, 'b1')))->not->toBe('sin-error');
});

it('presencia común: solo después de confirmar, con la institución de la llamada', function () {
    $calls = new ArrayObject;
    $presence = new class($calls) implements PresenceService
    {
        public function __construct(public ArrayObject $calls) {}

        public function heartbeat(ActorContext $context): PresenceSnapshot
        {
            throw new LogicException('no usado');
        }

        public function setManualStatus(ActorContext $context, ManualPresence $status): PresenceSnapshot
        {
            throw new LogicException('no usado');
        }

        public function snapshot(ActorContext $context, array $userIds): array
        {
            return [];
        }

        public function setInCall(InstitutionContext $context, int $userId, string $callId, int $ttlSeconds): void
        {
            $this->calls[] = ['set', $context->institutionId, $userId, $callId];
        }

        public function clearInCall(InstitutionContext $context, int $userId, string $callId): void
        {
            $this->calls[] = ['clear', $context->institutionId, $userId, $callId];
        }
    };
    $adapter = new CollaborationPresence($presence, app(\Modules\Core\Tenancy\CurrentInstitution::class));

    try {
        DB::transaction(function () use ($adapter) {
            $adapter->setInCall($this->ana->id, 'call-x', 60);
            throw new RuntimeException('revertida');
        });
    } catch (RuntimeException) {
    }
    expect($calls->getArrayCopy())->toBe([]);

    DB::transaction(fn () => $adapter->setInCall($this->ana->id, 'call-y', 60));
    DB::transaction(fn () => $adapter->clearInCall($this->ana->id, 'call-y'));
    expect($calls->getArrayCopy())->toBe([['set', $this->inst->id, $this->ana->id, 'call-y'], ['clear', $this->inst->id, $this->ana->id, 'call-y']]);
});

it('LiveKit: https/wss siempre; http/ws solo con la excepción explícita de desarrollo y en local/testing', function (string $env, bool $flag, bool $complete) {
    config(['teamcalls.media.livekit' => [
        'api_url' => 'http://127.0.0.1:7880', 'client_url' => 'ws://127.0.0.1:7880',
        'api_key' => 'APIdevkey', 'api_secret' => str_repeat('s', 40), 'allow_insecure_dev_urls' => $flag,
    ]]);
    $previous = app()['env'];
    app()['env'] = $env;
    try {
        expect(LiveKitConfig::fromConfig()->isComplete())->toBe($complete);
        app()->forgetInstance(MediaRoomService::class);
    } finally {
        app()['env'] = $previous;
    }
})->with([
    'testing sin excepción' => ['testing', false, false],
    'testing con excepción' => ['testing', true, true],
    'local con excepción' => ['local', true, true],
    'staging con excepción' => ['staging', true, false],
    'producción con excepción' => ['production', true, false],
]);
