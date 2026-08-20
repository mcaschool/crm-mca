<?php

declare(strict_types=1);

namespace Modules\Notifications\Support;

use DOMComment;
use DOMDocument;
use DOMElement;
use DOMText;

/**
 * Sanitiza el HTML del editor de correo con una LISTA BLANCA (allowlist): solo
 * sobreviven las etiquetas y atributos de formato explícitamente permitidos; todo
 * lo demás se elimina. Al ser allowlist, cualquier construcción desconocida o
 * maliciosa (scripts, manejadores on*, javascript:, iframes, estilos…) se descarta
 * por defecto. La salida se re-serializa con DOMDocument (mitiga mutation-XSS).
 *
 * Se aplica SIEMPRE en el servidor antes de guardar y enviar; el navegador no es
 * una barrera de seguridad.
 */
class EmailHtmlSanitizer
{
    /** Etiquetas de formato permitidas. */
    private const ALLOWED_TAGS = [
        'p', 'br', 'div', 'span', 'strong', 'b', 'em', 'i', 'u', 's', 'strike',
        'a', 'ul', 'ol', 'li', 'h1', 'h2', 'h3', 'blockquote', 'hr', 'img', 'font',
    ];

    /** Atributos permitidos por etiqueta (el resto se elimina). */
    private const ALLOWED_ATTRS = [
        'a' => ['href'],
        // La imagen inline solo conserva su marca `data-cid` (y alt); el src NO se
        // acepta del editor: lo fija el servidor a cid:… al embeber (ver embebedor).
        'img' => ['data-cid', 'alt'],
        // Tipografía: <font face="..." size="..."> con la fuente/tamaño elegidos
        // (ambos validados contra su lista blanca antes de conservarlos).
        'font' => ['face', 'size'],
    ];

    /** Atributos que son URL y deben validarse como enlace seguro. */
    private const URL_ATTRS = ['href'];

    /** Fuentes web-safe permitidas para <font face> (se rinden en Gmail/Outlook). */
    private const ALLOWED_FONTS = [
        'Arial', 'Georgia', 'Verdana', 'Times New Roman', 'Helvetica', 'Tahoma', 'Courier New', 'Trebuchet MS',
    ];

    /** Tamaños permitidos para <font size> (escala HTML 1–7; lo que emite fontSize). */
    private const ALLOWED_SIZES = ['1', '2', '3', '4', '5', '6', '7'];

    /** Contenedores peligrosos: se eliminan CON su contenido. */
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

            // Permitida: se limpian atributos no permitidos y se valida el href.
            $allowed = self::ALLOWED_ATTRS[$tag] ?? [];
            foreach (iterator_to_array($child->attributes) as $attr) {
                $name = strtolower($attr->nodeName);
                // Fuera cualquier atributo no permitido (incluye on*, style, src, etc.).
                if (! in_array($name, $allowed, true)) {
                    $child->removeAttribute($attr->nodeName);

                    continue;
                }
                // Los atributos de tipo URL (href) deben ser un enlace seguro.
                if (in_array($name, self::URL_ATTRS, true) && ! $this->safeHref(trim($attr->nodeValue ?? ''))) {
                    $child->removeAttribute($attr->nodeName);
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
}
