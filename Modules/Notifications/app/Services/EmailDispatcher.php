<?php

declare(strict_types=1);

namespace Modules\Notifications\Services;

use App\Models\User;
use Illuminate\Support\Str;
use Modules\Crm\Models\Contact;
use Modules\Crm\Services\EventService;
use Modules\Notifications\Models\EmailMessage;
use Modules\Notifications\Models\EmailSender;
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
    ) {}

    public function send(EmailSender $sender, Contact $contact, ?int $leadId, ?User $sentBy, string $subject, string $body): EmailMessage
    {
        $to = (string) $contact->email;

        $status = 'sent';
        $error = null;

        try {
            $this->mailer->send($sender, $to, $subject, nl2br(e($body)));
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
            'body' => $body,
            'status' => $status,
            'error' => $error,
            'sent_at' => $status === 'sent' ? now() : null,
        ]);

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
}
