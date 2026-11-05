# Auditoría de cadencia de WhatsApp — 5 de noviembre de 2026

Base local: `4f8ddd07b82380cb9e31bd24f47a38c5c6b0eb5a`.
Trabajo exclusivamente local. Este documento no autoriza publicación, despliegue ni activación.

## Diagnóstico anterior

Existían consentimiento por finalidad, límites institucionales y de asesor, cancelación
de acciones/borradores por respuesta, idempotencia, aprobación versionada, gateway único,
interruptores independientes y conciliación de resultados ambiguos. No existían tres
momentos comerciales explícitos dentro de la ventana.

La detección periódica esperaba 24 horas por defecto. La política de WhatsApp consultaba
el snapshot de la oportunidad al programar y contrastaba con la hora actual, en vez de
comprobar también la fecha programada. No había margen de cierre configurable. Una plantilla
seleccionada dentro de ventana podía omitir su validación en esa política. El gateway
revisaba la identidad del contenido, pero no repetía la elegibilidad después de confirmar
la intención de despacho. La ingestión suplía timestamps ausentes por hora de recepción
sin distinguir esa ausencia para FollowUp.

## Regla externa y decisiones comerciales

Se contrastó la regla con la [política oficial de WhatsApp Business](https://business.whatsapp.com/policy/preview?lang=es_LA):
el texto libre requiere estar dentro de las 24 horas posteriores al último mensaje del
usuario; fuera de ese intervalo se requieren plantillas aprobadas y las demás condiciones
aplicables. Los tres seguimientos, sus intervalos y el margen son políticas de MCA, no
límites impuestos por Meta.

La estrategia nace **desactivada**. Valores propuestos: momentos 5, 14 y 21 horas desde
el último mensaje del prospecto; separación mínima de 4 horas; margen de cierre de
60 minutos; máximo de 3 seguimientos por ventana. Los campos permiten restringir o
ajustar estos valores, con un mínimo absoluto validado de 2 horas y un margen de al
menos 30 minutos. Los momentos deben mantener separación y quedar antes del margen.
La respuesta inicial sigue perteneciendo al asesor reactivo existente.

## Arquitectura e integración

- `WhatsAppWindow` deriva el vencimiento UTC de mensajes originales de Social Inbox.
  No duplica conversaciones ni necesita un campo de expiración que pueda quedar obsoleto.
  Los mensajes salientes, incluidos ecos de la app, no prolongan la ventana. Se selecciona
  el mayor timestamp entrante válido, no el mayor ID ni la recepción más reciente.
- `SocialIngestService` conserva una marca de procedencia temporal en `ai_meta`, verdadera
  solo cuando el proveedor aporta fecha no futura. Se conserva el
  comportamiento de recepción/preview existente; FollowUp bloquea el texto libre ante
  evidencia temporal desconocida. Un webhook antiguo no retrocede `last_inbound_at`.
- `WhatsAppCadence` concentra reglas de momentos, separación, reservas y resumen.
  Es una política del módulo existente, no otro scheduler, chatbot o gateway.
- `FollowUpPolicyEngine` aplica la estrategia solo a WhatsApp, manteniendo consentimiento,
  institución, asesor, canal, horario, Lead, exclusiones y control humano. Los intervalos
  de cadencia sustituyen los intervalos generales solo para texto libre de esta estrategia.
- `OpportunityDetector` puede detectar silencio antes de 24 horas únicamente con la
  estrategia habilitada. Conserva próximas evaluaciones para los momentos posteriores.
  Detectar o reevaluar no genera automáticamente un mensaje: propuesta, borrador,
  revisión y despacho conservan los contratos existentes.
- El contexto del borrador incorpora momento y vencimiento, y recuerda que cada contacto
  necesita valor nuevo verificable. Se reutilizan las recomendaciones `no_contact`,
  `human_review`, fuentes, comprobación de repetición, cifrado y aprobación existentes.

## Contadores, reaperturas y concurrencia

Se separan envíos confirmados (`sent`, `delivered`, `read`), reservas pendientes y resultados
desconocidos. Las respuestas reactivas, los mensajes humanos del CRM, los mensajes de la
app y los entrantes se presentan por separado. No se usa `attempt_number` como contador
de mensajes enviados: sigue siendo el identificador histórico de intentos/propuestas.

Cada reserva de cadencia tiene una clave única persistente por institución, conversación,
mensaje entrante de referencia y momento 1–3. La restricción MySQL evita dobles reservas.
Un momento cancelado queda en el historial y no se vuelve a reservar; no se contabiliza
como envío confirmado. Los momentos perdidos se omiten, nunca se comprimen en un lote.

El planificador serializa sobre contacto o conversación además de la oportunidad.
Los límites acumulados consultan oportunidades del mismo contacto conocido o conversación,
sin reiniciar por nuevo ciclo, cambio de asesor o nueva respuesta. Los despachos con
resultado ambiguo bloquean nueva ejecución y no se reintentan automáticamente. Las
reservas pendientes y las intenciones de despacho se cuentan conservadoramente para
impedir saturación, aunque todavía no sean envíos confirmados.

La nueva respuesta cancela pendientes e invalida contexto mediante los servicios existentes.
La identidad de la ventana de una acción no puede cambiar para reutilizar un job antiguo.
Los horarios siguen en zona institucional y la ventana técnica se calcula en UTC.

## Cierre seguro y plantillas

La política valida ventana y margen contra la fecha programada y vuelve a comprobarlos
en la ejecución. El comando de despacho reevalúa antes de encolar. El gateway verifica
de nuevo inmediatamente antes de su transición de envío. Si apareció una respuesta,
intervención, bloqueo o vencimiento, marca el intento como bloqueado sin llamar a Meta.
Las llamadas externas siguen fuera de transacciones abiertas por el ejecutor.

Una plantilla requiere validación incluso dentro de ventana. Fuera de ventana se trata
como una propuesta independiente, sujeta a categoría, finalidad, consentimiento,
aprobación y controles generales. No se convierte un borrador en plantilla automáticamente.
Elegir una plantilla no elude el máximo compartido dentro de una ventana activa,
el límite acumulado, la exclusión de acciones simultáneas ni la separación del prospecto.
El normalizador descarta como evidencia temporal las fechas de WhatsApp malformadas,
fuera de rango o futuras, conservando el mensaje para su procesamiento seguro.

## Centro de Comando

La configuración institucional y de asesor reutiliza el formulario y sus servicios.
Incluye activación de reglas, tres momentos, separación, margen y máximo por ventana;
el máximo acumulado, horarios, modos y canales continúan en los controles existentes.
Las restricciones institucionales prevalecen sobre las del asesor. Guardar invalida
acciones incompatibles y nunca activa los interruptores de envío.

El detalle muestra la ventana, último mensaje, minutos restantes, evaluación fechada,
envíos acumulados, reservas, resultados desconocidos y desglose de mensajes. El próximo
momento es orientativo y se etiqueta expresamente: no equivale a autorización ni envío
programado. No se presenta un contador como si se actualizara en tiempo real.

## Límites y aceptación operativa

- Cloud API y Coexistence comparten política y gateway; no se toca Embedded Signup,
  conexiones ni rutas de webhook. Instagram/Messenger no heredan la cadencia.
- Los timestamps históricos que ya se almacenaron con un fallback antes de esta revisión
  no permiten reconstruir retrospectivamente su procedencia sin evidencia original.
  La marca nueva resuelve el problema para nuevas ingestiones; no se inventa un backfill.
  Sin esa evidencia, FollowUp bloquea texto libre hasta recibir un mensaje nuevo verificable.
- Un contacto no identificado en varias conversaciones no puede deduplicarse como una
  misma persona con certeza. Se agrupa por contacto vinculado cuando existe y por
  conversación en caso contrario; no se fusionan identidades por inferencia.
- El máximo acumulado es conservador y no se restablece automáticamente. Abrir un ciclo
  comercial nuevo no borra despachos anteriores.
- La revalidación precede la frontera de despacho. No puede retractar una petición ya
  aceptada por el proveedor ni garantizar tiempos reales de red; el margen reduce ese riesgo.
- La aceptación local no acredita entrega real, capacidad de un número, calidad comercial
  real ni autorización productiva. No se realizaron llamadas reales a proveedores.

## Validación local y revisión visual

Todas las pruebas y migraciones utilizaron exclusivamente MySQL `crm_mca_test`.
Los proveedores externos se simularon; no se ejecutaron workers productivos.

| Comprobación | Resultado |
|---|---|
| Regresión completa de FollowUp y Social | 770 pruebas, 4099 aserciones, 0 fallos, 0 omitidas; 606,03 s |
| Aceptación final de cadencia, después de los ajustes visuales | 41 pruebas, 120 aserciones, 0 fallos, 0 omitidas; 63,30 s |
| Pint final, archivos PHP modificados | 28 archivos correctos |
| PHPStan: FollowUp, gateway Social, ingestión y normalizador | Sin errores |
| Compilación frontend final | Vite correcto; 1,88 s |
| Ocho migraciones acumuladas | Instalación limpia, reversión completa y reinstalación incremental correctas; contacto de CRM conservado |
| Seguridad tras la sesión visual | Ejecución global deshabilitada, 0 interruptores habilitados, 0 despachos, 0 jobs |

Las 41 pruebas específicas se solapan con la regresión: no deben sumarse como pruebas
únicas. Cubren los límites temporales, cambios de hora, evidencia inválida, nueva
respuesta, mensajes humanos/app, permisos, institución, plantillas, reaperturas,
contactos compartidos, omisión de momentos, separación y carrera de dos procesos
reales reservando la misma posición. También ejercitan el flujo con IA/proveedor
simulados y la configuración Livewire con el resumen corregido.

La ejecución amplia inicial de FollowUp, Social, Ai, Crm, Core, Institutions y pruebas
de traducciones/layout terminó con 1363 pruebas correctas y tres fallos. Se corrigieron
una entrada aleatoria que alteraba la validación de idioma y la contaminación de la
salida JSON de procesos hijos por OPcache CLI compartida; el tercer fallo correspondió
a una fixture cargada antes de la nueva marca de procedencia temporal. La repetición
completa de los módulos afectados es la de 770 pruebas de la tabla. No se afirma haber
repetido toda la suite global del repositorio después de los últimos ajustes visuales.

La reversión de la migración nueva reveló que InnoDB sustituía el índice implícito de
la clave foránea por el compuesto nuevo. La reversión ahora restaura el índice de
soporte antes de retirar el compuesto. El ensayo completo de ocho migraciones pasó
después de esta corrección.

Se abrió realmente el CRM local en navegador con un administrador e identidades
ficticias. Se comprobaron:

- Ventana activa calculada desde el mensaje original, contadores separados y próxima
  evaluación identificada como orientativa.
- Revisión y aprobación versionada del borrador: confirmación del servidor y estado
  programado, sin despacho ni encolado.
- Rechazo de intervalos incompatibles con mensaje funcional en español y foco de error.
- Guardado por asesor de primer momento a seis horas y máximo dos; persistencia
  comprobada tras recargar. El cambio invalidó borradores/acciones pendientes.
- Ventana vencida, aviso de texto libre bloqueado y ausencia de aprobación del borrador
  invalidado. El bloqueo de política se verifica adicionalmente en pruebas automatizadas.
- Escritorio 1440×1000, tablet 768×1024 y móvil 390×844. Sin desbordamiento horizontal
  del documento en las vistas revisadas. Controles de cadencia en dos columnas y una
  columna móvil, con etiquetas y foco visible; avance por Tab comprobado.
- Sin errores de consola registrados durante estos flujos.

La revisión permitió corregir un resumen que confundía el intervalo general con la
cadencia específica de WhatsApp, con cobertura automatizada, y reutilizar la cuadrícula
responsive existente para los nuevos controles. No constituye una certificación WCAG
ni una prueba de entrega en dispositivos físicos o con proveedores reales.

Evidencias locales en
`C:/Users/princ/.codex/visualizations/2026/11/04/01a1a81e-a8d1-7bb3-b099-8ee3298b5259/`:
`cadence-config-desktop.png`, `cadence-config-tablet.png`, `cadence-config-mobile.png`
y `cadence-window-mobile.png`. Las capturas contienen únicamente datos ficticios.
Logs de validación en `storage/logs/cadence-*.log`, no incluidos en el commit.

El servidor de revisión se detuvo. Los archivos ajenos `capturas-publicador/` y
`evaluacion_sophia_30_preguntas.md` se conservaron intactos. No se publicaron commits,
no hubo despliegue, cambios de credenciales, migraciones operativas ni comunicaciones
reales. La aceptación comercial y cualquier activación productiva requieren una
decisión posterior del usuario.

## Verificación global final previa a publicación

Verificación del 5 de noviembre de 2026 sobre `cb2fa21afc92703d5526c5f67f2719c99d2ba661`.
No se han modificado funcionalidades durante este cierre. La comprobación SQL de
`SELECT DATABASE()` confirmó `crm_mca_test` y `APP_ENV=testing` antes de arrancar
`vendor/bin/pest --compact --log-junit storage/logs/final-global.xml`, sin filtros.
Se deshabilitó OPcache únicamente para los procesos PHP CLI de validación.

**Resultado global satisfactorio:** 1761 pruebas aprobadas, 11202 aserciones,
0 fallos, 0 errores y 0 omitidas. Duración de Pest: 1354,16 s (22 min 34,16 s);
JUnit acumula 1342,365674 s de casos. JUnit contiene exactamente 1761 `testcase`,
sin nodos `failure`, `error` o `skipped`. No se reportaron advertencias, deprecaciones
ni pruebas arriesgadas en la salida de esta ejecución.

| Área | Pruebas | Aserciones |
|---|---:|---:|
| Ai | 306 | 2052 |
| Audit | 24 | 150 |
| Catalog | 43 | 209 |
| Chat | 22 | 105 |
| Core | 17 | 33 |
| Crm | 270 | 1606 |
| FollowUp | 291 | 1217 |
| Identity | 29 | 97 |
| Institutions | 8 | 20 |
| Integrations | 29 | 69 |
| Mcp | 67 | 917 |
| Notifications | 59 | 295 |
| Social | 480 | 2886 |
| Pruebas transversales `tests/` | 116 | 1546 |

Incluye `WhatsAppCadenceTest` (41/120), `FollowUpWorkspaceTest` (43/207),
`CalendarScreensTest` (9/94) y `EmailSenderTest` (8/39). Todos pasaron, incluidos
los casos funcionales relacionados con las dos incidencias históricas de PHPStan.
Estas cifras son subconjuntos, no ejecuciones que deban sumarse al total.

Calidad comprobada en este cierre:

- PHPStan/Larastan de FollowUp y los servicios compartidos modificados de Social y
  CRM: sin errores. PHPStan global conserva dos incidencias históricas en
  `Crm/Support/Agenda/CalendarGrid.php:112` (`foreach.emptyArray`) y
  `Notifications/Livewire/EmailSenders/Manage.php:49` (`missingType.iterableValue`).
  Sus blobs son idénticos al padre de la Etapa 1: respectivamente
  `acd0ab735d1ad60e740b5542c8ae78ad1c0c0ea0` y
  `9ec04bbb78911a14fece815381269e24bec64757`. No se añadieron ignores ni baseline.
- Pint global en modo comprobación: 871 archivos, cuatro incidencias históricas en
  `PreciseKnowledgeRanker.php`, `KnowledgeText.php`, `Contacts/Index.php` y
  `McpOpsTest.php`. Sin cambios frente al padre de la Etapa 1. Se conservaron intactos.
  Pint del alcance FollowUp/servicios compartidos: 97 archivos correctos.
- `npm run build`: correcto, 47,28 s. La variabilidad histórica del CSS por la caché
  Blade sigue documentada; los assets incidentales de esta ejecución se restauraron.
- `route:list`: cuatro rutas FollowUp con autenticación, contexto institucional,
  autorización de panel y segundo factor. Los permisos por operación se validan en
  los componentes/servicios y pruebas, no solamente en el menú.
- `module:list`: trece módulos habilitados, incluido FollowUp. `schedule:list`:
  detección, mantenimiento y sincronización comercial cada cinco minutos; despacho
  cada minuto. Se inspeccionó el registro, sin ejecutar `schedule:run`.
- El Centro de Comando se comprueba en la suite mediante Livewire: persistencia,
  cadencia, selección/canales, aislamiento entre asesores, permisos e invalidación.
  No se repitió la auditoría visual extensa: las capturas de este mismo commit se
  documentan arriba y no hay modificaciones visuales posteriores.

### Estado del remoto y alcance de publicación

`git fetch origin` terminó correctamente. Rama local: `main`. `origin/main`:
`173bbed19d8f31caae9c1122fd8387cbf4e9c2fb`. No hay commits en `HEAD..origin/main`;
`merge-base --is-ancestor origin/main HEAD` devuelve éxito. La publicación sería
fast-forward respecto de la referencia verificada, sujeta a que el remoto no cambie.

Los once commits pendientes al comenzar el cierre, en orden, son:

| Commit | Alcance |
|---|---|
| df81ac56907bd137a4771f4fbdef797837c92424 | Etapa 1: detección |
| 192f3a9c527cf0c15804007ec4615ef9dbd1d238 | Etapa 2: políticas y consentimiento |
| a9556124db355af01c6646ff7014033baae56d01 | Etapa 3: borradores cifrados |
| 316d6731e85e64dc2b0a59ce5be1d4133eeab4f7 | Etapa 4: ejecución controlada |
| c4a575ac80233516d62b92d815bef2ac1d2ff320 | Etapa 5: integración comercial |
| 054bfbd09de9065d34a43dcbbe6b42fbd27c49e5 | Etapa 6: interfaz administrativa |
| ee3973267e4e496a67b423c9e9a71a97d53bfbe9 | Etapa 7: analítica |
| 487d1b46b1270eaac168ce0b40a25a6a5403c054 | Etapa 8: endurecimiento |
| e928b2f3a1b4d205a64573866abf37bcac9472e9 | Cierre técnico anterior |
| 4f8ddd07b82380cb9e31bd24f47a38c5c6b0eb5a | Centro de Comando |
| cb2fa21afc92703d5526c5f67f2719c99d2ba661 | Cadencia WhatsApp |

Este cierre añade únicamente el commit documental que contiene este apartado;
su hash se entrega en el mensaje final y es el nuevo HEAD local. Son doce commits
pendientes contando ese cierre. El segundo `fetch`, al finalizar la suite, confirmó
la misma referencia remota y ausencia de divergencia.
No se identificaron commits remotos inesperados ni cambios locales ajenos al alcance
en esa relación. Los dos elementos no rastreados preexistentes siguen excluidos.

### Condiciones para operación futura, no ejecutadas

Las ocho migraciones reales pasaron nuevamente instalación desde cero, reversión
en orden inverso y actualización incremental sobre las tablas existentes, conservando
un contacto ficticio del CRM. Repetir `migrate --force` no añadió migraciones ni
alteró el registro aplicado. Se inventariaron columnas, valores predeterminados,
índices y claves foráneas de las once tablas del módulo, incluidos consentimiento,
auditoría y analítica. Se comprobaron las restricciones únicas de idempotencia,
reserva de cadencia, mensaje despachado y token de ejecución. No se ejecuta backfill
temporal: campos nuevos anulables y límites conservadores preservan datos previos.
El aislamiento institucional y los intentos manipulados se cubren en la suite global.

El ensayo adicional de seguridad eliminó las tres variables `FOLLOW_UP_*_ENABLED`
del entorno del proceso y comprobó que las tres resolvían a `false` por defecto.
Se serializó y reejecutó un job `ExecuteFollowUp` pendiente, con una acción ficticia
programada y configuración de ejecución ausente: quedó bloqueado por
`execution_global_disabled`, sin crear mensajes ni realizar peticiones HTTP.
También se probó ausencia de interruptor persistente con flag de proceso verdadero:
denegación correcta. Sin configuración institucional/de asesor, ambos quedan
deshabilitados en modo manual. Estado final del ensayo: 0 interruptores habilitados,
0 acciones despachadas y 0 jobs. Ninguna variable ni credencial de archivos `.env`
fue modificada. El ajuste del ensayo para retirar variables heredadas no cambió
código funcional del CRM.

La publicación en GitHub no activa el módulo ni autoriza un despliegue. Se mantienen
los valores predeterminados `false` de detección, política y ejecución; institución y
asesor deshabilitados, modo manual y cadencia deshabilitada. La configuración de
reglas y los interruptores de ejecución son permisos distintos.

La infraestructura deberá comprobarse en el hosting real antes de operar: cron único
por minuto para `php artisan schedule:run`, colas MySQL persistentes, caché/bloqueos,
retención de logs y supervisión de jobs fallidos. Comandos de consumidores derivados
del código, **solo para una futura activación autorizada**:

```text
php artisan queue:work database --queue=followup-drafts --tries=1 --timeout=75 --sleep=3
php artisan queue:work database --queue=followup-execution --tries=1 --timeout=60 --sleep=3
```

`retry_after` debe superar el timeout (90 s predeterminado). `social-advisor` no
consume estas colas. Conservar `social:reconcile-deliveries`; los resultados desconocidos
no se reenvían automáticamente. La analítica se reconstruye con
`followup:analytics-reconcile --all-institutions` por decisión explícita. No se
ejecutó ninguno de estos consumidores ni tareas operativas en este cierre. No se
verificaron capacidades de Hostinger por SSH. Sigue vigente `DEPLOYMENT.md` de este CRM.

Cloud API y Coexistence comparten protecciones. Instagram/Messenger no permiten esta
ejecución proactiva; Email no tiene ejecutor proactivo. Se conservan los límites sobre
evidencia temporal histórica, contactos no vinculados, aprobación y aceptación real
del canal. No hay aprobación implícita de envíos por superar pruebas locales.

### Dictamen de este cierre

**APTO CON CONDICIONES para publicación controlada en GitHub.** No se detectaron
defectos críticos o altos ni regresiones funcionales pendientes. La suite global
final está completamente satisfactoria. Las condiciones documentadas son conservar
explícitamente las dos incidencias estáticas y cuatro de formato históricas fuera
del alcance, sin presentar calidad global como cero incidencias, y obtener la
autorización expresa del usuario antes de publicar. La infraestructura real, assets
reproducibles, aceptación comercial y activación son condiciones posteriores de
despliegue/operación; no quedan autorizadas por este dictamen.

Evidencia local de este cierre: `storage/logs/final-global.log`, `final-global.xml`,
`final-phpstan-global.log`, `final-phpstan-scope.log`, `final-pint.log`,
`final-pint-scope.log`, `final-build.log`, `final-routes.json`, `final-modules.log`,
`final-scheduler.log`, `final-migrations.log`, `final-schema.json` y
`final-safety.log`. Los scripts efímeros de ensayo bajo `storage/logs/` no forman
parte del desarrollo ni del commit. No hubo push, PR, merge, rebase, reset, despliegue,
SSH, cambios productivos, activación de envíos ni comunicaciones reales.

## Archivos modificados

- `Modules/FollowUp/app/Console/DispatchActionsCommand.php`
- `Modules/FollowUp/app/Data/EffectiveFollowUpSettings.php`
- `Modules/FollowUp/app/Livewire/Opportunities.php`
- `Modules/FollowUp/app/Livewire/Settings.php`
- `Modules/FollowUp/app/Models/FollowUpAction.php`
- `Modules/FollowUp/app/Models/FollowUpAdvisorSetting.php`
- `Modules/FollowUp/app/Models/FollowUpSetting.php`
- `Modules/FollowUp/app/Services/ExecutionAuthorization.php`
- `Modules/FollowUp/app/Services/FollowUpActionPlanner.php`
- `Modules/FollowUp/app/Services/FollowUpDraftContext.php`
- `Modules/FollowUp/app/Services/FollowUpGatewayGuard.php`
- `Modules/FollowUp/app/Services/FollowUpPolicyEngine.php`
- `Modules/FollowUp/app/Services/FollowUpSettingsResolver.php`
- `Modules/FollowUp/app/Services/FollowUpSettingsService.php`
- `Modules/FollowUp/app/Services/OpportunityDetector.php`
- `Modules/FollowUp/app/Services/Policies/Channels/WhatsAppPolicy.php`
- `Modules/FollowUp/app/Services/WhatsAppCadence.php`
- `Modules/FollowUp/app/Services/WhatsAppWindow.php`
- `Modules/FollowUp/app/Support/Presentation.php`
- `Modules/FollowUp/database/migrations/2026_11_05_180000_add_whatsapp_cadence_controls.php`
- `Modules/FollowUp/DEPLOYMENT.md`
- `Modules/FollowUp/resources/views/livewire/opportunities.blade.php`
- `Modules/FollowUp/resources/views/livewire/settings.blade.php`
- `Modules/FollowUp/resources/views/partials/cadence-settings.blade.php`
- `Modules/FollowUp/tests/Feature/FollowUpExecutionTest.php`
- `Modules/FollowUp/tests/Feature/FollowUpStage8Test.php`
- `Modules/FollowUp/tests/Feature/WhatsAppCadenceTest.php`
- `Modules/FollowUp/tests/Support/cadence-preview.php`
- `Modules/FollowUp/tests/Support/stage8-migrations.php`
- `Modules/FollowUp/WHATSAPP_CADENCE_REVIEW.md`
- `Modules/Social/app/Services/MetaWebhookNormalizer.php`
- `Modules/Social/app/Services/SocialIngestService.php`
- `Modules/Social/database/factories/SocialMessageFactory.php`
