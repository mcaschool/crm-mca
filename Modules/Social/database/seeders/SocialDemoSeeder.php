<?php

declare(strict_types=1);

namespace Modules\Social\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Institutions\Models\Institution;
use Modules\Social\Models\SocialChannel;
use Modules\Social\Models\SocialConversation;
use Modules\Social\Models\SocialMessage;

/**
 * Datos DEMO de la bandeja social (solo desarrollo). Crea 1 canal por proveedor y varias
 * conversaciones con mensajes inbound/outbound para poder ver la UI del Bloque 2.
 *
 * Idempotente: borra los canales demo de la institución antes de recrearlos (el borrado
 * del canal arrastra en cascada sus conversaciones y mensajes). NO usar en producción.
 *
 * Comando:  php artisan db:seed --class="Modules\Social\Database\Seeders\SocialDemoSeeder"
 */
class SocialDemoSeeder extends Seeder
{
    public function run(): void
    {
        $institution = Institution::query()->orderBy('id')->firstOrFail();

        app(CurrentInstitution::class)->runFor($institution->id, function () {
            // Limpieza previa (cascade elimina conversaciones + mensajes).
            SocialChannel::query()->get()->each->delete();

            $channels = [
                'whatsapp' => SocialChannel::create([
                    'provider' => 'whatsapp',
                    'display_name' => 'Admisiones MCA',
                    'external_id' => 'demo_wa_phone',
                    'credentials' => ['token' => 'demo'],
                    'is_active' => true,
                ]),
                'instagram' => SocialChannel::create([
                    'provider' => 'instagram',
                    'display_name' => '@mca.school',
                    'external_id' => 'demo_ig_user',
                    'credentials' => ['token' => 'demo'],
                    'is_active' => true,
                ]),
                'messenger' => SocialChannel::create([
                    'provider' => 'messenger',
                    'display_name' => 'MCA School',
                    'external_id' => 'demo_fb_page',
                    'credentials' => ['token' => 'demo'],
                    'is_active' => true,
                ]),
            ];

            // [provider, contacto, unread, minutos-desde-ahora, [ [dir, texto], ... ] ]
            $threads = [
                ['whatsapp', 'María González', 3, 4, [
                    ['inbound', 'Hola, buenas 👋'],
                    ['outbound', '¡Hola María! Soy de Admisiones MCA, ¿en qué te ayudo?'],
                    ['inbound', 'Quiero info de las microcredenciales'],
                    ['inbound', '¿La microcredencial tiene certificación oficial?'],
                ]],
                ['messenger', 'Lucía Martín', 5, 11, [
                    ['inbound', 'Buenas tardes'],
                    ['inbound', 'Vi el diploma en liderazgo en Facebook'],
                    ['outbound', 'Hola Lucía, con gusto te cuento los detalles.'],
                    ['inbound', 'Me interesa el diploma en liderazgo'],
                ]],
                ['instagram', 'Ana Torres', 1, 26, [
                    ['inbound', '¡Hola! Los vi en Instagram 😍'],
                    ['outbound', '¡Hola Ana! Gracias por escribirnos.'],
                    ['inbound', '¿Cuándo empieza el próximo grupo?'],
                ]],
                ['whatsapp', 'Sofía Díaz', 2, 52, [
                    ['inbound', 'Buenos días'],
                    ['outbound', 'Buenos días Sofía, ¿en qué programa estás interesada?'],
                    ['inbound', 'En la de finanzas. ¿Hay opción de pago en cuotas?'],
                ]],
                ['instagram', 'Diego Fernández', 0, 95, [
                    ['inbound', '¿Tienen algo de marketing digital?'],
                    ['outbound', 'Sí, te paso la ficha con todos los detalles 👇'],
                    ['inbound', 'Ok lo reviso, gracias'],
                ]],
                ['messenger', 'Pedro Sánchez', 0, 140, [
                    ['inbound', 'Hola, ¿la certificación es reconocida?'],
                    ['outbound', 'Así es, es una microcredencial verificable. Aquí la info.'],
                    ['inbound', 'Gracias por la info 🙏'],
                ]],
                ['whatsapp', 'Carlos Ruiz', 0, 200, [
                    ['inbound', '¿Me confirmas el precio?'],
                    ['outbound', 'Te comparto la ficha en la web con el detalle de inversión.'],
                    ['inbound', 'Perfecto, muchas gracias 🙌'],
                ]],
            ];

            foreach ($threads as $i => [$provider, $contact, $unread, $minsAgo, $msgs]) {
                $lastAt = now()->subMinutes($minsAgo);
                $baseAt = $lastAt->copy()->subMinutes(count($msgs));

                $conv = SocialConversation::create([
                    'social_channel_id' => $channels[$provider]->id,
                    'provider' => $provider,
                    'external_conversation_id' => 'demo-conv-'.($i + 1),
                    'contact_name' => $contact,
                    'contact_external_id' => 'demo-contact-'.($i + 1),
                    'status' => 'open',
                    'unread_count' => $unread,
                    'last_message_preview' => end($msgs)[1],
                    'last_message_at' => $lastAt,
                ]);

                foreach ($msgs as $j => [$dir, $text]) {
                    SocialMessage::create([
                        'social_conversation_id' => $conv->id,
                        'external_message_id' => 'demo-msg-'.($i + 1).'-'.($j + 1),
                        'direction' => $dir,
                        'type' => 'text',
                        'body' => $text,
                        'status' => $dir === 'inbound' ? 'received' : 'sent',
                        'sender_type' => $dir === 'inbound' ? 'contact' : 'agent',
                        'provider_timestamp' => $baseAt->copy()->addMinutes($j),
                    ]);
                }
            }

            $this->command->info('Bandeja social demo: 3 canales, '.count($threads).' conversaciones creadas para la institución activa (id '.app(CurrentInstitution::class)->idOrFail().').');
        });
    }
}
