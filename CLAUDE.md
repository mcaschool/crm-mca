# Visión Global — CRM Conversacional Educativo
### Documento de contexto permanente para Claude Code

> **Naturaleza de este documento.** Esto **no es una orden de construcción**. Es el contexto de referencia que debes tener siempre presente: qué estamos construyendo, por qué, con qué decisiones de arquitectura ya cerradas y bajo qué principios. El sistema se construye **módulo por módulo**. No intentes montar todo de una vez. Al final de este documento se te indica cómo empezar. Espera la orden específica de cada bloque antes de programar ese bloque.

---

## 1. Qué estamos construyendo

Un **CRM conversacional para instituciones educativas**. Su función es capturar, informar, orientar, dar seguimiento y calificar prospectos que llegan a través de chatbots embebidos en distintas landing pages de la web institucional, alimentando todos un mismo CRM central.

La primera institución es **MCA School** y el primer módulo operativo es un chatbot especializado exclusivamente en **Microcredenciales**, con una asesora llamada **Celia**. Más adelante se sumará un segundo bot para la página principal (institucional), con una asesora llamada **Sofia**, y potencialmente más bots y más instituciones.

El sistema debe estar **operativo y en producción desde el día 1** como reemplazo del registro de leads que hoy se hace en Google Sheets, y crecer sumando módulos **sin detener la producción en vivo**.

## 2. Qué es y qué no es

**No es** un chatbot basado completamente en inteligencia artificial. **No es** tampoco un CRM atado a una sola institución ni a un solo bot.

**Es** un sistema **híbrido y modular**: determinista donde la respuesta es predecible (menús, botones, datos), inteligente donde se necesita razonamiento (Celia), y con capacidades agénticas acotadas solo donde una asesora necesita una herramienta concreta. La arquitectura contempla desde el inicio múltiples bots, múltiples instituciones y crecimiento por módulos.

## 3. Principios rectores

**La aplicación controla el proceso; la asesora controla la conversación.** Los flujos institucionales (navegación, captura, precios, matrícula, registro) los gobierna la aplicación con reglas explícitas. La IA se usa únicamente para conversar y razonar cuando el usuario lo pide.

**IA solo donde aporta valor.** Todo lo predecible se resuelve con navegación, datos y respuestas predefinidas, sin consumir tokens. Esto reduce costo, acelera respuestas, minimiza errores y da trazabilidad. Una métrica clave del sistema será el *AI Deflection Rate*: el porcentaje de interacciones resueltas sin llamar al modelo.

**La asesora nunca inventa.** Celia responde solo con datos estructurados del sistema, respuestas predefinidas y conocimiento autorizado. Si no tiene información suficiente, lo reconoce con honestidad y registra la consulta como *pregunta no resuelta* para mejorar la base de conocimiento después. Nunca inventa precios, duración, certificaciones, requisitos, fechas ni promociones. Celia sabe que es una asesora inteligente, no una persona humana, y no engaña al usuario sobre su naturaleza.

**Multi-institución desde la primera tabla.** Cada dato pertenece a una institución. Esto se hornea en el esquema desde el día 1, aunque al inicio solo exista MCA.

**El bot es una entidad configurable, no código.** Crear Sofia (o cualquier bot futuro) es configurar un registro, no programar otro sistema. De cada bot cuelgan su conocimiento, su árbol conversacional, su personalidad y su configuración de IA.

**IA multi-proveedor, elegible por proceso.** El sistema no se amarra a ningún proveedor. Cada tipo de tarea (conversar, clasificar, resumir, redactar correo) puede usar un proveedor y modelo distinto, configurable desde el panel. Así se usa la IA de mayor calidad solo donde el trato humano lo justifica, y la más económica donde solo se procesa información.

**Los secretos nunca se exponen.** Ninguna API key, token, contraseña o credencial se almacena hardcoded en código, frontend, JSON exportable, repositorio ni workflow. Todos los secretos se gestionan cifrados en el backend. Una vez guardada, una credencial no vuelve a mostrarse completa: se muestra enmascarada (`sk-••••8Jk2`) con opción de reemplazarla, nunca de revelarla.

**Bilingüe desde el día 1 (español e inglés).** Toda la experiencia del usuario funciona en español y en inglés, con un selector de idioma en el widget. El sistema recuerda el idioma preferido del contacto, y Celia conversa en el idioma que el usuario elija. Esto no es una capa cosmética: el contenido administrable (menús, respuestas, base de conocimiento y campos visibles del catálogo) se almacena en ambos idiomas, y los textos de interfaz se gestionan con el sistema de localización de Laravel. Los estudiantes están en Latinoamérica, Estados Unidos y Europa, por lo que el bilingüismo es un requisito de producto, no un extra.

**Modular y sin interrumpir producción.** Se construye bloque a bloque. Cada bloque se termina y se prueba antes de pasar al siguiente. Los módulos posteriores se suman sobre un sistema vivo sin detenerlo.

## 4. Decisiones de arquitectura ya cerradas

Estas decisiones están tomadas. No deben reabrirse salvo que se indique explícitamente.

**Stack:** PHP con **Laravel** y base de datos **MySQL/MariaDB**, desplegado en **Hostinger (hosting compartido)**. El chatbot es petición-respuesta y las llamadas a IA son HTTP, así que no requiere procesos persistentes ni websockets. El código debe escribirse de modo que la migración futura a un VPS sea mover, no reconstruir.

**Multi-tenancy:** base de datos única, esquema compartido, con `institution_id` en cada tabla. La identidad de un contacto es única por `(institution_id + email)`.

**El bot como entidad de primera clase:** tabla `bots`, con su configuración, conocimiento y árbol asociados por `bot_id`.

**Capa de IA agnóstica:** un adaptador con interfaz común para los distintos proveedores (ChatGPT, Gemini, Claude, DeepSeek, Qwen, Kimi u otros), y una configuración que asigna proveedor+modelo por proceso.

**Recuperación de conocimiento (Forma A):** el conocimiento de Celia es un cuerpo pequeño y estable (información transversal), por lo que se le entrega compacto al modelo, sin necesidad de recuperación selectiva por fragmentos en esta fase. Si el volumen de conocimiento creciera mucho, se evaluará añadir recuperación selectiva como módulo posterior.

**Internacionalización (i18n):** dos idiomas fijos —español e inglés— desde el inicio. Los textos de interfaz se resuelven con los archivos de traducción de Laravel. El contenido almacenado y administrable (nodos y opciones del árbol, fuentes de conocimiento y campos visibles de programas) lleva su versión en cada idioma; la estrategia concreta de almacenamiento (columnas por idioma o columna JSON de traducciones) se define en el Bloque 0. El idioma preferido del contacto se guarda y se propaga a Celia y al correo transaccional.

**Frontera CRM / sistema académico:** el CRM acompaña al prospecto hasta la matrícula. Al matricularse, el contacto se entrega al sistema de gestión académica propio (que tiene puerta de comunicación) y el CRM lo conserva solo como registro histórico, no como audiencia activa. Las campañas de correo son **solo para prospectos**, no para alumnos ni exalumnos (eso será un módulo futuro aparte).

## 5. Reparto de responsabilidades sobre la información

Es fundamental no confundir tres capas de información:

**La web (catálogo público)** es la fuente de la verdad sobre cada programa: precio, temario completo, requisitos. El sistema **no** duplica ni recita esto; enlaza a la ficha.

**El catálogo estructurado del sistema** (tabla `programs`, poblada desde el Excel del cliente) conoce de cada programa: nombre, área, nivel, meta, perfil, etiquetas y URL. No para recitar el temario, sino para **emparejar** al prospecto con programas adecuados, **enlazar** a la ficha en la web, y **registrar con precisión** por cuál programa se interesó cada lead. El precio y el temario completo **no** viven aquí.

**El conocimiento de Celia** (base de conocimiento) cubre lo transversal y estable que aplica a las Microcredenciales en general: qué es una Microcredencial, certificación, titulación, reconocimiento, metodología, formas de pago, proceso de matrícula. Esto es lo que Celia responde con sus propias palabras.

## 6. Reglas funcionales clave

- **Captura mínima inicial:** solo nombre y correo. Otros datos (teléfono, país, etc.) se piden después solo si hay una razón funcional.
- **Dos modos de conversación:** *guiado* (navegación por botones, sin IA) y *Celia* (conversación inteligente, se activa cuando el usuario lo pide).
- **Emparejador determinista:** el flujo "Ayúdame a elegir" filtra el catálogo por área, nivel y meta y muestra las fichas que encajan, con enlace a la web. Cero tokens de IA.
- **Sin derivación a humano** dentro del módulo de Microcredenciales: Celia es el último nivel de atención. Nunca promete contacto humano, llamada ni seguimiento manual.
- **Alcance acotado:** el bot de Microcredenciales solo trata Microcredenciales. Otras consultas se derivan al sitio institucional.
- **Todo comportamiento deja rastro** en el CRM (eventos), aunque la respuesta sea un enlace.

**El widget de chat en la web.** Es la cara visible del sistema: un componente embebible en la landing de Microcredenciales (y mañana en la página principal, con Sofia). Debe ser responsive y mobile-first, integrarse visualmente con MCA School, y cargar rápido sin bloquear la página. Incluye el selector de idioma (español/inglés), la captura inicial de nombre y correo, el historial visual de la conversación, la navegación por botones y tarjetas de programa, los enlaces a la ficha en la web, la transición entre modo guiado y Celia, el indicador de que Celia está escribiendo, y la recuperación de sesión cuando el usuario regresa. El widget **nunca** contiene secretos ni lógica sensible: solo habla con el backend a través de la API del sistema. Toda decisión de negocio ocurre en el servidor.

## 7. Modelo de datos

El esquema se organiza alrededor de `institution_id` (envuelve todo), `bot_id` (lo operativo) y el catálogo de programas.

**Multi-institución y acceso**
- `institutions` — id, name, slug, status, created_at
- `users` (panel) — id, institution_id, name, email, password_hash, role (Admin/Marketing/Admisiones), status, last_login_at

**Bots y configuración**
- `bots` — id, institution_id, name, slug, assistant_name, landing_url, status
- `ai_process_configs` — id, institution_id, bot_id, process (conversation/classification/summary/email_draft), integration_id, model
- `integrations` — id, institution_id, type (ai_provider/google/n8n/mailrelay/smtp/stripe/moodle), name, config (JSON cifrado), status, last_tested_at

**CRM Core**
- `contacts` — id, institution_id, first_name, last_name, email, phone, country, preferred_language (es/en), created_at, updated_at · único (institution_id, email)
- `leads` — id, institution_id, contact_id, bot_id, product_type, program_id (nullable), area, goal, level, source, status, interest_level, created_at, updated_at
- `conversations` — id, institution_id, contact_id, bot_id, session_id, channel, mode (guided/celia), language (es/en), status, started_at, last_activity_at
- `messages` — id, conversation_id, sender_type (user/system/celia), content, message_type, created_at
- `events` — id, institution_id, contact_id, conversation_id, bot_id, event_type, event_data (JSON), created_at
- `program_interests` — id, institution_id, contact_id, program_id, bot_id, source, created_at

**Catálogo**
- `program_categories` — id, institution_id, name (bilingüe), slug
- `programs` — id, institution_id, code, name (bilingüe), credential_en, category_id, level, goal, profile, duration (bilingüe), modality (bilingüe), short_description (bilingüe), url, status, display_order
- `program_tags` — id, program_id, tag

**Conocimiento de Celia**
- `knowledge_sources` — id, institution_id, bot_id, name, code, type, category, program_id (nullable), url, content (bilingüe), priority, status, last_synced_at

**Constructor conversacional**
- `conversation_nodes` — id, institution_id, bot_id, key, type (message/menu/program_list/form/action/start_celia/external_link), content (bilingüe), display_order, status
- `conversation_options` — id, node_id, label (bilingüe), target_node_id, action, event_type, display_order

Los campos marcados **(bilingüe)** guardan su versión en español y en inglés. El Excel actual del catálogo está en español; el nombre en inglés de cada programa puede partir de `credential_en`, mientras que descripciones y demás textos en inglés se completarán en la administración del catálogo. La forma exacta de guardar los campos bilingües (columnas `_es`/`_en` o columna JSON de traducciones) la define el Bloque 0.

**Relación en una frase:** un contacto (único por institución+correo) tiene muchas conversaciones (cada una con un bot y un modo); cada conversación tiene mensajes y genera eventos e intereses que apuntan a programas del catálogo; los leads consolidan la señal comercial; la institución envuelve todo.

## 8. Alcance del día 1 y visión futura

**Día 1 (MVP que reemplaza Sheets y sale a producción):** el widget de chat bilingüe (con selector de idioma) embebido en la landing de Microcredenciales, con un bot (Celia), navegación guiada + emparejador + Celia; captura de nombre y correo; CRM núcleo en MySQL; catálogo importado; panel admin básico con roles; credenciales cifradas; y correo transaccional de confirmación al lead.

**Después, sin parar producción:** constructor visual del árbol conversacional, administración completa de la base de conocimiento, SMTP y campañas a prospectos, analítica avanzada, integraciones (Moodle, Stripe, n8n como motor de automatización externo), y el segundo bot (Sofia). El sistema nunca debe asumir que Microcredenciales o MCA son el único caso.

## 9. Mapa de módulos (secuencia de construcción)

El sistema se construye en este orden, respetando dependencias:

0. **Cimiento** — propuesta de stack afinado, estructura del repositorio, estrategia de multi-institución, de secretos y de bilingüismo (i18n), y esqueleto Laravel + base de datos.
1. **Autenticación y panel** — usuarios, roles, login, cascarón del admin.
2. **Integraciones y secretos** — almacén de credenciales cifradas.
3. **Catálogo** — importación del Excel a programas, categorías y etiquetas.
4. **CRM Core** — contactos, leads, conversaciones, mensajes, eventos, intereses.
5. **Widget + navegación guiada + emparejador** — widget bilingüe con selector de idioma, flujo por botones sin IA, "Ayúdame a elegir".
6. **Celia** — adaptador de IA, base de conocimiento transversal, retrieval Forma A.
7. **Correo transaccional** — confirmación al lead.

Con los bloques 0–7 el sistema está en producción. Los módulos posteriores (constructor visual, conocimiento administrable, SMTP/campañas, analítica, Sofia) se suman después.

## 10. Cómo empezar (instrucción para Claude Code)

Este documento es tu contexto permanente. **No construyas nada todavía.**

Cuando recibas la orden del **Bloque 0**, tu primer trabajo **no es programar funcionalidades**, sino devolvernos una **propuesta de arquitectura** para revisión: stack afinado, estructura del repositorio, modelo de datos en migraciones, estrategia de autenticación, estrategia de gestión de secretos, y estrategia de multi-institución. No escribas código de módulos hasta que esa propuesta esté aprobada.

A partir de ahí, cada bloque se te encargará por separado. **Construye solo el bloque que se te pide, complétalo y déjalo probado antes de continuar.** No adelantes trabajo de bloques futuros. Si detectas que un bloque necesita algo de otro que aún no existe, señálalo y espera indicación, en lugar de construirlo por tu cuenta.
