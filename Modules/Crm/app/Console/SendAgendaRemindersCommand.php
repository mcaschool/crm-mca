<?php

declare(strict_types=1);

namespace Modules\Crm\Console;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Crm\Models\ActivityGuest;
use Modules\Crm\Models\ActivityReminder;
use Modules\Crm\Models\Booking;
use Modules\Crm\Notifications\AgendaNotification;
use Modules\Crm\Services\Agenda\AgendaMailer;
use Modules\Crm\Services\Booking\BookingService;
use Throwable;

/**
 * Envía los recordatorios vencidos de la agenda (un único comando, cada minuto, sin
 * solapes). Idempotente: cada fila se RECLAMA de forma atómica (pending → sending) antes
 * de enviar, así dos ejecuciones nunca mandan el mismo recordatorio. No envía nada si la
 * actividad ya no está programada o el invitado fue retirado. Un fallo deja la fila para
 * reintento hasta MAX_ATTEMPTS y después «failed»; el error técnico queda solo en la fila.
 */
class SendAgendaRemindersCommand extends Command
{
    protected $signature = 'crm:agenda-reminders {--limit=200}';

    protected $description = 'Envía los recordatorios vencidos de la agenda (correo e interno).';

    public function handle(CurrentInstitution $context, AgendaMailer $mailer): int
    {
        // Filas que se quedaron «sending» (proceso caído) vuelven a la cola tras 10 minutos.
        $context->runGlobally(fn () => ActivityReminder::query()
            ->where('status', 'sending')
            ->where('updated_at', '<', now()->subMinutes(10))
            ->update(['status' => 'pending']));

        /** @var array<int,array{id:int,institution_id:int}> $due */
        $due = $context->runGlobally(fn () => ActivityReminder::query()
            ->where('status', 'pending')
            ->where('due_at', '<=', now())
            ->orderBy('due_at')
            ->limit(max(1, (int) $this->option('limit')))
            ->get(['id', 'institution_id'])
            ->map(fn (ActivityReminder $r) => ['id' => (int) $r->id, 'institution_id' => (int) $r->institution_id])
            ->all());

        $sent = 0;
        $failed = 0;
        $skipped = 0;
        foreach ($due as $row) {
            $result = $context->runFor($row['institution_id'], fn () => $this->process($row['id'], $mailer));
            match ($result) {
                'sent' => $sent++,
                'failed', 'retry' => $failed++,
                default => $skipped++,
            };
        }

        $this->info("Recordatorios: {$sent} enviados · {$failed} fallidos/reintento · {$skipped} omitidos.");

        // Reservas públicas cuyos correos (confirmación o aviso al asesor) fallaron: reintento seguro
        // (cada envío se reclama de forma atómica; nunca crea otra reserva ni duplica correos).
        /** @var array<int,array{id:int,institution_id:int}> $pending */
        $pending = $context->runGlobally(fn () => Booking::query()
            ->where(fn ($q) => $q->whereNull('confirmation_sent_at')->orWhereNull('advisor_mailed_at'))
            ->where('mail_attempts', '<', Booking::MAX_MAIL_ATTEMPTS)
            ->where('created_at', '<=', now()->subMinute())
            ->where('created_at', '>=', now()->subDays(2))
            ->whereHas('activity', fn ($q) => $q->where('status', 'scheduled'))
            ->limit(50)
            ->get(['id', 'institution_id'])
            ->map(fn (Booking $b) => ['id' => (int) $b->id, 'institution_id' => (int) $b->institution_id])
            ->all());
        foreach ($pending as $row) {
            $context->runFor($row['institution_id'], function () use ($row): void {
                $booking = Booking::query()->find($row['id']);
                if ($booking !== null) {
                    app(BookingService::class)->sendPostBookingMails($booking);
                }
            });
        }
        if ($pending !== []) {
            $this->info('Reservas con correos reintentados: '.count($pending).'.');
        }

        return self::SUCCESS;
    }

    private function process(int $id, AgendaMailer $mailer): string
    {
        // Reclamo atómico: solo una ejecución pasa de pending a sending.
        $claimed = ActivityReminder::query()->whereKey($id)->where('status', 'pending')
            ->update(['status' => 'sending', 'attempts' => DB::raw('attempts + 1'), 'updated_at' => now()]);
        if ($claimed !== 1) {
            return 'skipped';
        }

        /** @var ActivityReminder $reminder */
        $reminder = ActivityReminder::query()->with('activity')->findOrFail($id);
        $activity = $reminder->activity;
        if ($activity === null || ! $activity->isScheduled() || $activity->starts_at->lte(now())) {
            $reminder->forceFill(['status' => 'skipped'])->save();

            return 'skipped';
        }

        try {
            $ok = false;
            if ($reminder->recipient_type === 'advisor') {
                $advisor = User::query()->find($reminder->recipient_id);
                if ($advisor === null || $advisor->id !== $activity->assigned_to_user_id) {
                    $reminder->forceFill(['status' => 'skipped'])->save();

                    return 'skipped';
                }
                if ($reminder->channel === 'internal') {
                    $advisor->notify(AgendaNotification::for('reminder', $activity));
                    $ok = true;
                } else {
                    $ok = $mailer->toAdvisor($activity, $advisor, 'reminder');
                }
            } else {
                $guest = ActivityGuest::query()->find($reminder->recipient_id);
                if ($guest === null || ! $guest->isActive() || $guest->activity_id !== $activity->id) {
                    $reminder->forceFill(['status' => 'skipped'])->save();

                    return 'skipped';
                }
                $ok = $mailer->toGuest($activity, $guest, 'reminder');
            }
            $error = $ok ? null : ($mailer->lastError() ?? 'Envío fallido');
        } catch (Throwable $e) {
            $ok = false;
            $error = Str::limit(class_basename($e).': '.$e->getMessage(), 240);
        }

        if ($ok) {
            $reminder->forceFill(['status' => 'sent', 'sent_at' => now(), 'last_error' => null])->save();

            return 'sent';
        }

        $final = $reminder->attempts >= ActivityReminder::MAX_ATTEMPTS;
        $reminder->forceFill(['status' => $final ? 'failed' : 'pending', 'last_error' => $error])->save();

        return $final ? 'failed' : 'retry';
    }
}
