<?php

declare(strict_types=1);

namespace Modules\Social\Console;

use Illuminate\Console\Command;
use Modules\Social\Services\SocialPostScheduler;

/**
 * Publica las publicaciones programadas que ya vencieron (todas las instituciones), reanuda los
 * videos de Instagram que Meta seguía procesando y cierra las ejecuciones interrumpidas.
 *
 * Lo lanza el scheduler cada minuto (un único cron `schedule:run` en Hostinger): no necesita un
 * worker permanente. Trabaja ~50 s como máximo por pasada; lo que no quepa sale en la siguiente.
 * Seguro ante ejecuciones simultáneas: cada publicación se reclama de forma atómica.
 */
class PublishScheduledPostsCommand extends Command
{
    protected $signature = 'social:publish-scheduled
        {--limit=20 : Publicaciones vencidas como máximo por pasada}
        {--budget=50 : Segundos máximos de trabajo por pasada}';

    protected $description = 'Publica en Facebook e Instagram las publicaciones programadas que ya vencieron.';

    public function handle(SocialPostScheduler $scheduler): int
    {
        $r = $scheduler->tick(max(1, (int) $this->option('limit')), max(5, (int) $this->option('budget')));

        $this->info("Publicador: {$r['published']} ejecutadas · {$r['skipped']} omitidas · {$r['resumed']} reanudadas · {$r['recovered']} interrumpidas.");

        return self::SUCCESS;
    }
}
