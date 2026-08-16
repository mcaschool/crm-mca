<?php

declare(strict_types=1);

namespace Modules\Chat\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Nwidart\Modules\Support\ModuleServiceProvider;

class ChatServiceProvider extends ModuleServiceProvider
{
    /**
     * The name of the module.
     */
    protected string $name = 'Chat';

    /**
     * The lowercase version of the module name.
     */
    protected string $nameLower = 'chat';

    /**
     * Rate limiting del widget (D8, obligatorio): por IP. El endpoint de
     * emparejamiento lleva un limite mas estricto (accion costosa).
     */
    public function boot(): void
    {
        parent::boot();

        RateLimiter::for('widget', fn (Request $request) => Limit::perMinute(
            (int) config('crm.widget.rate_per_min', 30)
        )->by((string) $request->ip()));

        RateLimiter::for('widget-message', fn (Request $request) => Limit::perMinute(
            (int) config('crm.widget.message_rate_per_min', 8)
        )->by((string) $request->ip()));
    }

    /**
     * Command classes to register.
     *
     * @var string[]
     */
    // protected array $commands = [];

    /**
     * Provider classes to register.
     *
     * @var string[]
     */
    protected array $providers = [
        EventServiceProvider::class,
        RouteServiceProvider::class,
    ];

    /**
     * Define module schedules.
     *
     * @param  $schedule
     */
    // protected function configureSchedules(Schedule $schedule): void
    // {
    //     $schedule->command('inspire')->hourly();
    // }
}
