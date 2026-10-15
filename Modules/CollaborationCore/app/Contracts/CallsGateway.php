<?php

declare(strict_types=1);

namespace Modules\CollaborationCore\Contracts;

use Modules\CollaborationCore\Data\ActorContext;
use Modules\CollaborationCore\Data\CallSummary;
use Modules\CollaborationCore\Data\CallTarget;
use Modules\CollaborationCore\Data\CommandMetadata;
use Modules\CollaborationCore\Data\ConversationRef;
use Modules\CollaborationCore\Data\CursorPage;
use Modules\CollaborationCore\Data\JoinCredentials;
use Modules\CollaborationCore\Data\PageRequest;
use Modules\CollaborationCore\Enums\CallMedia;

/** Implemented exclusively by TeamCalls through an adapter. Recheck authorization even for command replays. No media secrets in summaries. */
interface CallsGateway
{
    public function start(ActorContext $context, CallTarget $target, CallMedia $media, CommandMetadata $command): CallSummary;

    /** @param list<int> $userIds A repeated command MUST NOT re-ring a participant who subsequently declined. */
    public function invite(ActorContext $context, string $callId, array $userIds, CommandMetadata $command): CallSummary;

    public function accept(ActorContext $context, string $callId, CommandMetadata $command): CallSummary;

    public function decline(ActorContext $context, string $callId, CommandMetadata $command): CallSummary;

    public function cancel(ActorContext $context, string $callId, CommandMetadata $command): CallSummary;

    public function join(ActorContext $context, string $callId, CommandMetadata $command): CallSummary;

    public function leave(ActorContext $context, string $callId, CommandMetadata $command): CallSummary;

    /** End for everyone: deny unauthorized callers; never silently delegate to leave. */
    public function end(ActorContext $context, string $callId, CommandMetadata $command): CallSummary;

    public function credentials(ActorContext $context, string $callId): JoinCredentials;

    public function find(ActorContext $context, string $callId): CallSummary;

    public function activeFor(ActorContext $context, ConversationRef $ref): ?CallSummary;

    /** @return CursorPage<CallSummary> */
    public function recentFor(ActorContext $context, PageRequest $page): CursorPage;

    /** @return CursorPage<CallSummary> */
    public function missedFor(ActorContext $context, PageRequest $page): CursorPage;
}
