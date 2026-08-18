<?php

declare(strict_types=1);

namespace Modules\Crm\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * INFRAESTRUCTURA DE PRUEBAS (no produccion). Vacia los datos de CRM generados por
 * las pruebas manuales para empezar de cero con un "prospecto nuevo":
 * contactos, leads, conversaciones, mensajes, eventos e intereses de programa.
 *
 * NO toca el catalogo, el arbol de navegacion, la base de conocimiento, las
 * integraciones/credenciales, los bots, las instituciones ni los usuarios del panel.
 * NO cambia la logica de memoria de Celia; solo limpia el rastro de datos.
 *
 * Bloqueado en produccion salvo --force explicito. Pide confirmacion mostrando
 * exactamente que borrara, salvo --force.
 */
class ResetDemoCommand extends Command
{
    protected $signature = 'crm:reset-demo {--force : No pedir confirmacion (y permitir en produccion)}';

    protected $description = 'Borra SOLO los datos de CRM de prueba (contactos, leads, conversaciones, mensajes, eventos, intereses). Conserva catalogo, arbol y conocimiento.';

    /** Tablas de datos de prueba que se vacian, en orden seguro para las FKs (hijas primero). */
    private const TABLES = ['messages', 'events', 'program_interests', 'leads', 'conversations', 'contacts'];

    /** Lo que se CONSERVA (solo informativo, para el reporte). */
    private const PRESERVED = [
        'programs', 'program_categories', 'program_tags',
        'conversation_nodes', 'conversation_options',
        'knowledge_sources', 'integrations', 'ai_process_configs',
        'bots', 'institutions', 'users',
    ];

    public function handle(): int
    {
        $force = (bool) $this->option('force');

        if (app()->environment('production') && ! $force) {
            $this->error('Bloqueado en produccion. Este comando es solo para desarrollo/pruebas.');
            $this->line('Si de verdad lo quieres en este entorno, repite con --force.');

            return self::FAILURE;
        }

        // Se informa EXACTAMENTE que se borrara (conteos por tabla) antes de tocar nada.
        $this->info('Se BORRARAN los siguientes datos de prueba (todas las instituciones):');
        $counts = [];
        $total = 0;
        foreach (self::TABLES as $table) {
            $count = DB::table($table)->count();
            $counts[$table] = $count;
            $total += $count;
            $this->line(sprintf('  - %-18s %d fila(s)', $table, $count));
        }

        $this->newLine();
        $this->info('Se CONSERVAN (no se tocan): '.implode(', ', self::PRESERVED).'.');
        $this->newLine();

        if ($total === 0) {
            $this->comment('No hay datos de prueba que borrar. Nada que hacer.');

            return self::SUCCESS;
        }

        if (! $force && ! $this->confirm("¿Borrar {$total} fila(s) de datos de prueba de CRM? Esta accion no se puede deshacer.")) {
            $this->comment('Cancelado. No se borro nada.');

            return self::SUCCESS;
        }

        // Borrado en una transaccion, hijas primero para no romper FKs.
        DB::transaction(function (): void {
            foreach (self::TABLES as $table) {
                DB::table($table)->delete();
            }
        });

        $this->info("Listo. Borradas {$total} fila(s). La base queda limpia de datos de prueba; catalogo, arbol y conocimiento intactos.");

        return self::SUCCESS;
    }
}
