# MCA Colaboración — CollaborationCore, Fase 2

Implementación local sobre `feature/collaboration-teamchat`, descendiente por avance rápido de `ef74cd9f1f5db65bad35c8a546ea9d391de7179e`. Los contratos, DTO, enums y errores de Fase 1 permanecen intactos. No se implementan conversaciones, mensajería, transporte Reverb, LiveKit ni entrega push.

## Servicios implementados

| Contrato / responsabilidad | Implementación |
|---|---|
| AuthenticatedSessionContextFactory | ContextFactory; sesión Laravel, usuario e institución vigentes, MFA verificado |
| ActorContextFactory | ActorFactory; dispositivo web asignado por servidor y vinculado a sesión |
| CollaborationAccess | AccessService; permiso canónico collaboration.use; denegación de capacidades desconocidas |
| UserDirectory | InstitutionUserDirectory; directorio por identificador y aislamiento institucional |
| DeviceRegistry | DatabaseDeviceRegistry; registro idempotente por sesión, múltiples sesiones, revocación y vigencia |
| PresenceService | InstitutionPresence; heartbeat, estado manual y señales de llamadas independientes |
| NotificationDispatcher | DatabaseNotificationDispatcher; intención persistida y deduplicada, sin envío |
| Acceso a aplicaciones | ApplicationAccess; concesión/revocación y administración institucional independientes |
| Ejecución interna | InstitutionExecution; institución explícita y restauración del contexto |
| Preferencias | NotificationPreferences; configuración propia del usuario |
| Revocación | Revocation y eventos de usuario/institución/logout |
| Retención | collaboration:prune --institution=ID o --all-institutions |

El proveedor registra solo implementaciones de Core. Este módulo pasa a ser necesario para el acceso institucional de User: no debe deshabilitarse de forma aislada como mecanismo de rollback. CallsGateway y ConversationDirectory siguen sin implementación. La infraestructura de sincronización/outbox de CollaborationRealtime sigue siendo exclusivamente contractual.

## Identidad y permisos

Una identidad existente en users. El rol nuevo `member` significa **sin rol CRM**, no Admisiones. La columna role tiene este valor predeterminado, y el formulario de altas comienza en él. `crmPersona()` devuelve `none` sin acceso comercial. El CRM requiere tanto acceso a la aplicación como un rol comercial; una concesión CRM no convierte un miembro neutro en asesor.

Aplicaciones conocidas: crm, hr, sigac, collaboration. HR/SIGAC se deniegan si no existe concesión; no se implementa su negocio. Colaboración está disponible por defecto para usuarios activos de instituciones activas con collaboration_enabled, salvo revocación explícita. No requiere rol comercial.

La migración preserva los accesos CRM de usuarios comerciales existentes mediante crm_legacy_access y preserva la administración de identidades de administradores anteriores mediante is_identity_admin. Las revocaciones explícitas prevalecen sobre el acceso heredado. Los nuevos administradores comerciales no reciben automáticamente administración institucional. La semilla de desarrollo aprovisiona explícitamente esos permisos solo al crear su administrador; no restaura concesiones revocadas al volver a ejecutarse.

canManageUsers gobierna identidades. canManageCrm gobierna las acciones administrativas comerciales antes ligadas a canManageUsers. Se protegen rutas y actualizaciones Livewire y se ocultan enlaces comerciales a usuarios neutros. Se mantienen las comprobaciones de recurso de las Policies existentes.

La lectura de permisos para dibujar el panel usa PanelAccessSnapshot, limitado a una petición y al ciclo scoped; cambios locales de usuarios, instituciones y concesiones lo invalidan. Evita consultas por cada tarjeta del pipeline. Los servicios sensibles de Core usan ApplicationAccess directamente, sin esa caché. Las concesiones se auditan. Los campos administrativos nuevos no son asignables masivamente. Un administrador institucional puede administrar otro de su misma institución; se impide quitarse a sí mismo esa capacidad por esta API.

## Sesión, dispositivo, MFA y presencia

No hay autenticación nueva. Se reutilizan cookies, sesiones, CSRF y TOTP/recuperación existentes. Al superar MFA se registra en sesión una prueba asociada al usuario y a la versión de sus credenciales. No basta tener TOTP configurado. Sesiones antiguas, recordarme sin MFA comprobado y cambios de credenciales requieren autenticación completa para Colaboración.

ActorContext se crea solo desde el servidor. Los campos user_id, institution_id, device_id o nivel de autenticación enviados por clientes nunca determinan el actor. Colaboración utiliza la institución de pertenencia del usuario, no un cambio arbitrario del contexto administrativo.

Cada sesión recibe un ULID de dispositivo; la base guarda SHA-256 del identificador de sesión y una huella de credenciales, nunca el identificador de sesión sin protección. La plataforma queda preparada para web/android/ios, pero se rechaza el enrolamiento móvil hasta disponer de autenticación móvil completa con MFA.

Un dispositivo registrado, una sesión válida, un usuario activo y presencia reciente son conceptos distintos. La API de dispositivos expone registro, vencimiento y session_valid, sin huellas ni secretos. Con sesiones database, se comprueba también la existencia y caducidad de la sesión Laravel; revocar un dispositivo elimina su sesión asociada. Los otros dispositivos siguen vigentes. Con otro driver, el registro revocado bloquea Colaboración; la invalidación global del driver necesita su propio mecanismo.

Desactivar o trasladar usuarios, cambiar credenciales o deshabilitar instituciones revoca registros mediante eventos de modelo. Toda operación vuelve a comprobar autorización. Las modificaciones administrativas deben pasar por modelos/servicios, no SQL masivo que omita eventos. Las comprobaciones de credenciales y acceso siguen rechazando nuevas operaciones aunque falten eventos.

El contexto CurrentInstitution es scoped. El middleware de usuario usa runFor para restaurar un contexto envolvente explícito; una solicitud normal empieza y termina sin contexto resuelto. El middleware de la API de Core lo limpia en finally. InstitutionExecution usa runFor para restaurar el contexto incluso ante errores. Se reutiliza la base de jobs institucionales existente.

## Presencia

PresenceStore permite sustituir DatabasePresenceStore por Redis posteriormente. Heartbeat: TTL fijo de 90 segundos, evaluado al leer sin necesitar scheduler. El registro de sesión por sí solo no produce presencia.

El estado manual es independiente de la conectividad: available, busy, away, offline. Se agregan dispositivos con heartbeat no vencido y registro no revocado. El modo manual offline fuerza online=false. Se conservan last_seen_at, last_activity_at y last_online_at; estos reflejan actividad/contacto de transporte observado, no autorización.

Cada señal in_call tiene institución, usuario, call_id y TTL entre 1 y 3600 segundos. Eliminar una llamada no elimina otra. El snapshot contractual devuelve una llamada vigente seleccionada de manera estable; la API expone solo in_call, sin revelar identificadores de llamada.

**Integración pendiente de Claude:** Core incorpora un puerto interno CallPresenceAuthorizer. La implementación predeterminada deniega todo. El adaptador de TeamCalls deberá verificar institución y asociación del participante con la llamada, incluido el cierre, antes de permitir setInCall/clearInCall. No es un cambio de PresenceService ni de CallsGateway. Debe invocarse después del commit de TeamCalls y renovar el TTL mientras proceda. Las pruebas de esta parte usan un doble explícito de ese adaptador; no acreditan integración multimedia real.

## Notificaciones

NotificationDispatcher recibe contexto institucional confiable, intención mínima y audiencia. Revalida todos los destinatarios; rechaza audiencias entre instituciones sin escritura parcial. Consulta preferencias y dispositivos vigentes. Los registros contienen identificadores, sin cuerpos de mensajes ni credenciales multimedia.

Estados: pending, suppressed, expired. **Ninguno significa entregado.** No hay workers, proveedores FCM/APNs ni envíos simulados.

La clave de deduplicación es única por institución. Repetición idéntica devuelve el mismo identificador; contenido diferente produce idempotency_conflict. La huella incluye la intención con fecha normalizada a UTC, audiencia ordenada y exclusión de dispositivo. Los identificadores opacos y claves de deduplicación mantienen distinción de mayúsculas en la base. Se vuelve a comprobar autorización antes de responder a una repetición. El identificador global de intención también es único.

Una futura entrega deberá volver a comprobar membresía, preferencias, vigencia de dispositivos y caducidad: la audiencia persistida es una instantánea, nunca autorización permanente. Debe existir una sola vía de entrega mediante Realtime y su futuro adaptador push.

## Persistencia y reversión

Migración: 2026_11_02_000000_create_collaboration_core.

- collab_application_access: única por institución/usuario/aplicación.
- collab_devices: sesión hash única, plataforma, huella, actividad, caducidad y revocación.
- collab_presence: estado manual e históricos únicos por institución/usuario.
- collab_presence_devices: un heartbeat por dispositivo.
- collab_presence_calls: una señal por institución/usuario/llamada.
- collab_notification_preferences: una preferencia por institución/usuario.
- collab_notification_intents: deduplicación, audiencia, dispositivos, estado y caducidad.

Claves foráneas e índices únicos respaldan las invariantes. Las escrituras concurrentes se serializan con bloqueo de usuario; las intenciones, con bloqueo institucional y destinatarios ordenados. Los repositorios usan consultas explícitamente acotadas por institución/usuario; no se crean modelos de dominio sin el trait institucional.

Prune elimina heartbeats y llamadas caducados, marca intenciones vencidas y elimina las vencidas hace más de 30 días. No se configura scheduler en esta fase. Se conservan tombstones de dispositivos revocados para impedir reinscripción de la misma sesión. El archivado de esos hashes requiere una política posterior que mantenga esa garantía.

down elimina tablas y columnas nuevas, pero conserva deliberadamente el default neutro de role. **No es un downgrade de aplicación automático:** el código anterior no conoce member y eliminar concesiones elimina también revocaciones. Una reversión operativa requiere revisar cuentas neutras y accesos, y mantener una versión de código compatible. Nunca se convierte un miembro en administrador al revertir.

## API web v1

Prefijo /api/v1/collaboration; sesión web existente, CSRF en mutaciones y límite de 120 solicitudes/minuto por autenticado. La primera solicitud válida registra el dispositivo web si no existe. No se aceptan tokens móviles ni IDs de dispositivo del navegador para autenticar.

| Método | Ruta relativa | Finalidad |
|---|---|---|
| GET | /me | Identidad mínima y dispositivo actual |
| GET | /users/{userId} | Miembro autorizado de la misma institución |
| GET | /devices | Dispositivos propios y vigencia |
| DELETE | /devices/{deviceId} | Revocación propia y sesión vinculada |
| POST | /presence/heartbeat | Actividad real del dispositivo |
| PUT | /presence/status | Estado manual |
| GET | /presence?user_ids[]=ID | Snapshot, máximo 100 miembros |
| GET / PUT | /notification-preferences | Preferencias propias |
| PUT | /applications/{application}/users/{userId} | Concesión institucional, cuerpo allowed |
| PUT | /identity-administrators/{userId} | Administración de identidad, cuerpo enabled |

Éxito: data y meta con request_id generado por servidor y version 1.0. Fechas UTC. Errores: error.code/message/retryable/request_id. No se devuelven detalles internos ni errores SQL. Se conservan los códigos aprobados; errores HTTP inesperados usan internal_error. CSRF fallido se representa como forbidden. Las respuestas de datos no se cachean.

Registro y escrituras por clave natural son repetibles; revocación y estados no duplican filas. Un reintento desde el mismo dispositivo ya revocado se deniega: la autorización tiene prioridad sobre repetir una respuesta anterior. No se añade un nuevo protocolo de idempotencia a contratos aprobados.

## Pruebas locales

Servidor MySQL exclusivo: 127.0.0.1:33317, base crm_mca_collaboration_core_test. Datos del servidor dentro de .testing/mysql, ignorados por Git. No reutilizar un servidor de negocio. La configuración phpunit.collaboration.xml fuerza esa base; el bootstrap rechaza otras direcciones y configuración Laravel cacheada. .env local contiene solo valores de testing; no está versionado.

Con PHP 8.3 y extensiones mbstring, openssl, pdo_mysql, fileinfo, intl, gd y pdo_sqlite (una regresión existente usa SQLite en memoria):
- php vendor/bin/pest -c phpunit.collaboration.xml
- php vendor/bin/pest -c phpunit.collaboration.xml Modules/Identity/tests Modules/Institutions/tests tests/Feature/Auth tests/Feature/Security tests/Feature/ProfileTest.php Modules/Core/tests
- php vendor/bin/pest -c phpunit.collaboration.xml Modules/Crm/tests
- php vendor/bin/phpunit -c phpunit.contracts.xml
- php tests/CollaborationConcurrency.php
- php tests/CollaborationMigration.php
- php vendor/bin/phpstan analyse -c phpstan.collaboration.neon --memory-limit=1G
- Pint solo sobre archivos nuevos/modificados de la fase.

No ejecutar suites simultáneamente sobre esa base. CollaborationMigration exige que no existan usuarios de fixtures y realiza down/up para comprobar acceso heredado y defaults neutros. CollaborationConcurrency ejecuta cuatro procesos y comprueba igualdad de respuestas y ausencia de duplicados; después elimina sus fixtures.

Resultados finales (15 de octubre de 2026):

| Verificación | Resultado |
|---|---|
| Pest: Core nuevo + Identity + Institutions + Core existente + CRM + Auth + Security + Profile | 396 correctas, 2.136 aserciones; 504,03 s |
| PHPUnit, contratos de Fase 1 sin cambios | 28 correctas, 252 aserciones |
| Concurrencia real | 4 procesos; 1 dispositivo, 1 agregado, 1 heartbeat y 1 intención; respuestas coincidentes |
| Migración | down/up correctos; acceso heredado preservado y nuevos usuarios neutros |
| PHPStan nivel 6, alcance de Fase 2 | Sin errores |
| Laravel Pint, archivos nuevos/modificados | Sin cambios pendientes |
| Git diff --check; contratos y lockfile | Correctos; contratos y lockfile intactos |

Correcciones verificadas: separación de privilegios de identidad/CRM, cierre del fallback de LeadPolicy, preservación del contexto envolvente sin filtración entre peticiones, prueba MFA ligada a credenciales, permisos del pipeline sin consultas por tarjeta y configuración aislada con idioma/extensiones requeridos por las regresiones existentes. El servidor temporal de pruebas se cerró al finalizar; los datos locales de testing y dependencias quedan fuera de Git. Composer mantuvo el lockfile, pero notificó 6 avisos de seguridad en 5 paquetes existentes; no se actualizaron dependencias fuera del alcance. Un análisis global detectó además tres diagnósticos en código no modificado (CalendarGrid, UserTimezone y Notifications/EmailSenders/Manage); el análisis específico de esta fase se ejecuta por separado.

## Integración y límites siguientes

Claude deberá implementar el adaptador de presencia validada, adaptar CallsGateway y reutilizar los servicios compartidos, sin copiar DeviceRegistry/Presence/NotificationDispatcher. La revocación de sockets y participantes LiveKit continúa pendiente y bloquea activar llamadas reales.

Fase 3 necesita transporte Reverb, almacenamiento/colas/outbox y recuperación, reautorización al reconectar y sincronización multidispositivo; no están implementados aquí. Redis es opcional para reemplazar PresenceStore, no un requisito de ejecución actual. LiveKit y su infraestructura permanecen responsabilidad de Claude.

No hubo push, despliegue, migración de producción ni modificaciones de TeamCalls. Las pruebas de TeamCalls no están disponibles en esta rama; requieren integración posterior autorizada. No se considera validada esa integración por las pruebas con dobles.