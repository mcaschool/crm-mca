<?php

declare(strict_types=1);

namespace Modules\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fija el idioma activo (es/en) de la peticion para la localizacion nativa de
 * Laravel y para el trait de contenido bilingue.
 *
 * Orden de resolucion:
 *  1. parametro explicito `lang` (widget: selector de idioma),
 *  2. usuario autenticado (users.preferred_language) — panel,
 *  3. cabecera Accept-Language,
 *  4. respaldo config('crm.fallback_locale').
 */
final class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $supported = (array) config('crm.locales', ['es', 'en']);
        $fallback = (string) config('crm.fallback_locale', 'es');

        $candidate = $request->input('lang')
            ?? optional($request->user())->preferred_language
            ?? $request->getPreferredLanguage($supported)
            ?? $fallback;

        $locale = in_array($candidate, $supported, true) ? $candidate : $fallback;

        app()->setLocale($locale);

        return $next($request);
    }
}
