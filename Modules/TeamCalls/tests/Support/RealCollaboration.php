<?php

declare(strict_types=1);

namespace Modules\TeamCalls\Tests\Support;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\DB;
use Modules\CollaborationCore\Contracts\ActorContextFactory;
use Modules\CollaborationCore\Data\ActorContext;
use Modules\CollaborationCore\Services\MfaSessionProof;
use Modules\CollaborationRealtime\Integration\WakeTransport;
use Modules\CollaborationRealtime\Services\OutboxWorker;
use Modules\TeamCalls\Contracts\CallEventPublisher;
use Modules\TeamCalls\Contracts\ConversationAccess;
use Modules\TeamCalls\Contracts\MediaRoomService;
use Modules\TeamCalls\Contracts\Presence;
use Modules\TeamCalls\Contracts\PushNotifier;
use Modules\TeamCalls\Services\CallService;

/**
 * Pruebas de integración 4B con los servicios REALES de CollaborationCore, CollaborationRealtime y
 * TeamChat (sin dobles de sus puertos). Solo se sustituyen:
 *  - el servidor de medios (RecordingCollaboration como MediaRoomService/Push: sin LiveKit en estas suites);
 *  - el envío de red del aviso WebSocket (WakeTransport), registrando a qué canal se avisaría.
 * Actores: ActorContextFactory real sobre una sesión con prueba MFA, como tras el login con TOTP.
 */
final class RealCollaboration
{
    /** @var list<string> canales privados a los que se habría enviado `collaboration.sync.required` */
    public static array $wakes = [];

    public static function setUp(): void
    {
        self::$wakes = [];
        TC::$rec = new RecordingCollaboration;
        // Solo medios y push quedan como dobles; presencia, eventos, directorio y revocaciones son reales.
        foreach ([MediaRoomService::class, PushNotifier::class] as $port) {
            app()->instance($port, TC::$rec);
        }
        foreach ([Presence::class, ConversationAccess::class, CallEventPublisher::class] as $port) {
            app()->forgetInstance($port);
        }
        app()->instance(WakeTransport::class, new class implements WakeTransport
        {
            public function send(string $privateChannel): void
            {
                RealCollaboration::$wakes[] = $privateChannel;
            }
        });
        app()->forgetInstance(CallService::class);
    }

    /** Sesión autenticada con MFA verificado y dispositivo registrado por Core (uno por sesión). */
    public static function actor(User $user, ?string $session = null): ActorContext
    {
        $request = Request::create('/api/v1/collaboration/calls');
        $store = new Store('test', new ArraySessionHandler(120), $session ?? str_pad('s'.$user->id, 40, 'x'));
        $store->start();
        MfaSessionProof::record($store, $user);
        $request->setLaravelSession($store);
        $request->setUserResolver(fn () => $user);

        return app(ActorContextFactory::class)->fromAuthenticatedRequest($request);
    }

    /** Ejecuta el worker real del outbox hasta vaciar lo pendiente de la institución. */
    public static function drain(int $institutionId, int $max = 200): int
    {
        $n = 0;
        while ($n < $max && app(OutboxWorker::class)->runOne($institutionId)) {
            $n++;
        }

        return $n;
    }

    /**
     * Entrega las revocaciones pendientes con RevocationDelivery REAL (lo que hace OutboxWorker tras
     * reclamarlas). Dentro de la transacción de prueba; el worker completo se prueba en worker-delivery.php.
     */
    public static function deliver(int $institutionId): int
    {
        $n = 0;
        foreach (DB::table('collab_outbox')->where('institution_id', $institutionId)->where('status', 'pending')->orderBy('available_at')->orderBy('event_id')->get() as $row) {
            $delivery = app(\Modules\CollaborationRealtime\Services\EventCodec::class)->decode(DB::table('collab_events')->where('id', $row->event_id)->first());
            if (in_array($delivery->event->type, \Modules\CollaborationRealtime\Services\RevocationPolicy::TYPES, true)) {
                app(\Modules\CollaborationRealtime\Services\RevocationDelivery::class)->deliver($delivery->event);
                DB::table('collab_outbox')->where('event_id', $row->event_id)->update(['status' => 'published']);
                $n++;
            }
        }

        return $n;
    }

    /** @return list<object> filas del outbox (estado de entrega) */
    public static function outbox(int $institutionId): array
    {
        return DB::table('collab_outbox')->where('institution_id', $institutionId)->orderBy('available_at')->get()->all();
    }

    /** @return list<object> eventos durables del agregado */
    public static function events(int $institutionId, string $aggregateType, ?string $aggregateId = null): array
    {
        return DB::table('collab_events')->where('institution_id', $institutionId)->where('aggregate_type', $aggregateType)
            ->when($aggregateId !== null, fn ($q) => $q->where('aggregate_id', $aggregateId))
            ->orderBy('position')->get()->all();
    }
}
