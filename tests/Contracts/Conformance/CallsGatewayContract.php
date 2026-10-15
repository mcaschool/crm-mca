<?php

declare(strict_types=1);

namespace Tests\Contracts\Conformance;

use Modules\CollaborationCore\Contracts\CallsGateway;
use Modules\CollaborationCore\Data\ActorContext;
use Modules\CollaborationCore\Data\CommandMetadata;
use Modules\CollaborationCore\Enums\CallStatus;
use Modules\CollaborationCore\Exceptions\ContractException;
use PHPUnit\Framework\TestCase;

/**
 * Extend in the TeamCalls adapter test suite with an isolated test database.
 * Not a fake implementation and not executed by phpunit.contracts.xml.
 */
abstract class CallsGatewayContract extends TestCase
{
    abstract protected function gateway(): CallsGateway;

    abstract protected function host(): ActorContext;

    abstract protected function member(): ActorContext;

    abstract protected function activeCallId(): string;

    abstract protected function extraUserId(): int;

    abstract protected function revokeDevice(ActorContext $actor): void;

    public function test_invite_command_replay_does_not_ring_twice(): void
    {
        $gateway = $this->gateway();
        $command = new CommandMetadata('invite-command-001', 'request-001');
        $first = $gateway->invite($this->host(), $this->activeCallId(), [$this->extraUserId()], $command);
        $second = $gateway->invite($this->host(), $this->activeCallId(), [$this->extraUserId()], $command);
        self::assertEquals($first->participants, $second->participants);
        self::assertSame($first->id, $second->id);
    }

    public function test_ordinary_member_cannot_end_for_everyone(): void
    {
        try {
            $this->gateway()->end($this->member(), $this->activeCallId(), new CommandMetadata('end-command-001', 'request-002'));
            self::fail('An ordinary member must not end the call or silently leave.');
        } catch (ContractException $error) {
            self::assertSame('forbidden', $error->errorCode);
        }
        self::assertSame(CallStatus::Active, $this->gateway()->find($this->host(), $this->activeCallId())->status);
    }

    public function test_revoked_device_cannot_obtain_media_credentials(): void
    {
        $actor = $this->member();
        $this->revokeDevice($actor);
        try {
            $this->gateway()->credentials($actor, $this->activeCallId());
            self::fail('Revoked devices must not obtain credentials.');
        } catch (ContractException $error) {
            self::assertContains($error->errorCode, ['device_revoked', 'session_revoked', 'unauthenticated']);
        }
    }
}
