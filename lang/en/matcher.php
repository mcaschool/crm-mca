<?php

declare(strict_types=1);

// Matcher option labels (placeholder, to refine against the reference recommender).
// area and meta come from the real catalog, not from here.

return [
    'seniority' => [
        'estudiante' => 'I am a student',
        'inicio' => 'Starting my career',
        'desarrollo' => 'In professional development',
        'mando_medio' => 'Middle management',
        'directivo' => 'Executive role',
        'empresario' => 'Entrepreneur / business owner',
    ],
    'educacion' => [
        'secundaria' => 'High school',
        'tecnico' => 'Technical',
        'universitario_incompleto' => 'University (in progress)',
        'universitario_completo' => 'University degree',
        'posgrado' => 'Postgraduate',
    ],
    'motivacion' => [
        'mejorar_empleo' => 'Improve my employability',
        'ascender' => 'Get promoted',
        'reconversion' => 'Change careers',
        'emprender' => 'Start a business',
        'crecimiento_personal' => 'Personal growth',
    ],
    'questions' => [
        'motivacion' => 'What motivates you to study now?',
        'meta' => 'What is your main goal?',
        'area' => 'Which area interests you?',
        'seniority' => 'Where are you in your career?',
        'educacion' => 'What is your education level?',
    ],
];
