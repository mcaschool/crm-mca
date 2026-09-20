<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Mcp\Livewire\Admin;

/*
|--------------------------------------------------------------------------
| Mcp — ruta web del panel (Configuración → Integraciones)
|--------------------------------------------------------------------------
| Cargada por el RouteServiceProvider del módulo con la pila del panel. La
| autorización fina (canManageIntegrations) la aplica el componente en mount().
*/

Route::get('/integrations/mcp', Admin::class)->name('mcp.admin');
