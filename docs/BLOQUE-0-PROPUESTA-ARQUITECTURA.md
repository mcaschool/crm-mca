# Bloque 0 — Propuesta de arquitectura (para revisión)

> Estado: **PROPUESTA. No aprobada. No se ha escrito código.**
> Base: `CLAUDE.md` del proyecto (visión, principios y decisiones cerradas).
> Fecha: 2026-08-15

---

## 1. Stack afinado

### 1.1 Núcleo

| Pieza | Elección | Por qué |
|---|---|---|
| PHP | **8.3** (código compatible con 8.4) | Es lo que exige Laravel 13 y lo que Hostinger ofrece de forma estable. 8.3 está disponible en tu Laragon local, así que dev y producción coinciden. |
| Framework | **Laravel 13.x** (última estable) | Decisión ya cerrada en `CLAUDE.md`. Ya lo estás corriendo en SIGAC-MCA, con el mismo toolchain. |
| BD | **MySQL 8 / MariaDB 10.6+** | Decisión cerrada. Ver §4: la posible MariaDB en Hostinger condiciona la estrategia de bilingüismo. |
| Interfaz del panel | **Livewire 4 + Blade + Tailwind CSS 4** | Renderizado en servidor: un solo despliegue, sin SPA, sin build de Node en el hosting. Mismo stack que SIGAC-MCA. |
| Widget del chat | **JS vanilla + Vite** (bundle único autocontenido) | Debe cargar rápido y embebido en una landing ajena. Sin framework, sin dependencias externas, sin CSS que se filtre a la página anfitriona (Shadow DOM). |
| Modularidad | **`nwidart/laravel-modules` ^13** | Ya probado en SIGAC-MCA. Permite que cada bloque sea una carpeta añadible. |

### 1.2 Paquetes recomendados por área

**Autenticación y roles (Admin / Marketing / Admisiones)**

- **Auth: `laravel/breeze` (stack Livewire)** — scaffolding de login, recuperación de contraseña y verificación. Se instala, se adapta y se queda; no es una dependencia en runtime.
- **Roles: NADA. Enum nativo + Policies/Gates de Laravel.**
  Razón: el `CLAUDE.md` ya cierra el modelo con `users.role (Admin/Marketing/Admisiones)` — tres roles fijos, sin permisos granulares en el alcance del día 1. Meter `spatie/laravel-permission` aquí añade 5 tablas, y en un sistema multi-institución obliga a activar el modo *teams*, que complica el scope global y las cachés de permisos. Se resuelve con un `enum UserRole` tipado + Policies por modelo.
  **Ruta de escape documentada:** si más adelante hace falta permiso por acción (p. ej. "Marketing puede ver leads pero no exportarlos"), se migra a `spatie/laravel-permission` sin tocar el código de negocio, porque toda autorización pasará por `Gate::authorize()` / Policies desde el día 1, nunca por `if ($user->role === 'admin')` disperso.
- **2FA:** fuera del alcance del día 1. Si lo quieres, `pragmarx/google2fa` (ya usado en SIGAC-MCA). Decisión tuya.

**Cifrado de credenciales**

- **NADA. El cifrado nativo de Laravel** (`Crypt`, AES-256-GCM con `APP_KEY`) mediante el cast `encrypted:array` en `integrations.config`. Ver §5.
- Ningún paquete de terceros toca los secretos. Menos superficie, menos confianza delegada.

**Localización bilingüe (es/en)**

- **Textos de interfaz: localización nativa de Laravel** (`lang/es/*.php`, `lang/en/*.php`). Decisión ya cerrada en `CLAUDE.md`.
- **Contenido administrable: columnas `_es` / `_en` + trait propio `HasTranslatedColumns`.** Sin paquete. Ver §4 para la justificación completa.

**Utilidades**

| Necesidad | Paquete | Nota |
|---|---|---|
| Importar el Excel del catálogo | `openspout/openspout` ^5 | Streaming, bajo consumo de memoria. Ya usado en SIGAC-MCA. |
| Llamadas HTTP a proveedores de IA | `Illuminate\Support\Facades\Http` (Guzzle, ya viene) | No se instalan SDKs de OpenAI/Gemini/Anthropic: cada SDK amarra a un proveedor y contradice la capa agnóstica. Un driver propio por proveedor sobre `Http` es ~80 líneas y se controla el timeout, el reintento y el registro de coste. |
| Calidad | `larastan`, `pint`, `pest` (dev) | Mismo estándar que SIGAC-MCA. |

### 1.3 Compatibilidad con Hostinger compartido — confirmación y excepciones

Todo lo anterior es **petición-respuesta puro**: no requiere procesos persistentes, ni websockets, ni Redis, ni Node en el servidor.

**Excepciones y verificaciones que hay que hacer contra tu plan real de Hostinger:**

1. **Node/npm no existe en el hosting.** El build de Vite (panel + widget) se hace en local y se sube `public/build/` ya compilado. No es un problema, es un paso del despliegue (§8).
2. **Colas:** driver `database`, nunca `redis`. El worker no es un demonio: corre acotado por tiempo desde el cron (§8).
3. **Caché y sesiones:** driver `database` (o `file`), nunca Redis/Memcached.
4. **`exec` / `proc_open` suelen estar deshabilitados** en compartido. Ningún componente propuesto los necesita. Sí lo necesitaría un `mysqldump` desde PHP: los backups se hacen por cron de shell, no desde la app.
5. **Salida HTTP hacia internet debe estar permitida** (obligatorio: las llamadas a los proveedores de IA). Hostinger la permite en compartido, pero **hay que verificarlo antes de cerrar el Bloque 0** con una prueba real.
6. **`max_execution_time`** (típicamente 60–120 s en compartido) es el techo real de una respuesta de Celia. Se mitiga con timeout explícito de 20–25 s por llamada a IA y respuesta de cortesía si expira. Riesgo detallado en §9.
7. **La versión de PHP del CLI (cron) puede no ser la misma que la de la web** en Hostinger. El cron debe invocar la ruta absoluta del binario correcto (`/usr/bin/php8.3`), no `php` a secas.

---

## 2. Estructura del repositorio

### 2.1 Principio

Monolito modular. Una sola aplicación desplegable, pero el código organizado **por funcionalidad, no por tipo de archivo**. Cada bloque del mapa de módulos (`CLAUDE.md` §9) es una carpeta autocontenida bajo `Modules/`, con sus propias migraciones, rutas, vistas, servicios y pruebas. Añadir un bloque = añadir una carpeta, sin cirugía en los anteriores.

### 2.2 Mapeo bloque → módulo (1:1)

| Bloque | Módulo | Contenido |
|---|---|---|
| 0 | `Core` | Clases base, `BelongsToInstitution`, `HasTranslatedColumns`, excepciones, enums compartidos, contexto de institución. |
| 0 | `Institutions` | `institutions`, `bots`, resolución de tenant. |
| 1 | `Identity` | `users`, roles, login, cascarón del panel, layout, navegación. |
| 2 | `Integrations` | `integrations`, `ai_process_configs`, cifrado, enmascarado, probador de credenciales. |
| 3 | `Catalog` | `programs`, `program_categories`, `program_tags`, importador de Excel. |
| 4 | `Crm` | `contacts`, `leads`, `conversations`, `messages`, `events`, `program_interests`. |
| 5 | `Chat` | API pública del widget, motor de navegación guiada, emparejador determinista, `conversation_nodes`, `conversation_options`, widget embebible. |
| 6 | `Ai` | Contrato `AiProvider`, drivers por proveedor, `knowledge_sources`, ensamblado de contexto (Forma A), Celia. |
| 7 | `Notifications` | Correo transaccional, plantillas bilingües. |
| — | `Audit` | `audit_logs` append-only (transversal, se crea en Bloque 0, se usa desde el 2). |

### 2.3 Árbol de carpetas

```
CRM-MCA/
├── app/                       # Solo lo que es del framework, delgadísimo
│   ├── Providers/
│   └── Models/User.php        # Extendido desde Modules/Identity
├── bootstrap/
├── config/
│   ├── crm.php                # Config propia del dominio (idiomas, niveles, metas, áreas)
│   └── modules.php
├── database/
│   ├── migrations/            # Solo tablas del framework (users, jobs, sessions, cache)
│   └── seeders/DatabaseSeeder.php
├── lang/
│   ├── es/                    # Textos de interfaz
│   └── en/
├── Modules/
│   ├── Core/
│   ├── Institutions/
│   ├── Identity/
│   ├── Integrations/
│   ├── Catalog/
│   ├── Crm/
│   ├── Chat/
│   ├── Ai/
│   ├── Notifications/
│   └── Audit/
├── public/                    # ÚNICO directorio expuesto a la web
│   └── build/                 # Assets compilados (se suben ya construidos)
├── resources/
│   ├── js/widget/             # Fuente del widget embebible
│   └── views/layouts/
├── routes/
├── storage/
├── tests/
├── docs/
│   └── BLOQUE-0-PROPUESTA-ARQUITECTURA.md
├── .env.example
└── CLAUDE.md
```

### 2.4 Estructura interna de cada módulo

```
Modules/<Nombre>/
├── Contracts/                 Interfaces públicas del módulo (lo único que otros módulos consumen)
├── Models/                    Entidades Eloquent
├── Services/                  Lógica de negocio
├── Enums/
├── Http/
│   ├── Controllers/           Delgados: solo traducen HTTP ↔ servicio
│   ├── Requests/              Validación en el borde
│   ├── Resources/             Serialización de salida (aquí se controla qué se expone)
│   └── Livewire/              Pantallas del panel
├── Providers/
├── routes/                    web.php, api.php propios
├── database/migrations/
├── database/seeders/
├── resources/views/
├── lang/                      Textos propios del módulo
└── tests/
```

### 2.5 Reglas de arquitectura (no negociables una vez aprobadas)

1. La lógica de negocio vive en **servicios**. Nunca en controladores, vistas, componentes Livewire ni migraciones.
2. Los módulos se comunican **por interfaces** (`Contracts/`) resueltas por el contenedor. Un módulo **nunca** consulta las tablas de otro directamente.
   Ejemplo: `Chat` no hace `Program::query()`; pide `CatalogService::match(...)` a través de `Modules\Catalog\Contracts\ProgramMatcher`.
3. Toda autorización pasa por **Policies / Gates**. Nunca `if ($user->role === ...)` disperso.
4. Toda salida hacia el widget o la API pasa por un **API Resource**. Nunca `return $model`.
5. Migraciones versionadas y **reversibles** (`down()` real).
6. Índices en toda FK y en toda columna usada en `WHERE` / `ORDER BY` / `JOIN`.
7. Zona horaria: **UTC en base de datos**, presentación en la zona configurada por institución.

---

## 3. Modelo de datos en migraciones (esquema propuesto)

### 3.0 Convenciones

- **Claves primarias:** `BIGINT UNSIGNED AUTO_INCREMENT`. **No UUID.**
  Razón: `messages` y `events` serán las tablas grandes (cientos de miles de filas al año); un UUID como PK en InnoDB fragmenta el índice agrupado y multiplica por ~3 el tamaño de los índices secundarios. El riesgo de enumeración no aplica porque **ningún id interno se expone jamás**: el widget habla por `bots.public_key` y `conversations.session_id`, ambos tokens opacos aleatorios.
- **`institution_id` en TODAS las tablas de dominio**, incluidas las que cuelgan de otra (`program_tags`, `conversation_options`, `messages`). Cuesta 8 bytes por fila y compra que el scope global sea universal, sin excepciones que recordar. Esta es la instrucción explícita del Bloque 0 y la respeto al pie de la letra.
- **Enums de dominio:** `VARCHAR` + validación por `config/crm.php` + enum de PHP, **no** `ENUM` de MySQL. Cambiar un valor no debe requerir `ALTER TABLE` en producción. Excepción: enums realmente cerrados por producto (`language`, `mode`, `sender_type`).
- **Bilingüe:** columnas hermanas `_es` / `_en`. Ver §4.
- **Timestamps:** `created_at` / `updated_at` salvo en tablas append-only de alto volumen (`messages`, `events`, `audit_logs`), que llevan solo `created_at`.

### 3.1 Tablas del framework

`users` (extendida, §3.3), `password_reset_tokens`, `sessions`, `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs`.

### 3.2 Multi-institución

**`institutions`**

| Columna | Tipo | Notas |
|---|---|---|
| `id` | bigint UN PK AI | |
| `name` | varchar(150) | |
| `slug` | varchar(80) | UNIQUE |
| `status` | varchar(20) | `active` / `inactive`, default `active` |
| `timezone` | varchar(64) | default `America/New_York` |
| `default_language` | char(2) | `es` / `en`, default `es` |
| `created_at`, `updated_at` | timestamp | |

### 3.3 Acceso al panel

**`users`**

| Columna | Tipo | Notas |
|---|---|---|
| `id` | bigint UN PK AI | |
| `institution_id` | bigint UN FK → institutions | `restrict` |
| `name` | varchar(120) | |
| `email` | varchar(190) | **UNIQUE global** (ver nota) |
| `password` | varchar(255) | hash bcrypt/argon2 |
| `role` | varchar(20) | `admin` / `marketing` / `admissions` |
| `is_super_admin` | boolean | default `false` — ver **Duda D1** en §9 |
| `preferred_language` | char(2) | `es` / `en`, default `es` — **añadido propuesto**, el `CLAUDE.md` no lo lista pero el panel bilingüe lo necesita |
| `status` | varchar(20) | `active` / `inactive` |
| `last_login_at` | timestamp null | |
| `remember_token`, `created_at`, `updated_at` | | |

Índices: `UNIQUE(email)`, `INDEX(institution_id, status)`.

> **Nota sobre `email`:** para los **usuarios del panel** propongo unicidad **global**, no por institución. Si fuera `(institution_id, email)`, el formulario de login tendría que preguntar además a qué institución entras, lo cual es ruido para un sistema que hoy tiene una sola institución. La unicidad por `(institution_id, email)` del `CLAUDE.md` aplica a **`contacts`**, que es donde importa, y ahí se respeta literalmente.

### 3.4 Bots y configuración

**`bots`**

| Columna | Tipo | Notas |
|---|---|---|
| `id` | bigint UN PK AI | |
| `institution_id` | bigint UN FK | |
| `name` | varchar(120) | "Bot Microcredenciales" |
| `slug` | varchar(80) | UNIQUE`(institution_id, slug)` |
| `assistant_name` | varchar(60) | "Celia" |
| `landing_url` | varchar(255) | |
| `public_key` | char(32) | **UNIQUE**, aleatorio. Es lo que el `<script>` del widget lleva embebido. **Añadido propuesto**: sin esto, el widget tendría que exponer un id interno. |
| `allowed_origins` | json | Lista blanca de orígenes para CORS. **Añadido propuesto** (seguridad del widget). |
| `default_language` | char(2) | `es` |
| `status` | varchar(20) | |
| `created_at`, `updated_at` | | |

**`integrations`**

| Columna | Tipo | Notas |
|---|---|---|
| `id` | bigint UN PK AI | |
| `institution_id` | bigint UN FK | |
| `type` | varchar(30) | `ai_provider` / `google` / `n8n` / `mailrelay` / `smtp` / `stripe` / `moodle` |
| `provider` | varchar(40) null | `openai` / `gemini` / `anthropic` / `deepseek` / `qwen` / `kimi` |
| `name` | varchar(120) | Etiqueta legible |
| `config` | text | **JSON CIFRADO** (cast `encrypted:array`). Único lugar donde viven los secretos. |
| `config_preview` | json | Valores **enmascarados** y metadatos no sensibles (`{"api_key":"sk-••••8Jk2","base_url":"https://..."}`). Se calcula al guardar. Ver §5. |
| `status` | varchar(20) | `active` / `inactive` / `error` |
| `last_tested_at` | timestamp null | |
| `last_test_ok` | boolean null | |
| `last_test_message` | varchar(255) null | Mensaje **saneado**, nunca el cuerpo crudo del error |
| `created_at`, `updated_at` | | |

Índices: `UNIQUE(institution_id, type, name)`, `INDEX(institution_id, type, status)`.

**`ai_process_configs`**

| Columna | Tipo | Notas |
|---|---|---|
| `id` | bigint UN PK AI | |
| `institution_id` | bigint UN FK | |
| `bot_id` | bigint UN FK **null** | `null` = configuración por defecto de la institución |
| `process` | varchar(30) | `conversation` / `classification` / `summary` / `email_draft` |
| `integration_id` | bigint UN FK → integrations | `restrict` |
| `model` | varchar(100) | `gpt-5-mini`, `gemini-2.5-flash`, … |
| `params` | json null | temperatura, max_tokens, timeout |
| `status` | varchar(20) | |
| `created_at`, `updated_at` | | |

Índices: `UNIQUE(institution_id, bot_id, process)`.

### 3.5 CRM Core

**`contacts`**

| Columna | Tipo | Notas |
|---|---|---|
| `id` | bigint UN PK AI | |
| `institution_id` | bigint UN FK | |
| `first_name` | varchar(80) | |
| `last_name` | varchar(80) null | Captura mínima = nombre + correo |
| `email` | varchar(190) | |
| `phone` | varchar(30) null | |
| `country` | char(2) null | ISO-3166-1 alpha-2 |
| `preferred_language` | char(2) | `es` / `en` |
| `consent_at` | timestamp null | **Añadido propuesto** — ver **Duda D2** (RGPD) |
| `consent_source` | varchar(60) null | **Añadido propuesto** |
| `unsubscribed_at` | timestamp null | **Añadido propuesto** — necesario antes de campañas |
| `created_at`, `updated_at` | | |

Índices: **`UNIQUE(institution_id, email)`** ← invariante del `CLAUDE.md`. `INDEX(institution_id, created_at)`.

**`leads`**

| Columna | Tipo | Notas |
|---|---|---|
| `id` | bigint UN PK AI | |
| `institution_id` | bigint UN FK | |
| `contact_id` | bigint UN FK → contacts | `cascade` |
| `bot_id` | bigint UN FK → bots | |
| `product_type` | varchar(40) | `microcredential` (día 1) |
| `program_id` | bigint UN FK null → programs | `set null` |
| `area` | varchar(80) null | Respuesta capturada, no necesariamente una categoría |
| `goal` | varchar(80) null | |
| `level` | varchar(40) null | |
| `source` | varchar(60) | `widget_microcredenciales`, … |
| `status` | varchar(30) | Ver **Duda D3** |
| `interest_level` | varchar(20) | `low` / `medium` / `high` |
| `created_at`, `updated_at` | | |

Índices: `INDEX(institution_id, status, created_at)`, `INDEX(institution_id, contact_id)`, `INDEX(institution_id, bot_id, created_at)`, `INDEX(program_id)`.

**`conversations`**

| Columna | Tipo | Notas |
|---|---|---|
| `id` | bigint UN PK AI | |
| `institution_id` | bigint UN FK | |
| `contact_id` | bigint UN FK **null** | Null hasta que el usuario da nombre+correo |
| `bot_id` | bigint UN FK | |
| `session_id` | char(36) | **UNIQUE**. Token opaco que vive en el `localStorage` del navegador |
| `channel` | varchar(20) | `web` |
| `mode` | varchar(10) | `guided` / `celia` |
| `language` | char(2) | `es` / `en` |
| `status` | varchar(20) | `open` / `closed` / `abandoned` |
| `current_node_id` | bigint UN FK null | **Añadido propuesto**: sin esto la recuperación de sesión no sabe dónde retomar |
| `started_at`, `last_activity_at` | timestamp | |
| `created_at`, `updated_at` | | |

Índices: `UNIQUE(session_id)`, `INDEX(institution_id, bot_id, last_activity_at)`, `INDEX(contact_id)`.

**`messages`** (append-only, alto volumen)

| Columna | Tipo | Notas |
|---|---|---|
| `id` | bigint UN PK AI | |
| `institution_id` | bigint UN FK | |
| `conversation_id` | bigint UN FK | `cascade` |
| `sender_type` | varchar(10) | `user` / `system` / `celia` |
| `content` | mediumtext | |
| `message_type` | varchar(30) | `text` / `menu` / `program_list` / `link` / `form` |
| `meta` | json null | **Añadido propuesto y clave**: `{provider, model, prompt_tokens, completion_tokens, latency_ms, cost_usd}`. Sin esto **no se puede calcular el AI Deflection Rate** que el `CLAUDE.md` §3 define como métrica clave. |
| `created_at` | timestamp | |

Índices: `INDEX(conversation_id, id)`, `INDEX(institution_id, created_at)`.

**`events`** (append-only, alto volumen)

| Columna | Tipo | Notas |
|---|---|---|
| `id` | bigint UN PK AI | |
| `institution_id` | bigint UN FK | |
| `contact_id` | bigint UN FK null | |
| `conversation_id` | bigint UN FK null | |
| `bot_id` | bigint UN FK null | |
| `event_type` | varchar(60) | `lead.captured`, `program.viewed`, `celia.started`, `celia.unresolved`, … |
| `event_data` | json | |
| `created_at` | timestamp | |

Índices: `INDEX(institution_id, event_type, created_at)`, `INDEX(conversation_id)`, `INDEX(contact_id)`.

> Las **"preguntas no resueltas"** de Celia (`CLAUDE.md` §3) se registran como `event_type = 'celia.unresolved'`. No propongo tabla propia en el Bloque 0; cuando llegue el módulo de administración del conocimiento se evaluará promoverlas a tabla con estado (`pendiente` / `resuelta`).

**`program_interests`**

| Columna | Tipo | Notas |
|---|---|---|
| `id` | bigint UN PK AI | |
| `institution_id` | bigint UN FK | |
| `contact_id` | bigint UN FK | |
| `program_id` | bigint UN FK | |
| `bot_id` | bigint UN FK | |
| `source` | varchar(40) | `matcher` / `menu` / `celia` |
| `created_at` | timestamp | |

Índices: `INDEX(institution_id, program_id, created_at)`, `INDEX(contact_id, program_id)`. **Sin UNIQUE**: el interés repetido es señal comercial, no duplicado.

### 3.6 Catálogo

**`program_categories`**

| Columna | Tipo |
|---|---|
| `id`, `institution_id` | |
| `name_es`, `name_en` | varchar(120) |
| `slug` | varchar(80) |
| `display_order` | smallint |
| `status` | varchar(20) |
| `created_at`, `updated_at` | |

Índices: `UNIQUE(institution_id, slug)`.

**`programs`**

| Columna | Tipo | Notas |
|---|---|---|
| `id`, `institution_id` | | |
| `code` | varchar(40) | Código del Excel |
| `name_es`, `name_en` | varchar(200) | |
| `credential_en` | varchar(200) null | Semilla del `name_en` según `CLAUDE.md` §7 |
| `category_id` | bigint UN FK null | = "área" |
| `level` | varchar(40) null | |
| `goal` | varchar(80) null | |
| `profile` | varchar(120) null | |
| `duration_es`, `duration_en` | varchar(80) null | |
| `modality_es`, `modality_en` | varchar(80) null | |
| `short_description_es`, `short_description_en` | text null | |
| `url` | varchar(500) | Enlace a la ficha en la web (fuente de la verdad) |
| `status` | varchar(20) | |
| `display_order` | smallint | |
| `created_at`, `updated_at` | | |

Índices: `UNIQUE(institution_id, code)`, `INDEX(institution_id, status, category_id)`, `INDEX(institution_id, level)`, `INDEX(institution_id, goal)`.

> **Nota:** ni `price` ni `syllabus`. Es deliberado: `CLAUDE.md` §5 dice que precio y temario viven en la web, y el sistema enlaza, no recita.

**`program_tags`**

`id`, `institution_id`, `program_id` (FK cascade), `tag` varchar(50).
Índices: `UNIQUE(program_id, tag)`, `INDEX(institution_id, tag)`.

### 3.7 Conocimiento de Celia

**`knowledge_sources`**

| Columna | Tipo | Notas |
|---|---|---|
| `id`, `institution_id`, `bot_id` | | |
| `name` | varchar(150) | |
| `code` | varchar(60) | |
| `type` | varchar(40) | `faq` / `policy` / `procedure` / `general` |
| `category` | varchar(60) null | |
| `program_id` | bigint UN FK null | |
| `url` | varchar(500) null | |
| `content_es`, `content_en` | longtext null | |
| `priority` | smallint | Orden de ensamblado en el contexto (Forma A) |
| `status` | varchar(20) | |
| `last_synced_at` | timestamp null | |
| `created_at`, `updated_at` | | |

Índices: `UNIQUE(institution_id, bot_id, code)`, `INDEX(institution_id, bot_id, status, priority)`.

### 3.8 Constructor conversacional

**`conversation_nodes`**

| Columna | Tipo | Notas |
|---|---|---|
| `id`, `institution_id`, `bot_id` | | |
| `key` | varchar(60) | Identificador estable usado por el código (`welcome`, `main_menu`) |
| `type` | varchar(30) | `message` / `menu` / `program_list` / `form` / `action` / `start_celia` / `external_link` |
| `content_es`, `content_en` | text | |
| `config` | json null | **Añadido propuesto**: parámetros del nodo (filtros del `program_list`, campos del `form`) |
| `display_order` | smallint | |
| `status` | varchar(20) | |
| `created_at`, `updated_at` | | |

Índices: `UNIQUE(institution_id, bot_id, key)`.

**`conversation_options`**

| Columna | Tipo |
|---|---|
| `id`, `institution_id` | |
| `node_id` | bigint UN FK cascade |
| `label_es`, `label_en` | varchar(150) |
| `target_node_id` | bigint UN FK null (`set null`) |
| `action` | varchar(50) null |
| `event_type` | varchar(60) null |
| `display_order` | smallint |
| `created_at`, `updated_at` | |

Índices: `INDEX(node_id, display_order)`.

### 3.9 Auditoría (añadido propuesto)

**`audit_logs`** (append-only)

`id`, `institution_id`, `user_id` (null), `action` varchar(60), `auditable_type` varchar(120), `auditable_id` bigint, `changes` json (**con secretos redactados**), `ip` varchar(45), `created_at`.

Justificación: el `CLAUDE.md` no la lista, pero el Bloque 2 guarda credenciales cifradas de terceros. Sin un rastro de *quién cambió qué credencial y cuándo*, un incidente de seguridad es inauditable. Coste: una tabla y un observer. **Recomiendo incluirla en el Bloque 0.**

### 3.10 Orden y organización de las migraciones

Cada migración vive en `Modules/<Nombre>/database/migrations/`. `php artisan migrate` las ordena globalmente por el *timestamp* del nombre de archivo, así que la secuencia se garantiza nombrando en este orden:

```
1. institutions
2. users (extiende la del framework)
3. bots
4. integrations, ai_process_configs
5. program_categories, programs, program_tags
6. contacts, conversations, leads, messages, events, program_interests
7. conversation_nodes, conversation_options   (FK conversations.current_node_id se añade aquí)
8. knowledge_sources
9. audit_logs
```

Toda migración con `down()` real. Criterio de cierre del bloque: `php artisan migrate:fresh --seed` y `php artisan migrate:rollback` completos sin error.

---

## 4. Estrategia de bilingüismo (i18n) — decisión abierta que cierro aquí

### 4.1 Textos de interfaz

Localización nativa de Laravel. `lang/es/*.php` y `lang/en/*.php`, más `Modules/<X>/lang/` para lo propio de cada módulo. Nunca texto literal en Blade. El idioma se resuelve así:

- **Panel:** `users.preferred_language`, con selector en la barra superior.
- **Widget / API pública:** cabecera `Accept-Language` o el parámetro explícito del widget → `App::setLocale()` vía middleware `SetLocale`.
- **Correo transaccional:** `contacts.preferred_language`, forzado con `Mail::locale()`.

Esto no tiene discusión: ya está cerrado en `CLAUDE.md`.

### 4.2 Contenido almacenado — **RECOMIENDO COLUMNAS `_es` / `_en`**

Las dos opciones que dejaste abiertas:

| | Columnas `_es` / `_en` | Columna JSON `{"es":…,"en":…}` |
|---|---|---|
| Añadir un 3.º idioma | Migración en ~8 tablas | Gratis |
| Índices y `ORDER BY name_es` | Nativo, trivial | Requiere columnas generadas |
| Búsqueda FULLTEXT por idioma | Nativo | Frágil / no soportado igual |
| `UNIQUE`, `NOT NULL` por idioma | Nativo | No se puede a nivel de BD |
| Importación desde Excel | Mapeo 1:1 con las columnas | Hay que construir el JSON |
| Legibilidad al depurar en SQL | Total | Baja |
| **Comportamiento en MariaDB** | Idéntico a MySQL | **`JSON` es un alias de `LONGTEXT`; los índices funcionales sobre rutas JSON son más limitados** |
| Coste en el esquema | Más columnas | Menos columnas |

**Recomiendo columnas `_es` / `_en`. Las tres razones que pesan:**

1. **Son dos idiomas fijos por decisión de producto**, no una lista abierta. `CLAUDE.md` §4 lo dice literal: *"dos idiomas fijos —español e inglés— desde el inicio"*. La única ventaja real del JSON (añadir idiomas gratis) es precisamente la que el producto ha declarado que no necesita. Pagar la complejidad de JSON por una flexibilidad descartada es el intercambio equivocado.
2. **Hostinger compartido puede darte MariaDB, no MySQL 8.** En MariaDB el tipo `JSON` es un alias de `LONGTEXT` con un `CHECK`; las expresiones de ruta y los índices funcionales no se comportan igual. Elegir JSON te ata a verificar el motor exacto y a un riesgo de portabilidad que las columnas no tienen. Este es, para mí, el argumento decisivo dado el entorno de despliegue ya cerrado.
3. **El emparejador ordena, filtra e indexa por estos campos.** `ORDER BY name_es`, `WHERE level = ? AND category_id = ?`, y previsiblemente búsqueda por texto en el catálogo. Con columnas es SQL corriente; con JSON hay que crear columnas generadas indexadas, es decir, terminas creando las columnas de todos modos, pero con una capa de indirección encima.

**Cómo se neutraliza la desventaja.** El riesgo de las columnas es que el código se llene de `name_es` y añadir portugués obligue a tocar todo. Se elimina con un trait de una sola pieza, en `Modules/Core`:

```php
// Modules/Core/Concerns/HasTranslatedColumns.php  (boceto, no implementado)
trait HasTranslatedColumns
{
    // En el modelo:  protected array $translatable = ['name', 'short_description'];

    public function getAttribute($key)
    {
        if (in_array($key, $this->translatable ?? [], true)) {
            $locale   = app()->getLocale();
            $fallback = config('crm.fallback_locale', 'es');
            return $this->attributes["{$key}_{$locale}"]
                ?? $this->attributes["{$key}_{$fallback}"]
                ?? null;
        }
        return parent::getAttribute($key);
    }

    public function scopeOrderByTranslated($q, string $key, string $dir = 'asc')
    {
        return $q->orderBy("{$key}_".app()->getLocale(), $dir);
    }
}
```

Con esto, **el código de negocio y las vistas escriben siempre `$program->name`**, nunca `name_es`. Sólo el trait y una migración conocen los sufijos. Añadir un tercer idioma en el futuro = una migración de columnas + una línea de config, sin tocar servicios ni vistas.

**Regla de respaldo (fallback):** si falta la traducción al inglés, se devuelve el español antes que una cadena vacía. Esto es realista: el Excel del catálogo viene en español y el inglés se completará después desde la administración. El panel debe marcar visualmente qué campos aún no tienen versión en inglés.

**Campos afectados:** `program_categories.name`, `programs.name / duration / modality / short_description`, `knowledge_sources.content`, `conversation_nodes.content`, `conversation_options.label`.

---

## 5. Estrategia de secretos

### 5.1 Dónde vive cada cosa

| Secreto | Dónde | Nunca |
|---|---|---|
| `APP_KEY` (llave maestra) | `.env` en el servidor, permisos `600` | En el repositorio |
| Credenciales de BD, correo del sistema | `.env` | En BD, en código |
| API keys de proveedores de IA, n8n, Mailrelay, Stripe, Moodle, Google | **`integrations.config`, cifrado en BD** | `.env`, código, frontend, JSON exportable, repositorio, logs |

Los secretos de terceros van a la base de datos, no al `.env`, porque son **por institución** y los administra un usuario Admin desde el panel, no un desarrollador desplegando.

### 5.2 Cómo se cifra

Cast nativo de Laravel en el modelo:

```php
protected function casts(): array
{
    return ['config' => 'encrypted:array'];
}
```

- AES-256-GCM autenticado, derivado de `APP_KEY`. El cifrado y descifrado ocurren en el borde de Eloquent: el resto del código nunca ve texto cifrado ni maneja la llave.
- **Consecuencia crítica de operación:** si se pierde o rota `APP_KEY`, **todas las credenciales guardadas se vuelven ilegibles**. Se documenta en el runbook de despliegue: `APP_KEY` se genera **una vez**, se respalda fuera del servidor, y rotarla exige un comando de recifrado (`php artisan integrations:rekey --old-key=…`) que se escribirá en el Bloque 2.

### 5.3 Cómo se enmascara

Al guardar, un servicio calcula `config_preview` (columna JSON **sin cifrar**, deliberadamente, porque no contiene secreto):

```
"sk-proj-AbCdEf...8Jk2"   →   "sk-••••8Jk2"
```

Regla: primeros 2–3 caracteres del prefijo reconocible + `••••` + últimos 4. Si el valor tiene menos de 8 caracteres, se enmascara **entero** (`••••`), para no filtrar secretos cortos.

El panel lee **siempre `config_preview`**, nunca `config`. Descifrar es un acto explícito y solo ocurre en el servidor, dentro del cliente HTTP que hace la llamada al proveedor.

### 5.4 Las siete barreras

1. **Modelo:** `protected $hidden = ['config'];` y `$guarded` — `config` nunca es asignable en masa desde una petición.
2. **Serialización:** ningún API Resource incluye `config`. La regla "toda salida pasa por Resource" (§2.5) hace que sea imposible que un `return $integration` se cuele.
3. **Vistas:** el formulario de edición muestra `config_preview` y un campo vacío "Reemplazar credencial". **Nunca existe un `value=` con el secreto en el HTML.** Guardar con el campo vacío = conservar la actual.
4. **Sin lectura:** no hay endpoint, ruta ni botón que devuelva una credencial descifrada. La única operación de lectura autorizada es *usarla* para llamar al proveedor. `CLAUDE.md` §3: se reemplaza, no se revela.
5. **Logs:** middleware que añade `config`, `api_key`, `token`, `secret`, `password`, `authorization` a los campos censurados de Laravel; los drivers de IA registran **modelo, tokens, latencia y coste**, jamás cabeceras. Los mensajes de error de proveedor se sanean antes de guardarse en `last_test_message`.
6. **Exportables:** cualquier exportación (JSON, XLSX, respaldo del árbol conversacional) usa una lista blanca de columnas. `integrations` sencillamente no es exportable.
7. **Repositorio:** `.gitignore` con `.env*` (salvo `.env.example`); `.env.example` con valores vacíos; auditoría de secretos en el cierre de cada bloque; el `audit_logs` (§3.9) registra el cambio de credencial con `changes` redactado (registra *que* cambió `api_key`, nunca el valor viejo ni el nuevo).

### 5.5 Autorización

Solo el rol **Admin** ve, crea, edita o prueba integraciones. Marketing y Admisiones no ven el módulo. Se aplica con una `IntegrationPolicy` verificada en el backend en cada acción — **ocultar el menú no es control de acceso**.

---

## 6. Multi-institución

### 6.1 Cuatro capas de defensa

**Capa 1 — Contexto explícito.** Un singleton `Modules\Core\Tenancy\CurrentInstitution` que sostiene la institución activa. Nunca se adivina: se **establece** en la frontera de la petición, en dos middlewares distintos:

- `ResolveInstitutionFromUser` (rutas del panel): la toma de `auth()->user()->institution_id`.
- `ResolveInstitutionFromBot` (rutas públicas del widget): la toma del `bots.public_key` que llega en la petición → `bot->institution_id`. **El widget nunca envía un `institution_id`**; lo deduce el servidor desde una llave opaca. Un cliente malicioso no puede pedir datos de otra institución porque no tiene nada que manipular.

**Capa 2 — Scope global automático.** Trait `BelongsToInstitution` en `Modules/Core`, aplicado a **todos** los modelos de dominio:

```php
// boceto
protected static function bootBelongsToInstitution(): void
{
    static::addGlobalScope('institution', function (Builder $q) {
        if ($id = CurrentInstitution::id()) {
            $q->where($q->getModel()->getTable().'.institution_id', $id);
        }
    });

    static::creating(function (Model $m) {
        $m->institution_id ??= CurrentInstitution::idOrFail();
    });
}
```

Dos efectos: toda lectura se filtra sola, y toda escritura se sella sola. Un desarrollador tendría que **querer** saltárselo.

**Capa 3 — Barandilla en el propio scope.** Si no hay institución en contexto y el modelo es de dominio, el scope **no** devuelve todo: lanza excepción en peticiones HTTP (fallo ruidoso, no fuga silenciosa). Solo en CLI/colas se permite el modo sin contexto, y de forma explícita.

**Capa 4 — Base de datos.** Toda tabla lleva `institution_id NOT NULL` con FK; los índices únicos son compuestos con `institution_id` (`contacts(institution_id, email)`, `programs(institution_id, code)`, …). Aunque el código fallara, la BD impide que dos instituciones colisionen y hace evidente cualquier fila huérfana.

### 6.2 Las tres fugas clásicas y su tapón

| Fuga | Tapón |
|---|---|
| **Colas.** Un `Job` serializado se ejecuta minutos después, en otro proceso, sin usuario autenticado → el scope no aplica y el job ve/escribe todo. | Clase base `TenantAwareJob` que **serializa `institution_id`** en el constructor y **restablece el contexto en `handle()`**. Ningún job del proyecto extiende `Job` a secas. |
| **Comandos de consola y `schedule`.** Mismo problema. | Todo comando de dominio recibe `--institution=` o itera instituciones estableciendo el contexto en cada vuelta. |
| **`withoutGlobalScopes()`.** Un atajo bienintencionado abre el sistema entero. | Prohibido por convención + regla de PHPStan que lo marca como error fuera de `Modules/Core`. |

### 6.3 Cómo se demuestra que funciona

Prueba obligatoria de cierre del Bloque 0: se siembran **dos** instituciones con datos solapados (mismo correo de contacto, mismo código de programa) y una batería que verifica, para cada modelo, que autenticado como usuario de la institución A:

- listar devuelve solo filas de A,
- acceder por id a una fila de B da 404 (no 403 — no se confirma su existencia),
- crear sin `institution_id` explícito la sella con A,
- un job encolado por A no toca datos de B.

Esta suite se mantiene y se vuelve a correr al cerrar **cada** bloque posterior.

---

## 7. Comunicación entre capas

```
┌────────────────┐   HTTPS/JSON     ┌──────────────────────────────┐
│  Widget (JS)   │ ───────────────▶ │  API pública  /api/v1/chat/* │
│  landing MCA   │ ◀─────────────── │  (sin sesión, token opaco)   │
└────────────────┘                  │                              │
                                    │      LARAVEL (Hostinger)     │
┌────────────────┐   Livewire       │                              │
│  Panel admin   │ ◀──────────────▶ │  Módulos + Servicios         │
└────────────────┘   (server-side)  │                              │
                                    └───┬──────────────────┬───────┘
                                        │ HTTP saliente    │ Webhooks firmados
                                        ▼                  ▼
                              ┌──────────────────┐   ┌──────────┐
                              │ Proveedores IA   │   │   n8n    │
                              │ (adaptador)      │   │          │
                              └──────────────────┘   └──────────┘
```

### 7.1 Widget ↔ backend: **REST/JSON, sin estado, versionado**

Endpoints previstos (se construyen en el Bloque 5, el contrato se fija ahora):

| Método | Ruta | Qué hace |
|---|---|---|
| `POST` | `/api/v1/chat/session` | Abre o recupera sesión. Entrada: `bot_key`, `language`, `session_token?`. Salida: `session_token`, nodo actual, datos del bot. |
| `POST` | `/api/v1/chat/select` | El usuario pulsa una opción → navegación determinista. |
| `POST` | `/api/v1/chat/identify` | Captura nombre + correo → `contacts` (upsert por `institution_id`+`email`) + `leads`. |
| `POST` | `/api/v1/chat/match` | Emparejador determinista (área/nivel/meta). **Cero IA.** |
| `POST` | `/api/v1/chat/message` | Mensaje libre → Celia. Único endpoint que puede gastar tokens. |
| `POST` | `/api/v1/chat/language` | Cambia idioma y lo persiste en la conversación y el contacto. |

Decisiones y por qué:

- **REST/JSON, no GraphQL.** El widget consume un puñado de operaciones fijas y bien conocidas; GraphQL añadiría un runtime, análisis de consultas y control de profundidad en un hosting compartido a cambio de una flexibilidad que aquí nadie necesita.
- **Sin websockets ni SSE.** Decisión cerrada en `CLAUDE.md` §4 y, además, imposible en compartido. El indicador de "Celia está escribiendo" es un estado del cliente mientras la petición está en vuelo, no un canal del servidor.
- **Sin sesión de Laravel ni cookies en el widget.** Se identifica con `session_token` opaco en el cuerpo/cabecera. Evita CSRF por construcción y funciona en contexto de terceros aunque el navegador bloquee cookies.
- **CORS restringido por bot** con `bots.allowed_origins`. Nada de `*`.
- **Rate limiting** por IP y por `session_token`, con límite más estricto en `/message` (el único endpoint costoso).
- **Idempotencia:** `identify` y `select` aceptan una `client_message_id` para que un reintento del navegador no duplique leads ni mensajes.
- **Versionado `/v1`** desde el primer día: el `<script>` del widget vivirá en una landing que no controlamos por completo; romper el contrato sin versión sería romper producción.
- **El widget nunca decide nada.** No conoce reglas de negocio, ni el catálogo, ni el árbol: pide y pinta. Toda decisión ocurre en el servidor (`CLAUDE.md` §6).

### 7.2 Panel: **Livewire, sin API intermedia**

El panel es interno, un solo cliente y un solo despliegue. Construirle una API REST propia sería duplicar validación y autorización sin beneficio. Livewire mantiene la lógica en servicios PHP del servidor, reutilizables luego por la API pública si hiciera falta.

### 7.3 Backend ↔ proveedores de IA: **adaptador con interfaz común**

```php
// Modules/Ai/Contracts/AiProvider.php  (boceto)
interface AiProvider
{
    public function chat(AiRequest $request): AiResponse;   // DTOs propios, no formatos de proveedor
    public function test(): AiTestResult;
}
```

- Un driver por proveedor (`OpenAiDriver`, `GeminiDriver`, `ClaudeDriver`, `DeepSeekDriver`, …), cada uno traduciendo el DTO propio al formato de su API. **Sin SDKs oficiales**: cada SDK arrastra su propio modelo de objetos y erosiona la neutralidad que `CLAUDE.md` §4 exige.
- La resolución es por **proceso**, no por código: `AiProviderFactory::forProcess('conversation', $botId)` lee `ai_process_configs`, obtiene la `integration`, descifra la credencial en memoria y devuelve el driver listo.
- Cada llamada aplica **timeout duro** (20–25 s), un reintento solo ante error de red, y registra `provider / model / tokens / latencia / coste` en `messages.meta`. Si el proveedor falla, Celia responde con un mensaje honesto de indisponibilidad — **nunca inventa** (`CLAUDE.md` §3).

### 7.4 Backend ↔ n8n: **webhooks firmados en ambos sentidos**

No es del día 1, pero el contrato se fija ahora para que después sea aditivo:

- **Salida:** `POST` al webhook de n8n con `X-Signature: sha256=HMAC(secret, body)` y `X-Idempotency-Key`. El secreto vive en `integrations` (tipo `n8n`), cifrado. Se despacha por cola, con reintentos y registro del resultado.
- **Entrada:** `POST /api/v1/hooks/n8n/{token}` con verificación de firma HMAC en tiempo constante, ventana de marca temporal contra repetición, y clave de idempotencia. n8n **nunca** recibe ni almacena credenciales del CRM más allá de su propio token.

---

## 8. Despliegue en Hostinger compartido

### 8.1 Disposición en el servidor

```
~/domains/<dominio>/
├── app/          ← el proyecto completo (fuera del alcance de la web)
│   ├── .env      ← permisos 600
│   ├── storage/
│   └── public/   ← el document root del dominio apunta AQUÍ
```

El `document root` del dominio se apunta a `app/public` desde hPanel. Si el plan no lo permitiera, la alternativa es dejar el contenido de `public/` en `public_html` y ajustar las dos rutas de `index.php`; funciona, pero es más frágil. **A verificar en tu plan.**

### 8.2 Ciclo de despliegue

En local:
```
npm run build                 # compila panel + widget a public/build/
composer install --no-dev --optimize-autoloader
```
En el servidor (SSH):
```
git pull                                   # o subida por SFTP del artefacto
php artisan down --render="errors::503"
php artisan migrate --force
php artisan config:cache route:cache view:cache event:cache
php artisan up
```

Notas:
- `vendor/` y `public/build/` se suben construidos; **no se ejecuta `npm` ni se compilan assets en Hostinger**.
- `config:cache` obliga a que **`env()` no se use fuera de `config/`**. Es una regla de código desde el Bloque 0; violarla produce fallos que solo aparecen en producción.
- `php artisan storage:link` a veces falla en compartido. Plan B: enlace simbólico relativo por SSH, o servir los ficheros por un controlador con autorización. En el día 1 hay poco contenido subido, así que no bloquea.

### 8.3 Migraciones

Por **SSH**, con `--force`. Si tu plan no incluye SSH, el plan B es un comando artisan expuesto en una ruta protegida por token largo del `.env` + lista blanca de IP, que se retira tras usarse — **es un último recurso**, no la vía normal. Conviene confirmar SSH antes de cerrar el bloque.

Regla: **nunca `migrate:fresh` en producción.** Migraciones incrementales y reversibles; el sistema sale a producción el día 1 y crece sin detenerse.

### 8.4 Tareas programadas y colas

Un solo cron, el estándar de Laravel:

```
* * * * * cd ~/domains/<dominio>/app && /usr/bin/php8.3 artisan schedule:run >> /dev/null 2>&1
```

(ruta absoluta del binario: la versión del CLI puede diferir de la de la web).

Y en `routes/console.php`:

```php
Schedule::command('queue:work --stop-when-empty --max-time=55 --tries=3 --sleep=1')
    ->everyMinute()->withoutOverlapping();
```

El worker **no es un demonio**: arranca, vacía la cola, muere antes del minuto siguiente. Con `withoutOverlapping()` nunca hay dos a la vez.

- Driver de cola: **`database`** (tablas `jobs`, `job_batches`, `failed_jobs`).
- Sesiones y caché: **`database`**.
- **Qué va a la cola:** correo transaccional, webhooks a n8n, importación del Excel. **Qué NO:** la respuesta de Celia (es síncrona; el usuario está esperando).
- Otras tareas programadas: purga de conversaciones abandonadas, poda de `sessions` y `failed_jobs`, resumen diario de leads.

### 8.5 Respaldos y observabilidad

- `mysqldump` diario por cron a una carpeta **fuera de `public/`**, con rotación a 14 días, más los respaldos propios de Hostinger.
- Logs con driver `daily` y retención de 14 días (el disco en compartido es limitado). Con la censura de campos sensibles de §5.
- Sin New Relic ni agentes: en compartido no aplican. La observabilidad del día 1 es `audit_logs` + `events` + `messages.meta` + `failed_jobs`.

---

## 9. Riesgos, decisiones de escalabilidad y dudas a resolver

### 9.1 Riesgos de este cimiento

| # | Riesgo | Impacto | Mitigación propuesta |
|---|---|---|---|
| R1 | **`max_execution_time` del hosting vs. latencia de la IA.** Un modelo lento puede tardar 30–60 s; el hosting corta antes. | Celia falla en producción de forma intermitente. | Timeout de cliente en 20–25 s, siempre por debajo del límite del servidor. Preferir modelos rápidos para `conversation`. Respuesta de cortesía al expirar. **Medir con el proveedor real antes de cerrar el Bloque 6.** |
| R2 | **`messages` y `events` crecen sin techo.** Son las tablas calientes. | En 2–3 años, consultas del panel lentas y respaldos pesados. | Índices correctos desde hoy (ya en §3.5), `created_at` indexado, y **política de retención decidida pronto** (D5). Archivado a tabla histórica cuando pase de ~2M filas. No hace falta particionar el día 1. |
| R3 | **MariaDB vs MySQL en Hostinger.** | Afecta a `JSON`, `utf8mb4` y colaciones. | Ya condiciona la decisión de §4 (columnas, no JSON). Las columnas `json` que quedan (`event_data`, `meta`, `config_preview`) se leen en PHP, nunca se consultan por ruta SQL. **Verificar el motor real y fijar `utf8mb4_unicode_ci`.** |
| R4 | **`APP_KEY` es un punto único de fallo** para todas las credenciales. | Perderla = reintroducir todas las integraciones a mano. | Respaldo fuera del servidor + runbook + comando de recifrado (Bloque 2). |
| R5 | **Hosting compartido = vecinos ruidosos y CPU limitada.** | Latencia impredecible en horas pico. | El código no depende de nada exclusivo del compartido (sin Redis, sin demonios); el paso a VPS es *mover, no reconstruir*, como exige `CLAUDE.md` §4. |
| R6 | **El widget es una API pública sin autenticación.** Abusable para quemar tokens de IA. | Coste real en euros y ruido en el CRM. | Rate limiting por IP y por sesión, CORS por bot, `honeypot` en el formulario de captura, y **presupuesto de IA por bot/día** con degradación a modo guiado al superarse. **Recomiendo incluir el presupuesto ya en el esquema del Bloque 2.** |
| R7 | **Livewire acopla el panel al servidor.** | Si algún día el panel se quiere como SPA, hay que reescribir la capa de presentación. | Aceptable: la lógica vive en servicios, no en los componentes. Se reescribe la vista, no el negocio. |
| R8 | **Ids autoincrementales** si alguna vez se expusieran. | Enumeración. | Ningún id sale al exterior (`public_key`, `session_token`). Debe verificarse en la auditoría de cierre de cada bloque. |
| R9 | **Retrieval Forma A**: todo el conocimiento va en cada prompt. | Si el conocimiento crece, el coste por conversación crece linealmente. | Ya previsto en `CLAUDE.md` §4. Añado una barandilla operativa: **alerta cuando el contexto ensamblado supere ~8.000 tokens**, señal de que toca el módulo de recuperación selectiva. |

### 9.2 Dudas del `CLAUDE.md` que necesito que resuelvas

| # | Duda | Por qué importa ahora | Mi recomendación si no quieres decidir |
|---|---|---|---|
| **D1** | **¿Existe un super-administrador que vea todas las instituciones?** `users.institution_id` implica que cada usuario pertenece a una sola. Cuando entre la segunda institución, ¿quién la administra? | Cambia el esquema de `users` y la Capa 2 del scope. Es más barato decidirlo hoy que después. | Incluir `is_super_admin` (boolean, default false) que puede cambiar de institución activa desde el panel. Coste: casi cero. |
| **D2** | **RGPD / consentimiento y retención.** `CLAUDE.md` dice que hay estudiantes en Europa, pero no menciona consentimiento, política de privacidad, derecho de supresión ni plazo de conservación. | Se capturan nombre y correo de ciudadanos de la UE desde el día 1. Añadir las columnas después es una migración con datos ya vivos. | Incluir ya `consent_at`, `consent_source` y `unsubscribed_at` en `contacts`, y una casilla de consentimiento con enlace a la política en la captura del widget. |
| **D3** | **Valores exactos de `leads.status`, `interest_level` y `product_type`.** | Definen el embudo del panel y los informes. | Arrancar con `status`: `new / contacted / qualified / enrolled / discarded`; `interest_level`: `low / medium / high`; `product_type`: `microcredential`. Configurable en `config/crm.php`. |
| **D4** | **¿Un lead por contacto+bot, o un lead por interés?** Si el mismo prospecto vuelve a los tres meses por otro programa, ¿se actualiza el lead o se crea otro? | Determina si `leads` lleva un índice único y cómo se cuentan las conversiones. | Un lead **por conversación con intención**: si el contacto vuelve tras 30 días de inactividad o pregunta por otro producto, se crea un lead nuevo. Preserva la historia comercial. |
| **D5** | **Retención de conversaciones y mensajes.** ¿Se guardan para siempre? | R2 y D2 dependen de esto. | 24 meses de conversación completa; después se conservan contacto, lead y eventos, y se purgan los `messages`. |
| **D6** | **Correo transaccional del día 1: ¿SMTP de Hostinger o Mailrelay?** | Cambia la configuración del Bloque 7 y los límites de envío. | SMTP de Hostinger para transaccional (volumen bajo); Mailrelay queda para campañas, que son módulo posterior. |
| **D7** | **Dominio del panel.** ¿`crm.mcaschool.us` separado, o una ruta `/admin` dentro del sitio principal? | Afecta a CORS, cookies, certificados y despliegue. | Subdominio propio para el panel, distinto del de la landing. Aísla cookies y simplifica CORS. |
| **D8** | **Anti-abuso del widget:** ¿aceptas un CAPTCHA invisible (hCaptcha/Turnstile) en la captura de nombre y correo? | Implica un tercero y una clave más. Es la defensa práctica contra bots que llenen el CRM de basura. | Empezar **sin** CAPTCHA, con honeypot + rate limiting, y añadirlo solo si aparece spam real. |
| **D9** | **Zona horaria de presentación.** `CLAUDE.md` no la fija. | Informes y fechas del panel. | UTC en BD, presentación en `America/New_York` (igual que SIGAC-MCA), configurable por institución. |
| **D10** | **¿Se versiona el catálogo?** Si un programa cambia de nombre, ¿los leads antiguos deben reflejar el nombre de entonces? | En SIGAC-MCA esto fue una invariante fuerte (*snapshot* inmutable). Aquí no se menciona. | **No versionar** en el día 1: el CRM registra interés, no emite documentos. `SoftDeletes` en `programs` para no romper FKs históricas. |

### 9.3 Añadidos al modelo de datos que propongo y necesitan tu visto bueno

Ninguno contradice el `CLAUDE.md`; todos son campos o tablas que él no lista y que creo necesarios:

1. `bots.public_key` y `bots.allowed_origins` — sin ellos el widget no puede identificarse sin exponer ids internos.
2. `integrations.config_preview` — hace posible el enmascarado sin descifrar.
3. `messages.meta` — **sin esto no se puede medir el AI Deflection Rate**, que el propio `CLAUDE.md` §3 declara métrica clave.
4. `conversations.current_node_id` — sin esto no hay recuperación de sesión, que `CLAUDE.md` §6 exige en el widget.
5. `users.preferred_language` — panel bilingüe.
6. `contacts.consent_at / consent_source / unsubscribed_at` — RGPD (D2).
7. `conversation_nodes.config` — parámetros del nodo (filtros, campos del formulario).
8. Tabla `audit_logs` — trazabilidad de cambios de credenciales.
9. `institutions.timezone` y `institutions.default_language`.

---

## 10. Qué haría al aprobarse (y solo entonces)

**Nada de esto está hecho.** Es el alcance exacto del primer encargo tras tu visto bueno, para que sepas qué estás aprobando:

1. `git init` + `.gitignore` + `composer create-project laravel/laravel` en `Proyectos Claude Code/CRM-MCA`.
2. Instalar Breeze (Livewire), `nwidart/laravel-modules`, `openspout`, y el toolkit de calidad (Pest, Larastan, Pint).
3. Crear las 10 carpetas de `Modules/` **vacías y registradas**, sin lógica.
4. `Modules/Core`: `BelongsToInstitution`, `HasTranslatedColumns`, `CurrentInstitution`, `TenantAwareJob`, enums compartidos, `config/crm.php`.
5. **Todas las migraciones de §3**, reversibles, sin controladores ni vistas.
6. Seeder mínimo: institución MCA School + bot Microcredenciales + usuario Admin.
7. La batería de pruebas de aislamiento multi-institución de §6.3, en verde.
8. `.env.example`, `README` de arranque y runbook de despliegue de §8.
9. Correr `migrate:fresh --seed`, `migrate:rollback`, Pest, PHPStan y Pint, y **mostrarte los resultados**.

Sin controladores de módulos, sin vistas de módulos, sin widget, sin chatbot, sin IA.

---

**Fin de la propuesta. Esperando revisión.**
