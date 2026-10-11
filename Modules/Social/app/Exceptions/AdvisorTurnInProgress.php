<?php

declare(strict_types=1);

namespace Modules\Social\Exceptions;

use RuntimeException;

/**
 * Un worker tiene el turno (lease) de una conversación afectada por un corte del asesor: la
 * operación se revierte entera y se reintenta en unos segundos. El mensaje es apto para el panel.
 */
final class AdvisorTurnInProgress extends RuntimeException {}
