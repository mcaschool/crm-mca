<?php

declare(strict_types=1);

namespace Modules\Mcp\Support;

use RuntimeException;

/**
 * Error OAuth 2.0 (RFC 6749 §5.2): lleva el código `error` estándar (invalid_request,
 * invalid_grant, invalid_client, invalid_scope, unsupported_grant_type…) + descripción
 * saneada + status HTTP. Nunca contiene secretos.
 */
final class OAuthException extends RuntimeException
{
    public function __construct(
        public readonly string $error,
        string $description = '',
        public readonly int $status = 400,
    ) {
        parent::__construct($description !== '' ? $description : $error);
    }
}
