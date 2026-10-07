<?php

declare(strict_types=1);

namespace Modules\Social\Console;

use Illuminate\Console\Command;
use Modules\Social\Services\MetaLeadFormService;

/**
 * Recoge los contactos nuevos de los formularios publicitarios ACTIVOS de las Páginas que
 * reciben (todas las empresas, cada una en su contexto). Lo lanza el scheduler; idempotente.
 */
class MetaLeadsPollCommand extends Command
{
    protected $signature = 'social:meta-leads-poll';

    protected $description = 'Recoge los contactos nuevos de los formularios publicitarios activos de cada empresa.';

    public function handle(MetaLeadFormService $service): int
    {
        $this->line('Contactos registrados: '.$service->poll());

        return self::SUCCESS;
    }
}
