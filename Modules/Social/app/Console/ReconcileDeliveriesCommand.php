<?php

declare(strict_types=1);

namespace Modules\Social\Console;

use Illuminate\Console\Command;
use Modules\Social\Services\DeliveryReconciler;

/**
 * Resuelve los salientes sin confirmar (nunca quedan «Enviando…» indefinidamente). Lo lanza el
 * scheduler cada minuto, de forma independiente del worker de respuestas. Nunca reenvía nada.
 */
class ReconcileDeliveriesCommand extends Command
{
    protected $signature = 'social:reconcile-deliveries';

    protected $description = 'Resuelve los mensajes salientes que quedaron sin confirmar (fallido o entrega desconocida), sin reenviarlos.';

    public function handle(DeliveryReconciler $reconciler): int
    {
        $done = $reconciler->run();
        $this->info("Sin enviar: {$done['failed']} · entrega desconocida: {$done['unknown']}");

        return self::SUCCESS;
    }
}
