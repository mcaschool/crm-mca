<?php

declare(strict_types=1);

namespace Modules\Notifications\Support;

use DOMComment;
use DOMDocument;
use DOMElement;
use DOMText;

/**
 * Sanitiza el HTML del editor de correo con una LISTA BLANCA (allowlist): solo
 * sobreviven las etiquetas y atributos explícitamente permitidos; todo lo demás se
 * elimina. Al ser allowlist, cualquier construcción desconocida o maliciosa (scripts,
 * manejadores on*, javascript:, iframes, <style>…) se descarta por defecto. La salida
 * se re-serializa con DOMDocument (mitiga mutation-XSS).
 *
 * Segunda puerta "email-safe" (modo código HTML/CSS): además del formato básico se
 * admiten TABLAS de maquetación y ESTILOS INLINE (style="…") saneados por una lista
 * blanca de PROPIEDADES CSS y valores seguros, más imágenes por URL https/data:image.
 * Esto NO abre el candado: lo ejecutable/peligroso sigue bloqueado siempre. NO se
 * admite el bloque <style> (solo estilos inline, que es lo que respeta Gmail).
 *
 * Se aplica SIEMPRE en el servidor antes de guardar y enviar; el navegador no es una
 * barrera de seguridad (la vista previa del editor va en un iframe sandbox sin scripts).
 */
class EmailHtmlSanitizer
{
    /** Etiquetas permitidas: formato + maquetación de correo (tablas). */
    private const ALLOWED_TAGS = [
        'p', 'br', 'div', 'span', 'strong', 'b', 'em', 'i', 'u', 's', 'strike',
        'a', 'ul', 'ol', 'li', 'h1', 'h2', 'h3', 'blockquote', 'hr', 'img', 'font', 'center',
        'table', 'thead', 'tbody', 'tfoot', 'tr', 'td', 'th', 'caption', 'col', 'colgroup',
    ];

    /** Atributo permitido en CUALQUIER etiqueta permitida: estilo inline (saneado). */
    private const GLOBAL_ATTRS = ['style'];

    /** Atributos permitidos por etiqueta (además de los globales; el resto se elimina). */
    private const ALLOWED_ATTRS = [
        'a' => ['href'],
        // Imagen: `src` (https/data:image, validado) o `data-cid` (subida embebida por CID).
        'img' => ['src', 'alt', 'width', 'height', 'data-cid'],
        // Tipografía: <font face="..." size="..."> (validados contra su lista blanca).
        'font' => ['face', 'size'],
        // Maquetación de tablas: atributos presentacionales inertes (no ejecutables).
        'table' => ['width', 'height', 'align', 'bgcolor', 'cellpadding', 'cellspacing', 'border'],
        'thead' => ['align', 'valign'],
        'tbody' => ['align', 'valign'],
        'tfoot' => ['align', 'valign'],
        'tr' => ['align', 'valign', 'bgcolor', 'height'],
        'td' => ['width', 'height', 'align', 'valign', 'bgcolor', 'colspan', 'rowspan'],
        'th' => ['width', 'height', 'align', 'valign', 'bgcolor', 'colspan', 'rowspan'],
        'col' => ['width', 'span', 'align', 'valign'],
        'colgroup' => ['width', 'span', 'align', 'valign'],
    ];

    /** Atributos que son URL y deben validarse como enlace seguro (esquema). */
    private const URL_ATTRS = ['href'];

    /** Fuentes web-safe permitidas para <font face> (se rinden en Gmail/Outlook). */
    private const ALLOWED_FONTS = [
        'Arial', 'Georgia', 'Verdana', 'Times New Roman', 'Helvetica', 'Tahoma', 'Courier New', 'Trebuchet MS',
    ];

    /** Tamaños permitidos para <font size> (escala HTML 1–7; lo que emite fontSize). */
    private const ALLOWED_SIZES = ['1', '2', '3', '4', '5', '6', '7'];

    /**
     * Propiedades CSS permitidas en `style` inline (email-safe: color, tipografía,
     * caja, bordes, fondos, maquetación). NO ejecutables. Además se admite cualquier
     * propiedad con prefijo `mso-` (específicas de Outlook, inertes).
     */
    private const ALLOWED_CSS = [
        'color', 'background', 'background-color', 'background-image', 'background-position', 'background-repeat', 'background-size',
        'font', 'font-family', 'font-size', 'font-weight', 'font-style', 'font-variant',
        'line-height', 'letter-spacing', 'word-spacing', 'text-align', 'text-decoration', 'text-transform', 'text-indent', 'white-space', 'direction', 'vertical-align',
        'width', 'height', 'max-width', 'min-width', 'max-height', 'min-height',
        'margin', 'margin-top', 'margin-right', 'margin-bottom', 'margin-left',
        'padding', 'padding-top', 'padding-right', 'padding-bottom', 'padding-left',
        'border', 'border-top', 'border-right', 'border-bottom', 'border-left',
        'border-width', 'border-style', 'border-color', 'border-radius', 'border-collapse', 'border-spacing',
        'display', 'float', 'clear', 'table-layout', 'empty-cells', 'caption-side',
        'list-style', 'list-style-type', 'list-style-position',
        'box-sizing', 'overflow', 'opacity',
    ];

    /** Contenedores peligrosos: se eliminan CON su contenido (incluye <style>). */
    private const DANGEROUS_TAGS = [
        'script', 'style', 'iframe', 'object', 'embed', 'link', 'meta',
        'head', 'title', 'template', 'noscript', 'form', 'input', 'button', 'svg', 'math',
    ];

    public function sanitize(string $html): string
    {
        $html = trim($html);
        if ($html === '') {
            return '';
        }

        $dom = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        // El prefijo XML fuerza UTF-8; DOMDocument envuelve el fragmento en html>body.
        $dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $body = $dom->getElementsByTagName('body')->item(0);
        if (! $body instanceof DOMElement) {
            return '';
        }

        $this->clean($body, $dom);

        $out = '';
        foreach (iterator_to_array($body->childNodes) as $child) {
            $out .= $dom->saveHTML($child);
        }

        return trim($out);
    }

    private function clean(DOMElement $node, DOMDocument $dom): void
    {
        // Copia a array porque el árbol se muta durante el recorrido.
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof DOMComment) {
                $node->removeChild($child);

                continue;
            }

            if ($child instanceof DOMText) {
                continue; // el texto se conserva (se re-escapa al serializar)
            }

            if (! $child instanceof DOMElement) {
                $node->removeChild($child); // PI, CDATA, etc.

                continue;
            }

            $tag = strtolower($child->tagName);

            // Peligrosas: fuera con todo su contenido.
            if (in_array($tag, self::DANGEROUS_TAGS, true)) {
                $node->removeChild($child);

                continue;
            }

            // No permitida (pero no peligrosa): se DESENVUELVE (se conserva su texto).
            if (! in_array($tag, self::ALLOWED_TAGS, true)) {
                $this->clean($child, $dom);
                while ($child->firstChild !== null) {
                    $node->insertBefore($child->firstChild, $child);
                }
                $node->removeChild($child);

                continue;
            }

            // Permitida: se limpian atributos no permitidos y se validan los sensibles.
            $allowed = array_merge(self::GLOBAL_ATTRS, self::ALLOWED_ATTRS[$tag] ?? []);
            foreach (iterator_to_array($child->attributes) as $attr) {
                $name = strtolower($attr->nodeName);

                // Fuera cualquier atributo no permitido (incluye on*, class, id, etc.).
                if (! in_array($name, $allowed, true)) {
                    $child->removeAttribute($attr->nodeName);

                    continue;
                }
                // Enlace: solo http(s)/mailto.
                if (in_array($name, self::URL_ATTRS, true) && ! $this->safeHref(trim($attr->nodeValue ?? ''))) {
                    $child->removeAttribute($attr->nodeName);

                    continue;
                }
                // Imagen: src solo https o data:image (si no, se quita; queda el resto).
                if ($tag === 'img' && $name === 'src' && ! $this->safeImageSrc(trim($attr->nodeValue ?? ''))) {
                    $child->removeAttribute($attr->nodeName);

                    continue;
                }
                // Estilo inline: se sanea por lista blanca de propiedades y valores.
                if ($name === 'style') {
                    $clean = $this->sanitizeStyle((string) $attr->nodeValue);
                    if ($clean === '') {
                        $child->removeAttribute($attr->nodeName);
                    } else {
                        $child->setAttribute('style', $clean);
                    }
                }
            }

            // Tipografía: solo se admite una fuente de la lista blanca.
            if ($tag === 'font' && $child->hasAttribute('face')
                && ! in_array(trim($child->getAttribute('face')), self::ALLOWED_FONTS, true)) {
                $child->removeAttribute('face');
            }

            // Tamaño: solo se admite un valor de la escala HTML 1–7.
            if ($tag === 'font' && $child->hasAttribute('size')
                && ! in_array(trim($child->getAttribute('size')), self::ALLOWED_SIZES, true)) {
                $child->removeAttribute('size');
            }

            // Los enlaces que sobreviven abren en pestaña nueva y sin fuga de opener.
            if ($tag === 'a' && $child->hasAttribute('href')) {
                $child->setAttribute('target', '_blank');
                $child->setAttribute('rel', 'noopener noreferrer');
            }

            $this->clean($child, $dom);
        }
    }

    /** Solo se admiten enlaces http(s) y mailto. javascript:, data:, etc. se descartan. */
    private function safeHref(string $href): bool
    {
        $lower = strtolower($href);

        return str_starts_with($lower, 'http://')
            || str_starts_with($lower, 'https://')
            || str_starts_with($lower, 'mailto:');
    }

    /** Imagen: solo por https o data:image (banner alojado o imagen embebida). */
    private function safeImageSrc(string $src): bool
    {
        $lower = strtolower($src);

        return str_starts_with($lower, 'https://') || str_starts_with($lower, 'data:image/');
    }

    /**
     * Sanea un `style` inline: conserva SOLO declaraciones cuya propiedad está en la
     * lista blanca (o es `mso-*`) y cuyo valor es seguro. Devuelve el CSS saneado
     * (vacío si no queda nada válido). Bloquea expression()/javascript:/behavior/
     *
     * @import/url() no https|data:image, y valores con `<`/`>`/backslash.
     */
    private function sanitizeStyle(string $css): string
    {
        if (strlen($css) > 4000) {
            return ''; // tope anti-DoS
        }

        $out = [];
        foreach (explode(';', $css) as $decl) {
            $decl = trim($decl);
            if ($decl === '') {
                continue;
            }
            $pos = strpos($decl, ':');
            if ($pos === false) {
                continue;
            }
            $prop = strtolower(trim(substr($decl, 0, $pos)));
            $value = trim(substr($decl, $pos + 1));

            if ($prop === '' || $value === '') {
                continue;
            }
            if (! in_array($prop, self::ALLOWED_CSS, true) && ! str_starts_with($prop, 'mso-')) {
                continue;
            }
            if (! $this->safeCssValue($value)) {
                continue;
            }

            $out[] = $prop.': '.$value;
        }

        return implode('; ', $out);
    }

    /** ¿Es seguro el valor de una declaración CSS? (sin ejecutables ni url() peligrosas) */
    private function safeCssValue(string $value): bool
    {
        if (strlen($value) > 500) {
            return false;
        }

        $low = strtolower($value);
        foreach (['expression', 'javascript:', 'vbscript:', 'behavior', '-moz-binding', '@import', '</', '\\'] as $bad) {
            if (str_contains($low, $bad)) {
                return false;
            }
        }
        if (str_contains($value, '<') || str_contains($value, '>')) {
            return false;
        }

        // Toda url(...) debe apuntar a https:// o data:image (nada de javascript:, http, etc.).
        if (str_contains($low, 'url(')) {
            preg_match_all('/url\(\s*[\'"]?([^\'")]+)/i', $value, $m);
            foreach ($m[1] as $url) {
                $u = strtolower(trim($url));
                if (! str_starts_with($u, 'https://') && ! str_starts_with($u, 'data:image/')) {
                    return false;
                }
            }
        }

        return true;
    }
}
