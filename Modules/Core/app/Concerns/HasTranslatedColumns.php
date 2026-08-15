<?php

declare(strict_types=1);

namespace Modules\Core\Concerns;

use Illuminate\Database\Eloquent\Builder;

/**
 * Contenido bilingue por COLUMNAS hermanas `_es` / `_en` (decision del Bloque 0).
 *
 * El codigo de negocio y las vistas escriben SIEMPRE el nombre logico
 * (`$program->name`); solo este trait y las migraciones conocen los sufijos.
 * Anadir un tercer idioma = una migracion de columnas + una linea de config,
 * sin tocar servicios ni vistas.
 *
 * Uso en el modelo:
 *   protected array $translatable = ['name', 'short_description'];
 *
 * Regla de respaldo (fallback): si falta la traduccion pedida, se devuelve la
 * del idioma de respaldo (config('crm.fallback_locale')) antes que null. Realista:
 * el catalogo llega en espanol y el ingles se completa despues.
 *
 * Cada modelo que use el trait declara su propia lista:
 *   protected array $translatable = ['name', 'short_description'];
 *
 * @property array<int,string> $translatable
 */
trait HasTranslatedColumns
{
    /**
     * Resuelve un atributo logico traducible al idioma activo.
     */
    public function getAttribute($key)
    {
        if ($this->isTranslatableAttribute($key)) {
            return $this->getTranslatedValue($key);
        }

        return parent::getAttribute($key);
    }

    /**
     * Permite asignar el atributo logico al idioma activo:
     *   $program->name = 'Hola';  // escribe name_es (o name_en) segun locale.
     */
    public function setAttribute($key, $value)
    {
        if ($this->isTranslatableAttribute($key)) {
            $locale = $this->currentTranslationLocale();

            return parent::setAttribute("{$key}_{$locale}", $value);
        }

        return parent::setAttribute($key, $value);
    }

    /**
     * Valor de un campo traducible en un idioma concreto, con respaldo.
     */
    public function translate(string $key, ?string $locale = null): ?string
    {
        $locale ??= $this->currentTranslationLocale();
        $fallback = (string) config('crm.fallback_locale', 'es');

        $value = $this->attributes["{$key}_{$locale}"] ?? null;

        if ($value === null || $value === '') {
            $value = $this->attributes["{$key}_{$fallback}"] ?? null;
        }

        return $value === '' ? null : $value;
    }

    /**
     * ¿Falta la traduccion en el idioma dado? Sirve para que el panel marque
     * los campos aun sin version en ingles.
     */
    public function isMissingTranslation(string $key, string $locale): bool
    {
        $value = $this->attributes["{$key}_{$locale}"] ?? null;

        return $value === null || $value === '';
    }

    /**
     * Ordena por un campo traducible en el idioma activo.
     *
     * @param  Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @return Builder<\Illuminate\Database\Eloquent\Model>
     */
    public function scopeOrderByTranslated(Builder $query, string $key, string $direction = 'asc'): Builder
    {
        return $query->orderBy("{$key}_".$this->currentTranslationLocale(), $direction);
    }

    protected function getTranslatedValue(string $key): ?string
    {
        return $this->translate($key);
    }

    protected function isTranslatableAttribute(string $key): bool
    {
        return in_array($key, $this->translatable, true);
    }

    protected function currentTranslationLocale(): string
    {
        $locale = app()->getLocale();
        $supported = (array) config('crm.locales', ['es', 'en']);

        return in_array($locale, $supported, true)
            ? $locale
            : (string) config('crm.fallback_locale', 'es');
    }
}
