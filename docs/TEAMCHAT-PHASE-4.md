# MCA Colaboración — TeamChat, Fase 4

## Alcance y base

Implementación local sobre `73d1ab9ba8b3453f2d1f27e293bb247c7cd6333d`, en `feature/collaboration-teamchat`, Worktree `C:\Users\Maikel\Proyectos Claude Code\CRM-MCA-teamchat`.

Módulo independiente Laravel Modules. Conversaciones directas institucionales y mensajes de texto. No modifica contratos compartidos, TeamCalls, su Worktree, el Worktree de contratos ni main. No incluye llamadas, grupos, canales, adjuntos, visitantes, aplicaciones móviles o despliegue.

## Funcionalidad

- Una conversación persistente por pareja ordenada de usuarios e institución, con restricción única SQL.
- Directorio de colaboradores activos de la misma institución: sólo ID y nombre, sin correo ni secretos.
- Historial con cursores cifrados vinculados a usuario, institución y conversación; límite máximo 50 y vigencia 24 horas. Cursores independientes hacia mensajes anteriores y posteriores.
- Envío idempotente por institución + remitente + command_id. Un reintento idéntico devuelve el mensaje original; contenido o conversación diferentes con el mismo comando generan 409.
- Secuencia definitiva asignada bajo bloqueo SQL. Mensaje, secuencia, evento, outbox e intención de notificación participan en la misma transacción.
- Estados enviados por el servidor: sent, delivered, read. El cliente representa pending y failed, conserva command_id al reintentar y mantiene borradores en memoria por conversación.
- Confirmación explícita por lista de IDs recibidos o visibles; nunca un rango implícito de mensajes descargados parcialmente. Consultar historial no confirma recepción. Leer implica entrega.
- Contador de mensajes entrantes sin recibo de lectura, sincronizado mediante eventos. Las preferencias de Core controlan avisos, no el contador.

## Propiedad e integración

| Responsabilidad | Implementación |
|---|---|
| Identidad, MFA, sesión, dispositivos, permisos y presencia | CollaborationCore existente |
| Persistencia de conversaciones, participantes, mensajes y recibos | TeamChat |
| Autorización de conversaciones directas para TeamCalls | DirectConversationDirectory, implementación de ConversationDirectory |
| Eventos transaccionales, outbox, canales y sincronización | CollaborationRealtime existente |
| Intenciones y preferencias de notificación | CollaborationCore; consulta autorizada mediante NotificationInbox de Realtime |
| Interfaz web y cliente de sincronización | TeamChat |
| Llamadas, multimedia, LiveKit y adaptadores CallsGateway | Claude Code / TeamCalls; pendientes de integración |

La implementación de ConversationDirectory proporciona resolve, authorize, eligibleInvitees, membership y accessVersion con las firmas aprobadas. Sólo admite ConversationType::Direct. Para llamadas valida que ambos participantes sigan activos y autorizados; eligibleInvitees exige seleccionar exactamente al otro participante. No determina quién puede finalizar una llamada ni proporciona tokens multimedia.

Claude Code deberá consumir este binding en una futura base Git acordada, adaptar sus referencias de conversación y volver a autorizar cada operación. La revocación multimedia verificable y la rotación de salas siguen bajo responsabilidad de TeamCalls. Esta fase no integra ramas ni prueba adaptadores de llamadas.

## Persistencia y servicios

Migración `Modules/TeamChat/database/migrations/2026_11_04_000000_create_teamchat.php`:

| Tabla / modelo | Contenido e índices relevantes |
|---|---|
| collab_chat_conversations / Conversation | Pareja directa, institución, secuencia de mensajes, versión de eventos y acceso, actividad. Unicidad de pareja y orden de actividad. |
| collab_chat_participants / Participant | Membresía individual activa. Unicidad conversación/usuario; índice institucional por usuario. |
| collab_chat_messages / Message | ULID, remitente, secuencia, texto y hash del comando. Unicidad de secuencia e idempotencia; índice de historial. |
| collab_chat_receipts / Receipt | Progreso individual por mensaje: delivered_at y read_at. Unicidad mensaje/destinatario. |

Los modelos aplican el scope institucional existente y no admiten asignación masiva. Las escrituras pasan por ChatCommands; ChatAccess comprueba permisos actuales; ChatQueries presenta datos; ChatCursor protege la paginación; ChatEvents registra eventos; ChatEventPolicy valida sus esquemas y acceso actual.

El bloqueo de institución precede a los usuarios ordenados y a la conversación, en coherencia con los servicios existentes. Es conservador y serializa escrituras por institución; debe medirse capacidad antes de una implantación de gran volumen. No se afirma escalabilidad ilimitada.

## API v1

Todas las rutas siguientes llevan el prefijo `/api/v1/collaboration`, autenticación de sesión existente, MFA verificado, CSRF para escrituras, ActorContext del servidor y autorización collaboration.use.

| Método y ruta | Entrada |
|---|---|
| GET /teamchat/directory | q, cursor, limit |
| GET /conversations | cursor, limit |
| POST /conversations/direct | participant_id |
| GET /conversations/{id} | — |
| GET /conversations/{id}/messages | cursor, limit; opcional ids[] para actualizar recibos concretos |
| POST /conversations/{id}/messages | command_id, body |
| POST /conversations/{id}/receipts | message_ids[] (1–50), kind: delivered/read |
| PUT /conversations/{id}/membership | active: false, salida propia; nunca sustitución de participantes ni autorreactivación |

Respuestas `{data, meta: {request_id, version: "1.0"}}`, sin caché. Se conservan errores de Core: 401/403 para sesión/dispositivo/permisos, 404 para recursos no accesibles, 409 idempotency_conflict, 410 sync_cursor_expired, 422 invalid y 429 por límite. Texto UTF-8 no vacío, máximo 5000 caracteres y sin NUL. La API rechaza campos de suplantación de remitente/institución/secuencia.

Límites: 120 peticiones/minuto compartidas por usuario en la API existente; además, 30 comandos/minuto en un limitador nombrado propio. No se habilita autenticación móvil alternativa.

Un participante puede conservar su historial cuando el otro sea desactivado, pero no enviarle mensajes ni iniciar llamadas. Perder el propio permiso, sesión, dispositivo o membresía impide nuevas consultas. La salida propia no permite eludir una revocación mediante reingreso automático. Las invitaciones o reincorporaciones administrativas requieren otra fase.

## Eventos y transporte

Eventos v1, aggregate.type = teamchat_conversation; aggregate.id = ULID de conversación:

| Tipo | data |
|---|---|
| teamchat.conversation.created | conversation_id |
| teamchat.message.created | conversation_id, message_id, sequence |
| teamchat.receipt.delivered | conversation_id, message_ids, user_id |
| teamchat.receipt.read | conversation_id, message_ids, user_id |
| teamchat.membership.changed | conversation_id, user_id, active |

No incluyen texto del mensaje. ChatEvents utiliza exclusivamente TransactionalEventRecorder; no publica directamente a Reverb. Cada cambio tiene una versión creciente del agregado y audiencia de ambos participantes; la entrega y la sincronización vuelven a comprobar acceso vigente.

El cliente habla el protocolo WebSocket de Reverb/Pusher: autoriza su canal privado de dispositivo, espera suscripción y consume únicamente collaboration.sync.required. Captura el corte de bootstrap antes de recuperar estado; después reproduce los eventos posteriores. Avanza el cursor sólo tras aplicar lecturas autorizadas, deduplica por evento y mensaje y reconecta con espera creciente. Un fallback incremental consulta cada 10 segundos en primer plano y cada 30 en segundo plano; no se presenta como señalización de llamadas.

La lectura requiere conversación activa, documento visible con foco y mensaje dentro del área visible. Los estados se actualizan por IDs concretos en otros dispositivos. El cliente no considera la aceptación de Reverb como entrega al destinatario.

Revocación: no es posible retirar contenido ya descargado. Nuevas lecturas quedan denegadas en el servidor; el cliente limpia su contenido ante revocación de sesión/dispositivo y revalida acceso periódicamente. Un socket ya abierto puede recibir una señal sin contenido durante una carrera, según la garantía documentada en Fase 3; el texto permanece protegido por la API.

## Interfaz y operación

Ruta `/colaboracion` y enlace «Colaboración» sujeto a collaboration.use, fuera del grupo comercial RequireCrmAccess. Shell, iconos y tipografía existentes; colores azul, marino y dorado MCA. Dos paneles en escritorio; lista/conversación con regreso en móvil. Búsqueda, avatares de iniciales, presencia de Core, historial, compositor con Enter y Shift+Enter, reintento, avisos discretos, estados de conexión y recursos ES/EN.

No muestra controles de llamada inexistentes. El texto se inserta mediante textContent, sin HTML del usuario. Las novedades actualizan recursos afectados y reutilizan los nodos; no recargan la página o todo el componente por mensaje.

Se requiere el servicio de Reverb y el consumidor outbox de Fase 3 operativos. TEAMCHAT_REVERB_HOST/PORT/SCHEME permiten definir la dirección pública del navegador independientemente de la dirección interna del publicador. Sólo la clave pública llega al cliente; nunca REVERB_APP_SECRET. En infraestructura real se requiere HTTPS/WSS, orígenes concretos y procesos supervisados, pendientes de autorización de despliegue.

No se implementa cifrado de extremo a extremo, FCM ni APNs. El almacenamiento está protegido por autorización institucional; cifrado de disco/copias y operación de transporte corresponden a la infraestructura.

## Verificación local

Entorno exclusivo: MySQL 8.4.3 en 127.0.0.1:33317 con datadir de este Worktree `.testing/mysql`, base `crm_mca_collaboration_core_test`. HTTP 127.0.0.1:33319 y Reverb 127.0.0.1:33318. Ninguna migración usa la base del repositorio principal o producción.

Comandos reproducibles usando PHP 8.3 con las extensiones locales indicadas en las fases anteriores:

- `vendor/bin/pest -c phpunit.collaboration.xml Modules/TeamChat/tests/Feature Modules/CollaborationCore/tests/Feature Modules/CollaborationRealtime/tests/Feature Modules/Core/tests Modules/Identity/tests tests/Feature/Auth --compact`.
- `vendor/bin/pest -c phpunit.contracts.xml --compact`.
- `tests/TeamChatConcurrency.php`: seis procesos reales para crear la pareja y seis para enviar/reintentar.
- `tests/TeamChatBrowserServer.php seed`: dos cuentas ficticias .invalid con MFA. Ejecutar su modo reverb, drain y el router HTTP mediante PHP -S. `tests/TeamChatBrowser.cjs` usa Playwright/Chrome instalado; PLAYWRIGHT_MODULE permite el paquete ya disponible y TEAMCHAT_PHP la ruta de PHP. El modo cleanup retira exclusivamente el fixture.
- `vendor/bin/phpstan analyse -c phpstan.teamchat.neon --no-progress --memory-limit=512M`.
- `vendor/bin/pint ... --test`, `npm run build`, `git diff --check`.

Resultados finales (15 de octubre de 2026):

| Validación | Resultado |
|---|---|
| Suite conjunta TeamChat, Core, Realtime, tenancy, identidad y autenticación | **152 pruebas / 555 aserciones**, correctas. Incluye 17 pruebas TeamChat. |
| Contratos compartidos | **28 pruebas / 276 aserciones**, correctas. Ninguna firma alterada. |
| TeamChatConcurrency.php | **20 comprobaciones**, correctas: seis creadores, seis envíos concurrentes, unicidad, secuencia, atomicidad y regresión de reintento SQL. |
| RealtimeIntegration.php | Correcto: concurrencia real, recuperación de lease, reintentos, deduplicación y cola persistente Laravel. |
| RealtimeWebSocket.php | **14 comprobaciones** con Reverb real, correctas: suscripción, reconexión, origen, canal falsificado y revocación. |
| TeamChatBrowser.cjs | **27 comprobaciones** con Chrome real: dos cuentas MFA, mensajes en ambos sentidos, contador, lectura, borrador, XSS, desconexión, reintento, historial y móvil. |
| TeamChatDevicesBrowser.cjs | **11 comprobaciones** con sesiones independientes: dispositivos distintos del mismo usuario, lectura compartida, preferencias y revocación. |
| TeamChatLayoutBrowser.cjs | **12 comprobaciones** con código final: historial intacto durante bootstrap, reintento, limpieza del error, Shift+Enter, móvil y ausencia de errores JavaScript. |
| PHPStan nivel 6 | TeamChat y CollaborationRealtime: sin errores. |
| Laravel Pint | 24 archivos PHP: correctos en modo --test. |
| Vite | Compilación correcta. Recursos generados actualizados junto con el manifest que este repositorio ya versionaba. |
| Git diff --check | Correcto. |

**Evidencia real:** dos cuentas institucionales ficticias, cookies independientes, login normal y TOTP; mensajes persistidos en MySQL y avisos WebSocket emitidos por Reverb. Se verificó la recuperación sin duplicados al interrumpir la conexión y reabrir el contexto del navegador. Otra prueba usó dos dispositivos diferentes del mismo destinatario. La reconciliación de Core confirmó 46 intenciones con aceptación de transporte durante el ensayo completo; esa aceptación no se etiquetó como entrega o lectura.

Se inspeccionaron visualmente las capturas locales `.testing/teamchat-desktop.png` y `.testing/teamchat-mobile.png` (1440×1000 y 390×844). Permanecen como evidencia local fuera del commit; los scripts reproducibles sí se versionan. El ensayo final dejó 42 eventos publicados, sin pendientes ni fallidos, antes de retirar el fixture.

**Correcciones verificadas durante integración:** el shell ya no monta pollers comerciales para usuarios sin sus permisos; un bootstrap tardío no vacía la conversación que el usuario acaba de seleccionar; un reintento exitoso retira el error anterior. La toma SQL de outbox ahora reintenta hasta tres veces un interbloqueo antes de publicar por red. Fue necesario tras observar un deadlock real al insertar una ráfaga de mensajes mientras el consumidor reclamaba eventos. La corrección es de implementación en CollaborationRealtime, sin cambio contractual. Una nueva ráfaga real se drenó completamente.

**Simulación identificada:** el test de reintento de envío aborta una solicitud de navegador deliberadamente; el test determinista de deadlock inyecta una excepción SQL y sustituye sólo el transporte para comprobar rollback/reintento. Además se ejecutaron la concurrencia MySQL, las ráfagas y los ensayos Reverb reales descritos arriba. No se confunden esos tests con carga de producción.

**Límites:** sólo sesiones web existentes; no autenticación móvil, FCM/APNs, E2EE, LiveKit ni llamadas. Chrome de escritorio y viewport móvil, no Android/iOS físicos. No se ha validado capacidad de producción ni operación multiserver. Las credenciales, datos ficticios y listeners pertenecen exclusivamente al laboratorio local y se retiran al cerrar el ensayo. Persisten la base aislada y sus migraciones para reproducir las pruebas. El funcionamiento en otro entorno requiere configuración, migraciones autorizadas, Reverb y consumidor supervisado; no se desplegó en el CRM principal.

## Siguientes fases y requisito conservado

Antes de ampliar funcionalidades: acordar la integración Git con Claude Code, pruebas de sus adaptadores sobre esta implementación de ConversationDirectory y revocación multimedia verificable. La fase funcional siguiente puede abordar equipos/grupos/canales con políticas explícitas y sin alterar las conversaciones directas. Requiere nueva autorización.

Fase futura independiente: cada asesor o colaborador autorizado podrá crear varios enlaces públicos para visitantes externos, revocables, regenerables y con caducidad configurable. Los visitantes tendrán identidad, permisos y aislamiento propios. No usar cuentas institucionales falsas ni acceso anónimo a API internas. No está implementado en esta fase.
