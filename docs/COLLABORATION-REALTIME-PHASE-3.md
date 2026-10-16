# MCA Colaboración — CollaborationRealtime, Fase 3

Estado: implementación local sobre feature/collaboration-teamchat, base b64636de145647913d8e341f7da1bb66dac3e17b. No despliegue ni activación de TeamChat/TeamCalls. Interfaces/DTO de Fase 1 y archivos de CollaborationCore sin cambios.

## Componentes

- DatabaseEventRecorder implementa TransactionalEventRecorder. Escribe evento, audiencia privada, secuencia y outbox en la conexión SQL predeterminada y en la transacción original. Rechaza ausencia de transacción; el productor debe usar esa misma conexión y dejar propagar errores/rollback.
- EventPolicies registra adaptadores internos EventPolicy por tipo. El propietario funcional valida un esquema cerrado de datos y la autorización actual del recurso. Sin adaptador se rechaza el evento. No hay reglas de llamadas ni modelos de mensajes copiados.
- OutboxWorker reclama filas con FOR UPDATE SKIP LOCKED, confirma la reclamación y publica fuera de transacción. Reintentos durables, lease de 120 segundos, token de fencing para finalizar, máximo 8 intentos, backoff exponencial con jitter hasta 303 segundos. Fallos de contrato/configuración: invalid_producer; fallo de transporte: transport_unavailable; abandono reiterado: retry_exhausted.
- ReverbPublisher implementa RealtimePublisher y utiliza Laravel Broadcasting por el adaptador WakeTransport. No se admite red dentro de una transacción SQL. Guzzle limita conexión a 2 segundos y solicitud a 5 segundos.
- DatabaseSyncFeed implementa SyncFeed: historial, bootstrap estable, cursores cifrados/autenticados vinculados a usuario e institución, límite 1–100, vigencia 24 horas. Cada lectura revalida sesión, dispositivo, collaboration.use y EventPolicy.
- CurrentSignals permite consultar señales efímeras vigentes por agregado. Exige TTL máximo de 5 minutos al registrar. Nunca aparecen en historial persistente; el cliente elimina cada señal al vencer su TTL.
- NotificationDelivery reconcilia intenciones existentes de Core contra el único camino de publicación de la outbox. NotificationInbox expone hasta 100 intenciones actuales autorizadas para el dispositivo. No envía por una segunda vía.
- DrainOutbox es un job opcional para cola collaboration, afterCommit, timeout 60 segundos, un intento de cola (los reintentos pertenecen a la outbox). El comando drain también funciona sin Redis ni cola externa.
- TechnicalRetention elimina la carga de señales expiradas hace más de un día y registros técnicos de notificación antiguos. Conserva IDs, hashes y versiones para idempotencia. Historial durable y recibos se conservan: su política de archivo/borrado debe acordarse con los módulos propietarios.

### Migración

2026_11_03_000000_create_collaboration_realtime.php crea:

| Tabla | Finalidad |
| --- | --- |
| collab_streams | Posición confirmada institucional y reserva de suelo de retención |
| collab_events | Envelope, audiencia privada, versión del agregado, hash, TTL |
| collab_outbox | Estado de despacho, intentos, reclamación, vencimiento, fallo |
| collab_transport_receipts | Aceptación del broker por evento/dispositivo; clave única |
| collab_notification_delivery | Estado técnico de la intención sin afirmar recepción |

Todas incluyen institución y claves externas. Identificadores de eventos/agregados usan comparación ASCII binaria. down elimina solamente estas tablas; pierde historial, cursores y pendientes Realtime. Detener productores/workers y respaldar antes de un rollback. No revierte ni altera datos Core.

## Garantías y límites de concurrencia

Cada append bloquea collab_streams de la institución hasta el commit exterior. La posición se asigna bajo ese bloqueo. Un segundo productor no puede confirmar una posición posterior antes del primero; rollback revierte también el contador. Las lecturas del contador solo observan commits. No se utiliza MAX de IDs autoincrementales como cursor.

Identificador igual y contenido/audiencia/TTL iguales: mismo ID, sin otra fila. ID reutilizado o versión no creciente: conflicto. Las versiones son monotonía del stream de agregado; un productor puede empezar por una versión mayor que 1 y mantener huecos justificados. El adaptador de TeamCalls debe asignarlas con su bloqueo de llamada, no con timestamps.

Los workers pueden publicar agregados fuera de orden entre sí: WebSocket solo despierta al cliente. El orden de estado se obtiene de sync y las versiones de agregado. Una caída después de aceptar el broker y antes del recibo puede repetir el aviso. Un lease que vence durante trabajo lento también puede repetirlo: no se promete exactly-once. El token de reclamación impide que un worker antiguo sobrescriba el estado de una reclamación nueva.

Un evento sin dispositivo elegible queda suppressed; continúa disponible por sync para usuarios autorizados. Publicado significa aceptación HTTP del broker, nunca que había un cliente conectado ni que leyó el evento. Una respuesta de error mantiene el pendiente para retry. El escáner SQL recupera trabajo incluso si se perdió el job de cola.

## API y protocolo cliente

Todas las rutas comparten sesión web, MFA y middleware CollaborationRequest de Core, CSRF en POST, límite 120 solicitudes/minuto y Cache-Control no-store. No se añade login móvil ni se acepta ActorContext desde JSON.

| Método/ruta bajo /api/v1/collaboration | Resultado |
| --- | --- |
| GET sync?limit=20&cursor=... | data.events, data.cursor, data.hasMore |
| GET sync?bootstrap=1&limit=20&cursor=... | data.resources, data.cursor, data.nextPageCursor |
| GET realtime/channel | Canal privado derivado en servidor para este dispositivo/sesión |
| POST realtime/auth | Firma privada ligada a socket_id y channel_name verificado |
| GET realtime/signals | Señales actuales, expires_at por ID en UTC, cursor independiente, límite 100 |
| GET realtime/notifications | Intenciones vigentes autorizadas, máximo 100, sin confirmar entrega |

Los cursores son opacos, no offsets ni IDs elegidos por el cliente. Error 422 invalid para manipulación/tipo/usuario/institución erróneos; 410 sync_cursor_expired exige nuevo bootstrap. Sesión/dispositivo/permisos usan los errores aprobados de Core. Sincronización devuelve el envelope aprobado (v, id, aggregate.version, fechas UTC, correlación/causación).

Secuencia obligatoria de integración cliente:

1. Obtener canal, conectar a Reverb, autorizar y esperar subscription_succeeded. Comenzar a acumular los avisos.
2. Si no hay cursor válido, pedir bootstrap y recorrer nextPageCursor hasta terminar. Todas las páginas conservan el mismo corte.
3. Obtener recursos mediante las API de dominio autorizadas. El bootstrap contiene referencias/versiones, no un snapshot del contenido de cada dominio.
4. Leer sync desde data.cursor del corte, hasta hasMore=false. Los eventos confirmados mientras se construía el snapshot quedan después del corte y se recuperan.
5. Al recibir collaboration.sync.required, repetir sync y consultar las señales/intenciones actuales. Deduplicar por ID y aplicar versiones de agregado en orden, sin retroceder estado.
6. En reconexión repetir suscripción y sync desde el cursor confirmado. Cada dispositivo conserva su cursor propio; los cursores son portables entre dispositivos válidos del mismo usuario, pero exceptDeviceId se evalúa en cada lectura.
7. Un respaldo de sondeo periódico protege contra avisos perdidos. Esto no equivale a llamadas entrantes móviles instantáneas.
8. Un 401/403 obliga a detener sincronización y vaciar datos que la aplicación de dominio ya no autorice; la revocación durante bootstrap se comprueba en cada página.

El evento WebSocket contiene únicamente {"v":1}; no contiene ID de evento, institución, usuario, recurso, texto ni credenciales. El canal es un HMAC de institución/usuario/dispositivo/sesión. Una suscripción antigua puede permanecer físicamente abierta; no concede acceso a datos y se excluye de publicaciones futuras al detectar revocación. Existe la carrera habitual de una comprobación de autorización frente a un envío ya en curso, limitada al aviso vacío. No se promete desconexión física instantánea.

El dominio debe ofrecer snapshot completo y eventos de membresía para descubrir recursos preexistentes tras un alta; este módulo enumera recursos representados en su historial, no inventa modelos TeamChat/TeamFiles.

## Notificaciones

- Core conserva intención pending/suppressed/expired.
- La tabla técnica distingue pending, awaiting_source, suppressed, expired, attempted, failed y transport_accepted.
- transport_accepted se refiere al aviso del evento fuente. No es confirmación de recepción/visualización de la notificación. El estado Core no se cambia a delivered.
- Se revalidan destinatario original, dispositivo original, sesión, autorización del recurso, preferencias y TTL al consultar/reconciliar. El aviso genérico de sincronización puede enviarse aunque el usuario desactive notificaciones, pues también transporta cambios de estado; no muestra una alerta.
- Encolar la intención y registrar el evento fuente en la misma transacción de negocio antes del commit. Una intención creada después de publicar su fuente no genera otro envío; aparece en consulta actual, sin promesa de alerta inmediata.
- El cliente deduplica alertas por intent.id/source_event_id. No existe confirmación del cliente implementada en esta fase.
- MobilePushTransport es un puerto interno sin implementación/binding. FCM/APNs, credenciales, push entrante y registro móvil con MFA quedan pendientes; no son operativos.

## Integración exacta para Claude Code

Lectura realizada de TeamCalls/Services/CallRecorder.php y llamadas en CallService.php, sin cambios en ese Worktree.

Hoy record(Call, type, actorId, subjectId, data, recipients, exceptDeviceId, dedupeKey) inserta collab_call_events y prepara CallEnvelope; afterCommit publica mediante CallEventPublisher, captura fallo y lo registra. notify crea un ULID y también publica después del commit. Los eventos Laravel de dominio se emiten después del commit por separado.

Claude deberá:

1. Registrar EventPolicy por cada tipo emitido por TeamCalls (incluidos nuevos tipos participant/media). Validar campos permitidos y comprobar institución, membresía actual, participante y capacidades de lectura. No usar solamente la audiencia histórica.
2. Dentro de record y notify, en la misma conexión/transacción que la llamada, construir EventEnvelope y DeliveryAudience e invocar TransactionalEventRecorder::append. El ID debe ser el del CallEvent/ULID existente; no crear otra identidad para la misma emisión.
3. Asignar aggregate.type=call y aggregate.id=call.id; administrar aggregate.version monotónica bajo el bloqueo de la llamada. CallEnvelope actual no aporta esta versión: el adaptador requiere decisión/implementación de Claude.
4. Mapear actorId, occurredAt, destinatarios y exceptDeviceId; proporcionar correlationId/causationId desde contexto servidor. Conservar call.ended y data.status=cancelled, incluyendo data.call_id requerido por el DTO aprobado. No crear call.cancelled.
5. Dar TTL corto a answered_elsewhere/avisos temporales y usar CurrentSignals/API de llamadas para su estado vigente. No colocar LiveKit tokens, secretos ni identidad de sesión en el envelope.
6. Retirar únicamente la publicación externa directa de afterCommit para esos mismos eventos, evitando doble envío. Mantener los eventos internos Laravel y los efectos multimedia posteriores al commit según su motor actual.
7. Encolar las intenciones de notificación comunes antes del commit. Sustituir futuros push directos cuando exista adaptador móvil probado; esta fase no vuelve operativo el PushNotifier de TeamCalls.
8. Ejecutar pruebas contractuales del adaptador, rollback y procesos concurrentes con el motor de llamadas real antes de activar llamadas. El doble test de call.ended no certifica la integración de CallService/LiveKit.
9. Entregar los cambios en su propia rama. No se ha integrado nada en main ni en TeamCalls.

TeamChat/TeamFiles usarán exactamente los mismos puertos. EventPolicy, WakeTransport y MobilePushTransport son extensiones internas nuevas; no alteran las firmas compartidas aprobadas.

## Ejecución y operación futura

Dependencia oficial laravel/reverb ^1.12, lock v1.12.0. Se añaden 13 paquetes, ninguno existente cambia de versión. Autoload regenerado con el merge-plugin existente; no se ejecutaron scripts de instalación/migración Composer.

Configurar secretos fuera de Git: REVERB_APP_ID, REVERB_APP_KEY (público), REVERB_APP_SECRET (privado). REVERB_SERVER_HOST controla escucha; REVERB_HOST controla publicación. Por defecto escucha solo en 127.0.0.1. REVERB_ALLOWED_ORIGINS es lista de hosts separada por comas, sin comodín. Eventos de cliente deshabilitados; rate limit activado.

Local, con valores de prueba y puerto libre:

    php artisan reverb:start --host=127.0.0.1 --port=33318
    php artisan collaboration:realtime drain --institution=ID --limit=100
    php artisan collaboration:realtime metrics --institution=ID
    php artisan collaboration:realtime retry --institution=ID --event=ID
    php artisan collaboration:realtime prune --institution=ID

También existe --all-institutions explícito. El job DrainOutbox se despacha después del commit y utiliza queue collaboration. La outbox ya es una cola SQL persistente; nunca depende de Redis para conservar eventos. Mantener un escáner drain periódico, aunque se use la cola Laravel, para recuperar jobs/claims perdidos. No se instaló cron ni supervisor local.

VPS futuro: procesos bajo Supervisor/systemd, reinicio automático, señales de parada ordenada y queue:restart al desplegar. Ejecutar drain en bucle supervisado con pausa corta, o dispatcher periódico y workers de cola persistente; la latencia de alertas depende del intervalo del escáner. Supervisar backlog/oldest_pending_at, failed, claimed vencidos y mean_publish_ms; métricas de conexiones/latencia de Reverb mediante observabilidad del VPS. No exponer métricas de control por API pública.

Hostinger → VPS: publicar por HTTPS autenticado hacia el endpoint Reverb del VPS; WSS cliente con TLS en proxy inverso y orígenes explícitos. App de Hostinger debe poder ejecutar el escáner/cola con la cadencia necesaria; si no puede, mover también el runtime trabajador/aplicación a red privada del VPS antes de prometer llamadas instantáneas. No conectar un VPS a MySQL público. Mantener MySQL/Redis en loopback/red privada, con firewall, usuarios de privilegios mínimos, backups cifrados y prueba de restauración. Redis opcional para scaling Reverb (REVERB_SCALING_ENABLED), sin sustituir outbox SQL.

Restaurar SQL junto con claves de cursor; cambios de clave invalidan cursores y fuerzan bootstrap. Procesar pendientes/claims vencidos tras recuperación; los clientes deduplican. Respaldo de fuente de verdad y planes de retención de cada dominio son requisitos previos a producción.

Fuentes oficiales consultadas: [Reverb 13.x](https://github.com/laravel/docs/blob/13.x/reverb.md), [Laravel Broadcasting](https://laravel.com/framework/docs/broadcasting).

## Auditoría Composer

Auditoría del lock, seis avisos en cinco dependencias previas:

| Paquete instalado | Aviso | Gravedad informada | Impacto en esta fase |
| --- | --- | --- | --- |
| laravel/framework 13.25.0 | CVE-2026-102279 / GHSA-jh5r-qr3c-85q8 | baja | XSS página debug; APP_DEBUG=false fuera de desarrollo |
| league/commonmark 2.10.0 | GHSA-97jj-33gv-5xf9 | media | bypass filtrado HTML; Realtime no renderiza Markdown |
| league/commonmark 2.10.0 | GHSA-3q6v-r5mr-hxv8 | alta | DoS tablas Markdown; fuera de este transporte |
| league/flysystem 3.35.2 | CVE-2026-102601 / GHSA-cxf4-7mrp-vvpr | baja | normalización rutas; Realtime no admite rutas de archivos |
| livewire/livewire 4.3.0 | CVE-2026-81887 / GHSA-g3hc-697w-wm82 | media | XSS estado cliente; sin UI Livewire nueva |
| symfony/http-foundation 7.4.16 | CVE-2026-108082 / PKSA-xmwy-rmx5-m7h4 | no indicada por Composer | ReDoS Cache-Control; afecta superficie HTTP de la aplicación |

No se detectó aviso clasificado crítico; no se actualizaron paquetes ajenos indiscriminadamente. El riesgo de HttpFoundation también alcanza estas API y debe corregirse mediante intervención compatible de seguridad antes de exposición pública (el aviso indica corrección en 7.4.19). Los otros avisos tampoco desaparecen por no usar sus componentes en Realtime. El rate limit no sustituye su parche.

## Validación

Los scripts tests/RealtimeIntegration.php y tests/RealtimeWebSocket.php fuerzan la base aislada heredada de Fase 2, verifican @@datadir y ausencia de usuarios reales. Se ejecutan secuencialmente; el segundo inicia y detiene su propio Reverb 127.0.0.1:33318. No se usa producción.

- Pest: seguridad institucional, idempotencia, bootstrap/cursor, revocación, payloads, señales, intenciones y contratos.
- MySQL real + procesos independientes: productor bloqueado, cursor durante transacción sin confirmar, cuatro workers, fallo de red simulado, proceso que termina con código 17 y recuperación del lease.
- Reverb real: conexión/subscripción, broker HTTP y frames WebSocket, reconexión, firma inválida, Origin rechazado, exclusión tras revocación.
- Prueba down/up de la nueva migración preserva usuarios/dispositivos de Core.
- PHPUnit de contratos, PHPStan nivel 6 y Pint del alcance nuevo.

Resultados exactos y commit se incluyen en el informe de cierre. No se certifican MariaDB, Redis distribuido, TLS/WSS del VPS, FCM/APNs, carga sostenida ni integración real de TeamCalls/LiveKit: requieren los entornos/adaptadores siguientes. Probado con MySQL 8.4 local; despliegue requiere MySQL 8+ o MariaDB con SKIP LOCKED y comprobación específica (no SQLite).

### Resultados locales de cierre

- Regresión conjunta: **145 pruebas Pest / 596 aserciones**, 184,15 s (Realtime inicial, CollaborationCore, Identity, Institutions, Core, Auth, Security y Profile).
- Suite Realtime final, después de los ajustes de TTL/contexto y casos adicionales: **22 pruebas / 73 aserciones**, 56,87 s. Incluye casos ya presentes en la regresión; no deben sumarse como pruebas distintas.
- Contratos Fase 1: **28 pruebas PHPUnit / 276 aserciones**.
- MySQL real: **35 comprobaciones PASS**, incluidos productores duplicados, cuatro workers, caída real de proceso, recuperación, máximo de intentos, replay y cola database/afterCommit.
- Reverb real: **14 comprobaciones PASS**, conexión/reconexión, suscripciones privadas, broker/frame, Origin, manipulación y revocación. Proceso temporal detenido por el arnés.
- Migración: **down/up PASS**, preservando usuario y dispositivo Core.
- PHPStan nivel 6 del alcance Realtime: **0 errores**. Pint: **sin diferencias pendientes**. No se utilizan baselines nuevos ni se atribuyen diagnósticos globales ajenos a esta fase.

La secuencia institucional serializa productores por institución: validar rendimiento con carga real antes de ampliar audiencia. Mantener transacciones breves y utilizar el retry transaccional estándar de Laravel frente a deadlocks (por ejemplo, DB::transaction(callback, 3)); nunca efectuar red bajo ese bloqueo.
