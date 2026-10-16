<?php

declare(strict_types=1);

namespace Modules\TeamCalls\Tests\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\CollaborationCore\Data\InstitutionContext;
use Modules\CollaborationRealtime\Contracts\TransactionalEventRecorder;
use Modules\CollaborationRealtime\Data\DeliveryAudience;
use Modules\CollaborationRealtime\Data\EventEnvelope;
use Modules\CollaborationRealtime\Services\DatabaseEventRecorder;
use Modules\TeamCalls\Contracts\CallEventPublisher;
use Modules\TeamCalls\Support\CallEnvelope;

/**
 * Doble del outbox de CollaborationRealtime sobre su interfaz OFICIAL, SOLO para el agregado «call» de las
 * pruebas del motor (dispositivos ficticios). Anota lo recibido con el nivel de transacción en que llegó;
 * si la transacción se revierte, lo anotado en ella se descarta (como exige el contrato).
 *
 * Todo lo demás (revocaciones durables de Core, eventos de TeamChat) va al recorder REAL de Realtime: las
 * pruebas del motor no desactivan Core. La integración real de los call.* se prueba en Integration4B.
 */
final class FakeOutbox implements TransactionalEventRecorder
{
    /** @var array<string, array{event: EventEnvelope, audience: DeliveryAudience, level: int}> por id de evento, en orden */
    public array $appended = [];

    /**
     * @param  CallEventPublisher|null  $mirror  observador de pruebas: recibe, TRAS CONFIRMAR, la misma entrega
     *                                           (sobre + audiencia) que el outbox, como hacía el puerto propio.
     */
    public function __construct(private readonly ?TransactionalEventRecorder $others = null, private readonly ?CallEventPublisher $mirror = null) {}

    public static function bind(?CallEventPublisher $mirror = null): self
    {
        $real = class_exists(DatabaseEventRecorder::class) ? app(DatabaseEventRecorder::class) : null;
        $outbox = new self($real, $mirror);
        app()->instance(TransactionalEventRecorder::class, $outbox);

        return $outbox;
    }

    public function append(InstitutionContext $context, EventEnvelope $event, DeliveryAudience $audience): string
    {
        if ($event->aggregateType !== 'call') {
            return $this->others?->append($context, $event, $audience) ?? throw new \LogicException('No recorder for '.$event->aggregateType);
        }
        if (DB::transactionLevel() === 0 || $context->institutionId !== $event->institutionId || $audience->institutionId !== $event->institutionId) {
            throw new \LogicException('Outbox contract violated.');
        }
        $this->appended[$event->id] = ['event' => $event, 'audience' => $audience, 'level' => DB::transactionLevel()];
        DB::afterRollBack(function () use ($event): void {
            unset($this->appended[$event->id]);
        });
        if ($this->mirror !== null) {
            $envelope = new CallEnvelope($event->id, $event->type, $event->aggregateId, $event->institutionId, $event->actorId,
                $event->data, $audience->userIds, Carbon::instance($event->occurredAt), $audience->exceptDeviceId);
            DB::afterCommit(fn () => $this->mirror->publish($envelope));
        }

        return $event->id;
    }

    /** @return list<EventEnvelope> */
    public function events(?string $callId = null): array
    {
        return array_values(array_map(fn (array $a) => $a['event'],
            array_filter($this->appended, fn (array $a) => $callId === null || $a['event']->aggregateId === $callId)));
    }
}
