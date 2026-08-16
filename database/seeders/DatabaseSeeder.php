<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Institutions\Models\Bot;
use Modules\Institutions\Models\Institution;

/**
 * Seed minimo del Bloque 0: institucion MCA School + bot de Microcredenciales
 * (asesora Celia) + usuario Admin. Sin datos de negocio (contactos, catalogo,
 * arbol): eso pertenece a bloques posteriores.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Institucion raiz (no usa scope de tenant).
        $mca = Institution::query()->firstOrCreate(
            ['slug' => 'mca-school'],
            [
                'name' => 'MCA School',
                'status' => 'active',
                'timezone' => 'America/New_York',
                'default_language' => 'es',
            ]
        );

        // Todo lo demas se crea con el contexto de MCA activo, para que el
        // sellado automatico de institution_id funcione.
        app(CurrentInstitution::class)->runFor($mca->id, function () use ($mca): void {
            Bot::query()->firstOrCreate(
                ['institution_id' => $mca->id, 'slug' => 'microcredenciales'],
                [
                    'name' => 'Bot Microcredenciales',
                    'assistant_name' => 'Celia',
                    'landing_url' => 'https://mcaschool.us/microcredenciales',
                    'public_key' => Str::random(32),
                    'allowed_origins' => ['https://mcaschool.us'],
                    'default_language' => 'es',
                    'status' => 'active',
                ]
            );

            User::query()->firstOrCreate(
                ['email' => 'admin@mcaschool.us'],
                [
                    'institution_id' => $mca->id,
                    'name' => 'Administrador MCA',
                    'password' => Hash::make('password'),
                    'role' => 'admin',
                    'is_super_admin' => true,
                    'preferred_language' => 'es',
                    'status' => 'active',
                ]
            );
        });

        // Arbol de navegacion guiada BASE (placeholder, administrable).
        $this->call(\Modules\Chat\Database\Seeders\ChatTreeSeeder::class);
    }
}
