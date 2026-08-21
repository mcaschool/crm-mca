<?php

declare(strict_types=1);

namespace Modules\Notifications\Support;

use DOMDocument;
use DOMElement;

/**
 * Prepara las imágenes INLINE del cuerpo para embeberse por CID. El editor inserta
 * <img data-cid="X" src="…preview…">; aquí se reescribe a <img src="cid:X"> SOLO
 * para las imágenes realmente subidas (mapa cid => descriptor), y se descarta
 * cualquier <img> que no coincida (así no se cuelan imágenes externas/tracking).
 *
 * Devuelve el HTML reescrito y los descriptores de las imágenes USADAS (las que hay
 * que embeber). El destinatario ve la imagen DENTRO del mensaje (no como enlace
 * externo ni base64).
 */
class InlineImageEmbedder
{
    /**
     * @param  array<string, array{path: string, mime: string, size: int}>  $inline
     * @return array{0: string, 1: array<int, array{path: string, cid: string, mime: string}>}
     */
    public function embed(string $html, array $inline): array
    {
        if (! str_contains($html, '<img')) {
            return [$html, []];
        }

        $dom = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $body = $dom->getElementsByTagName('body')->item(0);
        if (! $body instanceof DOMElement) {
            return [$html, []];
        }

        $used = [];
        foreach (iterator_to_array($dom->getElementsByTagName('img')) as $img) {
            $cid = trim($img->getAttribute('data-cid'));
            if ($cid !== '' && isset($inline[$cid])) {
                $img->removeAttribute('data-cid');
                $img->setAttribute('src', 'cid:'.$cid);
                if (! $img->hasAttribute('style')) {
                    $img->setAttribute('style', 'max-width:100%;height:auto');
                }
                $used[$cid] = ['path' => $inline[$cid]['path'], 'cid' => $cid, 'mime' => $inline[$cid]['mime']];
            } else {
                // No es una subida embebida. Se conserva SOLO si trae una imagen externa
                // segura (https/data:image, ya validada por el sanitizador); cualquier
                // otra (http, tracking, sin src) se descarta.
                $src = strtolower(trim($img->getAttribute('src')));
                if ($src !== '' && (str_starts_with($src, 'https://') || str_starts_with($src, 'data:image/'))) {
                    $img->removeAttribute('data-cid');
                } else {
                    $img->parentNode?->removeChild($img);
                }
            }
        }

        $out = '';
        foreach (iterator_to_array($body->childNodes) as $node) {
            $out .= $dom->saveHTML($node);
        }

        return [trim($out), array_values($used)];
    }
}
