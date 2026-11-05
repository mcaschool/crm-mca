# Etapa 8 — Auditoría integral y preparación de entrega

Fecha: 4 de noviembre de 2026. Entorno local Windows/PHP/MySQL. Repositorio
`C:\Users\princ\ProyectosAI\CRM\CRM-MCA`, rama `main`. Sin publicación ni despliegue.

## Base auditada y arquitectura

Se verificaron como ancestros los commits de las etapas 1–7:
`df81ac56907bd137a4771f4fbdef797837c92424`,
`192f3a9c527cf0c15804007ec4615ef9dbd1d238`,
`a9556124db355af01c6646ff7014033baae56d01`,
`316d6731e85e64dc2b0a59ce5be1d4133eeab4f7`,
`c4a575ac80233516d62b92d815bef2ac1d2ff320`,
`054bfbd09de9065d34a43dcbbe6b42fbd27c49e5` y
`ee3973267e4e496a67b423c9e9a71a97d53bfbe9`.
Esto acredita presencia local, no publicación remota.

Se conservaron los propietarios de datos: Social Inbox mantiene mensajes y
conversaciones; CRM mantiene contactos, Leads y actividades; FollowUp mantiene
oportunidades, decisiones, borradores, acciones y proyección analítica.
`OpportunityDetector`/`OpportunityClassifier` detectan y clasifican sin IA.
`FollowUpPolicyEngine` resuelve reglas y adaptadores de canal; `ConsentService`
registra evidencia y cambios; `FollowUpActionPlanner` planifica con idempotencia.
`FollowUpDraftContext` y `FollowUpDraftValidator` delimitan fuentes y contenido;
`FollowUpDraftService` reutiliza AiProcessResolver, AdvisorPromptBuilder y
AiChatClient. No se añadió otro proveedor ni motor de IA.

`FollowUpExecutor` reserva, revalida y confirma intención de despacho antes de
invocar el gateway Social. `ExecutionContent` vincula contenido/plantilla/versión;
`ExecutionSwitches` aplica cierres independientes. `FollowUpGatewayGuard` endurece
el contrato interno del gateway, reutilizando el motor existente. No es un segundo
motor de políticas. La llamada HTTP permanece fuera de las transacciones de BD.
La conciliación conserva estados monotónicos y no reintenta resultados ambiguos.

`FollowUpCommercialCoordinator` usa ActivityService y estados CRM confirmados;
no sustituye Agenda ni convierte inferencias en matrículas. Las vistas Livewire
reutilizan navegación/layout existentes. Agregador, métricas e insights están
separados de ejecución. Las recomendaciones no aplican cambios.

Hay dependencias de colaboración entre módulos y resolución diferida con `app()`;
no se introdujo un ciclo de construcción. La evaluación send consulta vigencia de
borrador, cuya comprobación usa la etapa prepare, acotada antes de autorización
de ejecución. No es una recursión send→send.

## Hallazgos y correcciones

| Hallazgo | Severidad | Evidencia / tratamiento |
|---|---|---|
| Gateway interno aceptaba preparación sin reserva válida | Crítico | Reproducción falla antes de corrección. Ahora exige transacción, acción reclamada, tenant/conversación/asesor, política send y contenido exacto. |
| Gateway despachaba mensajes sin intención vinculada | Crítico | Reproducción con HTTP simulado. Ahora exige acción sending enlazada al mensaje, token, dispatch_at y hash del contenido reservado. Conserva reclamación atómica pending→sending. |
| Plantilla alterada en memoria conservando ID | Crítico | Dos reproducciones fallaron por aceptar sustitución del nombre. Se comparan ID externo, nombre, idioma y componentes con la plantilla canónica validada; el hash no sustituye esa comprobación del objeto recibido. |
| Consentimiento granted sin origen/evidencia/fecha verificable | Alto | Reproducción con registro incompleto. Ahora devuelve unknown/consent_evidence_missing; nunca autoriza envío. No se inventa ni repara evidencia. |
| Validación desigual de actor activo/contexto en servicios internos | Alto | Reproducciones directas de consentimiento, configuración, planificación y borradores. Se exige cuenta activa, rol y correspondencia con CurrentInstitution; el motor también rechaza actor inactivo. Las pantallas ya tenían control de cuenta activa. |
| Repetir aprobación renovaba fecha/vigencia y duplicaba auditoría | Medio | Reproducido secuencialmente y con dos procesos. La aprobación idéntica vigente del mismo aprobador es idempotente después de revalidar contexto/políticas. |
| Faltaban traducciones EN de interfaz/auditoría | Medio | Dos fallos de la suite global. Se añaden entradas al catálogo existente, sin cambiar identificadores ni datos históricos; incluye los textos detectados de asesores y coexistencia. |
| Nombre técnico en validación de aprobación y etiqueta Null en gráfica | Bajo | Detectados en navegador. Mensaje de revisión explícito y etiqueta Sin información; verificados visualmente después de corregirlos. |
| Análisis estático global: CalendarGrid foreach.emptyArray | Bajo | Fuera de FollowUp; inferencia de una clausura que captura arrays por referencia. Registrar resultado global sin ocultar con baseline. |
| Análisis estático global: EmailSenders Manage iterableValue | Bajo | Anotación de array no reconocida. Fuera del módulo; no cambia permisos ni ejecución. |

Las tres reproducciones iniciales fallaron (3 pruebas/3 aserciones) y, tras el
endurecimiento inicial, pasaron las 80 pruebas de políticas/ejecución
(207 aserciones, 47,15 s). Los resultados finales se documentan más abajo.

## Política, seguridad y mensajería revisadas

- Consentimiento exacto por sujeto, canal y finalidad; negativo/desconocido bloquea.
  Exclusión de contacto y Lead enrolled/discarded prevalecen. La evidencia no se
  sustituye por una afirmación de la IA.
- Horarios y zona, intervalos, intentos acumulados, activación institucional/asesor,
  modo, canal y capacidad verificada. No se relajan límites al cambiar de ciclo.
- Borradores cifrados, reservas/versiones, caducidad, contexto, fuentes autorizadas,
  rechazo de instrucciones/secretos y afirmaciones materiales sin respaldo. El
  validador fuerza revisión humana incluso si la IA propone lo contrario.
- Aprobación vinculada al contenido exacto y versión, aprobador activo, institución,
  política y vigencia. La aprobación no invoca envío.
- WhatsApp Cloud y coexistence usan el mismo gateway: ventana, plantilla aprobada,
  sincronización reciente, categoría, idioma y variables deterministas. Se prueban
  dobles, no se acredita capacidad real de Meta ni se reconectan números.
- Instagram/Messenger/email conservan sus bloqueos cuando no hay contrato proactivo
  verificado. No se amplió soporte de canales.
- No se crea mensaje al denegar políticas. Un pending posterior al compromiso es
  evidencia de intento, no una entrega ficticia. Sent exige identificador; timeout
  o aceptación sin identificador conservan unknown; webhooks tardíos no retroceden.
- Scopes institucionales, permisos backend y parámetros Livewire protegidos. No se
  añadieron URLs de envío ni operaciones que omitan los servicios.

## Analítica y límites de interpretación

Se revisaron claves de proyección por fuente, reconstrucción transaccional por
institución, ventanas de respuesta (7 días) y conversión (30 días), identidad
verificada, primer resultado confirmado y atribución al último envío elegible.
Matrícula observada no equivale a efecto causal del seguimiento. Las tareas no
cuentan como reuniones. Unknown/failed no cuentan como ejecución confirmada.
Las recomendaciones se abstienen con muestras insuficientes, ventanas abiertas
o problemas de calidad. No hay costes monetarios ni ROI inventados.

Limitaciones: dimensiones actuales, reconstrucción completa institucional y
retención de fuentes; `detected_at` de la oportunidad puede conservar la primera
detección aunque se abra otro ciclo. No interpretar el indicador como número de
ciclos nuevos. El informe de Etapa 7 no debe leerse como garantía de fecha nueva
en cada reapertura. El bloqueo global de ejecución serializa reservas entre
instituciones: seguro, pero puede limitar rendimiento a gran escala.

## Despliegue y recuperación

Plan específico: [DEPLOYMENT.md](DEPLOYMENT.md), basado en el checklist existente
de MCA CRM. Requiere confirmar capacidades reales de Hostinger y consumidores
de las colas followup-drafts/followup-execution, conservar APP_KEY, respaldo,
ejecución apagada, aceptación de humo y autorización independiente. No se ejecutó
schedule:run ni se probó el proveedor real. Una reversión no revierte mensajes
externos; los resultados ambiguos requieren conciliación y nunca reenvío ciego.

## Validación final

| Comprobación | Resultado |
|---|---|
| Suite global `php artisan test --compact` | 1.678 correctas, 2 fallidas; 10.858 aserciones; 897,26 s. Ambos fallos eran traducciones ausentes. No se oculta ese resultado inicial. |
| Regresión posterior: FollowUp completo, InterfaceMessagesTest y AuditLabelsTest | 231 correctas, 1.089 aserciones; 225,25 s. Incluye corrección de los dos fallos globales, permisos, E2E y multiproceso. |
| Ejecución y nueva integración tras endurecer plantillas | 73 correctas, 226 aserciones; 79,89 s. |
| Plantillas válidas/alteradas tras formato y comparación estricta | 8 correctas, 16 aserciones; 23,32 s. |
| Regresión final de interfaz, analítica y traducciones | 62 correctas, 297 aserciones; 59,20 s, después de las correcciones visuales. |
| Pint `--test --dirty` | Correcto: 15 archivos PHP, comprobado tras todos los cambios. |
| PHPStan/Larastan global | Ejecutado; dos incidencias bajas preexistentes fuera de FollowUp, detalladas arriba. No se añadieron ignores ni baseline. |
| PHPStan/Larastan de FollowUp y gateway Social | Sin errores tras todos los cambios, incluidas las correcciones visuales. |
| Frontend | `npm run build` correcto, Vite 8.2.1, 1 min 41 s; sin nuevas dependencias ni modificaciones de contenido en assets compilados. No hay script de lint frontend independiente. |

La prueba E2E usa detección y planificación reales, consentimiento del contacto,
cliente IA simulado, cifrado, aprobación, gateway HTTP simulado, respuesta entrante,
cambio del Lead mediante LeadService, coordinación comercial y dos reconstrucciones
analíticas sin duplicar conversión. No es una prueba con Meta ni IA real.

La prueba multiproceso usa dos conexiones MySQL por fase, con barrera temporal:
detección, planificación, reserva de borrador, generación, aprobación, ejecución y
conciliación. Comprueba una oportunidad/ciclo/acción, una llamada IA simulada, una
aprobación auditada, un despacho HTTP simulado y una transición read. Se conservan
las carreras anteriores de tareas/conversión y reconstrucción analítica.
Otra prueba consume payloads serializados desde `jobs` mediante DatabaseQueue y
Worker::process, dos veces, verificando restauración del tenant y ausencia de envío
al apagar ejecución. No equivale a certificar el daemon del hosting ni una caída
del sistema operativo. Las reservas interrumpidas se cubren con estados persistidos
y reanudación/reconciliación; no se mataron procesos operativos.

Los conjuntos se solapan; no se suman como pruebas únicas. La suite global se
ejecutó una vez y sus fallos se corrigieron y revalidaron con las suites afectadas;
no se afirma una segunda ejecución global. Las reproducciones nuevas también
mostraron fallos antes de las correcciones, incluidos aprobación duplicada y
sustitución de plantilla. Los errores de preparación de fixtures se corrigieron
antes de aceptar sus resultados.

### Migraciones e infraestructura

`stage8-migrations.php`: instalación limpia de todo el CRM, seis down en orden
inverso, seis migraciones incrementales con Artisan y preservación exacta de los
atributos persistidos de un contacto ficticio. PASS. Se comprobaron columnas de
versión/aprobación, tablas analíticas y ausencia de interruptores habilitados.
No se crearon ni modificaron migraciones de aplicación en esta etapa.

Migraciones verificadas: 100000 oportunidades; 110000 políticas/consentimiento;
120000 borradores; 140000 ejecución; 160000 analítica del 2026-11-04, y 010000
contexto comercial del 2026-11-05. Se respeta el orden real de nombres de archivo,
no se presupone que coincida con el número de etapa.

`schedule:list` confirmó los cuatro comandos FollowUp y los otros ocho registros
existentes. No se ejecutó `schedule:run`. Las colas se comprobaron con MySQL y
Worker::process; no se instaló un daemon ni se certificó infraestructura remota.
Los scripts de migraciones/rendimiento devuelven error de proceso si falla una
comprobación. No deben ejecutarse contra una base que deba conservarse.

### Rendimiento local

Una oportunidad y una acción cancelada por caso; sin contactos ni Leads asociados,
sin ejecuciones confirmadas. Fixtures dentro de transacciones revertidas. La
medición excluye la creación de fixtures y no incluye latencia externa.

| Oportunidades / acciones | Hechos | Reconstrucción | Consultas de reconstrucción | Informe | Consultas del informe | Pico PHP |
|---:|---:|---:|---:|---:|---:|---:|
| 10 / 10 | 20 | 75,54 ms | 67 | 22,07 ms | 44 | 34 MiB |
| 100 / 100 | 200 | 391,56 ms | 520 | 30,73 ms | 44 | 36 MiB |
| 500 / 500 | 1.000 | 1.840,70 ms | 2.548 | 45,94 ms | 44 | 38 MiB |

Consulta del informe constante para esta muestra; reconstrucción con escrituras
y consultas por fuente, por tanto creciente. Aceptable en el volumen medido,
sin promesa de SLA productivo. Falta medir grandes historiales de respuestas,
decenas de miles de acciones, saturación entre instituciones y cuotas reales del
hosting. No convertir estos resultados en una certificación de escalabilidad.

### Verificación visual real

Se abrió `http://127.0.0.1:8876` con una institución/cuenta ficticias, login y TOTP
normales, sin cambiar credenciales existentes. Se revisaron realmente Resumen,
Oportunidades (lista y detalle), Seguimientos, Borradores, Atención humana,
Configuración, Historial y Analítica. Escritorio 1440×1000, tablet 768×1024 y móvil
390×844; se observaron las tablas convertidas en fichas. Las vistas móviles de
configuración, oportunidades y analítica no desbordaron horizontalmente.

Se verificó el botón **Actualizar proyección**: estado de carga, botón deshabilitado,
confirmación de éxito y cambio de pendiente a cuatro oportunidades persistidas.
Queda cerrada la limitación visual concreta de Etapa 7. Se revisó búsqueda sin
resultados, editor, guardado humano como versión 2, rechazo de aprobación sin
confirmación y aprobación válida como Programada, sin envío. El historial mostró
edición, política y aprobación. Se abrió la actividad original de Agenda desde
Atención humana y la conversación original desde el detalle hacia Social Inbox.

Se comprobaron labels, estado textual, Tab dentro del modal, Escape para cerrarlo
y distribución móvil del modal. Contraste calculado de los colores de texto
observados contra superficie blanca: principal 15,44:1, secundario 7,46:1 y atenuado
5,43:1. No sustituye una auditoría WCAG completa de badges, estados, lectores de
pantalla y navegadores. No hubo errores JavaScript registrados durante el recorrido.

Comprobación MySQL posterior al recorrido: 8 mensajes ficticios originales, 0
dispatch_at, 0 jobs, aprobación de versión 2 y execution_enabled=false. El servidor
temporal se detuvo y la pestaña se cerró. Las capturas se inspeccionaron en la
herramienta de navegador; no se entrega un archivo de captura exportado.

No se probó visualmente cada combinación de rol, error, formulario de configuración
o enlace a Lead/contacto; su cobertura automatizada se distingue de aceptación
manual. Tampoco se generó contenido con IA real ni se verificó Meta, su capacidad,
sus tokens, webhooks externos o una entrega real.

## Archivos de la etapa

Modificados:

- `Modules/FollowUp/app/Services/ConsentService.php`
- `Modules/FollowUp/app/Services/FollowUpActionPlanner.php`
- `Modules/FollowUp/app/Services/FollowUpDraftService.php`
- `Modules/FollowUp/app/Services/FollowUpExecutor.php`
- `Modules/FollowUp/app/Services/FollowUpPolicyEngine.php`
- `Modules/FollowUp/app/Services/FollowUpSettingsService.php`
- `Modules/FollowUp/app/Livewire/Opportunities.php`
- `Modules/FollowUp/app/Support/Presentation.php`
- `Modules/FollowUp/tests/Feature/FollowUpExecutionTest.php`
- `Modules/Social/app/Services/SocialOutboundService.php`
- `lang/en.json`

Nuevos:

- `Modules/FollowUp/app/Services/FollowUpGatewayGuard.php`
- `Modules/FollowUp/tests/Feature/FollowUpStage8Test.php`
- `Modules/FollowUp/tests/Support/stage8-worker.php`
- `Modules/FollowUp/tests/Support/stage8-migrations.php`
- `Modules/FollowUp/tests/Support/stage8-performance.php`
- `Modules/FollowUp/DEPLOYMENT.md`
- `Modules/FollowUp/ETAPA_8.md`

## Dictamen y condiciones

**APTO CON CONDICIONES** para una decisión de publicación controlada, manteniendo
ejecución apagada. Los defectos críticos/altos identificados se corrigieron y
tienen reproducciones y regresión. No se conoce uno de esas severidades pendiente
en el alcance auditado; esto no es una garantía absoluta de ausencia de errores.

Persisten dos incidencias bajas del análisis estático global, fuera de FollowUp,
enumeradas arriba. No se modificaron esos módulos para silenciar el informe.
Antes de operar seguimientos reales se exige confirmar soporte de workers/cron,
backups restaurables, cuotas, capacidad del canal y aceptación funcional remota
con autorización separada. Las mediciones locales y los dobles no acreditan esos
requisitos productivos. Publicación, despliegue y activación siguen sin autorizar.

El hash del commit local se entrega en el mensaje final; este informe pertenece
al mismo commit. Sin push, PR remoto, SSH, despliegue, migraciones productivas,
envíos reales ni activación de automatizaciones. La Etapa 8 termina aquí; cualquier
publicación o despliegue requiere nueva autorización expresa.

Los archivos ajenos `capturas-publicador/` y `evaluacion_sophia_30_preguntas.md`
permanecen intactos y excluidos del commit. No se modificaron credenciales, datos
operativos, producción ni configuración productiva. Los scripts stage8 están
limitados explícitamente a testing/crm_mca_test y no son herramientas de despliegue.

## Cierre técnico de liberación — 5 de noviembre de 2026

Verificación solicitada después del commit `487d1b46b1270eaac168ce0b40a25a6a5403c054`.
Se repite la suite global sobre MySQL `crm_mca_test`, con informe JUnit para
contabilizar también errores y omisiones. No se ejecuta ningún worker operativo,
scheduler completo, llamada real a Meta ni comunicación real.

### Suite global definitiva

`php artisan test --compact --log-junit storage/logs/followup-release-junit.xml`
terminó con código **0** después de todas las correcciones de la Etapa 8:

| Resultado | Cantidad |
|---|---:|
| Pruebas ejecutadas y correctas | 1.696 |
| Aserciones | 10.970 |
| Fallos | 0 |
| Errores | 0 |
| Omitidas | 0 |

Duración de consola: **1.233,47 segundos**. El XML JUnit confirma los mismos
totales y contiene 1.696 elementos testcase, sin failure/error/skipped.
Los resultados anteriores de este documento son históricos; este cierre sí
acredita una nueva ejecución completa satisfactoria, sin sumar suites solapadas.

Dentro de la ejecución global, `CalendarScreensTest` pasó sus 9 pruebas/94
aserciones, `EmailSenderTest` sus 8/39 y `FollowUpStage8Test` sus 16/83, incluidas
la concurrencia multiproceso y la cola MySQL. No se detectó una regresión funcional
asociada a las dos incidencias estáticas preexistentes ni a FollowUp. No fue
necesario cambiar código o añadir pruebas para reparar regresiones en este cierre.

Evidencia local (logs ignorados por Git): `storage/logs/followup-release-tests.log`,
`followup-release-junit.xml`, `followup-release-phpstan-global.log`,
`followup-release-phpstan-scope.log`, `followup-release-pint.log`,
`followup-release-pint-scope.log`, `followup-release-build.log` y
`followup-release-migrations.log`, todos bajo `storage/logs/`.

### Calidad y procedencia de incidencias

- PHPStan/Larastan de FollowUp y `SocialOutboundService`: **sin errores**.
- PHPStan global: reproduce exactamente las dos incidencias ya documentadas.
  Los blobs Git de ambos archivos coinciden antes de la Etapa 1 y en el cierre:
  `CalendarGrid.php`: `acd0ab735d1ad60e740b5542c8ae78ad1c0c0ea0`;
  `EmailSenders/Manage.php`: `9ec04bbb78911a14fece815381269e24bec64757`.
  No fueron introducidas por las ocho etapas. La primera es una inferencia
  estática de arrays capturados por referencia; existe una prueba funcional de
  carriles simultáneos en `CalendarScreensTest`. La segunda es una anotación
  PHPDoc de los destinatarios de prueba SMTP, no un error del ejecutor FollowUp.
- Pint de FollowUp y gateway: **82 archivos correctos**. Pint global en modo
  `--test`: **864 archivos, cuatro incidencias de estilo** en
  `Modules/Ai/app/Services/PreciseKnowledgeRanker.php`,
  `Modules/Ai/app/Support/KnowledgeText.php`,
  `Modules/Crm/app/Livewire/Contacts/Index.php` y
  `Modules/Mcp/tests/Feature/McpOpsTest.php`. No hay diferencias de esos archivos
  entre el padre de la Etapa 1 y HEAD. Se conservan intactos; no se presenta el
  formato global como aprobado.
- `npm run build`: **correcto**, Vite 8.2.1, 32,28 segundos. La configuración
  Tailwind preexistente incluye `storage/framework/views`; el CSS resultante
  varía con la caché local. Se restauraron únicamente el manifest y CSS que esta
  verificación había regenerado, previamente limpios. No se publica un cambio
  incidental de assets. La preparación futura de assets debe controlar esa
  dependencia de la caché; esta comprobación no acredita un build reproducible.

### Interruptores y colas

`detection_enabled`, `policy_enabled` y `execution_enabled` conservan valor por
defecto `false`; no hay overrides `FOLLOW_UP_*_ENABLED` en el `.env` local leído.
Las migraciones crean configuración institucional/de asesor deshabilitada,
modo `manual` y switches de ejecución con `enabled=false`. La ausencia de un
switch también deniega ejecución: no existe activación implícita por ausencia.

`followup:dispatch` no encola con ejecución global apagada. Un job ya persistido
vuelve a validar política y switches global/institución/asesor/canal antes de
reservar y antes de comprometer el despacho. Se exige capacidad del canal vigente
y con evidencia. La cola `followup-drafts` solo genera/persiste borradores; no
invoca envíos. Aprobar contenido tampoco despacha. Las pruebas existentes consumen
jobs reales de DatabaseQueue con proveedor simulado y comprueban cero envíos al
apagar ejecución. No se confunde la disponibilidad de un worker con autorización.
Un intento ya comprometido tras activación previa conserva su conciliación: el
apagado no revierte mensajes ni autoriza reintentar resultados desconocidos.

### Estado Git y alcance

Al comenzar, `main` tenía ocho commits por delante de la referencia local
`origin/main`, correspondientes a Etapas 1–8, y cero por detrás. No se ejecutó
fetch: esta comparación describe las referencias locales, no verifica el estado
actual del servidor GitHub. No se modificaron los archivos ajenos
`capturas-publicador/` ni `evaluacion_sophia_30_preguntas.md`.

### Migraciones y dictamen definitivo

Después de terminar la suite se repitió `stage8-migrations.php`: **correcto**,
código de salida 0. Se validaron instalación limpia, rollback inverso,
reinstalación incremental y conservación del contacto ficticio previo, únicamente
en `crm_mca_test`. Las seis migraciones quedaron registradas en este orden:

1. `2026_11_04_100000_create_follow_up_opportunities_table`
2. `2026_11_04_110000_create_follow_up_policy_tables`
3. `2026_11_04_120000_add_drafts_to_follow_up_actions`
4. `2026_11_04_140000_add_follow_up_execution`
5. `2026_11_04_160000_create_follow_up_analytics`
6. `2026_11_05_010000_add_follow_up_commercial_context`

Consulta final del entorno de prueba: detection/policy/execution = false;
cero switches persistidos y cero switches habilitados. No se añadieron migraciones.

**APTO PARA PUBLICACIÓN CONTROLADA EN GITHUB**, con las incidencias preexistentes
de análisis estático/formato y la limitación de reproducibilidad de assets
expresamente documentadas. La suite global está verde y no se han encontrado
regresiones funcionales ni bloqueadores nuevos de FollowUp para publicar el código.
Este dictamen sustituye la incertidumbre sobre la repetición de la suite global
del dictamen anterior; no acredita preparación productiva del hosting.

No se autoriza ni se ejecuta publicación, despliegue o activación. Las condiciones
de operación de `DEPLOYMENT.md` siguen vigentes. Solo se actualiza este informe
y se registra en un commit documental local adicional: nueve commits por delante
de la referencia local `origin/main`, con los ocho commits funcionales intactos.
Los dos elementos ajenos continúan sin seguimiento y fuera del commit.
