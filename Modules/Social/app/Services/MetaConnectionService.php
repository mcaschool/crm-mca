<?php

declare(strict_types=1);

namespace Modules\Social\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * «Conectar Meta» — capa común de onboarding vía Facebook Login for Business.
 *
 * Descubre los activos que la institución autoriza (Páginas de Facebook = Messenger, e
 * Instagram Professional asociado) reutilizando la app de Meta ya existente. Es un flujo
 * NUEVO que CONVIVE con la conexión Meta operativa: en esta etapa solo hace login +
 * descubrimiento + normalización; NO crea, sobreescribe ni toca canales, tokens ni webhooks.
 *
 * NO tiene nada que ver con WhatsApp Embedded Signup (Coexistence): son flujos separados y
 * no comparten estado. Sí comparte, deliberadamente, los MISMOS secretos de plataforma
 * (App ID, App Secret real y versión de Graph) para no duplicar credenciales.
 *
 * Seguridad: el authorization code se intercambia SERVER-SIDE (el App Secret jamás sale del
 * backend ni viaja en URLs); state anti-CSRF de un solo uso con TTL corto, ligado a usuario
 * e institución; los Page Access Tokens nunca se registran ni se devuelven al navegador
 * (la UI consume solo proyecciones forDisplay()).
 */
final class MetaConnectionService
{
    private const TIMEOUT_SECONDS = 20;

    private const STATE_TTL_SECONDS = 600;

    /** La plataforma está lista para iniciar el login (App ID + config ID + App Secret). */
    public function isPlatformConfigured(): bool
    {
        if ($this->fakeMode() !== null) {
            return true; // atajo SOLO-LOCAL para desarrollar sin credenciales reales
        }

        return $this->appId() !== ''
            && $this->loginConfigId() !== ''
            && $this->appSecret() !== '';
    }

    /**
     * Datos PÚBLICOS que necesita el SDK de Facebook en el navegador (App ID, Configuration
     * ID y tipo de token no son secretos; el App Secret jamás sale del servidor). token_type
     * gobierna los parámetros de FB.login y siempre vale 'user' o 'system' (default seguro).
     *
     * @return array{app_id: string, config_id: string, version: string, token_type: string}
     */
    public function browserConfig(): array
    {
        return [
            'app_id' => $this->appId(),
            'config_id' => $this->loginConfigId(),
            'version' => $this->graphVersion(),
            'token_type' => $this->loginTokenType(),
        ];
    }

    /** Tipo de token de la configuración: 'user' o 'system' (cualquier otro valor → 'system'). */
    public function loginTokenType(): string
    {
        return config('social.meta.login_token_type') === 'user' ? 'user' : 'system';
    }

    // ----------------------------------------------------------------- state anti-CSRF

    /** Emite el state (un solo uso, TTL corto) ligado al usuario e institución. */
    public function issueState(int $userId, int $institutionId): string
    {
        $state = Str::random(40);
        Cache::put($this->stateKey($userId, $state), $institutionId, self::STATE_TTL_SECONDS);

        return $state;
    }

    /** Consume el state (replay protection: solo la PRIMERA validación pasa). */
    public function consumeState(int $userId, string $state): ?int
    {
        if ($state === '') {
            return null;
        }
        $institutionId = Cache::pull($this->stateKey($userId, $state));

        return is_int($institutionId) ? $institutionId : null;
    }

    // ----------------------------------------------------------------- descubrimiento

    /**
     * Descubre las Páginas accesibles (con su Instagram Professional asociado) a partir de
     * lo que devolvió Facebook Login for Business. NO persiste nada. Soporta los DOS tipos
     * de configuración, inferidos por el propio resultado de FB.login (sin variable de tipo):
     *
     *  - User Access Token: el navegador entrega directamente el access token → se usa TAL
     *    CUAL (no hay code que intercambiar).
     *  - System User (BISU): el navegador entrega un authorization code → intercambio
     *    server-side (App Secret nunca sale del backend).
     *
     * El access token nunca se registra, ni se incluye en excepciones, ni se devuelve a la UI.
     */
    public function discoverAssets(string $code = '', string $accessToken = ''): MetaDiscoveryResult
    {
        if (($fake = $this->fakeMode()) !== null) {
            return $this->fakeDiscovery($fake);
        }

        if ($accessToken !== '') {
            $token = $accessToken;                 // User Access Token: uso directo
        } elseif ($code !== '') {
            $token = $this->exchangeCode($code);   // System User: intercambio server-side
        } else {
            throw new RuntimeException(__('Faltan datos de la autorización de Meta. Vuelve a iniciar el proceso.'));
        }

        return $this->fetchPages($token);
    }

    /** Intercambio server-side del authorization code por el access token de usuario. */
    private function exchangeCode(string $code): string
    {
        $secret = $this->appSecret();
        if ($secret === '') {
            throw new RuntimeException(__('Falta el App Secret en la configuración del servidor.'));
        }

        try {
            // POST con cuerpo form: ni el code ni el secret viajan en la URL.
            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->asForm()
                ->acceptJson()
                ->post("https://graph.facebook.com/{$this->graphVersion()}/oauth/access_token", [
                    'client_id' => $this->appId(),
                    'client_secret' => $secret,
                    'code' => $code,
                ]);
        } catch (Throwable $e) {
            Log::warning('social.meta.connect: error de red en el intercambio de código', ['error' => $e->getMessage()]);
            throw new RuntimeException(__('No se pudo completar la conexión con Meta (red). Inténtalo de nuevo.'));
        }

        $token = $response->json('access_token');
        if (! $response->successful() || ! is_string($token) || $token === '') {
            Log::warning('social.meta.connect: Meta rechazó el intercambio de código', [
                'status' => $response->status(),
                'code' => (int) ($response->json('error.code') ?? 0),
            ]);
            throw new RuntimeException(__('Meta rechazó la conexión. Vuelve a intentar el proceso desde el principio.'));
        }

        return $token;
    }

    /**
     * GET /me/accounts con el user token: Page ID, nombre, Page Access Token e Instagram
     * Professional asociado (id + username) en una sola llamada.
     */
    private function fetchPages(string $token): MetaDiscoveryResult
    {
        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->withToken($token)
                ->acceptJson()
                ->get("https://graph.facebook.com/{$this->graphVersion()}/me/accounts", [
                    'fields' => 'id,name,access_token,instagram_business_account{id,username}',
                    'limit' => 100,
                ]);
        } catch (Throwable $e) {
            Log::warning('social.meta.connect: error de red al consultar Páginas', ['error' => $e->getMessage()]);
            throw new RuntimeException(__('No se pudieron obtener las Páginas de Meta (red). Inténtalo de nuevo.'));
        }

        if (! $response->successful()) {
            Log::warning('social.meta.connect: Meta rechazó la consulta de Páginas', ['status' => $response->status()]);
            throw new RuntimeException(__('Meta no devolvió las Páginas autorizadas. Revisa los permisos concedidos e inténtalo de nuevo.'));
        }

        $rows = $response->json('data');
        $pages = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $pageId = (string) ($row['id'] ?? '');
            $pageToken = (string) ($row['access_token'] ?? '');
            if ($pageId === '' || $pageToken === '') {
                continue; // sin id o sin token no es un activo utilizable
            }
            $ig = is_array($row['instagram_business_account'] ?? null) ? $row['instagram_business_account'] : [];
            $igId = isset($ig['id']) ? (string) $ig['id'] : null;
            $igUsername = isset($ig['username']) ? (string) $ig['username'] : null;

            $pages[] = new MetaDiscoveredPage(
                pageId: $pageId,
                name: (string) ($row['name'] ?? $pageId),
                pageAccessToken: $pageToken,
                instagramId: $igId !== '' ? $igId : null,
                instagramUsername: $igUsername !== '' ? $igUsername : null,
            );
        }

        return new MetaDiscoveryResult($pages);
    }

    // ----------------------------------------------------------------- internos

    /**
     * App Secret de PLATAFORMA de la Meta App (config('social.meta.app_secret')). Este servicio
     * NO conoce WhatsApp/Messenger/Instagram: la resolución concreta del secreto vive en la
     * config, no aquí. NO se duplica ninguna credencial.
     */
    private function appSecret(): string
    {
        return (string) (config('social.meta.app_secret') ?? '');
    }

    private function appId(): string
    {
        return (string) config('social.meta_app_id', '');
    }

    private function loginConfigId(): string
    {
        return (string) config('social.meta.login_config_id', '');
    }

    private function graphVersion(): string
    {
        return (string) config('social.graph_version', 'v26.0');
    }

    private function stateKey(int $userId, string $state): string
    {
        return "social.meta.connect.state.{$userId}.".hash('sha256', $state);
    }

    /** Atajo SOLO-LOCAL: 'ok' | 'empty' | null (deshabilitado). Ignorado fuera de local. */
    private function fakeMode(): ?string
    {
        $fake = config('social.meta.fake_discovery');

        return (is_string($fake) && $fake !== '' && app()->environment('local')) ? $fake : null;
    }

    private function fakeDiscovery(string $mode): MetaDiscoveryResult
    {
        if ($mode === 'empty') {
            return new MetaDiscoveryResult([]);
        }

        return new MetaDiscoveryResult([
            new MetaDiscoveredPage(
                pageId: 'FAKE_PAGE_1',
                name: 'MCA Business & Postgraduate School',
                pageAccessToken: 'FAKE_PAGE_TOKEN',
                instagramId: 'FAKE_IG_1',
                instagramUsername: 'mcaschoolofbusiness',
            ),
        ]);
    }
}
