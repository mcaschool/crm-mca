<?php

declare(strict_types=1);

namespace Modules\Crm\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Modules\Crm\Console\PurgeRetentionCommand;
use Modules\Crm\Livewire\Contacts\Index as ContactsIndex;
use Modules\Crm\Livewire\Contacts\Show as ContactsShow;
use Modules\Crm\Livewire\Conversations\Show as ConversationsShow;
use Modules\Crm\Livewire\Leads\Index as LeadsIndex;
use Modules\Crm\Livewire\Leads\Show as LeadsShow;
use Modules\Crm\Models\Contact;
use Modules\Crm\Models\Lead;
use Modules\Crm\Policies\ContactPolicy;
use Modules\Crm\Policies\LeadPolicy;
use Nwidart\Modules\Support\ModuleServiceProvider;

class CrmServiceProvider extends ModuleServiceProvider
{
    /**
     * The name of the module.
     */
    protected string $name = 'Crm';

    /**
     * The lowercase version of the module name.
     */
    protected string $nameLower = 'crm';

    /**
     * Command classes to register.
     *
     * @var string[]
     */
    protected array $commands = [
        PurgeRetentionCommand::class,
    ];

    /**
     * Autorizacion y componentes del panel del modulo.
     */
    public function boot(): void
    {
        parent::boot();

        Gate::policy(Lead::class, LeadPolicy::class);
        Gate::policy(Contact::class, ContactPolicy::class);

        Livewire::component('crm.contacts.index', ContactsIndex::class);
        Livewire::component('crm.contacts.show', ContactsShow::class);
        Livewire::component('crm.leads.index', LeadsIndex::class);
        Livewire::component('crm.leads.show', LeadsShow::class);
        Livewire::component('crm.conversations.show', ConversationsShow::class);
    }

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
