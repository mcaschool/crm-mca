<?php

declare(strict_types=1);

namespace Modules\Core\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Core\Tenancy\CurrentInstitution;

/**
 * Job base consciente de la institucion (tapon de la fuga #1: colas).
 *
 * Un job se ejecuta minutos despues, en otro proceso, sin usuario autenticado:
 * el contexto de institucion NO existe alli. Esta clase captura institution_id
 * al construirse y lo restablece antes de ejecutar la logica, de modo que el
 * scope global sigue aislando correctamente dentro del worker.
 *
 * REGLA: ningun job del proyecto extiende Job/implementa ShouldQueue a secas;
 * todos extienden esta clase e implementan handleForInstitution().
 */
abstract class TenantAwareJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Institucion capturada en el momento de encolar.
     * (No es readonly: SerializesModels la re-hidrata al desserializar el job.)
     */
    public ?int $institutionId;

    public function __construct(?int $institutionId = null)
    {
        // Si no se pasa explicito, se toma del contexto vigente al encolar.
        $this->institutionId = $institutionId ?? app(CurrentInstitution::class)->id();
    }

    /**
     * Punto de entrada del worker. Restablece el contexto y delega.
     * Las subclases NO sobrescriben handle(); implementan handleForInstitution().
     */
    final public function handle(): void
    {
        $context = app(CurrentInstitution::class);

        if ($this->institutionId !== null) {
            $context->runFor($this->institutionId, fn () => $this->handleForInstitution());

            return;
        }

        // Job deliberadamente cross-tenant: modo global explicito.
        $context->runGlobally(fn () => $this->handleForInstitution());
    }

    abstract protected function handleForInstitution(): void;
}
