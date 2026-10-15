<?php

declare(strict_types=1);

namespace Tests\Contracts\Unit;

use DateTimeImmutable;
use InvalidArgumentException;
use Modules\CollaborationRealtime\Data\DeliveryAudience;
use Modules\CollaborationRealtime\Data\DeliveryEnvelope;
use Modules\CollaborationRealtime\Data\EventEnvelope;
use Modules\CollaborationRealtime\Data\SyncPage;
use PHPUnit\Framework\TestCase;

final class EventContractTest extends TestCase
{
    private function event(string $type = 'call.ended', string $status = 'cancelled'): EventEnvelope
    {
        return new EventEnvelope('event-1', $type, 1, 42, 'call', 'call-1', 8, 17,
            new DateTimeImmutable('2026-10-15T11:00:00-04:00'), 'request-1', null,
            ['call_id' => 'call-1', 'status' => $status, 'reason' => 'cancelled_by_caller']);
    }

    public function test_cancellation_keeps_existing_event_name_and_data(): void
    {
        $json = json_decode(json_encode($this->event(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('call.ended', $json['type']);
        self::assertSame('cancelled', $json['data']['status']);
        self::assertSame('42', $json['institution_id']);
        self::assertSame('2026-10-15T15:00:00.000000Z', $json['occurred_at']);
        self::assertSame(8, $json['aggregate']['version']);
        self::assertArrayNotHasKey('recipients', $json);
        self::assertArrayNotHasKey('token', $json);
    }

    public function test_second_cancellation_name_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->event('call.cancelled');
    }

    public function test_ended_cannot_carry_active_state(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->event(status: 'active');
    }

    public function test_cross_institution_delivery_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new DeliveryEnvelope($this->event(), new DeliveryAudience(43, [17]));
    }

    public function test_audience_is_separate_and_can_exclude_accepting_device(): void
    {
        $delivery = new DeliveryEnvelope($this->event(), new DeliveryAudience(42, [17], 'web.device'));
        self::assertSame('web.device', $delivery->audience->exceptDeviceId);
        self::assertArrayNotHasKey('except_device_id', $delivery->event->jsonSerialize());
    }

    public function test_empty_sync_page_still_has_a_recovery_cursor(): void
    {
        self::assertSame('opaque-cut', (new SyncPage([], 'opaque-cut', false))->cursor);
        $this->expectException(InvalidArgumentException::class);
        new SyncPage([], '', false);
    }
}
