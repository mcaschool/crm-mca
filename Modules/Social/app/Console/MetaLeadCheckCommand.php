<?php

declare(strict_types=1);

namespace Modules\Social\Console;

use Illuminate\Console\Command;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Social\Models\SocialChannel;
use Modules\Social\Services\MetaLeadAccessCheck;

/**
 * Prueba CONTROLADA de los formularios publicitarios con la conexión actual de la Página:
 * operación realizada + respuesta exacta de Meta (sin credenciales ni datos personales) y
 * veredicto: Página / acceso del negocio a los leads / permiso de la aplicación.
 * Por defecto solo LEE. --test-lead crea un lead de PRUEBA de Meta en el formulario, lo lee y lo
 * borra (salvo --keep-test-lead). No usa datos de prospectos reales ni cambia nada en Meta.
 */
class MetaLeadCheckCommand extends Command
{
    protected $signature = 'social:meta-lead-check
        {--channel= : Id del canal de Messenger (Página); por defecto el primero}
        {--form= : Id del formulario de Meta; por defecto el primero de la Página}
        {--test-lead : Crear, leer y borrar un lead de PRUEBA de Meta}
        {--keep-test-lead : No borrar el lead de prueba al terminar}';

    protected $description = 'Comprueba (solo lectura) si la conexión actual de la Página puede listar formularios y leer leads.';

    public function handle(MetaLeadAccessCheck $check, CurrentInstitution $tenancy): int
    {
        $page = $tenancy->runGlobally(fn (): ?SocialChannel => SocialChannel::query()
            ->where('provider', 'messenger')
            ->when($this->option('channel'), fn ($q, $id) => $q->whereKey((int) $id))
            ->orderBy('id')
            ->first());

        if ($page === null) {
            $this->error('No hay ninguna Página de Facebook conectada.');

            return self::FAILURE;
        }

        $result = $tenancy->runFor((int) $page->institution_id, fn (): array => $check->run(
            $page,
            $this->option('form') ? (string) $this->option('form') : null,
            (bool) $this->option('test-lead'),
            (bool) $this->option('keep-test-lead'),
        ));

        $this->line('Página: '.$page->display_name.' (canal '.$page->id.')');
        foreach ($result['steps'] as $step) {
            $this->newLine();
            $this->line(($step['ok'] ? '✔ ' : '✖ ').$step['operation'].'  → HTTP '.($step['http_status'] ?? '—').($step['area'] ? '  ['.$step['area'].']' : ''));
            $this->line((string) json_encode($step['response'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        $this->newLine();
        $labels = ['page' => 'Autorización de la Página', 'business' => 'Acceso del negocio a los leads', 'app' => 'Permiso de la aplicación'];
        foreach ($result['verdict'] as $area => $v) {
            $this->line(sprintf('%-32s %-8s %s', $labels[$area], strtoupper($v['status']), $v['detail']));
        }

        return self::SUCCESS;
    }
}
