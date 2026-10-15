<?php

declare(strict_types=1);

namespace Modules\CollaborationRealtime\Data;

use Modules\CollaborationCore\Support\Guard;

final readonly class EventEnvelope implements \JsonSerializable
{
    /** @param array<string, mixed> $data */
    public function __construct(
        public string $id,
        public string $type,
        public int $version,
        public int $institutionId,
        public string $aggregateType,
        public string $aggregateId,
        public int $aggregateVersion,
        public ?int $actorId,
        public \DateTimeImmutable $occurredAt,
        public string $correlationId,
        public ?string $causationId,
        public array $data,
    ) {
        foreach ([$id, $aggregateType, $aggregateId, $correlationId] as $value) {
            Guard::token($value);
        }
        if ($causationId !== null) {
            Guard::token($causationId);
        }
        Guard::id($institutionId);
        if ($actorId !== null) {
            Guard::id($actorId);
        }
        if ($version < 1 || $aggregateVersion < 1 || preg_match('/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$/D', $type) !== 1) {
            throw new \InvalidArgumentException('Invalid event version or type.');
        }
        json_encode($data, JSON_THROW_ON_ERROR);
        if ($type === 'call.cancelled') {
            throw new \InvalidArgumentException('Use call.ended with data.status cancelled.');
        }
        if ($type === 'call.ended' && (
            $aggregateType !== 'call' || ($data['call_id'] ?? null) !== $aggregateId ||
            ! in_array($data['status'] ?? null, ['ended', 'declined', 'cancelled', 'missed', 'failed'], true)
        )) {
            throw new \InvalidArgumentException('Invalid terminal call event.');
        }
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id, 'type' => $this->type, 'v' => $this->version,
            'occurred_at' => $this->occurredAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s.u\\Z'),
            'institution_id' => (string) $this->institutionId,
            'actor_id' => $this->actorId === null ? null : (string) $this->actorId,
            'aggregate' => ['type' => $this->aggregateType, 'id' => $this->aggregateId, 'version' => $this->aggregateVersion],
            'correlation_id' => $this->correlationId, 'causation_id' => $this->causationId, 'data' => $this->data,
        ];
    }
}
