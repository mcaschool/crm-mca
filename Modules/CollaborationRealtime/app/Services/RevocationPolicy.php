<?php

declare(strict_types=1);

namespace Modules\CollaborationRealtime\Services;

use Modules\CollaborationCore\Data\ActorContext;
use Modules\CollaborationRealtime\Data\EventEnvelope;
use Modules\CollaborationRealtime\Integration\EventPolicy;

final class RevocationPolicy implements EventPolicy
{
    public const TYPES = ['collaboration.access.revoked', 'collaboration.device.revoked', 'conversation.membership.revoked'];

    public function validate(EventEnvelope $event): void
    {
        $data = $event->data;
        $keys = array_keys($data);
        sort($keys);
        if (! in_array($event->type, self::TYPES, true) || $event->version !== 1 ||
            $event->aggregateType !== 'collaboration_revocation' || $event->aggregateId !== $event->id ||
            $keys !== ['reason', 'resource_id', 'scope', 'user_id'] ||
            ! is_int($data['user_id']) || $data['user_id'] < 1 ||
            ! is_string($data['resource_id']) || ! is_string($data['reason']) ||
            ! in_array($data['scope'], ['user', 'device', 'direct', 'group', 'channel', 'team'], true) ||
            ! in_array($data['reason'], ['application_revoked', 'user_inactive', 'institution_changed', 'credentials_changed', 'institution_disabled', 'device_revoked', 'session_ended', 'logout', 'eligibility_lost', 'left', 'removed', 'team_removed'], true)) {
            throw new \InvalidArgumentException('Invalid revocation event.');
        }
        \Modules\CollaborationCore\Support\Guard::token($data['resource_id']);
        $scope = $data['scope'];
        if (($event->type === self::TYPES[0] && $scope !== 'user') ||
            ($event->type === self::TYPES[1] && $scope !== 'device') ||
            ($event->type === self::TYPES[2] && ! in_array($scope, ['direct', 'group', 'channel', 'team'], true))) {
            throw new \InvalidArgumentException('Invalid revocation scope.');
        }
    }

    public function allows(ActorContext $actor, EventEnvelope $event): bool
    {
        return false;
    }

    public function ephemeral(): bool
    {
        return false;
    }
}
