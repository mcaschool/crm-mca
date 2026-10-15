# TeamCalls — motor de llamadas internas (MCA Colaboración)

Fase 1: núcleo funcional. Fase 2: API web JSON segura sobre el motor. Fase 3A: adaptador LiveKit
autohospedado (sin desplegar). Sin interfaz, WebSocket, push, VPS ni apps móviles todavía.

## Qué hace
- Llamadas 1:1 y grupales de voz o video entre colaboradores de la misma institución, iniciadas hacia
  personas concretas o desde una conversación (participantes decididos por el servidor).
- Estado global (`initiating → ringing → connecting → active → ended`, más `declined`, `cancelled`,
  `missed`, `failed`) separado del estado de cada participante (`ringing`, `accepted`, `joined`,
  `left`, `declined`, `missed`, `cancelled`). Transiciones explícitas en `Domain/CallStateMachine`.
- Operaciones (`Services/CallService`): iniciar, invitar, aceptar, rechazar, cancelar, conectarse,
  salir, finalizar, fallar, credenciales de la sala, eventos del servidor de medios, consultar,
  historial y perdidas. `Contracts/CallsGateway` es la única puerta para otros módulos.
- Garantías: transacciones con bloqueo (`lockForUpdate`), idempotencia por clave de quien llama,
  respuesta única por dispositivo (`answered_elsewhere`), eventos externos deduplicados, sin
  procesos permanentes (lo vencido se aplica al consultar y con `teamcalls:expire-stale` cada minuto).

## Tablas (prefijo `collab_call_`)
`collab_call_calls`, `collab_call_participants`, `collab_call_events` — migración
`2026_11_01_300000_create_collab_call_tables`. No reutiliza Agenda, Meet ni Zoom.

## Puertos y adaptadores provisionales
TeamCalls define los puertos que necesita (`app/Contracts`) y los enlaza con `bindIf` a adaptadores
provisionales (`app/Integration/Provisional`). Cuando CollaborationCore/TeamChat registren sus
implementaciones, las sustituyen sin cambios en TeamCalls.

| Puerto | Provisional | Lo implementará |
|---|---|---|
| `ConversationAccess` | `DenyAllConversationAccess` (deniega todo) | TeamChat vía `ConversationDirectory` |
| `UserDirectory` | `PanelUserDirectory` (usuario activo + `canWorkCrm()` + misma institución) | Core: `UserDirectory` + gate `collab.use` |
| `Presence` | `NoopPresence` | Core: presencia (capa «en llamada») |
| `CallEventPublisher` | `NoopCallEventPublisher` | Core: `RealtimePublisher` (WebSocket + bandeja) |
| `PushNotifier` | `NoopPushNotifier` | Core: FCM / APNs / VoIP |
| `MediaRoomService` | `UnconfiguredMediaRooms` (sin credenciales: `media_unavailable`) | TeamCalls: `LiveKitMediaRooms` (fase 3A, se activa por configuración) |

## Dependencias pendientes de CollaborationCore
1. `ConversationDirectory` (TeamChat): sin él no hay llamadas desde conversaciones (deny-by-default).
2. Gate `collab.use` / `UserDirectory`: hoy se usa la regla provisional `canWorkCrm()`.
3. `RealtimePublisher` + canales `private-collab.user.{id}` / `private-collab.call.{ulid}`: hoy los
   eventos solo quedan en `collab_call_events` y como eventos internos de Laravel.
4. Presencia, `PushNotifier` y registro de dispositivos. En la API web el dispositivo lo decide el
   servidor (`Http/WebDevice`, uno por sesión) y se rechaza cualquier `device_id` del cliente; con
   `collab_devices` se podrán aceptar y validar dispositivos registrados (móvil, escritorio).
5. Autenticación API móvil (Sanctum + 2FA): hoy solo existe la API web con la sesión del panel.
6. Servidor LiveKit operativo (VPS): el adaptador existe (fase 3A); sin servidor configurado `unirse` y
   `credenciales` responden 503 `media_unavailable` y ninguna llamada pasa a `active`.
7. CollaborationRealtime: entregar a los clientes `call.*` y `call.media.*` (hoy solo se guardan) para que la
   interfaz vea «conectándose/conectado» sin consultar.

## API web (fase 2)
Prefijo `/colaboracion/llamadas`, nombres `collab.calls.*`. Misma pila que el panel (`web`, `auth`,
`institution.user`, `setlocale`, `can:access-panel`, 2FA obligatorio) más `RespondWithCallsJson`, que
fuerza JSON. Quien actúa sale de la sesión; los permisos, la pertenencia, las transiciones y la
idempotencia los aplica `CallService` en cada operación (el controlador es una capa fina).

| Método y ruta | Nombre | Operación | Límite/min |
|---|---|---|---|
| `GET /` (`?limit=1..50`) | `index` | historial propio | read 120 |
| `GET /perdidas` | `missed` | llamadas perdidas propias | read 120 |
| `GET /{call}` | `show` | ver llamada y participantes | read 120 |
| `POST /` `{user_id, media, idempotency_key}` (o cabecera `Idempotency-Key`) | `store` | iniciar llamada individual | start 10 |
| `POST /{call}/aceptar` · `rechazar` · `cancelar` | `accept` · `decline` · `cancel` | responder / retirar | write 60 |
| `POST /{call}/unirse` · `salir` · `finalizar` | `join` · `leave` · `end` | sala | write 60 |
| `POST /{call}/invitar` `{user_ids: [..]}` | `invite` | invitar (solo quien está dentro) | write 60 |
| `POST /{call}/credenciales` | `credentials` | credenciales de la sala para este dispositivo | write 60 |

Formato: éxito `{"data": {...}, "meta": {"replayed": bool}}` (cabecera `Idempotent-Replayed`); error
`{"error": {"code", "message", "fields"?}}`. Todo con `Cache-Control: no-store`.

| HTTP | `code` |
|---|---|
| 201 / 200 | creada / correcta; `replayed: true` = repetición sin cambios |
| 401 | `unauthenticated` |
| 403 | `forbidden`, `two_factor_required` |
| 404 | `not_found` (inexistente, ajena o de otra institución: indistinguibles) |
| 409 | `invalid_transition`, `answered_elsewhere`, `idempotency_conflict`, `busy` |
| 419 | `csrf_token_mismatch` |
| 422 | `invalid` (con `fields`), `too_many_participants` |
| 429 | `too_many_requests` (con `Retry-After`) |
| 503 | `media_unavailable` |
| 500 | `server_error` (sin detalles internos) |

Campos que el cliente no puede imponer (422): `device_id`, `institution_id`, `initiator_id`,
`conversation*` y `user_ids` al iniciar (las llamadas grupales se forman invitando). Las llamadas desde
conversaciones siguen deshabilitadas. El 405 y las rutas inexistentes los resuelve el enrutador antes
de la API y salen con el JSON genérico de Laravel.

## Servidor de medios: LiveKit (fase 3A)
`Integration/LiveKit` implementa `MediaRoomService` sobre LiveKit **autohospedado**. Se activa solo con
`TEAMCALLS_MEDIA_DRIVER=livekit` y la configuración completa (si falta algo, o en producción las URL no
son `https`/`wss`, se usa `UnconfiguredMediaRooms`: 503 `media_unavailable`).

**Dependencias.** LiveKit no publica un SDK de servidor oficial para PHP (el que enlaza su documentación
es comunitario y arrastra `google/protobuf` y `twirp`). Se usa `firebase/php-jwt` ^7.2 (mantenido por
googleapis, PHP ^8.0) para los tokens y la firma de los webhooks, y el cliente HTTP de Laravel para el
API Twirp `livekit.RoomService` (JSON). Nada más.

| Pieza | Qué hace |
|---|---|
| `LiveKitConfig` | lee y valida la configuración; falla cerrado; nunca muestra el secreto |
| `LiveKitTokens` | credencial de participante (una sala, una identidad, permisos mínimos), token de servicio corto, verificación oficial de webhooks |
| `LiveKitRoomClient` | `CreateRoom`, `ListParticipants`, `ListRooms`, `RemoveParticipant`, `DeleteRoom` con tiempo máximo y reintentos (solo red, 5xx y 429) |
| `LiveKitMediaRooms` | el adaptador: errores de red → `media_unavailable`; «no existe» en operaciones idempotentes no es error |
| `LiveKitWebhookEvent` + `Services/MediaEventIngestor` | webhook verificado → evento neutral → `CallService` |
| `Services/MediaSweeper` (`teamcalls:media-sweep`, cada 5 min) | red de seguridad por si se pierde un webhook |

**Estados.** La máquina de estados no cambia; se añade el estado multimedia del participante
(`media_state`), que la API devuelve en cada participante:

| Situación | Participante | `media_state` | Llamada |
|---|---|---|---|
| Invitación aceptada | `accepted` | — | `connecting` |
| Conectándose (`unirse`) | `joined` | `connecting` | `connecting` |
| Conectado de verdad (webhook `participant_joined`) | `joined` | `connected` | `active` con 2 conectados |
| Desconectado (webhook `participant_left` / `participant_connection_aborted`) | `left` | `disconnected` | sigue si quedan 2; si no, `ended` |
| Llamada finalizada | `left` | `disconnected` | `ended` (no se reactiva) |

Con LiveKit, aceptar o unirse **no** activan la llamada: solo dos conexiones confirmadas por LiveKit. Sin
conexiones reales, la llamada pasa a `failed` (`connect_timeout`). Los dobles de las fases 1 y 2
(`confirmsConnections() = false`) mantienen el comportamiento anterior.

**Seguridad de salas y credenciales.**
- La sala la decide el backend: nombre opaco `tc-<ulid>` (sin ids ni institución), una por llamada, creada
  solo por el backend (`room.auto_create=false` en el servidor). El cliente no puede enviar sala ni
  identidad (422).
- Credencial = JWT HS256 con `roomJoin` a ESA sala, identidad `u{usuario}.{dispositivo}`, fuentes
  permitidas según el medio (voz: solo micrófono), sin `roomCreate`/`roomList`/`roomAdmin`/`roomRecord`,
  vigencia 60–3600 s (300 por defecto). Se emite solo tras comprobar institución activa, usuario activo y
  con permiso, participación sin expulsión, estado de la llamada y dispositivo.
- **No son de un solo uso.** Por eso la revocación se hace cumplir en tres sitios: (1) no se emiten
  credenciales nuevas; (2) `RemoveParticipant` al expulsar; (3) si vuelve a entrar con una credencial
  todavía vigente, su `participant_joined` se rechaza y se le desconecta otra vez (y el barrido lo repite).
  Lo mismo para otro dispositivo de la misma persona, otra institución, no participantes, identidades
  ajenas o una llamada terminada (su sala se cierra).
- Webhooks: firma oficial (JWT en `Authorization`, `iss` = API key, vigencia, `sha256` del cuerpo
  ORIGINAL), límite de tamaño y de frecuencia; duplicados por id; sesiones (`sid`) para descartar eventos
  retrasados o desordenados.

**Dispositivos.** Una identidad por persona y dispositivo; el mismo token sirve en los SDK de LiveKit para
navegador, Android e iOS, así que PC↔PC, PC↔Android, PC↔iPhone, Android↔iPhone y video grupal comparten el
mismo flujo. Hoy el dispositivo web lo decide `WebDevice` (uno por sesión); los móviles necesitarán el
registro de dispositivos de CollaborationCore. Una conexión de otro dispositivo se rechaza.

**API añadida.** `POST /{call}/expulsar {user_id}` (`collab.calls.revoke`, solo quien inició) y
`GET /{call}/medios` (`collab.calls.media`: participantes reales, sin dispositivos ni identidades).
Webhook: `POST /api/teamcalls/media/livekit` (`api.teamcalls.media.livekit`).

**Servidor LiveKit (VPS, fuera de este repositorio).** `livekit-server` con `keys: {<API_KEY>: <SECRET>}`,
`room.auto_create: false`, `webhook.api_key: <API_KEY>` y `webhook.urls: [https://<panel>/api/teamcalls/media/livekit]`,
TLS en el dominio de clientes (`wss://`), puertos 7881/TCP y UDP de RTC abiertos, TURN/TLS en 443 para redes
restrictivas, y el API (7880) accesible solo desde el servidor del CRM.

**Límites de lo probado.** Todo lo anterior está probado contra un LiveKit SIMULADO (`tests/Support/FakeLiveKit`,
que también exige tokens de servicio válidos). La compatibilidad de los claims y del formato de los
webhooks con un servidor real, y el audio/video, quedan pendientes de una prueba con LiveKit operativo.

## Concurrencia
`tests/Feature/CallRealConcurrencyTest.php` lanza procesos PHP independientes (cada uno con su propia
conexión MySQL, `tests/Concurrency/worker.php`) que actúan a la vez tras una barrera de tiempo: mismos
reintentos de inicio, inicios simultáneos de la misma persona, aceptación desde varios dispositivos,
cancelar contra contestar y respuestas simultáneas en grupo. Verificado contra MySQL 8.4 local; el
inicio serializa por persona (bloqueo de su fila en `users`) para que el «ocupado» no tenga carreras.

## Nomenclatura de eventos (`call.*`)
`call.started`, `call.ringing`, `call.accepted`, `call.answered_elsewhere` (solo tiempo real),
`call.declined`, `call.missed`, `call.participant.joined`, `call.participant.left`, `call.connected`,
`call.ended` (con `data.status`: ended, declined, cancelled, missed, failed), `call.media.joined|left`
(con `applied`/`rejected`), `call.media.room_finished`, `call.participant.connecting` (unirse con LiveKit) y
`call.participant.revoked`.
Nota para el contrato: la cancelación se publica como `call.ended` con `status: cancelled`.
Eventos internos de Laravel equivalentes en `app/Events` (p. ej. `CallEnded`, `CallMissed`).

## Pruebas
`Modules/TeamCalls/tests` (Pest, dobles en `tests/Support`). Para aislarlas de otras ramas:
`DB_DATABASE=crm_mca_teamcalls_test vendor/bin/pest Modules/TeamCalls/tests`.
