<?php

declare(strict_types=1);

namespace Modules\Crm\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Modules\Audit\Services\AuditService;
use Modules\Crm\Services\LeadIntake;

/**
 * Endpoint PÚBLICO general de captación de leads desde n8n (POST /api/v1/leads/intake).
 *
 * La autenticación (token Bearer) y la resolución de institución las hace el middleware
 * ResolveInstitutionFromLeadIntakeToken. Aquí se: (1) rechaza el cuerpo demasiado grande
 * (413); (2) valida tipos y longitudes (422); (3) resuelve product_type SIN asumir jamás
 * microcredencial (taxonomía controlada o derivación por formulario, si no → 422); (4) exige
 * un identificador de idempotencia real (Idempotency-Key o request_id, sin UUID de relleno);
 * (5) crea/actualiza el lead reutilizando la dedup del CRM; (6) audita SIN datos personales
 * ni token.
 *
 * Contrato de respuesta: { ok, lead_id, action, request_id }.
 *   action: created (201) · updated (200) · duplicate (200, reintento idempotente).
 * Errores: 401 (auth, middleware) · 413 (tamaño) · 422 (validación/identificador/tipo) · 429.
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
        $productTypes = (array) config('crm.lead_intake.product_types', []);
        $validator = Validator::make($request->all(), [
            'request_id' => ['nullable', 'string', 'max:190'],
            // Contacto. NOTA: email es obligatorio hoy por el esquema (contacts.email NOT NULL
            // + UNIQUE(institution_id,email)). El soporte email-O-teléfono exige un cambio de
            // esquema/diseño pendiente de aprobación (ver informe); no se crean emails ficticios.
            'email' => ['required', 'email:rfc', 'max:190'],
            'phone' => ['nullable', 'string', 'max:30'],
            'first_name' => ['nullable', 'string', 'max:80'],
            'last_name' => ['nullable', 'string', 'max:80'],
            // País: se acepta código ISO-2 O nombre completo; el CRM lo normaliza (no rechaza
            // el lead si no lo reconoce). Se limita la longitud para no abusar del cuerpo.
            'country' => ['nullable', 'string', 'max:80'],
            'preferred_language' => ['nullable', Rule::in(['es', 'en'])],
            // Señal comercial.
            'product_type' => ['nullable', Rule::in($productTypes)],
            'program' => ['nullable', 'string', 'max:150'],
            'area' => ['nullable', 'string', 'max:100'],
            'goal' => ['nullable', 'string', 'max:100'],
            'level' => ['nullable', 'string', 'max:40'],
            'interest_level' => ['nullable', Rule::in(['low', 'medium', 'high'])],
            'source' => ['nullable', 'string', 'max:60'],
            'channel' => ['nullable', 'string', 'max:60'],
            'form' => ['nullable', 'string', 'max:100'],
            // Consentimiento (D2). Nunca se inventa: si no llega, queda null.
            'consent' => ['nullable', 'boolean'],
            'consent_at' => ['nullable', 'date'],
            'consent_source' => ['nullable', Rule::in(['web_form', 'whatsapp', 'manual'])],
        ], [
            'required' => 'El campo «:attribute» es obligatorio.',
            'string' => 'El campo «:attribute» debe ser texto.',
            'email' => 'El campo «:attribute» debe ser un correo válido.',
            'boolean' => 'El campo «:attribute» debe ser verdadero o falso.',
            'date' => 'El campo «:attribute» debe ser una fecha válida.',
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

        // (3) product_type: explícito (taxonomía) o derivado del formulario. NUNCA por defecto
        // microcredencial: si no se puede determinar, se rechaza de forma explícita.
        $productType = $data['product_type'] ?? null;
        if ($productType === null && isset($data['form'])) {
            $map = (array) config('crm.lead_intake.form_product_type', []);
            $productType = $map[$data['form']] ?? null;
        }
        if ($productType === null) {
            return response()->json([
                'ok' => false,
                'message' => 'No se pudo determinar product_type: envíalo explícitamente (taxonomía controlada) o usa un «form» reconocido.',
                'errors' => ['product_type' => ['product_type es obligatorio y no pudo derivarse del formulario.']],
            ], 422);
        }
        $data['product_type'] = $productType;

        // (4) Idempotencia REAL: Idempotency-Key (cabecera) o request_id (cuerpo), del evento
        // original. Sin relleno con UUID: si no llega ninguno, se rechaza.
        $requestId = trim((string) ($request->header('Idempotency-Key') ?? ''));
        if ($requestId === '') {
            $requestId = trim((string) ($data['request_id'] ?? ''));
        }
        if ($requestId === '' || strlen($requestId) > 190) {
            return response()->json([
                'ok' => false,
                'message' => 'Falta un identificador de idempotencia estable: envía la cabecera Idempotency-Key o el campo request_id (máx. 190).',
                'errors' => ['request_id' => ['Idempotency-Key o request_id es obligatorio.']],
            ], 422);
        }

        // (5) Alta/actualización idempotente reutilizando la dedup del CRM.
        ['lead' => $lead, 'action' => $action, 'request_id' => $requestId] = $intake->ingest($data, $requestId);

        // (6) Auditoría SIN datos personales ni token: solo metadatos de trazabilidad.
        $audit->log('lead_intake.received', $lead, array_filter([
            'action' => $action,
            'request_id' => $requestId,
            'source' => $data['source'] ?? null,
            'channel' => $data['channel'] ?? null,
            'form' => $data['form'] ?? null,
            'program' => $data['program'] ?? null,
            'product_type' => $productType,
            'consent' => array_key_exists('consent', $data) ? (bool) $data['consent'] : null,
        ], fn ($v) => $v !== null && $v !== ''));

        return response()->json([
            'ok' => true,
            'lead_id' => $lead->getKey(),
            'action' => $action,
            'request_id' => $requestId,
        ], $action === 'created' ? 201 : 200);
    }
}
