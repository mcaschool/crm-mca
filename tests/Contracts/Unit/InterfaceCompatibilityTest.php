<?php

declare(strict_types=1);

namespace Tests\Contracts\Unit;

use Modules\CollaborationCore\Contracts\CallsGateway;
use Modules\CollaborationCore\Contracts\DeviceRegistry;
use Modules\CollaborationCore\Data\ActorContext;
use Modules\CollaborationCore\Data\AuthenticatedSessionContext;
use Modules\CollaborationCore\Data\CallSummary;
use Modules\CollaborationCore\Data\CommandMetadata;
use Modules\CollaborationCore\Exceptions\ContractException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class InterfaceCompatibilityTest extends TestCase
{
    public function test_gateway_has_full_lifecycle_and_invite_with_command_identity(): void
    {
        $methods = array_map(static fn (ReflectionMethod $m): string => $m->name, (new ReflectionClass(CallsGateway::class))->getMethods());
        foreach (['start', 'invite', 'accept', 'decline', 'cancel', 'join', 'leave', 'end', 'credentials', 'find', 'activeFor', 'recentFor', 'missedFor'] as $name) {
            self::assertContains($name, $methods);
        }
        $invite = new ReflectionMethod(CallsGateway::class, 'invite');
        self::assertSame([ActorContext::class, 'string', 'array', CommandMetadata::class],
            array_map(static fn ($p): string => (string) $p->getType(), $invite->getParameters()));
        self::assertSame(CallSummary::class, (string) $invite->getReturnType());
    }

    public function test_enrollment_does_not_require_an_already_registered_device(): void
    {
        $register = new ReflectionMethod(DeviceRegistry::class, 'register');
        self::assertSame(AuthenticatedSessionContext::class, (string) $register->getParameters()[0]->getType());
    }

    public function test_every_contract_and_dto_loads_without_laravel_or_database(): void
    {
        $root = dirname(__DIR__, 3);
        $loaded = 0;
        foreach (['CollaborationCore', 'CollaborationRealtime'] as $module) {
            $base = $root.'/Modules/'.$module.'/app/';
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base));
            foreach ($iterator as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                $relative = substr($file->getPathname(), strlen($base), -4);
                $class = 'Modules\\'.$module.'\\'.str_replace(['/', '\\'], '\\', $relative);
                self::assertTrue(class_exists($class) || interface_exists($class) || enum_exists($class), $class);
                $loaded++;
            }
        }
        self::assertGreaterThan(40, $loaded);
        self::assertFalse(class_exists('Illuminate\\Foundation\\Application', false));
    }

    public function test_errors_preserve_existing_code_and_exclude_internals(): void
    {
        $error = new ContractException('answered_elsewhere', 409);
        self::assertSame(['error' => ['code' => 'answered_elsewhere', 'message' => 'Already answered.', 'retryable' => false, 'request_id' => 'req-1']],
            $error->toResponse('req-1', 'Already answered.'));
    }

    public function test_error_status_mismatch_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ContractException('answered_elsewhere', 403);
    }

    public function test_core_does_not_depend_on_team_calls_or_realtime_implementations(): void
    {
        $base = dirname(__DIR__, 3).'/Modules/CollaborationCore/app';
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base)) as $file) {
            if ($file->getExtension() === 'php') {
                $source = file_get_contents($file->getPathname());
                self::assertStringNotContainsString('Modules\\TeamCalls\\', $source);
                self::assertStringNotContainsString('Modules\\CollaborationRealtime\\', $source);
            }
        }
    }
}
