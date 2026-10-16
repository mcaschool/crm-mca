<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Modules\CollaborationCore\Contracts\ActorContextFactory;
use Modules\CollaborationCore\Data\ActorContext;
use Modules\CollaborationCore\Services\MfaSessionProof;
use Modules\CollaborationRealtime\Data\EventEnvelope;
use Modules\CollaborationRealtime\Integration\EventPolicy;
use Modules\CollaborationRealtime\Services\EventPolicies;

final class RealtimeTestPolicy implements EventPolicy
{
    public bool $allowed = true;

    public function __construct(private bool $temporary = false) {}

    public function validate(EventEnvelope $event): void
    {
        if (array_diff(array_keys($event->data), ['value', 'call_id', 'status', 'password'])) {
            throw new InvalidArgumentException('Unexpected payload fields');
        }
    }

    public function allows(ActorContext $actor, EventEnvelope $event): bool
    {
        return $this->allowed;
    }

    public function ephemeral(): bool
    {
        return $this->temporary;
    }
}
function realtimePolicies(): RealtimeTestPolicy
{
    // Integración 4B: registro PROPIO de la prueba. Los dominios instalados ya registran sus tipos (TeamCalls,
    // dueño de call.*, registra call.ended); se conservan las políticas internas de revocación de Realtime.
    $registry = new EventPolicies;
    foreach (\Modules\CollaborationRealtime\Services\RevocationPolicy::TYPES as $type) {
        $registry->register($type, new \Modules\CollaborationRealtime\Services\RevocationPolicy);
    }
    app()->instance(EventPolicies::class, $registry);
    $policy = new RealtimeTestPolicy;
    foreach (['test.changed', 'call.ended'] as $type) {
        app(EventPolicies::class)->register($type, $policy);
    }
    app(EventPolicies::class)->register('test.typing', new RealtimeTestPolicy(true));

    return $policy;
}
function realtimeActor(User $user): ActorContext
{
    $request = Request::create('/');
    $session = new Store('test', new ArraySessionHandler(120));
    $session->start();
    MfaSessionProof::record($session, $user);
    $request->setLaravelSession($session);
    $request->setUserResolver(fn () => $user);

    return app(ActorContextFactory::class)->fromAuthenticatedRequest($request);
}
function realtimeEvent(ActorContext $a, string $id = 'event-1', int $version = 1, string $aggregate = 'resource-1', string $type = 'test.changed', array $data = ['value' => 1]): EventEnvelope
{
    return new EventEnvelope($id, $type, 1, $a->institutionId, $type === 'call.ended' ? 'call' : 'test', $aggregate, $version,
        $a->userId, new DateTimeImmutable('2026-10-01T00:00:00Z'), 'correlation-1', null, $data);
}
