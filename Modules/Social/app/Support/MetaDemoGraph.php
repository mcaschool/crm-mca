<?php

declare(strict_types=1);

namespace Modules\Social\Support;

use Illuminate\Support\Facades\Http;

/**
 * Atajo SOLO-LOCAL (APP_ENV=local + SOCIAL_META_FAKE_GRAPH=demo) para recorrer el asistente de
 * Formularios publicitarios sin Meta real: simula las respuestas de Graph para la Página de
 * demostración del modo SOCIAL_META_FAKE_DISCOVERY. Solo intercepta graph.facebook.com; el resto de
 * llamadas salen normalmente. En producción no se registra nunca (SocialServiceProvider lo impide).
 */
final class MetaDemoGraph
{
    public static function register(): void
    {
        Http::fake([
            'graph.facebook.com/*/debug_token*' => Http::response(['data' => [
                'is_valid' => true, 'type' => 'USER', 'user_id' => 'DEMO_USER_1',
                'scopes' => MetaLeadAccessGuidance::REQUIRED_PERMISSIONS,
                'expires_at' => now()->addDays(60)->getTimestamp(), 'data_access_expires_at' => now()->addDays(90)->getTimestamp(),
            ]]),
            'graph.facebook.com/*/leadgen_forms*' => Http::response(['data' => [
                ['id' => 'DEMO_FORM_1', 'name' => 'Solicitud de información · Otoño', 'status' => 'ACTIVE'],
                ['id' => 'DEMO_FORM_2', 'name' => 'Inscripción al webinar gratuito', 'status' => 'ACTIVE'],
            ]]),
            'graph.facebook.com/*/test_leads*' => Http::response(['id' => 'DEMO_TEST_1']),
            'graph.facebook.com/*/DEMO_TEST_1*' => Http::response(['platform' => 'fb', 'field_data' => [
                ['name' => 'full_name', 'values' => ['Test Lead Dummy']], ['name' => 'email', 'values' => ['test@fb.com']],
            ]]),
            'graph.facebook.com/*/DEMO_FORM_1/leads*' => Http::response(['data' => [['id' => 'DEMO_LEAD_1'], ['id' => 'DEMO_LEAD_2']]]),
            'graph.facebook.com/*/DEMO_FORM_2/leads*' => Http::response(['data' => []]),
            'graph.facebook.com/*/DEMO_LEAD_1*' => Http::response(['platform' => 'ig', 'campaign_name' => 'Otoño · Instagram', 'field_data' => [
                ['name' => 'full_name', 'values' => ['Contacto Demo Teléfono']],
                ['name' => 'phone_number', 'values' => ['+34 600 000 001']],
            ]]),
            'graph.facebook.com/*/DEMO_LEAD_2*' => Http::response(['platform' => 'fb', 'campaign_name' => 'Otoño · Facebook', 'field_data' => [
                ['name' => 'full_name', 'values' => ['Contacto Demo Correo']],
                ['name' => 'email', 'values' => ['contacto.demo@example.test']],
            ]]),
            'graph.facebook.com/*has_lead_access*' => Http::response(['has_lead_access' => ['app_has_leads_permission' => true, 'user_has_leads_permission' => true, 'can_access_lead' => true]]),
            'graph.facebook.com/*/FAKE_PAGE_*' => Http::response(['id' => 'FAKE_PAGE_1', 'name' => 'Página de demostración']),
        ]);
    }
}
