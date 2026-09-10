<?php

declare(strict_types=1);

namespace Modules\Social\Services;

use Modules\Social\Models\SocialWhatsAppTemplate;

/**
 * Reglas LOCALES de validación de plantillas de WhatsApp, centralizadas en un solo lugar
 * para poder actualizarlas cuando Meta cambie límites (no están repartidas por la UI).
 * La validación definitiva siempre es la revisión de Meta; esto evita llamadas que van a
 * fallar seguro y da mensajes claros al usuario.
 *
 * Límites tomados de la doc vigente de Cloud API (message templates):
 *  - name: [a-z0-9_], máx. 512.
 *  - BODY: obligatorio, máx. 1024 caracteres.
 *  - HEADER TEXT: máx. 60; FOOTER: máx. 60.
 *  - Botones: máx. 10 en total; máx. 2 URL; máx. 1 PHONE_NUMBER; textos máx. 25.
 *  - Variables posicionales {{1}}..{{n}}: consecutivas desde 1, cada una con ejemplo, y
 *    nunca dos variables adyacentes sin texto/puntuación entre ellas.
 */
final class WhatsAppTemplateValidator
{
    public const MAX_NAME = 512;

    public const MAX_BODY = 1024;

    public const MAX_HEADER_TEXT = 60;

    public const MAX_FOOTER = 60;

    public const MAX_BUTTONS = 10;

    public const MAX_URL_BUTTONS = 2;

    public const MAX_PHONE_BUTTONS = 1;

    public const MAX_BUTTON_TEXT = 25;

    /** Tipos de botón soportados hoy; ampliar aquí (COPY_CODE, VOICE_CALL…) cuando toque. */
    public const BUTTON_TYPES = ['QUICK_REPLY', 'URL', 'PHONE_NUMBER'];

    /** Formatos de HEADER soportados. */
    public const HEADER_FORMATS = ['TEXT', 'IMAGE', 'VIDEO', 'DOCUMENT'];

    /**
     * Valida la definición completa antes de llamar a Meta. Devuelve la lista de errores
     * (vacía = válida). $input:
     *  name, language, category, headerFormat (''|TEXT|IMAGE|VIDEO|DOCUMENT), headerText,
     *  body, footer, buttons[[type,text,url,phone]], examples[n=>string], headerExample.
     *
     * @param  array<string, mixed>  $input
     * @return array<int, string>
     */
    public function validate(array $input): array
    {
        $errors = [];

        // ---- name ----
        $name = (string) ($input['name'] ?? '');
        if ($name === '' || strlen($name) > self::MAX_NAME || preg_match('/^[a-z0-9_]+$/', $name) !== 1) {
            $errors[] = __('El nombre debe ir en minúsculas, solo letras a-z, números y guiones bajos (máx. 512).');
        }

        // ---- language ----
        $language = (string) ($input['language'] ?? '');
        if (preg_match('/^[a-z]{2,3}(_[A-Z]{2})?$/', $language) !== 1) {
            $errors[] = __('Selecciona un código de idioma válido de Meta (ej. es, es_MX, en_US).');
        }

        // ---- category ----
        $category = (string) ($input['category'] ?? '');
        if (! in_array($category, SocialWhatsAppTemplate::CATEGORIES, true)) {
            $errors[] = __('Selecciona una categoría válida (MARKETING o UTILITY).');
        }
        // AUTHENTICATION exige una estructura especial (OTP/copy-code) que este diseñador
        // libre no produce: se sincroniza, pero NO se crea manualmente desde la UI.
        if ($category === 'AUTHENTICATION') {
            $errors[] = __('Las plantillas de AUTHENTICATION no se crean desde este diseñador: requieren la estructura OTP específica de Meta. Se sincronizan automáticamente si existen.');
        }

        // ---- header ----
        $headerFormat = strtoupper((string) ($input['headerFormat'] ?? ''));
        if ($headerFormat !== '' && ! in_array($headerFormat, self::HEADER_FORMATS, true)) {
            $errors[] = __('Formato de encabezado no soportado.');
        }
        $headerText = (string) ($input['headerText'] ?? '');
        if ($headerFormat === 'TEXT') {
            if (trim($headerText) === '' || mb_strlen($headerText) > self::MAX_HEADER_TEXT) {
                $errors[] = __('El encabezado de texto es obligatorio y admite máximo 60 caracteres.');
            }
            if (count($this->variables($headerText)) > 1) {
                $errors[] = __('El encabezado de texto admite como máximo una variable.');
            }
        }
        if (in_array($headerFormat, ['IMAGE', 'VIDEO', 'DOCUMENT'], true)
            && trim((string) ($input['headerExample'] ?? '')) === '') {
            $errors[] = __('Sube un archivo de ejemplo para el encabezado: Meta lo exige para revisar la plantilla.');
        }

        // ---- body + variables ----
        $body = (string) ($input['body'] ?? '');
        if (trim($body) === '') {
            $errors[] = __('El cuerpo (BODY) es obligatorio.');
        } elseif (mb_strlen($body) > self::MAX_BODY) {
            $errors[] = __('El cuerpo admite máximo 1024 caracteres.');
        }

        $allText = $body.($headerFormat === 'TEXT' ? ' '.$headerText : '');
        $vars = $this->variables($allText);
        if ($vars !== range(1, count($vars))) {
            $errors[] = __('Las variables deben ser consecutivas empezando en {{1}} (sin saltos).');
        }
        if (preg_match('/\}\}\s*\{\{/', $allText) === 1) {
            $errors[] = __('No puede haber dos variables seguidas sin texto o puntuación entre ellas.');
        }

        $examples = is_array($input['examples'] ?? null) ? $input['examples'] : [];
        foreach ($vars as $n) {
            if (trim((string) ($examples[$n] ?? '')) === '') {
                $errors[] = __('Falta el valor de ejemplo de la variable {{:n}} (Meta lo exige para la revisión).', ['n' => $n]);
            }
        }

        // ---- footer ----
        $footer = (string) ($input['footer'] ?? '');
        if ($footer !== '') {
            if (mb_strlen($footer) > self::MAX_FOOTER) {
                $errors[] = __('El pie (FOOTER) admite máximo 60 caracteres.');
            }
            if ($this->variables($footer) !== []) {
                $errors[] = __('El pie (FOOTER) no admite variables.');
            }
        }

        // ---- buttons ----
        $buttons = is_array($input['buttons'] ?? null) ? $input['buttons'] : [];
        if (count($buttons) > self::MAX_BUTTONS) {
            $errors[] = __('Máximo 10 botones por plantilla.');
        }
        $urlCount = 0;
        $phoneCount = 0;
        foreach ($buttons as $button) {
            if (! is_array($button)) {
                continue;
            }
            $type = strtoupper((string) ($button['type'] ?? ''));
            $text = (string) ($button['text'] ?? '');
            if (! in_array($type, self::BUTTON_TYPES, true)) {
                $errors[] = __('Tipo de botón no soportado: :type.', ['type' => $type !== '' ? $type : '—']);

                continue;
            }
            if (trim($text) === '' || mb_strlen($text) > self::MAX_BUTTON_TEXT) {
                $errors[] = __('Cada botón necesita un texto de máximo 25 caracteres.');
            }
            if ($type === 'URL') {
                $urlCount++;
                $url = (string) ($button['url'] ?? '');
                if (! str_starts_with($url, 'https://') && ! str_starts_with($url, 'http://')) {
                    $errors[] = __('El botón de URL necesita una dirección http(s) válida.');
                }
            }
            if ($type === 'PHONE_NUMBER') {
                $phoneCount++;
                if (preg_match('/^\+?[0-9 ]{6,20}$/', (string) ($button['phone'] ?? '')) !== 1) {
                    $errors[] = __('El botón de teléfono necesita un número válido (con código de país).');
                }
            }
        }
        if ($urlCount > self::MAX_URL_BUTTONS) {
            $errors[] = __('Máximo 2 botones de URL.');
        }
        if ($phoneCount > self::MAX_PHONE_BUTTONS) {
            $errors[] = __('Máximo 1 botón de teléfono.');
        }

        return array_values(array_unique($errors));
    }

    /**
     * Variables posicionales {{n}} detectadas en un texto, únicas y ordenadas.
     *
     * @return array<int, int>
     */
    public function variables(string $text): array
    {
        preg_match_all('/\{\{(\d+)\}\}/', $text, $matches);
        $vars = array_map(intval(...), $matches[1]);
        $vars = array_values(array_unique($vars));
        sort($vars);

        return $vars;
    }
}
