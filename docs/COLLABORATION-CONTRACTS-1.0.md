# MCA Colaboración — Contratos comunes 1.0.0

Estado: interfaces y tipos; sin implementaciones de negocio, proveedores activos ni rutas.
Base: 835dc11895b68dc45fd34e07982b26bba5b88eaa.
Referencia TeamCalls inspeccionada: 22314f1c3f3bef7185c7fd1da6c3a5f76e481f74.
Los contratos nuevos NO sustituyen automáticamente las interfaces existentes de TeamCalls.

## Propiedad y dependencias

- Codex: CollaborationCore, CollaborationRealtime, TeamChat; TeamFiles posteriormente.
- Claude Code: TeamCalls, interfaz de llamadas, LiveKit y adaptadores de sus puertos.
- Identity e Institutions siguen siendo las fuentes de identidad.
- Core no depende de TeamCalls ni de Realtime. Realtime consume los DTOs de Core.
- Una sola implementación de dispositivos, presencia, notificaciones, outbox y sincronización.
- Los module.json no registran providers. No se habilitan módulos en modules_statuses.json.
- El autoload PSR-4 raíz hace disponibles los contratos tras regenerar autoload en una futura integración. No se modifican dependencias ni composer.lock.

## Inventario y responsables

| Interfaz | Implementación futura |
| --- | --- |
| AuthenticatedSessionContextFactory, ActorContextFactory | Core / Codex |
| CollaborationAccess, UserDirectory | Core / Codex |
| DeviceRegistry, PresenceService, NotificationDispatcher | Core / Codex |
| ConversationDirectory | TeamChat / Codex |
| CallsGateway | Adaptador TeamCalls / Claude |
| TransactionalEventRecorder, RealtimePublisher, SyncFeed | Realtime / Codex |

Las firmas PHP en Modules/CollaborationCore/app/Contracts y Modules/CollaborationRealtime/app/Contracts son la especificación ejecutable.

Los DTOs son readonly y validan forma, no identidad. Su constructor público NO acredita autenticación. Ningún endpoint puede hidratar ActorContext o InstitutionContext directamente del JSON del cliente.

## Identidad, sesión y dispositivos

Permiso único: collaboration.use. Cuenta e institución activas y pertenencia vigente; no requiere canWorkCrm ni otorga CRM, Marketing, Admisiones, RR. HH. o SIGAC.
La representación de usuarios sin rol comercial y su transición se implementará posteriormente, con denegación por defecto de aplicaciones no concedidas.

AuthenticatedSessionContext existe para evitar el ciclo de exigir un dispositivo registrado antes de registrarlo:
1. El backend valida sesión/login, MFA exigido, cuenta e institución.
2. DeviceRegistry.register enlaza un ID asignado por servidor con esa sesión (reintentos de la misma matrícula no crean duplicados).
3. ActorContextFactory resuelve un dispositivo registrado asociado a la sesión.
4. Cada operación revalida revocaciones; el DTO no congela derechos.

Dispositivo activo != sesión válida != presencia en línea. Una app en segundo plano puede recibir push sin aparecer en línea.
La referencia de sesión no es la cookie ni el bearer token y no se envía en eventos.
DeviceRegistry.revoke no admite revocar dispositivos ajenos salvo un flujo administrativo distinto y autorizado.
in_call es una actividad temporal por institución/usuario/llamada, sin sobrescribir el estado manual. Sus efectos externos se aplican tras commit.

## Conversaciones y llamadas

ConversationRef: direct, group o channel + ID; la institución se obtiene del contexto y se verifica contra el recurso.
ConversationDirectory aplica autorización al resolver, listar destinatarios, consultar membresía y obtener versión. accessVersion no reemplaza una nueva decisión.
En direct, el otro miembro se resuelve en servidor y CallTarget prohíbe selección explícita.
En group/channel, CallTarget exige selección explícita, no vacía y sin duplicados; nunca se interpreta como invitar a todo el equipo.
Todos los destinatarios se validan, o falla la operación completa sin revelar qué usuario es ajeno.

CallsGateway ofrece start, invite, accept, decline, cancel, join, leave, end, credentials, find, activeFor, recentFor y missedFor.
- leave: salida personal del dispositivo participante.
- end: finalización global; denegar a quien no tenga permiso. Nunca convertir silenciosamente end en leave.
- invite: delegar en CallService::invite(User, callId, userIds); no copiar su máquina de estados.
- accept: primera aceptación válida gana; reintento mismo dispositivo es seguro, otro obtiene answered_elsewhere.
- Cada operación comprueba institución, colaboración, sesión, dispositivo y autorización vigente. Para llamadas con conversación, revalidar membresía incluso si ya existe CallParticipant.
- Revocar membresía bloquea nuevas credenciales y debe iniciar expulsión multimedia.
- Leer historial también requiere autorización vigente; un resultado idempotente no evita esta comprobación.
- CallSummary y ParticipantSummary son DTOs comunes, no modelos Eloquent.
- JoinCredentials solo se devuelve por canal privado autenticado, sin logs, eventos ni caché de idempotencia.
- IDs internos PHP pueden ser int; serialización API de identificadores debe ser coherente y segura para JavaScript.

## Eventos y entrega

EventEnvelope conserva id, type, v, occurred_at, institution_id, actor_id, data y añade aggregate, correlation_id y causation_id.
v es la versión del esquema; aggregate.version es la secuencia del agregado. No confundir con cursor de entrega.
Cancelación canónica: call.ended, data.status = cancelled; data.call_id coincide con aggregate.id. No existe call.cancelled.
Los estados terminales son ended, declined, cancelled, missed y failed.
Los eventos no contienen credenciales, cookies ni secretos. jsonSerialize excluye audiencia; el productor debe limitar data a datos autorizados.
DeliveryAudience es metadato interno; DeliveryEnvelope rechaza instituciones incompatibles.

TransactionalEventRecorder.append:
- exige la transacción y conexión SQL del productor;
- persiste evento y audiencia atómicamente con el cambio de negocio;
- rechaza un contexto/audiencia de otra institución;
- ID repetido con contenido idéntico: mismo resultado; contenido distinto: conflicto;
- no hace red; rollback elimina el registro.
RealtimePublisher opera después del commit, reintenta y entrega al menos una vez. Consumidores deduplican por ID.
No escribir la outbox solo en afterCommit: existe una ventana de pérdida.
El registro histórico collab_call_events permanece; la outbox es entrega, no otra máquina de estados.

SyncFeed:
- cursor opaco, vinculado al usuario e institución; no aceptar offsets arbitrarios;
- orden de publicación confirmado, sin saltar transacciones pendientes;
- comprobar permisos actuales al recuperar y suscribirse;
- subscribe/buffer -> snapshot con corte estable -> replay desde corte -> deduplicar;
- bootstrap pagina referencias autorizadas, conservando el mismo corte entre páginas;
- cursor vencido: 410 sync_cursor_expired, recuperar snapshot;
- cambios de acceso invalidan resultados anteriores y notifican eliminación local;
- presencia/escritura son efímeras; no reconstruirlas como historial;
- answered_elsewhere puede ser efímero: el estado persistente de aceptación permite reconciliar.

## Notificaciones y medios

NotificationDispatcher.enqueue es durable e idempotente por institución + deduplicationKey; valida audiencia y dispositivos, caducidad y preferencias.
NotificationAudience pertenece a Core para evitar dependencia Core -> Realtime. Un adaptador convierte a DeliveryAudience cuando corresponda.
TeamCalls decide la intención. Core posee proveedores. Realtime ejecuta entregas. No enviar por dos caminos.
La cancelación push no garantiza que el cliente la reciba; al despertar consulta el estado actual.
MediaRoomService sigue exclusivamente en TeamCalls, incluido available(); no se redefine aquí.
LiveKit autohospedado: expulsar no revoca automáticamente tokens emitidos. No activar llamadas reales sin mecanismo de revocación verificado.

## API e idempotencia

Base futura: /api/v1/collaboration. API web actual de TeamCalls se mantiene hasta migración coordinada.
Web: sesión, CSRF y MFA. Móvil: credenciales revocables ligadas a sesión/dispositivo. No se implementa autenticación en esta fase.
Idempotency-Key: 8..64 caracteres [A-Za-z0-9._:-], compatible con el inicio actual de TeamCalls.
Alcance: institución + actor + operación + recurso; huella canónica calculada en servidor.
Misma clave y solicitud: mismo efecto; contenido diferente: 409 idempotency_conflict.
Un replay tardío de invite NO vuelve a invitar a quien rechazó: reinvitación intencional necesita nueva clave.
Resolver autorización vigente incluso en replay. No reproducir tokens multimedia antiguos.
Éxito: data + meta (replayed/cursor/request_id según operación). Errores: error.code/message/retryable/request_id.
HTTP: 401 autenticación/sesión; 403 permiso/dispositivo; 404 recurso ajeno o inexistente; 409 conflicto; 410 cursor vencido; 422 validación; 429 límite; 503 medios.
Adaptador HTTP mantiene 419 CSRF y 500 genérico sin exponer detalles internos.
Los límites definidos en DTOs son máximos de contrato; cada aplicación puede aplicar límites menores.

## Versionado

Versión del paquete contractual: 1.0.0. Referenciar también el SHA del commit.
Cambiar firmas, campos obligatorios, semántica o enums incompatibles exige nueva versión mayor.
Campos de evento opcionales pueden añadirse con compatibilidad; retirar/renombrar exige nueva v.
No utilizar números de secuencia ni ULIDs como prueba de autorización.

## Compatibilidad con TeamCalls y cambios a cargo de Claude

- Añadir adaptador del CallsGateway común a CallService, con resolución de User y contexto validado.
- Incluir invite y resto de operaciones; no copiar lógica del motor.
- end común deniega a no autorizados: el end interno actual puede delegar a leave y no basta por sí solo.
- Revalidar membresía en accept/join/credentials para participantes ya existentes.
- Traducir CallTarget: en grupos/canales la selección explícita NO puede descartarse al convertir al target actual.
- Incorporar DeviceRegistry; WebDevice por sesión sigue siendo provisional hasta esa transición.
- Sustituir PanelUserDirectory basado en canWorkCrm por collaboration.use.
- Registrar la outbox dentro de la transacción de CallRecorder, no solo en afterCommit.
- Mapear DTOs/Enums existentes a los comunes; no retornar Eloquent ni serializar User.
- Mantener call.ended/data.status=cancelled, sin renombrar tablas actuales.
- Adaptar presencia y push al contexto institucional. Reverb y LiveKit no se instalan aquí.
- recentFor/missedFor requieren paginación real por cursor en el adaptador futuro.

## Verificación local y límites

Configuraciones independientes: phpunit.contracts.xml y phpstan.contracts.neon.
Las pruebas Unit cargan únicamente contratos mediante tests/Contracts/bootstrap.php; no usan tests/Pest.php, RefreshDatabase ni bootstrap/app.php.
Ejemplo con herramientas ya instaladas (PHPUnit 12):

    php -n -d extension=mbstring /ruta/a/vendor/phpunit/phpunit/phpunit --configuration phpunit.contracts.xml
    php -n -d extension=mbstring -d memory_limit=512M /ruta/a/vendor/phpstan/phpstan/phpstan analyse -c phpstan.contracts.neon --debug --memory-limit=512M

En Windows puede ser necesario fijar extension_dir al directorio ext del PHP elegido. No ejecutar composer setup ni artisan test para esta suite.
Las bases abstractas tests/Contracts/Conformance deben extenderse en suites de adaptadores con una BD exclusiva de pruebas; no se cuentan como pruebas ejecutadas aquí.
Pendientes al integrar: carreras reales de accept/invite/cancel, revocación de membresía, replay tardío tras decline, caída tras commit, reintentos de entrega y recuperación con corte estable. No se afirma que estas garantías estén implementadas por las interfaces.
La suite de aislamiento del CRM requiere migraciones/BD: no se ejecuta en esta fase de contratos puros; será una regresión obligatoria al integrar implementaciones.

## Integración Git posterior

Compartir el SHA de este commit, sin copiar archivos a mano ni modificar el Worktree ajeno.
Cada propietario incorporará el mismo commit de contratos en su rama solo tras autorización de la operación Git correspondiente.
Probar los SHA acordados en una rama/Worktree de integración y BD de pruebas independientes; main queda fuera.
Los cambios globales de autoload, registro de módulos y configuración de pruebas se coordinan por un único responsable.

## Riesgos pendientes

No hay servicios activos de autorización/dispositivos/presencia, tablas de outbox, workers, transporte, API móvil ni control de revocación multimedia. La compatibilidad del motor requiere adaptadores y pruebas futuras.
El JSON de eventos debe contener únicamente datos autorizados: la validación de forma no sustituye la sanitización del productor.
La salida JSON de DTOs de seguridad debe utilizar recursos explícitos; no serializar contextos ni credenciales en eventos.

## Resultado de esta fase

Validación local: PHP 8.3.30; PHPUnit 12.5.24, 28 pruebas y 189 aserciones; PHPStan nivel 6 sin errores; Pint aplicado a 56 archivos PHP. Ejecución con PHP -n y solo mbstring adicional, sin controladores de base de datos. No se arrancó Laravel ni se cargó .env. Se reutilizaron herramientas existentes en modo lectura, sin instalar dependencias.

Las suites abstractas de conformidad requieren adaptadores futuros y no forman parte de esos 28 casos. No se han probado llamadas reales, revocación multimedia, transporte ni persistencia de outbox.
