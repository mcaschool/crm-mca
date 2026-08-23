<?php

declare(strict_types=1);

namespace Modules\Crm\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Modules\Audit\Services\AuditService;
use Modules\Crm\Services\IncompanyLeadIntake;

/**
 * Endpoint PÚBLICO de recepción de leads InCompany (formación corporativa) desde n8n.
 * La autenticación (token Bearer) y la resolución de institución ya las hizo el
 * middleware ResolveInstitutionFromIncompanyToken; aquí solo se VALIDA el JSON estricto, se crea el
 * lead (transaccional) y se registra en auditoría.
 *
 * Respuestas: 201 (creado, con id) · 401 (auth, en el middleware) · 422 (validación) ·
 * 429 (rate limit, en el middleware throttle).
 */
class IncompanyController
{
    public function store(Request $request, IncompanyLeadIntake $intake, AuditService $audit): JsonResponse
    {
        // Validación ESTRICTA. Se responde 422 JSON explícito (no depende del header
        // Accept) y no se crea nada si algo falla.
        $validator = Validator::make($request->all(), [
            'nombre_empresa' => ['required', 'string', 'max:150'],
            'nombre_contacto' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:190'],
            'whatsapp' => ['required', 'string', 'max:30'],
            'sector' => ['required', 'string', 'max:100'],
            'tamano_empresa' => ['required', 'string', 'max:40'],
            'modalidad' => ['required', Rule::in(['persona', 'grupo'])],
            'cantidad_personas' => ['required', 'integer', 'min:1', 'max:100000'],
            'programa_1' => ['required', 'string', 'max:100'],
            'programa_2' => ['nullable', 'string', 'max:100'],
            'programa_3' => ['nullable', 'string', 'max:100'],
            'area_desarrollo' => ['required', 'string', 'max:500'],
        ], [
            // Mensajes claros y AUTÓNOMOS (no dependen de lang/es/validation.php): el
            // integrador de n8n debe entender el 422 sin adivinar. :attribute se resuelve
            // con los nombres de abajo.
            'required' => 'El campo «:attribute» es obligatorio.',
            'string' => 'El campo «:attribute» debe ser texto.',
            'integer' => 'El campo «:attribute» debe ser un número entero.',
            'email' => 'El campo «:attribute» debe ser un correo válido.',
            'in' => 'El valor de «:attribute» no es válido (usa: persona o grupo).',
            'max.string' => 'El campo «:attribute» no puede superar :max caracteres.',
            'max.numeric' => 'El campo «:attribute» no puede ser mayor que :max.',
            'min.numeric' => 'El campo «:attribute» debe ser al menos :min.',
        ], [
            'nombre_empresa' => 'nombre_empresa',
            'nombre_contacto' => 'nombre_contacto',
            'email' => 'email',
            'whatsapp' => 'whatsapp',
            'sector' => 'sector',
            'tamano_empresa' => 'tamano_empresa',
            'modalidad' => 'modalidad',
            'cantidad_personas' => 'cantidad_personas',
            'programa_1' => 'programa_1',
            'programa_2' => 'programa_2',
            'programa_3' => 'programa_3',
            'area_desarrollo' => 'area_desarrollo',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Datos inválidos: revisa los campos requeridos, tipos y longitudes.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();

        $lead = $intake->create($data);

        // Auditoría: cada lead recibido queda registrado (la IP la captura el servicio
        // de auditoría automáticamente). Nunca se registra el token.
        $audit->log('incompany_lead.received', $lead, [
            'empresa' => $data['nombre_empresa'],
            'origen' => 'incompany_web',
            'programas' => array_values(array_filter([
                $data['programa_1'] ?? null,
                $data['programa_2'] ?? null,
                $data['programa_3'] ?? null,
            ])),
        ]);

        return response()->json(['id' => $lead->getKey(), 'status' => 'created'], 201);
    }
}
