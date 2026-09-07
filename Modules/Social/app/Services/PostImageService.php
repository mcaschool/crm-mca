<?php

declare(strict_types=1);

namespace Modules\Social\Services;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Normaliza la imagen subida a JPEG (Instagram exige JPEG) y la guarda en el disco PÚBLICO,
 * devolviendo la ruta y la URL HTTPS pública que Meta descargará. Aplana la transparencia
 * (PNG) sobre blanco para evitar fondos negros al convertir.
 */
final class PostImageService
{
    /**
     * @return array{path: string, url: string}
     */
    public function storeJpeg(string $binary): array
    {
        $source = @imagecreatefromstring($binary);
        if ($source === false) {
            throw new RuntimeException('El archivo no es una imagen válida.');
        }

        $width = imagesx($source);
        $height = imagesy($source);
        $canvas = imagecreatetruecolor($width, $height);
        $white = imagecolorallocate($canvas, 255, 255, 255);
        imagefilledrectangle($canvas, 0, 0, $width, $height, (int) $white);
        imagecopy($canvas, $source, 0, 0, 0, 0, $width, $height);

        ob_start();
        imagejpeg($canvas, null, 88);
        $jpeg = (string) ob_get_clean();

        imagedestroy($source);
        imagedestroy($canvas);

        $path = 'social-posts/'.Str::uuid()->toString().'.jpg';
        Storage::disk('public')->put($path, $jpeg);

        return ['path' => $path, 'url' => $this->publicUrl($path)];
    }

    /** URL absoluta HTTPS del disco público (Meta debe poder descargarla sin auth). */
    private function publicUrl(string $path): string
    {
        $url = Storage::disk('public')->url($path);

        return str_starts_with($url, 'http') ? $url : url($url);
    }
}
