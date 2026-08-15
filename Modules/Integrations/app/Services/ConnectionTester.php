<?php

declare(strict_types=1);

namespace Modules\Integrations\Services;

use Illuminate\Support\Facades\Http;
use Modules\Integrations\Models\Integration;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Throwable;

/**
 * Prueba de conectividad/credenciales por tipo de integracion, SIN construir la
 * funcionalidad del servicio. Ademas sirve para comprobar, ya en el servidor
 * real (Hostinger), que hay HTTP saliente hacia cada proveedor.
 *
 * Real en este bloque: proveedor de IA (validar clave / listar modelos), SMTP
 * (conectar+autenticar), n8n (ping firmado HMAC). El resto: pendiente de su bloque.
 *
 * NUNCA registra el secreto en el mensaje de resultado.
 */
class ConnectionTester
{
    public function test(Integration $integration): ConnectionTestResult
    {
        try {
            return match ($integration->type) {
                'ai_provider' => $this->testAiProvider($integration),
                'smtp' => $this->testSmtp($integration),
                'n8n' => $this->testN8n($integration),
                default => ConnectionTestResult::pending(),
            };
        } catch (Throwable $e) {
            // Mensaje saneado: la clase del error, nunca el contenido del secreto.
            return ConnectionTestResult::fail('Error de conexion: '.class_basename($e));
        }
    }

    private function testAiProvider(Integration $integration): ConnectionTestResult
    {
        $apiKey = (string) $integration->secret('api_key');
        if ($apiKey === '') {
            return ConnectionTestResult::fail('Falta la API key.');
        }

        $provider = (string) ($integration->provider ?? 'openai');
        $baseUrl = rtrim((string) ($integration->secret('base_url') ?: $this->defaultBaseUrl($provider)), '/');

        [$request, $url] = match ($provider) {
            'anthropic' => [
                Http::withHeaders(['x-api-key' => $apiKey, 'anthropic-version' => '2023-06-01']),
                $baseUrl.'/v1/models',
            ],
            'gemini' => [
                Http::asJson(),
                $baseUrl.'/v1beta/models?key='.urlencode($apiKey),
            ],
            // openai y compatibles (deepseek, qwen, kimi): Bearer + /models.
            default => [
                Http::withToken($apiKey),
                $baseUrl.'/models',
            ],
        };

        $response = $request->timeout(15)->get($url);

        return $response->successful()
            ? ConnectionTestResult::ok('Credencial valida ('.$provider.').')
            : ConnectionTestResult::fail('El proveedor respondio '.$response->status().'.');
    }

    private function defaultBaseUrl(string $provider): string
    {
        return match ($provider) {
            'anthropic' => 'https://api.anthropic.com',
            'gemini' => 'https://generativelanguage.googleapis.com',
            'deepseek' => 'https://api.deepseek.com',
            'qwen' => 'https://dashscope.aliyuncs.com/compatible-mode/v1',
            'kimi' => 'https://api.moonshot.cn/v1',
            default => 'https://api.openai.com/v1',
        };
    }

    private function testSmtp(Integration $integration): ConnectionTestResult
    {
        $host = (string) $integration->secret('host');
        $port = (int) $integration->secret('port');
        $encryption = (string) ($integration->secret('encryption') ?: 'tls');

        if ($host === '' || $port === 0) {
            return ConnectionTestResult::fail('Faltan host o puerto.');
        }

        $transport = new EsmtpTransport($host, $port, $encryption === 'ssl');
        $transport->setUsername((string) $integration->secret('username'));
        $transport->setPassword((string) $integration->secret('password'));

        // start() abre el socket y autentica; lanza excepcion si falla.
        $transport->start();
        $transport->stop();

        return ConnectionTestResult::ok('Conexion y autenticacion SMTP correctas.');
    }

    private function testN8n(Integration $integration): ConnectionTestResult
    {
        $webhookUrl = (string) $integration->secret('webhook_url');
        $signingSecret = (string) $integration->secret('signing_secret');

        if ($webhookUrl === '' || $signingSecret === '') {
            return ConnectionTestResult::fail('Faltan la URL del webhook o el secreto de firma.');
        }

        $payload = json_encode(['event' => 'connection.test'], JSON_THROW_ON_ERROR);
        $signature = hash_hmac('sha256', $payload, $signingSecret);

        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
            'X-CRM-Signature' => $signature,
        ])->timeout(15)->withBody($payload, 'application/json')->post($webhookUrl);

        return $response->successful()
            ? ConnectionTestResult::ok('Webhook n8n respondio correctamente.')
            : ConnectionTestResult::fail('El webhook respondio '.$response->status().'.');
    }
}
