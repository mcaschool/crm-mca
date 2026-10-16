# TeamCalls · Fase 4F — Videollamadas grupales y compartir pantalla

Rama local `integration/collaboration-4f`, creada desde `bfefef5` (4E). Afecta solo a la interfaz web de TeamCalls, que
sigue disponible únicamente en desarrollo. No hay cambios en el motor, la API ni los permisos del servidor, y no se
modificaron TeamChat ni el widget universal.

## Qué hay
- **Sala**
  - Fondo azul institucional (`--mca-blue-deep`) y altura fija.
  - El mosaico se adapta al número de personas (1, 2, 3 o 4 columnas) y al ancho; en móvil se apila.
- **Fichas por persona**
  - Video o avatar, nombre, micrófono silenciado y cámara apagada, según LiveKit.
  - Indicador de quién habla (`ActiveSpeakers`).
  - Insignia «Reconectando…» cuando LiveKit marca la conexión como perdida.
  - Ficha «Sonando…» para las invitaciones pendientes.
- **Entradas, salidas y reconexiones**
  - Avisos breves al entrar, rechazar, no contestar, salir, ser retirado o recibir una invitación.
  - Una desconexión deja la ficha en «Reconectando…» durante el margen de reconexión del servidor.
  - La salida se confirma cuando la API la registra o cuando vence ese margen, así que recargar la página no aparece
    como «salió».
- **Añadir personas**
  - Panel de participantes con la API existente `collab.calls.invite`.
  - El servidor valida quién puede invitar, el máximo de participantes y la membresía de la conversación.
- **Retirar**
  - Solo quien inicia la llamada, con un clic de confirmación.
  - Usa la API existente `collab.calls.revoke`.
  - La sala rota y los demás se reconectan solos.
- **Compartir pantalla**
  - Con el SDK de LiveKit (`setScreenShareEnabled`), que abre el diálogo nativo del navegador y excluye la propia
    pestaña.
  - La pantalla se muestra como contenido principal; los participantes pasan a una tira lateral, o inferior en móvil.
  - El botón «Dejar de compartir» del navegador se detecta por el evento `ended` de la pista.
- **Permisos de pantalla**
  - El botón solo aparece cuando LiveKit confirma la fuente `SCREEN_SHARE` en los permisos que concede el backend.
  - El backend la concede solo en las videollamadas: no hizo falta ningún ajuste del servidor.
- **Pantalla completa**: en la zona de la llamada.
- **Móvil**: controles solo con icono y con nombre accesible.

## Pruebas
- **Automáticas**
  - `CallsGroupUiTest`: rutas y elementos de la página.
  - `tests/js/group.test.cjs` (node --test): mosaico, avisos y permisos de fuente.
  - Regresión de `CallsUiTest`, `CallsUiStabilityTest` e `InterfaceMessagesTest`.
  - Los permisos por tipo de llamada ya estaban cubiertos en `LiveKitAdapterTest`, y las invitaciones y expulsiones en
    `CallApiTest`.
- **LiveKit real (1.13.10 local)** con tres sesiones (login y TOTP) y medios sintéticos:
  - videollamada de 3 personas;
  - invitación durante la llamada;
  - micrófono y cámara remotos;
  - reconexión al recargar;
  - pantalla compartida y su fin desde el navegador;
  - vista en móvil;
  - expulsión con rotación de sala;
  - salida voluntaria;
  - llamada de voz sin el permiso de pantalla.

## Pendiente
- El diálogo nativo de compartir pantalla con una pantalla real (no se puede automatizar), así como cámara y
  micrófono físicos.
- Audio de la pestaña o del sistema al compartir: depende del navegador y no está verificado.
- Cuadrícula con más de 25 personas y paginación: fuera de esta fase (fijar participantes llegó en la fase 4G).

## Fase 4G — Controles avanzados (rama `integration/collaboration-4g`)
Todo es local a quien mira: nada se comparte con los demás.

- **Prioridad del área principal:**
  1. la ficha fijada por quien mira;
  2. la pantalla compartida;
  3. el orador activo, en la vista de orador;
  4. sin ninguno de los anteriores, mosaico.
- **Fijar:** botón en cada ficha, visible al pasar el cursor o siempre en pantallas táctiles. Fijar durante una pantalla
  compartida es temporal: la pantalla pasa a la tira, se muestra «Volver a la pantalla compartida» y una pantalla nueva
  vuelve a ser la principal.
- **Orador activo:**
  - Vista automática con tres o más personas, que se puede cambiar entre mosaico y orador desde la cabecera.
  - `SpeakerTracker` evita los saltos: el nuevo orador tiene que hablar de forma sostenida 1,5 s y no hay más de un
    cambio cada 5 s.
- **Nivel de audio:** `participant.audioLevel` de LiveKit, en 0–3 barras con forma de onda junto al nombre y un halo en
  el botón del micrófono.
- **Calidad de conexión:** `ConnectionQuality` del SDK, mostrada como buena (excellent o good), inestable (poor) o sin
  conexión (lost). Si es desconocida, no se muestra indicador.
- **Pruebas:**
  - `tests/js/group.test.cjs`: histéresis, barras, calidad y prioridad del área principal;
  - `CallsGroupUiTest`;
  - verificación con LiveKit real en escritorio y móvil.
- **Sin verificar con LiveKit real:** el estado «sin conexión» solo se comprobó en la lógica, porque no se simuló una
  pérdida de red.
