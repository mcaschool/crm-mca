# TeamCalls — motor de llamadas internas (MCA Colaboración)

Fase 1: núcleo funcional. Fase 2: API web JSON segura sobre el motor. Fase 3A: adaptador LiveKit
autohospedado (sin desplegar). Fase 3B: validado con LiveKit real. Fase 3C: primera interfaz web (solo
desarrollo). Fase 3D: estabilización (pestañas, sondeo, tiempo real, sesión). Sin WebSocket, push, VPS ni apps móviles todavía.

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
| Desconectado (webhook `participant_left` / `participant_connection_aborted`) | `joined` durante el margen de reconexión; luego `left` | `disconnected` | sigue si quedan 2; si no, `ended` |
| Conexión validada por el backend | `joined` | `connected` + permisos concedidos (`UpdateParticipant`) | — |
| Llamada finalizada | `left` | `disconnected` | `ended` (no se reactiva) |

Con LiveKit, aceptar o unirse **no** activan la llamada: solo dos conexiones confirmadas por LiveKit. Sin
conexiones reales, la llamada pasa a `failed` (`connect_timeout`). Los dobles de las fases 1 y 2
(`confirmsConnections() = false`) mantienen el comportamiento anterior.

**Seguridad de salas y credenciales** (revisada en la fase 3B contra LiveKit real).
- **La sala la decide el backend.**
  - Nombre opaco `tc-<32 hex aleatorios>`: sin ids ni institución.
  - Hasta la fase 3B era un ULID contiguo al id de la llamada; el servidor real lo dejó en evidencia.
  - Una sala por llamada, creada solo por el backend (`room.auto_create=false`).
  - El cliente no puede enviar sala ni identidad (422).
- **La credencial solo permite ENTRAR.** Es un JWT HS256 con:
  - `roomJoin` a ESA sala e identidad `u{usuario}.{dispositivo}`;
  - `hidden` y sin `canSubscribe`, `canPublish` ni `canPublishData`;
  - nunca `roomCreate`, `roomList`, `roomAdmin` ni `roomRecord`;
  - vigencia de 60 a 3600 s (300 por defecto).

  Solo se emite tras comprobar institución activa, usuario activo y con permiso, participación sin
  expulsión, estado de la llamada y dispositivo.
- **Permisos concedidos por el servidor.** Al llegar el `participant_joined` firmado, el motor valida esa
  conexión: llamada viva, participante sin expulsión, mismo dispositivo, permiso vigente y sesión no
  cerrada. Solo entonces concede los medios con `UpdateParticipant`, con las fuentes según el medio (voz:
  solo micrófono).
  - Una conexión no válida se desconecta **sin haber recibido ni enviado medios**.
  - Verificado con LiveKit real: una credencial sin conceder no publica («insufficient permissions») ni
    recibe pistas, y no aparece ante los demás.
- **Revocación.** LiveKit autohospedado **no revoca credenciales**. Verificado con 1.13.10: tras
  `RemoveParticipant`, con o sin `revoke_token_ts`, la misma credencial vuelve a entrar. Además, el
  servidor entrega al cliente una credencial renovada de 10 min que incluye los permisos concedidos. Por
  eso:
  1. Expulsar (`revoke`) o retirar el acceso (`revokeAccess`, contrato para CollaborationCore) impide
     credenciales nuevas y desconecta a la persona.
  2. Si la llamada sigue con medios, se **rota la sala**: los demás pasan a una sala nueva y la vieja se
     cierra. Con `auto_create=false`, toda credencial de la sala vieja, original o renovada, es rechazada
     por LiveKit («requested room does not exist»; verificado).
  3. Finalizar la llamada cierra su sala, con el mismo efecto.
  4. Los demás reconectan pidiendo credenciales nuevas: su cliente ve `ROOM_DELETED` y la llamada sigue
     viva en la API.
- **Webhooks.**
  - Firma oficial: JWT en `Authorization` sin `Bearer`, `iss` = API key, `exp` de 5 min, y `sha256` en
    base64 estándar del cuerpo ORIGINAL.
  - Límite de tamaño y de frecuencia.
  - Duplicados descartados por `id`.
  - Sesiones (`sid`) para descartar eventos retrasados o desordenados.
- **Margen de reconexión** (`TEAMCALLS_RECONNECT_GRACE`, 20 s). Al reconectarse, LiveKit envía
  `participant_left` de la sesión vieja ANTES de `participant_joined` de la nueva (verificado). Por eso una
  desconexión del servidor de medios solo marca `media_state = disconnected`. Si la persona no vuelve en
  el margen, sale de la llamada: lo aplica la siguiente consulta o el comando de cada minuto. Salir por la
  API y expulsar siguen siendo inmediatos.
- **Riesgos residuales** (documentados, no resueltos en TeamCalls):
  1. **Credenciales al portador.** Quien copie la credencial vigente de una persona autorizada puede
     usarla mientras dure: LiveKit expulsa a la sesión original (`DUPLICATE_IDENTITY`) y la copia es
     admitida.
     - Mitigación actual: vigencia corta, entrega solo por sesión autenticada y TLS.
     - Solución: credenciales ligadas a un dispositivo verificado (registro de dispositivos de
       CollaborationCore).
  2. **Lo que ve una conexión sin admitir.** Mientras no está admitida, ve las identidades
     (`u{id}.{dispositivo}`) y la lista de pistas de los demás, no su contenido. Se puede reducir con
     identidades opacas si se considera necesario.
  3. **Retirada de acceso.** Llega solo cuando CollaborationCore llame a `revokeAccess`. Hasta entonces,
     una persona desactivada a mitad de llamada sigue dentro hasta que se reconecte, momento en que se le
     rechaza.

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

**Límites de lo probado.** Ver la fase 3B: validado contra LiveKit real local; quedan pendientes las pruebas
audiovisuales con dispositivos reales y redes distintas.

## Validación con LiveKit real (fase 3B)
LiveKit **1.13.10**, binario oficial con SHA-256 verificado, corriendo en local (solo `127.0.0.1`),
contra un CRM aislado con BD propia. Los clientes WebRTC eran reales: `livekit-client` 2.22.3 en el
navegador, con audio y video sintéticos. El procedimiento está en `tests/LiveKitDev/README.md`.

| Verificado contra el servidor real | Resultado |
|---|---|
| Crear sala (Twirp), idempotente; listar salas y participantes | OK (respuestas en snake_case) |
| Credencial: aceptada para sala creada; sala no creada → 404; firma/caducidad inválidas → 401 | OK |
| Permisos: sin conceder no publica ni recibe; `UpdateParticipant` concede; voz/video según el medio | OK |
| Videollamada 1:1 y grupal de 3 (video 320×240 recibido por cada cliente) | OK (sin cámara real) |
| Webhooks reales: firma, `id`, `createdAt`, camelCase; alterar el cuerpo invalida la firma | OK |
| Conexión → admitida → llamada activa solo con dos conexiones reales | OK |
| Expulsión: desconexión inmediata (`PARTICIPANT_REMOVED`) | OK |
| Reentrada con la credencial vigente tras expulsar (sin rotación) | **ENTRA**: LiveKit no revoca |
| Reentrada con credencial original o renovada tras rotar o finalizar | Rechazada (sala inexistente) |
| Reconexión completa / misma identidad | `left` (viejo) antes de `joined` (nuevo); con margen, la llamada sigue |
| Eventos perdidos (CRM caído): el barrido recupera las conexiones reales | OK |
| Desconexión real sin volver: tras el margen, sale y la llamada 1:1 termina | OK |

**Incompatibilidades encontradas y corregidas:**
1. **Revocación.** No existe en LiveKit autohospedado. Se resuelve con permisos concedidos por el servidor
   y rotación de sala.
2. **Orden de eventos al reconectarse.** Cortaba la llamada. Se resuelve con el margen de reconexión.
3. **Nombre de sala casi idéntico al id de la llamada.** Se cambia a un nombre aleatorio.
4. **El barrido no recuperaba conexiones cuyo evento se perdió.** Ahora las aplica y vuelve a conceder
   permisos si la concesión falló.
5. **Simulador alineado con el servidor real:**
   - `ListParticipants` de una sala inexistente responde 200 vacío;
   - permisos insuficientes responden 401 `unauthenticated`;
   - una firma inválida responde 401 en texto plano;
   - retirar o cerrar algo que no existe responde 404 `not_found`.

**Pruebas:**
- **Unitarias y simuladas:** `FakeLiveKit`.
- **Formato real:** `LiveKitRealFormatTest`, con webhooks capturados del servidor (`tests/Fixtures/livekit`).
- **Servidor real, opcionales:** `LiveKitRealServerTest`, grupo `livekit-real`.
- **Pendientes:** audiovisual con cámara y micrófono reales, redes distintas (NAT/TURN), Android e iOS, y
  calidad y latencia.

**Advertencias de Composer** (6, todas preexistentes y también en `main`; no se actualizan en esta fase):

| Paquete | Versión | Gravedad | Corregida en | Nota |
|---|---|---|---|---|
| league/commonmark | 2.10.0 | alta (DoS tablas GFM) | > 2.10.1 | transitiva de laravel/framework |
| league/commonmark | 2.10.0 | media (DisallowedRawHtml) | > 2.10.1 | ídem; se usa en `Modules/Ai` |
| livewire/livewire | 4.3.0 | media (XSS DOM) | 4.3.4 | `composer.json` fija `4.3` exacto: hay que cambiar la restricción |
| laravel/framework | 13.25.0 | baja (XSS página de depuración) | 13.30.0 | solo con `APP_DEBUG` |
| league/flysystem | 3.35.2 | baja (normalizador de rutas) | > 3.35.2 | transitiva |
| symfony/http-foundation | 7.4.16 | sin calificar (ReDoS `Cache-Control`) | 7.4.19 | transitiva |

## Interfaz web (fase 3C, solo desarrollo)
Página `GET /colaboracion/teamcalls` (`collab.calls.ui`). Usa el layout del panel (`x-app-layout`,
`x-ui.*` y tokens `--mca-*`) y no añade ninguna entrada al menú global. Existe solo con
`TEAMCALLS_UI_ENABLED=true` **y** en los entornos `local` o `testing` (`EnsureCallsUiEnabled`); en
cualquier otro caso responde 404. Así seguirá mientras falten los permisos definitivos de
CollaborationCore.

| Ruta | Nombre | Uso |
|---|---|---|
| `GET /colaboracion/teamcalls` | `collab.calls.ui` | página (pila HTML del panel: login, 2FA, institución) |
| `GET /colaboracion/teamcalls/recursos/{asset}` | `collab.calls.ui.asset` | lista cerrada: `teamcalls-ui.js`, `teamcalls-ui.css`, `livekit-client.umd.js` (versionados por contenido) |
| `GET /colaboracion/teamcalls/contactos` | `collab.calls.ui.contacts` | compañeros a los que se puede llamar (solo id y nombre; reglas de `canContact`) |
| `GET /colaboracion/teamcalls/en-curso` | `collab.calls.ui.live` | llamadas vivas de quien consulta: **sondeo temporal** de entrantes |

Todo lo demás pasa por la API existente: iniciar, aceptar, rechazar, unirse, credenciales, finalizar y
consultar. El prefijo propio evita cualquier choque con `/colaboracion/llamadas/{call}`.

**Componentes:**
- `resources/views/app.blade.php` y `resources/assets/{js,css}/teamcalls-ui.*`: JS sin compilar, sin
  tocar Vite ni `public/build`.
- SDK oficial `livekit-client` 2.22.3 (UMD verificado; `resources/assets/vendor/livekit-client`), servido
  por el módulo: sin CDN de terceros en el panel.
- Textos en `lang/{es,en}/teamcalls.php` (claves `teamcalls.ui.*`).

**Funciones:**
- Llamada de voz y videollamada individuales.
- Aviso de llamada entrante con aceptar o rechazar.
- Micrófono y cámara.
- Video local (en espejo) y remoto.
- Participantes con su estado de llamada y su estado de medios.
- Estados: llamando, conectando, esperando autorización, en llamada y reconectando.
- Colgar o cancelar.
- Historial reciente.
- Selección de micrófono, cámara y altavoz (cuando el navegador lo permite).
- Errores de permisos, dispositivo inexistente u ocupado, pista interrumpida, red, sesión y servidor de
  medios.

**Reglas de la fase 3B en el cliente:**
- **Permisos.** Entra a la sala sin medios y publica solo tras el `ParticipantPermissionsChanged` que
  confirma la concesión del backend.
- **Desconexión** (sala eliminada por rotación, expulsión, red o servidor):
  1. consulta la llamada;
  2. pide credenciales NUEVAS;
  3. entra a la sala actual con un `Room` nuevo, sin duplicar participantes;
  4. si fue expulsado o la llamada terminó, lo muestra y libera todo.
- **`DUPLICATE_IDENTITY`** (la llamada se abrió en otra pestaña): no reconecta, para no alternar entre
  pestañas.
- **Cortes breves.** Los recupera el SDK (`Reconnecting`/`Reconnected`). Al recargar la página en mitad de
  la llamada, se vuelve a entrar dentro del margen de reconexión del motor.
- **Al terminar.** Se paran y liberan todas las pistas y elementos multimedia.

**Medios sintéticos** (`?sintetico=1`, solo con `TEAMCALLS_UI_SYNTHETIC_MEDIA=true`): audio y video
generados para pruebas automatizadas sin cámara ni micrófono. Mismas reglas de permisos; desactiva
`adaptiveStream` para medir el flujo real.

### Validación con dos navegadores reales (LiveKit 1.13.10 local)
Dos sesiones independientes: Ana en `127.0.0.1:8099` y Beto en `localhost:8099`. Login real con
contraseña y 2FA TOTP, CSRF y BD aislada. Medios **sintéticos**: el navegador de pruebas no tiene
permisos de cámara ni micrófono.

| Prueba | Resultado |
|---|---|
| A inicia videollamada → B recibe el aviso | 2,3 s (sondeo 3 s) |
| B acepta → ambos con credenciales autorizadas, conectados y en llamada | 7,4 s |
| Audio y video en ambos sentidos | ~37 KB de audio y ~55 KB de video cada 3 s; fotogramas decodificados |
| Micrófono y cámara: silenciar o apagar → el otro recibe 0 bytes e indicador; reactivar → vuelve | OK |
| B recarga la página en mitad de la llamada | De vuelta en 7,7 s con credenciales nuevas; A sin duplicados |
| Rotación de sala (expulsión de un tercero) | Ambos clientes reconectan solos a la sala nueva en 4–7 s; medios recuperados |
| B cuelga | Llamada `ended`, historial actualizado en ambos, sala cerrada en LiveKit, pistas liberadas |
| Rechazo | A ve «La llamada fue rechazada», historial «Rechazada» |
| Sin respuesta (45 s) | A ve «Sin respuesta», historial «Perdida»; aceptar tarde → «La llamada ya no está disponible» |
| Permisos de cámara y micrófono denegados | Mensaje combinado; la llamada sigue y recibe al otro |

**Pendiente: prueba manual con cámara y micrófono físicos.** Y sin cubrir aún: cámara ocupada por otro
programa, dispositivo desconectado durante la llamada y redes distintas. El código los maneja, pero no se
han reproducido.

**Lecciones del entorno local** (no afectan a producción con PHP-FPM):
- `php -S` atiende de una en una: los webhooks van a otra instancia (puerto 8097) para no esperar al
  sondeo de la interfaz.
- Sin OPcache en CLI cada petición tardaba unos 2 s y la recarga superaba el margen de reconexión de 20 s.
  Con `-d zend_extension=opcache -d opcache.enable_cli=1`, unos 0,3 s.

### Para sustituir el sondeo por CollaborationRealtime
En `teamcalls-ui.js`, el objeto `events` es la única fuente de llamadas entrantes y de cambios de la
llamada en curso (`onIncoming(summary)`, `onUpdate(summary)`). CollaborationRealtime debe:
1. Autenticar la suscripción con la sesión web (y después con Sanctum) al canal privado
   `private-collab.user.{id}`.
2. Entregar los `call.*` que ya publica `CallRecorder` (`call.ringing`, `call.accepted`,
   `call.declined`, `call.missed`, `call.ended`, `call.participant.*`, `call.media.*`,
   `call.media.room_rotated`) con el `CallEnvelope` v1.
3. Sustituir `events.start()` por la suscripción: `call.ringing` → `onIncoming` (con el resumen de
   `GET /colaboracion/llamadas/{call}`), el resto → `onUpdate`. El sondeo puede quedar como respaldo si
   se pierde el canal.
4. Entregar `call.answered_elsewhere` (solo tiempo real) a los demás dispositivos de la persona, para
   cerrar su aviso de entrante.

## Estabilización de la interfaz (fase 3D)

### Pestañas de la misma sesión
Todas las pestañas de una sesión comparten dispositivo (`WebDevice`) y, por tanto, identidad en LiveKit.
`teamcalls-core.js` (`TabCoordinator`, con Web Locks y BroadcastChannel por usuario) impone:
- **Un control.** Solo una pestaña controla la llamada (`teamcalls-call-control:{usuario}`). Las demás
  muestran «Tienes una llamada en curso en otra pestaña» con **Continuar aquí** (toma explícita: avisa a la
  que la tiene, que suelta la sala y libera cámara y micrófono) y **Colgar**.
- **Un sondeo.** Solo una pestaña sondea la lista de llamadas (`teamcalls-poller:{usuario}`) y comparte el
  resultado. Si se cierra, la sustituye otra.
- **Aviso entrante.** Suena en todas; al aceptar o rechazar en una, las demás lo cierran.
- **Recargar** la pestaña que controla: se recupera sola (solo al cargar y solo si nadie más la
  controla). Cerrarla: otra pestaña puede recuperarla con «Continuar aquí» dentro del margen de
  reconexión. Nunca hay recuperación automática cruzada, que provocaría que dos pestañas se la quitasen
  mutuamente.
- **El servidor sigue siendo la autoridad.** LiveKit expulsa la conexión anterior de la misma identidad
  (`DUPLICATE_IDENTITY`) y la interfaz no reconecta en ese caso. Sin Web Locks, cada pestaña actúa y
  decide el servidor.
- **Sin credenciales fuera de la conexión.** Ni almacenamiento del navegador ni mensajes entre pestañas:
  `sanitize()` deja solo ids y estados.

### Contrato con CollaborationRealtime
La interfaz consume eventos con `window.teamcallsUi.useRealtime(adapter)` o
`window.TeamCallsRealtime = { adapter }`, cargado antes. El adaptador debe ofrecer
`adapter.subscribe({ userId, onEvent(envelope), onStatus('connected'|'disconnected') }) → unsubscribe`.

| Evento (nombre REAL de TeamCalls) | Qué hace la interfaz |
|---|---|
| `call.ringing` (`data.user_id` = destinatario) | relee la lista y muestra el aviso |
| `call.answered_elsewhere` | cierra el aviso en este dispositivo (solo tiempo real, no se guarda) |
| `call.accepted`, `call.declined`, `call.missed`, `call.ended`, `call.connected` | relee la llamada |
| `call.participant.connecting / joined / left / revoked` | relee la llamada |
| `call.media.joined / left / room_finished / room_rotated` | relee la llamada (reconexión y rotación) |

**Principios del consumo:**
- Los eventos solo disparan relecturas de la API, que es la fuente de verdad.
- Los ids repetidos se ignoran (`EventDeduper`) y las relecturas se agrupan (`Coalescer`).
- Al conectar o perder el canal se resincroniza desde la API.
- Con el canal conectado, el sondeo pasa a respaldo cada 30 s.

**Diferencias a resolver en el contrato común:**
1. **Cancelar.** No existe `call.cancelled`: la cancelación llega como `call.ended` con
   `data.status = cancelled`; los rechazos o pérdidas que cierran la llamada, igual.
2. **Perdidas.** `call.missed` es por persona (`data.user_id`), además del `call.ended` final.
3. **`call.answered_elsewhere`.** Necesita la exclusión por dispositivo (`CallEnvelope::exceptDeviceId`),
   que el `toArray()` público no incluye: el publicador debe respetar `recipients` y `exceptDeviceId`
   como metadatos de enrutado.
4. **Reconexión.** No hay un «call.reconnecting»: la señal es `call.media.left` (aplicado) y, si no vuelve
   en el margen, `call.participant.left`. La rotación es `call.media.room_rotated`.
5. **Eventos propios de TeamCalls** que el contrato debe admitir: `call.started`, `call.connected`,
   `call.participant.connecting`, `call.participant.revoked` y `call.media.*`.
6. **Sobre.** `CallEnvelope` v1: `{id, type, v, occurred_at, institution_id, actor_id, data.call_id…}`. El
   `id` (ULID) es la clave de deduplicación. Canal: `private-collab.user.{id}`.

### Sondeo temporal: coste y estrategia
Medido con `CallsPollingCostTest` (solo consultas de TeamCalls; sesión y usuario son infraestructura
global):

| Petición | Antes | Después |
|---|---|---|
| `en-curso` sin llamadas | 2 consultas (una de vencidos con subconsultas) | 1–2, sin consulta global de vencidos |
| `en-curso` con llamada | 3 consultas | ≤ 3, sin bloqueos |
| Ver la llamada (sondeo en llamada) | **transacción con 2 `FOR UPDATE`** en cada sondeo | lectura sin bloqueo; bloquea solo si hay algo vencido |

Lo vencido (invitación sin respuesta, conexión que no llega, margen de reconexión, llamada sin medios)
se sigue aplicando igual, ahora solo cuando hace falta, y el comando de cada minuto no cambia.

**Cliente (`pollDelay`):**

| Situación | Cadencia |
|---|---|
| Sin llamada | 4 s (15 s con la pestaña oculta) |
| Aviso entrante / llamando | 3 s |
| En llamada | 6 s (10 s oculta); los eventos de LiveKit fuerzan relecturas |
| Con tiempo real conectado | 30 s, solo como respaldo |
| Errores de red o 5xx | retroceso exponencial hasta 60 s, con aviso a partir de 2 fallos |
| 429 | respeta `Retry-After` |
| Sesión perdida (401, 2FA o CSRF) | deja de sondear |

Con varias pestañas sondea una sola. Al volver a una pestaña oculta se consulta enseguida.

### Seguridad (revisión de la fase)
- **CSRF y sesión.** CSRF en todas las escrituras (token de la meta). 401/419/2FA → la interfaz deja de
  consultar, cuelga localmente y **libera cámara y micrófono**.
- **Cierre de sesión.** `LeaveCallsOnLogout` (evento `Logout` del framework) saca a la persona de sus
  llamadas **en el dispositivo de esa sesión**; los demás dispositivos no se tocan. Sin sesión ya no se
  obtienen credenciales.
- **Credenciales.** Solo viven en la conexión de LiveKit: nada en `localStorage`, `sessionStorage`,
  IndexedDB, cookies ni mensajes entre pestañas (prueba estática + `sanitize`).
- **Institución y autorización.** Aislamiento institucional y autorización en todos los endpoints sin
  cambios: los aplica el motor.
- **Interfaz restringida.** Sigue limitada a `local`/`testing` con el interruptor.

### MCA One y permiso provisional
- **Parcial reutilizable.** La interfaz es `teamcalls::partials.calls`: autocontenida (estilos, SDK,
  núcleo y lógica del módulo), sin layout ni componentes del CRM. La página del CRM solo la envuelve.
- **Permiso.** El acceso a llamadas se decide en un único puerto, `UserDirectory`
  (`canUseCalls` / `canContact`). El adaptador provisional (`PanelUserDirectory`) exige usuario activo y
  `canWorkCrm()`: es **más restrictivo**, no un sustituto de `collaboration.use`, y no se ha ampliado.
- **Qué falta para MCA One:**
  1. **`UserDirectory` de CollaborationCore**, con la identidad y la autorización institucionales
     compartidas (gate `collaboration.use`), en lugar de los roles comerciales del CRM.
  2. **Pila de autenticación del API.** Hoy usa la del panel (sesión, `can:access-panel`, 2FA). Para
     otras aplicaciones hará falta un grupo de rutas con la autenticación común de MCA One (y Sanctum en
     móvil), sin depender del panel del CRM.
  3. **Registro de dispositivos de CollaborationCore**, en lugar de `WebDevice` (uno por sesión).

### Pruebas de la fase
- **Núcleo JS:** `tests/js/core.test.cjs` con `node --test`, lanzado desde Pest (`CallsUiStabilityTest`):
  dos pestañas, toma de control, relevo del sondeo, eventos repetidos, cadencia, mensajes sin
  credenciales y tiempo real.
- **Pest:**
  - coste del sondeo y ausencia de bloqueos;
  - cierre de sesión (este dispositivo sí, otros no) y credenciales tras cerrar sesión;
  - JS sin almacenamiento del navegador;
  - parcial sin el CRM;
  - interfaz deshabilitada fuera de entornos autorizados.
- **Navegador real con LiveKit 1.13.10 local y medios sintéticos:**
  - dos pestañas de la misma sesión (aviso en ambas, una sola conexión, toma explícita en 2 s con
    liberación de medios en la otra);
  - recarga de la pestaña que controla (4,4 s, sin quitársela a la otra);
  - cierre de la que controla (recuperada en 3,3 s);
  - eventos repetidos (4 eventos → 2 relecturas);
  - errores de red (retroceso hasta 24 s, aviso y recuperación en 2 s sin cortar la llamada);
  - cierre de sesión (el servidor la saca de la llamada y la pestaña libera medios en 1 s).
- **Pendiente: con cámara y micrófono físicos y con redes externas.**

## Contratos comunes de MCA Colaboración 1.0 (fase 4A)
La rama incorpora por merge `codex/collaboration-contracts` (solo interfaces, datos y suites de contrato;
ningún servicio de Core ni de Realtime). TeamCalls los **implementa o adapta**; **Core y Realtime NO
están integrados**: sus implementaciones reales no existen en esta rama, y todo lo que depende de ellas
falla cerrado o sigue con el adaptador provisional.

### `CallsGateway` común → `Integration/Collaboration/SharedCallsGateway`
Enlazado como `Modules\CollaborationCore\Contracts\CallsGateway`. Usa el `CallService` existente (mismos
bloqueos, transiciones, idempotencia y aislamiento).

| Contrato | TeamCalls |
|---|---|
| `start` | `CallService::start`; grupos/canales con **invitados elegidos** (`CallTarget::conversation(ref, selection)`), validados todos o ninguno (miembro vigente + contactable) |
| `invite` | `invite(..., commandKey)`: repetir el mismo comando no vuelve a sonar (ni a quien rechazó); misma clave con otros invitados → `idempotency_conflict` |
| `accept` / `decline` / `cancel` / `join` / `leave` | mismos métodos; el dispositivo sale del `ActorContext` |
| `end` | `terminate()`: **solo el anfitrión** finaliza para todos; cualquier otra persona → `forbidden` (nunca equivale a salir) |
| `credentials` | credenciales del dispositivo del actor; si la URL no es `https`/`wss` → `media_unavailable` |
| `find` / `activeFor` | resúmenes del contrato, sin sala, claves, estado de medios ni dispositivos (`deviceId = null`) |
| `recentFor` / `missedFor` | `historyPage()` por clave (`created_at`, `id`), cursor **opaco firmado** (HMAC con persona, institución y listado); manipulado o ajeno → `invalid` |

Errores: `CallException` → `ContractException` con el mismo código (la tabla código→HTTP del contrato
coincide); `InvalidArgumentException` → `invalid` (422).

### `ActorContext` → `ActorResolver`
- La identidad, la institución, la sesión y el dispositivo vienen del `ActorContext`, que construye Core desde la
  sesión autenticada del servidor. No se acepta ningún id del cliente.
- Se revalida con `DeviceRegistry::assertSessionBinding`. Además: la persona existe y pertenece a esa
  institución; si tiene 2FA, la sesión debe ser `Mfa`; y `CollaborationAccess::authorize(collaboration.use)`.
- **Sin `DeviceRegistry` o sin `CollaborationAccess` enlazados → `unauthenticated`.** Hoy, por tanto, la puerta
  común no admite a nadie fuera de las pruebas.
- Las reglas propias siguen en `CallService`: `UserDirectory`, institución activa y CSRF/2FA de la API web.
- Cada operación corre en la institución del actor (`CurrentInstitution::runFor`). La correlación es la del
  comando y la causalidad, el `requestId` (`OperationContext`, uno por petición).

### Eventos `call.*` (compatibles con CollaborationRealtime)
- **Versión del agregado.** `collab_call_calls.event_sequence` es el contador por llamada. Cada hecho
  (y cada aviso efímero) toma la versión siguiente con la fila de la llamada bloqueada, así que el orden
  por agregado no tiene carreras.
- **Traza.** `collab_call_events` guarda `sequence`, `correlation_id` y `causation_id` (único `call_id + sequence`).
  Las marcas internas de comandos (`call.command.invite`) no ocupan versión ni se publican.
- **Con `TransactionalEventRecorder` enlazado** (el outbox de Realtime):
  - `CallRecorder` anota `EventEnvelope` + `DeliveryAudience` en **la misma transacción** del cambio. Si no
    hay transacción, lanza una excepción.
  - No publica por ningún otro camino, así que no hay duplicados ni pérdidas.
  - No hay un outbox propio.
- **Sin outbox (hoy):** el puerto propio tras confirmar (`NoopCallEventPublisher`); la interfaz sigue sondeando.
- **Datos públicos** (`CallEventEnvelopes::PUBLIC_DATA`): `call_id`, `user_id`, `media`, `scope`, `status`,
  `reason`, `applied`, `rejected` y `participants`. Ni dispositivos ni sesiones de medios.
- **Audiencia:** participantes; el timbre solo a quien suena, con `expiresAt` = fin de la invitación;
  `call.answered_elsewhere` (efímero) excluye el dispositivo que contestó.
- **Cancelación:** `call.ended` con `data.status = cancelled` (el sobre común rechaza `call.cancelled`).
- **`CallEventAccess`:** regla de autorización lista (tipo de TeamCalls, misma institución, participante no
  expulsado). **No está registrada**: el contrato 1.0 aún no publica la interfaz de políticas de eventos.

### Adaptadores preparados (inactivos sin Core)
| Adaptador | Se activa cuando |
|---|---|
| `ConversationDirectoryAccess` (`ConversationAccess` sobre `ConversationDirectory`) | Core enlaza `ConversationDirectory`. Pregunta en nombre del actor de la operación; **sin actor (API web interna, tareas) deniega todo**. No lista miembros: exige selección |
| `CollaborationPresence` (`Presence` → `PresenceService::setInCall/clearInCall`) | Core enlaza `PresenceService`; se aplica tras confirmar |
| `CollaborationUserDirectory` (gate `collaboration.use`, sin exigir CRM) | `TEAMCALLS_AUTHORIZATION_DRIVER=collaboration`; mientras Core no defina el gate, **deniega a todos**. Por defecto sigue el provisional |
| `CallAccessRevocations` | Core/TeamChat lo invoquen (ver abajo) |

**Permiso definitivo.** El permiso es `collaboration.use`. No se ha abierto ninguna ruta ni se ha ampliado
ningún permiso: la API web y la interfaz siguen con la pila del panel y `PanelUserDirectory`.

### Revocación: puntos de integración
| Origen (Core / TeamChat) | Entrada en TeamCalls | Efecto |
|---|---|---|
| Usuario revocado | `CallAccessRevocations::userRevoked` → `revokeAccess` | sale de todas sus llamadas vivas, se le retiran los medios y se rota la sala |
| Pérdida de `collaboration.use` | `collaborationAccessLost` → `revokeAccess` | igual que el anterior, motivo `access_revoked` |
| Dispositivo revocado | `deviceRevoked` → `revokeDevice` | sale con **ese** dispositivo (`call.participant.revoked`, `device_revoked`); rota la sala si hay medios confirmados; puede volver desde otro dispositivo válido |
| Baja de la conversación | `membershipRemoved` → `revokeMembership` | expulsión de las llamadas vivas de esa conversación |
| Expulsión de participante | `expel()` interno (ya existente) | `revoked_at` + retirada de la sala |
| Rotación de sala LiveKit | `rotateRoom()` (fase 3B) | las credenciales anteriores dejan de servir |

Además, la puerta común revalida dispositivo y sesión en cada llamada (`assertSessionBinding`). Siguen
vigentes los mecanismos actuales: logout (`LeaveCallsOnLogout`), webhooks y barrido de medios.

### Credenciales `https`/`wss` y desarrollo
- `LiveKitConfig` exige `https`/`wss` **en todo entorno**. Antes solo lo hacía en producción, así que el
  cambio endurece la configuración.
- **Excepción solo de desarrollo:** `TEAMCALLS_LIVEKIT_ALLOW_INSECURE_DEV_URLS=true`, que solo surte efecto
  en `local`/`testing`.
- Aun con la excepción, la puerta común **no** devuelve credenciales `ws://`: responde `media_unavailable`.

### Pruebas de la fase
- `tests/Feature/Contracts/SharedCallsGatewayConformanceTest.php`: **la suite oficial**
  `CallsGatewayContract` contra el adaptador real, con un doble de las interfaces oficiales de Core
  (`FakeCollaborationCore`).
- `SharedGatewayTest`: fallo cerrado, identidad, ciclo, `end`, `invite`, cursores, selección, credenciales y errores.
- `CallEventsContractTest`: outbox transaccional (con doble `FakeOutbox`), reversión, versión contigua,
  correlación, audiencia, datos públicos y autorización de eventos.
- `CollaborationAdaptersTest`: enlaces, `collaboration.use`, directorio, revocaciones, presencia y la
  excepción de desarrollo de LiveKit.

## Integración funcional (fase 4B)
Con CollaborationCore, CollaborationRealtime y TeamChat **reales** (rama `integration/collaboration-4b`), los
adaptadores de la fase 4A están activos:
- identidad, dispositivo y `collaboration.use` de Core en todas las rutas de llamadas;
- API definitiva `/api/v1/collaboration/calls`;
- outbox, políticas `call.*` y `SyncFeed` de Realtime;
- revocaciones durables consumidas por TeamCalls;
- presencia de Core;
- llamadas desde TeamChat;
- adaptador de tiempo real en la interfaz.

`WebDevice` y `LeaveCallsOnLogout` se retiraron. Detalle, pruebas (reales y simuladas), bloqueos y pendientes de producción:
[docs/TEAMCALLS-PHASE-4B.md](docs/TEAMCALLS-PHASE-4B.md).

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
`call.participant.revoked` (con `reason`) y `call.media.room_rotated`.
Nota para el contrato: la cancelación se publica como `call.ended` con `status: cancelled`.
Eventos internos de Laravel equivalentes en `app/Events` (p. ej. `CallEnded`, `CallMissed`).

## Pruebas
`Modules/TeamCalls/tests` (Pest, dobles en `tests/Support`). Para aislarlas de otras ramas:
`DB_DATABASE=crm_mca_teamcalls_test vendor/bin/pest Modules/TeamCalls/tests`.
