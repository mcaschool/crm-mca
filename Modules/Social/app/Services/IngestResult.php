<?php

declare(strict_types=1);

namespace Modules\Social\Services;

/**
 * Resultado de ingerir un mensaje entrante:
 *  - created:   se guardó un mensaje nuevo (y se actualizó la conversación).
 *  - duplicate: el external_message_id ya existía (webhook reintentado) → no se duplicó.
 *  - parked:    el canal (provider + channel_external_id) no está configurado → no se creó nada.
 */
final readonly class IngestResult
{
    private function __construct(
        public string $status,
        public ?int $conversationId,
        public ?int $messageId,
    ) {}

    public static function created(int $conversationId, int $messageId): self
    {
        return new self('created', $conversationId, $messageId);
    }

    public static function duplicate(int $conversationId, int $messageId): self
    {
        return new self('duplicate', $conversationId, $messageId);
    }

    public static function parked(): self
    {
        return new self('parked', null, null);
    }
}
