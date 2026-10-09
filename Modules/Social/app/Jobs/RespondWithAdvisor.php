<?php

declare(strict_types=1);

namespace Modules\Social\Jobs;

use Modules\Core\Jobs\TenantAwareJob;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Social\Services\AdvisorTypingIndicator;
use Modules\Social\Services\SocialAdvisorResponder;
use Modules\Social\Support\AdvisorDispatcher;
use Throwable;

/**
 * Respuesta del asesor inteligente a un mensaje entrante de Instagram, Messenger o WhatsApp.
 * PERSISTENTE: la ingesta la encola (tabla `jobs`, cola «social-advisor») con la espera del canal
 * como retraso, y el webhook responde 200 sin esperar. La procesa social:advisor-worker (cron).
 *
 * Reintentos: si la IA falla de forma pasajera, el turno se deshace (sin respuesta enviada ni
 * mensaje duplicado) y el job vuelve a la cola; en el último intento la conversación pasa a
 * «Error de automatización» (con aviso a los administradores) y no se envía nada. Idempotencia:
 * advisor_message_receipts. Si otro worker atiende la misma conversación (turno persistente en
 * BD), el job vuelve a la cola a los pocos segundos sin gastar IA.
 */
final class RespondWithAdvisor extends TenantAwareJob
{
    public int $tries = 5;

    /**
     * Esperas crecientes cuando otro worker tiene el turno de la conversación. Suman 225 s, más que
     * la duración máxima del turno (180 s): si aquel worker murió, su turno ya caducó antes del
     * último intento. Los intentos son finitos ($tries): nunca hay liberaciones infinitas.
     *
     * @var array<int, int>
     */
    private const BUSY_BACKOFF = [15, 30, 60, 120];

    /** @var array<int, int> segundos entre intentos */
    public array $backoff = [30, 120];

    public function __construct(
        public int $messageId,
        ?int $institutionId = null,
    ) {
        parent::__construct($institutionId);
        $this->onQueue(AdvisorDispatcher::queue());
    }

    protected function handleForInstitution(): void
    {
        // El «escribiendo» del canal caduca a los ~20–25 s y el worker corre cada minuto: se renueva
        // al empezar a preparar la respuesta (best-effort, con tiempo límite corto; no bloquea).
        app(AdvisorTypingIndicator::class)->forMessage($this->messageId);
        $outcome = app(SocialAdvisorResponder::class)->respond($this->messageId, $this->finalAttempt());

        if ($outcome === SocialAdvisorResponder::RETRY && $this->job !== null) {
            $this->release($this->backoff[max(0, $this->attempts() - 1)] ?? 120);
        } elseif ($outcome === SocialAdvisorResponder::BUSY && $this->job !== null) {
            $this->release(self::BUSY_BACKOFF[max(0, $this->attempts() - 1)] ?? 120);
        }
    }

    /** Agotados los intentos por una excepción: pasa a error con aviso, sin enviar nada. */
    public function failed(?Throwable $e): void
    {
        $context = app(CurrentInstitution::class);
        $giveUp = fn () => app(SocialAdvisorResponder::class)->giveUp($this->messageId);

        $this->institutionId !== null ? $context->runFor($this->institutionId, $giveUp) : $context->runGlobally($giveUp);
    }

    private function finalAttempt(): bool
    {
        // Fuera de un worker (cola sync/pruebas) no hay reintento posible: es el último intento.
        return $this->job === null
            || $this->job->getConnectionName() === 'sync'
            || $this->attempts() >= $this->tries;
    }
}
