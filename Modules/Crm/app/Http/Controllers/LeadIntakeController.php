<?php

declare(strict_types=1);

namespace Modules\Crm\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Modules\Audit\Services\AuditService;
use Modules\Crm\Services\LeadIntake;

/**
 * Endpoint PÚBLICO general de captación de leads desde n8n (POST /api/v1/leads/intake).
 *
 * La autenticación (token Bearer) y la resolución de institución las hace el middleware
 * ResolveInstitutionFromLeadIntakeToken; aquí se: (1) rechaza el cuerpo demasiado grande
 * (413), (2) valida tipos y longitudes (422), (3) normaliza al contrato canónico del CRM,
 * (4) crea/actualiza el lead de forma idempotente y (5) audita SIN datos personales ni token.
 *
 * Contrato de respuesta: { ok, lead_id, action, request_id }.
 *   action: created (201) · updated (200) · duplicate (200, reintento idempotente).
 * Errores: 401 (auth, middleware) · 413 (tamaño) · 422 (validación) · 429 (rate limit).
 */
class LeadIntakeController
{
    public function store(Request $request, LeadIntake $intake, AuditService $audit): JsonResponse
    {
        // (1) Límite de tamaño del cuerpo (anti-abuso). Antes de validar/parsear a fondo.
        $maxBytes = (int) config('crm.lead_intake.max_payload_bytes', 16384);
        if (strlen((string) $request->getContent()) > $maxBytes) {
            return response()->json([
                'ok' => false,
                'message' => 'El cuerpo de la solicitud es demasiado grande.',
            ], 413);
        }

        // (2) Validación estricta. Mensajes autónomos (no dependen de lang/validation.php).
        $validator = Validator::make($request->all(), [
            // Idempotencia (opcional en el cuerpo; también se acepta por cabecera).
            'request_id' => ['nullable', 'string', 'max:190'],
            // Contacto.
            'email' => ['required', 'email:rfc', 'max:190'],
            'first_name' => ['nullable', 'string', 'max:120'],
            'last_name' => ['nullable', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:40'],
            'country' => ['nullable', 'string', 'size:2'], // ISO-3166-1 alpha-2
            'preferred_language' => ['nullable', Rule::in(['es', 'en'])],
            // Señal comercial del lead.
            'product_type' => ['nullable', 'string', 'max:40'],
            'program' => ['nullable', 'string', 'max:100'],
            'area' => ['nullable', 'string', 'max:100'],
            'goal' => ['nullable', 'string', 'max:100'],
            'level' => ['nullable', 'string', 'max:40'],
            'interest_level' => ['nullable', Rule::in(['low', 'medium', 'high'])],
            'source' => ['nullable', 'string', 'max:60'],
            'channel' => ['nullable', 'string', 'max:60'],
            'form' => ['nullable', 'string', 'max:100'],
        ], [
            'required' => 'El campo «:attribute» es obligatorio.',
            'string' => 'El campo «:attribute» debe ser texto.',
            'email' => 'El campo «:attribute» debe ser un correo válido.',
            'max.string' => 'El campo «:attribute» no puede superar :max caracteres.',
            'in' => 'El valor de «:attribute» no es válido.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'ok' => false,
                'message' => 'Datos inválidos: revisa los campos requeridos, tipos y longitudes.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();

        // (3) request_id estable: cabecera Idempotency-Key > campo request_id > uuid generado.
        $requestId = trim((string) ($request->header('Idempotency-Key') ?? ''));
        if ($requestId === '') {
            $requestId = trim((string) ($data['request_id'] ?? ''));
        }
        if ($requestId === '' || strlen($requestId) > 190) {
            $requestId = (string) Str::uuid();
        }

        // (4) Alta/actualización idempotente reutilizando la dedup del CRM.
        ['lead' => $lead, 'action' => $action, 'request_id' => $requestId] = $intake->ingest($data, $requestId);

        // (5) Auditoría SIN datos personales ni token: solo metadatos de trazabilidad.
        $audit->log('lead_intake.received', $lead, array_filter([
            'action' => $action,
            'request_id' => $requestId,
            'source' => $data['source'] ?? 'n8n_intake',
            'channel' => $data['channel'] ?? null,
            'form' => $data['form'] ?? null,
            'program' => $data['program'] ?? null,
            'product_type' => $data['product_type'] ?? null,
        ], fn ($v) => $v !== null && $v !== ''));

        return response()->json([
            'ok' => true,
            'lead_id' => $lead->getKey(),
            'action' => $action,
            'request_id' => $requestId,
        ], $action === 'created' ? 201 : 200);
    }
}
