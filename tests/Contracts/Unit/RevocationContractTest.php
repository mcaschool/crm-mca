<?php

declare(strict_types=1);

namespace Tests\Contracts\Unit;

use Modules\CollaborationCore\Events\CollaborationAccessRevoked;
use Modules\CollaborationCore\Events\CollaborationDeviceRevoked;
use Modules\CollaborationCore\Events\ConversationMembershipRevoked;
use Modules\CollaborationCore\Integration\CallPresenceAuthorizer;
use Modules\CollaborationCore\Integration\CallPresenceCleanupAuthorizer;
use PHPUnit\Framework\TestCase;

final class RevocationContractTest extends TestCase
{
    public function test_notices_are_immutable_and_contain_only_scope_references(): void
    {
        $notices = [
            new CollaborationAccessRevoked(1, 2, 'user_inactive'),
            new CollaborationDeviceRevoked(1, 2, 'device-1', 'logout'),
            new ConversationMembershipRevoked(1, 2, 'channel', 'channel-1', 'removed'),
        ];
        foreach ($notices as $notice) {
            self::assertTrue((new \ReflectionClass($notice))->isReadOnly());
            self::assertSame(1, $notice->institutionId);
            self::assertSame(2, $notice->data()['user_id']);
            self::assertSame(['user_id', 'scope', 'resource_id', 'reason'], array_keys($notice->data()));
        }
        self::assertSame('collaboration.access.revoked', $notices[0]->type());
        self::assertSame('collaboration.device.revoked', $notices[1]->type());
        self::assertSame('conversation.membership.revoked', $notices[2]->type());
    }

    public function test_historical_cleanup_is_an_additive_port_not_a_broadened_authorize(): void
    {
        self::assertTrue(is_subclass_of(CallPresenceCleanupAuthorizer::class, CallPresenceAuthorizer::class));
        self::assertCount(1, (new \ReflectionClass(CallPresenceAuthorizer::class))->getMethods());
        $method = new \ReflectionMethod(CallPresenceCleanupAuthorizer::class, 'authorizeCleanup');
        self::assertSame('void', (string) $method->getReturnType());
        self::assertSame(['Modules\\CollaborationCore\\Data\\InstitutionContext', 'int', 'string'],
            array_map(fn ($parameter) => (string) $parameter->getType(), $method->getParameters()));
    }
}
