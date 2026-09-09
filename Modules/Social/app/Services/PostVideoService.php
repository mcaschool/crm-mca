<?php

declare(strict_types=1);

namespace Modules\Social\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Guarda el VIDEO subido (Reel / Historia) en el disco público TAL CUAL — sin recodificar ni
 * recomprimir (nada de FFmpeg en esta versión; el CRM no convierte videos dentro de una
 * petición PHP) — y devuelve ruta, URL pública, MIME real y tamaño.
 *
 * Validación por CONTENIDO (finfo), no por extensión: en esta primera versión solo MP4
 * (requisito común de Reels/Historias duales FB+IG). Duración/FPS/resolución no se pueden
 * medir de forma fiable sin ffprobe (no garantizado en Hostinger), así que las valida Meta
 * al publicar y sus errores se traducen a mensajes comprensibles.
 */
final class PostVideoService
{
    /** Formatos aceptados en esta primera versión. */
    private const ACCEPTED_MIMES = ['video/mp4'];

    /**
     * Tope propio del servicio (100 MB), ADEMÁS del de Livewire/Publisher: defensa en
     * profundidad para cuando este servicio se reutilice desde Jobs u otros flujos.
     */
    private const MAX_BYTES = 104857600;

    /**
     * @return array{path: string, url: string, mime: string, size: int}
     */
    public function store(UploadedFile $file): array
    {
        if (! $file->isValid()) {
            throw new RuntimeException('El archivo de video no se subió correctamente. Inténtalo de nuevo.');
        }

        $mime = (string) $file->getMimeType(); // finfo sobre el contenido real, no la extensión
        if (! in_array($mime, self::ACCEPTED_MIMES, true)) {
            throw new RuntimeException('El video debe ser MP4. El archivo subido es '.($mime !== '' ? $mime : 'de tipo desconocido').'.');
        }

        if ((int) $file->getSize() > self::MAX_BYTES) {
            throw new RuntimeException('El video supera el tamaño máximo permitido (100 MB).');
        }

        // putFileAs hace copia por streaming: el video nunca se carga entero en memoria.
        $path = Storage::disk('public')->putFileAs('social-posts', $file, Str::uuid()->toString().'.mp4');
        if ($path === false) {
            throw new RuntimeException('No se pudo guardar el video en el servidor.');
        }

        // Integridad: el archivo persistido debe existir y medir lo mismo que el subido.
        // Un guardado parcial (disco lleno, corte) se elimina para no dejar huérfanos.
        $expected = (int) filesize((string) $file->getRealPath());
        $stored = Storage::disk('public')->exists($path) ? (int) Storage::disk('public')->size($path) : -1;
        if ($stored !== $expected) {
            Storage::disk('public')->delete($path);

            throw new RuntimeException('El video no se guardó completo en el servidor. Inténtalo de nuevo.');
        }

        return [
            'path' => $path,
            'url' => $this->publicUrl($path),
            'mime' => $mime,
            'size' => $stored,
        ];
    }

    /** URL absoluta HTTPS del disco público (Meta debe poder descargarla sin auth). */
    private function publicUrl(string $path): string
    {
        $url = Storage::disk('public')->url($path);

        return str_starts_with($url, 'http') ? $url : url($url);
    }
}
