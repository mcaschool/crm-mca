<?php

declare(strict_types=1);

namespace Modules\Social\Services;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Normaliza la imagen subida a JPEG (Instagram exige JPEG) y la guarda en el disco PÚBLICO,
 * devolviendo la ruta y la URL HTTPS pública que Meta descargará.
 *
 * PASSTHROUGH (calidad): si el archivo subido YA es JPEG, se guarda BYTE A BYTE tal cual —
 * sin decodificar ni re-codificar (cero pérdida nuestra; conserva EXIF/ICC). Re-codificar con
 * GD destruía ~60% de la información a igual resolución (medido con un post real: 463 KB →
 * 177 KB) por el q92 efectivo + submuestreo de croma 4:2:0, y Facebook recomprime ENCIMA →
 * texto con halos. Solo se convierte lo que NO es JPEG (PNG): aplanando la transparencia
 * sobre blanco para evitar fondos negros. NO redimensiona: conserva las dimensiones
 * originales (Meta reduce en su lado si excede sus máximos).
 */
final class PostImageService
{
    /** Calidad de codificación JPEG (0-100) al CONVERTIR un PNG; un JPEG no se re-codifica. */
    private const JPEG_QUALITY = 92;

    /**
     * @return array{path: string, url: string}
     */
    public function storeJpeg(string $binary): array
    {
        $info = @getimagesizefromstring($binary);
        if ($info === false) {
            throw new RuntimeException('El archivo no es una imagen válida.');
        }

        $jpeg = $info['mime'] === 'image/jpeg' ? $binary : $this->convertToJpeg($binary);

        $path = 'social-posts/'.Str::uuid()->toString().'.jpg';
        Storage::disk('public')->put($path, $jpeg);

        return ['path' => $path, 'url' => $this->publicUrl($path)];
    }

    /** PNG (u otro formato soportado por GD) → JPEG, aplanando la transparencia sobre blanco. */
    private function convertToJpeg(string $binary): string
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
        imagejpeg($canvas, null, self::JPEG_QUALITY);
        $jpeg = (string) ob_get_clean();

        imagedestroy($source);
        imagedestroy($canvas);

        return $jpeg;
    }

    /** URL absoluta HTTPS del disco público (Meta debe poder descargarla sin auth). */
    private function publicUrl(string $path): string
    {
        $url = Storage::disk('public')->url($path);

        return str_starts_with($url, 'http') ? $url : url($url);
    }
}
