# TeamChat — Fase 6: voz, imágenes y adjuntos privados

Base de coordinación: `588f8ce2cb560d8145184dc00f235767b01f6358`.
Rama: `feature/collaboration-teamchat`.
Worktree: `C:\Users\Maikel\Proyectos Claude Code\CRM-MCA-teamchat`.

## Implementación

La interfaz existente de Colaboración permite grabar, cancelar, previsualizar y enviar notas de voz; seleccionar imágenes, ampliarlas y descargarlas; compartir PDF, documentos DOCX, hojas XLSX, presentaciones PPTX y texto UTF-8 TXT/CSV. Funciona en conversaciones directas, grupos y canales estándar/privados. El compositor y los mensajes conservan traducciones ES/EN, etiquetas accesibles, controles nativos de audio y diseño móvil.

MediaRecorder selecciona dinámicamente WebM/Opus, MP4 o Ogg/Opus mediante `isTypeSupported`. El servidor admite también WAV y MP3, comprueba que exista exactamente una pista de audio y normaliza las notas a MP3 mono 44.1 kHz/96 kbps. No se usa LiveKit. El reproductor ofrece pausa, progreso y velocidades 1×, 1.5× y 2×. Límite inicial: cinco minutos. La entrada del micrófono se libera al detener, cancelar o abandonar la página; los callbacks de una captura cancelada no pueden interferir con la siguiente.

Referencias de plataforma: [MediaRecorder.isTypeSupported](https://developer.mozilla.org/en-US/docs/Web/API/MediaRecorder/isTypeSupported_static), [mimeType](https://developer.mozilla.org/en-US/docs/Web/API/MediaRecorder/mimeType), [getUserMedia](https://developer.mozilla.org/en-US/docs/Web/API/MediaDevices/getUserMedia). La selección dinámica no equivale a certificación en dispositivos físicos: Safari/iOS/Android quedan pendientes.

## Persistencia y servicios

- Migración `2026_11_07_000000_create_chat_uploads.php`: tabla `collab_chat_uploads`, con institución, remitente, conversación, mensaje opcional, ULID, comando/hash idempotentes, tamaño esperado/real, estado, MIME, periodo de acceso, duración/dimensiones, caducidad y resultado del análisis.
- `ChatAttachments`: preparación, validación de propiedad y permisos, carga, asociación transaccional, estado, cancelación y depuración.
- `AttachmentValidation`: inspección MIME real, límites, imágenes, audio y documentos.
- `AttachmentScanner` / `UnavailableAttachmentScanner`: puerto interno de TeamChat para antivirus. No modifica contratos compartidos.
- `AttachmentController`: API y entrega privada autenticada.
- `PruneUploads`: comando de limpieza de cargas caducadas no asociadas.
- `attachments.js/css`: captura, borradores, transferencias, reintento, reproductor, documentos y visor.
- Cambios acotados a `ChatCommands`, `ChatQueries`, controlador de mensajes, proveedor, rutas, configuración, traducciones y vista del módulo.

Los adjuntos se asocian dentro de la transacción del mensaje existente. Se mantienen ID, secuencia, reloj de historial, recibos, outbox, notificaciones y sincronización. Los eventos conservan sus payloads de referencia a mensaje; nunca incluyen binarios, nombres de archivo, hashes, rutas físicas o credenciales. Los mensajes de texto mantienen su hash idempotente anterior.

Estados: `prepared → ready → asociado a message_id`; cancelación explícita `cancelled`; cargas no asociadas caducadas `expired`. Una transferencia parcial no alcanza `ready`. El hash SHA-256 y tamaño esperado verifican integridad de entrada; no son una certificación antimalware. Los reintentos conservan el comando de preparación, la referencia de carga y el comando del mensaje.

## API v1

Prefijo `/api/v1/collaboration`; mismo ActorContext, sesión, MFA, dispositivo, autorización institucional, CSRF y limitadores existentes.

| Operación | Endpoint | Datos |
|---|---|---|
| Preparar | POST /conversations/{id}/uploads | command_id, name, kind, mime, size, sha256 |
| Subir | POST /uploads/{id}/content | multipart file |
| Estado/metadatos | GET /uploads/{id} | Estado y metadatos autorizados |
| Cancelar borrador | DELETE /uploads/{id} | No permite borrar adjuntos asociados |
| Asociar/enviar | POST /conversations/{id}/messages | command_id, body opcional, attachments: lista de ULID |
| Historial | GET /conversations/{id}/messages | Mensajes con attachments |
| Contenido | GET /uploads/{id}/content | Imagen/audio inline; documentos como descarga |
| Descargar | GET /uploads/{id}/content?download=1 | Content-Disposition attachment |

Respuesta de metadatos: id, name, kind, mime, size, state, scan_status, duration_ms, width, height, content_url. El URL es una ruta autenticada estable, no un permiso transferible ni enlace público. Las futuras aplicaciones móviles podrán reutilizar estos recursos mediante el esquema de autenticación común que se apruebe; esta fase no crea autenticación móvil.

Errores utilizan los códigos existentes: 401/403 por sesión/autorización, 404 para recursos no visibles, 409 para cambio de payload de un comando, 422 por contenido inválido, 429 por límites, 503 cuando la política exige antivirus y éste no está disponible. Un fallo de transferencia conserva el borrador para reintentar. Tras cerrar completamente la página no se persisten archivos locales pendientes; se limpian por caducidad en el servidor.

## Seguridad, configuración y retención

Disco Laravel `teamchat_private`, local y privado, con `serve=false`, raíz predeterminada `storage/app/private/teamchat`. Nunca se enlaza a `public/storage`. Clave interna: institución/ULID/content. Las descargas revalidan institución, membresía, elegibilidad, sesión/dispositivo y periodo histórico. Al reingresar a un grupo/canal no se recuperan adjuntos de un periodo histórico ya cerrado. Los enlaces antiguos dejan de funcionar cuando se pierde autorización. Los bytes ya descargados por un usuario autorizado no pueden retirarse de su dispositivo.

Cabeceras: no-store/private, nosniff, CSP sandbox/default-src none, same-origin, no-referrer y Content-Disposition. Los nombres se muestran como texto, nunca HTML; se rechazan rutas, controles y caracteres bidireccionales. Las peticiones Range del audio pasan por el mismo endpoint autorizado.

Límites iniciales: 20 MiB por archivo, cuatro por mensaje, 20 cargas pendientes vigentes por usuario, 24 h de staging. Imágenes JPEG/PNG/WebP hasta ocho megapíxeles se decodifican y recodifican en JPEG de hasta 2048 px por lado, descartando metadatos y datos anexados. La conversión es deliberadamente con pérdida y aplana transparencias.

PDF se entrega exclusivamente como descarga y se rechazan patrones activos conocidos. OOXML no se extrae a disco: se limitan entradas/tamaños descomprimidos, se rechazan macros, cifrado, objetos incrustados, relaciones externas, DTD/entidades y contenido ejecutable conocido. Los formatos heredados DOC/XLS/PPT, SVG, HTML, ejecutables y ZIP genéricos no están autorizados. Estas validaciones estructurales **no sustituyen un antivirus** y pueden rechazar documentos legítimos con características activas.

Variables: TEAMCHAT_PRIVATE_ROOT, TEAMCHAT_MAX_UPLOAD_BYTES, TEAMCHAT_MAX_ATTACHMENTS, TEAMCHAT_FFMPEG, TEAMCHAT_FFPROBE, TEAMCHAT_REQUIRE_SCAN. Otros límites y lista MIME/extensiones están en la configuración del módulo. FFmpeg/FFprobe, GD, fileinfo y ZipArchive deben estar disponibles; se utilizaron binarios ya existentes localmente, sin instalaciones.

El adaptador predeterminado devuelve **not_scanned**, nunca clean. TEAMCHAT_REQUIRE_SCAN=true bloquea la aceptación hasta incorporar un scanner real. No se probó un antivirus real. Antes de producción debe decidirse esta política e integrarse el motor y sus pruebas operativas.

Ejecutar periódicamente `teamchat:prune-uploads --all-institutions` (o --institution=ID) desde el scheduler institucional. No se instaló un scheduler de producción. Se eliminan los bytes de cargas no asociadas caducadas y se conserva su registro para trazabilidad/idempotencia. Los adjuntos asociados se conservan con el historial, sin caducidad automática en esta fase. Eliminaciones administrativas por SQL/cascada requieren reconciliar los bytes huérfanos; no se debe borrar almacenamiento fuera de este flujo sin una política aprobada. Una escritura de archivo que sobreviva a un rollback permanece inaccesible mientras el registro no esté ready y será recuperable por reintento o limpieza.

## Integración y límites

CollaborationCore y CollaborationRealtime se consumen tal como estaban en la base. No se cambiaron interfaces compartidas, TeamCalls, LiveKit ni otros Worktrees. Claude Code no necesita adaptar sus firmas por estos adjuntos. Los eventos internos de revocación 4B siguen dependiendo de su consumidor de TeamCalls; la verificación de transporte del navegador distingue esos eventos internos de la entrega de mensajes a Reverb.

No se creó TeamFiles ni una biblioteca paralela: cada carga pertenece a una conversación y, tras envío, a un mensaje. TeamFiles podrá reutilizar infraestructura de bytes/antivirus, con sus propias entidades y reglas; no debe heredar automáticamente los permisos del historial de TeamChat.

Requisito futuro conservado, sin implementar: enlaces de conversación para colaboradores con generación múltiple, regeneración, caducidad y revocación; distinguirlos de enlaces para visitantes externos y definir su autorización antes del desarrollo.

## Validación

Todas las bases, usuarios, sesiones, archivos y servicios usados fueron locales de testing; no se utilizaron datos ni credenciales de producción.

| Validación | Resultado |
|---|---|
| Pest: TeamChat, Core, Realtime, Core base, Identity, Institutions, autenticación y seguridad | **202 pruebas, 940 aserciones**, todas aprobadas; 129.27 s |
| Subconjunto nuevo de adjuntos, incluido rollback de cancelación | 13 pruebas / 79 aserciones incluidas en el total anterior |
| Contratos compartidos | **30 pruebas, 320 aserciones**, todas aprobadas |
| Concurrencia de adjuntos MySQL | **31 comprobaciones**, 20 procesos en cuatro escenarios |
| Regresión de concurrencia de mensajes | **20 comprobaciones** |
| Regresión de grupos, equipos y canales | **42 comprobaciones**, 28 procesos en cinco escenarios |
| Recuperación, outbox y cola persistente | **35 comprobaciones** |
| Reverb WebSocket real | **14 comprobaciones** |
| Chrome real: HTTP, MFA, MySQL, Reverb, archivos y tres sesiones | **33 comprobaciones**, sin errores JavaScript no capturados |
| Entrega de mensajes del navegador | Outbox de mensajería drenada; seis notificaciones con transporte aceptado |
| PHPStan nivel 6 | Sin errores en teamchat, collaboration, realtime y contracts |
| Laravel Pint | 19 archivos PHP afectados, aprobado |
| Vite | Compilación aprobada, nueve módulos |
| Git diff --check | Aprobado |

La regresión incluye las pruebas existentes de TeamChat Fases 4/5 y compatibilidad 4B. Las carreras verifican preparación repetida, cargas simultáneas, envío idempotente y carga contra expulsión; tras revocar, el usuario no puede consultar ni asociar el archivo. Las pruebas anteriores de espacios mantienen secuencias, historial y propietario único.

**Servicios reales:** MySQL aislado en 33317, Reverb en 33318, HTTP local en 33319, disco privado local, FFmpeg/FFprobe y Chrome instalado. Las pruebas de audio generaron y procesaron WebM, Ogg, MP4 y WAV reales. Chrome grabó, envió y reprodujo una nota, recuperó archivos tras desconexión y cambio de conversación, y descargó desde un segundo dispositivo registrado.

**Simulaciones/dobles:** dispositivo de captura de Chrome (no micrófono físico), denegación de getUserMedia, desconexión de un contexto y aborto controlado de una transferencia. Pest utiliza el disco temporal de Storage::fake y fixtures institucionales; las pruebas de recuperación inyectan fallos de transporte/deadlock además de los escenarios con procesos reales. El scanner sigue siendo el adaptador no disponible; no se simuló una aprobación antimalware como si fuese real.

**Correcciones durante validación:** nombre de descarga preservado mediante Content-Disposition; aislamiento de callbacks entre grabaciones canceladas/reiniciadas; cancelación de bytes diferida hasta commit, con rollback probado; espera explícita de decodificación/renderizado antes de capturar imágenes y audio. PHPStan necesitó --memory-limit=512M en este entorno; no se desactivaron reglas.

Evidencias locales (ignoradas por Git): `.testing/teamchat-phase6-desktop.png`, `.testing/teamchat-phase6-mobile.png`, `.testing/phase6-browser.log`, `.testing/phase6-regression.log`, `.testing/phase6-concurrency.log/json`, `.testing/phase6-chat-concurrency.log`, `.testing/phase6-spaces-concurrency.log`, `.testing/phase6-realtime.log`, `.testing/phase6-websocket.log`.

Scripts reproducibles versionados: tests/TeamChatAttachmentsBrowser.cjs, tests/TeamChatAttachmentsConcurrency.php y el servidor de fixtures TeamChatBrowserServer.php. Usan la configuración aislada existente. No ejecutar simultáneamente RefreshDatabase y los scripts de navegador/concurrencia sobre la misma base.

Los usuarios ficticios, sesiones y archivos del navegador se retiraron al finalizar. No se realizó push ni despliegue. El commit de entrega es el que contiene este documento; su hash se proporciona en el informe final.

## Pendientes antes de producción

Activar un antivirus real o aprobar explícitamente la política not_scanned; endurecer/aislar los procesos de conversión y mantener sus binarios actualizados; dimensionar cuotas y concurrencia de transcodificación; alinear límites PHP/proxy con el módulo; instalar limpieza periódica, respaldos privados y retención institucional; probar Safari/Android/iOS y micrófonos físicos. La transcodificación actual es síncrona y acotada por timeout: no se ha validado capacidad de producción.
