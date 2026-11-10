<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Modules\Core\Scheduling\SchedulerTick;
use Modules\Crm\Support\AgendaPendingWork;
use Modules\Social\Support\AdvisorDispatcher;
use Modules\Social\Support\AdvisorQueuePlan;
use Modules\Social\Support\SocialPendingWork;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Planificador — un cron, un proceso por minuto, trabajo pesado solo bajo demanda
|--------------------------------------------------------------------------
| Hostinger compartido: UNA entrada de cron (`* * * * * php artisan schedule:run`), sin daemons.
|
| 1) Lo ligero y acotado (solo BD) corre DENTRO del proceso de schedule:run (SchedulerTick), en
|    secuencia, sin lanzar procesos PHP nuevos: latido del planificador, reconciliación de
|    salientes y los pasos de FollowUp (cada uno respeta su interruptor).
| 2) Lo que puede ser largo o depende de servicios externos sigue en un proceso propio, pero el
|    planificador comprueba ANTES (`when`, 1–3 consultas) si hay trabajo: si no lo hay, no arranca
|    ningún proceso. La cadencia de comprobación es la de siempre; la latencia funcional no cambia.
| 3) Antisolape: mutex por evento (withoutOverlapping, caché) + reclamos atómicos a nivel de datos
|    en cada comando. `runInBackground` solo donde la tarea puede durar decenas de segundos.
*/

// --- Cada minuto, en proceso: latido + reconciliación + FollowUp (dispatch si está habilitado) ---
Schedule::call(SchedulerTick::class.'@minute')
    ->everyMinute()
    ->name('crm-tick-minute')
    ->description('CRM: latido del planificador, salientes sin confirmar y encolado de seguimientos')
    ->withoutOverlapping(2);

// --- Cada cinco minutos, en proceso: detección (si está habilitada), mantenimiento y reconciliación
//     comercial de seguimientos. Misma cadencia que antes. ---
Schedule::call(SchedulerTick::class.'@fiveMinutes')
    ->everyFiveMinutes()
    ->name('crm-tick-5min')
    ->description('CRM: detección, mantenimiento y reconciliación comercial de seguimientos')
    ->withoutOverlapping(4);

// --- Respuestas del asesor (cola «social-advisor»): proceso propio SOLO si hay trabajos ejecutables
//     en el próximo minuto (listos, retrasados que vencen en la ventana o reservados que caducan).
//     El comando vuelve a radiografiar la cola y escucha justo lo necesario. ---
Schedule::command('social:advisor-worker')
    ->everyMinute()
    ->when(fn (): bool => AdvisorDispatcher::queueIsPersistent() && AdvisorQueuePlan::inspect()->needsWorker())
    ->withoutOverlapping(5)
    ->runInBackground();

// --- Publicador: proceso propio SOLO si hay publicaciones vencidas, interrumpidas o videos de
//     Instagram por reanudar. Cada publicación se reclama de forma atómica (run_id). ---
Schedule::command('social:publish-scheduled')
    ->everyMinute()
    ->when(fn (): bool => SocialPendingWork::publishing())
    ->withoutOverlapping(15)
    ->runInBackground();

// --- Agenda: proceso propio SOLO si hay recordatorios vencidos (24 h / 1 h / 15 min), envíos
//     atascados o correos de reservas por reintentar. Idempotente (reclamo atómico por recordatorio). ---
Schedule::command('crm:agenda-reminders')
    ->everyMinute()
    ->when(fn (): bool => AgendaPendingWork::reminders())
    ->withoutOverlapping(5);

// --- Formularios publicitarios de Meta: SOLO si alguna empresa tiene la recepción activa. ---
Schedule::command('social:meta-leads-poll')
    ->everyFiveMinutes()
    ->when(fn (): bool => SocialPendingWork::metaLeadForms())
    ->withoutOverlapping(10)
    ->runInBackground();

// --- Perfiles de Messenger/Instagram incompletos: SOLO si hay candidatos; como mucho 25 consultas
//     a Meta por hora, con espera entre intentos. ---
Schedule::command('social:refresh-contact-profiles --limit=25')
    ->hourly()
    ->when(fn (): bool => SocialPendingWork::contactProfiles())
    ->withoutOverlapping(30)
    ->runInBackground();

// --- Estado de la conexión con Meta de cada empresa (caducidad/revocación), para avisar en el panel. ---
Schedule::command('social:meta-connections-check')
    ->dailyAt('04:10')
    ->withoutOverlapping();

// --- Purga de retención (D5): mensajes más antiguos que el umbral (24 meses por defecto), el día 1
//     de cada mes de madrugada. Línea alternativa de cron directo en docs/DEPLOYMENT-SECURITY-CHECKLIST.md. ---
Schedule::command('crm:purge-retention')
    ->monthlyOn(1, '03:30')
    ->withoutOverlapping()
    ->runInBackground();
