<?php

declare(strict_types=1);

namespace Modules\Social\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\Social\Models\SocialChannel;
use RuntimeException;
use Throwable;

/**
 * Sube el MEDIA DE EJEMPLO de una plantilla (HEADER IMAGE/VIDEO/DOCUMENT) y devuelve el
 * asset handle ("h") que Meta exige en example.header_handle para la revisión.
 *
 * IMPORTANTE — este flujo NO es el de WhatsAppMediaService: el media_id de
 * /{PHONE_NUMBER_ID}/media sirve para ENVIAR mensajes, no para crear plantillas. Las
 * plantillas usan la Resumable Upload API de la app (flujo oficial de 2 pasos):
 *   1) POST /{APP_ID}/uploads?file_length&file_type  → {"id":"upload:SESSION"}
 *   2) POST /upload:SESSION  (header file_offset: 0, body binario) → {"h":"HANDLE"}
 * Autenticación por header (Bearer/OAuth); el token jamás viaja en la URL ni se loguea.
 */
final class WhatsAppTemplateMediaService
{
    private const TIMEOUT_SECONDS = 120;

    /** MIME admitidos por formato de HEADER de plantilla. */
    private const HEADER_MIMES = [
        'IMAGE' => ['image/jpeg', 'image/png'],
        'VIDEO' => ['video/mp4'],
        'DOCUMENT' => ['application/pdf'],
    ];

    /**
     * Sube el archivo de ejemplo y devuelve el handle ("h"). Lanza RuntimeException con
     * mensaje apto para el usuario si el archivo no es válido o Meta rechaza la subida.
     */
    public function uploadExample(SocialChannel $channel, UploadedFile $file, string $headerFormat): string
    {
        $token = (string) ($channel->credentials['token'] ?? '');
        $appId = (string) config('social.meta_app_id', '');
        if ($token === '' || $appId === '') {
            throw new RuntimeException(__('Falta configuración para subir el ejemplo (token del canal o App ID de Meta).'));
        }

        $path = (string) $file->getRealPath();
        $mime = $this->detectMime($path);
        $allowed = self::HEADER_MIMES[strtoupper($headerFormat)] ?? [];
        if ($mime === null || ! in_array($mime, $allowed, true)) {
            throw new RuntimeException(__('Formato de ejemplo no válido para este encabezado. Usa JPG/PNG (imagen), MP4 (video) o PDF (documento).'));
        }

        $size = (int) filesize($path);
        $version = (string) config('social.graph_version', 'v26.0');

        try {
            // Paso 1: abrir la sesión de subida.
            $session = Http::timeout(self::TIMEOUT_SECONDS)
                ->withToken($token)
                ->acceptJson()
                ->post("https://graph.facebook.com/{$version}/{$appId}/uploads", [
                    'file_length' => $size,
                    'file_type' => $mime,
                ]);
            $sessionId = $session->json('id');
            if (! $session->successful() || ! is_string($sessionId) || $sessionId === '') {
                Log::warning('social.wa.tpl.media: Meta no abrió la sesión de subida', ['status' => $session->status()]);
                throw new RuntimeException(__('No se pudo iniciar la subida del ejemplo. Inténtalo de nuevo.'));
            }

            // Paso 2: enviar el binario (Authorization: OAuth + file_offset).
            $upload = Http::timeout(self::TIMEOUT_SECONDS)
                ->withHeaders([
                    'Authorization' => 'OAuth '.$token,
                    'file_offset' => '0',
                ])
                ->withBody((string) file_get_contents($path), $mime)
                ->post("https://graph.facebook.com/{$version}/{$sessionId}");
            $handle = $upload->json('h');
            if (! $upload->successful() || ! is_string($handle) || $handle === '') {
                Log::warning('social.wa.tpl.media: Meta rechazó el binario de ejemplo', ['status' => $upload->status()]);
                throw new RuntimeException(__('No se pudo subir el archivo de ejemplo. Inténtalo de nuevo.'));
            }

            return $handle;
        } catch (RuntimeException $e) {
            throw $e;
        } catch (Throwable $e) {
            Log::warning('social.wa.tpl.media: error de red', ['error' => $e->getMessage()]);
            throw new RuntimeException(__('No se pudo contactar con Meta para subir el ejemplo (red).'));
        }
    }

    private function detectMime(string $path): ?string
    {
        if ($path === '' || ! is_file($path)) {
            return null;
        }
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            return null;
        }
        $mime = finfo_file($finfo, $path);
        finfo_close($finfo);

        return is_string($mime) && $mime !== '' ? $mime : null;
    }
}
