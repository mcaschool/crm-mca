# TeamCalls · Manual operativo de preproducción (staging) — fase 4I

Estado: **preparado, no ejecutado**. No hay VPS contratado ni configurado. Este manual describe cómo montarlo
cuando se autorice. Los archivos de ejemplo están en `Modules/TeamCalls/deploy/` y no contienen secretos.

## 1. Arquitectura
```
Navegador ──https──▶ nginx (staging.example.com) ──▶ PHP-FPM · Laravel (CRM + CollaborationCore + TeamChat + TeamCalls)
         ──wss────▶ nginx (ws.…)  ──▶ Reverb :8080 (CollaborationRealtime)
         ──wss────▶ nginx (rtc.…) ──▶ LiveKit :7880 (señalización)
         ──UDP 50000-60000 / TCP 7881 / TURN 3478 UDP · 5349 TLS──▶ LiveKit (medios)
LiveKit ──webhook firmado https──▶ /api/teamcalls/media/livekit
Laravel ──API de salas (token corto)──▶ LiveKit · ──eventos──▶ Reverb · cola «collaboration» ──▶ worker DrainOutbox
```

**Recomendación para staging:** todo en el mismo VPS (aplicación, MySQL, Reverb, LiveKit y workers).
- Queda aislado del Hostinger de producción y se mantiene una sola experiencia de MCA CRM en el dominio de staging.
- En producción caben dos opciones, a decidir tras validar staging: la aplicación en Hostinger con medios y tiempo
  real en un VPS (los workers necesitan acceso a la base de datos), o migrar también la aplicación al VPS.

## 2. Requisitos
| Recurso | Staging (hasta unas 25 personas simultáneas) |
|---|---|
| VPS | 4 vCPU, 8 GB de RAM, 80 GB SSD, IPv4 pública fija, Ubuntu 24.04 LTS |
| Dominios | `staging.`, `ws.staging.`, `rtc.staging.` y `turn.staging.` (registros A a la IP del VPS) |
| Software | nginx, PHP 8.3-FPM (extensiones del CRM), MySQL 8.4, Composer, Node 20 (pruebas), supervisor, certbot y LiveKit server (binario oficial, misma versión validada: 1.13.x) |

Puertos del cortafuegos (entrada):

| Puerto | Uso |
|---|---|
| 22/TCP | SSH, solo IPs de administración |
| 80 y 443/TCP | nginx (HTTP→HTTPS, aplicación, wss de Reverb y de LiveKit) |
| 7881/TCP | WebRTC por TCP |
| 50000–60000/UDP | WebRTC |
| 3478/UDP | TURN |
| 5349/TCP | TURN/TLS |

No se exponen 3306 (MySQL), 7880 (LiveKit), 8080 (Reverb) ni 6789 (métricas): quedan en local.

## 3. Puesta en marcha (orden)
1. **Sistema base:** usuario de despliegue sin root, cortafuegos (`ufw`) con los puertos anteriores, actualizaciones
   automáticas de seguridad y hora sincronizada (NTP: los tokens y webhooks de LiveKit dependen de la hora).
2. **TLS:** usar certbot para los cuatro dominios. Los certificados de `turn.` se copian o enlazan a
   `/etc/livekit/certs/…` con un `deploy-hook` que reinicia LiveKit.
3. **Base de datos:** crear una base y un usuario exclusivos de staging. Nunca se copian datos reales de producción
   sin anonimizar.
4. **Aplicación:** clonar la rama aprobada (nunca `main` sin autorización) y ejecutar
   `composer install --no-dev --optimize-autoloader`.
   - Crear el `.env` a partir de `deploy/env/staging.env.example` y `APP_KEY` con `php artisan key:generate`.
   - Ejecutar `php artisan migrate --force` y `php artisan config:cache route:cache view:cache`.
5. **LiveKit:**
   - instalar el binario en `/usr/local/bin/livekit-server`;
   - copiar `deploy/livekit/livekit.yaml.example` a `/etc/livekit/livekit.yaml`;
   - generar la clave y el secreto **en el servidor** (`livekit-server generate-keys`) y guardarlos en
     `/etc/livekit/livekit.env` con permisos 600;
   - instalar `deploy/systemd/livekit.service.example` y ejecutar `systemctl enable --now livekit`.
6. **nginx:** `deploy/nginx/teamcalls-staging.conf.example`, después `nginx -t` y la recarga.
7. **Procesos:** `deploy/supervisor/teamcalls-staging.conf.example` (Reverb y dos workers de la cola
   `collaboration`), y después `supervisorctl reread && supervisorctl update`.
8. **Planificador y salud:** `deploy/cron/teamcalls-staging.cron.example` en `/etc/cron.d/`.
9. **Widget:** registrar el widget de la propia aplicación con `php artisan collaboration:widget register …`, con
   el origen de staging.
10. **Habilitar a personas autorizadas:** solo con `collaboration.use` y el segundo factor configurado. La interfaz ya
    está restringida a `APP_ENV=staging` por `TEAMCALLS_UI_ENVIRONMENTS`.

## 4. Verificación antes de abrir a personas autorizadas
1. `php artisan teamcalls:health --json` da `status: ok`, `media.reachable: true`, `breaker_open: false`, la cola sin
   retraso y `ui.enabled: true`.
2. `/colaboracion/teamcalls`:
   - con la sesión de una persona autorizada, aviso «Preproducción»;
   - sin `collaboration.use`, 403;
   - sin sesión, al login.
3. Llamada de voz y videollamada entre dos cuentas **desde dos redes distintas** (fibra y datos móviles):
   - el audio y el video van en los dos sentidos;
   - la pantalla compartida se ve;
   - al colgar, `teamcalls:health` muestra `calls.live: 0` y LiveKit queda sin salas.
4. **Aviso global:** con una persona en otra pantalla del CRM, entra una llamada → el aviso aparece; «Aceptar» abre
   y contesta. Si nadie contesta, aparece el aviso de llamada perdida.
5. **Llamada grupal de tres:** fijar, orador activo y retirar (la sala rota y el resto sigue).
6. **Fallos:**
   - detener LiveKit (`systemctl stop livekit`): la primera llamada falla en unos 6 s con «servidor de medios no
     disponible», las siguientes al instante y sin sonar a nadie, y el indicador está en rojo;
   - arrancarlo: en 30 s o menos vuelve a funcionar.
7. **Revocaciones:** cerrar la sesión o revocar el dispositivo durante una llamada → la página se cierra sola y libera
   cámara y micrófono; la otra persona ve la salida.
8. **TURN:** forzar una red que bloquee el UDP (o probar desde una red corporativa) y comprobar que la llamada se
   establece por TURN/TLS.
9. **Secretos:** revisar que el código fuente de la página (`tc-config`, `tc-incoming-config`) no contiene secretos y
   que `storage/logs/laravel.log` no registra tokens ni claves.

## 5. Supervisión y registros
- **Salud:** `teamcalls:health` cada 5 minutos (cron). El código 1 indica degradado: servidor de medios inalcanzable
  o cola retrasada más de 60 s.
- **Métricas de LiveKit:** Prometheus en `127.0.0.1:6789` (salas, participantes, pérdida de paquetes).
- **Registros:**
  - `/var/log/mca/*.log` (Reverb y workers, rotados por supervisor);
  - `journalctl -u livekit`;
  - `storage/logs/laravel.log`, donde TeamCalls registra operaciones y tipos de fallo, nunca secretos.
- **Reinicio:** supervisor (Reverb y workers) y systemd (LiveKit) reinician solos. Tras desplegar código, ejecutar
  `php artisan queue:restart` y `supervisorctl restart mca-reverb`.

## 6. Límites y capacidad
- **Por llamada:** `TEAMCALLS_MAX_PARTICIPANTS=25` (la aplicación) y `room.max_participants: 25` (LiveKit).
- **Recursos de LiveKit:** límites de pistas y ancho de banda en `limit:`. Con 4 vCPU, unas 25 personas con video a
  720p como orientación; hay que medirlo en la validación.
- **Peticiones:** límites por persona de TeamCalls (`TEAMCALLS_RATE_*`) y de CollaborationRealtime (`throttle`).

## 7. Vuelta atrás
- **Deshabilitar sin desplegar:** `TEAMCALLS_UI_ENABLED=false` y después `php artisan config:cache`. La interfaz y el
  aviso global desaparecen (404) y el resto del CRM sigue igual.
- **Medios:** `TEAMCALLS_MEDIA_DRIVER=none`. Las llamadas responden 503 de forma controlada.
- **Producción:** sigue bloqueada en el código (`CallLaunch::enabled`). Abrirla exige una autorización expresa y un
  cambio de código revisado.
