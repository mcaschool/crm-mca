# API InCompany — Especificación técnica para integradores (n8n)

Endpoint público para crear un **lead corporativo (InCompany)** en el CRM desde un
sistema externo (n8n). Este documento es autosuficiente: describe todo lo necesario
para configurar el nodo HTTP sin acceso al código.

> Versión del documento: 1.1 · Fuente: código en producción.
>
> **v1.1 — UPSERT por email con evento.** El endpoint ya no solo crea: deduplica por
> email y actualiza según el evento (`ruta_generada` / `solicita_contacto`). Ver §4 (campo
> `evento`), §6 (respuestas `created` vs `updated`) y §10 (embudo de estados).

---

## 1. Endpoint

| | |
|---|---|
| **URL (producción)** | `https://crm.mcaschool.education/api/v1/incompany/lead` |
| **Método** | `POST` |
| **Ruta (fija)** | `/api/v1/incompany/lead` |

> La ruta `/api/v1/incompany/lead` es fija. El host (`https://crm.mcaschool.education`)
> debe ser el dominio real donde corre el CRM en producción; confírmalo si el despliegue
> usa otro dominio o subdominio.

---

## 2. Cabeceras (headers) requeridas

| Header | Valor | Obligatorio |
|---|---|---|
| `Authorization` | `Bearer <TOKEN>` | **Sí** |
| `Content-Type` | `application/json` | **Sí** |
| `Accept` | `application/json` | **Sí** (para recibir SIEMPRE los errores en JSON) |

- El cuerpo debe ir en **UTF-8** (soporta acentos y ñ: `María`, `Construcción`).
- `Accept: application/json` es importante: sin él, los errores 401 y 429 podrían
  devolverse como página HTML en vez de JSON.

---

## 3. Autenticación

- **Esquema:** token Bearer en el header `Authorization`.
- **Formato exacto:** `Authorization: Bearer <TOKEN>`
  (la palabra `Bearer`, un espacio, y el token).
- **NO** se usa `X-API-Key` ni el token en la URL/query. El token viaja **solo** en el header.
- El token lo genera/gestiona el administrador del CRM en **Ajustes → Integraciones → n8n →
  «Token entrante InCompany (Bearer)»**. Se guarda cifrado y puede rotarse ahí.
- Este token **solo** habilita crear leads InCompany por este endpoint; no da acceso a
  nada más del CRM.

Ejemplo de header:

```
Authorization: Bearer 3f9a1c7e5b204d8a9f3a1c7e5b204d8a
```

---

## 4. Contrato JSON de entrada

Todos los nombres de campo son **exactos** (sensibles a mayúsculas/minúsculas).

| Campo | Tipo | Requerido | Reglas / formato |
|---|---|---|---|
| `evento` | string | **Sí** | valores permitidos EXACTOS: `ruta_generada` \| `solicita_contacto` |
| `nombre_empresa` | string | **Sí** | 1–150 caracteres |
| `nombre_contacto` | string | **Sí** | 1–120 caracteres |
| `email` | string | **Sí** | correo válido (RFC), máx. 190 |
| `whatsapp` | string | **Sí** | máx. 30 caracteres (texto libre; ej. `+1 809 555 7788`) |
| `sector` | string | **Sí** | máx. 100 |
| `tamano_empresa` | string | **Sí** | máx. 40 (texto libre; ej. `51-200`) |
| `modalidad` | string | **Sí** | valores permitidos EXACTOS: `persona` \| `grupo` |
| `cantidad_personas` | entero | **Sí** | número entero entre `1` y `100000` |
| `programa_1` | string | **Sí** | `course_idnumber` de Moodle, máx. 100 |
| `programa_2` | string \| null | No | `course_idnumber` de Moodle, máx. 100 |
| `programa_3` | string \| null | No | `course_idnumber` de Moodle, máx. 100 |
| `area_desarrollo` | string | **Sí** | máx. 500 |

### El campo `evento` y el comportamiento UPSERT

El formulario dispara **dos eventos** para la misma persona, en momentos distintos:

- `ruta_generada`: la persona vio su diagnóstico (los 3 programas).
- `solicita_contacto`: además pidió ser contactada.

El endpoint **deduplica por email** dentro de la institución: **un email = un solo lead
InCompany**, sin importar cuántos eventos lleguen. Según el evento:

| Evento recibido | Si el email NO existe | Si el email YA existe |
|---|---|---|
| `ruta_generada` | **CREA** el lead en estado `diagnostico` (respuesta `created`) | **ACTUALIZA** datos/diagnóstico; **no** degrada el estado (respuesta `updated`) |
| `solicita_contacto` | **CREA** el lead directamente en estado `solicita_contacto` (respuesta `created`) | **ACTUALIZA** el mismo lead a `solicita_contacto` (respuesta `updated`) |

- El estado **nunca retrocede**: si un `ruta_generada` llega después de que la persona ya
  pidió contacto, el lead se mantiene en `solicita_contacto`.
- Envía **el payload completo en ambos eventos** (todos los campos requeridos). El segundo
  evento refresca los datos del lead.

### Los 3 programas

- Se envían como **tres campos planos independientes**: `programa_1`, `programa_2`,
  `programa_3` (no es un array ni un objeto anidado).
- El **valor de cada campo es directamente el `course_idnumber` de Moodle** (por ejemplo
  `"mdec"`, `"mspth"`). No se envían nombre ni precio: el CRM resuelve el nombre del
  programa a partir del `course_idnumber`.
- `programa_1` es obligatorio; `programa_2` y `programa_3` son opcionales (envía `null`
  u omítelos si no aplican).
- Si un `course_idnumber` no existe en el catálogo, **el lead NO se rechaza**: se guarda
  el código tal cual y en el CRM se muestra como «sin enlazar». (Aun así conviene enviar
  idnumbers válidos.)

### Campos que NO se envían

- `origen`: lo fija el servidor a `incompany_web`. Si lo mandas, se **ignora**.
- `id`, `estado`/`status`, `fecha_creacion`: los gestiona el CRM. **No** se envían.
- Cualquier campo extra que envíes se ignora (solo se procesan los campos de la tabla).

---

## 5. Ejemplo de petición válida (listo para copiar)

```
POST https://crm.mcaschool.education/api/v1/incompany/lead
Authorization: Bearer 3f9a1c7e5b204d8a9f3a1c7e5b204d8a
Content-Type: application/json
Accept: application/json
```

```json
{
  "evento": "ruta_generada",
  "nombre_empresa": "Constructora del Caribe SRL",
  "nombre_contacto": "María Fernández",
  "email": "maria.fernandez@constructoracaribe.com",
  "whatsapp": "+1 809 555 7788",
  "sector": "Construcción",
  "tamano_empresa": "51-200",
  "modalidad": "grupo",
  "cantidad_personas": 25,
  "programa_1": "mdec",
  "programa_2": "mspth",
  "programa_3": null,
  "area_desarrollo": "Liderazgo intermedio y toma de decisiones para jefes de obra"
}
```

> Para el segundo evento (la persona pide contacto), envía **el mismo payload** cambiando
> únicamente `"evento": "solicita_contacto"`.

---

## 6. Respuestas

### 6.1 Éxito — `201 Created` (lead nuevo) o `200 OK` (lead actualizado)

Lead creado (`201`):

```json
{ "id": 128, "status": "created" }
```

Lead existente actualizado por el upsert (`200`):

```json
{ "id": 128, "status": "updated" }
```

- `id`: identificador numérico del lead en el CRM. En el `updated` es el **mismo id** que
  se devolvió al crearlo (no se duplica).
- `status`: `"created"` si se creó el lead, `"updated"` si se actualizó uno existente.
- Ambos (`200` y `201`) son éxito. En n8n, trata cualquier `2xx` como correcto.

### 6.2 Sin token o token inválido — `401 Unauthorized`

Sin header `Authorization`:

```json
{ "message": "Falta el token de autenticación." }
```

Con token que no corresponde:

```json
{ "message": "Token inválido." }
```

### 6.3 Validación fallida — `422 Unprocessable Content`

No se crea nada (ni parcialmente). Estructura:

```json
{
  "message": "Datos inválidos: revisa los campos requeridos, tipos y longitudes.",
  "errors": {
    "email": ["El campo «email» debe ser un correo válido."],
    "modalidad": ["El valor de «modalidad» no es válido (usa: persona o grupo)."],
    "cantidad_personas": ["El campo «cantidad_personas» debe ser un número entero."],
    "programa_1": ["El campo «programa_1» es obligatorio."]
  }
}
```

- `errors` es un objeto: clave = nombre del campo, valor = lista de mensajes de ese campo.
- Solo aparecen los campos que fallaron.

### 6.4 Límite de peticiones superado — `429 Too Many Requests`

```json
{ "message": "Too Many Attempts." }
```

Cabeceras incluidas en la respuesta 429:

```
Retry-After: 38
X-RateLimit-Limit: 20
X-RateLimit-Remaining: 0
X-RateLimit-Reset: 1787501631
```

- `Retry-After`: segundos que hay que esperar antes de reintentar.

---

## 7. Límite de peticiones (rate limit)

- **20 peticiones por minuto por IP de origen.**
- La petición nº 21 dentro de la misma ventana de 60 s devuelve `429`.
- Todas las respuestas incluyen las cabeceras `X-RateLimit-Limit` (20) y
  `X-RateLimit-Remaining` (cuántas quedan en la ventana actual).
- **Recomendación para n8n:** no enviar más de ~20/min desde el mismo origen. Si esperas
  ráfagas, agrega un pequeño retardo entre envíos o procesa en cola. Ante un `429`,
  reintenta después de los segundos que indica `Retry-After`.

---

## 8. Reglas de validación a prever en n8n (antes de enviar)

Para evitar `422`, valida en n8n que:

0. **`evento`** presente y con valor exactamente `ruta_generada` o `solicita_contacto`.
1. **Campos obligatorios no vacíos:** `nombre_empresa`, `nombre_contacto`, `email`,
   `whatsapp`, `sector`, `tamano_empresa`, `modalidad`, `cantidad_personas`,
   `programa_1`, `area_desarrollo`. Un valor vacío o solo espacios cuenta como ausente
   (el servidor recorta espacios y trata `""` como nulo).
2. **`email`** con formato de correo válido.
3. **`modalidad`** exactamente `persona` o `grupo` (en minúsculas).
4. **`cantidad_personas`** entero entre 1 y 100000 (envíalo como número JSON, no texto:
   `25`, no `"veinticinco"`).
5. **Longitudes máximas:** empresa ≤150, contacto ≤120, email ≤190, whatsapp ≤30,
   sector ≤100, tamaño ≤40, cada programa ≤100, área a desarrollar ≤500.
6. **`programa_1`** siempre presente; `programa_2`/`programa_3` opcionales (`null` u
   omitidos).
7. **Codificación UTF-8** en el cuerpo.

---

## 9. Resumen de códigos de estado

| Código | Significado | Acción en n8n |
|---|---|---|
| `201` | Lead creado (`status: created`) | OK; guarda el `id` devuelto |
| `200` | Lead existente actualizado (`status: updated`) | OK; mismo `id` que al crearlo |
| `401` | Falta token o token inválido | Revisar el header `Authorization` |
| `422` | Datos inválidos | Corregir según `errors`; no reintentar igual |
| `429` | Demasiadas peticiones | Esperar `Retry-After` segundos y reintentar |

---

## 10. Estados del embudo InCompany

El evento define el estado (`stage`) del lead en el embudo corporativo:

| Estado | Se alcanza con | Significado |
|---|---|---|
| `diagnostico` | `ruta_generada` | La persona vio su diagnóstico (aún no pide contacto). |
| `solicita_contacto` | `solicita_contacto` | **El lead más caliente**: pidió ser contactada. |

- El estado **solo avanza** (`diagnostico` → `solicita_contacto`); nunca retrocede.
- El CRM guarda además la marca de tiempo de cada salto (cuándo vio el diagnóstico y
  cuándo pidió contacto), visibles en la ficha del lead para el equipo comercial.
- Este estado del embudo es propio de InCompany; el equipo comercial gestiona aparte su
  propio estado de gestión del lead (nuevo/contactado/…) sobre el mismo registro.
