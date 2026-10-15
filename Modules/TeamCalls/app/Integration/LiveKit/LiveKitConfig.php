<?php

declare(strict_types=1);

namespace Modules\TeamCalls\Integration\LiveKit;

/**
 * Configuración de LiveKit autohospedado (config teamcalls.media.livekit). Falla CERRADO: si falta un
 * dato, el secreto es débil o en producción las URL no son seguras, isComplete() es false y TeamCalls
 * se comporta como si no hubiera servidor de medios (503). El secreto nunca se muestra (__debugInfo).
 */
final class LiveKitConfig
{
    public function __construct(
        public readonly string $apiUrl,
        public readonly string $clientUrl,
        public readonly string $apiKey,
        #[\SensitiveParameter] private readonly string $apiSecret,
        public readonly int $timeout = 5,
        public readonly int $connectTimeout = 3,
        public readonly int $retries = 2,
        public readonly int $retrySleepMs = 200,
        public readonly int $serverTokenTtl = 60,
        public readonly int $emptyTimeout = 120,
        public readonly int $departureTimeout = 20,
        public readonly int $webhookLeeway = 30,
        public readonly bool $requireSecureUrls = true,
    ) {}

    public static function fromConfig(): self
    {
        $c = (array) config('teamcalls.media.livekit', []);

        return new self(
            apiUrl: rtrim((string) ($c['api_url'] ?? ''), '/'),
            clientUrl: (string) ($c['client_url'] ?? ''),
            apiKey: (string) ($c['api_key'] ?? ''),
            apiSecret: (string) ($c['api_secret'] ?? ''),
            timeout: max(1, min(30, (int) ($c['timeout'] ?? 5))),
            connectTimeout: max(1, min(15, (int) ($c['connect_timeout'] ?? 3))),
            retries: max(0, min(5, (int) ($c['retries'] ?? 2))),
            retrySleepMs: max(0, min(5000, (int) ($c['retry_sleep_ms'] ?? 200))),
            serverTokenTtl: max(10, min(600, (int) ($c['server_token_ttl'] ?? 60))),
            emptyTimeout: max(10, min(3600, (int) ($c['empty_timeout'] ?? 120))),
            departureTimeout: max(0, min(600, (int) ($c['departure_timeout'] ?? 20))),
            webhookLeeway: max(0, min(300, (int) ($c['webhook_leeway'] ?? 30))),
            requireSecureUrls: app()->environment('production'),
        );
    }

    public function isComplete(): bool
    {
        $api = $this->requireSecureUrls ? ['https'] : ['http', 'https'];
        $client = $this->requireSecureUrls ? ['wss'] : ['ws', 'wss', 'http', 'https'];

        return $this->validUrl($this->apiUrl, $api) && $this->validUrl($this->clientUrl, $client)
            && preg_match('/^[A-Za-z0-9_-]{3,128}$/', $this->apiKey) === 1
            && strlen($this->apiSecret) >= 32; // HS256 exige al menos 256 bits
    }

    public function secret(): string
    {
        return $this->apiSecret;
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['apiUrl' => $this->apiUrl, 'clientUrl' => $this->clientUrl, 'apiKey' => $this->apiKey, 'apiSecret' => '••••'];
    }

    /** @param list<string> $schemes */
    private function validUrl(string $url, array $schemes): bool
    {
        $parts = parse_url($url);

        return $url !== '' && is_array($parts) && in_array(strtolower((string) ($parts['scheme'] ?? '')), $schemes, true)
            && ($parts['host'] ?? '') !== '' && ! isset($parts['user']) && ! isset($parts['pass']);
    }
}
