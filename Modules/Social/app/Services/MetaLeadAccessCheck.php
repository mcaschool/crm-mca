<?php

declare(strict_types=1);

namespace Modules\Social\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Modules\Social\Models\MetaLeadPage;
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
        if ($page->provider !== 'messenger') {
            return ['steps' => [], 'verdict' => $this->verdict([], 'La Página no tiene una conexión guardada en el CRM.')];
        }

        return $this->check((string) $page->external_id, (string) ($page->credentials['token'] ?? ''), $formId, $createTestLead, $keepTestLead);
    }

    /**
     * La misma comprobación para una Página de formularios publicitarios de una empresa.
     *
     * @return array{steps: array<int, array<string, mixed>>, verdict: array<string, array{status: string, detail: string}>}
     */
    public function runForLeadPage(MetaLeadPage $page, ?string $formId = null, bool $createTestLead = false, bool $keepTestLead = false): array
    {
        return $this->check((string) $page->page_id, (string) $page->page_token, $formId, $createTestLead, $keepTestLead);
    }

    /**
     * @return array{steps: array<int, array<string, mixed>>, verdict: array<string, array{status: string, detail: string}>}
     */
    public function check(string $pageId, string $token, ?string $formId = null, bool $createTestLead = false, bool $keepTestLead = false): array
    {
        $steps = [];
        if ($token === '' || $pageId === '') {
            return ['steps' => [], 'verdict' => $this->verdict([], 'La Página no tiene una conexión guardada en el CRM.')];
        }

        // 1) La Página es accesible con la conexión actual.
        $steps[] = $this->call('page', 'GET', $pageId, ['fields' => 'id,name'], $token);

        // 2) Quién respalda la conexión (debug_token, solo lectura): has_lead_access EXIGE user_id.
        $owner = $this->call('token_owner', 'GET', 'debug_token', ['input_token' => $token], $this->appToken() ?? $token, ['input_token']);
        $steps[] = $owner;
        $userId = $owner['ok'] ? (string) ($owner['raw_user_id'] ?? '') : '';

        // 3) Acceso a leads según Meta para ese usuario (permiso de la app, del negocio, Leads Access
        //    Manager). Sin user_id no es comprobable: el veredicto se basa en las lecturas reales.
        $steps[] = $userId !== ''
            ? $this->call('lead_access', 'GET', $pageId, ['fields' => 'has_lead_access.user_id('.$userId.')'], $token, [], [$userId])
            : $this->notCheckable('lead_access', 'GET /'.config('social.graph_version', 'v26.0').'/'.$pageId.'?fields=has_lead_access.user_id(…)', __('No comprobable: la conexión no permite saber qué usuario la respalda (user_id).'));

        // 4) Formularios de la Página.
        $forms = $this->call('forms', 'GET', $pageId.'/leadgen_forms', ['fields' => 'id,name,status,leads_count', 'limit' => 25], $token);
        $steps[] = $forms;
        $formId ??= $forms['ok'] ? ($forms['response']['data'][0]['id'] ?? null) : null;

        if ($formId !== null && $formId !== '') {
            if ($createTestLead) {
                // 5a) Lead de PRUEBA de Meta (datos ficticios de Meta), lectura y limpieza.
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
                // 5b) Lectura de leads del formulario (solo ids y fecha; sin datos personales).
                $steps[] = $this->call('form_leads', 'GET', $formId.'/leads', ['fields' => 'id,created_time', 'limit' => 1], $token);
            }
        }

        // El user_id en claro solo sirve para la consulta anterior: no sale de aquí.
        $steps = array_map(function (array $step): array {
            unset($step['raw_user_id']);

            return $step;
        }, $steps);

        return ['steps' => $steps, 'verdict' => $this->verdict($steps)];
    }

    /**
     * ¿Falló una comprobación REAL? (Página, formularios, lectura de leads o lead de prueba, o un
     * veredicto en «fail»). Las consultas auxiliares no comprobables no cuentan como fallo.
     *
     * @param  array{steps: array<int, array<string, mixed>>, verdict: array<string, array{status: string, detail: string}>}  $result
     */
    public function realCheckFailed(array $result): bool
    {
        $real = ['page', 'forms', 'form_leads', 'test_lead_create', 'test_lead_read', 'test_lead_delete'];

        return $result['steps'] === []
            || collect($result['verdict'])->contains(fn (array $v): bool => $v['status'] === 'fail')
            || collect($result['steps'])->contains(fn (array $s): bool => in_array($s['step'], $real, true) && $s['ok'] === false);
    }

    /** Token de aplicación (app_id|app_secret) para debug_token, si la instalación lo tiene. */
    private function appToken(): ?string
    {
        $id = (string) config('social.meta_app_id');
        $secret = (string) config('social.meta.app_secret');

        return $id !== '' && $secret !== '' ? $id.'|'.$secret : null;
    }

    /** @return array<string, mixed> paso que no se puede comprobar con esta conexión */
    private function notCheckable(string $step, string $operation, string $reason): array
    {
        return ['step' => $step, 'operation' => $operation, 'ok' => null, 'http_status' => null, 'response' => ['no_comprobable' => $reason], 'area' => 'not_checkable'];
    }

    private function maskId(string $id): string
    {
        return strlen($id) > 4 ? '…'.substr($id, -4) : '…';
    }

    /**
     * @param  array<string, mixed>  $params
     * @param  array<int, string>  $hiddenParams  parámetros que no se muestran en la operación (credenciales)
     * @param  array<int, string>  $maskValues  valores que se enmascaran en la operación (identificadores)
     * @return array<string, mixed>
     */
    private function call(string $step, string $method, string $path, array $params, string $token, array $hiddenParams = [], array $maskValues = []): array
    {
        $shown = $params;
        foreach ($hiddenParams as $p) {
            $shown[$p] = '[oculto]';
        }
        $operation = $method.' /'.config('social.graph_version', 'v26.0').'/'.ltrim($path, '/').($shown !== [] && $method === 'GET' ? '?'.urldecode(http_build_query($shown)) : '');
        foreach ($maskValues as $v) {
            $operation = str_replace($v, $this->maskId($v), $operation);
        }

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
        $rawUserId = $step === 'token_owner' ? ($body['data']['user_id'] ?? null) : null;
        $body = $this->sanitize($body, $token);

        return [
            'step' => $step,
            'raw_user_id' => $rawUserId, // solo para la siguiente consulta; nunca se imprime
            'operation' => $operation,
            'ok' => $response->successful() && ! isset($body['error']),
            'http_status' => $response->status(),
            'response' => $body,
            'area' => isset($body['error']) ? $this->classify((array) $body['error']) : null,
        ];
    }

    /**
     * Causa de un error de Meta: page | business | app | token | request | other.
     * «request» = la petición está mal formada (p. ej. #100 falta un parámetro): NO es un permiso.
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
            $code === 100 => 'request',
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
        } elseif ($access !== null && $access['ok'] === false) {
            // Solo cuenta si Meta respondió con una causa clara; una petición rechazada por un
            // parámetro (#100) o un fallo de red no dice nada de los permisos.
            match ($access['area']) {
                'business' => $fail('business', $access),
                'app' => $fail('app', $access),
                'page', 'token' => $fail('page', $access),
                default => null,
            };
        }
        $accessCheckable = $access !== null && $access['ok'] === true;

        // Lecturas REALES: listar formularios y leer leads (o el lead de prueba).
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
            match ((string) $step['area']) {
                'business' => $fail('business', $step),
                'app' => $fail('app', $step),
                'token', 'page' => $fail('page', $step),
                default => null, // petición mal formada, red u otro: fallo de la comprobación, no de un permiso
            };
        }

        $forms = $by->get('forms');
        $noForms = $forms !== null && $forms['ok'] && ((array) ($forms['response']['data'] ?? [])) === [];
        foreach (['app', 'business'] as $area) {
            if ($out[$area]['status'] === 'unknown') {
                $out[$area]['detail'] = match (true) {
                    $noForms => __('No hay formularios en la Página con los que comprobar la lectura de leads.'),
                    ! $accessCheckable => __('No comprobable con esta conexión y sin una lectura real de leads que lo confirme.'),
                    default => __('Sin datos suficientes.'),
                };
            }
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
            } elseif ($key === 'user_id' && (is_string($value) || is_int($value))) {
                $data[$key] = $this->maskId((string) $value); // identificador de una persona/usuario de sistema
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
