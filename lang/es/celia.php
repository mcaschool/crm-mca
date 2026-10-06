<?php

declare(strict_types=1);

// Textos de Celia visibles en el widget. Las BARRERAS de conducta viven en
// config/crm.php (system_prompt), no aqui: esto es solo interfaz.

return [
    // Saludo con memoria. :name se sustituye; si esta vacio se limpia el espacio.
    'greeting' => 'Hola :name, soy :advisor, la asesora académica inteligente para los programas de Microcredenciales de MCA School. ¿En qué te puedo ayudar?',
    'greeting_language_note' => 'Los programas se imparten por ahora solo en español, pero puedo orientarte en inglés.',
    'context_viewed_programs' => 'Vi que te interesó: :programs.',
    'context_topics' => 'Estuviste consultando sobre :topics.',

    // Paso 1 (filtro por palabras clave, sin IA): se ofrecen botones del arbol.
    // Transicion natural y contextual (solo cuando el arbol SI cubre el tema).
    'route_to_buttons' => 'Puedo ayudarte con eso. ¿Cuál de estos temas te interesa?',

    // Control de costos.
    'near_limit' => 'Podemos seguir un poco más, pero para no dejarte esperando: si quieres el detalle fino (precio, temario, fechas) está en el catálogo, y puedes inscribirte cuando quieras. ¿Seguimos?',
    'limit_reached' => 'Hemos conversado bastante y no quiero hacerte esperar. El detalle de cada programa está en el catálogo, y la inscripción está abierta cuando lo decidas. Si prefieres, déjame tu consulta y la retomamos. Catálogo: :catalog',

    // Respaldo cuando la IA no está disponible o falla: honesto, deriva.
    'ai_unavailable' => 'Ahora mismo no puedo conversar en detalle, pero puedo orientarte con las opciones o derivarte al catálogo: :catalog',

    // Asesores con «Identidad e instrucciones» propias (no Celia): textos neutros, sin
    // Microcredenciales ni catálogo concreto.
    'greeting_custom' => 'Hola :name, soy :advisor, :role. ¿En qué te puedo ayudar?',
    'default_role' => 'asistente virtual de la institución',
    'limit_reached_custom' => 'Hemos conversado bastante y no quiero hacerte esperar. Si necesitas más detalle, el equipo de la institución puede ayudarte.',
    'ai_unavailable_custom' => 'Ahora mismo no puedo responder en detalle. Inténtalo de nuevo en unos minutos, por favor.',
];
