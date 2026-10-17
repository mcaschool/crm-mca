# TeamCalls · Fase 4H — Preparación de la interfaz para preproducción

Rama local `integration/collaboration-4h`, creada desde `243eb60` (4G).
- **Qué se hizo:** la interfaz de llamadas queda lista para activarse de forma explícita en un entorno de
  preproducción.
- **Qué no se hizo:** no se habilitó en ningún sitio, no se desplegó nada y no se tocaron TeamChat, el widget ni otros
  módulos.

## 1. Habilitación
| Situación | Resultado |
|---|---|
| `TEAMCALLS_UI_ENABLED=false` (valor por defecto) | No existe en ningún entorno: página, API de la interfaz y lanzador responden 404 |
| `TEAMCALLS_UI_ENABLED=true` y `APP_ENV` incluido en `TEAMCALLS_UI_ENVIRONMENTS` (por defecto `local,testing`) | Habilitada |
| `APP_ENV=production` | **Nunca**, aunque se active el interruptor y se liste `production`. Abrirla en producción requiere una autorización posterior y un cambio de código (`CallLaunch::enabled`) |

- **Preproducción:** se habilita nombrando su entorno de forma explícita, por ejemplo `APP_ENV=staging` con
  `TEAMCALLS_UI_ENVIRONMENTS=staging`. Ese nombre activa el aviso «Preproducción» en la página.
- **Solo en desarrollo** (`local`/`testing`):
  - medios sintéticos (`?sintetico=1`);
  - URLs de LiveKit sin TLS (`TEAMCALLS_LIVEKIT_ALLOW_INSECURE_DEV_URLS`).
- **Tiempo real fuera de desarrollo:** si `TEAMCALLS_REVERB_SCHEME` no es `https`, la página no abre el canal y usa el
  sondeo de respaldo.

## 2. Controles de acceso (sin cambios: los existentes)
**Rutas de la página y de la API:**
- sesión (`auth`) y 2FA obligatorio (`EnsureTwoFactorEnabled`);
- CollaborationCore (`RequireCollaborationActor` → `CollaborationRequest`):
  - prueba MFA de la sesión;
  - permiso `collaboration.use`;
  - institución del actor;
  - dispositivo registrado y vinculado a la sesión en `DeviceRegistry`.
- protección CSRF de la pila `web`;
- límites por persona.

**Autorización:** no hay una segunda autenticación ni se exige ningún rol comercial del CRM. Las personas que solo usan
Colaboración entran.

**Lo que la página nunca recibe ni muestra:**
- secretos de LiveKit o de Reverb;
- salas, identidades ni rutas administrativas (webhooks, autorización del widget).

Solo recibe las rutas de la propia interfaz y de la API de llamadas, más la clave pública de Reverb.

## 3. Comportamiento ante fallos (verificado)
| Situación | Comportamiento |
|---|---|
| Servidor de medios caído | El aviso «El servidor de medios no está disponible». **Corregido en 4H:** la llamada también se termina en el servidor (quien llama) o se abandona (quien la aceptó); antes seguía sonando a los demás |
| Sesión expirada o revocada, 2FA pendiente, CSRF caducado | La página deja de consultar, cuelga en local y libera cámara y micrófono. **Corregido en 4H:** también ante `session_revoked` y `device_revoked`, que antes no se reconocían |
| Dispositivo revocado durante una llamada (E2E local con LiveKit) | En unos 1,5 s: sesión terminada, sala cerrada, ningún elemento de audio o video. Tras entregarse la revocación, el servidor saca a la persona y la llamada termina para la otra |
| Pérdida de conexión | Reconexión del SDK y recuperación con credenciales nuevas dentro del margen `TEAMCALLS_RECONNECT_GRACE` (fases 3B/3D, sin cambios) |
| Permisos de cámara o micrófono denegados | Aviso específico; la llamada sigue con lo disponible (fases 3C/4E, sin cambios) |
| Cierre de la página o de la pestaña | Se detienen las pistas (`pagehide`) y LiveKit cierra la conexión; el servidor aplica el margen de reconexión |

## 4. Variables para preproducción
| Variable | Valor en preproducción |
|---|---|
| `APP_ENV` | Nombre propio y distinto de `production`, p. ej. `staging` |
| `TEAMCALLS_UI_ENABLED` | `true` |
| `TEAMCALLS_UI_ENVIRONMENTS` | El mismo nombre, p. ej. `staging` |
| `TEAMCALLS_UI_SYNTHETIC_MEDIA` | `false` (se ignora fuera de desarrollo) |
| `TEAMCALLS_AUTHORIZATION_DRIVER` | `collaboration` |
| `TEAMCALLS_MEDIA_DRIVER` | `livekit` |
| `TEAMCALLS_LIVEKIT_API_URL` | `https://…` del LiveKit autohospedado |
| `TEAMCALLS_LIVEKIT_CLIENT_URL` | `wss://…` |
| `TEAMCALLS_LIVEKIT_API_KEY` y `TEAMCALLS_LIVEKIT_API_SECRET` | Del servidor LiveKit, solo en el `.env` del servidor y nunca en el repositorio |
| `TEAMCALLS_LIVEKIT_ALLOW_INSECURE_DEV_URLS` | `false` (se ignora fuera de desarrollo) |
| `BROADCAST_CONNECTION` | `reverb` |
| `REVERB_APP_ID`, `REVERB_APP_KEY`, `REVERB_APP_SECRET` | Credenciales de Reverb |
| `REVERB_HOST`, `REVERB_PORT`, `REVERB_SCHEME` | Reverb detrás de TLS, con `REVERB_SCHEME=https` |
| `TEAMCALLS_REVERB_HOST`, `TEAMCALLS_REVERB_PORT`, `TEAMCALLS_REVERB_SCHEME` | Dirección pública del canal; el esquema tiene que ser `https` |
| `SESSION_SECURE_COOKIE` | `true` |
| `SESSION_DRIVER` | Persistente (`database` o `redis`) |
| `TEAMCALLS_RECONNECT_GRACE`, `TEAMCALLS_RING_SECONDS`, `TEAMCALLS_MAX_PARTICIPANTS`, `TEAMCALLS_RATE_*` | Valores por defecto, salvo que se decida otra cosa |

**Requisitos del servidor LiveKit** (configuración de LiveKit, no de Laravel):
- `room.auto_create: false`;
- webhook firmado hacia `https://<preproducción>/api/teamcalls/media/livekit` con la misma API key;
- TURN/TLS para redes externas.

**Procesos persistentes en un VPS** (no en el hosting compartido):
- Reverb (`php artisan reverb:start`);
- el worker de entrega (`php artisan collaboration:realtime drain` de forma continua, o el worker de colas del outbox);
- el planificador (`schedule:run`), que ejecuta `teamcalls:media-sweep`.

## 5. Comprobaciones mínimas antes de abrir a personas autorizadas
1. Con `TEAMCALLS_UI_ENABLED=false`, `/colaboracion/teamcalls` da 404.
2. Con la configuración de preproducción, el aviso de la página dice «Preproducción» y no «Desarrollo».
3. Una persona sin `collaboration.use` recibe 403. Una sin 2FA va a «Mi perfil». Sin sesión, al login.
4. El código fuente de la página (`tc-config`) solo lleva rutas, textos y la clave pública de Reverb: ningún secreto de
   LiveKit ni de Reverb.
5. Llamada de prueba entre dos cuentas autorizadas, desde dos redes distintas:
   - se establece el audio y el video;
   - se puede compartir pantalla;
   - colgar cierra la sala (LiveKit sin salas activas).
6. Revocar un dispositivo desde otro: la página afectada se cierra sola y la llamada termina.
7. Detener LiveKit un momento: el aviso «servidor de medios no disponible» aparece y la llamada no queda sonando.
8. Comprobar que Reverb entrega los avisos por `wss` y que el worker está procesando el outbox.

## 6. Pruebas de esta fase
- **`CallsPreproductionTest`** (5 casos):
  - cerrada por defecto;
  - preproducción solo con su entorno nombrado;
  - producción nunca;
  - colaborador autorizado sí; sin sesión, 2FA o `collaboration.use`, no;
  - aislamiento de contactos;
  - sin credenciales ni rutas administrativas;
  - tiempo real solo con TLS;
  - fin de sesión en la página con `node --test`.
- **Regresión de lo afectado:** `CallsUiTest`, `CallsUiStabilityTest`, `CallsGroupUiTest`, `CallLaunchTest` e
  `InterfaceMessagesTest`.
- **E2E local con LiveKit:** dispositivo revocado durante una llamada y servidor de medios caído, antes y después de la
  corrección.
