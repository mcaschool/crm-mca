# Checklist de despliegue seguro — CRM-MCA (Hostinger compartido)

> Documento operativo para exponer el panel en producción. Recórrelo de arriba abajo.
> Sustituye los marcadores `USUARIO`, `DOMINIO`, `RUTA_BASE` por los valores reales del
> hosting. `RUTA_BASE` suele ser `/home/USUARIO/domains/DOMINIO/public_html`.

---

## 0. Antes de subir nada (higiene del repositorio)

- [x] **`.env` NO está en el repo**: `.env`, `.env.backup`, `.env.production` están en `.gitignore` y ninguno está rastreado por git. Solo se versiona `.env.example` (sin secretos). — *verificado en este bloque.*
- [ ] Confirmar en el server que subes el código **sin** `.env`, `node_modules/`, `.git/` ni `storage/*.key`.
- [ ] Ningún secreto (API key, contraseña, token) hardcodeado en código, blades, JSON exportable ni workflows. Todo va cifrado en la BD (integraciones) o en `.env`.

---

## 1. Variables de entorno de producción (`.env` en el server)

Crear el `.env` directamente en el server (nunca subirlo por git). Valores mínimos:

```dotenv
APP_NAME="MCA School"
APP_ENV=production
APP_DEBUG=false
APP_KEY=                      # generar en el server: php artisan key:generate
APP_URL=https://DOMINIO

# Base de datos (usuario dedicado de Hostinger, permisos mínimos sobre su BD)
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=xxx
DB_USERNAME=xxx
DB_PASSWORD=xxx

# Sesión / cookies seguras
SESSION_DRIVER=database        # en compartido evita depender de ficheros; requiere migración de sesiones
SESSION_SECURE_COOKIE=true     # cookie solo por HTTPS
SESSION_HTTP_ONLY=true         # (por defecto ya true) no accesible por JS
SESSION_SAME_SITE=lax
SESSION_ENCRYPT=true           # cifra el contenido de sesión en reposo

# Multi-institución: DORMANTE (una institución por instalación)
CRM_MULTI_INSTITUTION=false

# Retención de mensajes (purga por cron). 24 meses por defecto.
CRM_MESSAGE_RETENTION_MONTHS=24

# Log en producción
LOG_LEVEL=warning
```

Verificaciones:
- [ ] `APP_ENV=production` y `APP_DEBUG=false` (nunca `true` en producción: filtra stack traces y datos).
- [ ] `APP_KEY` generada (`php artisan key:generate`). Sin ella, las columnas cifradas (integraciones, `national_id`, secretos 2FA) no funcionan.
- [ ] `APP_URL` con `https://`.
- [ ] `SESSION_SECURE_COOKIE=true` (la config lee `env('SESSION_SECURE_COOKIE')`; en local se deja sin definir).

---

## 2. HTTPS forzado, cookies seguras y headers de seguridad

- [ ] **SSL activo** para el dominio (hPanel → SSL) y **"Forzar HTTPS"** activado.
- [ ] Con `SESSION_SECURE_COOKIE=true` + `SESSION_HTTP_ONLY=true` + `SESSION_SAME_SITE=lax`, la cookie de sesión viaja solo por HTTPS, no es accesible por JS y mitiga CSRF entre sitios.
- [ ] **Headers de seguridad** vía `.htaccess` en `RUTA_BASE/public/.htaccess` (Apache de Hostinger). Añadir dentro de `<IfModule mod_headers.c>`:

```apache
<IfModule mod_headers.c>
    Header always set X-Content-Type-Options "nosniff"
    Header always set X-Frame-Options "SAMEORIGIN"
    Header always set Referrer-Policy "strict-origin-when-cross-origin"
    Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains"
    # Permissions-Policy mínima
    Header always set Permissions-Policy "geolocation=(), microphone=(), camera=()"
</IfModule>
```

> Nota: el widget se embebe en la web de MCA. `X-Frame-Options: SAMEORIGIN` protege el
> PANEL. Si el widget se sirviera desde otro dominio y necesitara iframe cruzado, se
> ajustaría con `Content-Security-Policy: frame-ancestors` en la ruta del widget, no
> aquí. Revisar tras el primer embed real (ver §6).

---

## 3. Ninguna ruta del panel sin autenticación (verificado)

- [x] Todo el grupo panel vive tras `['auth', 'institution.user', 'setlocale', 'can:access-panel', EnsureTwoFactorEnabled]`. La prueba automatizada **`tests/Feature/Security/PanelRoutesGuardTest.php`** recorre TODAS las rutas con `can:access-panel` y confirma que un invitado es redirigido a `/login`. Si se añade una ruta al panel sin `auth`, la suite falla. — *verificado en este bloque.*
- [ ] Reejecutar `php artisan test --filter=PanelRoutesGuard` tras cualquier cambio de rutas.

---

## 4. Pasos de despliegue en el server

```bash
cd RUTA_BASE

# 1) Dependencias de producción (sin dev)
composer install --no-dev --optimize-autoloader

# 2) Clave de app (si es primera vez)
php artisan key:generate

# 3) Migraciones. La tabla `sessions` ya está en la migración base (SESSION_DRIVER=database
#    funciona sin pasos extra).
php artisan migrate --force

# 4) Enlace de almacenamiento público (avatares, etc.)
php artisan storage:link

# 5) Cachés de producción (rendimiento)
php artisan config:cache
php artisan route:cache
php artisan view:cache

# 6) Build de assets (si se compila en el server; normalmente se sube ya compilado)
#    npm ci && npm run build
```

- [ ] **Permisos de carpetas**: `storage/` y `bootstrap/cache/` escribibles por el usuario de PHP (típicamente `755`/`775`; en Hostinger el usuario ya es el propietario).
- [ ] Tras cualquier cambio de `.env` o rutas en producción: `php artisan config:clear && php artisan config:cache` (idem `route:cache`, `view:cache`).
- [ ] El `DocumentRoot` del dominio apunta a `RUTA_BASE/public` (no a la raíz del proyecto).

---

## 5. Cron de la purga por retención

**Qué purga** (política ya definida, no inventar): solo la tabla `messages` (mensajes de
conversación) con `created_at` anterior a `CRM_MESSAGE_RETENTION_MONTHS` (24 meses por
defecto). **Nunca** contactos, leads, eventos ni conversaciones. Respeta el scoping por
institución y **audita** cada ejecución real (`retention.purged`, con el conteo, sin los
valores borrados). El comando admite `--dry-run` (informa sin borrar).

Elegir **UNA** de las dos opciones (no ambas, para no duplicar ejecución):

### Opción A (recomendada) — Scheduler de Laravel

Ya está programado (`routes/console.php`): `crm:purge-retention` corre **el día 1 de cada
mes a las 03:30**. En el panel de cron de Hostinger, una sola entrada que dispara el
scheduler cada minuto:

```cron
* * * * * cd RUTA_BASE && /usr/bin/php artisan schedule:run >> /dev/null 2>&1
```

### Opción B (alternativa) — Llamada directa (si el plan NO permite cron por minuto)

Programar directamente el comando (día 1 de cada mes, 03:30):

```cron
30 3 1 * * cd RUTA_BASE && /usr/bin/php artisan crm:purge-retention >> RUTA_BASE/storage/logs/retention-purge.log 2>&1
```

Notas:
- Ajustar `/usr/bin/php` al binario **PHP 8.3** real del hosting (hPanel → PHP; o `which php8.3`).
- En instalación de una sola institución no hacen falta flags. Con varias, usar
  `--all-institutions` o `--institution=ID`.
- **Verificar primero en seco** desde SSH: `php artisan crm:purge-retention --dry-run`
  (muestra cuántos mensajes borraría, por institución, sin tocar nada).

---

## 6. Pruebas de humo post-deploy (en el server, no solo local)

- [ ] **IA (Qwen) conecta DESDE el server**: panel → Integraciones → *Probar conexión* del
  proveedor de IA. Debe dar OK. Si falla con timeout, revisar §7 (llamadas salientes).
- [ ] **Widget embebe bien**: abrir la landing real con el widget de Celia, comprobar que
  carga, permite captura de nombre/correo y responde (guiado + Celia).
- [ ] **Login con 2FA (humo)**: iniciar sesión con un usuario real → pide el código TOTP →
  entra al panel. Confirmar que un código incorrecto NO entra. (No resetear credenciales
  para probar; usar una cuenta real de prueba.)
- [ ] **Auditoría** (panel → Auditoría, solo Admin): confirmar que el login recién hecho
  aparece registrado con actor/acción/IP.

---

## 7. Límites de hosting compartido de Hostinger — qué vigilar

El hosting compartido tiene cuotas por cuenta. Vigilar tras desplegar:

### Conexiones MySQL (incidente ya observado en desarrollo)
- El compartido limita **conexiones simultáneas a MySQL** (`max_user_connections`). Procesos
  concurrentes que abran muchas conexiones pueden tumbar la BD (`ERROR 2002 / connection
  refused`, como pasó al correr suites de test en paralelo en local).
- Mitigaciones ya aplicadas / a mantener:
  - **No** ejecutar tareas concurrentes pesadas contra la BD (evitar solapar cron y
    procesos manuales; el scheduler usa `withoutOverlapping()`).
  - Mantener **una sola** entrada de cron (§5), no varias que arranquen a la vez.
  - No abrir workers/daemons permanentes (no los hay: el chatbot es petición-respuesta).
- Verificar en el server: hPanel → Bases de datos → uso; o `SHOW STATUS LIKE 'Threads_connected';`.

### Llamadas salientes (Qwen y otros proveedores)
- Algunos planes compartidos **restringen o ralentizan** conexiones HTTP salientes. Si
  *Probar conexión* de Qwen falla desde el server pero funciona en local, es señal de esto.
- Verificar desde SSH: `curl -I https://<endpoint-de-qwen>` — debe responder. Si no,
  abrir ticket a Hostinger para habilitar el destino / revisar firewall de salida.
- Confirmar que el **timeout** de las llamadas de IA es razonable (no cuelga la petición
  del usuario si el proveedor tarda).

### Recursos (CPU/RAM/procesos)
- El compartido limita procesos y memoria por cuenta (`entry processes`, `EP`). Evitar
  comandos artisan pesados en horas pico; la purga corre de madrugada (03:30) por eso.
- Cachés de producción activas (§4) para reducir CPU por request.
- Vigilar `storage/logs/` (rotación): con `LOG_LEVEL=warning` el volumen baja.

---

## 8. Repaso final

- [ ] `APP_DEBUG=false`, `APP_ENV=production`, HTTPS forzado, cookies seguras.
- [ ] Cachés (`config`/`route`/`view`) generadas.
- [ ] Cron de purga configurado (una sola opción) y `--dry-run` probado en el server.
- [ ] Humo: Qwen OK desde server, widget OK, login 2FA OK, auditoría registrando.
- [ ] Suite verde en local antes de desplegar (`php artisan test`).
