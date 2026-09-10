<?php

declare(strict_types=1);

namespace Modules\Social\Http\Controllers;

use Illuminate\Support\Facades\Storage;
use Modules\Social\Models\SocialMessage;
use Modules\Social\Services\WhatsAppMediaService;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Sirve los adjuntos de WhatsApp guardados en el disco PRIVADO ('local'): nunca hay URL
 * pública ni hotlink a Meta. Cadena de acceso:
 *  - middleware del panel (auth + institution.user + can:access-panel + 2FA);
 *  - binding implícito de SocialMessage con InstitutionScope → un mensaje de OTRA
 *    institución simplemente no existe (404);
 *  - canWorkCrm (mismo gate que la bandeja);
 *  - la ruta servida sale SIEMPRE de attachments[index].storage_path guardado por el
 *    propio CRM y se exige dentro de social-wa/ sin '..' (anti path-traversal).
 */
final class MediaController
{
    public function show(SocialMessage $message, int $index): BinaryFileResponse
    {
        abort_unless(auth()->user()?->canWorkCrm() ?? false, 403);

        $attachment = is_array($message->attachments) ? ($message->attachments[$index] ?? null) : null;
        abort_unless(is_array($attachment), 404);

        $path = (string) ($attachment['storage_path'] ?? '');
        abort_if(
            $path === ''
            || str_contains($path, '..')
            || ! str_starts_with($path, WhatsAppMediaService::STORAGE_DIR.'/'),
            404,
        );

        $disk = Storage::disk('local');
        abort_unless($disk->exists($path), 404);

        $filename = (string) ($attachment['filename'] ?? basename($path));

        return response()->file($disk->path($path), [
            'Content-Type' => (string) ($attachment['mime'] ?? 'application/octet-stream'),
            'Content-Disposition' => 'inline; filename="'.str_replace(['"', "\r", "\n"], '', $filename).'"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
