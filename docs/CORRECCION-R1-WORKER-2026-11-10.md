# MCA CRM — Corrección de los defectos de la auditoría adversarial (R1 y observaciones)

**Fecha:** 2026-11-10 · **Base:** `0fed41a` (versión optimizada con el defecto) · **Corrección:** commit `eab0f4b` · **Comparación de tres versiones:** original `e299739`, defectuosa `0fed41a`, corregida `eab0f4b`, medidas en el mismo banco local (PHP 8.3.30, MySQL 8.4.3, Windows), misma BD de desarrollo, mismo trabajo de prueba (un `RespondWithAdvisor` para un mensaje inexistente que termina en ~15 ms sin IA ni Meta). Sin push, sin despliegue, sin cambios en Hostinger.

Marcas: **[MEDIDO]** reproducido en esta intervención · **[CÓDIGO]** inspección con archivo · **[PROD: VALIDAR]** solo en Hostinger.

---

## 1. Qué se corrigió y cómo

| Hallazgo de la auditoría | Cambio | Archivos |
|---|---|---|
| **R1 (bloqueante)** — el worker solo arrancaba en el :00 del cron: un mensaje posterior esperaba hasta el minuto siguiente | **Tres modos** del worker. *Escucha continua*: mientras el interruptor general esté activo y exista al menos una cuenta activa con el asesor habilitado (`AdvisorDispatcher::listeningRequired()`), el cron lanza el worker cada minuto y éste escucha **55 s** (`--max-time` por defecto, antes 50) consultando la cola cada segundo: un mensaje se atiende en el segundo en que llega, igual que un retrasado que venza en la ventana. *Bajo demanda*: sin cuentas habilitadas (solo restos en cola) arranca únicamente si hay trabajos ejecutables en 60 s y escucha lo justo (lógica de `AdvisorQueuePlan`). *Nada*: sin cuentas habilitadas ni trabajos, no se crea ningún proceso. El planificador aplica la misma decisión antes de lanzar (`when()`), y el comando la vuelve a aplicar al arrancar | `Modules/Social/app/Support/AdvisorDispatcher.php` (`listeningRequired`), `Modules/Social/app/Console/AdvisorWorkerCommand.php`, `routes/console.php` |
| R2 — una excepción en un `when()` abortaba la pasada | `SchedulerGuards::safe()`: la comprobación se ejecuta en `try/catch`; ante error se reporta (`report()` + `Log::warning`) y se responde «sí», de modo que el comando arranca y falla por sí mismo si procede. Nunca se omite trabajo por culpa de la comprobación | `Modules/Core/app/Scheduling/SchedulerGuards.php`, `routes/console.php` |
| R3 — tope 50 s frente a ventana de 60 s | Tope 55 s. Con escucha continua el borde desaparece; en bajo demanda queda ≤ 5 s | `AdvisorWorkerCommand.php` |
| R4 — los ticks se definían antes que los procesos bajo demanda; TTL del tick de 5 min (4) < cadencia | Los procesos propios (worker, publicador, agenda, Meta, perfiles) se definen **antes** que los ticks: se evalúan y lanzan primero; TTL del tick de 5 min = 10 min (≥ cadencia). El tick de minuto conserva 2 min | `routes/console.php` |
| R5 — «En marcha» no implicaba que el worker pudiera arrancar | El bloque «Estado del servicio» muestra el **modo** del worker (escucha continua / bajo demanda) y avisa con «Trabajos listos sin atender» cuando hay trabajos listos y ningún worker reciente | `AdvisorServiceStatus.php`, `advisor-channels.blade.php`, `lang/en.json` |
| Recuperación del mutex ante fallos abruptos | TTL del mutex del worker 5 → **3 min** (vida máxima real: 55 s de escucha + un trabajo ≤ 60 s). Si muere el grupo de procesos sin liberar, el siguiente worker arranca como tarde 3 min después (antes 5). El resto de TTL sin cambios | `routes/console.php` |

Sin rediseños: no se añadieron servicios, colas ni dependencias; la cola `social-advisor`, los reintentos (`tries` 5, `backoff` 30/120), las reservas (`retry_after` 90) y las reglas de activación general y por canal no cambian.

---

## 2. Doble validación: latencia real por segundo del minuto [MEDIDO]

Cron simulado (`schedule:run` en cada :00), trabajo encolado en el segundo indicado del primer minuto, tiempo hasta que desaparece de `jobs`. Una cuenta activa con el asesor habilitado e interruptor general activo (condición idéntica para las tres versiones).

### 2.1 Trabajos disponibles de inmediato

| Segundo de llegada | Original `e299739` | Defectuosa `0fed41a` | **Corregida `eab0f4b`** |
|---|---|---|---|
| :00 | 1,0 s | 1,0 s | **1,0 s** |
| :05 | 0,5 s | **55,6 s** | **0,5 s** |
| :20 | 0,9 s | **40,9 s** | **0,9 s** |
| :40 | 1,0 s | **20,7 s** | **0,9 s** |
| :55 | 6,0 s (hueco :50–:60) | 6,0 s | **0,5 s** (hueco reducido a :55,5–:60,5) |

### 2.2 Trabajos retrasados 20 s (espera de canal / «escribiendo»)

| Llegada | Original | Defectuosa | **Corregida** |
|---|---|---|---|
| :05 (disponible :25) | 1,2 s tras disponible | 36 s tras disponible | **0,6 s** tras disponible |
| :40 (disponible :00 del minuto siguiente) | — | — | **0,6 s** tras disponible |

### 2.3 Trabajo que llega **mientras** el worker ya escucha (prueba automatizada)

`SocialAdvisorTest` «un mensaje que llega mientras el worker escucha se atiende al instante»: el entrante se encola durante el bucle de `queue:work` (evento `Looping`) y se procesa **< 3 s** después (medido ≈ 1 s de sondeo). Es exactamente el caso que no cubría ninguna prueba y por el que R1 pasó desapercibido.

**Conclusión:** la versión corregida iguala a la original en todos los segundos y la mejora en el :55; la espera artificial hasta el minuto siguiente ha desaparecido.

---

## 3. Doble validación: consumo, concurrencia y recuperación [MEDIDO]

### 3.1 Pasada del cron en reposo (cola vacía, sin usuarios)

| Métrica por pasada | Original | Defectuosa | **Corregida, asesor inactivo** | **Corregida, asesor activo** |
|---|---|---|---|---|
| Procesos PHP lanzados | 5 (+2 `finish`); 9 en múltiplo de 5 | 0 | **0** | **1 worker (+1 `finish`)** |
| Procesos simultáneos (máx.) | 3 | 0 | **0** | **1** (55 s) |
| Segundos-proceso vivos | 52–53 | 0 | **0** | ≈ 54 |
| Consultas MySQL | 308–348 | 20–32 | **20–32** | **309–321** (sondeo de la cola) |
| CPU de los `php` observados | 0,69–0,80 s | 0,23–0,27 s | ≈ 0,25 s | ≈ 0,45 s (worker 0,19 s) |
| Memoria del worker (WorkingSet) | 129–155 MB sumados | — | — | **55 MB** |
| Duración de `schedule:run` | 2,1–3,7 s | 0,3–0,6 s | **0,5–0,8 s** | **0,8 s** |

Derivados [PROD: VALIDAR]: arranques de PHP por hora en reposo: original ≈ 542; corregida con el asesor inactivo **60**; corregida con el asesor activo **≈ 180** (60 `schedule:run` + 60 worker + 60 `schedule:finish`), frente a los 542 originales. La condición de aceptación 4 («procesos innecesarios eliminados cuando el asesor esté completamente inactivo») se cumple; con el asesor activo el único proceso recurrente es el que garantiza la latencia.

### 3.2 Concurrencia, interrupción y colas

| Experimento | Resultado |
|---|---|
| 5 trabajos simultáneos, 2 workers en escucha a la vez | 2 + 3 procesados, 0 pendientes, 0 fallidos (reserva `FOR UPDATE SKIP LOCKED`) |
| Worker en escucha matado abruptamente a los 6 s con un trabajo retrasado 30 s | El trabajo sigue en cola sin reservar; el siguiente worker («simula el minuto siguiente») lo procesa en su momento; 0 fallidos |
| Dos `schedule:run` simultáneos | Una sola ejecución del tick y un solo worker (mutex); verificado en la validación anterior y sin cambios de mecanismo |
| Cola vacía, asesor inactivo | El worker no arranca (ni proceso ni latido); `AdvisorQueuePlanTest`, `SocialAdvisorTest` |
| Cola vacía, asesor activo | Escucha continua hasta el tope; el cron la renueva cada minuto |
| Resto en cola con la cuenta desactivada después de encolar | Bajo demanda: termina al vaciar la cola, sin responder (reglas del canal), 0 fallidos |

### 3.3 Frecuencias, leads, publicaciones y recordatorios

- `php artisan schedule:list`: mismas cadencias que la etapa 1 (minuto / 5 min / hora / día / mes); solo cambia el orden de definición y los TTL.
- Recepción de leads y mensajes (webhooks, intake, widget, indicadores, notificador): sin cambios de código desde `e299739` (diff vacío en esas rutas y servicios).
- Publicador y agenda: guards sin cambios; `SchedulerGuardsTest` y `AgendaTest` siguen en verde.
- Regresión amplia (Social, Crm, Core, FollowUp, Chat, indicadores, localización): ver §6.

---

## 4. Pruebas añadidas o actualizadas

| Prueba | Qué demuestra |
|---|---|
| `SocialAdvisorTest` «un mensaje que llega mientras el worker escucha se atiende al instante…» | R1 eliminado a nivel de proceso: < 3 s desde la llegada |
| `SocialAdvisorTest` «con cuentas activas el worker escucha de forma continua aunque la cola esté vacía; sin cuentas activas no arranca» | modo de escucha y modo nulo |
| `SocialAdvisorTest` «sin cuentas activas, un resto en cola se atiende bajo demanda» | modo bajo demanda conservado |
| `AdvisorQueuePlanTest` «el worker escucha de forma continua solo con el interruptor general y alguna cuenta activa con el asesor» | `listeningRequired()` por cuenta activa / asesor habilitado / interruptor general / cola persistente; el evento del planificador pasa o no el filtro |
| `AdvisorQueuePlanTest` (estado del servicio) | `worker.listening` y `worker.stalled` |
| `SchedulerResilienceTest` «una comprobación when() que lanza excepción no aborta la pasada» | R2 |
| `SchedulerResilienceTest` «los procesos bajo demanda se evalúan y lanzan antes que los ticks» + TTL | R4 y mutex del worker |

Resultados: `AdvisorQueuePlanTest` 10/10, `SchedulerResilienceTest` 9/9, `SchedulerTickTest` 6/6, `SocialAdvisorTest` 28/28. PHPStan (nivel 6) y Pint sin incidencias en los archivos modificados.

---

## 5. Riesgos que permanecen

1. **Coste con el asesor activo**: ≈ 300 consultas/min y un proceso 55 s de cada 60, como en la versión original. Es el precio de la latencia; solo se paga mientras haya cuentas con el asesor habilitado. Reducirlo exigiría otra arquitectura (p. ej. un «kick» desde el webhook o un sondeo más lento con pérdida de latencia) y no procede en esta intervención.
2. **Hueco de 5 s por minuto** (:55,5–:60,5): un mensaje que llegue ahí espera ≤ 5 s (antes ≤ 10 s). Ampliar el tope a 58–59 s haría que el siguiente `schedule:run` encontrase el mutex tomado y saltase un minuto entero; 55 s es el equilibrio.
3. **Procesos de fondo en Linux** [PROD: VALIDAR]: el worker lo lanza `runInBackground`; en este banco (Windows) el hijo sobrevive al padre cuando `schedule:run` se arranca sin redirección de consola, pero la prueba definitiva de supervivencia y de liberación del mutex por `schedule:finish` es en Hostinger.
4. **Mutex huérfano** si muere el grupo de procesos entero: hasta 3 min sin worker (antes 5).
5. **`InterfaceMessagesTest`** sigue en rojo por 4 claves ajenas del módulo Ai (commit `a19c260`); las claves nuevas de esta intervención sí están traducidas.

---

## 6. Regresión amplia sobre la versión corregida [MEDIDO]

Ejecutada desde cero sobre `eab0f4b`, en secuencia, sobre `crm_mca_test`:

| Suite | Pruebas | Resultado |
|---|---|---|
| `Modules/Social/tests` (webhooks, WhatsApp, asesor, automatización, publicador, Meta, bandeja…) | 499 | todas en verde (3 024 aserciones) |
| `Modules/Crm/tests` (agenda, reservas, leads, intake, notificador, indicadores, dashboard…) | 271 | todas en verde (1 613 aserciones) |
| `Modules/Core/tests` (aislamiento, tenancy, tick, resistencia, guards) | 32 | todas en verde (103 aserciones) |
| `Modules/FollowUp/tests` | 291 | todas en verde (1 217 aserciones) |
| `Modules/Chat/tests` (widget) | 22 | todas en verde |
| `tests/Feature/PanelBadgesTest.php` | 8 | todas en verde |
| **Total de la intervención** | **1 123** | **0 fallos** |
| `tests/Feature/Localization/InterfaceMessagesTest.php` | 4 | 3 en verde; 1 fallo **preexistente y ajeno** (4 claves del editor de instrucciones del módulo Ai, commit `a19c260`); las claves nuevas de esta intervención están traducidas |

## 7. Despliegue posterior (cuando se autorice)

Igual que la etapa 1: `git pull`, `composer dump-autoload -o`, `php artisan optimize:clear`; sin migraciones; cron sin cambios. Comprobar: `schedule:list`; con el asesor activo, `ps` debe mostrar el worker ≈ 55 s de cada minuto y el panel «Escucha continua cada minuto»; con el asesor inactivo, solo `schedule:run`.

## 8. Commits locales

- `eab0f4b` — corrección (código y pruebas).
- Commit siguiente — este informe y la actualización de `docs/DEPLOYMENT-SECURITY-CHECKLIST.md` (hash en el mensaje de entrega).
- Verificación visual del bloque «Estado del servicio» con la fila del worker (modo y última ejecución) a 1366 px: `scratchpad/bench/estado-servicio-correccion-1366.jpg`.
