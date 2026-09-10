<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Modules\Social\Services\PostImageService;

/**
 * POLÍTICA DE PRESERVACIÓN del Publicador: la imagen se guarda TAL CUAL, byte a byte —
 * sin recomprimir, redimensionar, recodificar ni tocar metadatos. JPEG y PNG pasan
 * idénticos (probado por identidad binaria Y SHA-256); jamás se genera un derivado.
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

it('un JPEG se guarda tal cual, byte a byte (sin recompresión) y con extensión .jpg', function () {
    Storage::fake('public');
    $src = jpegBytes();

    $out = app(PostImageService::class)->store($src);

    $stored = (string) Storage::disk('public')->get($out['path']);
    expect($stored)->toBe($src); // identidad binaria estricta
    expect(hash('sha256', $stored))->toBe(hash('sha256', $src));
    expect($out['mime'])->toBe('image/jpeg');
    expect(str_ends_with($out['path'], '.jpg'))->toBeTrue();
});

it('un PNG se guarda tal cual, byte a byte: mismos bytes, .png, image/png y SIN derivado JPEG', function () {
    Storage::fake('public');
    $src = pngBytes(300, 200);

    $out = app(PostImageService::class)->store($src);

    $stored = (string) Storage::disk('public')->get($out['path']);
    expect(hash('sha256', $stored))->toBe(hash('sha256', $src)); // SHA-256 idéntico
    expect($stored)->toBe($src);                                  // e identidad binaria
    expect($out['mime'])->toBe('image/png');
    expect(str_ends_with($out['path'], '.png'))->toBeTrue();
    expect(str_ends_with($out['url'], '/'.$out['path']))->toBeTrue(); // la URL apunta al MISMO archivo

    // No se crea NINGÚN archivo derivado (ni .jpg ni variantes): solo el PNG original.
    $files = Storage::disk('public')->allFiles('social-posts');
    expect($files)->toHaveCount(1);
    expect(str_ends_with($files[0], '.png'))->toBeTrue();
});

it('rechaza un binario que no es imagen', function () {
    Storage::fake('public');

    expect(fn () => app(PostImageService::class)->store('esto no es una imagen'))
        ->toThrow(RuntimeException::class);
});

it('rechaza formatos de imagen distintos de JPEG/PNG sin convertirlos', function () {
    Storage::fake('public');

    // GIF real de 1x1: formato válido de imagen, pero fuera de la política (no se convierte).
    $gif = base64_decode('R0lGODlhAQABAIAAAP///wAAACH5BAEAAAAALAAAAAABAAEAAAICRAEAOw==', true);

    expect(fn () => app(PostImageService::class)->store((string) $gif))
        ->toThrow(RuntimeException::class, 'no soportado');
    expect(Storage::disk('public')->allFiles('social-posts'))->toBe([]);
});
