# TeamChat — Fase 8: widget universal de MCA Colaboración

Base verificada antes de editar: 44f66d5071a0d84060be68acfe82809d1bbc8f04, rama feature/collaboration-teamchat, worktree C:\Users\Maikel\Proyectos Claude Code\CRM-MCA-teamchat, inicialmente limpio. Trabajo local exclusivamente. Sin cambios en TeamCalls, main, el worktree de contratos ni los de Claude Code; sin fetch, pull, merge, rebase, push o despliegue. No se instalaron dependencias.

## Resultado implementado

- SDK público public/collaboration/widget.js: montaje único, burbuja, contador institucional, avisos genéricos, iframe aislado y Shadow DOM, posición configurable, apertura/cierre/destrucción y limpieza.
- El iframe reutiliza chat-content.blade.php, teamchat.js, spaces.js, interactions.js y attachments.js. La pantalla completa conserva el mismo contenido. Conversaciones, grupos, equipos, canales, mensajes, historial, recibos y archivos siguen siendo únicos.
- Registro institucional de aplicaciones/orígenes exactos/capacidades/entorno/apariencia y comando administrativo collaboration:widget register|disable|prune.
- Autorización central con MFA y collaboration.use; consentimiento en popup, código de un uso ligado a S256 y credenciales efímeras sólo en memoria. Ninguna identidad aportada por el anfitrión se acepta como autenticación.
- Delegación acotada a la API de Colaboración, sin cookies para las peticiones delegadas ni acceso a endpoints administrativos. Renovación espera las operaciones en vuelo, incluidas subidas. Las peticiones normales del CRM conservan su protección CSRF.
- Coordinador Realtime por institución/usuario/dispositivo con heartbeat, TTL, elección de instancia preferida, reclamación, confirmación y deduplicación por evento. Store intercambiable mediante cache con locks compartidos; database probado, Redis preparado por configuración.
- Preferencias propias visuales/sonido/silencio temporal/conversación. Las actualizaciones parciales preservan ajustes de otras pestañas y el silencio temporal vigente. Audio requiere un gesto en cada página. No se piden permisos de notificaciones del sistema ni se implementa push móvil.
- Integración del layout CRM condicionada a ID registrado/configurado y permiso; no añade burbuja en /colaboracion ni en login.
- Manual de instalación y ejemplos en [COLLABORATION-WIDGET.md](COLLABORATION-WIDGET.md). Se incluye arnés local reproducible y páginas anfitrionas independientes.

Durante la validación se corrigieron el tratamiento de la respuesta de autenticación Reverb, la validación de preferencias globales, el registro de foco reciente y la presión de peticiones de actividad/reclamación. Los enlaces de descarga ahora se muestran después de persistir el mensaje: los archivos provisionales conservan sólo su vista previa local. La desconexión es idempotente ante respuestas concurrentes: retira la UI inmediatamente, no cierra dos veces el audio y descarta renovaciones tardías. Los fallos temporales de carga multimedia no quedan memorizados permanentemente. La reconciliación evita mover nodos multimedia sin cambios, para no reiniciar su reproducción al actualizar el historial. Códigos, verificadores, tokens y leases sensibles están protegidos frente a su inclusión como argumentos en trazas PHP.

## Responsabilidades y contratos

| Componente | Responsabilidad |
|---|---|
| Core / WidgetApplications | Registro confiable y validación de aplicación/origen |
| Core / WidgetSessions | Grants, credenciales, renovación y revocación de instancias |
| Core / WidgetPreferences | Preferencias con autorización institucional vigente |
| Core / WidgetSessionMiddleware + WidgetCsrf | Separar sesión cookie de delegación validada; preservar CSRF normal |
| ActorFactory | Consumir el contexto delegado creado exclusivamente en servidor |
| Realtime / WidgetStateStore + CacheWidgetStateStore | Estado efímero compartido y exclusión mutua |
| Realtime / WidgetCoordinator | Preferencia, leases, confirmación y recuperación de avisos |
| TeamChat / WidgetController y rutas widget | Adaptar la UI y exponer endpoints v1 acotados |
| TeamChat / ChatAccess::unreadCount | Contador central autorizado, incluyendo periodos históricos de acceso |
| SDK + widget-frame.js | Integración anfitriona, autenticación, UI compartida y ciclo de vida |
| TeamCalls / Claude Code | Futuro consumidor de avisos y controles de llamadas; sin implementación en esta fase |

No se cambian firmas de ActorContext, ConversationDirectory, CallsGateway, Presence, SyncFeed ni contratos compartidos 1.1.0. Las nuevas interfaces son adaptadores locales de esta rama. La coordinación no sustituye NotificationInbox ni SyncFeed. La prueba 4B conserva revocaciones, limpieza de presencia histórica y resolución del destinatario directo.

Tablas nuevas: collab_widget_apps, collab_widget_grants, collab_widget_sessions y collab_widget_preferences. Migración 2026_11_09_000000_create_collaboration_widget.php, ejecutada sólo en testing. API añadida: config, exchange, content, state, heartbeat, claim, ack, preferences, renew y revoke bajo /api/v1/collaboration/widget. El manual detalla métodos, cuerpos y controles.

## Evidencias y pruebas

| Validación | Resultado |
|---|---|
| Pest: TeamChat 4–7/4B/widget, Core, Realtime, Core base, Identity, Institutions, autenticación, seguridad y arquitectura | **234 pruebas, 1.287 aserciones**, aprobadas; 312,45 s |
| Pruebas específicas WidgetTest (incluidas arriba) | **14 pruebas**, aprobadas (incluidas en el total de aserciones anterior) |
| Contratos compartidos | **30 pruebas, 341 aserciones**, aprobadas |
| Carreras MySQL del widget | **3 carreras, 18 procesos**, aprobadas |
| Recuperación de outbox/colas MySQL | **35 comprobaciones**, aprobadas |
| Reverb real | **14 comprobaciones**, aprobadas |
| Chrome: widget en tres aplicaciones simuladas | **55 comprobaciones**, aprobadas |
| Chrome: integración CRM y seguridad HTTP | **8 comprobaciones**, aprobadas |
| Chrome: regresión de interacciones Fase 7 | **44 comprobaciones**, aprobadas |
| Chrome: regresión multimedia Fase 6 | **33 comprobaciones**, aprobadas |
| Reconciliación JS fuera de orden | **5 aserciones**, aprobadas |
| Desconexión JS concurrente, función de producción con dobles | **7 aserciones**, aprobadas |
| PHPStan nivel 6 | Core, Realtime, TeamChat y contratos sin errores |
| Laravel Pint | **146 archivos**, aprobado |
| Vite | **14 módulos**, compilación aprobada |
| Git diff | Comprobado sin errores de espacios |

Las carreras midieron 211,08 ms para seis intercambios simultáneos del mismo código (un ganador), 1.270,06 ms para seis reclamaciones desde la misma instancia (un ganador) y 1.345,90 ms para seis reclamaciones repartidas entre tres instancias (una preferida). Además se verificaron deduplicación tras confirmación, recuperación tras retirar al propietario y rechazo de confirmación después de revocar el dispositivo. Son mediciones locales sin el arranque de procesos, no un benchmark productivo.

En Chrome, cada una de tres aplicaciones recibió el contador y el historial, mientras sólo una emitió la alerta y el intento de sonido. Se verificaron el cambio de foco, cierre/reasignación, lectura explícita, reconexión sin duplicar mensajes, imágenes, descarga de bytes autorizados, voz procesada centralmente, interacciones y revocación. La inspección de cabeceras confirmó peticiones delegadas sin Cookie incluso dentro del CRM first-party. Tras el recorrido del widget se verificó outbox entregada y seis aceptaciones de transporte Core. Esa comprobación excluye los eventos internos de revocación que requieren al consumidor de TeamCalls; no se presenta ese consumidor como probado.

Logs: .testing/phase8-regression-final.log, phase8-contracts-final.log, phase8-concurrency.log/json, phase8-realtime.log, phase8-websocket.log, phase8-browser-final.log, phase8-security-browser.log, phase8-interactions-browser.log y phase8-attachments-browser.log.

Las suites de base de datos se ejecutan secuencialmente. Los fixtures usan cuentas ficticias @teamchat.invalid, MySQL 8.4.3 en 127.0.0.1:33317 con datadir propio .testing/mysql, almacenamiento privado temporal, HTTP loopback y Reverb en 33318. El servidor de navegador utiliza cuatro procesos PHP detrás de un proxy Node local porque el servidor de desarrollo PHP de Windows es serial. No es una medición de capacidad productiva.

Servicios reales: PHP/Laravel, SQL y locks, intercambio MFA, cookies first-party de login, peticiones delegadas sin cookies, WebSocket/Reverb, outbox, FFmpeg, almacenamiento privado y Chrome instalado. Simulaciones: instituciones/cuentas de prueba, tres páginas anfitrionas de distintos orígenes, micrófono simulado de Chrome, viewport móvil y desconexión controlada. Pest usa también fixtures/dobles de almacenamiento y transporte según cada prueba. No se presenta el micrófono como hardware físico ni el viewport como Android/iOS real.

Capturas revisadas: .testing/teamchat-phase8-desktop.png y .testing/teamchat-phase8-mobile.png. No muestran desbordamiento horizontal; panel, navegación, historial y compositor son utilizables. Los controles del anfitrión conservan sus estilos y comportamiento. Las tres páginas simuladas usan localhost:33320, :33321 y :33322; central 127.0.0.1:33319. Son orígenes locales distintos, no certificación de dominios productivos con TLS.

Scripts versionados: TeamChatWidgetServer.ps1, TeamChatWidgetHosts.cjs, TeamChatWidgetBrowser.cjs, TeamChatWidgetSecurityBrowser.cjs, WidgetConcurrency.php y TeamChatWidgetClient.cjs. Los logs/evidencias locales permanecen ignorados por Git en .testing.

## Garantías y límites efectivos

- La fuente de verdad revoca nuevas operaciones inmediatamente. El panel elimina datos al detectar el rechazo en una petición; no se promete borrado remoto instantáneo de bytes previamente descargados.
- Una reclamación vigente por evento/dispositivo; se confirma antes del aviso. Puede perderse el aviso si muere la pestaña entre confirmación y presentación; perder el cache puede permitir repetición. No se garantiza exactamente una vez. El mensaje/historial y sus eventos siguen siendo recuperables.
- TTL de instancia 90 s, heartbeat nominal 30 s, lease 12 s, credencial 300 s, máximo absoluto ocho horas subordinado a la sesión central. La suspensión de pestañas puede retrasar actividad/reconexión.
- Se coordinan las instancias del widget. Los avisos preexistentes de una pantalla completa abierta en otra pestaña mantienen su comportamiento anterior; esa extensión del coordinador no se presenta como implementada.
- Un dispositivo Core web está ligado a la sesión. Dos perfiles/navegadores o sesiones independientes pueden producir avisos independientes incluso en un mismo ordenador.
- No se depende de cookies de terceros. Se requiere login/MFA first-party central y permitir el popup cuando no exista acceso automático same-origin. No existe SSO operativo de Moodle/SIGAC por el mero hecho de tener sesión en ellos.
- El aislamiento frente al anfitrión depende de usar orígenes diferentes. El CRM servido en el mismo origen comparte la frontera de confianza; un origen central dedicado requiere configuración y validación de despliegue.
- Redis, navegadores distintos de Chrome, dominios productivos/TLS, dispositivos móviles físicos, instalaciones reales de SIGAC/Moodle y capacidad de Hostinger/VPS quedan pendientes de validación. El antivirus de adjuntos sigue con la limitación documentada en Fase 6: not_scanned no significa seguro.
- Sin LiveKit, llamadas, TeamFiles, visitantes, APNs/FCM ni producción.

## Instalación y coordinación posteriores

Desplegar requiere una autorización distinta: aplicar la migración en el entorno aprobado, configurar origen central e ID CRM, registrar aplicaciones/orígenes, publicar assets, revisar CSP/CORS/Permissions-Policy y TLS/WSS, supervisar Reverb y workers, elegir un cache compartido y medir capacidad. No basta con insertar el script si esos requisitos no están configurados.

Claude Code deberá aportar políticas/intents de TeamCalls autorizados para NotificationInbox y consumir las operaciones del coordinador antes de presentar avisos de llamada. Después podrá integrar aceptar/rechazar/interfaz multimedia; la política de cámara del iframe sigue deshabilitada en esta fase y requerirá revisión al integrar video. No se copia ni modifica código de su worktree.

Se conserva para una fase posterior el requisito de **múltiples enlaces públicos independientes por colaborador, cada uno con PIN individual, caducidad, regeneración y revocación**, con identidades de visitantes separadas de usuarios internos. No está implementado.

El commit local y el estado final de Git se consignan en la entrega del chat. No se inicia otra fase.
