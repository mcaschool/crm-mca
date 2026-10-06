<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Purga de retencion (D5): elimina mensajes mas antiguos que el umbral (24 meses por
// defecto). Se ejecuta el dia 1 de cada mes de madrugada. En Hostinger compartido
// basta con un unico cron que llame a `artisan schedule:run` cada minuto (ver el
// checklist de despliegue); si el plan no permite cron por minuto, se puede llamar
// directamente a `crm:purge-retention` desde el panel de cron (linea alternativa
// documentada en docs/DEPLOYMENT-SECURITY-CHECKLIST.md).
Schedule::command('crm:purge-retention')
    ->monthlyOn(1, '03:30')
    ->withoutOverlapping()
    ->runInBackground();

// Respuestas del asesor en Instagram/Messenger/WhatsApp (cola persistente «social-advisor»):
// cada minuto procesa lo pendiente durante ~50 s y termina. Sin procesos permanentes. Deja un
// latido; el panel no permite activar el asesor en un canal hasta ver ese latido.
Schedule::command('social:advisor-worker')
    ->everyMinute()
    ->withoutOverlapping(5)
    ->runInBackground();
