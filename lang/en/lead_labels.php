<?php

declare(strict_types=1);

/*
 * Lead labels. `source` = the REASON/TRIGGER that converted the contact into a
 * lead (conversion rule). Unknown types fall back to a humanized version of the
 * code (see Lead::sourceLabel()).
 *
 * NOTE: the file is named lead_labels (not "leads") on purpose: on a
 * case-insensitive filesystem a lang/leads.php file would collide with the
 * __('Leads') translation key used across the panel.
 */

return [
    'source' => [
        'widget_matcher' => 'Matcher',
        'corporate' => 'Corporate interest (InCompany)',
        'program' => 'Program interest',
        'pricing' => 'Pricing / enrollment',
        'manual' => 'Manual entry',
    ],
];
