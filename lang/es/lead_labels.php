<?php

declare(strict_types=1);

/*
 * Etiquetas del lead. `source` = MOTIVO/DISPARADOR por el que el contacto se
 * convirtio en lead (regla de conversion). Los tipos desconocidos caen a una
 * version humanizada del codigo (ver Lead::sourceLabel()).
 *
 * OJO: el archivo se llama lead_labels (no "leads") a proposito: en un sistema de
 * archivos insensible a mayusculas, un archivo lang/leads.php colisionaria con la
 * clave de traduccion __('Leads') usada en el panel.
 */

return [
    'source' => [
        'widget_matcher' => 'Emparejador',
        'corporate' => 'Interés corporativo (InCompany)',
        'program' => 'Interés en un programa',
        'pricing' => 'Precio / inscripción',
        'manual' => 'Alta manual',
    ],
];
