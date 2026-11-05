# Etapa 5 — Integración comercial local

## Resultado funcional y arquitectura

FollowUp conserva la propiedad de oportunidades, recomendaciones y resultados. Crm continúa siendo propietario de contactos, Leads, conversaciones y actividades; Social mantiene los mensajes. No se incorporan proveedores ni motores de IA. Los borradores y la ejecución de las etapas anteriores permanecen bajo sus controles independientes, deshabilitados por defecto.

`FollowUpCommercialCoordinator` mantiene una proyección comercial en la oportunidad y crea tareas únicamente ante una solicitud administrativa autorizada. Los eventos `Crm\Events\CommercialRecordChanged` se emiten desde los servicios oficiales, después del commit. FollowUp escucha esos eventos y los eventos sociales sin introducir una dependencia de Crm hacia FollowUp. No hay observadores globales nuevos.

El comando `followup:commercial-sync --institution=ID` o `--all-institutions` recupera eventos perdidos a partir de los registros vigentes. Su programación local cada cinco minutos no crea actividades ni comunica con proveedores. No se ha instalado ni ejecutado en producción.

## Componentes reutilizados

- `ConversationContactLink`: identidad telefónica exacta de WhatsApp.
- `Lead`, `Contact`, `Conversation`, `ProgramInterest`: referencias institucionales, sin copias del pipeline o catálogo.
- `ActivityService`: creación, disponibilidad, responsables e idempotencia de Agenda. Se añade el argumento opcional `notifyAssignment=true`; el comportamiento existente se conserva y FollowUp pasa explícitamente `false`.
- `CurrentInstitution`, políticas y permisos existentes: consultas y operaciones del tenant activo.
- `ActionInvalidator`, `FollowUpEventRecorder`, `AuditService`: cancelación de acciones, invalidación de borradores e historial.
- Resolución de políticas y límites acumulados de las etapas anteriores. Se corrigieron los horarios por defecto del modelo de configuración no persistido para igualarlos a los de la migración original.

## Relaciones y modelo

La migración aditiva `2026_11_05_010000_add_follow_up_commercial_context.php` amplía `follow_up_opportunities`. Añade referencias opcionales a responsable, conversación CRM, programa, actividad y último mensaje revisado por una persona; autor, fecha y evidencia cifrada de asociación; versión, huella de contexto, recomendación, resultado, cierre, pausa y fecha de sincronización. Las referencias tienen claves foráneas con `nullOnDelete`; las consultas institucionales y las validaciones impiden asociaciones ajenas mediante los contratos expuestos. No se crean tablas paralelas de contactos, Leads ni tareas.

La asociación automática usa teléfono normalizado verificable, nunca similitud de nombre ni identificadores de Instagram/Messenger. Solo se selecciona un Lead cuando hay un único candidato del contacto; dos o más dejan la relación pendiente. La detección original también deja de elegir arbitrariamente el Lead más reciente. No se crean Leads automáticamente.

La asociación manual exige administrador activo, contexto institucional, permiso de contacto/Lead, relación Lead-contacto y evidencia. Una conversación CRM adicional debe pertenecer al mismo contacto, institución y asesor y no ser de prueba. Quedan autor, fecha y auditoría. Los vínculos manuales sobreviven a la detección periódica. Cambiar el vínculo invalida acciones y borradores anteriores; no modifica los registros originales.

## Coordinación, responsables y Agenda

Se reconcilian las asignaciones del Lead, la conversación y la actividad pendiente. Un responsable debe estar activo, pertenecer a la institución y poder operar el CRM sin perfil de solo lectura. Asignaciones contradictorias requieren revisión. La IA no asigna usuarios.

Antes de crear una tarea se consultan actividades pendientes del contacto. La regla conservadora reutiliza una actividad pendiente existente y evita sumar otra obligación al mismo prospecto. La creación usa `ActivityService`, una clave institucional `followup:{oportunidad}:{ciclo}:human` y bloqueos de responsable, oportunidad y Lead. Se revalida dentro de la misma transacción que crea la actividad. Reintentar devuelve la actividad existente; versiones obsoletas no sobrescriben decisiones.

Llamadas y reuniones se representan como tareas internas de coordinación de treinta minutos, con medio `none`, sin invitados, recordatorios de envío ni notificación de asignación. No se confirma una cita inexistente. Fechas en el formulario mínimo se introducen explícitamente en UTC; la ficha de Agenda conserva su visualización horaria habitual.

Completada, cancelada, no realizada y reprogramada se sincronizan desde Agenda. Completar una actividad no matricula ni cierra el Lead. La oportunidad mantiene la pausa hasta revisión explícita. No se altera el comportamiento de notificaciones de las operaciones ordinarias de Agenda.

## Recomendaciones, resultados y cierre

Las recomendaciones deterministas distinguen seguimiento conversacional, tarea de inscripción, propuesta de llamada/reunión, objeción, revisión humana, espera y ausencia de acción. Sugieren mantener etapa, `contacted`, `qualified` o revisión de `discarded`, sin ejecutar cambios de pipeline. Una intención de inscripción sigue siendo una inferencia.

Solo el estado oficial `enrolled` produce `confirmed_conversion / converted`. `discarded`, baja del contacto o consentimiento negativo cierran el seguimiento correspondiente, cancelan acciones incompatibles e invalidan borradores. Los cierres son persistentes: ni un job antiguo, ni un nuevo consentimiento, ni una respuesta reabren automáticamente la oportunidad.

Los resultados observados de Agenda y respuesta se distinguen de las recomendaciones inferidas. Un resultado previo no se borra por una evaluación periódica. El historial de eventos y las fichas originales conservan la trazabilidad.

La intervención humana, actividades pendientes y solicitudes que requieren revisión pausan la automatización incompatible. La reanudación administrativa registra qué mensaje se revisó, de modo que una nueva señal puede volver a exigir intervención. La actividad pendiente y los cierres no se pueden eludir con ese control. Límites de intentos se acumulan entre ciclos; el intervalo mínimo produce espera con fecha de próxima evaluación.

## Interfaz y auditoría

Se amplía el componente existente de oportunidades, sin otro panel de Leads ni otra Agenda: estado comercial, responsable, recomendación, resultado, vínculos a las fichas autorizadas, actividades, asociación documentada, creación de tarea y pausa/reanudación. Las operaciones usan versiones y permisos del servidor. Los errores de disponibilidad se muestran en el formulario.

Los eventos comerciales identifican institución, oportunidad, actor, Lead/contacto, responsable, actividad, recomendación, resultado y versión. Las asociaciones y decisiones administrativas también usan `AuditService`. La evidencia manual se cifra y se oculta de la serialización. No se registran conversaciones completas, texto de proveedor ni secretos en los logs de integración.

## Concurrencia y recuperación

Las proyecciones usan bloqueo de oportunidad, lectura bloqueada del Lead y huella de contexto: repetir un evento sin cambios no escribe otra versión ni otro evento. Los listeners se ejecutan tras commit y sus fallos no revierten una operación comercial ya confirmada; se registra el identificador de oportunidad para reconciliar. No hay llamadas externas dentro de las transacciones.

La ejecución de mensajes mantiene las revalidaciones de Etapa 4. Una conversión cancela lo pendiente. Un mensaje que ya cruzó el punto de despacho no se declara ficticiamente cancelado ni se reenvía: conserva su reconciliación original.

## Archivos del alcance

Archivos nuevos:

- `Modules/Crm/app/Events/CommercialRecordChanged.php`
- `Modules/FollowUp/app/Console/SyncCommercialCommand.php`
- `Modules/FollowUp/app/Events/CommercialSyncRequested.php`
- `Modules/FollowUp/app/Listeners/SyncCommercialContext.php`
- `Modules/FollowUp/app/Services/FollowUpCommercialCoordinator.php`
- `Modules/FollowUp/database/migrations/2026_11_05_010000_add_follow_up_commercial_context.php`
- `Modules/FollowUp/tests/Feature/FollowUpCommercialTest.php`
- `Modules/FollowUp/tests/Support/commercial-worker.php`
- `Modules/FollowUp/ETAPA_5.md`

Archivos modificados:

- `Modules/Crm/app/Services/Agenda/ActivityService.php`
- `Modules/Crm/app/Services/ContactService.php`
- `Modules/Crm/app/Services/LeadService.php`
- `Modules/FollowUp/app/Livewire/Opportunities.php`
- `Modules/FollowUp/app/Models/FollowUpOpportunity.php`
- `Modules/FollowUp/app/Models/FollowUpSetting.php`
- `Modules/FollowUp/app/Providers/EventServiceProvider.php`
- `Modules/FollowUp/app/Providers/FollowUpServiceProvider.php`
- `Modules/FollowUp/app/Services/ConsentService.php`
- `Modules/FollowUp/app/Services/OpportunityDetector.php`
- `Modules/FollowUp/resources/views/livewire/opportunities.blade.php`
- `Modules/FollowUp/tests/Feature/FollowUpPolicyTest.php`
- `routes/console.php`

## Pruebas y resultados

Pruebas sobre MySQL `crm_mca_test`, con HTTP, correo, notificaciones y colas simulados donde corresponde. La suite comercial cubre relaciones exactas y ambiguas, cifrado, roles, institución, responsables, idempotencia, tareas, disponibilidad, estados/resultados, exclusiones, conversión con acción programada, límites, respuesta, pausa y reanudación, eventos duplicados/perdidos, commit/rollback y panel Livewire.

Las pruebas de concurrencia arrancan dos procesos PHP independientes contra MySQL. Comprueban una única tarea y una única proyección. Para visibilidad entre conexiones confirman fixtures y reconstruyen exclusivamente la base descartable `crm_mca_test`, con guardas de entorno y nombre de base. No deben ejecutarse en paralelo con otra suite que use esa misma base.

El fixture de políticas de Etapa 2 se actualiza para que el contacto coincida con el teléfono real de la conversación simulada, como exige la nueva comprobación de identidad. Sus aserciones de comportamiento se conservan.

Regresión amplia comprobada: **716 pruebas, 4.093 aserciones, sin fallos** (394,95 s). Incluye FollowUp Etapas 1–5, asesores y catálogo IA, toda la suite funcional de Social, Core/tenancy/colas, Leads, contactos, Agenda y remitentes, roles y acceso al panel. Proveedores externos simulados.

Comando ejecutado:

```powershell
php artisan test Modules\FollowUp\tests\Feature Modules\Ai\tests\Feature\AdvisorTurnServiceTest.php Modules\Ai\tests\Feature\AdvisorCatalogTest.php Modules\Social\tests\Feature Modules\Core\tests\Feature Modules\Crm\tests\Feature\LeadServiceTest.php Modules\Crm\tests\Feature\ContactServiceTest.php Modules\Crm\tests\Feature\AgendaTest.php Modules\Crm\tests\Feature\AgendaSenderTest.php Modules\Identity\tests\Feature\PanelAccessTest.php --compact
```

Análisis estático: **PHPStan/Larastan sin errores** en `Modules/FollowUp/app`, los tres servicios CRM modificados y `Modules/Crm/app/Events`. Pint `--test --dirty`: **20 archivos PHP aprobados**. Se utilizó la instalación local existente de Pint, sin instalar dependencias.

Después de la regresión amplia se reforzó la revalidación bajo los mismos bloqueos de creación, se cerró explícitamente el ciclo comercial vigente y se añadieron pruebas de conversión concurrente y referencias CRM. Repetición final sobre ese código: **149 pruebas, 611 aserciones, sin fallos** (128,84 s), ejecutando `php artisan test Modules\FollowUp\tests\Feature --compact`. Incluye 41 casos de la suite comercial y las regresiones de las etapas anteriores. Los dos procesos concurrentes verificaron evaluación, creación de tarea y conversión mientras se intenta crear la actividad.

`git diff --check` no encontró errores de espacios. El hash definitivo del commit local se entrega junto a este informe en la respuesta de cierre; el commit incluye estos 22 archivos del alcance y ningún archivo ajeno.

## Alcance y aceptación pendiente

La entrega es local. No se ha probado aceptación visual con usuarios comerciales, rendimiento con volumen productivo ni integración real con Meta, correo o calendarios externos. Las pruebas automatizadas no constituyen aprobación de producción.

La asociación no verificable queda pendiente. La elección conservadora de una actividad pendiente del contacto puede requerir que un administrador revise la ficha de Agenda antes de crear otra. Solo se permite una tarea FollowUp por ciclo: una tarea adicional distinta se gestiona en Agenda. Las actividades previas siguen disponibles en el historial.

Los cambios directos de datos que no utilizan los servicios instrumentados se recuperan mediante reconciliación periódica. No se ofrecen reaperturas automáticas de cierres definitivos. Las propuestas de reuniones requieren confirmación humana posterior fuera de esta etapa. No se desarrolla el dashboard de Etapa 6.

No se ejecutó push, publicación de ramas, PR, despliegue, SSH, migraciones productivas, cambios de credenciales ni comunicaciones reales. No se activaron envíos proactivos. Los archivos ajenos `capturas-publicador/` y `evaluacion_sophia_30_preguntas.md` se conservan fuera del commit.
