# TeamChat — Fase 7: interacciones y organización de mensajes

Base: `771758f5d047a0b9565d477f24c90a4deadd0986`.
Rama: `feature/collaboration-teamchat`.
Worktree: `C:\Users\Maikel\Proyectos Claude Code\CRM-MCA-teamchat`.

## Alcance implementado

Respuestas persistentes, reacciones explícitas, menciones verificadas, edición de texto, eliminación lógica, búsqueda por conversación y mensajes fijados. Se conserva el motor de mensajes de las Fases 4/5/6: identificadores, secuencias, periodos de acceso, recibos, adjuntos, voz, outbox, notificaciones y transporte Reverb.

No se modificaron interfaces compartidas, CollaborationCore, CollaborationRealtime, TeamCalls, LiveKit ni otros Worktrees. Las extensiones del protocolo son propias de TeamChat y aditivas. No se realizó ninguna operación remota Git ni despliegue.

## Persistencia y servicios

Migración `2026_11_08_000000_add_message_interactions.php`:

- `collab_chat_messages`: reply_to_id, version (inicial 1), edited_at, deleted_at.
- `collab_chat_reactions`: unicidad mensaje/usuario/emoji y tick de acceso.
- `collab_chat_mentions`: referencias mensaje/usuario únicas, estado activo y tick; conserva referencia tras retirar la mención para deduplicar avisos.
- `collab_chat_pins`: un registro por mensaje.
- `collab_chat_message_commands`: idempotencia por institución/actor/comando y hash del payload.
- `collab_chat_message_audit`: actor, operación, versión, fecha y hashes anterior/posterior; no expone ni duplica el texto en el registro de auditoría.

`MessageInteractions` concentra autorización de mensajes, escritura de interacciones, menciones y proyección autorizada. `InteractionController` incorpora acciones, búsqueda, miembros mencionables, fijados y resolución de una notificación de mención. `ChatCommands` mantiene el envío único y acepta reply_to_id opcional; los envíos anteriores conservan su hash idempotente. `ChatQueries` añade metadatos y produce tombstones sin contenido para mensajes eliminados. `ChatAttachments` cambia únicamente para rechazar acceso a adjuntos cuyo mensaje fue eliminado.

`interactions.js/css` amplía la vista existente con menú contextual, referencia de respuesta, selector de emojis, menciones por teclado/ratón, editor integrado, confirmación de eliminación, búsqueda y panel de fijados. Se conservan traducciones ES/EN.

## Reglas

**Respuestas:** el mensaje original debe estar en la misma conversación y dentro del periodo de acceso del actor. Se conserva sólo la referencia, no una copia del contenido. Cada destinatario obtiene su propia proyección: neutral si el original es inaccesible, «Mensaje eliminado» si fue borrado y una referencia compacta si es visible. Se permite referenciar voz, imágenes y documentos. La navegación consulta nuevamente el mensaje autorizado.

**Reacciones:** lista inicial configurable 👍 ❤️ 😂 🎉 😮 🙏. Agregar y retirar son operaciones distintas, nunca toggles de backend. Misma reacción/actor/mensaje no se duplica. Las cantidades y participantes visibles excluyen membresías revocadas y periodos anteriores. No se permite reaccionar a un mensaje eliminado.

**Menciones:** el cliente inserta nombres legibles seleccionados del directorio del espacio y envía tokens `@[ID]`; el servidor valida todos los identificadores contra institución, elegibilidad, membresía y acceso al mensaje. Máximo diez usuarios únicos por mensaje. Un nombre escrito sin selección no implica automáticamente una mención. No hay @todos/@everyone. Las referencias se renderizan como texto marcado, sin HTML aportado por usuarios.

Se emite un aviso de mención como máximo una vez por mensaje/usuario. Editar conservando la mención o retirarla y volver a agregarla no crea otro intento. Agregar por primera vez un destinatario válido durante una edición sí genera un aviso. No se notifica al propio autor. Los destinatarios mencionados se excluyen del aviso genérico inicial para evitar dos alertas por el mismo envío. Se usan NotificationDispatcher, preferencias compartidas, dispositivos y la outbox existentes; no se crea otro canal de push. La elegibilidad se vuelve a verificar antes de entrega y antes de navegar al mensaje.

**Edición:** sólo el autor, con permiso vigente de envío, texto no vacío y dentro de 15 minutos configurables. Edita únicamente el texto; no reemplaza archivos ni notas de voz. Un mensaje multimedia sin texto no adquiere texto mediante edición. Los captions existentes sí pueden editarse. version es obligatoria en la semántica de la operación; una versión obsoleta devuelve el conflicto existente 409/idempotency_conflict. No cambian ID, secuencia ni recibos. El editor conserva el borrador y exige revisar la versión actual tras conflicto.

**Eliminación:** sólo el autor dentro de 24 horas configurables. Confirmación explícita en interfaz. Conserva fila, ID, secuencia y recibos; incrementa versión y audita. El contenido deja de aparecer en API, búsqueda, referencias, notificaciones y fijados. Se retiran reacciones y fijación y se desactivan menciones. Los bytes de adjuntos permanecen privados según retención, pero sus URLs autenticadas dejan de servir contenido. No hay borrado físico ordinario ni permiso implícito de moderación para administradores CRM.

**Fijación:** miembros en conversaciones directas; propietarios/administradores del grupo; roles de administración del canal (membresía de equipo para canales estándar, membresía explícita para privados). Límite inicial de diez fijados por conversación. Roles comerciales/CRM no confieren capacidad de fijar. La lista filtra periodo histórico y estado del mensaje.

Configuración en `teamchat.interactions`: edit_minutes, delete_hours, pin_limit y emojis.

## API v1

Prefijo `/api/v1/collaboration`; mismo ActorContext, MFA, sesión/dispositivo, autorización institucional, CSRF y envoltorio de respuesta/error. Ningún GET cambia recibos o membresías.

| Operación | Endpoint |
|---|---|
| Enviar respuesta | POST /conversations/{id}/messages, con reply_to_id opcional |
| Consultar referencias | GET de mensajes existente; nuevo campo reply |
| Escribir interacción | POST /conversations/{id}/messages/{message}/actions/{operation} |
| Resolver sugerencias | GET /conversations/{id}/mention-members?q=…&cursor=… |
| Buscar | GET /conversations/{id}/search?q=…&limit=…&cursor=… |
| Consultar fijados | GET /conversations/{id}/pins |
| Resolver aviso de mención | GET /conversations/{id}/mention-target/{event} |

operation: edit, delete, react, unreact, pin o unpin. Todas las escrituras llevan command_id; edit/delete incluyen version; edit incluye body; react/unreact incluyen emoji. Respuesta: estado autorizado actual del mensaje. Repetir un comando no vuelve a cambiar estado; reutilizarlo con otro payload produce 409. Un reintento antiguo de «agregar reacción» no revierte una retirada posterior.

Metadatos adicionales de mensaje: version, deleted, edited_at, reply, mentions, reactions, pinned, can_edit, can_delete, can_pin. Las capacidades del cliente son orientativas: el servidor revalida en cada escritura.

## Búsqueda y privacidad

Se inspeccionó MySQL **8.4.3** y los índices chat_history (institución/conversación/secuencia), chat_access_history (conversación/tick/secuencia) y chat_message_sequence. Se eligió LIKE parametrizado con comodines escapados para admitir fragmentos, incluyendo palabras que FULLTEXT podría omitir por stopwords o longitud. No se conectan servicios externos ni se incorporan índices semánticos.

Cada consulta se restringe primero a institución, conversación, periodo vigente y mensajes no eliminados. Explora como máximo 5.000 filas autorizadas por tramo y devuelve hasta 50 resultados (20 por defecto). El cursor permite continuar por tramos anteriores incluso cuando un tramo no contiene coincidencias. Los cursores cifrados se vinculan a actor, institución, conversación, periodo y término; no contienen contadores públicos. Límites: término de 2–100 caracteres y 20 consultas/minuto por usuario. La búsqueda global entre conversaciones queda fuera de esta fase; el usuario elige primero una conversación.

No se ha probado MariaDB ni volumen de producción. El límite por tramo controla coste, pero no sustituye una prueba de capacidad con los tamaños institucionales reales.

## Transacciones y eventos

Las escrituras bloquean primero la institución, como el motor existente, y validan membresía dentro de la misma transacción. Cambio de dominio, auditoría, deduplicación y evento/outbox se confirman o revierten juntos. La serialización protege versión de texto, unicidad de reacciones y límite de fijados.

Nuevos eventos v1:
- teamchat.message.edited
- teamchat.message.deleted
- teamchat.message.reaction.changed
- teamchat.message.pin.changed
- teamchat.message.mentioned

Los primeros cuatro sólo incluyen conversation_id, message_id y sequence. El de mención incluye conversation_id, message_id y user_id y sólo puede recuperarlo su destinatario mientras conserve acceso. No incluyen texto, nombres, archivos, tokens ni rutas privadas.

El socket sigue enviando avisos mínimos de sincronización. Los clientes consumen el mismo feed por cursor y recuperan estado autorizado de la API. Las mutaciones refrescan también respuestas visibles para evitar fragmentos obsoletos. La reconciliación ignora una respuesta HTTP con versión de texto inferior a la ya cargada, evitando restaurar un texto antiguo o un mensaje eliminado por respuestas fuera de orden. Tras bootstrap/reconexión se reconcilian mensajes cargados, sin duplicar interacciones. Buscar y abrir fijados no marca mensajes como leídos; navegar al mensaje puede generar el recibo explícito habitual al quedar visible.

## Pruebas y evidencias

Se utilizan exclusivamente MySQL, HTTP, Reverb, archivos y cuentas ficticias aisladas. Las suites destructivas se ejecutaron secuencialmente.

| Validación | Resultado |
|---|---|
| Pest: TeamChat, Core, Realtime, Core base, Identity, Institutions, autenticación y seguridad | 221 pruebas, 1.042 aserciones, aprobadas; 179,80 s |
| Contratos compartidos 1.1.0 | 30 pruebas, 320 aserciones, aprobadas; interfaces sin cambios |
| Concurrencia MySQL de interacciones | 5 escenarios, 22 procesos independientes, aprobados |
| Recuperación de outbox/colas MySQL | 35 comprobaciones aprobadas |
| WebSocket Reverb real | 14 comprobaciones aprobadas |
| Chrome Fase 7: dos cuentas, tres sesiones/dispositivos | 44 comprobaciones aprobadas |
| Chrome regresión Fase 6 | 33 comprobaciones aprobadas |
| Reconciliación JS fuera de orden | 5 aserciones aprobadas sobre la función de producción |
| PHPStan nivel 6 | Sin errores en TeamChat, CollaborationCore, CollaborationRealtime y contratos |
| Laravel Pint | 39 archivos comprobados, aprobado |
| Vite | Compilación aprobada, 11 módulos |
| Diff | git diff --check aprobado |

Concurrencia: seis ediciones sobre la misma versión produjeron un ganador y cinco conflictos; seis altas simultáneas de la misma reacción produjeron una fila; eliminar frente a reaccionar no dejó reacciones residuales; seis fijaciones respetaron el límite de dos configurado en prueba; reaccionar frente a revocar dejó al participante sin reacción visible y sin permiso para nuevas operaciones. Todas las filas de eventos tuvieron outbox asociada. Duración de cada carrera (sin arranque): 412,25 / 433,16 / 206,67 / 632,28 / 201,77 ms; son mediciones locales, no garantías de capacidad.

**Servicios reales:** MySQL 8.4.3 en 127.0.0.1:33317 con datadir .testing/mysql; HTTP local con MFA y sesiones registradas; Laravel Reverb local en 33318; outbox y avisos Core; Chrome instalado; almacenamiento privado temporal y procesamiento FFmpeg. Los flujos Chrome terminaron con outbox entregada y recibos de transporte Core (2 en Fase 7; 6 en regresión multimedia). La verificación excluye los eventos internos de revocación reservados al consumidor de TeamCalls, como en Fase 6; no se afirma validación de ese consumidor.

**Dobles y simulaciones:** fixtures institucionales y Storage::fake en Pest; micrófono simulado de Chrome (no hardware físico), desconexión controlada, denegación simulada de getUserMedia y aborto de transferencia. Las pruebas de recuperación inyectan fallos de transporte/deadlock además de ejecutar procesos reales. La vista móvil se comprobó con viewport Chrome de 390 × 844, no en dispositivos físicos Android/iOS. No se probó push APNs/FCM ni LiveKit; el scanner antivirus sigue no disponible.

Regresión cubierta por TeamChatTest, SpacesTest, AttachmentsTest y Compatibility4BTest, además de CoreTest/RealtimeTest. No cambian las interfaces compartidas 1.1.0 ni la integración de presencia, revocación y destinatarios de llamadas 4B.

Evidencia local (ignorada por Git): .testing/phase7-browser.log, .testing/phase7-attachments-browser.log, .testing/phase7-regression-final.log, .testing/phase7-concurrency.log/json, .testing/phase7-realtime.log y .testing/phase7-websocket.log. Capturas revisadas visualmente: teamchat-phase7-desktop.png, teamchat-phase7-mobile.png, teamchat-phase7-media-desktop.png y teamchat-phase7-media-mobile.png, todas dentro de .testing.

Scripts versionados: TeamChatInteractionsBrowser.cjs, TeamChatInteractionsClient.cjs, TeamChatInteractionsConcurrency.php y la regresión TeamChatAttachmentsBrowser.cjs. Los scripts de PHP emplean tests/collaboration-runtime.php o phpunit.collaboration.xml para impedir usar la base normal de la aplicación. No ejecutar suites destructivas mientras haya fixtures de navegador activas.

## Inventario de cambios

Archivos nuevos:
- Modules/TeamChat/app/Services/MessageInteractions.php
- Modules/TeamChat/app/Http/InteractionController.php
- Modules/TeamChat/database/migrations/2026_11_08_000000_add_message_interactions.php
- Modules/TeamChat/resources/js/interactions.js
- Modules/TeamChat/resources/css/interactions.css
- Modules/TeamChat/tests/Feature/InteractionsTest.php
- tests/TeamChatInteractionsConcurrency.php
- tests/TeamChatInteractionsBrowser.cjs
- tests/TeamChatInteractionsClient.cjs
- docs/TEAMCHAT-PHASE-7.md

Adaptaciones: ChatController, ChatCommands, ChatQueries, ChatAttachments, ChatEventPolicy, TeamChatServiceProvider, rutas/configuración/idiomas/vista/compositor de TeamChat y assets compilados. El script de navegador de Fase 6 sólo añade un prefijo opcional para guardar evidencias sin sobrescribir las anteriores.

## Limitaciones y siguiente bloque recomendado

El antivirus sigue pendiente: not_scanned no significa seguro. Esta fase no modifica el scanner ni la transcodificación. Se mantiene la retención de Fase 6; eliminar lógicamente no destruye los bytes ni ofrece una interfaz de recuperación. La auditoría registra hashes/versiones, no un archivo de versiones textuales recuperables.

No hay moderación institucional de mensajes ajenos, búsqueda global/semántica, menciones masivas, notificaciones push nativas nuevas, TeamFiles ni integración multimedia de llamadas. Las ventanas de edición/eliminación son políticas iniciales configurables.

Recomiendo un bloque posterior de seguridad y operación de adjuntos (antivirus real, cuotas, retención y capacidad), coordinando por separado las pruebas de integración de TeamCalls con Claude Code. No se inicia ninguna de esas tareas.

## Requisito futuro: conversaciones de visitantes externos

Cada asesor o colaborador autorizado podrá generar **múltiples enlaces públicos independientes** para recibir conversaciones desde un navegador. Cada enlace tendrá identificación propia, caducidad configurable, regeneración y revocación individual. Los visitantes no necesitarán cuenta institucional ni obtendrán acceso a módulos internos de MCA. Se implementará en una fase posterior independiente, con sus propios controles de abuso, identidad externa y autorización. No está implementado en Fase 7.
