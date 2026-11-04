# Etapa 4 — Ejecución controlada (local)

## Auditoría previa

Se verificaron los commits locales `df81ac56907bd137a4771f4fbdef797837c92424`, `192f3a9c527cf0c15804007ec4615ef9dbd1d238` y `a9556124db355af01c6646ff7014033baae56d01`. No se presupone publicación ni despliegue.

El contrato `send` de Etapa 2 devolvía `execution_not_implemented`; las aprobaciones no identificaban el contenido. SocialOutboundService ya persistía mensajes en Inbox y delegaba en WhatsAppMessageSender/MetaMessageSender. DeliveryReconciler distinguía `pending` de `sending`, y SocialIngestService reconciliaba estados y ecos con protección frente a estados fuera de orden. La infraestructura existente usa TenantAwareJob, TenantAwareCommand, cola database y scheduler Laravel.

## Arquitectura y contrato

- `FollowUpExecutor::approve`: autoriza al administrador, valida versión y contexto, calcula el contenido definitivo y registra usuario, versión, hash, política y caducidad. El control Livewire incluye el hash mostrado; un cambio entre vista y aprobación se rechaza.
- `FollowUpExecutor::execute`: reclama una acción programada; reevalúa `send` en una segunda transacción, prepara un registro en Social Inbox y confirma la intención de despacho; delega en SocialOutboundService fuera de la transacción; proyecta el resultado.
- `ExecutionAuthorization`: vigencia, destinatario, conversación, asesor, institución, aprobación y límites acumulados por contacto/conversación.
- `ExecutionContent`: texto definitivo o plantilla vigente; verificación de idioma, categoría, componentes y sustituciones permitidas.
- `ExecutionSwitches`: activación explícita global, institucional, del asesor y del canal; cuotas de 24 horas y evidencia de capacidad del canal con vigencia de una hora.
- `ExecuteFollowUp` y `followup:dispatch`: ejecución institucional en cola dedicada, sin depender de n8n.

No se incorpora cliente HTTP, webhook ni historial paralelo. El gateway existente recibe una extensión de preparación/despacho que conserva al asesor como remitente y no provoca una transferencia humana artificial.

## Persistencia

La migración `2026_11_04_140000_add_follow_up_execution.php` amplía `follow_up_actions` con referencia única a SocialMessage, token único de intento, reserva, fecha de despacho, fecha de encolado, error normalizado, versión/hash/caducidad de aprobación y parámetros cifrados. Añade índices de consulta.

La nueva tabla `follow_up_execution_switches` almacena permisos operativos independientes por ámbito, límites y referencia de evidencia. Se justifica separadamente de las preferencias comerciales porque necesita un registro global persistente y controles por canal, además de bloqueo transaccional independiente. La ausencia de una fila equivale a deshabilitado. Las consultas de ámbito institucional comprueban expresamente institution_id; el ámbito global solo se administra desde contexto CLI autenticado como administrador. La UI solo permite detener la institución.

La migración se ejecuta exclusivamente en MySQL `crm_mca_test` durante las pruebas. No se modifica configuración operativa ni se rellenan interruptores para instituciones existentes.

La referencia a SocialMessage utiliza `nullOnDelete`, compatible con la retención existente. La purga del mensaje histórico no reactiva la acción ni elimina los eventos de resultado/correlación.

## Políticas y modos

`FOLLOW_UP_EXECUTION_ENABLED` nace en false. Además se requieren las cuatro filas de activación y la configuración de seguimiento institucional/asesor existente. Una condición desconocida bloquea. Los controles incluyen consentimiento exacto por sujeto/canal/finalidad, baja, Lead cerrado, horario actual, intervalo, intentos acumulados, conexión, asesor, identidad del destinatario, actividad posterior y contexto del borrador.

Manual y supervisado requieren aprobación explícita de contenido vigente. El botón de aprobación constituye la solicitud expresa de programar esa versión. En automático controlado se respeta también `requires_human_review`: los borradores actuales de Etapa 3 siempre lo requieren, por lo que no se elimina la revisión humana para activar el modo automático. No hay activación de envío en esta entrega.

Los límites por contacto/conversación contemplan otras oportunidades y ciclos. Los límites diarios de institución, asesor y canal cuentan todos los despachos, incluso fallidos o ambiguos. Una entrega desconocida bloquea otros intentos al mismo sujeto. Las reservas finales se serializan mediante los interruptores persistentes para evitar que dos acciones superen simultáneamente una cuota.

## Canales y fuentes oficiales

WhatsApp utiliza la fecha del último mensaje entrante persistido para la ventana de servicio. Fuera de 24 horas exige plantilla; nunca coloca el borrador IA como variable. La implementación conserva Cloud API para coexistencia. Las reglas de consentimiento y plantilla se contrastaron con la [política oficial de WhatsApp Business](https://business.whatsapp.com/policy), consultada el 4 de noviembre de 2026.

Las plantillas deben estar aprobadas, sincronizadas en la última hora, pertenecer al canal, tener idioma compatible y categoría MARKETING para seguimiento comercial o UTILITY para finalidad operacional. Se admiten BODY y FOOTER; las variables solo pueden enlazarse explícitamente con el nombre disponible del contacto. Se rechazan texto libre como variable, parámetros desconocidos, encabezados, botones y otros componentes no soportados. La vista mínima permite aprobar las plantillas sin variables; los enlaces de nombre están disponibles en el contrato interno y probados, sin editor adicional de plantillas.

No se inventan límites de Meta. Para el canal se exige referencia de evidencia operativa y límite local explícito con caducidad corta. No se consulta ni actualiza Meta durante este desarrollo; la renovación verificada de esa evidencia es una condición operativa futura, no una afirmación de capacidad real validada.

Instagram y Messenger conservan `provider_policy_unverified` y no pueden ejecutarse. Se intentó consultar la [documentación oficial de políticas Messenger](https://developers.facebook.com/docs/messenger-platform/policy/policy-overview/) y la [documentación oficial Instagram](https://developers.facebook.com/docs/messenger-platform/instagram/features/send-message/); el servidor devolvió HTTP 429. No se habilitan excepciones basadas en fuentes secundarias. Email permanece fuera del ejecutor.

## Estados, idempotencia y punto de despacho

El flujo normal es `scheduled → claimed → sending → sent → delivered → read`. Los bloqueos pasan a `suppressed`; la invalidación anterior al despacho puede cancelar; una reserva abandonada se suprime. Los rechazos definitivos son `failed` y la ambigüedad es `delivery_unknown`. No hay reenvío automático de esos estados.

La reserva bloqueada por fila tiene UUID. Un job duplicado no reclama una acción ya reclamada. La preparación del mensaje y su vínculo único se confirman en la misma transacción que el estado sending de FollowUp. El gateway usa una transición atómica pending → sending de SocialMessage; solo quien gana puede llamar al proveedor.

El punto lógico de despacho es el commit de `execution.dispatch_committed`. Hasta ese punto, la revalidación bloquea cambios de contexto, revocaciones y cancelaciones visibles. Después, el intento queda comprometido y no se ofrece cancelación: la llamada externa puede iniciarse inmediatamente, o el proceso puede caer antes. No es posible hacer atómico el commit MySQL con la aceptación de Meta. Una desactivación posterior detiene nuevos despachos pero no revoca el ya comprometido. Esta es una garantía de no reenvío ordinario, no una promesa absoluta de exactly-once del proveedor.

Si cae el proceso antes de llamar al gateway, SocialMessage sigue pending y DeliveryReconciler lo resuelve como no enviado. Si cae después de empezar la llamada, queda sending y pasa a delivery_unknown; nunca se reencola. Una aceptación sin identificador externo también queda desconocida. Un resultado HTTP tardío no degrada delivered/read.

## Reconciliación, seguridad y auditoría

DeliveryReconciler sigue siendo el reconciliador: se extiende para proyectar a FollowUp los estados existentes de SocialMessage y recuperar reservas. FollowUp no consulta otra API ni reenvía durante reconciliación. Los estados proyectados avanzan de forma monotónica y los eventos duplicados no producen cambios adicionales.

Las aprobaciones y borradores despachados se conservan como evidencia histórica; no pueden editarse ni descartarse posteriormente. Los jobs restauran la institución. La ejecución no modifica Leads, credenciales, Embedded Signup ni el comportamiento de transferencia existente.

Los eventos incluyen acción/oportunidad/institución, intento UUID, política, aprobador, hash, mensaje local, identificador externo y código normalizado. El remitente conserva ai_bot_id en Social Inbox. Se evita añadir contenido completo a logs. Se elimina el registro de mensajes arbitrarios de error de WhatsAppMessageSender para impedir que una excepción o respuesta de proveedor introduzca información sensible en logs.

Defectos previos corregidos de forma acotada: cálculo sin salida con días permitidos vacíos; caducidad de una acción leída antes de un despacho concurrente; actualización de reconciliación que podía sobrescribir una confirmación recibida entre lectura y escritura. Se usan bloqueos o actualizaciones condicionales para preservar estados posteriores.

## Colas y operación futura

Scheduler: `followup:dispatch --all-institutions` cada minuto, sin solapamiento. Solo encola con el feature flag activado. Reclamación de encolado persistente y recuperación tras cinco minutos si el job se perdió antes de empezar. El job tiene un intento y timeout de 60 segundos; el remitente WhatsApp conserva su timeout de 15 segundos.

Una futura operación expresamente autorizada necesitará un worker database para `followup-execution`, separado de `social-advisor`, con retry_after superior al timeout, además del reconciliador existente. No se instala ni inicia ese worker, no se cambia infraestructura del servidor y no se habilitan interruptores en datos operativos.

El comando de detención `followup:execution-stop {scope} {subject} --actor={admin_id} --institution={institution_id}` desactiva y audita el ámbito solicitado; global utiliza subject=0 y exige contexto CLI. Este comando no tiene opción de activación. La cola database existente tiene retry_after predeterminado de 90 segundos, superior al timeout de 60 del job.

## Pruebas y límites

Las pruebas usan MySQL de testing, Http fake con prohibición de solicitudes sin simular y colas simuladas. Cubren gateway, permisos, aprobación, cifrado existente, políticas, horarios vacíos, apagado por ámbito, plantillas, coexistencia, timeouts, token inválido, rate limit, duplicación de jobs/scheduler, carreras antes/después del despacho, recuperación y proyección de entregas.

Los escenarios concurrentes usan intercalados deterministas; no sustituyen una prueba de carga multiproceso ni la aceptación con workers reales. La aprobación de plantillas de Meta, calidad del número, capacidad comercial y entrega real requieren una validación posterior autorizada. No se afirma garantía absoluta de cero errores ni se inicia Etapa 5.

## Archivos de la etapa

Dentro de `Modules/FollowUp`:

- `app/Services/FollowUpExecutor.php`
- `app/Services/ExecutionAuthorization.php`
- `app/Services/ExecutionContent.php`
- `app/Services/ExecutionSwitches.php`
- `app/Services/FollowUpPolicyEngine.php`
- `app/Services/FollowUpDraftService.php`
- `app/Services/ActionMaintenanceService.php`
- `app/Services/Policies/Channels/WhatsAppPolicy.php`
- `app/Models/FollowUpAction.php`
- `app/Console/DispatchActionsCommand.php`
- `app/Console/StopExecutionCommand.php`
- `app/Jobs/ExecuteFollowUp.php`
- `app/Providers/FollowUpServiceProvider.php`
- `app/Livewire/Opportunities.php`
- `resources/views/livewire/opportunities.blade.php`
- `config/config.php`
- `database/migrations/2026_11_04_140000_add_follow_up_execution.php`
- `tests/Feature/FollowUpExecutionTest.php`
- `tests/Feature/FollowUpPolicyTest.php`
- `ETAPA_4.md`

Integraciones existentes modificadas:

- `Modules/Social/app/Services/SocialOutboundService.php`
- `Modules/Social/app/Services/DeliveryReconciler.php`
- `Modules/Social/app/Services/WhatsAppMessageSender.php`
- `routes/console.php`

## Resultados de validación local

- Regresión ampliada: **672 pruebas correctas, 3.825 aserciones**, 386,76 segundos. Incluye FollowUp, AdvisorTurnService, AdvisorCatalog, Social (WhatsApp, Instagram, Messenger, Inbox, coexistencia, automatización y colas), Core/tenancy, Leads, contactos, Agenda y permisos.
- Suite final FollowUp tras los últimos ajustes: **108 pruebas correctas, 330 aserciones**, 60,92 segundos. Incluye las etapas 1–4, aprobación por previsualización, apagado CLI/Livewire, privacidad de errores y compatibilidad de la referencia con retención.
- PHPStan/Larastan: **sin errores** en `Modules/FollowUp/app` y los servicios SocialOutboundService, DeliveryReconciler y WhatsAppMessageSender.
- Pint: **22 archivos PHP correctos**.
- `git diff --check`: correcto.

Los recuentos de suites se solapan; no deben sumarse. La interfaz se comprobó mediante Livewire, sin revisión visual en navegador. No se realizaron push, pull requests, despliegues, operaciones en producción ni comunicaciones reales. Los archivos ajenos `capturas-publicador/` y `evaluacion_sophia_30_preguntas.md` quedan fuera de la etapa.
