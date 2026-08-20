<?php

declare(strict_types=1);

namespace Modules\Notifications\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Correo saliente generico: el "De" es el remitente ELEGIDO (no el global). El
 * transporte SMTP concreto lo fija SenderMailer segun el remitente; aqui solo
 * viajan direccion/nombre de origen, asunto y cuerpo (HTML). No contiene secretos.
 */
class OutboundEmail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public string $fromAddress,
        public string $fromName,
        public string $subjectLine,
        public string $bodyHtml,
    ) {}

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
}
