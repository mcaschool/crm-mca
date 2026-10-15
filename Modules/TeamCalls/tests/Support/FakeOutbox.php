<?php

declare(strict_types=1);

namespace Modules\TeamCalls\Tests\Support;

use Illuminate\Support\Facades\DB;
use Modules\CollaborationCore\Data\InstitutionContext;
use Modules\CollaborationRealtime\Contracts\TransactionalEventRecorder;
use Modules\CollaborationRealtime\Data\DeliveryAudience;
use Modules\CollaborationRealtime\Data\EventEnvelope;

/**
 * Doble del outbox de CollaborationRealtime sobre su interfaz OFICIAL. Anota lo recibido con el nivel de
 * transacción en que llegó; si la transacción se revierte, lo anotado en ella se descarta (como exige el
 * contrato). No es un outbox: no persiste ni publica nada.
 */
final class FakeOutbox implements TransactionalEventRecorder
{
    /** @var array<string, array{event: EventEnvelope, audience: DeliveryAudience, level: int}> por id de evento, en orden */
    public array $appended = [];

    public static function bind(): self
    {
        $outbox = new self;
        app()->instance(TransactionalEventRecorder::class, $outbox);

        return $outbox;
    }

    public function append(InstitutionContext $context, EventEnvelope $event, DeliveryAudience $audience): string
    {
        if (DB::transactionLevel() === 0 || $context->institutionId !== $event->institutionId || $audience->institutionId !== $event->institutionId) {
            throw new \LogicException('Outbox contract violated.');
        }
        $this->appended[$event->id] = ['event' => $event, 'audience' => $audience, 'level' => DB::transactionLevel()];
        DB::afterRollBack(function () use ($event): void {
            unset($this->appended[$event->id]);
        });

        return $event->id;
    }

    /** @return list<EventEnvelope> */
    public function events(?string $callId = null): array
    {
        return array_values(array_map(fn (array $a) => $a['event'],
            array_filter($this->appended, fn (array $a) => $callId === null || $a['event']->aggregateId === $callId)));
    }
}
