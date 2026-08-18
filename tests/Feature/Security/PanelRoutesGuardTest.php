<?php

declare(strict_types=1);

/**
 * Barandilla de despliegue: NINGUNA ruta del grupo panel debe ser accesible sin
 * autenticacion. Se recorren TODAS las rutas cuyo middleware incluye la puerta
 * `can:access-panel` (marca del grupo panel) y se comprueba que un invitado es
 * redirigido al login. Asi, si en el futuro se añade una ruta al panel sin `auth`,
 * esta prueba lo detecta.
 */
it('ninguna ruta del panel es accesible sin autenticación (barrido completo)', function () {
    $panelRoutes = collect(app('router')->getRoutes()->getRoutes())
        ->filter(fn ($route) => in_array('can:access-panel', $route->gatherMiddleware(), true))
        ->filter(fn ($route) => in_array('GET', $route->methods(), true));

    // Sanidad: el barrido efectivamente encontró el grupo panel.
    expect($panelRoutes->count())->toBeGreaterThan(8);

    $panelRoutes->each(function ($route) {
        // El middleware `auth` corre ANTES del route-model-binding, así que un
        // invitado es redirigido aunque el parámetro no exista: se sustituye por "1".
        $uri = preg_replace('/\{[^}]+\}/', '1', $route->uri());

        test()->get('/'.ltrim((string) $uri, '/'))
            ->assertRedirect('/login');
    });
});

it('las rutas críticas del panel redirigen al login siendo invitado', function () {
    foreach (['/dashboard', '/users', '/integrations', '/audit', '/crm/leads', '/mi-perfil'] as $path) {
        test()->get($path)->assertRedirect('/login');
    }
});
