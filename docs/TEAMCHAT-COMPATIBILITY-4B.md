# MCA Colaboración — Correcciones 4B y contratos 1.1.0

## Base y alcance

Rama: `feature/collaboration-teamchat`.
Worktree: `C:\Users\Maikel\Proyectos Claude Code\CRM-MCA-teamchat`.
Base verificada y limpia: `461a8a353437f6b1b3d611ab0f3bc7c6995bded3`.

Cambios exclusivos de compatibilidad, sin mensajería nueva, interfaz visual, motor de llamadas ni LiveKit.
TeamCalls fue inspeccionado en lectura en `315e42d3e085395e0e7087d25af01fab683b816c`,
incluido `Modules/TeamCalls/docs/TEAMCALLS-INTEGRATION-4B.md`.
No se modificaron sus archivos ni el Worktree original de contratos. Sin operaciones remotas.

## 4B.1 — Revocaciones durables

Los DTO inmutables de Core son:

| DTO | Tipo de evento v1 | Alcance |
|---|---|---|
| CollaborationAccessRevoked | collaboration.access.revoked | institución + usuario |
| CollaborationDeviceRevoked | collaboration.device.revoked | institución + usuario + dispositivo |
| ConversationMembershipRevoked | conversation.membership.revoked | institución + usuario + recurso direct/group/channel/team |

`Events/RevocationNotice` contiene institución, usuario, scope, resourceId y reason.
El envelope existente conserva id, type, v, institution_id, occurred_at, aggregate,
correlation_id, causation_id, actor_id y data. Estos eventos usan actor_id=null,
aggregate.type=collaboration_revocation, aggregate.id=id y aggregate.version=1.
Cada transición efectiva tiene un ID independiente. La posición institucional del stream mantiene
el orden de confirmación; el consumidor no debe suponer que los reintentos se entregan en ese orden.

Payload estricto:
`{user_id: int, scope: string, resource_id: string, reason: string}`.
No contiene sesión, hash de sesión, token, credenciales, correo ni nombres.
Motivos: application_revoked, user_inactive, institution_changed, credentials_changed,
institution_disabled, device_revoked, session_ended, logout, eligibility_lost, left, removed, team_removed.

Fuentes:

- ApplicationAccess: transición efectiva de acceso permitido a denegado.
- User e Institution: guardado Eloquent de campos de seguridad, envuelto junto con su revocación
  por AtomicCollaborationRevocation. Incluye guardados que no tenían transacción exterior.
  Se bloquea la institución antes de materializar el cambio. La comparación usa el estado persistido,
  no sólo los atributos originales potencialmente desactualizados del modelo.
- Cambio de institución: evento en la institución anterior, donde viven las asociaciones previas.
- Cambio de credenciales/MFA: revoca los dispositivos anteriores, conserva el requisito de nueva sesión.
- DeviceRegistry y logout: sesión y dispositivos registrados revocados en la misma transacción.
  Las sesiones database deben compartir conexión transaccional con el dominio; otra conexión falla explícitamente.
- Revocation::reconcile, integrado en collaboration:prune: materializa una única revocación de dispositivos
  cuya sesión expiró, desapareció o perdió elegibilidad. Las nuevas operaciones ya se deniegan
  mediante validación actual, sin esperar al barrido.
- ChatCommands/SpaceCommands: abandono directo, expulsión/abandono grupal o de canal y expulsión del equipo.
  El último caso emite también por cada canal al que tenía acceso (estándar derivado y privado admitido).
  Se mantienen las reglas de propietario, archivo, auditoría, generación y periodo de historia de Fase 5.

La repetición de la misma transición no añade otra revocación. Los comandos de membresía conservan
sus respuestas anteriores: algunas repeticiones sin command_id reutilizable son rechazadas porque el
actor ya no tiene acceso, pero no crean efectos duplicados.

### Un único almacenamiento y entrega fiable

Core declara `Contracts/RevocationRecorder::record(RevocationNotice): string`.
CollaborationRealtime implementa ese puerto con RevocationEvents, que utiliza
TransactionalEventRecorder y las tablas existentes collab_events/collab_outbox.
Core no depende de la implementación Realtime ni de TeamCalls.

El evento y la mutación comparten transacción; cualquier error de persistencia o rollback deshace ambos.
No hay publicación de red antes del commit.
La única excepción a la exigencia de institución activa del recorder es la política estricta interna
de revocaciones: permite registrar precisamente la deshabilitación de la institución.
La audiencia debe estar vacía, sin dispositivo excluido ni expiración; ningún cliente obtiene estos
eventos mediante SyncFeed o Reverb. Las políticas anteriores no cambian.

OutboxWorker reconoce estos eventos y llama a RevocationDelivery, reutilizando claims, lease, backoff,
ocho intentos y reintento operativo existentes. Realtime declara el puerto interno
`Integration/RevocationConsumer::consume(EventEnvelope): void`, que implementará TeamCalls.

La nueva tabla collab_revocation_receipts guarda la confirmación del consumidor en la misma transacción
que sus efectos SQL o sus intenciones durables. Una caída posterior a esa confirmación no repite el
consumidor. Los errores se propagan al worker y conservan el evento recuperable. La migración se
ejecutó sólo en la base aislada de testing.

Sin un consumidor registrado se conserva pending/retry y, tras agotar los intentos, failed.
**Nunca se marca exitoso por ausencia de TeamCalls.** El historial durable no se poda.
Recuperación con las operaciones existentes:

- `collaboration:realtime metrics --institution=ID`
- `collaboration:realtime retry --institution=ID --event=ID_EVENTO`
- `collaboration:realtime drain --institution=ID`

## 4B.2 — Limpieza autorizada de presencia

Se agrega `CallPresenceCleanupAuthorizer extends CallPresenceAuthorizer` con:

`authorizeCleanup(InstitutionContext $context, int $userId, string $callId): void`.

La firma original authorize no cambia. El adaptador de TeamCalls debe verificar una asociación histórica
real institución/usuario/llamada para cleanup, aunque el participante haya salido, esté revocado o la
llamada haya terminado. Para setInCall mantiene la autorización de participación activa.

clearInCall no exige que usuario/institución sigan habilitados, pues debe poder limpiar tras su revocación.
Sí exige el adaptador confiable y conserva aislamiento del contexto institucional.
Borra exclusivamente la clave (institution_id,user_id,call_id): no afecta otra llamada, heartbeat ni estado manual.
Una señal expirada o ausente se limpia de forma segura.

Los adaptadores antiguos siguen pasando por su authorize original: no se amplían sus permisos.
Para poder limpiar después de leave/end deben implementar el puerto nuevo.
El adaptador predeterminado continúa denegando. No hay endpoint HTTP para estas operaciones.
Esto es una frontera de servicio de la aplicación confiable, no un aislamiento frente a código PHP malicioso.

## 4B.3 — Llamada privada sin seleccionar de nuevo

ConversationDirectory::eligibleInvitees mantiene su firma.
Para direct + [] resuelve el otro participante desde la pareja persistida.
Comprueba pareja distinta de dos personas, miembros activos exactos, actor incluido, institución,
membresía y elegibilidad actuales. Reutiliza UserDirectory.
Se conserva [peer] para consumidores anteriores; [actor] u otro identificador es rechazado.
CallTarget::conversation(direct) ya producía []; no necesitó modificación.

Grupos y canales siguen exigiendo selección explícita no vacía.
El directorio entrega una comprobación actual, no una autorización permanente.
TeamCalls debe seguir revalidando start/invite/accept/join/credentials bajo sus transacciones autorizadas.

## Incorporaciones de Claude Code

1. Consumir este commit de forma controlada cuando se autorice la integración Git; no se realizó merge,
   cherry-pick ni copia hacia su Worktree.
2. Implementar CallPresenceCleanupAuthorizer y enlazarlo como CallPresenceAuthorizer en boot del proveedor.
   authorize verifica participación activa; authorizeCleanup verifica asociación histórica institucional.
3. Enlazar RevocationConsumer. Consumir los tres tipos y sus scopes, incluyendo canales y equipos.
   Procesar SQL/intenciones durables en la transacción proporcionada; no hacer llamadas LiveKit dentro de ella.
   El ID del envelope es la clave de deduplicación.
4. No tragar fallos de persistencia. Las acciones multimedia externas deben tener su propia intención durable
   idempotente y reintentos, responsabilidad exclusiva de TeamCalls.
5. Revalidar estado actual al procesar eventos retrasados: una revocación antigua no debe expulsar una
   admisión nueva válida. Usar el ámbito, occurred_at y la asociación de llamada; no usar sólo el usuario.
6. ConversationDirectoryAccess::memberIds debe usar eligibleInvitees(actor, directRef, []) para direct.
   Mantener la selección explícita para group/channel.
7. Mantener el barrido multimedia de defensa. Completar pruebas con su motor y LiveKit antes de activar llamadas reales.
8. Integrar la migración y operar los workers y el barrido de sesiones en la futura infraestructura autorizada.
   Revisar/reintentar failed generados antes de registrar el consumidor.

La sugerencia preliminar de publicar eventos Laravel solamente con afterCommit se reemplaza por outbox
durable. La sugerencia de omitir toda autorización en clearInCall se reemplaza por autorización histórica,
como exige esta orden 4B.

## Pruebas y alcance de evidencia

Pest final: **189 pruebas, 861 aserciones, todas aprobadas** (131,60 s).
Contratos puros: **30 pruebas, 320 aserciones, todas aprobadas**.
Revalidación específica tras el ajuste de tipos del trait: **17 pruebas 4B, 71 aserciones**, aprobadas.
PHPStan nivel 6: cuatro configuraciones sin errores. Pint --test: **33 archivos**, aprobado.
Revisión manual y git diff --check: sin incidencias.
Las 10 pruebas originales de SpacesTest de Fase 5 están incluidas y aprobadas; los 17 casos nuevos
4B se agregan a las 172 regresiones anteriores.

- Base de pruebas exclusiva: MySQL local 33317, crm_mca_collaboration_core_test, datadir de este Worktree
  .testing/mysql. Se verificó el directorio real y ausencia de usuarios ajenos antes de los scripts de integración.
- Pest: Core, Realtime, TeamChat Fases 4/5, compatibilidad 4B, Identity, Institutions, Core, Auth y Security.
- Contratos puros: sin arranque Laravel ni base de datos.
- Compatibility4BIntegration: 16 comprobaciones; seis procesos de revocación concurrentes,
  rollback real, recuperación, fallo de consumidor y pérdida de confirmación/lease.
  Consumidor de TeamCalls simulado; persistencia, transacciones y worker reales.
- TeamChatSpacesConcurrency: 28 procesos en cinco escenarios; creación, altas repetidas, envío en tres
  espacios, expulsión contra envío y transferencia de propietario.
- TeamChatConcurrency: 20 comprobaciones; seis creadores y seis envíos reales. El deadlock de claim
  específico es inyectado; las transacciones se ejecutan en MySQL.
- RealtimeIntegration: 35 comprobaciones de concurrencia/recuperación/cola database real.
  WakeTransport es un doble en los escenarios de fallo controlado.
- RealtimeWebSocket: 14 comprobaciones con servidor Reverb y conexiones reales;
  reconexión, firma de canal, revocación y Origin. Se actualizó el harness para drenar la fila interna adicional.
- Presencia histórica: servicio y almacenamiento Core reales con adaptador de autorización de TeamCalls simulado.
- PHPStan nivel 6: Core (incluidos User e Institution), Realtime, TeamChat y contratos.
  Se acotó phpstan.contracts.neon a contratos/DTO/puertos puros; el runtime se analiza con Larastan en las
  configuraciones de sus módulos. No se añadieron ignores ni baseline.
- Laravel Pint sólo en PHP afectado; git diff --check y revisión manual.

## Límites y pendientes

No se probó expulsión multimedia ni rotación de sala: no se implementaron y requieren a Claude.
No se ejecutó una nueva batería de navegador de la UI, que no cambió; sí se ejecutaron las regresiones
de dominio Fase 5 y transporte Reverb real.
No se instalaron dependencias ni se usaron credenciales de producción.

Los guardados Eloquent y los servicios de revocación son las fronteras soportadas.
Las escrituras SQL masivas externas que omiten el modelo no pueden producir una outbox atómica
retroactivamente; no deben usarse para cambiar los campos de seguridad. El barrido detecta dispositivos
ya inelegibles, pero no reemplaza esas fronteras.
Los módulos Core y Realtime y la nueva tabla deben estar disponibles al habilitar esta versión.
La activación de workers/scheduler en servidores y la integración del consumidor quedan pendientes de
autorización y coordinación, no se realizaron despliegues.

No se inicia una fase posterior.
