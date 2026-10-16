<?php

declare(strict_types=1);

namespace Modules\CollaborationCore\Events;

/** Contract 1.1: immutable domain payload; delivery uses the durable EventEnvelope ID, not Laravel fire-and-forget. */
abstract readonly class RevocationNotice
{
    protected function __construct(public int $institutionId, public int $userId, public string $scope, public string $resourceId, public string $reason) {}

    abstract public function type(): string;

    /** @return array{user_id:int,scope:string,resource_id:string,reason:string} */
    public function data(): array
    {
        return ['user_id' => $this->userId, 'scope' => $this->scope, 'resource_id' => $this->resourceId, 'reason' => $this->reason];
    }
}
