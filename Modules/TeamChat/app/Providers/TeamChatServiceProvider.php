<?php

declare(strict_types=1);

namespace Modules\TeamChat\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\CollaborationCore\Contracts\ConversationDirectory;
use Modules\CollaborationRealtime\Services\EventPolicies;
use Modules\TeamChat\Integration\ChatEventPolicy;
use Modules\TeamChat\Services\DirectConversationDirectory;

final class TeamChatServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/config.php', 'teamchat');
        $this->app->bind(ConversationDirectory::class, DirectConversationDirectory::class);
    }

    public function boot(): void
    {
        \Illuminate\Support\Facades\RateLimiter::for('teamchat.commands', fn (\Illuminate\Http\Request $request) => \Illuminate\Cache\RateLimiting\Limit::perMinute(30)->by('teamchat:'.($request->user()->id ?? $request->ip())));
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
        $this->loadRoutesFrom(__DIR__.'/../../routes/web.php');
        $this->loadViewsFrom(__DIR__.'/../../resources/views', 'teamchat');
        $this->loadTranslationsFrom(__DIR__.'/../../lang', 'teamchat');
        $policies = $this->app->make(EventPolicies::class);
        foreach (['conversation.created', 'message.created', 'receipt.delivered', 'receipt.read', 'membership.changed',
            'group.created', 'group.updated', 'group.members.changed', 'team.created', 'team.updated', 'team.members.changed', 'channel.created', 'channel.updated', 'channel.members.changed'] as $type) {
            $policies->register('teamchat.'.$type, $this->app->make(ChatEventPolicy::class));
        }
    }
}
