<?php

declare(strict_types=1);

namespace Modules\TeamCalls\Integration\LiveKit;

use RuntimeException;

/** Webhook del servidor de medios sin firma válida (el motivo es técnico y nunca incluye secretos). */
final class InvalidMediaWebhook extends RuntimeException {}
