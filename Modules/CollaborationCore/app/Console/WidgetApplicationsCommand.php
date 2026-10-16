<?php

declare(strict_types=1);

namespace Modules\CollaborationCore\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\CollaborationCore\Services\WidgetApplications;

final class WidgetApplicationsCommand extends Command
{
    protected $signature = 'collaboration:widget {action} {id?} {--institution=} {--name=} {--origin=*} {--environment=production} {--capability=*} {--position=right} {--theme=light}';

    protected $description = 'Register/disable widget applications or prune expired delegated credentials; trusted operator only';

    public function handle(WidgetApplications $apps): int
    {
        $action = $this->argument('action');
        $id = (string) $this->argument('id');
        if ($action === 'prune') {
            DB::table('collab_widget_grants')->where('expires_at', '<', now()->subDay())->delete();
            DB::table('collab_widget_sessions')->where('absolute_expires_at', '<', now()->subDay())->delete();
            $this->info('Expired widget credentials pruned');

            return self::SUCCESS;
        }
        if ($action === 'disable') {
            DB::transaction(function () use ($id): void {
                DB::table('collab_widget_apps')->where('id', $id)->update(['enabled' => false, 'updated_at' => now()]);
                DB::table('collab_widget_sessions')->where('app_id', $id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
            });
            $this->info('Application disabled');

            return self::SUCCESS;
        }
        if ($action !== 'register') {
            $this->error('Use register, disable or prune');

            return self::FAILURE;
        }
        $apps->register($id, (int) $this->option('institution'), (string) $this->option('name'), $this->option('origin'), (string) $this->option('environment'), $this->option('capability') ?: ['chat', 'media', 'notifications'], ['position' => (string) $this->option('position'), 'theme' => (string) $this->option('theme')]);
        $this->info('Application registered');

        return self::SUCCESS;
    }
}
