<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Modules\Core\Http\Middleware\ResolveInstitutionFromUser;
use Modules\Core\Http\Middleware\SetLocale;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // El contexto de institucion debe fijarse ANTES de SubstituteBindings,
        // porque el route-model-binding de un modelo con scope global (p. ej.
        // Program) consulta la BD y sin contexto la barandilla lo rechaza (500).
        $middleware->prependToPriorityList(SubstituteBindings::class, ResolveInstitutionFromUser::class);
        $middleware->prependToPriorityList(SubstituteBindings::class, SetLocale::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
