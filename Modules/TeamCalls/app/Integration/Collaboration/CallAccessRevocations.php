<?php

declare(strict_types=1);

namespace Modules\TeamCalls\Integration\Collaboration;

use Modules\CollaborationCore\Data\ConversationRef as SharedRef;
use Modules\CollaborationCore\Data\InstitutionContext;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\TeamCalls\Services\CallService;
use Modules\TeamCalls\Support\ConversationRef;

/**
 * Puntos de entrada para las revocaciones que emitirá CollaborationCore/TeamChat. El contrato 1.0 aún no
 * define los eventos de revocación, así que NADIE los invoca todavía: los mecanismos actuales (logout,
 * revokeAccess, webhooks y barrido de medios) siguen siendo los vigentes. Cada uno corre en la
 * institución indicada y es idempotente (repetirlo no vuelve a tocar llamadas ya resueltas).
 */
final class CallAccessRevocations
{
    public function __construct(private readonly CallService $calls, private readonly CurrentInstitution $institution) {}

    /** Cuenta desactivada o retirada de la institución: sale de todas sus llamadas vivas. */
    public function userRevoked(InstitutionContext $context, int $userId): int
    {
        return $this->institution->runFor($context->institutionId, fn (): int => $this->calls->revokeAccess($userId, 'user_revoked'));
    }

    /** Pérdida del permiso collaboration.use: igual que una cuenta revocada, solo para llamadas. */
    public function collaborationAccessLost(InstitutionContext $context, int $userId): int
    {
        return $this->institution->runFor($context->institutionId, fn (): int => $this->calls->revokeAccess($userId, 'access_revoked'));
    }

    /** Dispositivo revocado: sale con ESE dispositivo (y se rota la sala); puede volver desde otro válido. */
    public function deviceRevoked(InstitutionContext $context, int $userId, string $deviceId): int
    {
        return $this->institution->runFor($context->institutionId, fn (): int => $this->calls->revokeDevice($userId, $deviceId));
    }

    /** Baja de la conversación: expulsión de las llamadas vivas de ESA conversación. */
    public function membershipRemoved(InstitutionContext $context, SharedRef $ref, int $userId): int
    {
        return $this->institution->runFor($context->institutionId,
            fn (): int => $this->calls->revokeMembership(new ConversationRef($ref->type->value, $ref->id), $userId));
    }
}
