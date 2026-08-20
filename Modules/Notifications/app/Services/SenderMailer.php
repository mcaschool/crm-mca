<?php

declare(strict_types=1);

namespace Modules\Notifications\Services;

use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Modules\Notifications\Mail\OutboundEmail;
use Modules\Notifications\Models\EmailSender;
use Throwable;

/**
 * Envia correo usando el SMTP PROPIO de un remitente. Registra un mailer en runtime
 * a partir de sus credenciales (mismo enfoque que el probador de conexion) y manda
 * un OutboundEmail con el "De" del remitente elegido.
 *
 * Las credenciales se leen del punto de acceso seguro del modelo (secret()); nunca
 * se registran en logs.
 */
class SenderMailer
{
    /**
     * Envia un correo por el SMTP del remitente. Puede lanzar excepcion si el SMTP
     * falla (autenticacion/conexion): el llamador decide como manejarlo.
     */
    public function send(EmailSender $sender, string $to, string $subject, string $bodyHtml): void
    {
        Mail::mailer($this->configureMailer($sender))
            ->to($to)
            ->send(new OutboundEmail($sender->from_address, $sender->name, $subject, $bodyHtml));
    }

    /**
     * "Probar envio": manda un correo de prueba a `$to` y sella el resultado del
     * test en el remitente (last_tested_at/ok/message). Devuelve true si salio.
     */
    public function sendTest(EmailSender $sender, string $to): bool
    {
        try {
            $this->send(
                $sender,
                $to,
                'Prueba de envío — '.$sender->name,
                '<p>Este es un correo de prueba de <strong>'.e($sender->name).'</strong> '
                .'('.e($sender->from_address).').</p>'
                .'<p>Si lo recibes, el remitente está configurado correctamente.</p>',
            );

            $sender->forceFill([
                'last_tested_at' => now(),
                'last_test_ok' => true,
                'last_test_message' => 'Correo de prueba enviado a '.$to.'.',
            ])->save();

            return true;
        } catch (Throwable $e) {
            $sender->forceFill([
                'last_tested_at' => now(),
                'last_test_ok' => false,
                // Mensaje del error SMTP, acotado. Nunca incluye la contraseña.
                'last_test_message' => Str::limit($e->getMessage(), 450),
            ])->save();

            return false;
        }
    }

    /**
     * Registra (en memoria, para esta petición) un mailer SMTP con las credenciales
     * del remitente y devuelve su clave. No persiste nada en disco ni en logs.
     */
    private function configureMailer(EmailSender $sender): string
    {
        $key = 'email_sender_'.$sender->getKey();

        config(['mail.mailers.'.$key => [
            'transport' => 'smtp',
            // Laravel 13 usa `scheme`: 'smtps' = TLS implicito (SSL, puerto 465 de
            // Hostinger); 'smtp' = 465 sin SSL o STARTTLS en 587. Se deriva del
            // cifrado elegido en el remitente.
            'scheme' => $this->scheme($sender),
            'host' => (string) $sender->secret('host'),
            'port' => (int) $sender->secret('port'),
            'username' => (string) $sender->secret('username'),
            'password' => (string) $sender->secret('password'),
            'timeout' => 15,
        ]]);

        return $key;
    }

    private function scheme(EmailSender $sender): string
    {
        return strtolower((string) $sender->secret('encryption')) === 'ssl' ? 'smtps' : 'smtp';
    }
}
