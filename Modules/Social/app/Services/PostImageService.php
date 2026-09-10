<?php

declare(strict_types=1);

namespace Modules\Social\Services;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Guarda la IMAGEN subida TAL CUAL — byte a byte, sin recomprimir, redimensionar,
 * recodificar ni tocar metadatos/perfil de color (POLÍTICA DE PRESERVACIÓN: el CRM nunca
 * transforma el medio; cualquier compresión queda en manos de Meta). Acepta JPEG y PNG,
 * validados por CONTENIDO (no por extensión); la extensión almacenada refleja el MIME real.
 * Si un destino no admite el formato (p. ej. Instagram exige JPEG), el Publicador lo
 * rechaza ANTES de publicar — jamás se convierte en silencio.
 */
final class PostImageService
{
    /** MIME reales aceptados y su extensión de almacenamiento. */
    private const EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
    ];

    /**
     * @return array{path: string, url: string, mime: string}
     */
    public function store(string $binary): array
    {
        $info = @getimagesizefromstring($binary);
        if ($info === false) {
            throw new RuntimeException('El archivo no es una imagen válida.');
        }

        $ext = self::EXTENSIONS[$info['mime']] ?? null;
        if ($ext === null) {
            throw new RuntimeException('Formato de imagen no soportado: usa JPG/JPEG o PNG.');
        }

        $path = 'social-posts/'.Str::uuid()->toString().'.'.$ext;
        Storage::disk('public')->put($path, $binary);

        return ['path' => $path, 'url' => $this->publicUrl($path), 'mime' => $info['mime']];
    }

    /** URL absoluta HTTPS del disco público (Meta debe poder descargarla sin auth). */
    private function publicUrl(string $path): string
    {
        $url = Storage::disk('public')->url($path);

        return str_starts_with($url, 'http') ? $url : url($url);
    }
}
