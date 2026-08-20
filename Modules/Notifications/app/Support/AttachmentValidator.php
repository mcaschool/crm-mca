<?php

declare(strict_types=1);

namespace Modules\Notifications\Support;

use Illuminate\Http\UploadedFile;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * Validación de adjuntos en el SERVIDOR (no solo en el navegador): tamaño por
 * archivo, tamaño total, número y TIPO seguro. El tamaño y el MIME se leen del
 * archivo REAL ya subido al servidor (no del dato que declara el cliente), así que
 * no se puede saltar el límite manipulando la petición.
 */
class AttachmentValidator
{
    /**
     * @param  array<int, UploadedFile|TemporaryUploadedFile>  $files
     * @return array<int, string> errores (vacío = válido)
     */
    public function validate(array $files): array
    {
        /** @var array{max_file_bytes:int,max_total_bytes:int,max_count:int,allowed_extensions:array<int,string>,allowed_mimes:array<int,string>} $cfg */
        $cfg = config('crm.mail.attachments');

        $errors = [];

        if (count($files) > $cfg['max_count']) {
            $errors[] = "No se permiten más de {$cfg['max_count']} adjuntos.";
        }

        $total = 0;
        foreach ($files as $file) {
            $name = $file->getClientOriginalName();
            $size = (int) $file->getSize();            // tamaño real del archivo en el servidor
            $mime = (string) $file->getMimeType();     // MIME derivado del CONTENIDO real
            $ext = strtolower($file->getClientOriginalExtension() ?: (string) pathinfo($name, PATHINFO_EXTENSION));
            $total += $size;

            if ($size > $cfg['max_file_bytes']) {
                $errors[] = sprintf('"%s" supera el máximo de %s por archivo.', $name, $this->human($cfg['max_file_bytes']));
            }

            if (! in_array($ext, $cfg['allowed_extensions'], true) || ! in_array($mime, $cfg['allowed_mimes'], true)) {
                $errors[] = sprintf('"%s" no es un tipo de archivo permitido.', $name);
            }
        }

        if ($total > $cfg['max_total_bytes']) {
            $errors[] = sprintf('El total de adjuntos supera el máximo de %s.', $this->human($cfg['max_total_bytes']));
        }

        return array_values(array_unique($errors));
    }

    /**
     * Valida en el SERVIDOR una imagen inline: debe ser una imagen real (MIME del
     * contenido) y no superar el máximo por archivo. Devuelve el error o null.
     */
    public function imageError(UploadedFile|TemporaryUploadedFile $file): ?string
    {
        /** @var array{max_file_bytes:int} $cfg */
        $cfg = config('crm.mail.attachments');
        $imageMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

        $mime = (string) $file->getMimeType();
        if (! in_array($mime, $imageMimes, true)) {
            return 'Solo se permiten imágenes (JPG, PNG, GIF o WebP).';
        }

        if ((int) $file->getSize() > $cfg['max_file_bytes']) {
            return 'La imagen supera el máximo de '.$this->human($cfg['max_file_bytes']).'.';
        }

        return null;
    }

    private function human(int $bytes): string
    {
        return round($bytes / (1024 * 1024)).' MB';
    }
}
