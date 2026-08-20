<?php

declare(strict_types=1);

namespace Modules\Notifications\Support;

use Illuminate\Support\Facades\Storage;
use Modules\Notifications\Models\EmailMessage;

/**
 * Prepara un correo ENVIADO para verlo en el panel TAL COMO se envió: el cuerpo ya
 * está sanitizado en origen; aquí solo se resuelven las imágenes inline (cid:) a
 * data-URI leyendo el archivo guardado, para que se vean dentro del correo en la
 * pantalla del panel (no es un envío: aquí data-URI es seguro y cómodo).
 */
class SentEmailRenderer
{
    public function displayBody(EmailMessage $message): string
    {
        $html = (string) $message->body;

        foreach ($message->inlineImages as $img) {
            if ($img->path === null || ! Storage::disk('local')->exists($img->path)) {
                continue;
            }
            $dataUri = 'data:'.$img->mime.';base64,'.base64_encode((string) Storage::disk('local')->get($img->path));
            $html = str_replace('cid:'.$img->content_id, $dataUri, $html);
        }

        return $html;
    }
}
