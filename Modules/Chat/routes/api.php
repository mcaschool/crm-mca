<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Chat\Http\Controllers\WidgetController;

/*
| API publica del widget (bajo el prefijo global /api). Stateless, sin cookies.
| institution.bot deduce el bot desde X-Bot-Key (el cliente nunca envia
| institution_id). Rate limiting obligatorio (D8); el emparejador lleva un limite
| mas estricto porque es la accion mas costosa.
*/

Route::prefix('v1/widget')
    ->middleware(['institution.bot', 'setlocale', 'throttle:widget'])
    ->group(function () {
        Route::post('session', [WidgetController::class, 'session'])->name('widget.session');
        Route::get('matcher-options', [WidgetController::class, 'matcherOptions'])->name('widget.matcher-options');
        Route::post('lead', [WidgetController::class, 'lead'])->name('widget.lead');
        Route::post('navigate', [WidgetController::class, 'navigate'])->name('widget.navigate');
        Route::post('match', [WidgetController::class, 'match'])
            ->middleware('throttle:widget-message')
            ->name('widget.match');

        // Modo Celia (IA): activacion con memoria y conversacion. El mensaje puede
        // gastar tokens, por eso lleva el limite mas estricto (como el emparejador).
        Route::post('celia/start', [WidgetController::class, 'celiaStart'])->name('widget.celia.start');
        Route::post('celia', [WidgetController::class, 'celia'])
            ->middleware('throttle:widget-message')
            ->name('widget.celia');
    });
