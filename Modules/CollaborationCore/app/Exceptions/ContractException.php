<?php

declare(strict_types=1);

namespace Modules\CollaborationCore\Exceptions;

final class ContractException extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        public readonly int $httpStatus,
        public readonly bool $retryable = false,
    ) {
        $expected = match ($errorCode) {
            'unauthenticated', 'session_revoked' => 401,
            'forbidden', 'two_factor_required', 'device_revoked' => 403,
            'not_found' => 404,
            'invalid_transition', 'answered_elsewhere', 'idempotency_conflict', 'busy' => 409,
            'sync_cursor_expired' => 410,
            'invalid', 'too_many_participants' => 422,
            'too_many_requests' => 429,
            'media_unavailable' => 503,
            default => throw new \InvalidArgumentException('Unknown public contract error.'),
        };
        if ($httpStatus !== $expected) {
            throw new \InvalidArgumentException('Error/status mismatch.');
        }
        parent::__construct($errorCode);
    }

    /** @return array{error: array{code: string, message: string, retryable: bool, request_id: string}} */
    public function toResponse(string $requestId, string $publicMessage): array
    {
        return ['error' => ['code' => $this->errorCode, 'message' => $publicMessage, 'retryable' => $this->retryable, 'request_id' => $requestId]];
    }
}
