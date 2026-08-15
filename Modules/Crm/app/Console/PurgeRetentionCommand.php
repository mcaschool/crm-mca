<?php

declare(strict_types=1);

namespace Modules\Crm\Console;

use Illuminate\Console\Command;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Crm\Models\Message;
use Modules\Institutions\Models\Institution;

/**
 * Purga de retencion (D5): elimina los `messages` con mas de N meses
 * (config crm.retention.messages_months, por defecto 24). SOLO toca messages;
 * nunca contactos ni leads ni eventos. Con --dry-run informa sin borrar.
 *
 * La programacion en cron es operativa del despliegue (no se define aqui).
 */
class PurgeRetentionCommand extends Command
{
    protected $signature = 'crm:purge-retention {--institution=} {--all-institutions} {--dry-run}';

    protected $description = 'Elimina los mensajes de conversacion mas antiguos que el umbral de retencion (solo messages).';

    public function handle(CurrentInstitution $context): int
    {
        $months = (int) config('crm.retention.messages_months', 24);
        $cutoff = now()->subMonths($months);
        $dryRun = (bool) $this->option('dry-run');

        $this->info("Umbral de retencion: {$months} meses (mensajes anteriores a {$cutoff->toDateString()}).");
        if ($dryRun) {
            $this->comment('Modo --dry-run: no se borrara nada.');
        }

        $ids = $this->resolveInstitutions($context);
        if ($ids === null) {
            return self::FAILURE;
        }

        $total = 0;
        foreach ($ids as $institutionId) {
            $count = $context->runFor($institutionId, function () use ($cutoff, $dryRun): int {
                $query = Message::query()->where('created_at', '<', $cutoff);
                $count = $query->count();

                if (! $dryRun && $count > 0) {
                    $query->delete();
                }

                return $count;
            });

            $this->line("  Institucion #{$institutionId}: {$count} mensajes ".($dryRun ? 'a purgar' : 'purgados').'.');
            $total += $count;
        }

        $this->info(($dryRun ? 'Se purgarian ' : 'Purgados ')."{$total} mensajes en total.");

        return self::SUCCESS;
    }

    /**
     * @return array<int,int>|null
     */
    private function resolveInstitutions(CurrentInstitution $context): ?array
    {
        if ($this->option('all-institutions')) {
            /** @var array<int,int> $ids */
            $ids = $context->runGlobally(fn () => Institution::query()->pluck('id')->all());

            return $ids;
        }

        $option = $this->option('institution');
        if ($option !== null) {
            return [(int) $option];
        }

        /** @var array<int,int> $ids */
        $ids = $context->runGlobally(fn () => Institution::query()->pluck('id')->all());

        if (count($ids) === 1) {
            return [(int) $ids[0]];
        }

        $this->error('Hay '.count($ids).' instituciones; usa --institution=ID o --all-institutions.');

        return null;
    }
}
