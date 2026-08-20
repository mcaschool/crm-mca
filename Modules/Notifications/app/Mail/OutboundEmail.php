<?php

declare(strict_types=1);

namespace Modules\Notifications\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Symfony\Component\Mime\Email;

/**
 * Correo saliente generico: el "De" es el remitente ELEGIDO (no el global). El
 * transporte SMTP concreto lo fija SenderMailer segun el remitente; aqui viajan
 * direccion/nombre de origen, asunto, cuerpo (HTML ya sanitizado) y los adjuntos.
 * No contiene secretos.
 *
 * @param  array<int, array{path: string, name: string, mime: string}>  $files
 * @param  array<int, array{path: string, cid: string, mime: string}>  $inline
 */
class OutboundEmail extends Mailable
{
    use Queueable;
    use SerializesModels;

    /**
     * @param  array<int, array{path: string, name: string, mime: string}>  $files
     * @param  array<int, array{path: string, cid: string, mime: string}>  $inline  imágenes embebidas por CID
     */
    public function __construct(
        public string $fromAddress,
        public string $fromName,
        public string $subjectLine,
        public string $bodyHtml,
        public array $files = [],
        public array $inline = [],
    ) {
        // Imágenes inline: se embeben en el mensaje con Content-ID = cid, para que el
        // cuerpo (que referencia src="cid:…") las muestre DENTRO del correo.
        if ($this->inline !== []) {
            $this->withSymfonyMessage(function (Email $message): void {
                foreach ($this->inline as $img) {
                    $message->embedFromPath($img['path'], $img['cid'], $img['mime']);
                }
            });
        }
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address($this->fromAddress, $this->fromName),
            subject: $this->subjectLine,
        );
    }

    public function content(): Content
    {
        return new Content(htmlString: $this->bodyHtml);
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        return array_map(
            fn (array $f): Attachment => Attachment::fromPath($f['path'])->as($f['name'])->withMime($f['mime']),
            $this->files,
        );
    }
}
