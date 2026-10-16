# MCA Colaboración · Fase 4D — Consolidación de TeamChat (Fases 6–8), TeamCalls y servicios compartidos

Rama local `integration/collaboration-4d` · worktree `CRM-MCA-integration-4d` · base `13816d8` (4C). Sin push, sin
merge a `main` y sin despliegues. Los worktrees originales no se tocan.

## 1. Commits incorporados (orden de dependencia, `cherry-pick -x`)
| Fase TeamChat | Origen (Codex) | Integrado | Contenido |
|---|---|---|---|
| 6 | `771758f` | `633963d` | Notas de voz y adjuntos privados (almacenamiento privado, validación de tipo, tamaño y contenido, escáner opcional) |
| 7 | `44f66d5` | `53daa8b` | Interacciones: respuestas, reacciones, menciones, fijados, edición y borrado; búsqueda autorizada |
| 8 | `42f12a7` | `860d00f` | Widget universal de colaboración y avisos coordinados |

**Verificaciones previas:**
- Las tres fases no estaban en 4C, ni directamente ni por cherry-pick.
- La corrección 4C (`4711a80` ← `7256bdf`) no se duplica: sus 16 archivos siguen idénticos a `7256bdf`.
- Sin cambios en `composer.json`, `composer.lock` ni `package.json`.

**Comprobación del árbol:** fuera de `Modules/TeamCalls`, el árbol resultante es idéntico al de la referencia autorizada
`7256bdf`, salvo las diferencias de integración ya aprobadas:
- `composer` con `firebase/php-jwt`;
- `modules_statuses.json` y `tests/Pest.php` con TeamCalls;
- `.env.example`;
- `InterfaceMessagesTest`;
- `PanelRoutesGuardTest`, de TeamCalls fase 2.

**No incorporado:** `cde6df0` (*fix(mcp)* de worktrees de Git). Es posterior a la referencia autorizada `7256bdf`; ver el
punto 6.

**Conflictos Git:** ninguno en los tres cherry-picks.

## 2. Problemas de integración encontrados y corregidos
| Problema | Causa | Corrección |
|---|---|---|
| La suite completa abortaba con `Cannot redeclare widgetHeaders()` | `WidgetTest.php` (Fase 8) declara una función global ya existente en `Modules/Chat/tests/Feature/WidgetApiTest.php` (widget de Celia del CRM); Pest carga todos los archivos en un proceso | Se renombra solo el helper de la prueba nueva (`collaborationWidgetHeaders`), sin tocar ninguna aserción (`f0534c5`). **Necesita el visto bueno de Codex** |
| La prueba de CSRF de TeamCalls no ejercía el CSRF real | La Fase 8 sustituye el `PreventRequestForgery` del grupo `web` por `WidgetCsrf` (`final`) | La prueba ejercita el middleware vigente con un entorno distinto de `testing`: escrituras sin token → 419, con token → 201 |
| Credenciales delegadas del widget contra la API heredada de llamadas → **500** | `WidgetCsrf` lanza `ContractException` fuera de `/api/v1/collaboration`, donde el renderizador de Core no actúa | TeamCalls la responde en sus rutas con su código y estado (403 `forbidden`). Prueba añadida: el widget no inicia llamadas por ninguna API |

## 3. Integración TeamChat ↔ TeamCalls
- **Un único motor de llamadas:** CallsGateway y CallService. TeamChat no inicia llamadas por su cuenta y las Fases 6–8
  no contienen lógica de llamadas.
- **Chat privado:**
  - `POST /api/v1/collaboration/calls` con `conversation: direct`: TeamChat resuelve al otro miembro.
  - Probado con servicios reales en las pruebas y en la E2E (punto 5).
- **Grupos y canales:**
  - La selección es explícita y se valida todo o nada contra la membresía **del canal**, no la del equipo.
  - Salir del canal, del grupo o del equipo expulsa de la llamada mediante la revocación durable (`ChannelCallsTest`
    nuevo y `RealServicesTest`).
- **Widget universal:**
  - Reutiliza la API, Core, Realtime y las vistas de TeamChat, sin un motor paralelo.
  - Sus credenciales delegadas (`Authorization: MCAWidget`) solo alcanzan la lista de rutas de chat y presencia de
    `WidgetCsrf`; **no** las llamadas (403).
  - No hay llamadas dentro del widget: para habilitarlas, Codex tendría que añadir la capacidad y las rutas de forma
    coordinada.
- **Eventos:** los nuevos `teamchat.message.*` no colisionan con `call.*`, y TeamCalls sigue siendo el dueño de sus
  políticas.

## 4. Seguridad y aislamiento
- **Adjuntos y notas de voz** (verificado en E2E real):
  - disco privado fuera de `public` (`…/uploads/<institución>/<subida>/content`, `serve: false`);
  - descarga solo por el endpoint autorizado: sin sesión, **401**;
  - cabeceras `no-store`, CSP `sandbox; default-src 'none'`, `nosniff` y CORP `same-origin`.
- **Búsqueda y menciones:** limitadas a la membresía y la institución (pruebas de Codex de la Fase 7 y búsqueda real en
  la E2E).
- **Se conservan:** `collaboration.use`, MFA de sesión, CSRF, aislamiento institucional, la corrección 4C de
  `CurrentInstitution` y los filtros multiinstitución.

## 5. Pruebas
**Batería completa** (MySQL `crm_mca_integration_4d_test`): **1835 passed, 1 failed, 3 skipped** (12140 aserciones,
4182 s). En 4C: 1786 passed, 1 failed, 3 skipped. Comparación caso a caso por JUnit (archivo › nombre):
- 0 regresiones y 0 casos desaparecidos.
- 49 casos nuevos, todos en verde: 46 de TeamChat (Fases 6–8) y 3 de TeamCalls (`ChannelCallsTest` y la prueba del
  widget contra las llamadas).
- El único fallo es el mismo de 4C (`McpOpsTest › crm_git_status`, de entorno; ver el punto 6), y las tres omisiones
  son las `livekit-real`.
- **Pint** limpio en todo lo modificado desde `13816d8`. **PHPStan** sin errores en contratos, CollaborationCore,
  CollaborationRealtime, TeamChat y TeamCalls. **Conformidad de contratos:** 30/30.

Además:
- **Scripts autónomos de Codex** sobre un MySQL **aislado propio** (puerto 33327, datos en `.testing/mysql` del worktree
  4D). El puerto 33317 de Codex no se tocó; el puerto del arnés se cambió solo durante la ejecución y se restauró.
  - Los 9 terminan con éxito: 211 comprobaciones PASS.
  - Cubren `CollaborationConcurrency`, `RealtimeIntegration`, `Compatibility4BIntegration`, `TeamChatConcurrency`,
    `TeamChatSpacesConcurrency`, `TeamChatAttachmentsConcurrency`, `TeamChatInteractionsConcurrency`,
    `WidgetConcurrency` y `RealtimeWebSocket` (Reverb real).
- **E2E real** (MySQL, Reverb, LiveKit 1.13.10, dos navegadores con login y TOTP, personas solo de Colaboración):
  - **Widget en el layout del CRM:** frame → authorize → exchange → contenido → canal de Reverb → sync.
  - **Chat directo:** mensaje y documento por el flujo real de subida (`ready`). La otra persona lo lee, lo descarga y lo
    encuentra con la búsqueda; sin sesión, 401. El archivo queda en el almacenamiento privado.
  - **Llamada desde ese chat** por la API definitiva:
    - el aviso llega por tiempo real y se acepta desde la interfaz;
    - hay medios reales en ambos sentidos (fotogramas y bytes medidos).
  - **Salir del chat durante la llamada:**
    - `conversation.membership.revoked (direct, left)` → worker → 1 recibo → expulsión → llamada terminada;
    - LiveKit queda sin salas, la presencia limpia y el outbox sin pendientes.

## 6. Pendientes
- **MCP:** `McpOpsTest › crm_git_status` sigue fallando en worktrees.
  - La corrección existe en `cde6df0` (Codex), posterior a la referencia autorizada `7256bdf`, y **no se incorpora** sin
    autorización.
  - Resuelve los metadatos con `git rev-parse` en lugar de leer `.git/HEAD`.
- **LiveKit:** las tres pruebas `livekit-real` siguen omitidas. Requieren `TEAMCALLS_LIVEKIT_REAL_*`. LiveKit real sí
  se usó en la E2E.
- **Observaciones para Codex (no bloqueantes):**
  - la descarga de adjuntos sale como `Cache-Control: public, no-store`, porque `BinaryFileResponse` añade `public`;
    conviene `setPrivate()`;
  - el widget en un origen no registrado responde 500 en `/collaboration/widget/*`; debería ser 403;
  - no hay un punto de entrada a llamadas desde la interfaz de TeamChat ni desde el widget: se inician por la API
    definitiva o por la interfaz de TeamCalls.
