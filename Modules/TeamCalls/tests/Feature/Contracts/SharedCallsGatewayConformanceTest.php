<?php

declare(strict_types=1);

namespace Modules\TeamCalls\Tests\Feature\Contracts;

use Modules\CollaborationCore\Contracts\CallsGateway;
use Modules\CollaborationCore\Data\ActorContext;
use Modules\CollaborationCore\Data\CallTarget;
use Modules\CollaborationCore\Data\CommandMetadata;
use Modules\CollaborationCore\Enums\CallMedia;
use Modules\TeamCalls\Integration\Collaboration\SharedCallsGateway;
use Modules\TeamCalls\Tests\Support\BootsLaravel;
use Modules\TeamCalls\Tests\Support\FakeCollaborationCore;
use Modules\TeamCalls\Tests\Support\TC;
use Tests\Contracts\Conformance\CallsGatewayContract;

/**
 * Suite de conformidad OFICIAL del contrato CallsGateway (tests/Contracts/Conformance, de Codex) contra
 * el adaptador real de TeamCalls, sobre la BD de prueba. Core se sustituye por un doble de sus
 * interfaces oficiales: esto prueba el adaptador, no la integración con el Core real.
 */
final class SharedCallsGatewayConformanceTest extends CallsGatewayContract
{
    use BootsLaravel {
        BootsLaravel::setUp as bootLaravel;
    }

    private FakeCollaborationCore $core;

    private ActorContext $hostActor;

    private ActorContext $memberActor;

    private string $callId;

    private int $extra;

    protected function setUp(): void
    {
        $this->bootLaravel();
        TC::fakes();
        $this->core = FakeCollaborationCore::bind();
        $institution = TC::institution();
        [$ana, $beto, $carla] = [TC::user($institution), TC::user($institution), TC::user($institution)];
        $this->hostActor = FakeCollaborationCore::actor($ana, 'dev-ana-1');
        $this->memberActor = FakeCollaborationCore::actor($beto, 'dev-beto-1');
        $this->extra = (int) $carla->id;

        $gateway = $this->gateway();
        $call = $gateway->start($this->hostActor, CallTarget::users([(int) $beto->id]), CallMedia::Video, new CommandMetadata('start-cmd-0001', 'corr-0001'));
        $gateway->accept($this->memberActor, $call->id, new CommandMetadata('accept-cmd-001', 'corr-0002'));
        $gateway->join($this->memberActor, $call->id, new CommandMetadata('join-cmd-0001', 'corr-0003'));
        $gateway->join($this->hostActor, $call->id, new CommandMetadata('join-cmd-0002', 'corr-0004'));
        $this->callId = $call->id;
    }

    protected function gateway(): CallsGateway
    {
        $gateway = app(CallsGateway::class);
        self::assertInstanceOf(SharedCallsGateway::class, $gateway);

        return $gateway;
    }

    protected function host(): ActorContext
    {
        return $this->hostActor;
    }

    protected function member(): ActorContext
    {
        return $this->memberActor;
    }

    protected function activeCallId(): string
    {
        return $this->callId;
    }

    protected function extraUserId(): int
    {
        return $this->extra;
    }

    protected function revokeDevice(ActorContext $actor): void
    {
        $this->core->revoke($actor, $actor->deviceId);
    }
}
