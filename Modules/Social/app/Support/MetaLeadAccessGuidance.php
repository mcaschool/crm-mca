<?php

declare(strict_types=1);

namespace Modules\Social\Support;

/**
 * Traduce el resultado de «Comprobar acceso» a QUIÉN debe actuar y QUÉ hacer:
 *
 *  - company:  acceso que la EMPRESA concede a su Página (tareas de la persona que conecta, acceso
 *              a clientes potenciales del negocio) o una conexión que hay que renovar. Lo resuelve
 *              el administrador de la empresa desde su cuenta de Meta y este panel.
 *  - platform: permiso que le falta a la APLICACIÓN del CRM ante Meta (p. ej. pages_manage_ads,
 *              leads_retrieval). No depende de cada empresa ni lo concede el código: lo gestiona
 *              centralmente el operador de la plataforma (configuración de inicio de sesión de la
 *              app y, si Meta lo exige, su revisión). Al cliente solo se le informa.
 */
final class MetaLeadAccessGuidance
{
    /** Permisos de Meta que usa la función (listar formularios y leer sus contactos). */
    public const REQUIRED_PERMISSIONS = ['pages_show_list', 'pages_read_engagement', 'pages_manage_ads', 'leads_retrieval'];

    /** @return list<string> permisos de Meta citados en un mensaje de error */
    public static function permissionsIn(string $message): array
    {
        preg_match_all('/\b(pages_[a-z_]+|leads_retrieval|ads_management|ads_read|business_management)\b/', strtolower($message), $m);

        return array_values(array_unique($m[1]));
    }

    public static function who(string $area): string
    {
        return in_array($area, ['page', 'token', 'business'], true) ? 'company' : 'platform';
    }

    /**
     * Problemas detectados en una comprobación, sin credenciales ni datos personales.
     *
     * @param  array{steps: array<int, array<string, mixed>>, verdict: array<string, array{status: string, detail: string}>}  $result
     * @return list<array{area: string, who: string, permissions: list<string>, http_status: int|null, code: int|null, meta_message: string}>
     */
    public static function issues(array $result): array
    {
        $issues = [];
        foreach ($result['steps'] as $step) {
            if ($step['ok'] !== false || ! in_array($step['step'], ['page', 'lead_access', 'forms', 'form_leads', 'test_lead_create', 'test_lead_read'], true)) {
                continue;
            }
            $area = (string) ($step['area'] ?? 'other');
            if ($step['step'] === 'lead_access' && in_array($area, ['request', 'other', 'network'], true)) {
                continue; // consulta auxiliar no concluyente: no es un problema que resolver
            }
            $message = (string) ($step['response']['error']['message'] ?? $step['response']['network_error'] ?? '');
            $key = $area.'|'.implode(',', self::permissionsIn($message));
            $issues[$key] = [
                'area' => $area,
                'who' => self::who($area),
                'permissions' => self::permissionsIn($message),
                'http_status' => isset($step['http_status']) ? (int) $step['http_status'] : null,
                'code' => isset($step['response']['error']['code']) ? (int) $step['response']['error']['code'] : null,
                'meta_message' => mb_substr($message, 0, 240),
            ];
        }

        return array_values($issues);
    }

    /**
     * Texto para el administrador de la EMPRESA.
     *
     * @param  array{area: string, who: string, permissions: list<string>}  $issue
     */
    public static function forCompany(array $issue): string
    {
        return match ($issue['area']) {
            'token' => __('La conexión con Meta caducó o fue revocada. Pulsa «Reconectar Meta» y vuelve a autorizar.'),
            'page' => __('Tu empresa debe dar acceso a esta Página: la persona que conecta Meta necesita control total de la Página (o la tarea de anuncios) y debe seleccionarla al autorizar. Después pulsa «Reconectar Meta».'),
            'business' => __('Tu empresa debe permitir el acceso a los clientes potenciales: en Meta Business Suite → Configuración del negocio → Integraciones → Acceso a clientes potenciales, da acceso a la aplicación del CRM o a la persona que conectó Meta. Después pulsa «Comprobar acceso».'),
            'app' => __('Pendiente de la plataforma del CRM: la aplicación aún no dispone de un permiso de Meta necesario. No requiere ninguna acción de tu empresa; podrás activar la recepción cuando esté disponible.'),
            default => __('Meta no respondió como se esperaba. Vuelve a comprobar el acceso en unos minutos.'),
        };
    }

    /**
     * Texto para el OPERADOR de la plataforma (gestión central ante Meta).
     *
     * @param  array{area: string, who: string, permissions: list<string>}  $issue
     */
    public static function forOperator(array $issue): string
    {
        $perms = $issue['permissions'] !== [] ? implode(', ', $issue['permissions']) : implode(', ', self::REQUIRED_PERMISSIONS);

        return match ($issue['area']) {
            'app' => __('Gestión central: la aplicación de Meta del CRM no obtiene «:perms». Inclúyelo en la configuración de Facebook Login for Business de la plataforma y, para empresas que no son propietarias de la aplicación, solicita su acceso avanzado en la revisión de la aplicación. El código no puede conceder permisos de Meta. Cuando esté concedido, cada empresa solo tiene que pulsar «Reconectar Meta».', ['perms' => $perms]),
            'request', 'other', 'network' => __('Error técnico en la consulta a Meta: revisa el registro de la aplicación (social.lead_forms).'),
            default => self::forCompany($issue),
        };
    }
}
