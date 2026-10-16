<?php

declare(strict_types=1);

namespace Modules\CollaborationRealtime\Services;

use Modules\CollaborationRealtime\Data\DeliveryAudience;
use Modules\CollaborationRealtime\Data\DeliveryEnvelope;
use Modules\CollaborationRealtime\Data\EventEnvelope;

final class EventCodec
{
    public function decode(object $row): DeliveryEnvelope
    {
        $e = json_decode($row->envelope, true, 32, JSON_THROW_ON_ERROR);
        $a = json_decode($row->audience, true, 32, JSON_THROW_ON_ERROR);

        return new DeliveryEnvelope(
            new EventEnvelope($e['id'], $e['type'], $e['v'], (int) $e['institution_id'], $e['aggregate']['type'], $e['aggregate']['id'],
                $e['aggregate']['version'], $e['actor_id'] === null ? null : (int) $e['actor_id'], new \DateTimeImmutable($e['occurred_at']),
                $e['correlation_id'], $e['causation_id'], $e['data']),
            new DeliveryAudience((int) $row->institution_id, $a['users'], $a['except'], $row->expires_at ? new \DateTimeImmutable($row->expires_at, new \DateTimeZone('UTC')) : null),
        );
    }

    /** @param array<mixed> $value */
    public function canonical(array $value): string
    {
        $sort = function (mixed $item) use (&$sort): mixed {
            if (! is_array($item)) {
                if (is_object($item) || is_resource($item)) {
                    throw new \InvalidArgumentException('Payload must contain JSON primitives only.');
                }

                return $item;
            }
            if (! array_is_list($item)) {
                ksort($item);
            }

            return array_map($sort, $item);
        };

        return json_encode($sort($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
