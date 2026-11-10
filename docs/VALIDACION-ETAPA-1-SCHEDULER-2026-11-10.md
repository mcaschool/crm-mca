# MCA CRM — Validación funcional y de resistencia de la etapa 1 (planificador y workers)

**Fecha:** 2026-11-10 · **Código validado:** `main` @ `6d31777` (+ informe `4d0af2f`) · **Base de comparación:** `e299739` · **Sin optimizaciones nuevas.** Solo se añadieron pruebas; no hubo que corregir defectos derivados de la etapa.

Leyenda de la columna «Estado»: **VERIFICADO** = demostrado en local con prueba automatizada o experimento reproducible · **POR CONSTRUCCIÓN** = el código del camino no cambió (diff vacío frente a `e299739`) y sus pruebas existentes pasan · **PROD: VALIDAR** = solo comprobable en Hostinger.

---

## 1. Leads y Meta

| Comprobación | Evidencia | Estado |
|---|---|---|
| Recepción por webhooks de Meta (WhatsApp, Instagram, Messenger): rutas, controlador e ingesta | `git diff --stat e299739..HEAD -- routes Modules/*/routes Modules/Social/app/Http Modules/Social/app/Services/SocialIngestService.php …` → **vacío**. `route:list` muestra intactas `api/social/webhook/{provider}` (GET verify / POST handle con firma HMAC), `api/v1/leads/intake`, `api/v1/incompany/lead` y `api/v1/widget/*`. Suites `SocialWebhookTest`, `SocialWhatsAppTest`, `LeadIntakeEndpointTest`, `LeadIntakeChannelTest`, `IncompanyEndpointTest`, `WidgetApiTest` en la regresión (§5) | POR CONSTRUCCIÓN + pruebas |
| Latencia de recepción | El webhook no pasa por ningún componente modificado (solo consulta `AdvisorDispatcher::autoreplyEnabled()`, sin cambios). Ninguna de las clases nuevas se ejecuta en una petición HTTP de entrada | POR CONSTRUCCIÓN |
| Entrante almacenado aunque el asesor esté deshabilitado | `SocialAdvisorTest` «con el asesor automático deshabilitado en la instalación el entrante se guarda, no se encola nada y el worker no arranca»: 1 mensaje entrante guardado, `unread_count` = 1, cola vacía, 0 llamadas a IA, worker no arranca | VERIFICADO |
| Indicadores de nuevos leads y notificación en el panel | `PanelBadges`, `SidebarBadges`, `NewLeadNotifier`, `panel-badges-sync` sin cambios (diff vacío). Suites `NewLeadNotifierTest`, `SidebarBadgesTest`, `PanelBadgesTest` en la regresión (§5). Son sondeos del navegador: no dependen del planificador | POR CONSTRUCCIÓN + pruebas |
| Recuperación de leads de formularios (`social:meta-leads-poll`) | `SchedulerGuardsTest` «formularios de Meta»: sin Página recibiendo → no se lanza; Página con `receiving_enabled` + `verified` → el evento del planificador pasa el filtro (`filtersPass` = true); kill-switch → no. Misma cadencia (cada 5 min). Lógica del comando sin cambios (`MetaLeadFormService` diff vacío); `MetaLeadFormsTest` en la regresión | VERIFICADO (guard) + POR CONSTRUCCIÓN (comando) |

## 2. WhatsApp y asesor automático

| Comprobación | Evidencia (`Modules/Social/tests/Feature/SocialAdvisorTest.php`, 26 pruebas en verde) | Estado |
|---|---|---|
| Recepción, almacenamiento y encolado con la espera del canal; el webhook responde sin esperar a la IA | «el webhook confirma al instante: encola la respuesta con la espera del canal…» (existente) | VERIFICADO |
| Procesamiento y respuesta; un reintento de Meta del mismo mensaje no duplica | «…responde una sola vez / Un reintento de Meta del mismo mensaje no encola ni responde otra vez» (existente) | VERIFICADO |
| Reintentos ante fallo pasajero de IA, sin duplicar; agotados los intentos → «Error de automatización» sin enviar | dos pruebas existentes (5 intentos, `failed_jobs` = 0) | VERIFICADO |
| **Trabajos retrasados**: con espera de canal de 2 s, el worker bajo demanda escucha solo hasta el vencimiento + 5 s y responde en la misma ejecución | «un trabajo retrasado dentro de la ventana se atiende en la misma ejecución bajo demanda» (< 12 s en total) | VERIFICADO |
| Cola vacía: el worker no arranca ni deja latido; `--stop-when-empty` conserva lo clásico | `AdvisorQueuePlanTest` + `SocialAdvisorTest` «con la cola vacía el worker bajo demanda no arranca…» | VERIFICADO |
| **Asesor deshabilitado (instalación)**: se guarda el entrante, no se encola, el worker no arranca | prueba nueva citada en §1 | VERIFICADO |
| **Cuenta desactivada tras encolar**: el trabajo termina sin responder, sin fallos ni reintentos | «si la cuenta se desactiva después de encolar, el trabajo termina sin responder y sin fallos» (cola 0, IA 0, `failed_jobs` 0) | VERIFICADO |
| Interruptor general ≠ interruptor por canal | el general impide **encolar** (ingesta); el de canal gobierna al **ejecutar** (`SocialAdvisorResponder`); ninguno cambió. Activación desde el panel exige `ready()` (general + cola persistente + latido del planificador **o** del worker): `AdvisorQueuePlanTest` «el despacho está listo con el latido del planificador…», `SocialAdvisorCutoffTest`, `SocialAutomationTest` | VERIFICADO |
| Un trabajo reservado por un worker caído se recupera | `AdvisorQueuePlanTest` «un trabajo reservado por un worker caído cuenta a partir de retry_after»: entra en la ventana cuando faltan ≤ 60 s para `reserved_at + 90` | VERIFICADO (plan); la entrega efectiva la hace `DatabaseQueue` (framework) |
| Respuesta real con la IA del servidor y el retraso configurado | requiere el proveedor de IA y un canal real | PROD: VALIDAR |

## 3. SchedulerTick: fallos, excepciones y lentitud

| Comprobación | Evidencia (`Modules/Core/tests/Feature/SchedulerResilienceTest.php`, 7 pruebas; `SchedulerTickTest`, 6) | Estado |
|---|---|---|
| Un paso lanza excepción → se reporta y los pasos siguientes se ejecutan; la pasada siguiente es normal y deja latido | «una excepción en un paso no impide los siguientes…»: `['valtest:boom' => 'error', 'social:reconcile-deliveries' => 'ok', 'followup:maintain' => 'ok']`, luego `minute()` → ok + latido | VERIFICADO |
| Un paso **lento de FollowUp** (2 s simulados) retrasa pero no pierde los posteriores | «un paso lento de FollowUp retrasa pero no pierde los pasos posteriores» | VERIFICADO |
| Salida distinta de cero de un paso → `exit:N`, sin abortar | «una salida distinta de cero se registra sin abortar el resto» | VERIFICADO |
| Comando inexistente en un paso → `error`, el resto sigue | `SchedulerTickTest` «un paso que falla se reporta y no impide los siguientes» | VERIFICADO |
| El tick libera su mutex al terminar (también con interruptores activos) | «el tick libera su mutex al terminar aunque un paso falle dentro» | VERIFICADO |
| Interruptores respetados: `followup:dispatch`/`detect` solo con su flag; `maintain`/`commercial-sync` siempre (cadencia 5 min intacta) | `SchedulerTickTest` (minute/fiveMinutes) | VERIFICADO |
| Operaciones independientes no se pierden entre pasadas | Cada comando reclama su trabajo de forma atómica (reconciliación por `UPDATE` condicionado, recordatorios `pending→sending`, publicaciones por `run_id`, FollowUp por `FOR UPDATE`): lo no procesado en una pasada queda para la siguiente. Sin cambios en esos comandos (diff vacío) | POR CONSTRUCCIÓN + pruebas existentes de cada comando |

## 4. Concurrencia

| Comprobación | Evidencia | Estado |
|---|---|---|
| **Dos `schedule:run` simultáneos** (procesos reales, BD de desarrollo) | Experimento `scratchpad/bench/mutex.php` + dos `php artisan schedule:run -v` lanzados a la vez: proceso A → «No scheduled commands are ready to run»; proceso B → `Running [CRM: latido…] 19.61ms DONE`. **Una sola ejecución del tick**, un solo latido | VERIFICADO (experimento real) |
| **Pasada anterior aún en curso** (mutex del tick y del worker tomados a mano) → la nueva pasada no ejecuta nada ni actualiza el latido; al liberar, vuelve a correr | Mismo experimento (paso 2): `No scheduled commands…`, latido sin cambios; `SchedulerResilienceTest` «el tick no se solapa consigo mismo…» (con `Artisan::call('schedule:run')`) | VERIFICADO |
| **Workers coincidentes**: con trabajos en cola y el mutex del worker vigente (worker anterior en marcha) el planificador **no** lanza otro | «mientras un worker sigue en marcha el planificador no lanza otro aunque haya trabajos» (`withoutOverlapping(5)`) | VERIFICADO |
| Dos workers sobre el mismo trabajo (si el mutex fallara) | `DatabaseQueue::pop` reserva con `FOR UPDATE SKIP LOCKED`; además el turno por conversación (180 s) y la decisión persistente por mensaje (`alreadyHandled` → `duplicate`) impiden una segunda respuesta. Pruebas existentes de duplicados en `SocialAdvisorTest`/`SocialAutomationTest`. No reproducido con dos procesos reales (requiere IA real) | POR CONSTRUCCIÓN + pruebas de duplicado |
| **Trabajo que excede el minuto**: el worker escucha ≤ 55 s (tope `--max-time` 50 + margen) y `queue:work` termina el trabajo en curso antes de parar; el siguiente `schedule:run` ve el mutex (TTL 5 min) y espera; un trabajo nunca dura más que `retry_after` (90 s): IA 30 s + envío 15 s + «escribiendo» 5 s | «invariantes que impiden dos workers sobre el mismo trabajo: escucha ≤ 55 s y retry_after ≥ 90 s»; timeouts verificados en la auditoría (§6 del informe 1) | VERIFICADO (invariantes) · PROD: VALIDAR con un trabajo real |
| Sin pérdida de trabajos | Un job no procesado permanece en `jobs` (sin reservar) y entra en la ventana del minuto siguiente; un job reservado por un worker muerto vuelve a estar disponible a los 90 s y el plan lo contempla (`AdvisorQueuePlanTest`) | VERIFICADO (plan) |

## 5. Regresión de las funciones afectadas por el planificador

Ejecutadas en local, en secuencia, sobre `crm_mca_test` (MySQL), con el código de la etapa 1 más las pruebas de validación:

| Suite | Pruebas | Resultado |
|---|---|---|
| `Modules/Social/tests` (webhooks, WhatsApp, asesor, automatización, publicador, formularios de Meta, perfiles, bandeja, plantillas, coexistence…) | 496 | **todas en verde** (2 993 aserciones, 321 s) |
| `Modules/FollowUp/tests` (detección, política, borradores, ejecución, comercial, cadencia WhatsApp, analítica) | 291 | **todas en verde** (1 217 aserciones) |
| `Modules/Crm/tests` (agenda y recordatorios, reservas, leads, intake, notificador de leads, indicadores, dashboard, pipeline, retención) | 271 | **todas en verde** (1 613 aserciones) |
| `Modules/Chat/tests` (widget de Celia, emparejador) | 22 | **todas en verde** |
| `Modules/Core/tests` (aislamiento, tenancy, jobs, tick y resistencia del planificador) | 30 | **todas en verde** |
| `tests/Feature/PanelBadgesTest.php` (indicadores del menú) | 8 | **todas en verde** |
| `tests/Feature/Architecture` · `tests/Feature/Layout` | 3 · 40 | **todas en verde** |
| **Total** | **1 161** | **0 fallos** |

Frecuencias originales conservadas: reconciliación y recordatorios cada minuto; publicación comprobada cada minuto; `followup:maintain` y `followup:commercial-sync` cada 5 min; `followup:dispatch`/`detect` cada minuto/5 min cuando sus interruptores estén activos; formularios de Meta cada 5 min; perfiles cada hora; conexiones diario; retención mensual (`php artisan schedule:list`).

Pendiente ajeno a la etapa (ya documentado): `tests/Feature/Localization/InterfaceMessagesTest.php` falla por 4 claves sin traducción EN del editor de instrucciones del módulo Ai (commit `a19c260`).

## 6. Comprobaciones que solo son posibles en producción

1. Con el asesor activo en un canal de prueba: mensaje real → respuesta dentro del minuto con la espera configurada; `jobs` vuelve a 0; «Estado del servicio» muestra la última ejecución del worker.
2. Publicación programada real al minuto (Meta) y recordatorio de 15 min real (SMTP).
3. `ps -eo pid,etimes,cmd | grep artisan` muestreado 2 min: solo `schedule:run` en reposo; worker/publicador únicamente cuando haya trabajo.
4. `cache` → `crm.scheduler.seen_at` reciente tras el primer minuto; `cache_locks` sin mutex antiguos pasados 30 min.
5. Logs de acceso: cadencia de webhooks y tiempos de respuesta iguales a los previos al despliegue.

## 7. Defectos encontrados

Ninguno derivado de la etapa 1. No fue necesario corregir código de la aplicación; solo se añadieron pruebas (`SchedulerResilienceTest`, `SchedulerGuardsTest`, un caso en `AgendaTest` y dos en `SocialAdvisorTest`).
