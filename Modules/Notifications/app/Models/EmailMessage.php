<?php

declare(strict_types=1);

namespace Modules\Notifications\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Tenancy\Concerns\BelongsToInstitution;
use Modules\Notifications\Database\Factories\EmailMessageFactory;

/**
 * Correo saliente enviado a una persona (lead/contacto). Historial de trazabilidad:
 * remitente usado, asunto, cuerpo, a quien, quien del equipo lo envio y resultado.
 * Se ESCRIBE en el Paso 3; aqui esta el modelo listo. No contiene secretos.
 *
 * @property int $institution_id
 * @property int|null $email_sender_id
 * @property int|null $contact_id
 * @property int|null $lead_id
 * @property int|null $sent_by_user_id
 * @property string $from_address
 * @property string|null $from_name
 * @property string $to_address
 * @property string $subject
 * @property string $body
 * @property string $status
 * @property string|null $error
 * @property \Illuminate\Support\Carbon|null $sent_at
 */
class EmailMessage extends Model
{
    use BelongsToInstitution;

    /** @use HasFactory<EmailMessageFactory> */
    use HasFactory;

    protected $fillable = [
        'institution_id',
        'email_sender_id',
        'contact_id',
        'lead_id',
        'sent_by_user_id',
        'from_address',
        'from_name',
        'to_address',
        'subject',
        'body',
        'status',
        'error',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<EmailSender, $this>
     */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(EmailSender::class, 'email_sender_id');
    }

    /**
     * Quién del equipo lo envió.
     *
     * @return BelongsTo<\App\Models\User, $this>
     */
    public function sentByUser(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'sent_by_user_id');
    }

    protected static function newFactory(): EmailMessageFactory
    {
        return EmailMessageFactory::new();
    }
}
