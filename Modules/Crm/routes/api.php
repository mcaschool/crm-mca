<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Core\Http\Middleware\ResolveInstitutionFromIncompanyToken;
use Modules\Core\Http\Middleware\ResolveInstitutionFromLeadIntakeToken;
use Modules\Crm\Http\Controllers\IncompanyController;
use Modules\Crm\Http\Controllers\LeadIntakeController;

/*
| API pública del módulo CRM (bajo el prefijo global /api). Stateless, JSON, sin cookies.
|
| Endpoint InCompany: recibe un lead corporativo ya diagnosticado desde n8n.
| - Autenticación por token Bearer en el header (ResolveInstitutionFromIncompanyToken) → 401.
| - Rate limit por IP (throttle:incompany) → 429.
| - Validación estricta del JSON en el controlador → 422.
| - Éxito → 201 con el id del lead.
*/
Route::prefix('v1/incompany')
    ->middleware(['throttle:incompany', ResolveInstitutionFromIncompanyToken::class])
    ->group(function () {
        Route::post('lead', [IncompanyController::class, 'store'])->name('incompany.lead');
    });

/*
| Endpoint GENERAL de captación de leads: recibe leads de cualquier formulario/flujo de n8n.
| - Autenticación por token Bearer propio (ResolveInstitutionFromLeadIntakeToken) → 401.
|   Token independiente del InCompany; ninguno sirve para el endpoint del otro.
| - Rate limit por IP (throttle:lead-intake) → 429.
| - Límite de tamaño del cuerpo (413) y validación estricta (422) en el controlador.
| - Idempotente por request_id (cabecera Idempotency-Key o campo). Éxito → 201/200.
*/
Route::prefix('v1/leads')
    ->middleware(['throttle:lead-intake', ResolveInstitutionFromLeadIntakeToken::class])
    ->group(function () {
        Route::post('intake', [LeadIntakeController::class, 'store'])->name('leads.intake');
    });
