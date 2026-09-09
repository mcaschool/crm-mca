<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Modules\Social\Services\PostImageService;

/**
 * Normalización de imagen del Publicador. Lo crítico es el PASSTHROUGH: un JPEG subido se
 * guarda BYTE A BYTE tal cual (cero recompresión — re-codificar destruía ~60% de la
 * información y Facebook recomprime encima). Un PNG sí se convierte a JPEG (IG lo exige)
 * aplanando la transparencia sobre blanco y conservando dimensiones.
 */
function jpegBytes(int $w = 320, int $h = 240): string
{
    $img = imagecreatetruecolor($w, $h);
    imagefilledrectangle($img, 0, 0, $w, $h, (int) imagecolorallocate($img, 30, 90, 168));
    ob_start();
    imagejpeg($img, null, 90);
    imagedestroy($img);

    return (string) ob_get_clean();
}

function pngBytes(int $w = 320, int $h = 240): string
{
    $img = imagecreatetruecolor($w, $h);
    imagesavealpha($img, true);
    imagefill($img, 0, 0, (int) imagecolorallocatealpha($img, 0, 0, 0, 127));
    ob_start();
    imagepng($img);
    imagedestroy($img);

    return (string) ob_get_clean();
}

it('un JPEG se guarda tal cual, byte a byte (passthrough, sin recompresión)', function () {
    Storage::fake('public');
    $src = jpegBytes();

    $out = app(PostImageService::class)->storeJpeg($src);

    expect(Storage::disk('public')->get($out['path']))->toBe($src);
});

it('un PNG se convierte a JPEG conservando las dimensiones', function () {
    Storage::fake('public');
    $src = pngBytes(300, 200);

    $out = app(PostImageService::class)->storeJpeg($src);

    $stored = (string) Storage::disk('public')->get($out['path']);
    $info = getimagesizefromstring($stored);
    expect($info['mime'])->toBe('image/jpeg');
    expect($info[0])->toBe(300);
    expect($info[1])->toBe(200);
});

it('rechaza un binario que no es imagen', function () {
    Storage::fake('public');

    expect(fn () => app(PostImageService::class)->storeJpeg('esto no es una imagen'))
        ->toThrow(RuntimeException::class);
});
