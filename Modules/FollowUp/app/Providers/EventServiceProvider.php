<?php

declare(strict_types=1);

namespace Modules\FollowUp\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Modules\FollowUp\Listeners\UpdateOpportunityFromSocialMessage;
use Modules\Social\Events\SocialMessageStored;

class EventServiceProvider extends ServiceProvider
{
    /** @var array<class-string, array<int, class-string>> */
    protected $listen = [
        SocialMessageStored::class => [
            UpdateOpportunityFromSocialMessage::class,
        ],
    ];
}
