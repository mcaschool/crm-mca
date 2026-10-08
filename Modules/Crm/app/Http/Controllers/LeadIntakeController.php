<?php

declare(strict_types=1);

namespace Modules\Crm\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Modules\Audit\Services\AuditService;
use Modules\Core\Support\PhoneNumber;
use Modules\Crm\Exceptions\ContactIdentityConflictException;
use Modules\Crm\Exceptions\InvalidContactDataException;
use Modules\Crm\Models\Contact;
use Modules\Crm\Services\LeadIntake;
use Modules\Crm\Support\ContactDataNormalizer;
use Modules\Crm\Support\LeadIntakeChannel;

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

        // (2) Detección centralizada del origen WhatsApp (channel/source = whatsapp, o form con
        // prefijo whatsapp_). Un lead de WhatsApp exige first_name + last_name + phone; el email
        // sigue siendo opcional. Se lee en crudo (string seguro) porque decide la obligatoriedad.
        $rawStr = static fn (string $key): string => is_string($v = $request->input($key)) ? $v : '';
        $isWhatsApp = LeadIntakeChannel::isWhatsApp($rawStr('channel'), $rawStr('source'), $rawStr('form'));

        // (3) Validación estricta. Mensajes autónomos (no dependen de lang/validation.php).
        $productTypes = (array) config('crm.lead_intake.product_types', []);
        $validator = Validator::make($request->all(), [
            'request_id' => ['nullable', 'string', 'max:190'],
            // Identidad del contacto: email O teléfono (al menos uno). Nunca se fabrica email.
            // En WhatsApp, el teléfono (wa_id) es obligatorio; el email sigue opcional.
            // Longitudes = las del esquema (capa común ContactDataNormalizer::MAX).
            'email' => [$isWhatsApp ? 'nullable' : 'required_without:phone', 'nullable', 'email:rfc', 'max:'.ContactDataNormalizer::MAX['email']],
            'phone' => [$isWhatsApp ? 'required' : 'required_without:email', 'nullable', 'string', 'max:'.ContactDataNormalizer::MAX['phone']],
            'first_name' => [$isWhatsApp ? 'required' : 'nullable', 'string', 'max:'.ContactDataNormalizer::MAX['first_name']],
            'last_name' => [$isWhatsApp ? 'required' : 'nullable', 'string', 'max:'.ContactDataNormalizer::MAX['last_name']],
            // País: se acepta código ISO-2 O nombre completo; el CRM lo normaliza (no rechaza
            // el lead si no lo reconoce). Se limita la longitud para no abusar del cuerpo.
            'country' => ['nullable', 'string', 'max:80'],
            'preferred_language' => ['nullable', Rule::in(['es', 'en'])],
            // Señal comercial.
            'product_type' => ['nullable', Rule::in($productTypes)],
            'program' => ['nullable', 'string', 'max:150'],
            'area' => ['nullable', 'string', 'max:80'], // leads.area / leads.goal son varchar(80)
            'goal' => ['nullable', 'string', 'max:80'],
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
            'required_without' => 'Debes enviar al menos «email» o «phone» para identificar el lead.',
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

        // (4) product_type: explícito (taxonomía) o derivado del formulario. NUNCA por defecto
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

        // (5) Idempotencia REAL: Idempotency-Key (cabecera) o request_id (cuerpo), del evento
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

        // (5b) Identidad: sin email, el teléfono DEBE poder normalizarse (si no, no hay forma
        // segura de identificar/deduplicar → 422, sin asociar mal). WhatsApp llega internacional
        // (assumeInternational) según la detección centralizada de arriba.
        if (($data['email'] ?? null) === null) {
            if (PhoneNumber::normalize((string) ($data['phone'] ?? ''), $isWhatsApp) === null) {
                return response()->json([
                    'ok' => false,
                    'message' => 'Sin email, el teléfono debe venir en formato internacional válido (no ambiguo) para identificar el lead.',
                    'errors' => ['phone' => ['Teléfono ambiguo o inválido y sin email: no se puede identificar el lead.']],
                ], 422);
            }
        }

        // (6) Alta/actualización idempotente reutilizando la dedup del CRM. Un conflicto de
        // identidad (email y teléfono → contactos distintos) NO fusiona: se responde 409 y se
        // audita sin datos personales (solo IDs y metadatos). Un dato que la capa común del CRM
        // rechaza (formato, marcador sintético como única identidad…) → 422 con el campo.
        try {
            ['lead' => $lead, 'action' => $action, 'request_id' => $requestId] = $intake->ingest($data, $requestId);
        } catch (InvalidContactDataException $e) {
            return response()->json([
                'ok' => false,
                'message' => 'Datos inválidos: revisa los campos requeridos, tipos y longitudes.',
                'errors' => array_map(fn (string $why): array => [$why], $e->errors),
            ], 422);
        } catch (ContactIdentityConflictException $e) {
            $auditable = Contact::query()->find($e->emailContactId) ?? Contact::query()->find($e->phoneContactId);
            if ($auditable !== null) {
                $audit->log('lead_intake.conflict', $auditable, array_filter([
                    'request_id' => $requestId,
                    'email_contact_id' => $e->emailContactId,
                    'phone_contact_id' => $e->phoneContactId,
                    'source' => $data['source'] ?? null,
                    'form' => $data['form'] ?? null,
                    'product_type' => $productType,
                ], fn ($v) => $v !== null && $v !== ''));
            }

            return response()->json([
                'ok' => false,
                'error' => 'identity_conflict',
                'message' => 'El email y el teléfono corresponden a contactos distintos; no se fusionan automáticamente. Revisión manual requerida.',
                'request_id' => $requestId,
            ], 409);
        }

        // (7) Auditoría SIN datos personales ni token: solo metadatos de trazabilidad.
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
