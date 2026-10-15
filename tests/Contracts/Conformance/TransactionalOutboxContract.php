<?php

declare(strict_types=1);

namespace Tests\Contracts\Conformance;

use DateTimeImmutable;
use Modules\CollaborationCore\Data\InstitutionContext;
use Modules\CollaborationRealtime\Contracts\TransactionalEventRecorder;
use Modules\CollaborationRealtime\Data\DeliveryAudience;
use Modules\CollaborationRealtime\Data\EventEnvelope;
use PHPUnit\Framework\TestCase;

/** Extend when the SQL implementation exists. No fake store, no database bootstrap here. */
abstract class TransactionalOutboxContract extends TestCase
{
    abstract protected function recorder(): TransactionalEventRecorder;

    abstract protected function beginTransaction(): void;

    abstract protected function rollbackTransaction(): void;

    abstract protected function commitTransaction(): void;

    abstract protected function persistedCount(string $eventId): int;

    abstract protected function networkPublishCount(): int;

    private function event(): EventEnvelope
    {
        return new EventEnvelope('outbox-contract-1', 'call.ended', 1, 1, 'call', 'call-1', 1, 2,
            new DateTimeImmutable('2026-10-15T15:00:00Z'), 'request-1', null,
            ['call_id' => 'call-1', 'status' => 'cancelled']);
    }

    public function test_rollback_removes_event_and_no_network_is_called(): void
    {
        $this->beginTransaction();
        try {
            $this->recorder()->append(new InstitutionContext(1), $this->event(), new DeliveryAudience(1, [2]));
            self::assertSame(0, $this->networkPublishCount());
        } finally {
            $this->rollbackTransaction();
        }
        self::assertSame(0, $this->persistedCount('outbox-contract-1'));
    }

    public function test_duplicate_append_produces_one_durable_event(): void
    {
        $this->beginTransaction();
        try {
            $recorder = $this->recorder();
            $first = $recorder->append(new InstitutionContext(1), $this->event(), new DeliveryAudience(1, [2]));
            $second = $recorder->append(new InstitutionContext(1), $this->event(), new DeliveryAudience(1, [2]));
            self::assertSame($first, $second);
            $this->commitTransaction();
        } catch (\Throwable $error) {
            $this->rollbackTransaction();
            throw $error;
        }
        self::assertSame(1, $this->persistedCount('outbox-contract-1'));
        self::assertSame(0, $this->networkPublishCount());
    }
}
