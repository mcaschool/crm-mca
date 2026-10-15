# LiveKit de desarrollo (fase 3B) — procedimiento reproducible

Para validar TeamCalls contra un LiveKit **real, local y efímero**. Nunca contra producción, y nunca
en el hosting compartido. Nada de esto se despliega.

## 1. Servidor LiveKit (solo 127.0.0.1)
Binario oficial `livekit-server` (validado con **v1.13.10**, release de GitHub `livekit/livekit`; se
verifica su SHA-256 con el `checksums.txt` del release). En Windows no hace falta Docker:

```
livekit-server --config livekit.dev.yaml --bind 127.0.0.1 --node-ip 127.0.0.1
```

Con Docker (Linux/macOS), el equivalente es
`docker run --rm -p 127.0.0.1:7880:7880 -p 127.0.0.1:50000-50100:50000-50100/udp -v ./livekit.dev.yaml:/livekit.yaml livekit/livekit-server:v1.13.10 --config /livekit.yaml --node-ip 127.0.0.1`.
Con Docker, en `webhook.urls` va `host.docker.internal` en lugar de `127.0.0.1`.

Antes de arrancarlo, sustituye el secreto de `livekit.dev.yaml` por uno aleatorio de 48 caracteres o
más. Ese secreto no se versiona. `livekit.dev.yaml` deja así el servidor:
- `room.auto_create: false`: solo el backend crea salas;
- TCP de respaldo desactivado y rango UDP acotado;
- webhooks al CRM local y a `client/capture.php`.

## 2. CRM aislado que recibe los webhooks
Base de datos propia (p. ej. `crm_mca_teamcalls_lk`, creada y migrada con `artisan migrate`) y estas
variables de entorno **del proceso**, no del `.env`:

- `APP_ENV=local`
- `DB_DATABASE=crm_mca_teamcalls_lk`
- `TEAMCALLS_MEDIA_DRIVER=livekit`
- `TEAMCALLS_LIVEKIT_API_URL=http://127.0.0.1:7880`
- `TEAMCALLS_LIVEKIT_CLIENT_URL=ws://127.0.0.1:7880`
- `TEAMCALLS_LIVEKIT_API_KEY=APIteamcallsdev`
- `TEAMCALLS_LIVEKIT_API_SECRET=<el mismo secreto>`

Servidor del CRM, arrancado **desde `public/`** (si no, el enrutador de PHP no encuentra `index.php`):
`php -S 127.0.0.1:8099 ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php`.

> Ojo: `php artisan serve` no sirve aquí. Elimina las variables del proceso y cargaría el `.env`, que
> apunta a la base de datos de desarrollo.

## 3. Cliente WebRTC real y escenario
- Página: `php -S 127.0.0.1:8098 -t client`. Abre `http://127.0.0.1:8098/?who=ana` (y `beto`, `carla`)
  en pestañas distintas. Carga `livekit-client@2.22.3` desde jsDelivr y publica audio y video sintéticos:
  no necesita cámara ni micrófono. Desde la consola se maneja con `window.lk.connect()`,
  `window.lk.publish()`, `window.lk.report()` y `window.lk.disconnect()`.
- El motor real se maneja con el entorno del punto 2:
  `php Modules/TeamCalls/tests/LiveKitDev/scenario.php seed|start|accept|join|credentials|revoke|end|status|sweep '{json}'`.
  `credentials` escribe `client/tokens/<who>.json` para la página. Esa carpeta no se versiona.
- El script se niega a correr fuera de la BD `crm_mca_teamcalls_lk`.

## 4. Pruebas automáticas contra el servidor real
`tests/Feature/LiveKit/LiveKitRealServerTest.php` (grupo `livekit-real`) se omite salvo que existan
`TEAMCALLS_LIVEKIT_REAL_API_URL` (solo `127.0.0.1` o `localhost`), `TEAMCALLS_LIVEKIT_REAL_KEY` y
`TEAMCALLS_LIVEKIT_REAL_SECRET`.
