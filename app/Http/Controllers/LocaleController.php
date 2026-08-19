<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Conmutador de idioma del panel (ES/EN). Persiste la preferencia en el usuario
 * (`users.preferred_language`), que es lo que el middleware SetLocale lee para fijar
 * el locale en cada peticion. Asi el cambio se mantiene al navegar (no es un simple
 * ?lang= de una sola peticion).
 */
class LocaleController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $supported = (array) config('crm.locales', ['es', 'en']);
        $lang = (string) $request->input('lang');

        if (in_array($lang, $supported, true)) {
            $user = $request->user();
            if ($user !== null) {
                $user->preferred_language = $lang;
                $user->save();
            }
            app()->setLocale($lang);
        }

        return redirect()->back();
    }
}
