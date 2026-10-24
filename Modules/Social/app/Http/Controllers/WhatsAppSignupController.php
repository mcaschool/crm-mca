<?php

declare(strict_types=1);

namespace Modules\Social\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
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

        // Embedded Signup v4 (coexistencia): el evento FINISH entrega SOLO waba_id; el
        // phone_number_id NO llega del navegador y se descubre server-side desde la WABA.
        // Por eso es OPCIONAL aquí (se acepta por compatibilidad si el flujo lo aportara).
        $data = $request->validate([
            'state' => ['required', 'string', 'max:120'],
            'code' => ['required', 'string', 'max:2048'],
            'waba_id' => ['required', 'string', 'max:64'],
            'phone_number_id' => ['nullable', 'string', 'max:64'],
            'display_phone_number' => ['nullable', 'string', 'max:32'],
            'correlation_id' => ['nullable', 'string', 'max:64'],
        ]);

        if (! $coexistence->isEnabled()) {
            return response()->json(['status' => 'disabled', 'message' => __('La conexión de WhatsApp Business aún no está habilitada (configuración de Meta pendiente).')], 409);
        }

        // State de un solo uso, ligado al usuario y a SU institución (replay protection).
        $institutionId = $coexistence->consumeState((int) $user->id, (string) $data['state']);
        if ($institutionId === null || $institutionId !== $context->id()) {
            return response()->json(['status' => 'invalid_state', 'message' => __('La conexión expiró o no es válida. Vuelve a iniciar el proceso.')], 422);
        }

        $correlationId = (string) ($data['correlation_id'] ?? '') ?: (string) Str::uuid();

        try {
            $channel = $coexistence->completeSignup(
                $institutionId,
                (string) $data['code'],
                (string) $data['waba_id'],
                (string) ($data['phone_number_id'] ?? ''),
                (string) ($data['display_phone_number'] ?? ''),
                $correlationId,
            );
        } catch (RuntimeException $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage(), 'correlation_id' => $correlationId], 422);
        }

        // Post-onboarding automático FUERA del ciclo de respuesta: contactos y luego
        // historial (best-effort e idempotentes; un fallo no revierte la conexión).
        ProcessWhatsAppPostOnboarding::dispatchAfterResponse($channel->id, $channel->institution_id);

        return response()->json([
            'status' => 'connected',
            'channel_id' => $channel->id,
            'connection_status' => $channel->connection_status,
            'correlation_id' => $correlationId,
        ]);
    }

    /**
     * Beacon de diagnóstico del Embedded Signup. Registra DE FORMA SEGURA solo la
     * información que el SDK de Meta expone en el navegador en los eventos autorizados
     * (ERROR/CANCEL): fase, paso actual, mensaje de error de Meta, error_id y session_id.
     * Sirve para localizar dónde se interrumpe el onboarding cuando Meta NO llega a devolver
     * el código al CRM (p. ej. un error #1690130 dentro del popup). NUNCA recibe ni registra
     * access tokens, authorization codes, secretos ni datos personales.
     */
    public function diagnostic(Request $request, CurrentInstitution $context): JsonResponse
    {
        $user = $request->user();
        abort_unless($user !== null && $user->can('create', SocialChannel::class), 403);

        $data = $request->validate([
            'correlation_id' => ['nullable', 'string', 'max:64'],
            'phase' => ['required', 'string', 'max:40'],
            'current_step' => ['nullable', 'string', 'max:80'],
            'error_message' => ['nullable', 'string', 'max:300'],
            'error_id' => ['nullable', 'string', 'max:64'],
            'session_id' => ['nullable', 'string', 'max:120'],
        ]);

        Log::info('social.wa.coex.client', [
            'attempt' => (string) ($data['correlation_id'] ?? ''),
            'institution_id' => $context->id(),
            'phase' => $data['phase'],
            'current_step' => $data['current_step'] ?? null,
            // error_message es el texto que Meta muestra al usuario; no es un secreto.
            'error_message' => $data['error_message'] ?? null,
            'error_id' => $data['error_id'] ?? null,
            'session_id' => $data['session_id'] ?? null,
        ]);

        return response()->json(['status' => 'logged'], 202);
    }
}
