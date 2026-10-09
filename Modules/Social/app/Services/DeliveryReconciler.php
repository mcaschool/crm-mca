<?php

declare(strict_types=1);

namespace Modules\Social\Services;

use Illuminate\Support\Facades\Log;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Social\Models\SocialConversation;
use Modules\Social\Models\SocialMessage;
use Modules\Social\Support\SocialAutomationAlerts;

/**
 * Recupera los salientes del CRM que quedaron sin confirmar (caída del worker, proceso cortado)
 * SIN depender de que el job que enviaba vuelva a ejecutarse. Lo lanza el scheduler cada minuto
 * (social:reconcile-deliveries). Pasado social.delivery.unknown_after_seconds sin respuesta, eco
 * ni estado del proveedor:
 *
 *   pending  → failed            el proveedor NUNCA se llamó: seguro que no se envió.
 *   sending  → delivery_unknown  el proveedor pudo aceptarlo: NO se reenvía nunca.
 *
 * Si era una respuesta AUTOMÁTICA, la conversación sale de la automatización (error con motivo
 * visible) y se avisa una sola vez (alertas deduplicadas). Un eco posterior aún puede conciliarlo
 * como enviado (SocialIngestService). Ningún saliente queda «Enviando…» indefinidamente.
 */
final class DeliveryReconciler
{
    public function __construct(
        private readonly CurrentInstitution $context,
        private readonly SocialAutomationService $automation,
        private readonly SocialAutomationAlerts $alerts,
    ) {}

    /** @return array{failed: int, unknown: int} */
    public function run(): array
    {
        $cutoff = now()->subSeconds(max(30, (int) config('social.delivery.unknown_after_seconds', 180)));
        $stale = $this->context->runGlobally(fn () => SocialMessage::query()
            ->where('direction', 'outbound')
            ->whereIn('status', ['pending', 'sending'])
            ->whereNull('external_message_id')
            ->where('updated_at', '<', $cutoff)
            ->orderBy('id')
            ->get(['id', 'institution_id']));

        $done = ['failed' => 0, 'unknown' => 0];
        foreach ($stale as $row) {
            $outcome = $this->context->runFor((int) $row->institution_id, fn (): ?string => $this->resolve((int) $row->id, $cutoff));
            if ($outcome !== null) {
                $done[$outcome]++;
            }
        }

        return $done;
    }

    private function resolve(int $id, \DateTimeInterface $cutoff): ?string
    {
        // Relectura con condición: si un eco o el propio envío lo resolvió entretanto, no se toca.
        $message = SocialMessage::query()->whereKey($id)->whereIn('status', ['pending', 'sending'])
            ->whereNull('external_message_id')->where('updated_at', '<', $cutoff)->first();
        if ($message === null) {
            return null;
        }

        $neverSent = $message->status === 'pending';
        $message->status = $neverSent ? 'failed' : 'delivery_unknown';
        $message->ai_meta = array_merge((array) $message->ai_meta, [
            'send_status' => $neverSent ? 'not_sent' : 'delivery_unknown',
            'reconciled_by' => 'timeout',
            'reconciled_at' => now()->toIso8601String(),
        ]);
        $message->save();

        Log::warning('social.delivery: saliente sin confirmar resuelto por plazo', [
            'message_id' => $message->getKey(),
            'outcome' => $message->status,
            'sender_type' => $message->sender_type,
        ]);

        if ($message->sender_type === 'bot') {
            $this->stopAutomation((int) $message->social_conversation_id, $neverSent ? 'send_failed' : 'delivery_unknown');
        }

        return $neverSent ? 'failed' : 'unknown';
    }

    private function stopAutomation(int $conversationId, string $reason): void
    {
        $conversation = SocialConversation::query()->with('channel')->find($conversationId);
        if ($conversation === null) {
            return;
        }
        if (in_array($conversation->automation_state ?? 'bot', ['bot', 'waiting_human'], true)) {
            $this->automation->fail($conversation, $reason);   // pausa + aviso (deduplicado)
        } elseif ($conversation->automation_state !== SocialConversation::ERROR_STATE) {
            // Ya la atiende una persona o está pausada: no se cambia su estado, pero se avisa.
            $this->alerts->automationStopped($conversation, $reason);
        }
    }
}
