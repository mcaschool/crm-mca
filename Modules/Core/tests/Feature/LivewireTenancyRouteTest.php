<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/**
 * Regresion: el endpoint AJAX de Livewire corre en el grupo 'web', fuera del
 * grupo del panel. Sin re-establecer el contexto de institucion alli, TODA
 * accion de un componente del panel (guardar usuario, guardar integracion...)
 * fallaria con MissingInstitutionContextException. CoreServiceProvider lo
 * arregla via Livewire::setUpdateRoute; este test lo blinda.
 */
it('la ruta de update de Livewire incluye el middleware de contexto de institucion', function () {
    $route = collect(Route::getRoutes()->getRoutes())
        ->first(fn ($r) => str_contains($r->uri(), 'livewire/update'));

    expect($route)->not->toBeNull();

    $middleware = $route->gatherMiddleware();
    expect($middleware)->toContain('institution.user');
    expect($middleware)->toContain('setlocale');
});
