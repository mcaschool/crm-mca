<?php

declare(strict_types=1);

namespace Modules\Social\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Social\Models\SocialChannel;
use RuntimeException;
use Throwable;

/**
 * Media de WhatsApp Cloud API, en ambos sentidos:
 *
 *  - ENTRANTE: el webhook entrega un media id (no una URL). Flujo oficial:
 *      1) GET /{MEDIA_ID} con Bearer → devuelve una URL TEMPORAL de Meta + mime/size.
 *      2) Descargar esa URL con el mismo Bearer (no es pública ni hotlinkeable).
 *      3) Validar el contenido REAL (finfo, nunca solo la extensión/el mime declarado).
 *      4) Guardar en el disco PRIVADO del CRM (nunca en public: sin URL directa).
 *    La bandeja sirve el archivo por una ruta autenticada del panel (MediaController).
 *
 *  - SALIENTE: subir el binario a la Media API y enviar después por media id (el archivo
 *    jamás se expone a Meta mediante una URL pública del CRM):
 *      POST /{PHONE_NUMBER_ID}/media  (multipart: messaging_product=whatsapp + file)
 *
 * Los límites por tipo son los oficiales de WhatsApp (imagen 5 MB, sticker 1 MB,
 * video/audio 16 MB, documento 100 MB). El token va SIEMPRE en el header Bearer.
 */
final class WhatsAppMediaService
{
    private const TIMEOUT_SECONDS = 20;

    private const DOWNLOAD_TIMEOUT_SECONDS = 120;

    /** Directorio raíz en el disco PRIVADO ('local'); nunca en 'public'. */
    public const STORAGE_DIR = 'social-wa';

    /** Límites de WhatsApp Cloud API por tipo, en bytes. */
    private const TYPE_LIMITS = [
        'image' => 5 * 1024 * 1024,
        'sticker' => 1024 * 1024,
        'video' => 16 * 1024 * 1024,
        'audio' => 16 * 1024 * 1024,
        'document' => 100 * 1024 * 1024,
    ];

    /** MIME reales admitidos para ENVIAR, clasificados al tipo de mensaje de WhatsApp. */
    private const OUTBOUND_MIMES = [
        'image/jpeg' => 'image',
        'image/png' => 'image',
        'video/mp4' => 'video',
        'video/3gpp' => 'video',
        'audio/aac' => 'audio',
        'audio/mp4' => 'audio',
        'audio/mpeg' => 'audio',
        'audio/amr' => 'audio',
        'audio/ogg' => 'audio',
        'application/pdf' => 'document',
        'text/plain' => 'document',
        'application/msword' => 'document',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'document',
        'application/vnd.ms-excel' => 'document',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'document',
        'application/vnd.ms-powerpoint' => 'document',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'document',
    ];

    /** Extensión de almacenamiento por MIME (solo informativa; el acceso valida por ruta). */
    private const EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'video/mp4' => 'mp4',
        'video/3gpp' => '3gp',
        'audio/aac' => 'aac',
        'audio/mp4' => 'm4a',
        'audio/mpeg' => 'mp3',
        'audio/amr' => 'amr',
        'audio/ogg' => 'ogg',
        'application/pdf' => 'pdf',
        'text/plain' => 'txt',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.ms-excel' => 'xls',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'application/vnd.ms-powerpoint' => 'ppt',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
    ];

    /**
     * Clasifica un archivo del panel por su MIME REAL (finfo sobre el contenido, no la
     * extensión) y valida el límite de WhatsApp para ese tipo. Lanza RuntimeException con
     * mensaje apto para el usuario si no es enviable.
     *
     * @return array{0: string, 1: string} [tipo de mensaje WhatsApp, mime real]
     */
    public function classifyUpload(UploadedFile $file): array
    {
        $path = (string) $file->getRealPath();
        if (! $file->isValid() || $path === '' || ! is_file($path)) {
            throw new RuntimeException(__('No se pudo leer el archivo. Inténtalo de nuevo.'));
        }

        // finfo sobre el CONTENIDO real del archivo: nunca la extensión ni el mime declarado.
        $mime = $this->detectMimeFromPath($path);
        $type = $mime !== null ? (self::OUTBOUND_MIMES[$mime] ?? null) : null;
        if ($type === null) {
            throw new RuntimeException(__('Formato de archivo no soportado por WhatsApp. Usa JPG/PNG, MP4, audio (MP3/AAC/OGG) o documentos (PDF/Office/TXT).'));
        }

        $size = (int) filesize($path);
        $limit = self::TYPE_LIMITS[$type];
        if ($size <= 0 || $size > $limit) {
            throw new RuntimeException(__('El archivo supera el límite de WhatsApp para este tipo (:mb MB).', ['mb' => (int) ($limit / 1048576)]));
        }

        return [$type, $mime];
    }

    /**
     * Guarda la copia PRIVADA del adjunto saliente (para renderizarlo en la bandeja).
     *
     * @return array{storage_path: string, filename: string, size: int}
     */
    public function storeUpload(int $institutionId, UploadedFile $file, string $mime): array
    {
        $name = (string) Str::uuid().'.'.(self::EXTENSIONS[$mime] ?? 'bin');
        $path = Storage::disk('local')->putFileAs(self::STORAGE_DIR.'/'.$institutionId, $file, $name);
        if ($path === false) {
            throw new RuntimeException(__('No se pudo guardar el archivo. Inténtalo de nuevo.'));
        }

        return [
            'storage_path' => $path,
            'filename' => (string) $file->getClientOriginalName(),
            'size' => (int) Storage::disk('local')->size($path),
        ];
    }

    /**
     * Sube el binario a la Media API del canal y devuelve el media id (o null si falla).
     * El archivo viaja en el multipart del request autenticado; nunca por URL pública.
     */
    public function upload(SocialChannel $channel, string $absolutePath, string $mime, string $filename): ?string
    {
        $token = (string) ($channel->credentials['token'] ?? '');
        $phoneNumberId = (string) ($channel->external_id ?? '');
        if ($token === '' || $phoneNumberId === '' || ! is_file($absolutePath)) {
            return null;
        }

        $version = (string) config('social.graph_version', 'v26.0');
        $stream = fopen($absolutePath, 'rb');
        if ($stream === false) {
            return null;
        }

        try {
            $response = Http::timeout(self::DOWNLOAD_TIMEOUT_SECONDS)
                ->withToken($token)
                ->acceptJson()
                ->attach('file', $stream, $filename, ['Content-Type' => $mime])
                ->post("https://graph.facebook.com/{$version}/{$phoneNumberId}/media", [
                    'messaging_product' => 'whatsapp',
                ]);
        } catch (Throwable $e) {
            Log::warning('social.wa.media.upload: error de red', ['error' => $e->getMessage()]);

            return null;
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        if (! $response->successful()) {
            // Diagnóstico seguro: jamás token, Authorization ni el binario.
            Log::warning('social.wa.media.upload: Meta rechazó la subida', [
                'status' => $response->status(),
                'code' => (int) ($response->json('error.code') ?? 0),
                'message' => (string) ($response->json('error.message') ?? ''),
            ]);

            return null;
        }

        $id = $response->json('id');

        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * Resuelve y descarga un media ENTRANTE por su media id (flujo oficial de 2 pasos) y lo
     * guarda en el disco privado. Devuelve la metadata para fusionar en attachments, o null
     * si algo falla (best-effort: el mensaje ya está ingerido; nunca lanza hacia el webhook).
     *
     * @return array{storage_path: string, mime: string, size: int, filename: string}|null
     */
    public function fetchInbound(SocialChannel $channel, string $mediaId, string $type, ?string $filename = null): ?array
    {
        $token = (string) ($channel->credentials['token'] ?? '');
        if ($token === '' || $mediaId === '') {
            return null;
        }

        $version = (string) config('social.graph_version', 'v26.0');

        try {
            // Paso 1: metadata + URL temporal.
            $meta = Http::timeout(self::TIMEOUT_SECONDS)
                ->withToken($token)
                ->acceptJson()
                ->get("https://graph.facebook.com/{$version}/{$mediaId}");
            if (! $meta->successful()) {
                Log::info('social.wa.media.fetch: Meta no devolvió el media', ['status' => $meta->status()]);

                return null;
            }

            $url = $meta->json('url');
            $declaredSize = (int) ($meta->json('file_size') ?? 0);
            if (! is_string($url) || $url === '') {
                return null;
            }

            $limit = self::TYPE_LIMITS[$type] ?? self::TYPE_LIMITS['document'];
            if ($declaredSize > $limit) {
                Log::info('social.wa.media.fetch: media supera el límite del tipo', ['type' => $type, 'size' => $declaredSize]);

                return null;
            }

            // Paso 2: descarga autenticada de la URL temporal (no es pública).
            $download = Http::timeout(self::DOWNLOAD_TIMEOUT_SECONDS)
                ->withToken($token)
                ->get($url);
            if (! $download->successful()) {
                Log::info('social.wa.media.fetch: descarga fallida', ['status' => $download->status()]);

                return null;
            }

            $bytes = $download->body();
            if ($bytes === '' || strlen($bytes) > $limit) {
                return null;
            }

            // Validación por CONTENIDO real (nunca por extensión ni por el mime declarado).
            $detected = $this->detectMime($bytes);
            if ($detected === null || ! $this->mimeMatchesType($detected, $type)) {
                Log::info('social.wa.media.fetch: contenido no coincide con el tipo declarado', ['type' => $type]);

                return null;
            }

            $name = (string) Str::uuid().'.'.(self::EXTENSIONS[$detected] ?? 'bin');
            $path = self::STORAGE_DIR.'/'.$channel->institution_id.'/'.$name;
            Storage::disk('local')->put($path, $bytes);

            return [
                'storage_path' => $path,
                'mime' => $detected,
                'size' => strlen($bytes),
                'filename' => $filename !== null && $filename !== '' ? $filename : $name,
            ];
        } catch (Throwable $e) {
            Log::info('social.wa.media.fetch: error inesperado', ['error' => $e->getMessage()]);

            return null;
        }
    }

    private function detectMimeFromPath(string $path): ?string
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            return null;
        }
        $mime = finfo_file($finfo, $path);
        finfo_close($finfo);

        return is_string($mime) && $mime !== '' ? $mime : null;
    }

    private function detectMime(string $bytes): ?string
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            return null;
        }
        $mime = finfo_buffer($finfo, $bytes);
        finfo_close($finfo);

        return is_string($mime) && $mime !== '' ? $mime : null;
    }

    /** El contenido detectado debe corresponder al tipo de mensaje que declaró el webhook. */
    private function mimeMatchesType(string $mime, string $type): bool
    {
        return match ($type) {
            'image', 'sticker' => str_starts_with($mime, 'image/'),
            'video' => str_starts_with($mime, 'video/'),
            'audio' => str_starts_with($mime, 'audio/') || $mime === 'application/ogg',
            'document' => true, // los documentos admiten cualquier formato de archivo
            default => false,
        };
    }
}
