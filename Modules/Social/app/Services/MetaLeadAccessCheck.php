<?php

declare(strict_types=1);

namespace Modules\Social\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Modules\Social\Models\SocialChannel;
use Throwable;

/**
 * Comprobación CONTROLADA del acceso a los formularios publicitarios con la conexión ACTUAL de la
 * Página (su token guardado, cifrado). No depende del interruptor de formularios: solo LEE de Meta
 * (salvo el lead de prueba, que únicamente se crea si se pide explícitamente y se borra al final).
 *
 * Distingue tres causas de fallo:
 *   - page:     la Página no está autorizada para esta conexión (o la conexión caducó);
 *   - business: el negocio no da acceso a los leads (Leads Access Manager / permiso del usuario);
 *   - app:      la aplicación (o el token) no tiene el permiso de leads (leads_retrieval, …).
 *
 * Cada paso devuelve la operación (sin token) y la respuesta EXACTA de Meta, saneada: nunca
 * incluye credenciales ni datos personales (los valores de los campos de un lead se enmascaran).
 */
final class MetaLeadAccessCheck
{
    private const TIMEOUT_SECONDS = 15;

    private const LEAD_FIELDS = 'id,created_time,form_id,platform,is_organic,field_data';

    /**
     * @return array{steps: array<int, array<string, mixed>>, verdict: array<string, array{status: string, detail: string}>}
     */
    public function run(SocialChannel $page, ?string $formId = null, bool $createTestLead = false, bool $keepTestLead = false): array
    {
        $steps = [];
        $token = (string) ($page->credentials['token'] ?? '');
        $pageId = (string) $page->external_id;

        if ($page->provider !== 'messenger' || $token === '' || $pageId === '') {
            return ['steps' => [], 'verdict' => $this->verdict([], 'La Página no tiene una conexión guardada en el CRM.')];
        }

        // 1) La Página es accesible con la conexión actual.
        $steps[] = $this->call('page', 'GET', $pageId, ['fields' => 'id,name'], $token);

        // 2) Acceso a leads según Meta (permiso de la app, del usuario/negocio, Leads Access Manager).
        $steps[] = $this->call('lead_access', 'GET', $pageId, ['fields' => 'has_lead_access'], $token);

        // 3) Formularios de la Página.
        $forms = $this->call('forms', 'GET', $pageId.'/leadgen_forms', ['fields' => 'id,name,status,leads_count', 'limit' => 25], $token);
        $steps[] = $forms;
        $formId ??= $forms['ok'] ? ($forms['response']['data'][0]['id'] ?? null) : null;

        if ($formId !== null && $formId !== '') {
            if ($createTestLead) {
                // 4a) Lead de PRUEBA de Meta (datos ficticios de Meta), lectura y limpieza.
                $created = $this->call('test_lead_create', 'POST', $formId.'/test_leads', [], $token);
                $steps[] = $created;
                $leadId = $created['ok'] ? (string) ($created['response']['id'] ?? '') : '';
                if ($leadId !== '') {
                    $steps[] = $this->call('test_lead_read', 'GET', $leadId, ['fields' => self::LEAD_FIELDS], $token);
                    if (! $keepTestLead) {
                        $steps[] = $this->call('test_lead_delete', 'DELETE', $leadId, [], $token);
                    }
                }
            } else {
                // 4b) Lectura de leads del formulario (solo ids y fecha; sin datos personales).
                $steps[] = $this->call('form_leads', 'GET', $formId.'/leads', ['fields' => 'id,created_time', 'limit' => 1], $token);
            }
        }

        return ['steps' => $steps, 'verdict' => $this->verdict($steps)];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function call(string $step, string $method, string $path, array $params, string $token): array
    {
        $operation = $method.' /'.config('social.graph_version', 'v26.0').'/'.ltrim($path, '/').($params !== [] && $method === 'GET' ? '?'.urldecode(http_build_query($params)) : '');

        try {
            $request = Http::timeout(self::TIMEOUT_SECONDS)->withToken($token)->acceptJson();
            $url = 'https://graph.facebook.com/'.config('social.graph_version', 'v26.0').'/'.ltrim($path, '/');
            /** @var Response $response */
            $response = match ($method) {
                'POST' => $request->asForm()->post($url, $params),
                'DELETE' => $request->delete($url),
                default => $request->get($url, $params),
            };
        } catch (Throwable $e) {
            return ['step' => $step, 'operation' => $operation, 'ok' => false, 'http_status' => null, 'response' => ['network_error' => $this->clean($e->getMessage(), $token)], 'area' => 'network'];
        }

        $body = is_array($response->json()) ? $response->json() : ['raw' => mb_substr($response->body(), 0, 300)];
        $body = $this->sanitize($body, $token);

        return [
            'step' => $step,
            'operation' => $operation,
            'ok' => $response->successful() && ! isset($body['error']),
            'http_status' => $response->status(),
            'response' => $body,
            'area' => isset($body['error']) ? $this->classify((array) $body['error']) : null,
        ];
    }

    /**
     * Causa de un error de Meta: page | business | app | token | other.
     *
     * @param  array<string, mixed>  $error
     */
    public function classify(array $error): string
    {
        $code = (int) ($error['code'] ?? 0);
        $subcode = (int) ($error['error_subcode'] ?? 0);
        $message = strtolower((string) ($error['message'] ?? ''));

        return match (true) {
            $code === 190 || $code === 102 => 'token',
            str_contains($message, 'lead access') || str_contains($message, 'leads access') || str_contains($message, 'leads access manager') => 'business',
            str_contains($message, 'leads_retrieval') || str_contains($message, 'pages_manage_ads') || str_contains($message, 'ads_management')
                || str_contains($message, 'pages_read_engagement') || str_contains($message, 'pages_show_list') || $code === 3 => 'app',
            ($code === 100 && $subcode === 33) || str_contains($message, 'task') || str_contains($message, 'page admin') => 'page',
            $code === 10 || $code === 200 => str_contains($message, 'app') ? 'app' : 'page',
            default => 'other',
        };
    }

    /**
     * Veredicto en lenguaje llano para las tres causas.
     *
     * @param  array<int, array<string, mixed>>  $steps
     * @return array<string, array{status: string, detail: string}>
     */
    private function verdict(array $steps, ?string $globalError = null): array
    {
        $out = [
            'page' => ['status' => 'unknown', 'detail' => __('Sin comprobar.')],
            'business' => ['status' => 'unknown', 'detail' => __('Sin comprobar.')],
            'app' => ['status' => 'unknown', 'detail' => __('Sin comprobar.')],
        ];
        if ($globalError !== null) {
            $out['page'] = ['status' => 'fail', 'detail' => __($globalError)];

            return $out;
        }

        $by = collect($steps)->keyBy('step');
        $fail = function (string $area, array $step) use (&$out): void {
            if ($out[$area]['status'] !== 'fail') {
                $out[$area] = ['status' => 'fail', 'detail' => (string) ($step['response']['error']['message'] ?? __('Meta rechazó la operación.'))];
            }
        };

        $page = $by->get('page');
        if ($page !== null) {
            if ($page['ok']) {
                $out['page'] = ['status' => 'ok', 'detail' => __('La conexión actual puede leer la Página.')];
            } else {
                $fail($page['area'] === 'app' ? 'app' : 'page', $page);
            }
        }

        $access = $by->get('lead_access');
        if ($access !== null && $access['ok']) {
            $info = (array) ($access['response']['has_lead_access'] ?? []);
            if (array_key_exists('app_has_leads_permission', $info)) {
                $out['app'] = $info['app_has_leads_permission']
                    ? ['status' => 'ok', 'detail' => __('La aplicación tiene el permiso de leads.')]
                    : ['status' => 'fail', 'detail' => (string) ($info['failure_resolution'] ?? $info['failure_reason'] ?? __('La aplicación no tiene el permiso de leads.'))];
            }
            if (array_key_exists('user_has_leads_permission', $info) || array_key_exists('can_access_lead', $info)) {
                $can = (bool) ($info['user_has_leads_permission'] ?? $info['can_access_lead'] ?? false);
                $out['business'] = $can
                    ? ['status' => 'ok', 'detail' => __('El negocio da acceso a los leads a esta conexión.')]
                    : ['status' => 'fail', 'detail' => (string) ($info['failure_resolution'] ?? $info['failure_reason'] ?? __('El negocio no da acceso a los leads a esta conexión (Leads Access Manager).'))];
            }
        } elseif ($access !== null) {
            $fail($access['area'] === 'business' ? 'business' : ($access['area'] === 'page' || $access['area'] === 'token' ? 'page' : 'app'), $access);
        }

        foreach (['forms', 'form_leads', 'test_lead_create', 'test_lead_read'] as $key) {
            $step = $by->get($key);
            if ($step === null) {
                continue;
            }
            if ($step['ok']) {
                foreach (['app', 'business'] as $area) {
                    if ($out[$area]['status'] === 'unknown' && in_array($key, ['form_leads', 'test_lead_read'], true)) {
                        $out[$area] = ['status' => 'ok', 'detail' => __('Meta permitió leer los leads del formulario.')];
                    }
                }

                continue;
            }
            $area = (string) $step['area'];
            $fail(match ($area) {
                'business' => 'business',
                'token', 'page' => 'page',
                default => 'app',
            }, $step);
        }

        return $out;
    }

    /**
     * Quita credenciales y enmascara datos personales de la respuesta de Meta.
     *
     * @param  array<string|int, mixed>  $data
     * @return array<string|int, mixed>
     */
    private function sanitize(array $data, string $token): array
    {
        foreach ($data as $key => $value) {
            if (in_array((string) $key, ['access_token', 'token'], true)) {
                $data[$key] = '[oculto]';
            } elseif ($key === 'field_data' && is_array($value)) {
                $data[$key] = array_map(fn ($f): array => [
                    'name' => is_array($f) ? ($f['name'] ?? null) : null,
                    'values' => is_array($f) && is_array($f['values'] ?? null) ? array_map(fn ($v): string => $this->mask((string) $v), $f['values']) : [],
                ], $value);
            } elseif (is_array($value)) {
                $data[$key] = $this->sanitize($value, $token);
            } elseif (is_string($value)) {
                // Los mensajes de error pueden citar datos del usuario; el resto solo pierde credenciales.
                $data[$key] = $key === 'message' ? $this->clean($value, $token) : $this->stripToken($value, $token);
            }
        }

        return $data;
    }

    private function stripToken(string $text, string $token): string
    {
        $text = $token !== '' ? str_replace($token, '[oculto]', $text) : $text;

        return (string) preg_replace('/access_token=[^&\s"]+/', 'access_token=[oculto]', $text);
    }

    private function clean(string $text, string $token): string
    {
        $text = $this->stripToken($text, $token);
        $text = (string) preg_replace('/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', '[correo]', $text);

        return (string) preg_replace('/\+?\d[\d\s().-]{7,}\d/', '[teléfono]', $text);
    }

    private function mask(string $value): string
    {
        $len = mb_strlen($value);

        return $len <= 2 ? str_repeat('•', $len) : mb_substr($value, 0, 1).str_repeat('•', min(8, $len - 2)).mb_substr($value, -1);
    }
}
