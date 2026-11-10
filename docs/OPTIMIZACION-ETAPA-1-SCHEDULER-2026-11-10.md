# MCA CRM — Optimización, etapa 1: planificador y workers

**Fecha:** 2026-11-10 · **Base:** `main` @ `e299739` · **Referencia:** `docs/AUDITORIA-RENDIMIENTO-2026-11-10.md` y `docs/AUDITORIA-SCHEDULER-2026-11-10.md` · **Alcance:** solo el planificador (`routes/console.php`), el worker del asesor y el estado de servicio del panel. Sin migraciones. Sin push, despliegue ni cambios de cron en Hostinger.

Marcas: **[MEDIDO]** en local (PHP 8.3, MySQL 8.4, BD de desarrollo) · **[DERIVADO]** cálculo a partir de lo medido · **[PROD: VALIDAR]** pendiente de comprobar en Hostinger.

---

## 1. Cambios implementados

### 1.1 Planificador: un cron, un proceso por minuto, trabajo pesado bajo demanda (`routes/console.php`)

| Antes | Ahora |
|---|---|
| 5 `Schedule::command` por minuto (cada uno un proceso PHP) + 2 `schedule:finish` | **Un tick en proceso** (`Schedule::call(SchedulerTick::class.'@minute')`): latido del planificador, `social:reconcile-deliveries` y `followup:dispatch` (solo si `FOLLOW_UP_EXECUTION_ENABLED`) se ejecutan **dentro** de `schedule:run` vía `Artisan::call`, con `try/catch` por paso |
| 4 comandos más cada 5 min (4 procesos) | **Un tick en proceso** cada 5 min: `followup:detect` (solo si `FOLLOW_UP_DETECTION_ENABLED`), `followup:maintain`, `followup:commercial-sync`. **Misma cadencia que antes** |
| `social:advisor-worker` cada minuto, en fondo, siempre | Cada minuto **solo si** `AdvisorQueuePlan::inspect()->needsWorker()` (hay trabajos ejecutables en los próximos 60 s). Si no, no se lanza ningún proceso |
| `social:publish-scheduled` cada minuto, en fondo, siempre | Cada minuto **solo si** `SocialPendingWork::publishing()` (vencidas, interrumpidas > 15 min o videos de Instagram por reanudar) |
| `crm:agenda-reminders` cada minuto, siempre | Cada minuto **solo si** `AgendaPendingWork::reminders()` (recordatorios vencidos, envíos atascados > 10 min o correos de reserva por reintentar) |
| `social:meta-leads-poll` cada 5 min, siempre | Cada 5 min **solo si** `SocialPendingWork::metaLeadForms()` (alguna Página con recepción activa y sin kill-switch) |
| `social:refresh-contact-profiles` cada hora, siempre | Cada hora **solo si** `SocialPendingWork::contactProfiles()` (hay perfiles incompletos) |
| `social:meta-connections-check` diario · `crm:purge-retention` mensual | Sin cambios |

Las comprobaciones `when()` replican las condiciones de entrada de cada comando con 1–3 `EXISTS` globales; el comando sigue siendo quien reclama y procesa de forma atómica. Antisolape: mismos `withoutOverlapping` por evento (los ticks con 2 y 4 min) + reclamos a nivel de datos, sin cambios.

### 1.2 Worker del asesor bajo demanda (`Modules/Social/app/Console/AdvisorWorkerCommand.php`, `Modules/Social/app/Support/AdvisorQueuePlan.php`)

- `AdvisorQueuePlan::inspect()`: **una consulta agregada** sobre `jobs` (cola `social-advisor`): listos, retrasados, reservados, próximo ejecutable y último ejecutable dentro de la ventana de 60 s. Un trabajo reservado por un worker caído cuenta a partir de `reserved_at + retry_after` (90 s).
- El comando decide: sin ejecutables en la ventana → **no arranca `queue:work`** (1 consulta y sale); todos ya disponibles → `--stop-when-empty` (termina al vaciar la cola); alguno retrasado dentro de la ventana (espera «está escribiendo…» o la del canal, 0–8 s) → escucha **hasta que venza + 5 s**, con tope `--max-time` (50). Nunca más los 50 s fijos.
- `--stop-when-empty` explícito conserva el comportamiento clásico (lo usan las pruebas existentes).
- La cola `social-advisor` sigue independiente; `RespondWithAdvisor` (`tries=5`, `backoff 30/120`), la reserva de cola y la decisión persistente por mensaje no cambian. Interruptor general y por canal: **no se tocan** (el job ya encolado se resuelve con las reglas del canal, como antes).

### 1.3 Latido del planificador y activación del asesor (`Modules/Core/app/Scheduling/SchedulerHeartbeat.php`, `AdvisorDispatcher`)

- Nueva clave de caché `crm.scheduler.seen_at`, escrita por el tick cada minuto.
- `AdvisorDispatcher::ready()` = interruptor general **y** cola persistente **y** `dispatchAlive()` (latido del planificador < 3 min **o** latido del worker < 3 min). El panel sigue pudiendo activar el asesor aunque el worker no haya corrido, porque lo que garantiza el despacho es el planificador. Textos del panel sin cambios.

### 1.4 Estado de los servicios en el panel (`AdvisorServiceStatus`, `advisor-channels.blade.php`, `Channels.php`)

Bloque «Estado del servicio» en la ficha del asesor → «Canales de atención automática», con cuatro planos separados: **Planificador del servidor** (última pasada, En marcha/Detenido) · **Worker del asesor** (última ejecución real; «En espera» cuando no hay trabajos) · **Asesor automático de la instalación** (Habilitado/Deshabilitado) · **Trabajos en la cola del asesor** (listos · retrasados · en ejecución · fallidos). En «Canales sociales» la línea «Procesamiento automático del servidor: funcionando (última comprobación …)» usa ahora el latido del planificador. Verificado con captura real a 1366 px (`scratchpad/bench/estado-servicio-1366.jpg`), estética del kit sin franjas laterales.

### 1.5 Documentación
`docs/DEPLOYMENT-SECURITY-CHECKLIST.md`: sección nueva «Planificador: un cron, un proceso por minuto…» con comprobaciones de servidor; corregida la afirmación «no hay workers» y la del Publicador.

### Archivos
Nuevos: `Modules/Core/app/Scheduling/SchedulerHeartbeat.php`, `Modules/Core/app/Scheduling/SchedulerTick.php`, `Modules/Social/app/Support/AdvisorQueuePlan.php`, `Modules/Social/app/Support/SocialPendingWork.php`, `Modules/Social/app/Support/AdvisorServiceStatus.php`, `Modules/Crm/app/Support/AgendaPendingWork.php`, `Modules/Core/tests/Feature/SchedulerTickTest.php`, `Modules/Social/tests/Feature/AdvisorQueuePlanTest.php`, este informe.
Modificados: `routes/console.php`, `Modules/Social/app/Console/AdvisorWorkerCommand.php`, `Modules/Social/app/Support/AdvisorDispatcher.php`, `Modules/Social/app/Livewire/AdvisorChannels.php`, `Modules/Social/app/Livewire/Channels.php`, `Modules/Social/resources/views/advisor-channels.blade.php`, `Modules/Social/tests/Feature/SocialAdvisorTest.php`, `lang/en.json`, `docs/DEPLOYMENT-SECURITY-CHECKLIST.md`.
No se tocó: webhooks, ingesta, LeadIntake, widget, notificador de leads, indicadores, Bandeja, comandos de FollowUp/Agenda/Publicador (solo cambia quién y cuándo los lanza).

---

## 2. Rendimiento antes / después

Método [MEDIDO]: una pasada real de `php artisan schedule:run` (minuto normal, sin usuarios, cola vacía, interruptores de FollowUp apagados) lanzada desde PowerShell; `Questions` de MySQL antes y 58 s después (incluye subprocesos), muestreo de procesos `php` cada segundo, salida `-v` del planificador. Script: `scratchpad/bench/schedrun.ps1`. Ticks y guards contados con `DB::listen` (`scratchpad/bench/ticks.php`).

| Métrica (reposo, minuto normal) | Antes (`e299739`) [MEDIDO] | Después [MEDIDO] |
|---|---|---|
| Procesos PHP lanzados por `schedule:run` | 5 comandos (+2 `schedule:finish` en fondo) | **0** (el tick corre en el propio `schedule:run`) |
| Procesos `php` adicionales simultáneos (máx / media durante 58 s) | **3 / 1,07** (el worker vive ~50 s) | **0 / 0** |
| Consultas MySQL por pasada (todas las fuentes) | **252** | **20** |
| Tiempo de ejecución del trabajo del planificador | ≈ 3 s de lanzamientos + 50 s de worker | tick: **20 ms** (`Running [CRM: latido…] 20.24ms DONE`) |
| Consultas del tick de cada minuto | — | 3 (`cache`, `social_messages`, `follow_up_actions`) |
| Consultas del tick de 5 min | — | 7 (`institutions` ×2, `follow_up_actions` ×4, `follow_up_opportunities`) |
| Consultas de las comprobaciones `when()` (minuto normal) | — | 7 (`jobs` 1, `social_posts` 2, `social_post_targets` 1, `crm_activity_reminders` 2, `crm_bookings` 1); +1 cada 5 min (`meta_lead_pages`), +1 cada hora (`social_conversations`) |

| Derivados [DERIVADO de lo medido; PROD: VALIDAR] | Antes | Después |
|---|---|---|
| Arranques de PHP CLI por hora en reposo | ≈ 542 (auditoría) | **60** (solo `schedule:run`) + los bajo demanda cuando haya trabajo |
| Consultas MySQL por hora atribuibles al cron en reposo | ≈ 15 000 | ≈ 1 200 |
| Worker del asesor vivo en reposo | ≈ 83 % del tiempo | 0 % |

**Con trabajo** [MEDIDO en pruebas]: un entrante de WhatsApp con espera de canal de 2 s encola el job retrasado; `social:advisor-worker` (sin `--stop-when-empty`) lo atiende **en la misma ejecución**, en menos de 12 s en total (escucha ≈ retraso + 5 s), y deja el latido del worker; con la cola vacía no arranca y no deja latido. La latencia del asesor se mantiene (≤ 60 s + retraso configurado), igual que la de publicaciones (comprobación por minuto) y recordatorios (comprobación por minuto).

**Condición de leads en tiempo real**: ningún mecanismo de recepción/registro/notificación de leads pasa por el planificador (webhooks e ingesta síncronos, `LeadIntake`, notificador de leads y los indicadores del panel son peticiones del navegador). El único proceso programado relacionado con leads, `social:meta-leads-poll`, conserva su cadencia de 5 min cuando hay Páginas con recepción activa (la comprobación `when()` es exactamente la condición que el comando ya exigía para hacer algo). Sin cambios de latencia.

---

## 3. Pruebas ejecutadas

| Suite | Resultado |
|---|---|
| `Modules/Core/tests/Feature/SchedulerTickTest.php` (nueva) | 6 pasan: latido caduca a 3 min; tick de minuto deja latido, reconcilia y respeta `FOLLOW_UP_EXECUTION_ENABLED`; tick de 5 min respeta `FOLLOW_UP_DETECTION_ENABLED`; un paso que falla no impide los demás; la definición tiene los ticks y ningún proceso propio para FollowUp/reconcile; el worker solo se lanza con trabajos ejecutables en la ventana (retrasado 10 min → no; retrasado 20 s → sí; cola `sync` → no) |
| `Modules/Social/tests/Feature/AdvisorQueuePlanTest.php` (nueva) | 9 pasan: cola vacía; listos → stop-when-empty; retrasado 20 s → escucha 25 s; retrasado 600 s → no arranca; reservado caído → cuenta desde `retry_after`; el comando no arranca con cola vacía y `--stop-when-empty` conserva lo clásico; `ready()` con latido del planificador sin worker; `AdvisorServiceStatus` separa los cuatro planos; el panel muestra el estado |
| `Modules/Social/tests/Feature/SocialAdvisorTest.php` (+2 nuevas) | 24 pasan (cola vacía no arranca; retrasado en ventana atendido en la misma ejecución < 12 s) |
| `SocialAutomationTest` · `SocialAdvisorCutoffTest` · `SocialAdvisorCatalogTest` | 48 · 16 · 27 pasan (activación del asesor, latidos, worker) |
| `Modules/Core/tests/Feature/InstitutionIsolationTest.php` · `TenantAwareJobTest.php` | regresión de aislamiento: 6 y 2 pasan |
| `tests/Feature/Localization/InterfaceMessagesTest.php` | 3 de 4 pasan. **El fallo es preexistente y ajeno**: 4 claves sin traducción EN en `Modules/Ai/resources/views/livewire/advisor/partials/prompt-field.blade.php` (commit `a19c260`, 2026-11-06). Las claves nuevas de esta etapa sí están en `lang/en.json` |
| PHPStan (nivel 6, `--xdebug`) sobre los archivos cambiados | 0 errores |
| Pint sobre los archivos cambiados | OK |
| `php artisan schedule:list` · `schedule:run -v` real | Dos ticks «CRM: …» + comandos condicionados; latido `crm.scheduler.seen_at` escrito en la caché |

---

## 4. Riesgos pendientes

1. **Semántica del latido**: «En marcha» significa que el planificador pasa cada minuto; si el cron funciona pero `queue:work` no pudiera arrancar (p. ej. `proc_open` deshabilitado para `runInBackground`), el panel seguiría permitiendo activar el asesor. Mitigación: el bloque de estado muestra «Última ejecución» del worker y los trabajos listos/retrasados; si hay listos y el worker no se ejecuta, se ve. [PROD: VALIDAR con un trabajo real]
2. **Trabajo reservado por un worker muerto**: se reintenta como pronto a los `retry_after` (90 s) y como tarde 60 s después; igual que antes salvo que antes el worker siempre estaba escuchando. Aceptable para la latencia del asesor.
3. **Duración del tick de 5 min**: `followup:maintain` y `followup:commercial-sync` conservan su cadencia y ahora corren en el proceso del planificador; con cientos de oportunidades/acciones activas el tick podría durar segundos. [PROD: VALIDAR conteos; si crece, relajar cadencia en una etapa posterior]
4. **Fallo de BD durante `schedule:run`**: las comprobaciones `when()` consultan la BD; si falla, ese minuto no se lanza nada (antes, los comandos fallarían igual). Sin pérdida: todo se reclama en la pasada siguiente.
5. **Mutex antiguos** en `cache_locks` de los comandos retirados caducan solos (≤ 30 min); no bloquean nada nuevo (las claves de los ticks son distintas).
6. **Medición local en Windows**: `runInBackground` se comporta distinto que en Linux; las cifras de procesos simultáneos de Hostinger deben medirse allí (§5).
7. **Prueba de traducciones** en rojo por un pendiente ajeno (§3): corregir en el módulo Ai cuando se autorice.

---

## 5. Requisitos para el despliegue posterior (cuando el usuario lo autorice)

1. `git pull` · `composer dump-autoload -o` (clases nuevas) · `php artisan optimize:clear` (config/rutas/consola/vistas). **Sin migraciones.** El cron de Hostinger **no cambia** (`* * * * * php artisan schedule:run`).
2. Comprobar al minuto: `php artisan schedule:list` (dos ticks «CRM: …»); `SELECT \`key\`, \`value\` FROM cache WHERE \`key\` LIKE '%scheduler.seen_at%'` (latido reciente); panel → ficha del asesor → «Estado del servicio» → «Planificador del servidor: En marcha».
3. Medir en el servidor (solo lectura), antes y después: `ps -eo pid,etimes,cmd | grep artisan` muestreado 2 min (esperado: solo `schedule:run` ≈ 1 s en reposo); `SHOW GLOBAL STATUS LIKE 'Questions'` a 60 s sin usuarios; `SELECT queue, COUNT(*) FROM jobs GROUP BY queue`.
4. Prueba funcional con el asesor activo en un canal de prueba: mensaje entrante → respuesta dentro del minuto; publicación programada al minuto; recordatorio de 15 min.
5. Vuelta atrás: `git revert` del commit + `php artisan optimize:clear`. No hay estado nuevo en BD (solo una clave de caché).

---

## 6. Commit local

- Código, pruebas y documentación de despliegue: **`6d31777`** («Planificador: un proceso por minuto y workers bajo demanda (etapa 1 de rendimiento)»).
- Este informe se añade en el commit siguiente. Los informes de auditoría (`AUDITORIA-RENDIMIENTO-2026-11-10.md`, `AUDITORIA-SCHEDULER-2026-11-10.md`) siguen sin versionar, a decisión del usuario.
- Sin push, sin despliegue, sin cambios de cron en Hostinger.
