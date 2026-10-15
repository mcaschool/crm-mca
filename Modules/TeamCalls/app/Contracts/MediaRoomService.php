<?php

declare(strict_types=1);

namespace Modules\TeamCalls\Contracts;

use Modules\TeamCalls\Support\JoinCredentials;
use Modules\TeamCalls\Support\MediaGrant;
use Modules\TeamCalls\Support\MediaParticipant;

/**
 * Abstracción del servidor de medios (LiveKit autohospedado: Integration/LiveKit). TeamCalls decide
 * quién entra y con qué permisos; esta interfaz solo materializa esa decisión. Las credenciales
 * caducan pronto y NO se asumen de un solo uso: el backend puede expulsar o cerrar la sala.
 *
 * Las operaciones que necesitan al servidor lanzan CallException «media_unavailable» si no responde.
 * Todas son idempotentes (preparar una sala existente, retirar a quien ya no está, cerrar una sala
 * cerrada no fallan).
 */
interface MediaRoomService
{
    /** ¿Hay servidor de medios configurado? Sin él nadie se conecta a la sala ni la llamada pasa a activa. */
    public function available(): bool;

    /**
     * true: la conexión REAL a la sala la confirma el servidor de medios con eventos firmados; la
     * llamada solo pasa a activa con dos personas realmente conectadas. false: el motor da por
     * conectada a quien se une (dobles de prueba sin eventos).
     */
    public function confirmsConnections(): bool;

    /** Crea la sala si no existe (las salas solo las crea el backend). */
    public function prepareRoom(string $room, int $maxParticipants): void;

    /**
     * Credencial para ENTRAR a la sala. Si el servidor confirma conexiones, no concede medios por sí
     * sola: hace falta admitParticipant() tras validar la conexión real.
     */
    public function issueJoinCredentials(MediaGrant $grant): JoinCredentials;

    /** Concede a una conexión ya validada los permisos de medios de $grant (idempotente). */
    public function admitParticipant(MediaGrant $grant): void;

    /** @return list<MediaParticipant> participantes conectados realmente (vacío si la sala no existe) */
    public function participants(string $room): array;

    /** @return list<string> salas abiertas en el servidor de medios */
    public function rooms(): array;

    public function removeParticipant(string $room, string $identity): void;

    public function closeRoom(string $room): void;
}
