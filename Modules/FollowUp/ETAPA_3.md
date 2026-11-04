# Generación de borradores

La generación parte de una acción existente y comprueba la política `prepare` y las exclusiones explícitas de consentimiento. Los estados de autorización comercial pertenecen al motor de políticas. Todos los resultados requieren revisión humana.

## Componentes

- `FollowUpDraftService` reserva, genera, confirma, edita, descarta e invalida.
- `FollowUpDraftContext` limita el historial a 20 mensajes de 1.000 caracteres y recupera conocimiento asignado mediante `KnowledgeRetriever`, `AdvisorCatalog` y `ProgramAssignmentService`.
- `FollowUpDraftValidator` valida JSON, referencias, idioma y contenido material.
- `GenerateFollowUpDraft` extiende `TenantAwareJob` y usa la conexión `database`, cola `followup-drafts`. El worker debe atender esa cola. La interfaz solo encola bajo solicitud explícita.
- Se reutilizan `AiProcessResolver` con el proceso configurado `conversation`, `AdvisorPromptBuilder`, `AiChatClient` y su decorador de telemetría. El consumo se etiqueta `followup_draft`.

## Persistencia y concurrencia

La migración aditiva amplía `follow_up_actions`. `draft_payload` usa `encrypted:array`. El historial conversacional se recupera en memoria. Solo se conserva la propuesta estructurada y un hash del contexto, además de eventos sin contenido sensible.

La reserva tiene UUID y versión. El proveedor se invoca fuera de las transacciones del servicio. Una entrega repetida no reclama una reserva en ejecución. La confirmación revalida estado, contexto, caducidad y reserva. Las ediciones exigen versión actual. Las reservas de más de cinco minutos se invalidan y admiten reintento explícito. Cada acción tiene un límite configurable de tres solicitudes por hora. Se respetan los límites de tokens y timeout configurados si son inferiores a los límites de borradores.

La respuesta del prospecto, intervención humana y cambios de consentimiento/configuración aprovechan la invalidación existente. Los demás cambios se detectan mediante fingerprint antes de confirmar, editar o mostrar y mediante `followup:maintain`. El fingerprint abarca oportunidad, conversación, canal, asesor, Lead/contacto, mensajes recientes, fuentes recuperadas, catálogo asignado, configuración y consentimiento aplicable.

## Contrato

Se exige `recommended_action`, `commercial_objective`, `draft_text`, `language`, `reason_codes`, `context_summary`, `source_references`, `warnings` y `requires_human_review`. Las recomendaciones son `draft_message`, `human_review` y `no_contact`. Esta última conserva texto vacío. Los campos adicionales se descartan por validación y nunca se interpretan como comandos.

## Límites de validación

El idioma se estima con el detector existente es/en. Las afirmaciones materiales detectadas requieren coincidencia textual con una fuente activa, asignada y citada. Las paráfrasis no verificables se bloquean dejando una recomendación de revisión. Esta comprobación es conservadora y puede producir falsos positivos. No constituye una verificación semántica exhaustiva ni una evaluación comercial con un proveedor real.

La telemetría común registra proveedor, modelo, tokens y latencia. Los eventos de seguimiento vinculan la acción y oportunidad. El coste depende de los mecanismos y tarifas existentes, sin introducir una estimación alternativa.

## Validación local

Las pruebas usan MySQL `crm_mca_test`, colas simuladas y un doble de `AiChatClient`. Cubren cifrado, contexto acotado, fuentes asignadas, esquema, recomendaciones, permisos, versiones, reservas, entrega repetida, cambios durante la llamada, edición, caducidad, límites y errores. Los escenarios de concurrencia se ejercitan con intercalados deterministas. La aceptación comercial con modelos reales y las pruebas de carga de varios procesos requieren una validación posterior.

Las migraciones de esta etapa se ejecutan exclusivamente en testing. Publicación y despliegue requieren autorización independiente.

## Archivos de la etapa

Todos están dentro de `Modules/FollowUp`:

- `app/Services/FollowUpDraftService.php`: ciclo de generación, edición e invalidación.
- `app/Services/FollowUpDraftContext.php`: contexto y huella verificable.
- `app/Services/FollowUpDraftValidator.php`: contrato y verificaciones de contenido.
- `app/Jobs/GenerateFollowUpDraft.php`: ejecución asíncrona institucional.
- `app/Models/FollowUpAction.php`: cifrado y tipos del borrador.
- `app/Services/ActionInvalidator.php`: integración con cancelaciones existentes.
- `app/Services/ActionMaintenanceService.php`: revisión periódica de vigencia.
- `app/Livewire/Opportunities.php`: operaciones administrativas.
- `resources/views/livewire/opportunities.blade.php`: consulta, edición y auditoría.
- `config/config.php`: límite de solicitudes por hora.
- `database/migrations/2026_11_04_120000_add_drafts_to_follow_up_actions.php`: columnas aditivas, índice y referencia al editor.
- `tests/Feature/FollowUpDraftTest.php`: pruebas específicas.
- `ETAPA_3.md`: informe técnico y límites de operación.

La interfaz se valida con pruebas funcionales Livewire; no se realizó una revisión visual en navegador. No se inició un worker contra una base operativa. Para una futura aceptación autorizada será necesario disponer de la migración y de un worker que atienda `followup-drafts`.

## Resultados del 4 de noviembre de 2026

- Regresión ampliada: 596 pruebas correctas, 3.370 aserciones. Incluye FollowUp, AdvisorTurnService, AdvisorCatalog, Social, Core, LeadService, ContactService y PanelAccess.
- FollowUp tras ampliar los casos de concurrencia y Livewire: 54 pruebas correctas, 193 aserciones.
- PHPStan/Larastan sobre `Modules/FollowUp/app`: sin errores.
- Pint sobre los 11 archivos PHP modificados: correcto.
- `git diff --check`: correcto.

Los recuentos de suites se solapan y no deben sumarse. Los proveedores y las comunicaciones se simulan. La aceptación con IA real, la carga multiproceso y el funcionamiento comercial real no se afirman como validados.
