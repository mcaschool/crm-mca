# TeamCalls · Especificación de integración para la Fase 4B

**Estado:** especificación (Fase 4A.1). No se ha modificado código, ni migraciones, ni contratos, ni ramas ajenas.

**Bases verificadas por lectura (`git show`, `git merge-tree`):**

| Rama | Commit | Contenido |
|---|---|---|
| `feature/collaboration-teamcalls` | `a8b3546` | TeamCalls hasta la fase 4A, sobre los contratos `ef74cd9` |
| `feature/collaboration-teamchat` | `c74b8d0` | `ef74cd9` + Core `b64636d` + Realtime `73d1ab9` + TeamChat `c74b8d0` |
| `codex/collaboration-contracts` | `ef74cd9` | contratos 1.0.0 |
| `main` | `835dc11` | antecesor común de las dos ramas funcionales |

- Base común de las ramas funcionales: `ef74cd9`.
- La rama de TeamChat **no modifica** ningún archivo de contrato (`Contracts`, `Data`, `Enums`, `Exceptions`, `Support`,
  `Authorization`, `tests/Contracts` ni el documento de contratos).
- Los worktrees de TeamChat y de contratos están limpios. `main` tiene 2 cambios locales ajenos, que no se tocan.

**Reglas que respeta toda la especificación:**
- TeamCalls es la única autoridad sobre llamadas y participantes.
- Core se encarga de identidad, autorización, dispositivos y presencia; Realtime, del outbox, la distribución y la
  sincronización.
- Dependencias permitidas (sin ciclos): TeamCalls → contratos, Core y Realtime; Realtime → contratos de Core;
  TeamChat → Core y Realtime. Ni Core, ni Realtime, ni TeamChat dependen de TeamCalls.

---

## 1. Contratos aprobados sin cambios

### 1.1 Registro y autorización de los eventos `call.*` en Realtime
**Interfaces que se reutilizan (ya implementadas en `73d1ab9`):**
- `Modules\CollaborationRealtime\Integration\EventPolicy`:
  - `validate(EventEnvelope $event): void`
  - `allows(ActorContext $actor, EventEnvelope $event): bool`
  - `ephemeral(): bool`
- `Modules\CollaborationRealtime\Services\EventPolicies::register(string $type, EventPolicy $policy): void`, que es
  un *singleton*; TeamChat lo usa igual.
- `TransactionalEventRecorder::append(InstitutionContext, EventEnvelope, DeliveryAudience): string`, que
  `CallRecorder` ya consume.

**Adaptador de TeamCalls:** `CallEventPolicy`, que sustituye a `CallEventAccess`. Hay dos instancias:
- una duradera, para los 15 tipos;
- una efímera, para `call.answered_elsewhere`.

Se registra en `TeamCallsServiceProvider::boot()` para cada clave de `CallEventEnvelopes::TYPES`, solo si
`EventPolicies` está enlazado.

**Qué hace cada método:**
- `validate`:
  - `version` = 1 y `aggregateType` = `call`;
  - `data.call_id` coincide con `aggregateId`;
  - el esquema de `data` está cerrado por tipo (subconjunto de `PUBLIC_DATA`) y los tipos de cada campo son estrictos.
- `allows`:
  - misma institución;
  - el actor es participante de la llamada y no ha sido expulsado (`revoked_at` nulo);
  - en llamadas de conversación, `ConversationDirectory::membership(actor, ref, actor.userId)->active`
    (contrato §CallsGateway: revalidar la membresía).

**Requisitos del recorder real que TeamCalls debe cumplir en 4B** (cambios solo en TeamCalls):
1. **Avisos efímeros:** `expiresAt` como máximo a `now + 5 min`. Hoy `notify()` lo envía nulo; se fijará a `now + 60 s`.
2. **`exceptDeviceId`:** debe existir en `collab_devices`. Por eso el punto 1.7 (dispositivos de Core) debe
   llegar **antes** de ejecutar suites con el recorder real.
3. **Políticas:** todo tipo emitido necesita una política registrada; sin ella `EventPolicies::get` lanza
   `LogicException` y la operación de llamada se revierte entera.
4. **Contenido:** sin claves sensibles (`token`, `session_id`…); la lista blanca actual ya lo cumple.
   Audiencia de 100 personas como máximo; TeamCalls permite 25.

**Pruebas:**
- **Unitarias de la política:** acepta el *payload* real de cada tipo y rechaza campos extra, `call_id` distinto,
  versión distinta y agregado distinto; `ephemeral()` es correcto por tipo.
- **De integración, con el `DatabaseEventRecorder` real:**
  - `start`, `accept`, `join` y `end` dejan filas en `collab_events` con `aggregate_version` contiguo;
  - un *rollback* no deja filas;
  - repetir el mismo id con el mismo contenido no hace nada, y con contenido distinto da conflicto;
  - el aviso efímero lleva su caducidad;
  - no hay publicación por `CallEventPublisher`, así que no se duplica.
- **`SyncFeed`:** un participante ve los eventos y alguien ajeno o expulsado no.
- **`OutboxWorker`:** publica una sola vez.

### 1.2 `JoinCredentials` con LiveKit local
- **Contrato:** sin cambios. Exige `https`/`wss` y TeamCalls ya lo aplica en dos capas: `LiveKitConfig`
  (todo entorno) y `SharedSummaries::credentials` (`media_unavailable` si no es seguro).
- **Desarrollo local:** LiveKit detrás de TLS local, por ejemplo un proxy inverso con certificado de `mkcert` o
  `tls internal` de Caddy para `localhost`, con `wss://localhost:<puerto>` y `https://localhost:<puerto>`. Así la
  puerta común funciona en local sin rebajar el DTO.
- **Excepción de desarrollo:** `TEAMCALLS_LIVEKIT_ALLOW_INSECURE_DEV_URLS` (solo `local`/`testing`) queda únicamente
  para la API web interna y `LiveKitRealServerTest`, hasta que la interfaz migre a la API común (1.7).
- **Pruebas:**
  - existentes: `ws://` → `media_unavailable`, y producción con la excepción → configuración incompleta;
  - nueva: con TLS local, la credencial `wss` se valida contra el LiveKit real (`/rtc/validate`) y se conecta un
    navegador real.

### 1.3 Idempotencia de todos los comandos del ciclo de vida
- **Se reutilizan:**
  - `CommandMetadata(idempotencyKey, correlationId)` y `ContractException('idempotency_conflict', 409)`;
  - la regla del documento de contratos: alcance institución + actor + operación + recurso, huella calculada en el
    servidor, y repetir siempre revalida la autorización.
- **Contrato:** sin cambios.
- **Responsable:** TeamCalls.

**Implementación** (mecanismo de `invite` de la fase 4A, generalizado):
- **Comandos:** `accept`, `decline`, `cancel`, `join`, `leave` y `end`.
- **Marca:** `CallRecorder::remember()` en `collab_call_events`, con
  `dedupe_key = 'cmd:{op}:'.substr(sha256(actorId|op|key), 0, 48)`. El índice único `(call_id, dedupe_key)` ya
  existe, así que **no hace falta migración**.
- **Huella:** `sha256` de `op | callId | deviceId` (más los invitados, en `invite`).
- **Cuándo se escribe:** dentro de la misma transacción bloqueada que el efecto. Un comando que falla no deja marca.
- **Repetición:** primero `ActorResolver` y la comprobación de participante; después se devuelve el resumen
  actual **sin volver a ejecutar** el comando.
  - Ejemplo: repetir `join` tras un `leave` no vuelve a unir.
- **Conflicto:** mismo alcance con otra huella → 409.
- **Credenciales:** nunca se reproducen en una repetición.
- **`start`:** conserva su mecanismo actual (`idempotency_key` + `request_hash`).
- **Pruebas:** por comando, la repetición devuelve el mismo efecto y la misma clave con otro dispositivo da 409.
  Además:
  - una repetición tras perder el permiso o revocar el dispositivo es denegada;
  - repetir `decline` y después `accept` con claves distintas cumple las reglas de estados;
  - concurrencia real (procesos independientes, `CallRealConcurrencyTest`) con la misma clave.

### 1.4 Semántica de `collaboration.calls.end`
- **Se reutiliza:** `Capabilities::END_CALL = 'collaboration.calls.end'` y
  `CollaborationAccess::authorize(ActorContext, string)`.
- **Contrato:** sin cambios.
- **En 4B:**
  - `end` sigue siendo **solo del anfitrión** (`initiator_id`) y exige que sea participante;
  - TeamCalls **no consulta** `END_CALL`. Core hoy la deniega siempre (`AccessService` solo admite
    `collaboration.use`), y consultarla cambiaría el comportamiento en silencio el día que Core la conceda.
- **Fase futura** (requiere decisión de negocio): finalizar como **moderación**. Exigirá:
  - `authorize(actor, END_CALL)` en la misma institución;
  - en llamadas de conversación, `ConversationDirectory::authorize(actor, ref, View)`;
  - un `end_reason` distinto (`ended_by_moderator`).
  
  La concesión la implementará Core en `AccessService`/`ApplicationAccess`.
- **Pruebas:** quien no es anfitrión recibe `forbidden` y sigue dentro (ya existe), también con Core real y aunque
  `END_CALL` llegara a concederse.

### 1.5 `ActorContextFactory` y `DeviceRegistry` en lugar de los identificadores provisionales
- **Se reutiliza:**
  - `ActorContextFactory::fromAuthenticatedRequest(Request): ActorContext`, implementado por `ActorFactory`. Registra
    el dispositivo web por sesión y vuelve a comprobar el enlace;
  - el *middleware* de Core `CollaborationRequest`, que deja el actor en el atributo `collaboration.actor` y ejecuta
    en la institución del actor;
  - `DeviceRegistry::assertSessionBinding`.
- **Contrato:** sin cambios.
- **Responsable:** TeamCalls.
- **Cambios en TeamCalls:**
  1. **Rutas nuevas** `/api/v1/collaboration/calls…` con la pila `['web', 'auth', CollaborationRequest::class,
     throttle]` (CSRF por `web`; el 2FA lo exige `ContextFactory`). Los controladores toman el `ActorContext` del
     atributo y llaman a `SharedCallsGateway`.
  2. **`WebDevice` desaparece** de las rutas nuevas: el dispositivo es `actor->deviceId`, un ULID de
     `collab_devices`. El cliente no envía ningún identificador.
  3. **`teamcalls.authorization.driver = collaboration`** pasa a ser el valor por defecto en la rama de
     integración, en lugar de `PanelUserDirectory`/`canWorkCrm`, que con Core pasa a significar «acceso al CRM».
  4. **API web interna y página de la interfaz:** se mantienen con su pila actual hasta migrar la interfaz.
     Después se retiran junto con `WebDevice` y `LeaveCallsOnLogout`, que lo sustituye 2.1 (`logout`).
- **Pruebas:**
  - un `device_id` del cliente se ignora o rechaza;
  - un dispositivo revocado o una sesión cerrada dan 403/401 y no obtienen credenciales;
  - dos sesiones del mismo usuario son dos dispositivos;
  - aislamiento entre instituciones;
  - CSRF da 419;
  - sin 2FA → `two_factor_required`.

---

## 2. Modificaciones mínimas imprescindibles

### 2.1 Avisos de revocación: tres eventos de dominio aditivos
- **Problema.** Core revoca con `Revocation::user`/`::session`, `DatabaseDeviceRegistry::revoke` y
  `ApplicationAccess::set`, y TeamChat con `ChatCommands::membership(..., false)`. Ninguno avisa a otros módulos
  (el `teamchat.membership.changed` va solo al outbox, hacia clientes).
- **Qué cubre TeamCalls sin aviso:** revalida en cada operación, así que bloquea credenciales nuevas.
- **Qué no cubre:** **no puede expulsar** a quien ya está conectado. El contrato lo exige (§CallsGateway:
  «Revocar membresía… debe iniciar expulsión multimedia»; §medios: LiveKit no revoca sus tokens).

**Diferencia exacta.** Tres clases nuevas, `final readonly`, validadas con `Guard`, en el espacio común
`Modules\CollaborationCore\Events`. Así TeamCalls no depende de servicios de Core ni de TeamChat. Es un cambio
compatible del paquete de contratos (1.0.0 → 1.1.0); ninguna firma existente cambia.

| Clase | Campos | Emisor |
|---|---|---|
| `CollaborationAccessRevoked` | `int $institutionId`, `int $userId`, `string $reason` ∈ {`user_inactive`, `institution_changed`, `credentials_changed`, `application_revoked`, `institution_disabled`} | Core: `Revocation::user` (desde `User::updated`, `Institution::updated` y `ApplicationAccess::set`) |
| `CollaborationDeviceRevoked` | `int $institutionId`, `int $userId`, `string $deviceId`, `string $reason` ∈ {`device_revoked`, `session_ended`, `logout`} | Core: `Revocation::session`, una vez por dispositivo (cubre `logout` y `DatabaseDeviceRegistry::revoke`) |
| `ConversationMembershipRevoked` | `int $institutionId`, `ConversationRef $conversation`, `int $userId` | TeamChat: `ChatCommands::membership` con `active = false` |

**Reglas de emisión:**
- `DB::afterCommit(fn () => event(...))`: una revocación revertida no expulsa a nadie; sin transacción, se emite al
  momento.
- Si cambia `institution_id`, `$institutionId` es la institución **anterior**, porque las llamadas viven allí.

**Consumo en TeamCalls:**
- Escuchas síncronas hacia `CallAccessRevocations`:
  - `userRevoked` y `collaborationAccessLost`: expulsión, retirada de medios y rotación de sala;
  - `deviceRevoked`: sale ese dispositivo, con rotación;
  - `membershipRemoved`.
- Las escuchas registran el fallo y no lo propagan: no deben romper la revocación de Core.
- **Red de seguridad:** `teamcalls:media-sweep` (cada 5 min) ya recalcula `mayBeInRoom` con
  `UserDirectory::canUseCalls` (gate `collaboration.use`).
- **Riesgo residual documentado:** por dispositivo no hay barrido, porque el contrato no ofrece consultar un
  dispositivo sin su `ActorContext`.

**Pruebas:**
- **Core y TeamChat:** cada emisor emite una sola vez después de confirmar y nada si se revierte.
- **Integración**, cada caso sobre una llamada viva con `FakeLiveKit`:
  - desactivar a la persona o cambiarla de institución;
  - `ApplicationAccess::set(collaboration, false)`;
  - `collaboration_enabled = false` en la institución;
  - `DELETE /api/v1/collaboration/devices/{id}`;
  - cerrar sesión;
  - dejar la conversación directa.
  
  En todos: expulsión o salida correcta, sala rotada, credenciales denegadas, el otro dispositivo de la misma
  persona puede seguir o volver, y repetir no tiene efecto.

### 2.2 Presencia: no autorizar el borrado de la señal «en llamada»
- **Se reutiliza:** `Modules\CollaborationCore\Integration\CallPresenceAuthorizer::authorize(InstitutionContext
  $context, int $userId, string $callId): void`, que ya existe con `UnavailableCallPresenceAuthorizer` (deniega todo).
- **Problema.** `InstitutionPresence::updateCall` autoriza también `clearInCall`. TeamCalls limpia la presencia
  *después* de que la persona salió o fue expulsada, cuando ya no está activa en la llamada; el borrado fallaría y
  quedaría «en llamada» hasta el TTL (máx. 3600 s).
- **Diferencia exacta** (solo implementación de Core, sin cambio de contrato), en `updateCall`:

  `$this->calls->authorize($context, $userId, $callId);`
  → `if ($ttlSeconds !== null) { $this->calls->authorize($context, $userId, $callId); }`

  Borrar solo reduce información de esa institución, persona y llamada, y no concede nada.
- **Adaptador de TeamCalls:** `TeamCallsPresenceAuthorizer`.
  - Ejecuta en la institución (`runFor`). Exige que la llamada exista en ella y siga viva, y que la persona sea
    participante, sin `revoked_at` y en estado `accepted` o `joined`.
  - En caso contrario lanza `ContractException('forbidden', 403)`, con el mismo código para «no existe» y «no
    activo».
  - Se enlaza en `TeamCallsServiceProvider::boot()`. Así gana de forma determinista al `bind()` de Core en
    `register()`: ambos módulos tienen prioridad 0 y el orden de registro no está garantizado.
- **Cambio en `CollaborationPresence`:** captura `ContractException` tras el *commit* y la registra. La presencia
  nunca rompe una llamada.
- **Pruebas:**
  - **Unitarias del autorizador:** participante activo admitido; quien salió, fue expulsado, es de otra
    institución o la llamada no existe, denegado.
  - **Integración con `InstitutionPresence`:** `start`, `accept` y `join` crean la señal en
    `collab_presence_calls`; `leave`, `end` y la expulsión la borran; un *rollback* no deja nada.

### 2.3 Llamadas directas desde una conversación de TeamChat
- **Problema.** Contradicción entre implementaciones del mismo contrato:
  - `CallTarget::conversation(Direct)` prohíbe la selección, y el documento dice que en `direct` «el otro miembro se
    resuelve en servidor»;
  - `DirectConversationDirectory::eligibleInvitees` exige `selection === [peer]` y devuelve `invalid` con `[]`;
  - `ConversationDirectory` no ofrece otra forma de obtener el otro miembro.
  
  Resultado: TeamCalls no puede llamar desde una conversación directa.
- **Diferencia exacta** (implementación de TeamChat, sin cambio de firma). En `eligibleInvitees`, para `Direct` y
  `$selection === []`, devolver `[$this->users->resolve($context, $peer)]`. Se mantiene el rechazo de cualquier
  selección distinta de `[]` o `[peer]`.
- **Adaptador de TeamCalls:** `ConversationDirectoryAccess::memberIds()` pasa de `[]` a
  `eligibleInvitees(actor, ref, [])` solo para `direct`, y sin actor sigue sin conceder nada. Grupos y canales siguen
  exigiendo selección explícita; TeamChat aún no los ofrece.
- **Pruebas:**
  - llamada desde una conversación directa con el TeamChat real;
  - quien no es miembro, una conversación de otra institución o un miembro inactivo: denegado;
  - la membresía se revalida en `accept`, `join` y `credentials`.

---

## 3. Responsables

| Modificación | Módulo | Responsable |
|---|---|---|
| 2.1 Clases de evento en `CollaborationCore\Events` (contratos 1.1.0) | Contratos | Codex |
| 2.1 Emisión en `Revocation` | CollaborationCore | Codex |
| 2.1 Emisión en `ChatCommands::membership` | TeamChat | Codex |
| 2.2 Autorizar solo `setInCall` en `InstitutionPresence` | CollaborationCore | Codex |
| 2.3 `eligibleInvitees(Direct, [])` | TeamChat | Codex |
| 1.1 `CallEventPolicy` y su registro, caducidad de los efímeros | TeamCalls | Claude |
| 1.3 Idempotencia de todos los comandos | TeamCalls | Claude |
| 1.5 Rutas `/api/v1/collaboration/calls`, actor y dispositivo de Core, *driver* `collaboration` | TeamCalls | Claude |
| 2.1 Escuchas de revocación, retirada de `LeaveCallsOnLogout` | TeamCalls | Claude |
| 2.2 `TeamCallsPresenceAuthorizer` y endurecer `CollaborationPresence` | TeamCalls | Claude |
| 2.3 `memberIds` directo vía `eligibleInvitees` | TeamCalls | Claude |

Los cambios de Codex se entregan en sus ramas. TeamCalls no modifica archivos de Core, Realtime, TeamChat ni de los
contratos.

---

## 4. Procedimiento seguro de integración Git (no ejecutado)

### 4.1 Comprobaciones previas (solo lectura)
1. `git -C <worktree> --no-optional-locks status --porcelain` vacío en los worktrees de TeamCalls y TeamChat.
2. Commits esperados: `git rev-parse feature/collaboration-teamcalls` = `a8b3546` (o el commit 4B aprobado) y
   `feature/collaboration-teamchat` = `c74b8d0` (o el que entregue Codex con 2.1–2.3).
3. Relación de historias:
   - `git merge-base feature/collaboration-teamcalls feature/collaboration-teamchat` = `ef74cd9`;
   - `git merge-base --is-ancestor 835dc11` sobre ambas ramas.
4. Contratos intactos:

   ```
   git diff --quiet ef74cd9 feature/collaboration-teamchat -- Modules/CollaborationCore/app/{Contracts,Data,Enums,Exceptions,Support,Authorization} Modules/CollaborationRealtime/app/{Contracts,Data} tests/Contracts docs/COLLABORATION-CONTRACTS-1.0.md
   ```

   Si Codex publica la versión 1.1.0, se verifica su diferencia exacta, que debe limitarse a `CollaborationCore/app/Events`.
5. Vista previa sin tocar ramas: `git merge-tree --write-tree --name-only --messages feature/collaboration-teamcalls
   feature/collaboration-teamchat`. **Resultado actual:** conflictos solo en `composer.lock` y
   `modules_statuses.json`; `composer.json` y `tests/Pest.php` se fusionan solos.

### 4.2 Worktree de integración independiente
```
git worktree add -b integration/collaboration-4b "../CRM-MCA-integration-4b" feature/collaboration-teamcalls
cd ../CRM-MCA-integration-4b
git merge --no-ff --no-commit feature/collaboration-teamchat
```
- **`modules_statuses.json`:** unión de módulos, con `TeamCalls`, `CollaborationCore`, `CollaborationRealtime` y
  `TeamChat` a `true`.
- **`composer.lock`:**
  1. `git checkout --theirs composer.lock`, que trae `laravel/reverb`;
  2. `php composer.phar update firebase/php-jwt --no-scripts`, que añade solo esa dependencia de TeamCalls;
  3. `composer validate` y `composer audit`.
- **Revisar a mano**, aunque se fusione sin conflicto:
  - `composer.json`: `firebase/php-jwt`, `laravel/reverb` y el PSR-4 de `TeamChat`;
  - `tests/Pest.php`: las cuatro rutas de pruebas de módulo.
- **`composer dump-autoload`**, `git commit` del merge (mensaje con el SHA de cada rama), y sin `push`.
- **Entorno:** BD propia `crm_mca_integration_4b_test` (`migrate:fresh`), `.env` local con valores de prueba, sin
  credenciales reales. LiveKit y Reverb locales.
- **Después:** los commits 4B de TeamCalls, uno por punto de la §3, en esta rama.

### 4.3 Archivos compartidos con riesgo (sin conflicto textual, pero con cambio de comportamiento)
- **`app/Models/User.php`:**
  - `canWorkCrm()` pasa a exigir acceso a la aplicación `crm` (`crm_legacy_access` o concesión);
  - `canManageUsers()` pasa a `is_identity_admin`.
  
  Afecta a `PanelUserDirectory` y a las pruebas de TeamCalls; las fábricas dan `crm_legacy_access` a los roles
  comerciales.
- **`CurrentInstitution`** pasa a *scoped*, y `ResolveInstitutionFromUser` usa `runFor` y la olvida al acabar.
  Revisar los comandos y escuchas de TeamCalls que dependan de la institución fuera de una petición.
- **`routes/web.php`:** el panel añade `RequireCrmAccess`. Las rutas de TeamCalls no están en ese grupo; hay que
  confirmar que la interfaz de desarrollo sigue cerrada para quien no tiene CRM.
- **`Logout`:** dos escuchas (`LeaveCallsOnLogout` y `Revocation::session` de Core). Conviven hasta 2.1; después
  queda solo la de Core.
- **Migración de Core:**
  - `users.role` toma por defecto `member`;
  - se añaden `users.crm_legacy_access`, `users.is_identity_admin` e `institutions.collaboration_enabled` (por
    defecto `true`);
  - `InstitutionExecution` exige `collaboration_enabled`.
- **Sesiones `database`:** `DatabaseDeviceRegistry` comprueba la sesión almacenada; con el *driver* `array` de las
  pruebas no lo hace.
- **Pruebas de TeamCalls con Core real:** necesitan `MfaSessionProof::record` en la sesión, porque `ContextFactory`
  la exige.

---

## 5. Pruebas de integración requeridas
- **Regresión de cada parte en el worktree integrado:**
  - TeamCalls completa, incluida la conformidad de 4A, y `phpunit.contracts.xml` (28);
  - suites de Core, Realtime y TeamChat;
  - Architecture, Localization, Security y Auth;
  - Pint de los archivos tocados, PHPStan de TeamCalls y de contratos.
- **Conformidad:** `CallsGatewayContract` contra `SharedCallsGateway` con **Core real**, sin
  `FakeCollaborationCore`. También `TransactionalOutboxContract` con el recorder real, si Codex no lo ha ejecutado
  ya en su rama.
- **Pruebas específicas:** las enumeradas en 1.1–1.5 y 2.1–2.3.
- **Extremo a extremo local:**
  - LiveKit con TLS local y Reverb;
  - dos cuentas ficticias con TOTP, y dos navegadores más un segundo dispositivo de la misma persona;
  - recorrido: llamada desde una conversación directa → aviso por Realtime (sin sondeo) → contestar en un
    dispositivo (`answered_elsewhere` en el otro) → medios → revocar el dispositivo en uso (salida y rotación) →
    volver desde el otro → retirar `collaboration.use` (expulsión) → historial con cursor.
- **Concurrencia real con MySQL:** las pruebas actuales más las repeticiones con la misma clave en todos los
  comandos, y la revocación simultánea a `join`/`credentials`.

## 6. Condiciones para autorizar la Fase 4B
1. Codex entrega 2.1, 2.2 y 2.3 en su rama, con las pruebas de su parte en verde. Las clases de evento se publican
   como contratos 1.1.0, con SHA, y no hay ningún otro cambio en los contratos 1.0.0.
2. Las comprobaciones de 4.1 dan el resultado esperado sobre los SHA finales, y los worktrees están limpios.
3. Se autoriza expresamente el merge local de `feature/collaboration-teamchat` en `integration/collaboration-4b`
   (worktree nuevo), sin `push` ni merge a `main`.
4. Hay decisiones de negocio confirmadas sobre:
   - `end` solo del anfitrión en 4B;
   - el *driver* `collaboration` por defecto en integración;
   - retirar la API web interna cuando migre la interfaz.
5. Hay un entorno local disponible: MySQL, LiveKit con TLS local y Reverb, sin datos ni credenciales reales.
6. Ninguna prueba de 5 se da por superada con dobles: Core, Realtime y TeamChat deben ser los reales.
