<?php

declare(strict_types=1);

namespace Modules\TeamCalls\Tests\Feature\Integration4B;

use Modules\CollaborationCore\Contracts\CallsGateway;
use Modules\CollaborationCore\Contracts\DeviceRegistry;
use Modules\CollaborationCore\Data\ActorContext;
use Modules\CollaborationCore\Data\CallTarget;
use Modules\CollaborationCore\Data\CommandMetadata;
use Modules\CollaborationCore\Enums\CallMedia;
use Modules\TeamCalls\Integration\Collaboration\SharedCallsGateway;
use Modules\TeamCalls\Tests\Support\BootsLaravel;
use Modules\TeamCalls\Tests\Support\RealCollaboration;
use Modules\TeamCalls\Tests\Support\TC;
use Tests\Contracts\Conformance\CallsGatewayContract;

/**
 * Suite OFICIAL de conformidad CallsGatewayContract con el CollaborationCore REAL: actores de
 * ActorContextFactory sobre sesiones con MFA, dispositivos de DatabaseDeviceRegistry y revocación con
 * DeviceRegistry::revoke. Sin dobles de Core (solo el servidor de medios).
 */
final class RealCoreConformanceTest extends CallsGatewayContract
{
    use BootsLaravel {
        BootsLaravel::setUp as bootLaravel;
    }

    private ActorContext $hostActor;

    private ActorContext $memberActor;

    private string $callId;

    private int $extra;

    protected function setUp(): void
    {
        $this->bootLaravel();
        RealCollaboration::setUp();
        $institution = TC::institution();
        [$ana, $beto, $carla] = [TC::user($institution), TC::user($institution), TC::user($institution)];
        $this->hostActor = RealCollaboration::actor($ana);
        $this->memberActor = RealCollaboration::actor($beto);
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
        app(DeviceRegistry::class)->revoke($actor, $actor->deviceId);
    }
}
