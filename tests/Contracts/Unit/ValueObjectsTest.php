<?php

declare(strict_types=1);

namespace Tests\Contracts\Unit;

use DateTimeImmutable;
use InvalidArgumentException;
use Modules\CollaborationCore\Authorization\Capabilities;
use Modules\CollaborationCore\Data\ActorContext;
use Modules\CollaborationCore\Data\CallTarget;
use Modules\CollaborationCore\Data\CommandMetadata;
use Modules\CollaborationCore\Data\ConversationRef;
use Modules\CollaborationCore\Data\DeviceDescriptor;
use Modules\CollaborationCore\Data\JoinCredentials;
use Modules\CollaborationCore\Data\PageRequest;
use Modules\CollaborationCore\Enums\AuthenticationLevel;
use Modules\CollaborationCore\Enums\ConversationType;
use Modules\CollaborationCore\Enums\DevicePlatform;
use Modules\CollaborationCore\Enums\DeviceStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ValueObjectsTest extends TestCase
{
    public function test_neutral_actor_has_no_commercial_role_requirement(): void
    {
        $actor = new ActorContext(1, 2, 'web.device', 'session.ref', 'request.ref', AuthenticationLevel::Mfa);
        self::assertSame('collaboration.use', Capabilities::USE);
        self::assertSame(2, $actor->userId);
        self::assertFalse(property_exists($actor, 'crmRole'));
        self::assertTrue((new \ReflectionClass($actor))->isReadOnly());
    }

    public function test_device_registration_does_not_claim_an_active_session_or_presence(): void
    {
        $device = new DeviceDescriptor('android.device', 2, DevicePlatform::Android, DeviceStatus::Active);
        self::assertFalse(property_exists($device, 'online'));
        self::assertFalse(property_exists($device, 'sessionValid'));
    }

    public function test_rejects_invalid_actor_institution(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ActorContext(0, 2, 'device', 'session', 'request', AuthenticationLevel::Mfa);
    }

    public function test_direct_call_resolves_recipient_on_server(): void
    {
        $target = CallTarget::conversation(new ConversationRef(ConversationType::Direct, 'conversation-1'));
        self::assertSame([], $target->userIds);
    }

    public function test_direct_call_cannot_override_recipient(): void
    {
        $this->expectException(InvalidArgumentException::class);
        CallTarget::conversation(new ConversationRef(ConversationType::Direct, 'c1'), [99]);
    }

    #[DataProvider('groupTypes')]
    public function test_group_and_channel_cannot_implicitly_invite_everyone(ConversationType $type): void
    {
        $this->expectException(InvalidArgumentException::class);
        CallTarget::conversation(new ConversationRef($type, 'c1'));
    }

    public static function groupTypes(): iterable
    {
        yield [ConversationType::Group];
        yield [ConversationType::Channel];
    }

    public function test_explicit_selection_is_canonical_for_request_fingerprinting(): void
    {
        $ref = new ConversationRef(ConversationType::Group, 'c1');
        self::assertEquals(CallTarget::conversation($ref, [3, 2]), CallTarget::conversation($ref, [2, 3]));
    }

    #[DataProvider('badRecipients')]
    public function test_invalid_recipients_are_not_silently_coerced(array $ids): void
    {
        $this->expectException(InvalidArgumentException::class);
        CallTarget::users($ids);
    }

    public static function badRecipients(): iterable
    {
        yield [[]];
        yield [[2, 2]];
        yield [[0]];
        yield [['2']];
        yield [[1 => 2]];
    }

    public function test_key_boundary_matches_existing_team_calls(): void
    {
        self::assertSame(64, strlen((new CommandMetadata(str_repeat('a', 64), 'r1'))->idempotencyKey));
        $this->expectException(InvalidArgumentException::class);
        new CommandMetadata('short', 'r1');
    }

    public function test_page_bounds(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new PageRequest(null, 101);
    }

    public function test_credentials_require_secure_transport(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new JoinCredentials('http://media.example.test', 'secret', 'identity', new DateTimeImmutable('+1 minute'));
    }
}
