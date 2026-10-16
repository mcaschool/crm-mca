# TeamCalls · Fase 4B — Integración funcional con CollaborationCore, CollaborationRealtime y TeamChat

Rama local `integration/collaboration-4b` · worktree `CRM-MCA-integration-4b` · sin push ni merge a `main`.

## 1. Integración Git
| Paso | Resultado |
|---|---|
| Base | `feature/collaboration-teamcalls` `315e42d` (TeamCalls) |
| Incorporado | `588f8ce2cb560d8145184dc00f235767b01f6358` (Codex, `feature/collaboration-teamchat`; HEAD exacto, sin commits posteriores) |
| Merge | `256c950`, local `--no-ff` |
| Conflictos | `modules_statuses.json` (unión de módulos) y `composer.lock` (el de Codex con `laravel/reverb` + solo `firebase/php-jwt` v7.2.1) |
| Fusión automática revisada | `composer.json`, `tests/Pest.php` |
| Commits de integración | `c9e6439` (adaptación funcional) y `cac12d9` (pruebas) |

`composer audit`: 6 avisos en `laravel/framework`, `league/commonmark`, `league/flysystem`, `livewire/livewire` y
`symfony/http-foundation`. Son las **mismas versiones que `main`**: la integración no los introduce.

## 2. Contratos 1.1.0 conectados (implementaciones REALES)
| Contrato | Uso en TeamCalls |
|---|---|
| `ActorContextFactory` + `CollaborationRequest` | API definitiva; `RequireCollaborationActor` lo reutiliza en la API web heredada y en la página |
| `DeviceRegistry` | El dispositivo es el de Core (`ActorDevice`); `assertSessionBinding` en cada operación (`ActorResolver`) |
| `CollaborationAccess` / gate `collaboration.use` | `ActorResolver` y `CollaborationUserDirectory` (*driver* por defecto `collaboration`) |
| `ConversationDirectory` (TeamChat) | `ConversationDirectoryAccess`: directo con `eligibleInvitees(actor, ref, [])`; grupos y canales con selección explícita |
| `CallsGateway` | `SharedCallsGateway` y API `/api/v1/collaboration/calls` |
| `PresenceService` + `CallPresenceCleanupAuthorizer` | `CollaborationPresence` y `TeamCallsPresenceAuthorizer` (activa en `set`, histórica en `cleanup`), enlazado en `boot` |
| `TransactionalEventRecorder` + `EventPolicy` | `CallRecorder` escribe en el outbox real dentro de la transacción; `CallEventPolicy` registrada para los 16 `call.*` |
| `RealtimePublisher` / `SyncFeed` | Los de Realtime, sin publicador paralelo. La interfaz usa `teamcalls-realtime.js`, que es cliente del sistema común |
| `RevocationRecorder` / `RevocationConsumer` | `TeamCallsRevocationConsumer` consume `collaboration.access.revoked`, `collaboration.device.revoked` y `conversation.membership.revoked` |

**Provisionales retirados:** `WebDevice`, `LeaveCallsOnLogout` (el cierre de sesión lo revoca Core) y `CallEventAccess`.

**Se conservan solo como vuelta atrás explícita:** `PanelUserDirectory` (*driver* `provisional`), `DenyAllConversationAccess` y
`NoopPresence`, que se usan únicamente si Core no está instalado.

## 3. Comportamiento resultante
- **Permiso:** `collaboration.use`. Una persona sin rol del CRM usa llamadas y el CRM le sigue cerrado (403).
  - Con la excepción temporal de 2FA del panel, Colaboración exige MFA igualmente.
  - En modo multiinstitución, Colaboración opera siempre en la institución **propia** del usuario.
- **Finalizar:** solo el anfitrión finaliza para todos; el resto recibe `forbidden` y sale con `leave`.
  - La interfaz cuelga con `end` si eres anfitrión y con `leave` si no.
  - «Colgar en otro dispositivo» usa `leave` con `all_devices`.
- **Idempotencia:** `CallService::command()` cubre `accept`, `decline`, `cancel`, `join`, `leave` y `end`.
  - Alcance: actor + operación + llamada; huella calculada en el servidor.
  - Repetir revalida la autorización y no repite el efecto; misma clave con otra petición → 409.
- **Revocación:**
  - Core la anota de forma durable en la misma transacción que el cambio, y el worker la entrega al consumidor dentro
    de su transacción, con recibo.
  - TeamCalls aplica en esa transacción salida o expulsión, `call.participant.revoked` y rotación de sala.
  - LiveKit se toca **después** de confirmar (`afterCommit`); si falla, el estado persistido es la intención durable y
    `teamcalls:media-sweep` la reintenta.
  - Eventos retrasados: solo afectan a admisiones anteriores a `occurred_at`. Si la persona sigue sin acceso, todas.
- **Eventos:** el agregado `call` con versión contigua, correlación y causalidad, audiencia de participantes.
  - El timbre va solo a quien suena; `answered_elsewhere` es efímero, con 60 s de caducidad y excluye el dispositivo
    que contestó.
  - `call.ended` con `status: cancelled` para las cancelaciones.

## 4. Pruebas
**Suite completa del árbol integrado** (CRM y los cuatro módulos de colaboración, MySQL, BD aislada):
**1754 superadas, 16 fallidas, 3 omitidas** (2988 s).
- Las 16 fallidas: 15 de Social (bloqueo 5.1, ya presente en el merge antes de tocar TeamCalls) y 1 de Mcp,
  `crm_git_status`, que es del entorno (en un worktree `.git` es un archivo, y también falla en `main`).
- Las 3 omitidas son pruebas contra un LiveKit real que exigen variables `TEAMCALLS_LIVEKIT_REAL_*`; se validó con
  LiveKit real en la E2E.
- Contratos 1.1.0: 30/30.
- PHPStan sin errores: TeamCalls, contratos, collaboration, realtime y teamchat.
- Pint: correcto en todo lo modificado.
- JavaScript: 13/13.

### Con servicios reales (MySQL, Core, Realtime y TeamChat reales)
- **`tests/Feature/Integration4B/RealServicesTest.php`:**
  - persona solo de Colaboración; sin MFA o sin `collaboration.use`;
  - dispositivos por sesión y `answered_elsewhere`;
  - outbox y `SyncFeed`, con *rollback*;
  - revocación de dispositivo con rotación y otros que siguen;
  - retirada de `collaboration.use`; fallo temporal del consumidor y recuperación;
  - presencia real sin tocar el estado manual;
  - llamada desde chat directo y salida del chat;
  - grupo con selección explícita, baja y revocación retrasada;
  - aislamiento institucional; idempotencia con dispositivos reales.
- **`RealCoreConformanceTest`:** la suite oficial `CallsGatewayContract` con el Core real.
- **`tests/Integration4B/worker-delivery.php`** (fuera de la transacción de prueba): 15 comprobaciones del worker real:
  - avisos solo a participantes, nunca a ajenos ni a revocados, y sin reenvíos;
  - revocación entregada al consumidor;
  - reintento tras fallo; *lease* perdido sin efectos duplicados;
  - sala rotada y la anterior cerrada.
- **`tests/Integration4B/reverb-calls.php`:** Reverb real y WebSocket real, 5 comprobaciones:
  - canal privado firmado;
  - aviso al invitado y no al ajeno;
  - sync con `call.ringing`;
  - el dispositivo revocado no recibe avisos.

### E2E con navegador real (LiveKit 1.13.10 local, Reverb, worker y dos sesiones con login y TOTP)
- Dos personas **solo de Colaboración**: el menú solo muestra Colaboración y el CRM devuelve 403.
- La interfaz conecta con Reverb mediante el adaptador (`realtime: true`).
- Llamada de vídeo:
  - el aviso llega por `collaboration.sync.required` → `sync` con `call.started`, `call.ringing`… (versiones 1→4);
  - aceptar desde la interfaz da medios reales en ambos sentidos (fotogramas y bytes medidos).
- Recargar en mitad de la llamada: vuelve a entrar y LiveKit mantiene **2** participantes, sin duplicados.
- Presencia `in_call` de Core para ambas personas.
- Cerrar sesión en mitad de la llamada:
  - `collaboration.device.revoked (logout)` → worker → consumidor (1 recibo) → `call.participant.revoked` → llamada
    terminada;
  - LiveKit queda sin salas y la presencia, limpia;
  - la otra parte ve terminar la llamada y quien salió queda con 401.
- API definitiva desde la sesión real:
  - TeamChat crea el chat directo y la llamada `conversation: direct` resuelve al otro miembro (201);
  - la repetición con la misma clave da la misma llamada; cancelar funciona.

### Solo con dobles (no son pruebas de servicio real)
- El adaptador JS de tiempo real con `fetch` y `WebSocket` simulados (`tests/js/realtime.test.cjs`). Su uso real sí se
  comprobó en el E2E.
- Las pruebas del motor con `TC::fakes()`: los `call.*` van a un outbox de prueba y el resto, al recorder real.

### No probado
- LiveKit con **TLS** local: la E2E usó `ws://` con la excepción explícita de desarrollo, a través de la API heredada.
  La API definitiva devuelve `media_unavailable` con `ws://`, por diseño.
- Tres o más navegadores reales en la misma llamada: los «otros siguen» se probaron con servicios reales y worker, no
  con navegador.
- Cámara y micrófono físicos, redes externas, móvil (FCM/APNs) y carga sostenida.

## 5. Bloqueos y avisos para Codex (no modificados unilateralmente)
1. **Regresión de Social** (15 pruebas; en `main` pasan y en el merge `256c950` fallan, antes de cualquier cambio de
   TeamCalls):
   - **Causa:** `CurrentInstitution` pasó de `singleton` a `scoped`. `SocialIngestService` llama a `runGlobally()`
     sobre una instancia anterior a la renovación que Laravel hace al procesar un trabajo (cola `sync`), mientras
     `InstitutionScope` lee la nueva → `MissingInstitutionContextException` en `/api/social/webhook/*`.
   - **Riesgo:** el mismo patrón en *workers* de cola.
   - **Corrección:** decisión de Core (Codex) junto con quien mantiene Social.
2. **Archivos de prueba de Codex ajustados en la rama de integración** (necesitan su visto bueno):
   - `tests/RealtimeFixtures.php`: registro de políticas propio de la prueba, porque TeamCalls registra
     `call.ended` como dueño de `call.*`;
   - `Modules/TeamChat/tests/Feature/Compatibility4BTest.php`: retira el consumidor de TeamCalls para el caso «sin
     consumidor».
3. **Observación de rendimiento:** `DatabaseDeviceRegistry::register()` bloquea la fila `users` en **cada** petición
   autenticada. Con el sondeo de la interfaz (≤ 4 s por pestaña) es un bloqueo por petición.
4. **Sin cliente común de tiempo real** en el navegador: el de TeamChat es interno a su interfaz. TeamCalls usa
   `teamcalls-realtime.js` con el mismo protocolo; conviene extraer uno común.

## 6. Cambios a trasladar a las ramas de desarrollo
- **TeamCalls** (`feature/collaboration-teamcalls`): todo `Modules/TeamCalls` de `c9e6439` y `cac12d9`, más
  `.env.example` (`TEAMCALLS_AUTHORIZATION_DRIVER=collaboration`).
- **Compartido:** `tests/Feature/Localization/InterfaceMessagesTest.php` (reconoce claves de módulo y exige que
  existan en inglés).
- **Codex:** los dos ajustes de pruebas del punto 5.2 y la corrección del punto 5.1.

## 7. Requisitos pendientes para producción
- **Infraestructura:**
  - LiveKit autohospedado con TLS (`wss`/`https`) en un VPS, no en el hosting compartido;
  - Reverb con TLS;
  - *worker* del outbox (`collaboration:realtime drain` o `DrainOutbox`) y *scheduler* de
    `teamcalls:expire-stale`, `teamcalls:media-sweep` y `collaboration:prune`.
- **Migraciones** de Core, Realtime, TeamChat y TeamCalls, ensayadas sobre una copia, con revisión de las
  revocaciones `failed` anteriores al consumidor.
- **Correcciones previas:** la regresión de Social (5.1) y la validación de los ajustes de prueba (5.2).
- **Pruebas pendientes:** cámara y micrófono físicos, redes reales (TURN), más de dos navegadores, carga y móvil.
- **Retirada de lo heredado:** la API web heredada y el interruptor `TEAMCALLS_UI_ENABLED`, que sigue limitado a
  `local`/`testing`, cuando la interfaz migre a la API definitiva.
