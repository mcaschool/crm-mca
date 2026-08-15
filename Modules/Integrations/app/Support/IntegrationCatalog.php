<?php

declare(strict_types=1);

namespace Modules\Integrations\Support;

/**
 * Catalogo de tipos de integracion: describe, por tipo, sus campos (cuales son
 * SECRETOS y cuales metadatos), sus proveedores y si tiene prueba de conexion
 * real en este bloque.
 *
 * Es la fuente unica que alimenta:
 *  - el formulario de configuracion (campos dinamicos, inputs de secreto vacios),
 *  - el enmascarado (que claves son secretas),
 *  - el probador de conexion (que test aplicar).
 *
 * El USO real de cada servicio NO vive aqui (Celia en Bloque 6, correo en 7...).
 */
final class IntegrationCatalog
{
    /**
     * @return array<string, array{
     *   label: string,
     *   testable: bool,
     *   providers: array<int, string>,
     *   fields: array<int, array{key: string, label: string, secret: bool, required: bool, type: string, options?: array<int,string>}>
     * }>
     */
    public static function all(): array
    {
        return [
            'ai_provider' => [
                'label' => 'Proveedor de IA',
                'testable' => true,
                'providers' => ['openai', 'gemini', 'anthropic', 'deepseek', 'qwen', 'kimi'],
                'fields' => [
                    ['key' => 'api_key', 'label' => 'API Key', 'secret' => true, 'required' => true, 'type' => 'password'],
                    ['key' => 'base_url', 'label' => 'Base URL (opcional)', 'secret' => false, 'required' => false, 'type' => 'text'],
                ],
            ],
            'smtp' => [
                'label' => 'SMTP',
                'testable' => true,
                'providers' => [],
                'fields' => [
                    ['key' => 'host', 'label' => 'Host', 'secret' => false, 'required' => true, 'type' => 'text'],
                    ['key' => 'port', 'label' => 'Puerto', 'secret' => false, 'required' => true, 'type' => 'number'],
                    ['key' => 'username', 'label' => 'Usuario', 'secret' => false, 'required' => true, 'type' => 'text'],
                    ['key' => 'password', 'label' => 'Contrasena', 'secret' => true, 'required' => true, 'type' => 'password'],
                    ['key' => 'encryption', 'label' => 'Cifrado', 'secret' => false, 'required' => false, 'type' => 'select', 'options' => ['tls', 'ssl', 'none']],
                ],
            ],
            'n8n' => [
                'label' => 'n8n',
                'testable' => true,
                'providers' => [],
                'fields' => [
                    ['key' => 'webhook_url', 'label' => 'URL del webhook', 'secret' => false, 'required' => true, 'type' => 'text'],
                    ['key' => 'signing_secret', 'label' => 'Secreto de firma (HMAC)', 'secret' => true, 'required' => true, 'type' => 'password'],
                ],
            ],
            'google' => [
                'label' => 'Google',
                'testable' => false,
                'providers' => [],
                'fields' => [
                    ['key' => 'client_id', 'label' => 'Client ID', 'secret' => false, 'required' => true, 'type' => 'text'],
                    ['key' => 'client_secret', 'label' => 'Client Secret', 'secret' => true, 'required' => true, 'type' => 'password'],
                ],
            ],
            'mailrelay' => [
                'label' => 'Mailrelay',
                'testable' => false,
                'providers' => [],
                'fields' => [
                    ['key' => 'api_key', 'label' => 'API Key', 'secret' => true, 'required' => true, 'type' => 'password'],
                    ['key' => 'account', 'label' => 'Cuenta', 'secret' => false, 'required' => false, 'type' => 'text'],
                ],
            ],
            'mailjet' => [
                'label' => 'Mailjet',
                'testable' => false,
                'providers' => [],
                'fields' => [
                    ['key' => 'api_key', 'label' => 'API Key', 'secret' => true, 'required' => true, 'type' => 'password'],
                    ['key' => 'api_secret', 'label' => 'API Secret', 'secret' => true, 'required' => true, 'type' => 'password'],
                ],
            ],
            'stripe' => [
                'label' => 'Stripe',
                'testable' => false,
                'providers' => [],
                'fields' => [
                    ['key' => 'secret_key', 'label' => 'Secret Key', 'secret' => true, 'required' => true, 'type' => 'password'],
                    ['key' => 'publishable_key', 'label' => 'Publishable Key', 'secret' => false, 'required' => false, 'type' => 'text'],
                ],
            ],
            'moodle' => [
                'label' => 'Moodle',
                'testable' => false,
                'providers' => [],
                'fields' => [
                    ['key' => 'base_url', 'label' => 'Base URL', 'secret' => false, 'required' => true, 'type' => 'text'],
                    ['key' => 'token', 'label' => 'Token', 'secret' => true, 'required' => true, 'type' => 'password'],
                ],
            ],
        ];
    }

    /**
     * @return array{
     *   label: string,
     *   testable: bool,
     *   providers: array<int, string>,
     *   fields: array<int, array{key: string, label: string, secret: bool, required: bool, type: string, options?: array<int,string>}>
     * }|null
     */
    public static function for(string $type): ?array
    {
        return self::all()[$type] ?? null;
    }

    public static function exists(string $type): bool
    {
        return array_key_exists($type, self::all());
    }
}
