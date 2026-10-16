<?php

declare(strict_types=1);

namespace Modules\CollaborationRealtime\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\CollaborationCore\Contracts\RevocationRecorder;
use Modules\CollaborationCore\Data\InstitutionContext;
use Modules\CollaborationCore\Events\RevocationNotice;
use Modules\CollaborationRealtime\Contracts\TransactionalEventRecorder;
use Modules\CollaborationRealtime\Data\DeliveryAudience;
use Modules\CollaborationRealtime\Data\EventEnvelope;

/** One adapter into the existing outbox; never a parallel event store. */
final class RevocationEvents implements RevocationRecorder
{
    public function record(RevocationNotice $notice): string
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Revocation must share the mutation transaction.');
        }
        $id = (string) Str::ulid();

        return app(TransactionalEventRecorder::class)->append(new InstitutionContext($notice->institutionId),
            new EventEnvelope($id, $notice->type(), 1, $notice->institutionId, 'collaboration_revocation', $id, 1, null,
                now()->toDateTimeImmutable(), $id, null, $notice->data()),
            new DeliveryAudience($notice->institutionId, []));
    }
}
