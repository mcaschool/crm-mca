# Etapa 7 — Analítica comercial y optimización supervisada

## Alcance y arquitectura

Se integra Analítica en la navegación de Seguimiento Inteligente, en `/crm/seguimiento/analitica`. Reutiliza layout, breadcrumbs, tarjetas, botones, badges, tablas adaptativas y paginador del CRM. No incorpora librerías, un motor de IA alternativo ni otro CRM.

`FollowUpAnalyticsAggregator` reconstruye una proyección derivada del estado persistido. `FollowUpMetricsService` consulta únicamente esa proyección. `FollowUpInsightsService` produce recomendaciones deterministas y permite, exclusivamente bajo demanda, que el cliente IA existente seleccione identificadores de un catálogo cerrado. `Analytics` integra filtros, comparación con el periodo anterior, gráficos HTML nativos, calidad, consumo, recomendaciones y evidencia paginada.

Fuentes reutilizadas: oportunidades, ciclos vigentes, acciones, eventos FollowUp, mensajes originales de Social Inbox (solo metadatos), contactos, Leads, actividades de Agenda, retiros de consentimiento y `AiUsageEvent`. No se copian cuerpos de mensajes, contenido de borradores, prompts, teléfonos, correos ni credenciales.

## Persistencia, reconstrucción y retención

La migración `2026_11_04_160000_create_follow_up_analytics.php` crea:

- `follow_up_analytics_runs`: una fila de control por institución, generación vigente, versión, fecha de finalización y número de hechos.
- `follow_up_analytics_facts`: hechos derivados de oportunidad, acción, respuesta, conversión, reunión, exclusión, generación, aprobación y consumo; dimensiones y referencias a las fuentes originales.

La clave única `(institution_id, kind, source_key)` evita duplicación por reconstrucción. Hay índices por institución/tipo/fecha, ejecución, identidad y oportunidad. Una transacción y el bloqueo de la fila institucional serializan reconstrucciones concurrentes; un fallo revierte el conjunto. Se procesan fuentes en lotes de 100. Se eliminan proyecciones obsoletas y se reconstruyen asociaciones después de corregir/eliminar las acciones fuente. Cada reconstrucción cambia la generación de la caché; consultas cacheadas durante 60 segundos.

Actualización explícita mediante el botón administrativo o `php artisan followup:analytics-reconcile --institution=ID`. El comando hereda el aislamiento de `TenantAwareCommand`; no está programado, no activa workers y no se ejecuta al abrir la página. La unidad de reprocesamiento es una institución completa, no un rango temporal incremental. No se elimina información fuente: la retención de la proyección sigue a las fuentes durante la siguiente reconciliación. Las dimensiones representan el estado actual de los vínculos, no una historia bitemporal.

## Diccionario de métricas

Todos los periodos se interpretan en UTC; rango máximo 366 días. La comparación usa el mismo número de días inmediatamente anterior. Las unidades son registros o identidades únicas y porcentajes explícitos. Denominador cero produce `null` y «Sin datos suficientes», nunca un 0 % inventado.

| Indicador | Fuente, unidad y fecha | Numerador / denominador, exclusiones y límites |
|---|---|---|
| Oportunidades detectadas | Oportunidad única; `detected_at` vigente | No suma ciclos repetidos. Un nuevo ciclo puede cambiar la fecha de clasificación de la oportunidad. |
| Propuestas y estados | Acción única; fecha de creación | Estado actual. Diferencia pendiente, aprobación, programación, bloqueo, proceso, envío, entrega, lectura, fallo, desconocido, cancelación y caducidad según persistencia. |
| Ejecuciones | Acción con envío confirmado; `dispatch_at` | Exige mensaje outbound de la misma conversación, ID externo, estado sent/delivered/read del mensaje y de la acción. Excluye `delivery_unknown`, fallos y afirmaciones sin evidencia. |
| Respuesta | Identidades únicas de la cohorte de ejecución | Identidades con inbound posterior, en hasta 7 días / identidades con ejecución confirmada. Antes o simultáneo al envío no cuenta. |
| Reactivación | Oportunidades únicas de la cohorte | Oportunidades con respuesta / oportunidades con ejecución confirmada. No suma ciclos. |
| Respuesta no asociada | Conversación; primera respuesta posterior a detección | No tiene envío elegible anterior en 7 días. No se atribuye al sistema ni se afirma conocer la causa espontánea. |
| Matrículas observadas | Lead único; primer evento CRM verificable de conversión | Requiere estado actual enrolled y evento commercial.synchronized con basis confirmed_crm, resultado converted e ID de Lead coincidente. Sin fecha verificable se conserva como incidencia y no se asigna al periodo. |
| Matrículas asociadas | Lead único asociado a cohorte de ejecución | Último envío elegible anterior en 30 días, mismo contacto verificado y mismo Lead. No es atribución causal. |
| Tasa de conversión | Identidades únicas | Identidades con matrícula asociada / identidades alcanzadas. Varios Leads de un contacto no multiplican el numerador de esta tasa. |
| Reuniones | Actividad actual vinculada; fecha de creación | Solo video_call/in_person_meeting programada o completada y del contacto correcto. Completadas según estado actual. Tareas, sugerencias y cancelaciones no cuentan. No reconstruye actividades históricas ya desvinculadas. |
| Cualificadas, perdidas, intervención humana | Oportunidad; fecha de detección | Estado comercial actual, motivo de cierre y hold actual. Son estados descriptivos, no efectos demostrados del seguimiento. |
| Propuestas de reunión/inscripción | Recomendación de oportunidad | Señales automáticas separadas de reuniones/matrículas reales. |
| Generaciones | Evento draft.generated por acción y versión; primera fecha | Duplicados conservan la primera fecha. Generaciones adicionales en el periodo = versiones generadas menos acciones distintas generadas en ese periodo; no mide repeticiones que empezaron fuera del periodo. |
| Aprobación | Evento execution.approved por acción y versión | Versiones generadas en el periodo aprobadas / versiones generadas. Versiones exclusivamente editadas no integran esta cohorte. |
| Error | Acción por fecha de creación | Acciones failed con dispatch_at / acciones con dispatch_at. Desconocido no es fallo. No usa la fecha exacta de fallo porque no se conserva uniformemente. |
| Exclusión | Contacto con baja o evento de retiro de consentimiento | Identidades con retiro/baja posterior asociado en 30 días / identidades alcanzadas. Consentimiento exige canal y finalidad coincidentes. Duplicados no multiplican identidades. |
| Entrega y lectura | Mensaje vinculado a ejecución confirmada | Estados delivered/read; sent no equivale a delivered. No se inventan tiempos de entrega/lectura. |
| Tiempo de respuesta | Diferencia entre dispatch_at y primer inbound elegible | Media por acción confirmada con respuesta. Varias acciones pueden preceder al mismo inbound; no es media deduplicada por persona. |
| Consumo y latencia IA | AiUsageEvent de proceso followup_draft; created_at | Suma de tokens y media de latencia registrada; solo segmentable por institución/asesor. Otros filtros no permiten atribuir consumo por acción/canal/programa. |
| Costes y rentabilidad | No disponibles | No existen tarifas históricas/importes verificables suficientes. No se estiman ni se presenta ROI, coste por respuesta o conversión ficticio. |

Identidad: contacto con vínculo `manual` o `verified_phone` y Lead del mismo contacto. Sin vínculo verificado, respuesta usa identidad de conversación, sin deduplicación garantizada entre canales y sin atribución de matrícula. Los resultados asociados pueden ocurrir después del periodo de ejecución; las ventanas de 7/30 días deben madurar antes de interpretar cohortes recientes.

## Dashboard y navegación

Seis indicadores principales, tasas y denominadores, periodo anterior, calidad, distribución por día/estado/prioridad, conversiones, motivos de bloqueo/error, segmentos por canal/asesor/programa y horas UTC. Los segmentos muestran hasta 30 grupos por volumen y advierten muestras pequeñas. No se considera más volumen como mayor efectividad.

Filtros por fechas, asesor, canal, programa, prioridad, etapa comercial, estado de oportunidad, tipo/resultado de acción y responsable; institución impuesta por el contexto autenticado. Los indicadores enlazan a sus registros de origen, respetando la cohorte de ejecución aunque la creación ocurriera fuera del periodo. Evidencia paginada de 20, con acceso a la oportunidad y desde ella a las áreas ya integradas. Un intervalo inválido muestra una pantalla recuperable sin cifras engañosas.

## Recomendaciones y seguridad

La generación se abstiene con menos de 20 identidades, ventanas de respuesta abiertas o incidencias de calidad. No afirma significación estadística. Cada recomendación muestra métrica, valor actual/anterior, periodo, volumen y limitaciones. Catálogo: revisar bloqueos, errores, solicitudes de baja, intervención humana y claridad/horarios del contenido. Nunca propone eludir consentimiento o restricciones ni aumentar volumen automáticamente.

IA opcional: usa `AiProcessResolver` y `AiChatClient`, proveedor configurado del asesor. Recibe solo catálogo y agregados; devuelve IDs autorizados. JSON inválido, IDs desconocidos o fallo del proveedor conservan recomendaciones deterministas. No se llama IA en render ni en reconstrucción. No modifica parámetros operativos ni aplica recomendaciones.

Acceso: administrador activo con `canManageSettings()`. Se verifica en cada petición Livewire y también en el servicio de recomendaciones con institución del actor. No se amplían roles. Los modelos conservan el scope institucional; parámetros de URL no pueden elegir otra institución. Recomendaciones Livewire bloqueadas contra manipulación; Blade escapa contenido. No hay rutas de envío ni cambios en las validaciones de ejecución.

## Rendimiento y límites operativos

Las consultas del informe tienen un número fijo independiente de los hechos: prueba con 200 registros adicionales. La proyección evita cargar conversaciones en la pantalla. Reconstrucción por lotes con búsquedas de evidencia por conversación; coste lineal y transacción institucional completa, no procesamiento incremental. Para instituciones grandes debe medirse su duración y preferirse el comando local antes de habilitar el botón en cargas reales; no se afirma un benchmark productivo. Los dropdowns reutilizan catálogos institucionales actuales.

No se añaden automatizaciones, colas ni disparadores productivos. La información ausente no se completa con IA. Históricos incompletos, vínculos corregidos, eliminación de fuentes y estado actual pueden cambiar cifras tras reconstrucción. Falta validar utilidad comercial con datos reales autorizados; las fixtures solo demuestran comportamiento técnico.

## Validación

Todo acceso a datos durante esta etapa se limita a `crm_mca_test`; proveedores simulados y ejecución deshabilitada. Sin push, despliegue, SSH ni comunicaciones reales.

| Comprobación | Resultado demostrado |
|---|---|
| Regresión FollowUp 1–7, asesores, Social, Core/tenancy, Leads, Contactos, Agenda, remitentes y permisos | 777 pruebas, 4.401 aserciones; correctas; 533,39 s. |
| Revalidación de analítica tras ajustes de consultas indexadas y recuperación de fechas | 35 pruebas, 172 aserciones; correctas; 52,06 s. |
| Verificación posterior a los ajustes visuales: fechas, evidencia por cohorte y actualización Livewire | 3 pruebas, 17 aserciones; correctas; 18,64 s. Incluye persistencia de la proyección y conservación de la acción original. Estas pruebas se solapan con la batería anterior; no deben sumarse como casos únicos. |
| Concurrencia | Dos procesos PHP/MySQL reconstruyen la misma institución sin duplicar acción, conversión ni generación vigente. |
| PHPStan/Larastan | Sin errores en `Modules/FollowUp/app` y `app/View/Breadcrumbs.php`. |
| Pint | Comprobación sobre los 15 archivos PHP cambiados. |
| Frontend | `npm run build` correcto; sin librerías añadidas ni cambios de contenido en los assets compilados. No existe un script independiente de lint frontend en package.json. |

La cobertura específica comprueba: evidencia de envío, aceptación versus entrega, desconocido versus fallo, límites de 7 días, conversión única y último elegible, identidad/fechas ausentes, correcciones y eliminación de fuentes, inferencias sin matrícula, aislamiento institucional, roles, manipulación de filtros, estados vacíos, ausencia de IA al render, catálogo IA válido/inválido/fallo, abstención, deduplicación de versiones, filtros/periodos, consultas constantes al añadir 200 hechos, concurrencia real, respuestas sin asociación, retiros de consentimiento, telemetría sin costes inventados, reuniones versus tareas y acceso a evidencia fuera de la fecha de creación.

## Revisión visual y aceptación pendiente

Se abrió realmente la aplicación local en navegador, con una institución y cuenta ficticias, autenticación normal y sin worker. Se revisaron escritorio 1440×1000, tablet 768×1024 y móvil 390×844. Se comprobaron encabezado, navegación interna, widgets, gráficos con etiquetas y valores, filtros, estado vacío por canal, tasas, advertencias, evidencia, navegación a oportunidad y recuperación de fechas inválidas. En móvil, la tabla pasa a tarjetas; se comprobó que no hay desbordamiento horizontal en la vista observada. La recomendación por muestra insuficiente se mostró correctamente. No se observaron errores JavaScript durante estos flujos.

Se corrigió la unión visual del enlace de evidencia con el periodo anterior, se acortó su texto conservando nombre accesible completo y se añadió desplazamiento hacia la evidencia. La selección de evidencia queda alineada con el indicador consultado. Los controles conservan labels, foco y estados textuales; no dependen solo del color.

Limitación explícita: la herramienta del navegador quedó bloqueada al manejar una confirmación JavaScript de actualización. Se recuperó una pestaña nueva y se simplificó la actualización idempotente eliminando esa confirmación redundante; no se debilitó ningún permiso ni validación operativa. No se pudo confirmar visualmente la finalización del botón en esa sesión. El método Livewire sí fue probado directamente con MySQL, comprobando persistencia, mensaje posterior al éxito y ausencia de cambios comerciales. La aceptación manual de ese botón queda pendiente. Tampoco se certifica WCAG integral, uso con lectores de pantalla, carga productiva o efectividad comercial real. El servidor temporal de pruebas se detuvo.

## Archivos de esta etapa

Nuevos:

- `Modules/FollowUp/ETAPA_7.md`
- `Modules/FollowUp/app/Console/ReconcileAnalyticsCommand.php`
- `Modules/FollowUp/app/Livewire/Analytics.php`
- `Modules/FollowUp/app/Models/AnalyticsFact.php`
- `Modules/FollowUp/app/Services/FollowUpAnalyticsAggregator.php`
- `Modules/FollowUp/app/Services/FollowUpMetricsService.php`
- `Modules/FollowUp/app/Services/FollowUpInsightsService.php`
- `Modules/FollowUp/database/migrations/2026_11_04_160000_create_follow_up_analytics.php`
- `Modules/FollowUp/resources/views/livewire/analytics.blade.php`
- `Modules/FollowUp/resources/views/livewire/analytics-invalid.blade.php`
- `Modules/FollowUp/tests/Feature/FollowUpAnalyticsTest.php`
- `Modules/FollowUp/tests/Support/analytics-worker.php`
- `Modules/FollowUp/tests/Support/analytics-preview.php`

Adaptados:

- `Modules/FollowUp/app/Models/FollowUpAction.php`: metadatos de fecha para análisis estático.
- `Modules/FollowUp/app/Models/FollowUpEvent.php`: metadatos y relación con oportunidad.
- `Modules/FollowUp/app/Providers/FollowUpServiceProvider.php`: registro del comando.
- `Modules/FollowUp/resources/views/partials/navigation.blade.php`: acceso a Analítica.
- `app/View/Breadcrumbs.php`: breadcrumb nativo.
- `routes/web.php`: ruta protegida bajo el grupo administrativo existente.

Los archivos ajenos `capturas-publicador/` y `evaluacion_sophia_30_preguntas.md` se conservan y no forman parte del commit. El hash se entrega en el informe de cierre; el documento forma parte del mismo commit local.
