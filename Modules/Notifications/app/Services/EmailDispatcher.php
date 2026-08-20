<?php

declare(strict_types=1);

namespace Modules\Notifications\Services;

use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Crm\Models\Contact;
use Modules\Crm\Models\Lead;
use Modules\Crm\Services\EventService;
use Modules\Notifications\Models\EmailMessage;
use Modules\Notifications\Models\EmailSender;
use Modules\Notifications\Support\EmailHtmlSanitizer;
use Modules\Notifications\Support\InlineImageEmbedder;
use Modules\Notifications\Support\TagResolver;
use Throwable;

/**
 * Envia un correo a una persona (lead/contacto) por el SMTP del remitente ELEGIDO y
 * lo registra en el historial (email_messages) con: remitente usado, asunto, cuerpo,
 * a quien, quien del equipo lo envio y resultado. Deja rastro en el timeline.
 *
 * El cuerpo se captura como texto plano y se envia como HTML seguro (escapado +
 * saltos de linea), para no inyectar HTML del usuario.
 */
class EmailDispatcher
{
    public function __construct(
        private readonly SenderMailer $mailer,
        private readonly EventService $events,
        private readonly EmailHtmlSanitizer $sanitizer,
        private readonly TagResolver $tags,
        private readonly InlineImageEmbedder $embedder,
    ) {}

    /**
     * @param  array<int, array{path: string, name: string, mime: string, size: int}>  $attachments
     * @param  array<string, array{path: string, mime: string, size: int}>  $inlineImages  cid => imagen
     */
    public function send(EmailSender $sender, Contact $contact, ?int $leadId, ?User $sentBy, string $subject, string $bodyHtml, array $attachments = [], array $inlineImages = []): EmailMessage
    {
        $to = (string) $contact->email;

        // Etiquetas dinámicas: se reemplazan por el dato REAL del destinatario (con
        // fallback si falta). En el asunto van en claro; en el cuerpo, ESCAPADAS.
        $lead = $leadId !== null ? Lead::query()->with('program')->find($leadId) : null;
        $map = $this->tags->map($contact, $lead);
        $subject = $this->tags->resolveText($subject, $map);

        // El cuerpo se SANITIZA en el servidor antes de guardar y enviar (HTML limpio),
        // luego se resuelven las etiquetas, y por último las imágenes inline se
        // reescriben a src="cid:…" (se embeben para verse DENTRO del correo).
        $cleanBody = $this->tags->resolveHtml($this->sanitizer->sanitize($bodyHtml), $map);
        [$cleanBody, $inline] = $this->embedder->embed($cleanBody, $inlineImages);

        // Para el transporte solo viajan path/name/mime.
        $files = array_map(
            fn (array $a): array => ['path' => $a['path'], 'name' => $a['name'], 'mime' => $a['mime']],
            $attachments,
        );

        $status = 'sent';
        $error = null;

        try {
            $this->mailer->send($sender, $to, $subject, $cleanBody, $files, $inline);
        } catch (Throwable $e) {
            $status = 'failed';
            $error = Str::limit($e->getMessage(), 900);
        }

        $message = EmailMessage::query()->create([
            'email_sender_id' => $sender->getKey(),
            'contact_id' => $contact->getKey(),
            'lead_id' => $leadId,
            'sent_by_user_id' => $sentBy?->getKey(),
            'from_address' => $sender->from_address,
            'from_name' => $sender->name,
            'to_address' => $to,
            'subject' => $subject,
            'body' => $cleanBody,
            'status' => $status,
            'error' => $error,
            'sent_at' => $status === 'sent' ? now() : null,
        ]);

        // Se PERSISTEN los archivos (disco privado) para poder abrir el correo tal
        // como se envió (cuerpo con formato + imágenes) y descargar los adjuntos.
        foreach ($attachments as $a) {
            $message->attachments()->create([
                'disposition' => 'attachment',
                'filename' => $a['name'],
                'mime' => $a['mime'],
                'size' => $a['size'],
                'path' => $this->storeFile($message, $a['path'], $a['name']),
            ]);
        }

        foreach ($inline as $img) {
            $cid = $img['cid'];
            $size = $inlineImages[$cid]['size'] ?? (is_file($img['path']) ? (int) filesize($img['path']) : 0);
            $message->attachments()->create([
                'disposition' => 'inline',
                'content_id' => $cid,
                'filename' => 'imagen',
                'mime' => $img['mime'],
                'size' => $size,
                'path' => $this->storeFile($message, $img['path'], 'imagen-'.$cid),
            ]);
        }

        // Rastro en el timeline solo cuando salio; el intento fallido queda en el
        // historial de correos con su estado.
        if ($status === 'sent') {
            $this->events->record('email_sent', [
                'contact_id' => $contact->getKey(),
                'bot_id' => null,
                'data' => ['subject' => $subject, 'from' => $sender->from_address, 'to' => $to],
            ]);
        }

        return $message;
    }

    /**
     * Guarda un archivo temporal en el disco privado y devuelve su ruta (o null si
     * el temporal ya no existe). Nombre saneado + prefijo aleatorio.
     */
    private function storeFile(EmailMessage $message, string $tempPath, string $filename): ?string
    {
        if (! is_file($tempPath)) {
            return null;
        }

        $safe = Str::of($filename)->basename()->replaceMatches('/[^\w.\- ]+/u', '_')->limit(80, '')->trim();
        $safe = $safe->isEmpty() ? 'archivo' : (string) $safe;
        $path = 'email-files/'.$message->institution_id.'/'.$message->getKey().'/'.Str::random(20).'_'.$safe;

        Storage::disk('local')->put($path, (string) file_get_contents($tempPath));

        return $path;
    }
}
