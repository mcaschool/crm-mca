<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Configuracion de dominio CRM-MCA
|--------------------------------------------------------------------------
|
| Valores de negocio del sistema. Se leen SIEMPRE via config('crm.*'),
| nunca con env() fuera de este archivo (regla: config:cache en produccion
| invalida cualquier env() disperso). Ver docs/BLOQUE-0-PROPUESTA-ARQUITECTURA.md.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Modo multi-institucion (flag maestro)
    |--------------------------------------------------------------------------
    | Modelo de producto: UNA institucion por instalacion. Cada cliente es una
    | copia separada del software en su propio servidor. El MOTOR multi-tenant
    | (institution_id, InstitutionScope, BelongsToInstitution, TenantAwareJob/
    | Command, suite de aislamiento) se conserva INTACTO y DORMANTE tras este flag.
    |
    | false (normal): se ocultan las superficies multi-institucion del panel
    |   (cambiador de institucion activa, gestion del atributo super-admin). La
    |   institucion activa se resuelve sola a la unica de la instalacion.
    | true: reaparecen esas superficies y el sistema se comporta multi-tenant.
    |
    | Si algun dia se activa, se hara una auditoria de aislamiento completa.
    */
    'multi_institution' => (bool) env('CRM_MULTI_INSTITUTION', false),

    /*
    |--------------------------------------------------------------------------
    | Internacionalizacion (i18n) — dos idiomas fijos (decision cerrada)
    |--------------------------------------------------------------------------
    | Interfaz: localizacion nativa de Laravel (lang/es, lang/en).
    | Contenido administrable: columnas _es / _en via HasTranslatedColumns.
    */
    'locales' => ['es', 'en'],

    // Si falta la traduccion pedida, se devuelve esta antes que una cadena vacia.
    'fallback_locale' => 'es',

    /*
    |--------------------------------------------------------------------------
    | Zona horaria (D9)
    |--------------------------------------------------------------------------
    | Se guarda TODO en UTC. La presentacion usa la zona de la institucion;
    | este es el valor por defecto cuando la institucion no fija una.
    */
    'default_timezone' => 'America/New_York',

    /*
    |--------------------------------------------------------------------------
    | Dominio del panel administrativo (D7)
    |--------------------------------------------------------------------------
    | El panel va en su propio subdominio, separado de la API del widget. En
    | produccion se fija PANEL_DOMAIN (p. ej. crm.mcaschool.us). Vacio = sin
    | restriccion de dominio (desarrollo local y pruebas).
    */
    'panel_domain' => env('PANEL_DOMAIN'),

    /*
    |--------------------------------------------------------------------------
    | URL base para el snippet de incrustacion del widget
    |--------------------------------------------------------------------------
    | Dominio (con https://) desde el que se sirve el widget en produccion. Se usa
    | para generar el <script> de incrustacion que el Admin copia y pega en la web
    | publica. Configurable por entorno; por defecto el dominio de produccion de MCA.
    | NO afecta al widget en si, solo al snippet que se muestra en el panel.
    */
    'widget_embed_url' => rtrim((string) env('CRM_WIDGET_EMBED_URL', 'https://crm.mcaschool.education'), '/'),

    // Separacion del lanzador del widget desde el borde inferior (px) que se incluye
    // en el snippet de incrustacion. 90 evita chocar con un boton flotante tipico de
    // "subir arriba"; el widget acepta cualquier valor via data-offset-bottom.
    'widget_offset_bottom' => (int) env('CRM_WIDGET_OFFSET_BOTTOM', 90),

    // Version del asset del widget: se anexa como ?v= al src de celia.js en el snippet
    // para forzar recarga tras un redeploy (evita que el navegador/CDN del cliente
    // sirva un celia.js cacheado). Subir este valor al cambiar el widget.
    'widget_asset_version' => (string) env('CRM_WIDGET_ASSET_VERSION', '2'),

    /*
    |--------------------------------------------------------------------------
    | Leads (D3, D4)
    |--------------------------------------------------------------------------
    */
    'lead' => [
        // D3 — embudo comercial.
        'statuses' => ['new', 'contacted', 'qualified', 'enrolled', 'discarded'],
        'interest_levels' => ['low', 'medium', 'high'],
        // product_type es extensible; day 1 solo microcredential.
        'product_types' => ['microcredential'],

        // D4 — se abre un lead nuevo tras N dias de inactividad o cambio de producto.
        'reopen_after_days' => (int) env('CRM_LEAD_REOPEN_DAYS', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Retencion de datos (D5) — coordinado con consentimiento (D2)
    |--------------------------------------------------------------------------
    | Tras N meses se purgan los `messages`; contacto, lead y eventos se
    | conservan. Configurable para cumplir peticiones de supresion RGPD.
    */
    'retention' => [
        'messages_months' => (int) env('CRM_MESSAGE_RETENTION_MONTHS', 24),
    ],

    /*
    |--------------------------------------------------------------------------
    | Widget publico — anti-abuso (D8): rate limit OBLIGATORIO
    |--------------------------------------------------------------------------
    | Sin CAPTCHA al inicio (honeypot + rate limit). El endpoint de mensajes
    | (unico que gasta tokens de IA) lleva un limite mas estricto.
    */
    'widget' => [
        'rate_per_min' => (int) env('CRM_WIDGET_RATE_PER_MIN', 30),
        'message_rate_per_min' => (int) env('CRM_WIDGET_MESSAGE_RATE_PER_MIN', 8),

        // Ventana de reanudacion de sesion (minutos). Al volver dentro de la ventana
        // (recargar/minimizar) se reanuda la MISMA conversacion; al volver despues,
        // el regreso se trata como una conversacion NUEVA (el contador de
        // CONVERSACIONES crece y, si el contacto ya existia, se marca el re-contacto).
        'session_resume_minutes' => (int) env('CRM_WIDGET_SESSION_RESUME_MINUTES', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Procesos de IA (capa agnostica) — se configuran por institucion/bot
    |--------------------------------------------------------------------------
    | Catalogo de procesos elegibles. La asignacion proveedor+modelo vive en
    | la tabla ai_process_configs, no aqui.
    */
    'ai_processes' => ['conversation', 'classification', 'summary', 'email_draft'],

    /*
    |--------------------------------------------------------------------------
    | Tipos de integracion admitidos
    |--------------------------------------------------------------------------
    */
    'integration_types' => ['ai_provider', 'google', 'n8n', 'mailrelay', 'mailjet', 'smtp', 'stripe', 'moodle'],

    'ai_providers' => ['openai', 'gemini', 'anthropic', 'deepseek', 'qwen', 'kimi'],

    /*
    |--------------------------------------------------------------------------
    | Areas de microcredencial (filtro del CRM) — CONFIGURABLE
    |--------------------------------------------------------------------------
    | Lista de areas usada por el filtro de leads. No hardcodeada en el codigo:
    | se puede ampliar/renombrar aqui. El filtro compara contra el area del
    | emparejador/interes del lead (leads.area).
    */
    'microcredential_areas' => [
        'Administración y Negocios',
        'Liderazgo',
        'Economía y Finanzas',
        'Marketing y Marca Personal',
        'Comunicación',
    ],

    /*
    |--------------------------------------------------------------------------
    | Departamentos del equipo (transferencia de seguimiento)
    |--------------------------------------------------------------------------
    */
    'departments' => ['Admisiones', 'Marketing', 'Administrativo', 'Contabilidad y Finanzas', 'Soporte', 'Académico'],

    /*
    |--------------------------------------------------------------------------
    | Celia (Bloque 6) — asesora academica con IA, en su propio modo
    |--------------------------------------------------------------------------
    | El system prompt (barreras de comportamiento) vive AQUI, protegido, NO en
    | los .md de conocimiento. La KB (hechos) se administra por archivos; las
    | reglas de conducta son configuracion del sistema.
    */
    'celia' => [
        // Control de costos: mensajes de IA por sesion (los resueltos por botones no cuentan).
        'message_limit' => (int) env('CRM_CELIA_MESSAGE_LIMIT', 15),
        // Longitud maxima de la respuesta (caracteres).
        'max_reply_chars' => (int) env('CRM_CELIA_MAX_REPLY_CHARS', 900),
        // La carpeta de conocimiento es el disco 'knowledge' (storage/app/knowledge),
        // definido en config/filesystems.php. El panel "Sincronizar" la reprocesa.
        // Cuantas secciones de KB (por relevancia) se envian al modelo (control de costo).
        'knowledge_sections' => (int) env('CRM_CELIA_KB_SECTIONS', 3),
        // Cuantos mensajes recientes se envian como memoria de la conversacion.
        'history_messages' => (int) env('CRM_CELIA_HISTORY', 6),

        // Enlaces de derivacion (la web es la fuente de la verdad de precio/temario).
        'catalog_url' => [
            'es' => 'https://mcaschool.education/es/microcredenciales/',
            'en' => 'https://mcaschool.education/es/microcredenciales/',
        ],
        'enrollment_url' => [
            'es' => 'https://mcaschool.education/es/microcredenciales/inscripciones/',
            'en' => 'https://mcaschool.education/es/microcredenciales/inscripciones/',
        ],

        // Canal corporativo (InCompany): correo + formulario. Celia encamina aqui
        // las consultas de formacion para empresas/equipos.
        'corporate_email' => 'academics@mcaschool.education',
        'corporate_form_url' => [
            'es' => 'https://mcaschool.education/es/microcredenciales/solicitud-de-formacion-corporativa',
            'en' => 'https://mcaschool.education/es/microcredenciales/solicitud-de-formacion-corporativa',
        ],

        // Barreras de comportamiento (protegidas). Se combinan con la KB y el
        // contexto en cada llamada. Celia responde en JSON para enrutar acciones.
        'system_prompt' => [
            'es' => "Eres Celia, asesora académica INTELIGENTE (no humana, y no lo ocultas) de las Microcredenciales de MCA School. Tono profesional, cercano, preciso; sin frases genéricas de chatbot. Sabes lo mismo que el bot de navegación: entregas ese conocimiento de forma natural, no sabes más.\n\nIDIOMA Y ORTOGRAFÍA: escribe en español correcto y natural, CON TILDES y signos de apertura (á, é, í, ó, ú, ñ, ¿, ¡). El JSON es UTF-8 y admite acentos sin ningún problema; NUNCA omitas tildes ni escribas solo en ASCII.\n\nENLACES OFICIALES (usa exactamente estos, sin inventar otros):\n- Inscripciones (aquí se aplican becas y cupones): https://mcaschool.education/es/microcredenciales/inscripciones/\n- Catálogo de programas: https://mcaschool.education/es/microcredenciales/\n\nREGLAS INQUEBRANTABLES:\n- PRIORIDAD ABSOLUTA — RESPONDE, NO LANCES EL MENÚ: si la pregunta tiene respuesta en tu CONOCIMIENTO (por ejemplo duración, metodología, formas/métodos de pago, certificación, titulación, requisitos generales, proceso de matrícula), DEBES responderla con esa información y action='answer'. El menú de opciones y el emparejador son un ÚLTIMO RECURSO, SOLO cuando de verdad NO tienes el dato; NUNCA los uses para eludir una pregunta que sí puedes responder.\n- NUNCA inventes precios, temarios, fechas, horas académicas, créditos, cupones concretos, programas, avales ni reconocimientos que no estén en tu CONOCIMIENTO. Si no tienes el dato con certeza, reconócelo con naturalidad, deriva a la ficha del programa o a inscripciones, y marca action='unresolved'.\n- Precio, temario o fechas concretas: NO cites cifras; deriva a la web (ficha/inscripciones).\n- DURACIÓN vs CARGA ACADÉMICA: la duración en TIEMPO (semanas, a ritmo propio) es distinta de la carga en HORAS ACADÉMICAS o créditos; son cosas diferentes. NO respondas con la duración en semanas cuando te pregunten por horas académicas o créditos. Si no tienes ese dato concreto en tu conocimiento, reconócelo y deriva a la ficha del programa (action='unresolved').\n- BECAS Y CUPONES: orienta a verificar en la página de inscripciones si actualmente hay alguna beca disponible; si aparece un cupón activo, que lo aplique durante la inscripción para que la beca se haga efectiva. Incluye SIEMPRE el enlace de inscripciones. NO uses la palabra 'promociones'.\n- Al recomendar un programa concreto, incluye SIEMPRE su enlace, o lanza el emparejador (action='start_matcher') para una recomendación personalizada con enlaces.\n- Otros programas de MCA (Maestrías, etc.) o cualquier tema fuera de tu alcance: deriva al sitio institucional escribiendo la URL COMPLETA y clicable https://mcaschool.education/ (SIEMPRE con https:// y bien escrita, para que se active como enlace).\n- El soporte académico/técnico es solo para estudiantes matriculados. Tú eres el apoyo de los prospectos: NO ofrezcas derivación a un humano ni prometas llamadas o asesores.\n- Los programas se imparten solo en español por ahora; si el usuario escribe en inglés, respondes en inglés y lo aclaras cuando aplique.\n- Responde en el idioma del usuario.\n- Cuando conozcas el nombre del usuario (ya dejó sus datos), dirígete a él SOLO por su PRIMER NOMBRE; nunca por nombre y apellido.\n\nFORMATO: responde SIEMPRE con JSON válido: {\"reply\": \"tu respuesta breve y útil, en español con tildes\", \"action\": \"answer|unresolved|start_matcher\"}. Usa 'unresolved' cuando no tengas el dato. Usa 'start_matcher' si conviene lanzar el emparejador de 5 preguntas para orientar. 'answer' en los demás casos. No incluyas nada fuera del JSON.",
            'en' => "You are Celia, an INTELLIGENT (not human, and you don't hide it) academic advisor for MCA School's Microcredentials. Professional, warm, precise tone; no generic chatbot phrases. You know the same as the guided bot: you deliver that knowledge naturally, nothing more.\n\nLANGUAGE & SPELLING: reply in the user's language with correct spelling and punctuation. When you reply in Spanish, use proper accents and opening marks (á, é, í, ó, ú, ñ, ¿, ¡); the JSON is UTF-8 and supports them, so NEVER drop accents.\n\nOFFICIAL LINKS (use exactly these, do not invent others):\n- Enrollment (this is where grants and coupons are applied): https://mcaschool.education/es/microcredenciales/inscripciones/\n- Program catalog: https://mcaschool.education/es/microcredenciales/\n\nUNBREAKABLE RULES:\n- ABSOLUTE PRIORITY — ANSWER, DO NOT SHOW THE MENU: if the question can be answered from your KNOWLEDGE (e.g. duration, methodology, payment methods, certification, general requirements, enrollment process), you MUST answer it with that information and action='answer'. The options menu and the matcher are a LAST RESORT, ONLY when you truly lack the fact; NEVER use them to dodge a question you can answer.\n- NEVER invent prices, syllabi, dates, academic hours, credits, specific coupons, programs, endorsements or recognitions not in your KNOWLEDGE. If you don't have the fact with certainty, acknowledge it naturally, point to the program page or enrollment, and set action='unresolved'.\n- Specific price, syllabus or dates: do NOT cite figures; point to the website (program page/enrollment).\n- DURATION vs ACADEMIC LOAD: duration in TIME (weeks, self-paced) is different from the load in ACADEMIC HOURS or credits; they are distinct things. Do NOT answer with the duration in weeks when asked about academic hours or credits. If you don't have that specific figure in your knowledge, acknowledge it and point to the program page (action='unresolved').\n- GRANTS AND COUPONS: guide the user to check on the enrollment page whether there is currently any grant available; if an active coupon appears, have them apply it during enrollment so the grant takes effect. ALWAYS include the enrollment link. Do NOT use the word 'promotions'.\n- When recommending a specific program, ALWAYS include its link, or launch the matcher (action='start_matcher') for a personalized recommendation with links.\n- Other MCA programs (Master's, etc.) or anything outside your scope: point to the institutional site writing the FULL, clickable URL https://mcaschool.education/ (ALWAYS with https:// and well-formed, so it becomes a link).\n- Academic/technical support is only for enrolled students. You support prospects: do NOT offer human handoff or promise calls or advisors.\n- Programs are currently taught in Spanish only; if the user writes in English, reply in English and clarify this when relevant.\n- Reply in the user's language.\n- When you know the user's name (they've left their details), address them by their FIRST NAME only; never by full name.\n\nFORMAT: ALWAYS reply with valid JSON: {\"reply\": \"your brief, helpful answer\", \"action\": \"answer|unresolved|start_matcher\"}. Use 'unresolved' when you lack the fact. Use 'start_matcher' if launching the 5-question matcher would help. 'answer' otherwise. Output nothing outside the JSON.",
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Emparejador (Opcion B — 5 preguntas, determinista, sin IA)
    |--------------------------------------------------------------------------
    | area y meta se derivan del catalogo real (categorias y goals). Aqui van las
    | opciones fijas y el mapeo seniority+educacion -> nivel (logica confirmada).
    | La motivacion NO filtra: se guarda como senal en el CRM.
    */
    'matcher' => [
        'seniority' => ['estudiante', 'inicio', 'desarrollo', 'mando_medio', 'directivo', 'empresario'],
        'educacion' => ['secundaria', 'tecnico', 'universitario_incompleto', 'universitario_completo', 'posgrado'],
        'motivacion' => ['mejorar_empleo', 'ascender', 'reconversion', 'emprender', 'crecimiento_personal'],

        // seniority -> nivel base.
        'seniority_level' => [
            'estudiante' => 'inicial',
            'inicio' => 'inicial',
            'desarrollo' => 'intermedio',
            'mando_medio' => 'intermedio',
            'directivo' => 'avanzado',
            'empresario' => 'avanzado',
        ],

        // Educaciones que NO bajan el nivel. El resto lo afina un escalon a la baja.
        'educacion_alta' => ['universitario_completo', 'posgrado'],

        'levels_order' => ['inicial', 'intermedio', 'avanzado'],
    ],
];
