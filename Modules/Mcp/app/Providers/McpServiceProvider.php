<?php

declare(strict_types=1);

namespace Modules\Mcp\Providers;

use Modules\Mcp\Console\McpClientCommand;
use Nwidart\Modules\Support\ModuleServiceProvider;

class McpServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'Mcp';

    protected string $nameLower = 'mcp';

    /**
     * Command classes to register.
     *
     * @var string[]
     */
    protected array $commands = [
        McpClientCommand::class,
    ];

    /**
     * Provider classes to register.
     *
     * @var string[]
     */
    protected array $providers = [
        RouteServiceProvider::class,
    ];
}
