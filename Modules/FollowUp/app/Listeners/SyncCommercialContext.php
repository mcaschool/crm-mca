<?php

declare(strict_types=1);

namespace Modules\FollowUp\Listeners;

use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Crm\Events\CommercialRecordChanged;
use Modules\FollowUp\Events\CommercialSyncRequested;
use Modules\FollowUp\Models\FollowUpOpportunity;
use Modules\FollowUp\Services\FollowUpCommercialCoordinator;
use Modules\Social\Events\SocialConversationStateChanged;
use Modules\Social\Events\SocialMessageStored;

final class SyncCommercialContext implements ShouldHandleEventsAfterCommit
{
    public function handle(CommercialRecordChanged|CommercialSyncRequested|SocialConversationStateChanged|SocialMessageStored $event): void
    {
        app(CurrentInstitution::class)->runFor($event->institutionId, function () use ($event): void {
            $entity = $event instanceof SocialConversationStateChanged || $event instanceof SocialMessageStored ? 'social' : $event->entity;
            $id = $event instanceof SocialConversationStateChanged || $event instanceof SocialMessageStored ? $event->conversationId : $event->recordId;
            $query = FollowUpOpportunity::query();
            match ($entity) {
                'lead' => $query->where(function ($q) use ($id): void {
                    $q->where('lead_id', $id);
                    $contactId = \Modules\Crm\Models\Lead::query()->whereKey($id)->value('contact_id');
                    if ($contactId !== null) {
                        $q->orWhere('contact_id', $contactId);
                    }
                }),
                'contact' => $query->where(fn ($q) => $q->where('contact_id', $id)->orWhereNull('contact_id')),
                'activity' => $query->where(function ($q) use ($id): void {
                    $q->where('commercial_activity_id', $id);
                    $contactId = \Modules\Crm\Models\Activity::query()->whereKey($id)->value('contact_id');
                    if ($contactId !== null) {
                        $q->orWhere('contact_id', $contactId);
                    }
                }),
                'social' => $query->where('social_conversation_id', $id),
                default => $query->whereRaw('1 = 0'),
            };
            $query->select('id')->chunkById(100, function ($rows): void {
                foreach ($rows as $row) {
                    try {
                        app(FollowUpCommercialCoordinator::class)->sync((int) $row->id);
                    } catch (\Throwable) {
                        // The committed CRM operation must not fail due to a projection.
                        // The periodic reconciliation retries from current source records.
                        \Illuminate\Support\Facades\Log::warning('followup.commercial_sync_failed', ['institution_id' => app(CurrentInstitution::class)->idOrFail(), 'opportunity_id' => (int) $row->id]);
                    }
                }
            });
        });
    }
}
