<?php

declare(strict_types=1);

namespace Modules\TeamCalls\Integration\Collaboration;

use App\Models\User;
use InvalidArgumentException;
use Modules\CollaborationCore\Contracts\CallsGateway;
use Modules\CollaborationCore\Data\ActorContext;
use Modules\CollaborationCore\Data\CallSummary;
use Modules\CollaborationCore\Data\CallTarget;
use Modules\CollaborationCore\Data\CommandMetadata;
use Modules\CollaborationCore\Data\ConversationRef;
use Modules\CollaborationCore\Data\CursorPage;
use Modules\CollaborationCore\Data\JoinCredentials;
use Modules\CollaborationCore\Data\PageRequest;
use Modules\CollaborationCore\Enums\CallMedia;
use Modules\CollaborationCore\Enums\ConversationType;
use Modules\CollaborationCore\Exceptions\ContractException;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\TeamCalls\Enums\CallMedia as InternalMedia;
use Modules\TeamCalls\Exceptions\CallException;
use Modules\TeamCalls\Models\Call;
use Modules\TeamCalls\Services\CallService;
use Modules\TeamCalls\Support\CallSummary as InternalSummary;
use Modules\TeamCalls\Support\CallTarget as InternalTarget;
use Modules\TeamCalls\Support\ConversationRef as InternalRef;

/**
 * CallsGateway del contrato común (MCA Colaboración 1.0) sobre el motor existente de TeamCalls.
 *
 * - La identidad, la institución, la sesión y el dispositivo salen del ActorContext (servidor), nunca
 *   del cliente; ActorResolver falla cerrado mientras CollaborationCore no esté integrado.
 * - Cada operación corre en la institución del actor y con la correlación del comando, que acaba en los
 *   eventos (correlation_id / causation_id).
 * - Las reglas siguen siendo las de CallService (bloqueos, transiciones, idempotencia, aislamiento).
 * - Errores: solo los códigos públicos del contrato (ContractException); nunca modelos internos.
 */
final class SharedCallsGateway implements CallsGateway
{
    public function __construct(
        private readonly CallService $calls,
        private readonly ActorResolver $actors,
        private readonly CurrentInstitution $institution,
        private readonly OperationContext $operation,
    ) {}

    public function start(ActorContext $context, CallTarget $target, CallMedia $media, CommandMetadata $command): CallSummary
    {
        return $this->run($context, $command, function (User $user) use ($context, $target, $media, $command): CallSummary {
            $internal = $target->conversation !== null
                ? InternalTarget::conversation(new InternalRef($target->conversation->type->value, $target->conversation->id),
                    $target->conversation->type === ConversationType::Direct ? [] : $target->userIds)
                : InternalTarget::users($target->userIds);
            $call = $this->calls->start($user, $internal, InternalMedia::from($media->value), $command->idempotencyKey, $context->deviceId);

            return $this->summary($call, $user);
        });
    }

    public function invite(ActorContext $context, string $callId, array $userIds, CommandMetadata $command): CallSummary
    {
        return $this->run($context, $command, fn (User $user) => $this->summary(
            $this->calls->invite($user, $callId, $userIds, $command->idempotencyKey), $user));
    }

    public function accept(ActorContext $context, string $callId, CommandMetadata $command): CallSummary
    {
        return $this->run($context, $command, fn (User $user) => $this->summary(
            $this->calls->accept($user, $callId, $context->deviceId), $user));
    }

    public function decline(ActorContext $context, string $callId, CommandMetadata $command): CallSummary
    {
        return $this->run($context, $command, fn (User $user) => $this->summary($this->calls->decline($user, $callId), $user));
    }

    public function cancel(ActorContext $context, string $callId, CommandMetadata $command): CallSummary
    {
        return $this->run($context, $command, fn (User $user) => $this->summary($this->calls->cancel($user, $callId), $user));
    }

    public function join(ActorContext $context, string $callId, CommandMetadata $command): CallSummary
    {
        return $this->run($context, $command, fn (User $user) => $this->summary(
            $this->calls->join($user, $callId, $context->deviceId), $user));
    }

    public function leave(ActorContext $context, string $callId, CommandMetadata $command): CallSummary
    {
        return $this->run($context, $command, fn (User $user) => $this->summary(
            $this->calls->leave($user, $callId, $context->deviceId), $user));
    }

    /** Para todos: solo el anfitrión; cualquier otra persona recibe «forbidden» (nunca equivale a salir). */
    public function end(ActorContext $context, string $callId, CommandMetadata $command): CallSummary
    {
        return $this->run($context, $command, fn (User $user) => $this->summary($this->calls->terminate($user, $callId), $user));
    }

    public function credentials(ActorContext $context, string $callId): JoinCredentials
    {
        return $this->run($context, null, fn (User $user) => SharedSummaries::credentials(
            $this->calls->credentials($user, $callId, $context->deviceId)));
    }

    public function find(ActorContext $context, string $callId): CallSummary
    {
        return $this->run($context, null, fn (User $user) => SharedSummaries::call($this->calls->find($user, $callId)));
    }

    public function activeFor(ActorContext $context, ConversationRef $ref): ?CallSummary
    {
        return $this->run($context, null, function (User $user) use ($ref): ?CallSummary {
            $call = $this->calls->activeForConversation($user, $ref->type->value, $ref->id);

            return $call !== null ? $this->summary($call, $user) : null;
        });
    }

    public function recentFor(ActorContext $context, PageRequest $page): CursorPage
    {
        return $this->page($context, $page, 'recent');
    }

    public function missedFor(ActorContext $context, PageRequest $page): CursorPage
    {
        return $this->page($context, $page, 'missed');
    }

    /** @return CursorPage<CallSummary> */
    private function page(ActorContext $context, PageRequest $page, string $kind): CursorPage
    {
        return $this->run($context, null, function (User $user) use ($context, $page, $kind): CursorPage {
            $after = HistoryCursor::decode($context, $kind, $page->cursor);
            [$items, $next] = $this->calls->historyPage($user, $kind === 'missed', $after, $page->limit);

            return new CursorPage(
                array_map(fn (InternalSummary $s) => SharedSummaries::call($s), $items),
                $next !== null ? HistoryCursor::encode($context, $kind, $next) : null,
            );
        });
    }

    private function summary(Call $call, User $viewer): CallSummary
    {
        return SharedSummaries::call(InternalSummary::from($call, $viewer->id));
    }

    /**
     * @template T
     *
     * @param  callable(User): T  $fn
     * @return T
     */
    private function run(ActorContext $context, ?CommandMetadata $command, callable $fn): mixed
    {
        try {
            return $this->institution->runFor($context->institutionId, fn () => $this->operation->run(
                $context,
                $command !== null ? $command->correlationId : $context->requestId,
                $context->requestId,
                fn () => $fn($this->actors->resolve($context)),
            ));
        } catch (CallException $e) {
            throw new ContractException($e->reason, $e->status, $e->reason === 'media_unavailable');
        } catch (InvalidArgumentException $e) {
            throw new ContractException('invalid', 422);
        }
    }
}
