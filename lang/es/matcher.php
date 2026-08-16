<?php

declare(strict_types=1);

// Etiquetas de las opciones del emparejador (placeholder, a afinar con el
// recomendador de referencia). area y meta salen del catalogo real, no de aqui.

return [
    'seniority' => [
        'estudiante' => 'Soy estudiante',
        'inicio' => 'Estoy iniciando mi carrera',
        'desarrollo' => 'En desarrollo profesional',
        'mando_medio' => 'Mando medio',
        'directivo' => 'Cargo directivo',
        'empresario' => 'Empresario / emprendedor',
    ],
    'educacion' => [
        'secundaria' => 'Secundaria',
        'tecnico' => 'Tecnico',
        'universitario_incompleto' => 'Universitario (en curso)',
        'universitario_completo' => 'Universitario completo',
        'posgrado' => 'Posgrado',
    ],
    'motivacion' => [
        'mejorar_empleo' => 'Mejorar mi empleabilidad',
        'ascender' => 'Ascender en mi trabajo',
        'reconversion' => 'Reconvertir mi carrera',
        'emprender' => 'Emprender un negocio',
        'crecimiento_personal' => 'Crecimiento personal',
    ],
    'questions' => [
        'motivacion' => '¿Que te motiva a estudiar ahora?',
        'meta' => '¿Cual es tu meta principal?',
        'area' => '¿Que area te interesa?',
        'seniority' => '¿En que momento estas de tu carrera?',
        'educacion' => '¿Cual es tu nivel de estudios?',
    ],
];
