<?php

declare(strict_types=1);

namespace Modules\Notifications\Support;

use DOMDocument;
use DOMElement;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Notifications\Models\EmailTemplate;

/**
 * Gestiona las imágenes inline PERSISTIDAS de una plantilla. El cuerpo referencia cada
 * imagen por `data-cid`; el archivo vive en el disco privado. Tres operaciones:
 *
 *  - persist(): reconcilia el cuerpo guardado con las imágenes en disco (guarda las
 *    nuevas, conserva las que siguen referenciadas, borra las que ya no aparecen).
 *  - displayBody(): reinyecta `src="data:…"` en cada <img data-cid> para PODER VERLA
 *    en el editor/vista previa (data-URI solo para mostrar en pantalla, nunca al enviar).
 *  - inlineMap(): descriptores cid => imagen para REHIDRATAR el pipeline de embebido
 *    por CID al cargar la plantilla en el compositor (así viaja dentro del correo).
 */
class TemplateBodyImages
{
    /**
     * Reconcilia las imágenes de la plantilla con las referenciadas en el cuerpo ya
     * sanitizado. `$newImages`: cid => ['path' (temporal), 'mime', 'size'] recién subidas.
     *
     * @param  array<string, array{path: string, mime: string, size: int}>  $newImages
     */
    public function persist(EmailTemplate $template, string $sanitizedBody, array $newImages): void
    {
        $referenced = $this->referencedCids($sanitizedBody);

        // Borra las imágenes que ya no aparecen en el cuerpo (y su archivo).
        foreach ($template->images()->get() as $existing) {
            if (! in_array($existing->content_id, $referenced, true)) {
                if ($existing->path !== null && Storage::disk('local')->exists($existing->path)) {
                    Storage::disk('local')->delete($existing->path);
                }
                $existing->delete();
            }
        }

        $already = $template->images()->pluck('content_id')->all();

        // Guarda las imágenes nuevas referenciadas que aún no están persistidas.
        foreach ($newImages as $cid => $img) {
            if (! in_array($cid, $referenced, true) || in_array($cid, $already, true)) {
                continue;
            }
            if (! is_file($img['path'])) {
                continue;
            }

            $path = 'email-template-files/'.$template->institution_id.'/'.$template->getKey().'/'.Str::random(20).'_img';
            Storage::disk('local')->put($path, (string) file_get_contents($img['path']));

            $template->images()->create([
                'content_id' => $cid,
                'mime' => $img['mime'],
                'size' => $img['size'],
                'path' => $path,
            ]);
        }
    }

    /**
     * Cuerpo listo para MOSTRAR: cada <img data-cid> recupera su `src` como data-URI
     * leyendo el archivo guardado. Solo para pantalla (editor/vista previa).
     */
    public function displayBody(EmailTemplate $template): string
    {
        $html = (string) $template->body;
        if (! str_contains($html, '<img')) {
            return $html;
        }

        $map = [];
        foreach ($template->images()->get() as $img) {
            if ($img->path !== null && Storage::disk('local')->exists($img->path)) {
                $map[$img->content_id] = 'data:'.$img->mime.';base64,'.base64_encode((string) Storage::disk('local')->get($img->path));
            }
        }

        if ($map === []) {
            return $html;
        }

        $dom = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $body = $dom->getElementsByTagName('body')->item(0);
        if (! $body instanceof DOMElement) {
            return $html;
        }

        foreach (iterator_to_array($dom->getElementsByTagName('img')) as $img) {
            $cid = trim($img->getAttribute('data-cid'));
            if ($cid !== '' && isset($map[$cid])) {
                $img->setAttribute('src', $map[$cid]);
                $img->setAttribute('style', 'max-width:100%;height:auto');
            }
        }

        $out = '';
        foreach (iterator_to_array($body->childNodes) as $node) {
            $out .= $dom->saveHTML($node);
        }

        return trim($out);
    }

    /**
     * Descriptores para REHIDRATAR el embebido por CID: cid => ['path' (absoluta en
     * disco), 'mime', 'size']. Solo las imágenes que siguen referenciadas en el cuerpo.
     *
     * @return array<string, array{path: string, mime: string, size: int}>
     */
    public function inlineMap(EmailTemplate $template): array
    {
        $referenced = $this->referencedCids((string) $template->body);
        $map = [];

        foreach ($template->images()->get() as $img) {
            if ($img->path === null
                || ! in_array($img->content_id, $referenced, true)
                || ! Storage::disk('local')->exists($img->path)) {
                continue;
            }

            $map[$img->content_id] = [
                'path' => Storage::disk('local')->path($img->path),
                'mime' => $img->mime,
                'size' => $img->size,
            ];
        }

        return $map;
    }

    /**
     * cid de cada <img data-cid> presente en el cuerpo.
     *
     * @return array<int, string>
     */
    private function referencedCids(string $html): array
    {
        if (! str_contains($html, 'data-cid')) {
            return [];
        }

        preg_match_all('/data-cid="([^"]+)"/', $html, $matches);

        return array_values(array_unique($matches[1]));
    }
}
