<?php

declare(strict_types=1);

namespace Modules\Integrations\Services;

use Illuminate\Support\Facades\Http;
use Modules\Ai\Enums\AiErrorCategory;
use Modules\Integrations\Models\AiProcessConfig;
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

    /**
     * Prueba de una integración de IA en DOS niveles: (1) endpoint + credencial vía
     * /models; (2) GENERACIÓN real mínima con el modelo configurado (si hay un proceso
     * que use esta integración). Un /models=200 NO basta para declararla operativa:
     * la cuota/el modelo se validan con la generación. Los fallos se reportan por
     * categoría NORMALIZADA (quota_exhausted, model_unavailable, rate_limited...).
     */
    private function testAiProvider(Integration $integration): ConnectionTestResult
    {
        $apiKey = (string) $integration->secret('api_key');
        if ($apiKey === '') {
            return ConnectionTestResult::fail('Falta la API key.');
        }

        $provider = (string) ($integration->provider ?? 'openai');
        $baseUrl = rtrim((string) ($integration->secret('base_url') ?: $this->defaultBaseUrl($provider)), '/');
        /** @var array<string,string> $errorMap */
        $errorMap = (array) config("ai_models.providers.{$provider}.error_map", []);

        // (1) Endpoint + autenticación: /models.
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

        try {
            $models = $request->timeout(15)->get($url);
        } catch (Throwable) {
            return ConnectionTestResult::fail('No se pudo conectar con el proveedor (red/timeout).');
        }
        if (! $models->successful()) {
            $cat = AiErrorCategory::fromResponse($models->status(), (string) $models->json('error.code'), $errorMap);

            return ConnectionTestResult::fail('Autenticacion/endpoint: '.$cat->value.' (HTTP '.$models->status().').');
        }

        // (2) Generación real por CADA asignación proceso→modelo (una misma integración
        // puede servir a varios procesos con modelos distintos). Cada asignación se valida
        // por separado: la integración NO se declara operativa por probar un modelo suelto.
        $transport = (string) config("ai_models.providers.{$provider}.transport", 'openai_compatible');
        $models = AiProcessConfig::query()
            ->where('integration_id', $integration->getKey())
            ->where('status', 'active')
            ->pluck('model')
            ->map(fn ($m) => trim((string) $m))
            ->filter()
            ->unique()
            ->values();

        if ($models->isEmpty()) {
            return ConnectionTestResult::ok('Endpoint y credencial validos ('.$provider.'). Sin proceso de IA configurado: generacion no probada.');
        }
        if ($transport !== 'openai_compatible') {
            return ConnectionTestResult::ok('Endpoint y credencial validos ('.$provider.'). Generacion no probada: transporte "'.$transport.'" aun sin adapter.');
        }

        $results = [];
        $allOk = true;
        foreach ($models as $model) {
            try {
                $gen = Http::withToken($apiKey)->timeout(20)->post($baseUrl.'/chat/completions', [
                    'model' => $model,
                    'messages' => [['role' => 'user', 'content' => 'Respond only with OK']],
                    'max_tokens' => 5,
                    'temperature' => 0,
                ]);
            } catch (Throwable) {
                $results[] = $model.': sin respuesta (red/timeout)';
                $allOk = false;

                continue;
            }

            if ($gen->successful()) {
                $results[] = $model.': OK';

                continue;
            }

            $cat = AiErrorCategory::fromResponse($gen->status(), (string) $gen->json('error.code'), $errorMap);
            $results[] = $model.': '.$cat->value.' (HTTP '.$gen->status().')';
            $allOk = false;
        }

        $message = 'Credencial/endpoint OK. Modelos — '.implode(' · ', $results).'.';

        return $allOk ? ConnectionTestResult::ok($message) : ConnectionTestResult::fail($message);
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
