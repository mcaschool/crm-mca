<?php

declare(strict_types=1);

namespace Modules\FollowUp\Providers;

use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Modules\FollowUp\Console\DetectOpportunitiesCommand;
use Modules\FollowUp\Console\MaintainActionsCommand;
use Modules\FollowUp\Livewire\Opportunities;
use Modules\FollowUp\Livewire\Settings;
use Modules\FollowUp\Models\FollowUpOpportunity;
use Modules\FollowUp\Policies\FollowUpOpportunityPolicy;
use Nwidart\Modules\Support\ModuleServiceProvider;

class FollowUpServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'FollowUp';

    protected string $nameLower = 'followup';

    /** @var string[] */
    protected array $commands = [
        DetectOpportunitiesCommand::class,
        MaintainActionsCommand::class,
        \Modules\FollowUp\Console\DispatchActionsCommand::class,
        \Modules\FollowUp\Console\StopExecutionCommand::class,
        \Modules\FollowUp\Console\SyncCommercialCommand::class,
        \Modules\FollowUp\Console\ReconcileAnalyticsCommand::class,
    ];

    protected array $providers = [
        EventServiceProvider::class,
    ];

    public function boot(): void
    {
        parent::boot();

        Gate::policy(FollowUpOpportunity::class, FollowUpOpportunityPolicy::class);
        Livewire::component('followup.opportunities', Opportunities::class);
        Livewire::component('followup.settings', Settings::class);
    }
}
