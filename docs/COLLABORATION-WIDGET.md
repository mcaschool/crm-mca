# Widget institucional MCA — manual de integración, Fase 8

## Arquitectura y alcance

Un SDK JavaScript sin dependencias crea un Shadow DOM y un iframe del servicio central. El iframe reutiliza la vista y los módulos JS de TeamChat; no existe otro motor ni otro historial. Core verifica identidad, MFA, permisos, dispositivo y sesión; Realtime conserva SyncFeed, canales privados, outbox y entrega. La API sigue bajo /api/v1/collaboration. No se cambian interfaces compartidas 1.1.0 ni TeamCalls.

La página anfitriona recibe únicamente estado de conexión, contador, configuración pública y avisos genéricos. No recibe textos, archivos ni credenciales. La separación de origen del navegador protege el iframe frente a un anfitrión de otro origen. **Un anfitrión del mismo origen comparte la frontera de confianza**: ningún iframe same-origin protege contra JavaScript comprometido del propio CRM. Para aislamiento también frente al CRM, publicar el servicio central en un origen institucional dedicado, con revisión posterior de su configuración de autenticación y TLS.

## Registro y activación

El registro es una operación administrativa de consola, sin endpoint público de altas. Ejecutar sólo en el entorno autorizado, después de desplegar la migración habitual de Core. Ejemplo ficticio:

~~~sh
php artisan collaboration:widget register sigac-staging --institution=123 --name=SIGAC --origin=https://sigac.example.invalid --environment=staging --capability=chat --capability=media --capability=notifications --position=right --theme=light
~~~

Cada ID queda ligado a una institución. Varias opciones --origin son posibles, siempre orígenes exactos, sin rutas, comodines, credenciales ni barra final. HTTPS es obligatorio excepto localhost/127.0.0.1 cuando COLLABORATION_WIDGET_LOCAL_HTTP=true en pruebas locales. No habilitar esa opción en producción. La única política disponible es central_mfa; no hay una política que acepte la identidad aportada por Moodle o SIGAC.

Configuración central:

~~~dotenv
COLLABORATION_WIDGET_ORIGIN=https://colaboracion.example.invalid
COLLABORATION_WIDGET_CACHE=database
COLLABORATION_WIDGET_CRM_APP=crm-institucional
COLLABORATION_WIDGET_LOCAL_HTTP=false
~~~

El CRM incorpora automáticamente el script en su layout institucional para usuarios con collaboration.use cuando se configura su ID público. Registrar también su origen exacto. /colaboracion conserva la interfaz completa sin una segunda burbuja. Sin la configuración del ID no se activa automáticamente en entornos existentes. No se conceden permisos comerciales ni se reutilizan contadores de Social Inbox.

La apariencia registrada admite position=left|right y theme=light|dark; el tema afecta al acento de la burbuja, no constituye un tema oscuro completo del chat. Las capacidades son chat (obligatoria), media y notifications. Quitar media bloquea los endpoints de archivos; quitar notifications excluye la instancia de la elección de avisos. Los permisos de conversación se comprueban además en cada operación.

## Inserción

~~~html
<script async src="https://colaboracion.example.invalid/collaboration/widget.js"
        data-app="sigac-staging" data-environment="staging"
        data-position="right"></script>
~~~

El idioma procede de html lang (es/en). Para montaje manual, cargar el script sin data-app y, tras su evento load, llamar:

~~~js
const widget = window.MCACollaboration.mount({
  app: 'sigac-staging', environment: 'staging', locale: 'es',
  position: 'right', open: false
});
widget.open();
widget.close();
widget.destroy();
~~~

También se exponen MCACollaboration.open(), close() y destroy(). mount es idempotente: una instancia por documento. close oculta el panel y preserva borrador/sincronización; destroy elimina iframe, temporizadores y listeners, solicita revocación y libera recursos. Si el navegador interrumpe esa solicitud al eliminar el iframe, la instancia expira por TTL. Una SPA debe llamar destroy antes de desmontar su layout y mount cuando corresponda; no insertar repetidamente el script en cada actualización Livewire.

No insertar IDs de usuario, secretos, contraseñas ni tokens en este fragmento.

## Autenticación y revocación

1. El servidor valida ID, origen permitido y política del iframe. CSP restringe sus ancestros al origen registrado.
2. El iframe crea un verificador aleatorio y desafío S256 en memoria.
3. Conectar abre una ventana central. Si existe sesión central MFA válida, no vuelve a solicitar las credenciales; sí presenta consentimiento para la aplicación y su origen. Sin ella utiliza el login/MFA institucional existente.
4. Un código de un solo uso, válido 60 segundos, retorna exclusivamente al iframe central por postMessage con origen, ventana y estado correlacionados. Se intercambia por POST con el verificador, nunca en la URL.
5. La credencial de instancia dura 300 segundos, se guarda sólo en memoria y se renueva antes de caducar. En SQL se almacenan hashes. Queda vinculada a aplicación, origen autorizado, dispositivo y sesión central; máximo absoluto de ocho horas.
6. Cada solicitud revalida usuario activo, institución, elegibilidad, permiso, dispositivo, MFA y vigencia de la sesión de origen. La expiración de esa sesión puede ocurrir antes de ocho horas: el widget no prolonga una sesión central caducada. Revocar dispositivo, sesión o acceso impide nuevas operaciones inmediatamente.
7. Desconectar afecta a la instancia. Cerrar la sesión institucional o revocar su dispositivo invalida todas las instancias derivadas. La pantalla se limpia cuando la siguiente petición detecta la revocación.

La API delegada usa Authorization: MCAWidget y cabeceras X-Widget-Instance/App/Origin, credentials: omit. No depende de cookies de terceros. Las cookies first-party se usan sólo en el login/consentimiento central y, en el CRM del mismo origen, para el intercambio automático protegido por CSRF. El middleware delegado no abre permisos administrativos y mantiene la protección Laravel normal para peticiones sin credencial delegada.

No es un proveedor SSO nuevo. Una sesión Moodle, SIGAC o estudiantil no demuestra elegibilidad MCA. Los conectores federados reales, si se necesitan, deben mapear identidades verificadas a la identidad institucional central y conservar MFA; están pendientes. Con cookies bloqueadas o políticas de popup restrictivas, el usuario debe permitir explícitamente la ventana central. Recargar la página pierde la credencial efímera y requiere conectar de nuevo; no hay tokens persistentes en localStorage.

## API añadida

| Método / ruta relativa | Función |
|---|---|
| GET widget/config?app&origin | Configuración pública sin identidad ni secretos |
| POST widget/exchange | Código de un uso + verificador; sólo origen central |
| GET widget/content | Interfaz compartida, autenticada |
| POST widget/heartbeat | Actividad e instancia preferida |
| GET widget/state | Contador autorizado del historial vigente |
| POST widget/claim | Reclamar un intento de NotificationInbox |
| POST widget/ack | Confirmar event + lease |
| GET/PUT widget/preferences | Preferencias propias, recurso autorizado opcional |
| POST widget/renew | Rotar credencial de la instancia |
| POST widget/revoke | Revocar la instancia actual |

Rutas web adicionales: GET /collaboration/widget/frame y GET/POST /collaboration/widget/authorize. Los errores conservan la convención existente: 401 sesión inválida, 403 acceso denegado, 404 recurso no accesible, 422 entrada inválida, 429 límite. No usar el ID público como autenticación. Las solicitudes mutadoras de TeamChat siguen utilizando sus claves idempotentes existentes.

Persistencia nueva: collab_widget_apps, grants, sessions y preferences. Estado efímero: WidgetStateStore, con CacheWidgetStateStore y locks compartidos. Un cache array de proceso no sirve para coordinación productiva.

## Coordinación y garantías de avisos

La clave es institución + usuario + dispositivo Core. Cada pestaña/aplicación tiene una instancia y una credencial propia. Los dispositivos Core actuales están ligados a sesiones; navegadores, perfiles o sesiones institucionales distintas pueden ser dispositivos distintos aunque estén en el mismo ordenador. No se utiliza fingerprinting para unirlos artificialmente.

Heartbeat nominal de 30 s; expiración de instancia de 90 s. La elección prioriza visibilidad, foco y actividad reciente, pero estos son indicios del cliente, no autorizaciones. Sólo participan aplicaciones y sesiones válidas con notifications. Cada evento fuente tiene una reclamación de 12 s y una confirmación protegidas por lock. Un propietario caído permite recuperar tras vencer la reclamación y reelegir una instancia; la ausencia completa se detecta por TTL.

Todas las instancias conservan sus feeds y contadores. Sólo la preferida reclama alertas; las demás no bloquean mensajes ni recibos. Los eventos procesados se descartan. El contador excluye mensajes propios/eliminados/leídos y respeta los periodos vigentes de acceso de grupos y canales. Un widget cerrado o sin foco no marca mensajes como leídos por su mera presencia.

PUT widget/preferences admite campos parciales: omitir un campo conserva su valor, incluido muted_until. Las escrituras usan upsert atómico para no reemplazar ajustes ajenos a la modificación.

Preferencias visuales, sonido, silencio temporal y conversación silenciada se validan en el servidor antes del aviso. La burbuja conserva el contador incluso silenciada. Sonido desactivado inicialmente; requiere gesto explícito en cada página para habilitar AudioContext. No se pide permiso de notificaciones del sistema ni se implementa FCM/APNs. Los avisos son genéricos, sin cuerpo del mensaje.

**Garantía efectiva:** una reclamación vigente por evento/dispositivo y deduplicación mientras el estado compartido esté disponible. No se promete exactamente una vez. Se confirma antes de mostrar: si la respuesta se pierde o la pestaña muere entre confirmación y visualización, puede no mostrarse la alerta. Si el cache se pierde, puede repetirse. El historial permanece durable y recuperable. Las alertas se limitan a los intents vigentes que devuelve NotificationInbox (hasta 100); fuera de esa ventana los mensajes siguen disponibles mediante historial, contadores y SyncFeed. La coordinación se aplica a instancias del widget; los avisos anteriores de una pantalla completa de TeamChat abierta en otra pestaña mantienen su comportamiento anterior.

El coordinador acepta intents autorizados por NotificationInbox, no sólo mensajes. Para TeamCalls, Claude debe aportar su política de acceso/eventos y registrar intents compatibles, integrar un consumidor que reclame/confirme por este mecanismo y añadir sus controles sólo después de verificar los adaptadores. No hay llamadas ni controles multimedia ficticios.

## CSP, CORS, WebSocket y alojamiento

El anfitrión debe permitir script-src hacia el origen central, frame-src hacia él y delegar microphone al iframe si desea notas de voz. Una Permissions-Policy restrictiva de la página superior puede bloquear el micrófono aunque el iframe lo solicite. El SDK declara esa delegación. El consentimiento de micrófono sigue siendo del navegador.

El iframe impone frame-ancestors exacto, CSP sin fuentes de scripts de terceros, no-referrer y nosniff. Login/consentimiento no se pueden enmarcar. Las llamadas API ocurren desde el origen central: no hace falta abrir CORS de la API a Moodle/SIGAC. No añadir Access-Control-Allow-Origin: * ni credenciales CORS globales. El proxy no debe añadir X-Frame-Options: SAMEORIGIN a la ruta del iframe: esa ruta usa su CSP exacta; conservar las protecciones del resto de páginas.

Reverb conserva autenticación de canales privados por dispositivo y avisos mínimos; el contenido se obtiene mediante SyncFeed/API autorizada. Configurar TEAMCHAT_REVERB_* y la configuración actual de Reverb, proxy WSS con TLS, allowed_origins para el origen central y sin exponer secretos del servidor. No introducir claves privadas en el SDK.

El servicio necesita PHP/Laravel y SQL actuales, almacenamiento privado y procesadores multimedia de Fase 6, workers persistentes de outbox y notificaciones, Reverb supervisado y HTTPS/WSS. El cache database permite pruebas y arranque; en VPS puede cambiarse COLLABORATION_WIDGET_CACHE a un store Redis compartido con locks y TTL. Todos los nodos deben usar el mismo store. Redis privado, autenticado, sin exposición pública. El alojamiento Hostinger puede continuar sirviendo aplicaciones anfitrionas; éstas cargan el servicio central del VPS por HTTPS. Se requiere autorización de despliegue y validación de cookies first-party, proxies, CSP, TLS y capacidad antes de activar entornos reales.

Una conexión WebSocket por instancia; sin leader election de socket entre dominios. Heartbeats cada 30 s y polling de recuperación de TeamChat cada 45 s conectado / 15 s desconectado en widgets. Contador limitado localmente a una consulta cada 3 s; reclamaciones de recuperación cada 15 s sólo en la instancia que cree ser preferida, siempre revalidadas por servidor. Máximo 30 instancias activas por dispositivo. No es un benchmark de alojamiento productivo; medir pestañas, QPS, SQL y latencias reales antes de ampliar despliegue. Las pestañas suspendidas por el navegador pueden tardar en recuperar foco/avisos.

## Moodle y SIGAC

SIGAC: incorporar el fragmento en su layout institucional, una vez por documento; no pasar su ID de usuario. Moodle: usar un plugin local, tema o mecanismo oficial de HTML/JS permitido por su instalación, limitado a las páginas institucionales previstas. Autorizar su origen exacto y revisar CSP/Permissions-Policy. No editar el núcleo ni conceder collaboration.use por el mero rol de estudiante. Probar inicio/cierre de sesión y permisos con cuentas institucionales y no elegibles antes de activar.

Las páginas tests/TeamChatWidgetHosts.cjs representan CRM/SIGAC/Moodle en tres puertos locales. Son simulaciones, no integraciones de instalaciones reales. Chrome local y loopback no acreditan comportamiento definitivo bajo dominios productivos/TLS, Safari, Firefox ni móviles físicos.

## Deshabilitación y mantenimiento

~~~sh
php artisan collaboration:widget disable sigac-staging
php artisan collaboration:widget prune
~~~

disable invalida el registro y sus sesiones; no borra mensajes. Los clientes lo detectan en la próxima petición. Retirar además el script de la aplicación para eliminar la burbuja. prune elimina credenciales expiradas antiguas; programarlo mediante el mecanismo operativo aprobado. Nunca registrar cuerpos de solicitudes de authorize/exchange/renew, cabeceras Authorization ni códigos/verificadores en logs, APM o proxy. No cachear respuestas privadas. Servir widget.js con revalidación de cache; los assets Vite con hash pueden utilizar cache inmutable.

## Reproducción local

Preparar el MySQL aislado de pruebas del repositorio, puerto 33317, datadir .testing/mysql, base crm_mca_collaboration_core_test, sin datos de aplicación. Los scripts verifican el entorno testing; no usar la base habitual. Compilar con npm run build. En PowerShell, tests/TeamChatWidgetServer.ps1 start inicia fixtures, cuatro workers PHP seriales detrás de un proxy Node loopback, Reverb y drenaje de outbox. Los orígenes anfitriones son localhost:33320/33321/33322 y el central 127.0.0.1:33319; los workers internos usan 33323–33326.

Ejecutar tests/TeamChatWidgetBrowser.cjs con Node y el módulo Playwright/Chrome local disponible (PLAYWRIGHT_MODULE permite indicar su ruta). La suite de seguridad es tests/TeamChatWidgetSecurityBrowser.cjs. Terminar con tests/TeamChatWidgetServer.ps1 stop: sólo detiene los procesos registrados por ese arnés y elimina sus fixtures; no apaga MySQL.

No ejecutar Pest RefreshDatabase ni las carreras mientras haya fixtures de navegador. Después del stop, ejecutar Pest con phpunit.collaboration.xml y los directorios pertinentes; tests/WidgetConcurrency.php comprueba las carreras con procesos independientes y cache database compartido. Los scripts RealtimeIntegration.php y RealtimeWebSocket.php verifican recuperación y Reverb real. Cada ejecución debe terminar antes de la siguiente suite que modifique esa base.

## Alcance futuro conservado

Visitantes externos: múltiples enlaces públicos independientes por colaborador, cada uno con PIN propio, caducidad, regeneración y revocación. Identidades de visitantes separadas de usuarios internos, sin permisos de plataforma. No implementado aquí. También quedan fuera LiveKit, aplicaciones nativas, APNs/FCM, TeamFiles y despliegues.

Referencias de diseño: [PKCE S256, RFC 7636](https://www.rfc-editor.org/rfc/rfc7636.html), [validación de postMessage](https://developer.mozilla.org/en-US/docs/Web/API/Window/postMessage), [restricciones de cookies de terceros](https://developer.mozilla.org/en-US/docs/Web/Privacy/Guides/Third-party_cookies).
