# TeamCalls ↔ TeamChat · Guía técnica de integración (fase 4I)

Esta guía es para Codex (TeamChat y el widget universal). Explica cómo representar y lanzar llamadas sin tocar
TeamCalls. Distingue lo que ya está implementado en TeamCalls (✅) de lo que tiene que integrar TeamChat (🔧). Las
fases anteriores relacionadas son la 4E (lanzador y botones) y las 4F–4H (sala, controles y preproducción).

**Reglas generales**
- **Autoridad única:** CollaborationCore (sesión, MFA, `collaboration.use`, dispositivo, institución).
- **Sin motor nuevo:** TeamChat no implementa motor de llamadas ni notificaciones paralelas. Consume:
  - los eventos `call.*` de CollaborationRealtime;
  - la API de llamadas;
  - el lanzador `window.MCACalls`.

## 1. Eventos `call.*` (✅ TeamCalls los emite)

**Entrega:**
- Por CollaborationRealtime: un aviso sin contenido por Reverb al canal del dispositivo, y después
  `GET /api/v1/collaboration/sync?cursor=…`.
- La política `CallEventPolicy` autoriza cada lectura:
  - misma institución;
  - quien lee participa (o participó) en la llamada sin haber sido expulsado;
  - si la llamada nació en una conversación, sigue siendo miembro de ella.

**Sobre el evento:**
```json
{
  "id": "01J…", "type": "call.ringing", "v": 1,
  "occurred_at": "2026-10-16T22:50:58.123456Z",
  "institution_id": "1", "actor_id": "1",
  "aggregate": { "type": "call", "id": "01m5…", "version": 3 },
  "correlation_id": "…", "causation_id": null,
  "data": { "call_id": "01m5…", "user_id": 2, "media": "video" }
}
```

**Esquema de `data`** (cerrado y congelado por `CallEventsContractTest`). Todos llevan además `call_id`; los
identificadores de llamada son ULID **en minúsculas**:

| Tipo | Campos | Significado para la interfaz |
|---|---|---|
| `call.started` | `media` (`audio`/`video`), `scope` | Se creó una llamada |
| `call.ringing` | `user_id`, `media` | Le suena a `user_id` (invitación inicial o posterior) → aviso de llamada entrante si `user_id` soy yo |
| `call.accepted` | `user_id` | `user_id` contestó |
| `call.answered_elsewhere` | `user_id` | Efímero: contestó en otro dispositivo o pestaña → ocultar el aviso en este |
| `call.declined` | `user_id` | `user_id` rechazó |
| `call.missed` | `user_id` | No contestó a tiempo → «llamada perdida» si `user_id` soy yo |
| `call.connected` | — | Hay audio y video entre al menos dos personas |
| `call.ended` | `status` (`ended`/`declined`/`cancelled`/`missed`/`failed`), `reason` | Terminó → ocultar avisos y actualizar el historial |
| `call.participant.connecting` / `joined` / `left` | `user_id` | Cambios de participantes |
| `call.participant.revoked` | `user_id`, `reason` | Retirado por quien la inició o por perder el acceso |
| `call.media.*` | (internos de medios) | No hace falta mostrarlos |

Motivos de `call.ended.reason` que puede ver la interfaz:
- `ended_by_host`: la finalizó quien la inició;
- `everyone_left`: salieron todos;
- `cancelled_by_caller`: quien llamaba colgó antes de que contestaran;
- `no_answer`: nadie contestó (`status` = `missed`);
- `declined`: rechazada;
- `connect_timeout`: no llegó a conectar (`status` = `failed`);
- `media_lost`: se perdió el servidor de medios.

**Lo que nunca va en los eventos:** salas, credenciales, identidades de medios, dispositivos ni datos de personas
ajenas a la llamada.

**🔧 Suscripción** (mismo protocolo que TeamChat ya usa con CollaborationRealtime):
1. `GET /api/v1/collaboration/realtime/channel` devuelve el canal privado del dispositivo; se autoriza con
   `POST …/realtime/auth`.
2. Al recibir `collaboration.sync.required`, leer `GET …/sync?limit=100&cursor=<cursor>` y filtrar `type` que empiece
   por `call.`.
3. Al arrancar, `GET …/sync?bootstrap=1&limit=1` da el corte inicial. Un 410 indica cursor vencido: pedir un corte
   nuevo y releer el estado.
4. **Cancelar la suscripción:** cerrar el WebSocket y dejar de leer. Un 401 o 403 detiene la suscripción, porque la
   sesión o el dispositivo ya no valen.

Implementación de referencia: `Modules/TeamCalls/resources/assets/js/teamcalls-realtime.js`.

## 2. Estado actual de una llamada (✅)
Los eventos solo **avisan**; la fuente de verdad es la API:

| Necesidad | Endpoint |
|---|---|
| Llamada viva de una conversación (botón «Unirse») | `GET /api/v1/collaboration/calls/active?type=direct\|group\|channel&id=<conversación>` |
| Detalle | `GET /api/v1/collaboration/calls/{id}` (`participants[].state`, `viewer_state`) |
| Historial y llamadas perdidas | `GET /api/v1/collaboration/calls` y `…/missed` |

Estados de participante: `ringing`, `accepted`, `joined`, `left`, `declined`, `missed`, `cancelled`.

Estados de llamada: `initiating`, `ringing`, `connecting`, `active`, `ended`, `declined`, `cancelled`, `missed`,
`failed`.

## 3. Botones de voz y video (🔧 TeamChat; mecanismo ✅ desde la 4E)
- **Lanzador:** `@includeIf('teamcalls::partials.launcher')`. Imprime algo solo donde las llamadas están habilitadas,
  nunca en producción.
- **Ocultar los botones** donde no haya llamadas:
  ```css
  html:not([data-mca-calls="on"]) [data-mca-call] { display: none !important; }
  ```
- **Botones declarativos:**
  - conversación privada: `data-mca-call="audio|video" data-mca-call-conversation="direct:<id>"`;
  - grupo o canal: añadir `data-mca-call-invitees="3,4"`, o completar la selección en el evento `mca-call:before`;
  - unirse: `data-mca-call="open" data-mca-call-id="<call>"`.
- **API JS:** `MCACalls.open({...})` (siempre dentro del clic) y `await MCACalls.active({type, id})`.
- **Errores de permisos:** `forbidden` y `too_many_participants` los resuelve la interfaz de TeamCalls; TeamChat no
  autoriza nada.

## 4. Avisos de llamada entrante en cualquier pantalla
- **✅ Ya disponible:** TeamCalls incluye un aviso propio en el layout del CRM (`teamcalls::partials.incoming`), solo
  donde las llamadas están habilitadas.
  - Consume el mismo canal y la misma API; no es otro sistema.
  - «Aceptar» abre TeamCalls y contesta directamente (`?call=<id>&answer=1`); «Rechazar» llama a la API.
  - Si la llamada deja de sonar sin atenderla, aparece el aviso «Llamada perdida» con «Devolver llamada».
- **🔧 Si TeamChat o el widget asumen estos avisos** (recomendado a medio plazo, para un único centro de avisos):
  1. Mostrar el aviso con `call.ringing` (`user_id` = yo). Ocultarlo con `call.accepted`, `call.answered_elsewhere`,
     `call.declined`, `call.missed` o `call.ended` de esa llamada.
  2. **Aceptar:** `MCACalls.open({ call: id, answer: true })` dentro del clic. **Rechazar:**
     `POST /api/v1/collaboration/calls/{id}/decline` con `Idempotency-Key`.
  3. Marcar `<html data-mca-call-alerts="widget">` (o `TEAMCALLS_UI_INCOMING_NOTIFIER=false`): el aviso de TeamCalls
     se calla y no hay duplicados.
- **Sin duplicados:**
  - deduplicar por `id` de evento (el feed puede repetir al reconectar);
  - un solo aviso por `call_id` mientras suene;
  - lo atendido en una pestaña se oculta en las demás (`BroadcastChannel` `teamcalls:<user_id>`, mensaje
    `incoming-handled`);
  - si la página de TeamCalls está abierta (Web Lock `teamcalls-page:<user_id>`), avisa ella.

  Implementación de referencia: `teamcalls-incoming.js` y `IncomingTracker` en `teamcalls-core.js`.

## 5. Llamadas perdidas y finalizadas (🔧 representación en TeamChat)
- **Perdida:** `call.missed` con `user_id` = yo, o `call.ended` con `status=missed` o `cancelled` sin haberla
  contestado. Mostrarla en la conversación o en el centro de avisos con «Devolver llamada»:
  `MCACalls.open({ media, user })` o, en la conversación, `{ media, conversation }`.
- **Finalizada:** `call.ended` con su `status` y `reason`. Duración: `answered_at` → `ended_at` del detalle.

## 6. Pruebas de compatibilidad (✅ en TeamCalls)
| Prueba | Qué cubre |
|---|---|
| `tests/Feature/Integration4B/CallEventsContractTest.php` | Esquema congelado; quien recibe la llamada lee `call.ringing` y el cierre por `/sync`; un tercero y otra institución no leen nada; releer da los mismos ids; rechazo y llamada perdida |
| `tests/Feature/Http/CallsIncomingNotifierTest.php` | Cuándo se incluye el aviso global y sin secretos; `answer=1` solo para abrir |
| `tests/js/group.test.cjs` | `IncomingTracker` (sin duplicados y lo atendido no vuelve), causas de fallo y estados |
| `tests/Feature/Integration4B/CallLaunchTest.php` y `tests/js/launcher.test.cjs` | Lanzador y botones (4E) |

**Recomendación para TeamChat:** una prueba propia que, con los servicios reales, inicie una llamada desde una
conversación y compruebe que su consumidor muestra y oculta el aviso con los eventos de la sección 1.

## 7. Qué NO tiene que hacer TeamChat
- No crear salas, emitir credenciales ni hablar con LiveKit.
- No decidir quién puede llamar o unirse: lo valida el servidor (CallsGateway).
- No guardar estado de llamadas en el navegador.
- No modificar los eventos `call.*`. Si necesita otro dato, se acuerda primero y se amplía el esquema con su prueba.
