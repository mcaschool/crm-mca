<?php

declare(strict_types=1);

namespace Modules\TeamCalls\Integration\LiveKit;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Modules\TeamCalls\Support\MediaGrant;
use Throwable;

/**
 * Tokens de LiveKit (JWT HS256 firmados con el secreto de la API, formato oficial de «access token»):
 *
 *  - participant(): credencial de UN dispositivo para UNA sala que SOLO permite entrar, oculto y sin
 *    medios. LiveKit autohospedado no revoca credenciales (verificado con 1.13.10: ni RemoveParticipant
 *    ni revoke_token_ts impiden volver), así que los permisos se conceden aparte, por conexión validada.
 *  - permission(): permisos que el backend concede con UpdateParticipant (fuentes según el medio).
 *  - server(): token de servicio muy corto para que el backend llame al API de salas.
 *  - verifyWebhook(): validación OFICIAL de los webhooks: JWT del encabezado Authorization firmado con
 *    la misma API (iss = API key, firma, vigencia) y claim «sha256» = SHA-256 en base64 del cuerpo
 *    ORIGINAL de la petición.
 */
final class LiveKitTokens
{
    public function __construct(private readonly LiveKitConfig $config) {}

    /** @return array{token: string, expires_at: Carbon} */
    public function participant(MediaGrant $grant, ?Carbon $now = null): array
    {
        $now ??= Carbon::now();
        $expires = $now->copy()->addSeconds($grant->ttlSeconds);

        // Credencial SIN permisos de medios y oculta: solo permite entrar a la sala. Los permisos los
        // concede el backend (UpdateParticipant, ver permission()) cuando valida la conexión real. Así
        // una credencial reutilizada o de alguien ya expulsado no recibe ni envía audio, video ni datos.
        $claims = [
            'iss' => $this->config->apiKey,
            'sub' => $grant->identity,
            'jti' => (string) Str::ulid(),
            'nbf' => $now->getTimestamp(),
            'iat' => $now->getTimestamp(),
            'exp' => $expires->getTimestamp(),
            'metadata' => json_encode(['user_id' => $grant->userId, 'role' => $grant->isHost ? 'host' : 'member'], JSON_THROW_ON_ERROR),
            'video' => [
                'room' => $grant->room,
                'roomJoin' => true,
                'canPublish' => false,
                'canPublishSources' => [],
                'canSubscribe' => false,
                'canPublishData' => false,
                'canUpdateOwnMetadata' => false,
                'roomCreate' => false,
                'roomList' => false,
                'roomAdmin' => false,
                'roomRecord' => false,
                'hidden' => true,
                'recorder' => false,
            ],
        ];

        return ['token' => JWT::encode($claims, $this->config->secret(), 'HS256'), 'expires_at' => $expires];
    }

    /**
     * Permisos que el backend CONCEDE a una conexión ya validada (ParticipantPermission del API de
     * salas, nombres del proto). Las fuentes dependen del medio de la llamada.
     *
     * @return array<string, mixed>
     */
    public function permission(MediaGrant $grant): array
    {
        $sources = array_values(array_filter([
            $grant->canPublishAudio ? 'MICROPHONE' : null,
            $grant->canPublishVideo ? 'CAMERA' : null,
            $grant->canShareScreen ? 'SCREEN_SHARE' : null,
            $grant->canShareScreen ? 'SCREEN_SHARE_AUDIO' : null,
        ]));

        return [
            'can_subscribe' => true,
            'can_publish' => $sources !== [],
            'can_publish_data' => true,
            'can_publish_sources' => $sources,
            'can_update_metadata' => false,
            'hidden' => false,
            'recorder' => false,
        ];
    }

    /** @param array<string, mixed> $video permisos del API de salas (roomCreate, roomList, roomAdmin + room) */
    public function server(array $video): string
    {
        $now = time();

        return JWT::encode([
            'iss' => $this->config->apiKey,
            'sub' => 'teamcalls-backend',
            'nbf' => $now,
            'iat' => $now,
            'exp' => $now + $this->config->serverTokenTtl,
            'video' => $video,
        ], $this->config->secret(), 'HS256');
    }

    /**
     * @return array<string, mixed> claims del token ya verificado
     *
     * @throws InvalidMediaWebhook
     */
    public function verifyWebhook(?string $authorization, string $rawBody): array
    {
        $token = trim((string) preg_replace('/^Bearer\s+/i', '', (string) $authorization));
        if ($token === '' || $rawBody === '') {
            throw new InvalidMediaWebhook('missing token or body');
        }

        $previous = JWT::$leeway;
        JWT::$leeway = $this->config->webhookLeeway;
        try {
            $claims = (array) JWT::decode($token, new Key($this->config->secret(), 'HS256'));
        } catch (Throwable) {
            throw new InvalidMediaWebhook('bad signature');
        } finally {
            JWT::$leeway = $previous;
        }

        if (($claims['iss'] ?? null) !== $this->config->apiKey || ! isset($claims['exp'])) {
            throw new InvalidMediaWebhook('bad issuer or no expiry');
        }
        $expected = base64_encode(hash('sha256', $rawBody, true));
        if (! is_string($claims['sha256'] ?? null) || ! hash_equals($expected, $claims['sha256'])) {
            throw new InvalidMediaWebhook('body mismatch');
        }

        return $claims;
    }
}
