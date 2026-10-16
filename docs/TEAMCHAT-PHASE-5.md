# MCA Colaboración — TeamChat, Fase 5

## Base y alcance

Base autorizada: `c74b8d0d4d18ae507aeefcc279bc7b67622d1b79`.
Rama: `feature/collaboration-teamchat`.
Worktree: `C:\Users\Maikel\Proyectos Claude Code\CRM-MCA-teamchat`.

Se amplía el módulo TeamChat existente. No hay un segundo motor de mensajes: directos, grupos y canales utilizan ChatCommands, ChatQueries, las mismas tablas de mensajes/recibos, secuencias, idempotencia, TransactionalEventRecorder, outbox y sincronización. No se modifican interfaces de CollaborationCore/Realtime, TeamCalls, main ni otros Worktrees. No se realizaron operaciones de red Git o despliegues.

## Funcionalidades y políticas

- Directos: preservación de pareja única ordenada, historial, envío y recibos.
- Grupos privados: mínimo tres miembros al crear; nombre/descripción; propietario, administradores y miembros; altas/bajas, salida, transferencia de propiedad, archivo y mensajería.
- Equipos: identidad institucional única; nombre/descripción; propietario, administradores y miembros; múltiples equipos por colaborador, cambios de rol, alta/baja/salida y archivo.
- Canales estándar: membresía derivada de la del equipo, sin filas duplicadas de participantes.
- Canales privados: admisión expresa y pertenencia vigente al equipo. Un propietario o administrador de equipo no adquiere lectura de canales privados ajenos.
- Cada equipo crea General atómicamente. Índice único `team_id/default_key`; General no se archiva por separado: se puede archivar el equipo.
- Visibilidad elegida al crear e inmutable en esta fase. Cambiar un canal privado a estándar podría divulgar su historial; se rechaza ese cambio y se crea otro canal cuando sea necesario.
- Límite inicial: 100 miembros por grupo/equipo, coherente con la audiencia máxima de eventos y notificaciones compartidas. Máximo 99 seleccionados adicionales en creación. No representa una capacidad de producción certificada.
- No hay acceso automático desde roles comerciales ni administración general de identidades.

### Roles

| Operación | Propietario | Administrador | Miembro |
|---|---|---|---|
| Leer historial autorizado / conversar en espacio no archivado | Sí | Sí | Sí |
| Cambiar nombre, descripción o archivo | Sí | Sí | No |
| Agregar miembros autorizados | Sí | Sí | No |
| Retirar miembros ordinarios | Sí | Sí | Sólo salida propia |
| Retirar otro administrador | Sí | No | No |
| Asignar administrador / transferir propiedad | Sí | No | No |
| Salir | Después de transferir propiedad | Sí | Sí |

Los canales estándar se administran con roles del equipo; se sale del equipo, no de un canal estándar individual. Los canales privados tienen sus propios roles. Crear canales requiere propietario/administrador del equipo. Los propietarios no pueden retirarse a sí mismos sin transferir; la transferencia es una sola transacción y deja al anterior como administrador. Un propietario institucionalmente desactivado congela el envío y las llamadas; no se habilita una recuperación institucional que lea conversaciones privadas.

Si se retira del equipo al propietario de un canal privado, el canal queda archivado. Su historial autorizado se conserva para los miembros restantes; no se sustituye automáticamente al propietario ni se admite al administrador del equipo. Una futura recuperación requerirá una política aprobada.

### Historial y revocación

Se usa un reloj lógico persistente por institución, protegido por el mismo bloqueo transaccional de escritura. No depende de timestamps, del reloj del cliente ni del orden de ULIDs.

Cada incorporación registra `from_tick` y una generación. Cada mensaje lleva `access_tick`. Sólo se consultan mensajes desde el inicio del periodo actual, además de comprobar acceso vigente. Un nuevo miembro no ve mensajes anteriores. Una reincorporación inicia un periodo nuevo: por decisión conservadora tampoco reabre periodos anteriores propios. Los datos anteriores no se borran y no existe un endpoint para conceder historial retroactivo.

En canales estándar, el corte se deriva del periodo del equipo. En privados se usa el máximo del periodo de equipo y del canal. Al salir del equipo se revocan además las admisiones privadas; reingresar al equipo no las restaura.

El mismo filtro se aplica a historial, consultas por IDs, último mensaje, contadores, recibos, cursores y eventos. Los recibos que hagan referencia a mensajes fuera del periodo no se exponen. Los cursores cifrados existentes siguen vinculados a usuario/institución/conversación y se reautorizan: nunca conceden acceso por sí mismos. Una respuesta de envío idempotente de un periodo anterior tampoco revela el ID del mensaje. La interfaz recibe una huella de periodo y descarta mensajes almacenados en memoria si cambia.

Las futuras descargas/adjuntos deberán reutilizar este mismo periodo y autorización; no basta con conocer el ID del archivo.

Los GET no modifican membresías ni recibos. Core conserva sus comprobaciones técnicas de sesión/dispositivo. Entrega y lectura son POST explícitos; Reverb no confirma lectura. Para un mensaje propio de grupo, `delivered_count` y `read_count` indican recibos registrados; el estado resumido delivered/read significa que al menos un destinatario lo confirmó. Para mensajes recibidos, el estado corresponde al propio usuario, nunca al recibo de otro miembro.

Una revocación impide nuevas consultas inmediatamente. El cliente vuelve a autorizar la conversación abierta periódicamente y limpia el hilo al ser denegado. No puede retirarse contenido ya descargado de un dispositivo. El socket transporta únicamente la señal compartida sin contenido.

## Persistencia

Migración: `Modules/TeamChat/database/migrations/2026_11_05_000000_extend_teamchat_spaces.php`.

| Entidad | Uso |
|---|---|
| collab_chat_conversations | Mantiene mensajes/recibos asociados. Agrega type, nombre, descripción, equipo, visibilidad, archivo y clave General. Pareja nullable sólo para permitir otros tipos; se conserva el índice único directo. |
| collab_chat_participants | Se reutiliza para directos, grupos y canales privados; agrega rol, inicio y generación. |
| collab_chat_messages | Agrega access_tick e índice de historial autorizado. |
| collab_chat_teams | Metadatos, archivo y versión de acceso del equipo. |
| collab_chat_team_members | Membresía única equipo/usuario, rol, inicio, generación y estado. |
| collab_chat_clocks | Orden lógico de cambios por institución. |
| collab_chat_event_access | Corte de acceso de cada evento, separado del contrato público. |
| collab_chat_space_commands | Idempotencia por institución/actor/comando y hash de solicitud. |
| collab_chat_membership_history | Historial de cambios con actor, rol, estado y tick; no se elimina al salir. |

Todas las tablas nuevas incluyen institución y FK. El historial de participantes constituye auditoría de altas/bajas/roles; los eventos versionan cambios de metadatos y archivo. Las escrituras se hacen mediante servicios, nunca asignando roles enviados sin comprobarlos.

El rollback rechaza destruir espacios con historial persistido. La migración asigna direct a las conversaciones previas y tick 0 a sus mensajes/participantes, preservando acceso y unicidad. La prueba independiente `tests/TeamChatPhase5Migration.php` verifica una tabla de Fase 4 poblada antes de ejecutar la ampliación.

## Servicios y API

- ChatAccess: institución, membresía vigente, periodo, participantes efectivos, roles derivados y disponibilidad de envío.
- ChatClock: orden lógico transaccional.
- SpaceCommands: creación, metadatos, archivo, membresía y roles; idempotencia, auditoría y eventos atómicos.
- SpaceQueries: equipos, canales, miembros y cambios autorizados.
- ChatCommands/Queries/Events/ChatEventPolicy: motor existente ampliado.
- SpaceController: adaptación HTTP de esos mismos servicios.

Prefijo `/api/v1/collaboration`. Cookies de sesión web existentes, CSRF, MFA, ActorContext del servidor, `collaboration.use`. No se agrega autenticación móvil.

| Método / ruta | Entrada / resultado |
|---|---|
| POST /spaces | kind group/team/channel, command_id, name, description, members[], team_id y visibility cuando corresponda |
| GET /teams | Equipos propios; cursor/limit |
| GET /teams/{id} | Metadatos, rol y general_id |
| GET /teams/{id}/channels | Sólo canales accesibles; cursor/limit |
| GET /spaces/{scope}/{id}/members | scope team/conversation; lista acotada a 100 y membresía derivada explícita |
| GET /spaces/{scope}/{id}/changes | Cambios desde el periodo autorizado; cursor/limit |
| PUT /spaces/{scope}/{id} | command_id, name, description, archived |
| POST /spaces/{scope}/{id}/members | command_id, user_id, operation add/remove/role/transfer, role member/admin |
| GET /conversations y /conversations/{id} | Ahora incluyen direct/group/channel; tipo, nombre, rol, visibilidad, equipo, periodo, contadores |
| GET/POST /conversations/{id}/messages | Mismo motor y contrato base; remitente/nombre y contadores de recibos añadidos |
| POST /conversations/{id}/receipts | Mismo contrato de IDs explícitos |
| PUT /conversations/{id}/membership | Salida propia; en espacios nuevos aplica las reglas de propietario/visibilidad |

Respuestas no-store `{data,meta:{request_id,version:"1.0"}}`. Los errores siguen Core: 401/403 autenticación/autorización, 404 recurso no accesible, 409 idempotency_conflict, 410 cursor caducado, 422 validación, 429 límite. GET paginados máximo 50. Se mantiene el limitador compartido de 120/min y 30 comandos/min para escrituras de gestión y envío.

Las operaciones nuevas exigen command_id. Un replay idéntico devuelve el resultado sin repetir sus efectos; cambiar datos con el mismo comando devuelve 409. Siempre se comprueba acceso actual al recurso, incluso en replays. Tras abandonar un recurso, reintentar la salida puede devolver 404 porque el usuario ya no puede acceder.

## ConversationDirectory y TeamCalls

Se conserva la clase/binding `DirectConversationDirectory` para compatibilidad, pero su implementación reconoce los tres tipos. No se modifica la interfaz aprobada:

- resolve: comprueba tipo real, institución, acceso y disponibilidad.
- authorize: View permite historial autorizado; acciones de llamada requieren espacio disponible y propietario habilitado.
- eligibleInvitees: selección explícita, distinta, no vacía, hasta 99; todos autorizados o rechazo total. En direct debe ser exactamente el otro miembro.
- membership: estado actual del participante solicitado, sin conceder acceso al solicitante.
- accessVersion: cambia con membresía/roles/archivo y considera miembros institucionalmente habilitados. Es una huella, no una caché de autorización.

TeamCalls puede asociar llamadas a ref channel y usar general_id del equipo. Claude Code deberá consumir también group/team/channel.members.changed y updated para revalidar participantes y disponibilidad; la versión de acceso no sustituye una comprobación del servidor. Debe volver a autorizar cada acción y cada invitado. La integración de CallsGateway, invite, revocación multimedia, rotación de salas y LiveKit corresponde exclusivamente a Claude Code. No se probaron llamadas ni se integraron ramas.

## Eventos y tiempo real

Se mantienen los eventos de mensajes, recibos y membresía directa. Se añaden, todos v1:

- teamchat.group.created / updated / members.changed
- teamchat.team.created / updated / members.changed
- teamchat.channel.created / updated / members.changed

Todos usan agregado `teamchat_conversation` y payload mínimo `{conversation_id}`; los eventos de equipo se reflejan en sus canales autorizados (creación en General). No hay nombres, textos ni listas de miembros en el broker. Los detalles y el historial de cambios se consultan por API. ChatEventPolicy verifica tanto membresía actual como periodo y, para recibos, cada mensaje referenciado.

ChatEvents registra exclusivamente con TransactionalEventRecorder dentro de la transacción del dominio. La audiencia sólo incluye miembros actuales habilitados. Los mensajes generan una intención Core sin el remitente y respetan preferencias; publicación y sincronización revalidan acceso. No hay transporte, presencia ni notificaciones paralelos.

## Interfaz

Se amplía `/colaboracion` con pestañas Chats, Grupos, Equipos y Canales, árbol expandible equipo/canales, gestión en diálogos nativos, selección de participantes, roles/transferencia/salida/archivo y consulta de cambios. Mantiene borradores y el motor de reconciliación existente. Mensajes grupales muestran remitente y avatar de iniciales.

Los diálogos usan campos etiquetados, foco nativo, teclado, estados de carga/error/confirmación y ES/EN. No hay controles ficticios de llamadas/archivos. La presencia consultada es la de CollaborationCore. Diseño MCA propio, escritorio y viewport móvil. Los recursos compilados se versionan junto con el manifest porque ese es el patrón del repositorio existente.

## Concurrencia y medición

Sin optimizaciones especulativas: se conserva institución → usuarios/conversación → stream compartido, con tres intentos transaccionales. Se corrigió además OutboxWorker (implementación local de CollaborationRealtime, sin cambiar contratos): su reclamación SQL toma primero el bloqueo de institución, en el mismo orden que los productores, y lo libera antes de publicar por red. Una ráfaga real agotó los tres reintentos inmediatos del consumidor anterior; el fallo detuvo Reverb efectivo y la prueba de navegador lo detectó aunque el fallback HTTP mantuvo la UI actualizada. La corrección evita invertir ese orden; se verifican recuperación del backlog y una nueva ejecución completa. No se silenció la excepción ni se simularon señales. El reloj lógico y los cambios de membresía comparten el bloqueo de institución. Así, una expulsión y un envío tienen un orden definitivo: un envío anterior puede persistir, uno posterior no.

`tests/TeamChatSpacesConcurrency.php` inicia procesos PHP independientes, espera una barrera de preparación y mide sólo después de liberar esa barrera. MySQL 8.4.3 local, sin red externa; consumidor detenido durante esta medición de escrituras. El consumo simultáneo y las ráfagas se prueban aparte con navegador y Reverb:

| Escenario | Procesos | Tiempo total ms | Mediana ms | Máximo ms |
|---|---:|---:|---:|---:|
| Misma creación de equipo | 6 | 411,88 | 208,96 | 276,05 |
| Incorporación duplicada | 6 | 816,59 | 528,54 | 740,74 |
| Envíos en tres espacios, con replays | 12 | 1842,44 | 1121,77 | 1657,70 |
| Expulsión frente a envío | 2 | 208,44 | 161,41 | 161,41 |
| Transferencias de propiedad opuestas | 2 | 206,18 | 170,45 | 170,45 |

La prueba verifica unicidad, generaciones sin reinicios por altas duplicadas, secuencias contiguas, un único propietario, corte de revocación y atomicidad evento/outbox. No es una prueba de carga de producción. El bloqueo por institución es un límite conocido; cualquier reducción futura requiere coordinar Core/Realtime y volver a probar sus invariantes.

## Validación local

Entorno único y aislado: `127.0.0.1:33317`, base `crm_mca_collaboration_core_test`, datadir `CRM-MCA-teamchat/.testing/mysql`. Los scripts verifican datadir y ausencia de usuarios reales. HTTP en 33319 y Reverb en 33318, ambos loopback. Pruebas destructivas y fixtures de navegador se ejecutan secuencialmente.

Resultados comprobados (15 de octubre de 2026):

| Validación | Resultado |
|---|---|
| Suite conjunta TeamChat, Core, Realtime, tenancy, Identity, Institutions, autenticación y seguridad | 172 pruebas / 790 aserciones, correctas, después de corregir el consumidor. |
| Contratos comunes | 28 pruebas / 276 aserciones, correctas. |
| TeamChat final, incluyendo pruebas nuevas de API y revocación | 27 pruebas incluidas en la suite conjunta final, correctas. |
| TeamChatSpacesConcurrency.php | Correcto; 28 procesos en cinco escenarios, métricas anteriores. |
| TeamChatConcurrency.php | 20 comprobaciones de directos, unicidad, idempotencia y recuperación, correctas. |
| RealtimeIntegration.php | MySQL real, trabajadores paralelos, recuperación, deduplicación, outbox y cola persistente correctos. |
| RealtimeWebSocket.php | 14 comprobaciones Reverb real, conexión, reconexión, origen y revocación, correctas. |
| TeamChatPhase5Migration.php | Datos previos preservados exactamente; acceso y unicidad directos correctos. |
| TeamChatBrowser.cjs | 27 comprobaciones Chrome, correctas. |
| TeamChatDevicesBrowser.cjs | 11 comprobaciones Chrome, correctas. |
| TeamChatLayoutBrowser.cjs | 12 comprobaciones Chrome, correctas. |
| TeamChatSpacesBrowser.cjs / TeamChatTreeBrowser.cjs | 32 + 8 comprobaciones Chrome, correctas. Total navegador: 90 comprobaciones en cinco scripts. |
| PHPStan nivel 6 | Sin errores en TeamChat y CollaborationRealtime. |
| Pint | 29 archivos PHP correctos. |
| Vite | Compilación correcta, manifest y recursos actualizados juntos. |
| Git diff --check | Correcto. |

Las 27 pruebas TeamChat forman parte de las 172 conjuntas; no deben sumarse. Incluyen GET sin efectos de negocio, revocación tras reincorporación, propietario privado retirado y Directory Channel. Una ejecución intermedia detectó un error en el fixture MFA del test HTTP; se corrigió usando las claves reales de sesión. La ejecución final completa pasó.

Los navegadores usan cuatro cuentas ficticias .invalid con login y TOTP normales, cookies independientes, MySQL y Reverb reales. Los fallos de red de los ensayos de reintento/offline son inyectados deliberadamente; la persistencia y recuperación son reales. El ensayo determinista de deadlock de Fase 4 sustituye únicamente transporte/inyección SQL; se conserva separado de los procesos y Reverb reales.

Scripts reproducibles: ejecutar primero la suite Pest y los scripts MySQL/Reverb sin usuarios; después `TeamChatBrowserServer.php seed`, iniciar sus modos http/reverb/drain y ejecutar los scripts Chrome secuencialmente. La suite de espacios exige un fixture sin grupos previos; TreeBrowser continúa los espacios creados por SpacesBrowser. `verify` comprueba drenaje y `cleanup` retira sólo el fixture después de detener los procesos. No ejecutar Pest/RefreshDatabase mientras esos navegadores usan la base.

Capturas locales fuera del commit en `.testing/teamchat-phase5-*.png`; scripts de reproducción versionados. Se inspeccionaron las vistas de grupo en escritorio (1440×1000), móvil (390×844), gestión móvil, árbol de equipos y canal móvil. Las capturas esperan historial y miembros cargados; se corrigieron esperas prematuras de los ensayos de captura y de visibilidad del selector privado, sin sustituir sus comprobaciones por resultados simulados. Nunca se usan credenciales de producción.

El consumidor corregido permaneció operativo durante el recorrido final y drenó el outbox sin eventos pendientes, reclamados ni fallidos. La reconciliación de notificaciones confirmó 13 intenciones con aceptación de transporte, que no se confundió con lectura. La recuperación del backlog del ensayo fallido también fue verificada antes de retirar aquel fixture. Al cerrar se detuvieron los procesos locales HTTP/Reverb/consumidor y MySQL aislado y se retiraron las cuatro cuentas y sus datos de ensayo; permanecen las migraciones y evidencias ignoradas para reproducción.

## Riesgos y siguiente fase

- Escala limitada por bloqueo institucional y audiencia máxima de 100; medir con la carga prevista antes de desplegar.
- La reincorporación no reabre periodos previos; no se implementa concesión retroactiva ni conversión de visibilidad.
- No existe recuperación automática de propietario desactivado. Se bloquea el envío; los canales privados cuyo propietario sale del equipo se archivan.
- El estado resumido de recibos de grupo significa al menos un destinatario; los contadores ofrecen el detalle cuantitativo, no una lista pública de dispositivos.
- No se retira información que un cliente ya descargó; se impide nuevo acceso y se revalida la interfaz.
- Sólo web con sesión/MFA; pruebas Chrome y viewport móvil, no dispositivos físicos Android/iOS.
- No se despliega infraestructura, no se prueban llamadas, FCM/APNs ni E2EE.

Siguiente paso recomendado, sujeto a autorización: acordar una base Git con Claude Code y validar sus adaptadores TeamCalls contra Direct/Group/Channel y la revocación multimedia verificable. No comenzar otra fase funcional ni integrar main automáticamente.

Requisito futuro preservado: asesores y colaboradores autorizados podrán crear múltiples enlaces públicos de conversación independientes, revocables, regenerables y con caducidad configurable. Visitantes con identidad y aislamiento propios, sin cuentas institucionales ficticias ni acceso anónimo a API internas. No se implementa aquí. Tampoco notas de voz, adjuntos, TeamFiles, llamadas, grabaciones ni autenticación móvil.
