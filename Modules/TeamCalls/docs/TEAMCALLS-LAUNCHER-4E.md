# MCA Colaboración · Fase 4E — Punto de entrada a llamadas desde TeamChat y el widget

Rama local `integration/collaboration-4e` (desde `integration/collaboration-4d` `5c24463`). Solo desarrollo: igual que la
interfaz de TeamCalls, existe únicamente con `TEAMCALLS_UI_ENABLED=true` en los entornos `local` y `testing`. En
cualquier otro caso las rutas responden 404 y el parcial no imprime nada. No se habilita ninguna interfaz nueva en
producción.

## 1. Qué hay
| Pieza | Ubicación | Función |
|---|---|---|
| Contrato de intención | `app/Http/CallLaunch.php` | Formato de la URL, `CallLaunch::url()` para enlaces del servidor y `CallLaunch::enabled()` |
| Página de llamadas | `GET /colaboracion/teamcalls?…` (`collab.calls.ui`) | Recibe la intención y la ejecuta con la API existente |
| Lanzador JS | `GET /colaboracion/teamcalls/lanzador.js` (`collab.calls.ui.launcher`) | `window.MCACalls` y botones declarativos; estático, sin sesión |
| Parcial | `teamcalls::partials.launcher` | Imprime el `<script>` del lanzador solo si está habilitado |

**Motor único.** Las llamadas individuales usan la API web de TeamCalls; las de conversaciones, la API definitiva
`/api/v1/collaboration/calls` (CallsGateway). No hay servicios audiovisuales nuevos.

**Autorización.** Siempre en el servidor: sesión, MFA, `collaboration.use`, dispositivo, institución, membresía de la
conversación, selección explícita todo o nada y participantes. La intención solo expresa lo que la persona quiere.

## 2. Intenciones admitidas
| Caso | Parámetros | JS (`MCACalls.open`) |
|---|---|---|
| Voz individual | `media=audio&user=<id>` | `{media:'audio', user:42}` |
| Video individual | `media=video&user=<id>` | `{media:'video', user:42}` |
| Conversación privada | `media=audio\|video&conversation=direct:<id>` | `{media:'video', conversation:{type:'direct', id}}` |
| Grupo o canal | `media=…&conversation=group\|channel:<id>&invitees=3,4` | `{media, conversation:'channel:<id>', invitees:[3,4]}` |
| Abrir una llamada existente | `call=<id de la llamada>` | `{call:'<id>'}` |

Reglas de las intenciones:
- `key` (opcional, de 8 a 48 caracteres `[A-Za-z0-9._:-]`): clave de idempotencia. El lanzador la genera, así que
  repetir la misma intención no crea otra llamada.
- En las conversaciones privadas no se elige a nadie: TeamChat resuelve a la otra persona en el servidor.
- En grupos y canales, `invitees` es obligatorio (máximo 24) y se valida contra la membresía del canal o grupo.
- Abrir una llamada existente: si la llamada suena, se muestra el aviso para contestar; si la persona ya está en ella,
  entra; si no está autorizada, el servidor la rechaza.

Comportamiento de la página:
- **Confirmación:** si la navegación no viene del mismo origen (`Sec-Fetch-Site` distinto de `same-origin`: enlace
  externo, URL pegada), la página no hace nada sin un clic de confirmación.
- **Una sola vez:** la intención se elimina de la dirección al cargar, así que recargar no la repite.
- **Ventana reutilizada:** si la ventana `mca-teamcalls` ya está abierta, el lanzador le pasa la intención sin
  recargarla, para no cortar la llamada en curso. Con una llamada en curso, la página rechaza otra intención con un
  aviso.

## 3. Qué tiene que hacer Codex para mostrar los botones
1. **Cargar el lanzador** en las vistas de TeamChat (`inbox`, `chat-content`) y en el marco del widget
   (`widget-frame`):
   ```blade
   @includeIf('teamcalls::partials.launcher', ['nonce' => $nonce ?? null])
   ```
   - `@includeIf` no falla si TeamCalls no está instalado.
   - El script es del mismo origen, así que `script-src 'self'` basta en la CSP del widget; el `nonce` es opcional.
2. **Ocultar los botones** donde las llamadas no estén disponibles (producción, módulo apagado, lanzador sin cargar),
   con una regla en su CSS:
   ```css
   html:not([data-mca-calls="on"]) [data-mca-call] { display: none !important; }
   ```
   En interfaces generadas con JS sirve también `window.MCACalls?.available`, y el evento `mca-calls:ready` sobre
   `document` avisa si el lanzador carga después.
3. **Botones declarativos**, sin JS propio para abrir:
   ```html
   <!-- conversación privada -->
   <button type="button" data-mca-call="audio" data-mca-call-conversation="direct:{{ $conversation->id }}">Llamar</button>
   <button type="button" data-mca-call="video" data-mca-call-conversation="direct:{{ $conversation->id }}">Videollamada</button>
   <!-- grupo o canal: la selección la aporta TeamChat -->
   <button type="button" data-mca-call="video" data-mca-call-conversation="channel:{{ $id }}" data-mca-call-invitees="3,4">Videollamada</button>
   <!-- llamada viva de la conversación -->
   <button type="button" data-mca-call="open" data-mca-call-id="{{ $callId }}">Unirse</button>
   ```
4. **Selección de invitados en grupos y canales.** Hay dos opciones:
   - mantener `data-mca-call-invitees` al día;
   - escuchar `mca-call:before`, que es cancelable, y completar `event.detail.intent.invitees` con la selección. Si
     hace falta abrir antes un selector, se llama a `preventDefault()` y después a `MCACalls.open({...})` desde el
     clic de confirmación.
5. **Botón «Unirse».** `await MCACalls.active({type, id})` devuelve la llamada viva de la conversación (`{id, status,
   viewer_state, …}`) o `null`. En el widget devuelve `null`, porque las credenciales delegadas no alcanzan las
   llamadas (403); ahí basta un botón de llamada sin estado.
6. **Llamar a `MCACalls.open()` dentro del manejador del clic**, de forma síncrona, para que el navegador no bloquee la
   ventana emergente.
   - Si se bloquea en una página normal, el lanzador navega a la interfaz.
   - Dentro de un marco devuelve `{opened:false, url}` y no navega: el widget puede mostrar ese enlace.
   - El `iframe` del widget ya tiene `allow-popups allow-popups-to-escape-sandbox`.
7. **Errores:** el evento `mca-call:error` indica una intención mal formada (por ejemplo, un canal sin invitados) y
   `mca-call:opened` devuelve `{opened, reused, url}`. Los rechazos de permisos los muestra la interfaz de llamadas.

Desde PHP, por ejemplo en un aviso o un correo de desarrollo, la URL se obtiene con
`\Modules\TeamCalls\Http\CallLaunch::url(['call' => $id])`.

## 4. Pruebas focalizadas (fase 4E)
- **`tests/Feature/Integration4B/CallLaunchTest.php`** (Pest, servicios reales de Core y TeamChat, MySQL): 7 casos.
  - formato de cada intención y rechazo de las mal formadas;
  - confirmación según `Sec-Fetch-Site`;
  - `CallLaunch::url`;
  - lanzador y parcial solo en desarrollo, sin cookies;
  - conversación privada: inicio idempotente, la otra persona abre la llamada existente y un tercero no puede verla
    ni llamar;
  - canal: invitación explícita, incluido un miembro del equipo que no es del canal (403);
  - ejecución del lanzador JS con `node --test`.
- **`tests/js/launcher.test.cjs`** (node --test): 8 casos.
  - validación con las mismas reglas que el servidor;
  - URL;
  - abrir la ventana nueva, reutilizarla sin recarga y no tocar una ventana ajena;
  - ventanas bloqueadas, con página normal o dentro de un marco;
  - `active`;
  - botones declarativos con `before`, cancelación y error.
- **Regresión de lo modificado:** `CallsUiTest` (13), `CallsUiStabilityTest` (6) e `InterfaceMessagesTest` (4).
  PHPStan y Pint limpios en lo modificado.
- **Navegador real** (servidor local, MySQL de la E2E, LiveKit 1.13.10 local, dos sesiones con login y TOTP; sin
  Reverb, porque la interfaz usa el sondeo de respaldo). El lanzador se inyectó en la página de TeamChat como lo haría
  el parcial. Resultados:
  - **Permiso denegado:** videollamada desde una conversación privada que Beto había abandonado → `forbidden` y ninguna
    llamada creada.
  - **Llamada desde un canal:** el evento `before` aporta al invitado → llamada creada, `ringing`.
  - **Abrir la llamada existente:** Beto la detecta con `MCACalls.active` y la abre con un botón «Unirse» → aviso
    entrante → acepta → `in_call`, con LiveKit admitido y la otra persona conectada.
  - **Segunda intención con una llamada en curso:** se rechaza sin recargar la página.
  - **URL tecleada (no del mismo origen):** aparece el diálogo de confirmación; al cancelar no se crea nada.
  - **Cierre:** la anfitriona cuelga y la llamada termina para todos (`ended_by_host`).
  - El navegador de pruebas bloquea las ventanas emergentes, así que se ejercitó el respaldo de navegación; la
    reutilización de la ventana está cubierta con `node --test`. Cámara y micrófono físicos siguen sin probarse.

## 5. Limitaciones
- Solo desarrollo, igual que la interfaz de TeamCalls; la versión de producción depende de la decisión sobre la pila
  común de MCA One.
- Las llamadas viven en una ventana aparte: no hay llamada incrustada dentro del widget ni de TeamChat.
- Sin un listado de candidatos en el contrato común, la selección de invitados de grupos y canales es responsabilidad de
  TeamChat.
- `MCACalls.active` no funciona con credenciales delegadas del widget, por diseño (403).
- El aviso de llamada entrante depende de que la interfaz de llamadas esté abierta (sondeo o Reverb). Mostrar el aviso
  dentro de TeamChat o del widget requiere que Codex se suscriba a los eventos `call.*` de CollaborationRealtime.
