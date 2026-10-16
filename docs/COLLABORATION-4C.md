# MCA Colaboración — Corrección 4C

## Base y alcance

Rama: `feature/collaboration-teamchat`. Worktree: `C:\Users\Maikel\Proyectos Claude Code\CRM-MCA-teamchat`.
Base limpia: `42f12a7283d1cf3b5354f061d0efc802b2fe5e2b` (Fase 8).
Después de 4B (`588f8ce`) se conservan Fase 6 (`771758f`), Fase 7 (`44f66d5`) y Fase 8.
No se modifican sus assets, funcionalidades ni interfaces públicas.

Integración revisada: `0d3c629b09d0b4d7bf3359ff0672ffde5e3e0aa3`.
Se creó una copia Git de validación independiente, con HEAD desacoplado, en
`C:\Users\Maikel\Proyectos Claude Code\CRM-MCA-4c-validation`.
Las dependencias ya instaladas se copiaron localmente; no se descargaron paquetes.
La copia temporal se retiró al terminar, después de conservar sus evidencias en este worktree.
No se editó TeamCalls, main, contratos ni los worktrees originales de Claude.
La copia de validación recibe sólo los archivos de esta corrección; no se hace merge ni cherry-pick.

## Causa raíz demostrada

El cambio a `scoped(CurrentInstitution::class)` expuso referencias retenidas al contexto anterior.
Laravel renueva los servicios scoped en el callback de `QueueServiceProvider::registerWorker()`.
El router conserva el controlador resuelto, que conserva SocialIngestService y sus dependencias.
Si ese grafo vuelve a utilizarse después de ejecutar el worker, su contexto inyectado en el constructor
ya no es el contexto que InstitutionScope obtiene del contenedor.

Por tanto, `runGlobally()` habilitaba el contexto antiguo, mientras la consulta usaba uno nuevo sin institución:
`MissingInstitutionContextException` y HTTP 500 en el webhook. Las pruebas originales alternan webhooks
y el worker persistente en la misma aplicación de testing.

Prueba mínima con un worker database real sobre una cola vacía: el objeto scoped cambia; la instancia
retenida de SocialIngestService falla y una instancia recién resuelta devuelve `parked` para el mismo canal desconocido.
También se reprodujo un caso original sobre el árbol integrado sin corregir.

**Precisión respecto al diagnóstico de Claude:** SyncQueue no ejecuta ese callback de reinicio.
El desencadenante observado es `queue:work` mediante `social:advisor-worker`, no el despacho sync por sí solo.
No se presenta una simulación de reinicio como evidencia de una incidencia observada en producción.

## Corrección

Se conserva CurrentInstitution scoped, InstitutionScope, ResolveInstitutionFromUser y TenantAwareJob.
No se elimina ningún filtro, no se introduce institución predeterminada y no se usa un singleton de estado.

Se elimina la captura del contexto mutable en los constructores de siete servicios Social:
SocialIngestService, WhatsAppTemplateService, WhatsAppCoexistenceService, MetaLeadFormService,
MetaLeadPageService, SocialPostScheduler y DeliveryReconciler.
Cada operación resuelve `app(CurrentInstitution::class)` en el momento de uso.
La identidad institucional continúa derivándose del canal, WABA, página o fila persistida correspondiente.
Los ámbitos globales explícitos y runFor conservan sus límites y restauración mediante finally.

El mismo defecto se demostró y corrigió en InstitutionExecution y DatabaseEventRecorder:
el primero establece el contexto vigente; el segundo comprueba la institución ambiente vigente
antes de grabar el evento. La prueba negativa demostró que un recorder retenido podía aceptar
un evento de A mientras el nuevo contexto era B. Ahora lo rechaza y no persiste el evento.
No se cambia la transacción, outbox, secuencia, reintentos ni formato de eventos.

Los constructores internos dejan de recibir CurrentInstitution; la resolución por el contenedor sigue funcionando.
No se modifican firmas de contratos compartidos ni se requiere una nueva versión contractual.

## Revisión de ajustes de Claude

- `tests/RealtimeFixtures.php`: aceptado. La prueba instala su propio EventPolicies y conserva las políticas
  internas de revocación. Así puede registrar su doble de `call.ended` sin colisionar con el propietario real
  TeamCalls. Esto no reemplaza políticas de producción ni prueba la autorización real de llamadas.
- `Modules/TeamChat/tests/Feature/Compatibility4BTest.php`: aceptado. Retira explícitamente RevocationConsumer
  en el escenario “sin consumidor”; después instala el doble que prueba fallo, reintento y deduplicación.
  Las aserciones se conservan. El resto de pruebas mantiene el consumidor que corresponda.
- Ambos ajustes siguen siendo necesarios después de Fase 8 y se incorporan aquí.
  Los adaptadores y pruebas del consumidor real permanecen bajo responsabilidad de TeamCalls.

## Cobertura nueva

- Ingesta retenida para WhatsApp, Instagram y Messenger, instituciones alternadas y deduplicación.
- Webhooks de plantillas y de estado de cuenta WhatsApp después de renovar el scope; no actualizan otra institución.
- Servicios programados retenidos: reconciliación, publicación, sondeo de formularios y propiedad de páginas.
- Contextos anidados, modo global explícito, excepciones y restauración.
- Peticiones HTTP de dos instituciones y petición anónima sin contexto residual.
- Worker database real, dos instituciones, tres ejecuciones incluyendo reintento, sin institución ambiente después de cada job.
- Excepción en job sync con restauración de la institución exterior.
- InstitutionExecution y recorder retenidos, rechazo entre instituciones y aceptación en el contexto correcto.

Los nueve casos Social añadidos fallaron antes de corregir. Dos pruebas adicionales expusieron
los fallos de InstitutionExecution y recorder. Las pruebas de ciclo de vida que ya funcionaban se mantienen.

## Resultados

| Ejecución | Resultado |
|---|---|
| Social completa, incluidos nueve casos nuevos | 480 pruebas, 2.895 aserciones, aprobadas |
| Colaboración, Core, Identity, Institutions, Auth, Security y Architecture | 240 pruebas, 1.331 aserciones, aprobadas |
| Contratos compartidos | 30 pruebas, 341 aserciones, aprobadas |
| MCP completa | 66 aprobadas y 1 fallida; 915 aserciones; 75,50 s |
| Outbox/colas y concurrencia con MySQL real | 35 comprobaciones, aprobadas |
| Reverb local real, reconexión y revocación | 14 comprobaciones, aprobadas |
| PHPStan nivel 6 | Social/Core, CollaborationCore, CollaborationRealtime y TeamChat sin errores |
| Laravel Pint | 15 archivos PHP aprobados |
| Diff | Sin errores de espacios |
| Árbol integrado con TeamCalls | 516 aprobadas, 1 fallida, 3 omitidas; 3.494 aserciones; 638,36 s |

Comparación automática por nombre y dataset: las 15 regresiones originales aparecen aprobadas en
`.testing/phase4c-original-15-results.json`. No se cambió ninguna de sus aserciones.
La reproducción previa aislada de las cuatro suites afectadas más los nueve casos añadidos produjo
98 aprobadas y 24 fallidas (15 originales + 9 nuevas), 820 aserciones, 214,11 s.

La ejecución conjunta de esta rama incluyó inicialmente McpOpsTest solo: 721 aprobadas y 10 errores,
4.230 aserciones, 745,31 s. Esos diez errores eran `mcpCtx()` ausente porque su helper reside en otra
prueba de MCP; la corrida completa de MCP posterior sustituye esa evidencia incompleta.
Las filas Social y Colaboración de la tabla se extraen del JUnit de esa misma ejecución y no incluyen MCP.

PHPStan utiliza las configuraciones específicas ya existentes para Colaboración (validación defensiva
de DTO en tiempo de ejecución), y phpstan.neon para Social/Core con los modelos consumidores de sus traits.
No se añadieron baselines ni supresiones. Una invocación general con un conjunto incompleto de consumidores
y una política PHPDoc distinta reportó avisos; se repitió con el alcance/configuración mantenidos.

Logs y JUnit locales: `.testing/phase4c-target-before.*`, `phase4c-regression.*`, `phase4c-mcp.*`,
`phase4c-contracts.log`, `phase4c-realtime.log`, `phase4c-websocket.log`, `phase4c-stan-*.log`,
`phase4c-pint-final.log`. No se versionan credenciales ni fixtures temporales.

## Bloqueo adicional descubierto en TeamCalls (no modificado)

La corrida integrada comprende 520 casos: 122 de Social (todos aprobados), 194 de servicios compartidos
y seguridad (todos aprobados), y 204 de TeamCalls (200 aprobados, uno fallido y tres omitidos).
Los 15 casos Social originales también se cotejaron por nombre/dataset contra este JUnit: todos aprobados.
Los adaptadores reales de Integration4B y la conformidad CallsGateway están incluidos.

Falla `Modules/TeamCalls/tests/Feature/CallRealConcurrencyTest.php:105`,
“reintentos simultáneos con la misma clave: una sola llamada para todos”:
esperaba seis resultados ok, obtuvo cinco ok y un busy.

No es evidencia de una regresión de 4C: se reprodujo de forma controlada tanto con los nueve archivos
de producción restaurados al HEAD original 0d3c629 en la copia temporal como con la corrección aplicada.
Sin editar TeamCalls, un listener de consulta detuvo lógicamente el primer inicio después de leer
una clave inexistente y ejecutó/confirmó el segundo inicio en otro proceso MySQL antes de continuar.
Ambas versiones produjeron:
`interleaving_injected=true, competing_request=ok, first_request=busy, retry_same_call=true, calls=1`.

La ventana está en CallService::start(): consulta idempotencia (línea 79), resuelve destinatarios y comprueba
busy (líneas 89–90) antes de entrar en la transacción. La revalidación de idempotencia bajo lock
(líneas 98–104) no llega a ejecutarse si otro proceso confirmó la misma clave entre esas dos lecturas.
El problema afecta a la respuesta idempotente concurrente, no creó llamadas duplicadas en la reproducción.

**Pendiente de Claude:** revisar esa decisión busy para que quede coordinada con la consulta idempotente
y el bloqueo de usuario dentro de la transacción, conservando validación de huella y exclusión para claves
distintas. Repetir la prueba concurrente existente y la suite del motor. No se ha realizado esa modificación.
La compatibilidad global integrada no se declara cerrada mientras este fallo siga pendiente.

La reproducción usa MySQL y procesos reales, con el FakeOutbox que ya utiliza la prueba del motor
para los eventos call.*. No usa LiveKit real. Script y logs conservados localmente en
`.testing/phase4c-integration-evidence/`; el script sólo puede ejecutarse sobre un árbol con TeamCalls
y verifica explícitamente la base aislada. Para reproducirlo, copiar el script desde el archivo de evidencias
al directorio .testing de ese árbol integrado antes de ejecutarlo.

## McpOpsTest y pruebas omitidas

OpsTools::gitSummary() y su prueba leen `.git/HEAD` como si `.git` siempre fuera un directorio.
En un linked worktree es un archivo con la referencia al directorio Git real.
Es una incompatibilidad preexistente con el entorno worktree, independiente del contexto institucional.
No se modifica MCP ni se oculta su fallo en esta corrección.

Las tres omisiones reportadas por Claude corresponden a
`Modules/TeamCalls/tests/Feature/LiveKit/LiveKitRealServerTest.php`:
1. API de salas real: crear, listar, participantes, retirar y cerrar (idempotentes).
2. Credenciales reales: válidas solo para salas creadas por el backend; firma y vigencia.
3. Errores reales: token de servicio sin permiso (401) y servidor caído → media_unavailable.

Requieren TEAMCALLS_LIVEKIT_REAL_API_URL/KEY/SECRET. No se configura LiveKit en esta orden
ni se consideran aprobadas por su omisión.

## Entorno, límites y traslado

Sólo MySQL aislado en 127.0.0.1:33317, base `crm_mca_collaboration_core_test`, datadir propio
`.testing/mysql`; nunca credenciales de producción. Las suites que escriben esa base se ejecutan secuencialmente.
Una primera corrida Social se interrumpió por el límite PHP de 128 MB al generar una imagen;
se repitió con 768 MB. No se cambió esa prueba ni la configuración de producción.
La primera copia integrada no tenía .env: phpdotenv emitía avisos de lectura. Se detuvo esa corrida,
se creó exclusivamente en la copia un .env de testing sin credenciales externas y se repitió la validación.
También se copiaron los assets compilados locales existentes para disponer del entorno de vistas completo.

Meta e IA usan payloads de prueba y dobles HTTP/IA. No se validan credenciales, permisos o servicios externos reales.
La simulación `forgetScopedInstances()` reproduce el mecanismo del contenedor; el worker database sí es real.
Las verificaciones locales no certifican Octane, Redis ni un despliegue productivo.
No se repitieron los recorridos de navegador de Fases 6–8: su código de interfaz y assets no cambian;
se ejecutaron sus regresiones de servidor, incluidas las 14 pruebas del widget, y Reverb real.
Tampoco se presenta esta selección como una nueva ejecución de la totalidad de suites de todos los módulos del CRM.

Claude debe incorporar este commit de forma controlada, resolver la carrera de idempotencia descrita arriba,
conservar sus adaptadores de TeamCalls y repetir
la validación de su rama objetivo. Los dos fixtures ya adaptados sólo difieren en comentarios.
La validación aislada no integra automáticamente Fases 6–8 en su rama.
No se hace push, despliegue, cambio DNS ni trabajo de una fase funcional nueva.
Al finalizar se verificaron cero usuarios, mensajes Social y llamadas en la base aislada y se detuvo su proceso MySQL.
Las referencias protegidas main, contratos, feature/collaboration-teamcalls e integration/collaboration-4b
conservaron los hashes iniciales. El commit local y el estado final de Git se consignan en la entrega del chat.

## Relación de las 15 regresiones originales

Todos los casos fallaron antes de corregir con el contexto obsoleto descrito arriba y están aprobados en el JUnit final de esta rama.

| Nº | Clase y caso (incluido dataset) |
|---|---|
| 1 | SocialAdvisorCatalogTest — it reactivación en el mismo segundo: el mensaje A (recibido antes) nunca se responde; solo B, una vez, y la racha se reinicia with data set "('whatsapp') / dataset "el job de A corre mientras atiende la persona"" |
| 2 | SocialAdvisorCatalogTest — it reactivación en el mismo segundo: el mensaje A (recibido antes) nunca se responde; solo B, una vez, y la racha se reinicia with data set "('whatsapp') / dataset "el job de A corre tras reactivar y antes de B"" |
| 3 | SocialAdvisorCatalogTest — it reactivación en el mismo segundo: el mensaje A (recibido antes) nunca se responde; solo B, una vez, y la racha se reinicia with data set "('whatsapp') / dataset "el job de A corre después de llegar B"" |
| 4 | SocialAdvisorCatalogTest — it reactivación en el mismo segundo: el mensaje A (recibido antes) nunca se responde; solo B, una vez, y la racha se reinicia with data set "('messenger') / dataset "el job de A corre mientras atiende la persona"" |
| 5 | SocialAdvisorCatalogTest — it reactivación en el mismo segundo: el mensaje A (recibido antes) nunca se responde; solo B, una vez, y la racha se reinicia with data set "('messenger') / dataset "el job de A corre tras reactivar y antes de B"" |
| 6 | SocialAdvisorCatalogTest — it reactivación en el mismo segundo: el mensaje A (recibido antes) nunca se responde; solo B, una vez, y la racha se reinicia with data set "('messenger') / dataset "el job de A corre después de llegar B"" |
| 7 | SocialAdvisorCatalogTest — it concurrencia: si un worker tiene el turno (lease), la reactivación no se fuerza ni toca nada; al liberarlo, reactiva |
| 8 | SocialAdvisorCutoffTest — it la reactivación individual de una conversación sigue funcionando con el mismo corte with data set "('whatsapp')" |
| 9 | SocialAdvisorCutoffTest — it la reactivación individual de una conversación sigue funcionando con el mismo corte with data set "('messenger')" |
| 10 | SocialAdvisorTest — it cola persistente: el worker programado procesa, deja su latido y la respuesta sale una sola vez |
| 11 | SocialAutomationTest — it reactivar no contesta mensajes antiguos y queda registrado quién pausó y reactivó |
| 12 | SocialAutomationTest — it la PRIMERA activación de una cuenta solo atiende mensajes posteriores a ese momento |
| 13 | SocialAutomationTest — it un estado de error no reintenta solo: los mensajes nuevos no consultan la IA ni dejan trabajos pendientes |
| 14 | SocialAutomationTest — it el proveedor acepta y cae el worker: sin confirmación termina en «entrega sin confirmar», pausa, una alerta y nunca reenvía |
| 15 | SocialAutomationTest — it un eco posterior confirma el envío que quedó sin confirmar (sin crear otro mensaje ni pausar) |
