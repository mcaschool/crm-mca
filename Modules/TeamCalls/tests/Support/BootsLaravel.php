<?php

declare(strict_types=1);

namespace Modules\TeamCalls\Tests\Support;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\Concerns;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Arranque de Laravel (igual que Illuminate\Foundation\Testing\TestCase) para las suites de conformidad
 * de los contratos comunes, que heredan de un TestCase de PHPUnit puro. Misma BD de prueba y
 * RefreshDatabase que el resto de pruebas de TeamCalls.
 */
trait BootsLaravel
{
    use Concerns\InteractsWithAuthentication;
    use Concerns\InteractsWithConsole;
    use Concerns\InteractsWithContainer;
    use Concerns\InteractsWithDatabase;
    use Concerns\InteractsWithDeprecationHandling;
    use Concerns\InteractsWithExceptionHandling;
    use Concerns\InteractsWithSession;
    use Concerns\InteractsWithTestCaseLifecycle;
    use Concerns\InteractsWithTime;
    use RefreshDatabase;

    /** @var array<class-string, int> */
    protected array $traitsUsedByTest;

    public function createApplication(): Application
    {
        $app = require Application::inferBasePath().'/bootstrap/app.php';
        $this->traitsUsedByTest = class_uses_recursive(static::class);
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    protected function refreshApplication(): void
    {
        $this->app = $this->createApplication();
    }

    protected function setUp(): void
    {
        $this->setUpTheTestEnvironment();
    }

    protected function tearDown(): void
    {
        $this->tearDownTheTestEnvironment();
    }

    public static function tearDownAfterClass(): void
    {
        static::tearDownAfterClassUsingTestCase();
    }
}
