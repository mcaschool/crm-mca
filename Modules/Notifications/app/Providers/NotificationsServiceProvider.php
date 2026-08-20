<?php

declare(strict_types=1);

namespace Modules\Notifications\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Modules\Notifications\Livewire\EmailSenders\Manage as EmailSendersManage;
use Modules\Notifications\Livewire\EmailTemplates\Manage as EmailTemplatesManage;
use Modules\Notifications\Models\EmailSender;
use Modules\Notifications\Models\EmailTemplate;
use Modules\Notifications\Policies\EmailSenderPolicy;
use Modules\Notifications\Policies\EmailTemplatePolicy;
use Nwidart\Modules\Support\ModuleServiceProvider;

class NotificationsServiceProvider extends ModuleServiceProvider
{
    /**
     * The name of the module.
     */
    protected string $name = 'Notifications';

    /**
     * The lowercase version of the module name.
     */
    protected string $nameLower = 'notifications';

    /**
     * Autorizacion y componentes del panel del modulo.
     */
    public function boot(): void
    {
        parent::boot();

        Gate::policy(EmailSender::class, EmailSenderPolicy::class);
        Gate::policy(EmailTemplate::class, EmailTemplatePolicy::class);

        Livewire::component('notifications.email-senders', EmailSendersManage::class);
        Livewire::component('notifications.email-templates', EmailTemplatesManage::class);
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
