<?php

declare(strict_types=1);

/**
 * i18n de INTERFAZ: localizacion nativa de Laravel (lang/es, lang/en).
 */
it('resuelve los textos de interfaz segun el idioma activo', function () {
    app()->setLocale('es');
    expect(__('app.welcome'))->toBe('Bienvenido');

    app()->setLocale('en');
    expect(__('app.welcome'))->toBe('Welcome');
});

it('tiene el espanol como idioma de respaldo del sistema', function () {
    expect(config('crm.fallback_locale'))->toBe('es');
    expect(config('crm.locales'))->toBe(['es', 'en']);
});
