<?php

declare(strict_types=1);

namespace Modules\Social\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Social\Jobs\ProcessWhatsAppPostOnboarding;
use Modules\Social\Models\SocialChannel;
use Modules\Social\Services\WhatsAppCoexistenceService;
use RuntimeException;

/**
 * Callback del Embedded Signup (Coexistence). PREPARADO pero protegido: mientras el
 * feature flag social.embedded_signup.enabled esté apagado, responde 409 y no toca nada
 * (imposible una conexión accidental en producción).
 *
 * Seguridad: sesión del panel (auth + institución + CSRF del grupo web) + permiso de
 * canales + state de UN SOLO USO emitido por el propio CRM (anti-CSRF/replay) ligado al
 * usuario y a la institución. El authorization code llega del navegador y se intercambia
 * INMEDIATAMENTE server-side; jamás se persiste ni se loguea.
 */
final class WhatsAppSignupController
{
    public function store(Request $request, WhatsAppCoexistenceService $coexistence, CurrentInstitution $context): JsonResponse
    {
        $user = $request->user();
        abort_unless($user !== null && $user->can('create', SocialChannel::class), 403);

        $data = $request->validate([
            'state' => ['required', 'string', 'max:120'],
            'code' => ['required', 'string', 'max:2048'],
            'waba_id' => ['required', 'string', 'max:64'],
            'phone_number_id' => ['required', 'string', 'max:64'],
            'display_phone_number' => ['nullable', 'string', 'max:32'],
        ]);

        if (! $coexistence->isEnabled()) {
            return response()->json(['status' => 'disabled', 'message' => __('La conexión de WhatsApp Business aún no está habilitada (configuración de Meta pendiente).')], 409);
        }

        // State de un solo uso, ligado al usuario y a SU institución (replay protection).
        $institutionId = $coexistence->consumeState((int) $user->id, (string) $data['state']);
        if ($institutionId === null || $institutionId !== $context->id()) {
            return response()->json(['status' => 'invalid_state', 'message' => __('La conexión expiró o no es válida. Vuelve a iniciar el proceso.')], 422);
        }

        try {
            $channel = $coexistence->completeSignup(
                $institutionId,
                (string) $data['code'],
                (string) $data['waba_id'],
                (string) $data['phone_number_id'],
                (string) ($data['display_phone_number'] ?? ''),
            );
        } catch (RuntimeException $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 422);
        }

        // Post-onboarding automático FUERA del ciclo de respuesta: contactos y luego
        // historial (best-effort e idempotentes; un fallo no revierte la conexión).
        ProcessWhatsAppPostOnboarding::dispatchAfterResponse($channel->id, $channel->institution_id);

        return response()->json([
            'status' => 'connected',
            'channel_id' => $channel->id,
            'connection_status' => $channel->connection_status,
        ]);
    }
}
