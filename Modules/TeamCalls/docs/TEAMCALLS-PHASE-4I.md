# TeamCalls · Fase 4I — Robustez, integración colaborativa y preparación de infraestructura

- **Rama:** `integration/collaboration-4i`, creada desde `e82916d` (4H).
- **Autoridad de acceso:** CollaborationCore.
- **Producción:** sigue bloqueada (`CallLaunch::enabled`).
- **Módulos de Codex:** no se modificó ningún módulo de TeamChat ni el widget.

**Documentos relacionados:**
- guía para Codex: `TEAMCALLS-TEAMCHAT-INTEGRATION-4I.md`;
- manual de staging: `TEAMCALLS-STAGING-RUNBOOK-4I.md`;
- ejemplos de infraestructura: `Modules/TeamCalls/deploy/`.

## Cambios
1. **LiveKit caído**
   - **Disyuntor en el servidor** (`MediaHealth`, caché compartida): tras un fallo de conexión o una respuesta 5xx,
     las operaciones fallan al instante durante `TEAMCALLS_LIVEKIT_BREAKER_SECONDS` (30).
   - **Reintentos de conexión acotados** (`TEAMCALLS_LIVEKIT_CONNECT_RETRIES`=1).
   - **Inicio rechazado con el servidor caído** (`MediaOutage`): no se crea la llamada, así que nadie queda sonando.
   - **En la interfaz:**
     - el intento de conexión es cancelable, con tiempos máximos configurables (`TEAMCALLS_UI_*_TIMEOUT_MS`);
     - pasos visibles y aviso de «tarda más de lo normal»;
     - indicador de conexión (sin red, servidor de llamadas caído, sin servidor de medios, tiempo real o sondeo);
     - con el servidor caído, los botones de llamada se desactivan;
     - colgar es inmediato: se libera todo en local y el servidor se notifica en segundo plano.
   - **Mensajes distintos por causa:** servidor de medios, conexión de medios (WebRTC), lentitud, red, sesión, segundo
     factor, sesión revocada, dispositivo revocado y «la finalizó quien la inició».
2. **Avisos de llamada entrante en cualquier pantalla del CRM**
   - Lo hace `teamcalls::partials.incoming`, incluido en el layout del CRM solo donde las llamadas están habilitadas.
   - Es un consumidor de CollaborationRealtime y de la API de llamadas, no un sistema paralelo.
   - **Acciones:**
     - «Aceptar» abre TeamCalls y contesta (`answer=1`);
     - «Rechazar» rechaza por la API;
     - si deja de sonar sin atenderla, aparece el aviso de llamada perdida con «Devolver llamada».
   - **Sin duplicados:**
     - `IncomingTracker` muestra un solo aviso por llamada;
     - lo atendido en una pestaña se oculta en las demás;
     - se calla si la página de TeamCalls está abierta, o si el widget asume los avisos (`data-mca-call-alerts`).
3. **Contrato de eventos `call.*`**
   - Congelado por prueba y documentado para Codex, con el consumo por `/api/v1/collaboration/sync`.
4. **Operación**
   - `php artisan teamcalls:health [--json]`: medios, disyuntor, tiempo real, cola y llamadas, sin secretos. El código
     1 indica degradado.

## Defectos encontrados y corregidos
| Defecto | Corrección |
|---|---|
| Con LiveKit caído, avisar tardaba ~22 s | Reintentos acotados y disyuntor: unos 6 s la primera vez (medido en E2E), menos de 1 s las siguientes |
| Con el servidor de medios caído, una llamada nueva sonaba a los demás sin poder conectarse | `start` la rechaza con 503 mientras el disyuntor está abierto |
| Colgar mientras se conectaba esperaba la respuesta del servidor (4,3 s en E2E) | Corte y liberación locales inmediatos (0,03 s); el servidor recibe el aviso en segundo plano y cancela la llamada |
| `two_factor_required` y `session_revoked` mostraban el mismo texto que «sesión caducada» | Mensajes propios |
| Quien no inició la llamada veía «La llamada terminó» cuando quien la inició la cerraba para todos | Aviso «la finalizó quien la inició» |

## Pruebas
- **Nuevas:**
  - `MediaOutageTest` (5);
  - `HealthCommandTest` (2);
  - `CallsIncomingNotifierTest` (3);
  - `CallEventsContractTest` (5);
  - `tests/js/group.test.cjs` (+4 casos) y `launcher.test.cjs` (`answer`).
- **Ajustada:** `LiveKitAdapterTest`. «Sin conexión» pasa de 3 a 2 intentos (cambio deliberado); 5xx sigue en 3.
- **Regresión:** todo el módulo TeamCalls más Localization y PanelRoutesGuard dan **267 aprobadas y 3 omitidas**
  (`livekit-real`, requieren infraestructura). Muestras del layout del CRM (perfil, 2FA, diseño, widgets): 52
  aprobadas. PHPStan y Pint limpios en TeamCalls.
- **E2E local** (LiveKit 1.13.10, MySQL, dos o tres sesiones con login y TOTP, medios sintéticos):
  - LiveKit caído: 6,1 s la primera vez y 0,85 s después, sin crear llamada; indicador en rojo y botones desactivados;
  - colgar al conectar: 0,03 s y el servidor cancela;
  - aviso en `/profile`: «Aceptar» contesta, la llamada queda en curso y la otra parte ve los medios;
  - «Rechazar»: quien llama ve «rechazada»;
  - sin respuesta: aviso «Llamada perdida» y quien llama ve «Sin respuesta»;
  - cierre brusco de una de tres pestañas: «Reconectando…» a los ~4 s, salida a los ~31 s y la llamada sigue para
    las otras dos.
- **No verificado** (requiere VPS, equipos o redes reales): TURN/TLS desde redes externas, cámara, micrófono y
  pantalla físicos, Reverb con `wss` real y carga de 25 personas.
