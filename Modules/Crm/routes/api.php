<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Core\Http\Middleware\ResolveInstitutionFromIncompanyToken;
use Modules\Crm\Http\Controllers\IncompanyController;

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
