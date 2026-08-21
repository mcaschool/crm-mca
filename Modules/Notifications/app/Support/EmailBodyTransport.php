<?php

declare(strict_types=1);

namespace Modules\Notifications\Support;

/**
 * Transporte SEGURO del cuerpo del editor entre navegador y servidor.
 *
 * Problema: en hosting compartido (Hostinger) el WAF/ModSecurity inspecciona el
 * cuerpo de la petición y BLOQUEA (antes de llegar a PHP) los POST que llevan HTML
 * crudo con patrones tipo XSS (<script, <iframe, onerror=, javascript:…). El editor
 * en "modo código" envía justo eso, así que la petición de Livewire se rechaza y la
 * plantilla/correo no se guarda (sin rastro en el log de Laravel).
 *
 * Solución: el editor envía el HTML CODIFICADO en base64 (con un marcador); aquí se
 * DECODIFICA antes de usarlo. El payload viaja como texto inocuo (no dispara el WAF),
 * y el HTML resultante sigue pasando por el SANITIZADOR igual que siempre: no se abre
 * ningún hueco de seguridad. Retrocompatible: un valor sin marcador se devuelve tal cual.
 */
class EmailBodyTransport
{
    /**
     * Marcador WAF-safe que antecede al HTML en base64. Sin '@' a propósito: Blade
     * escapa '@@' → '@' dentro de las plantillas, lo que rompería el marcador emitido
     * por el JS del editor. Con '_' no hay colisión ni con Blade ni con el WAF.
     */
    public const MARKER = '__B64__';

    public function decode(string $value): string
    {
        if (! str_starts_with($value, self::MARKER)) {
            return $value; // texto plano/HTML directo (retrocompatible)
        }

        $decoded = base64_decode(substr($value, strlen(self::MARKER)), true);

        return $decoded === false ? '' : $decoded;
    }
}
