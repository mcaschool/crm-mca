<?php

declare(strict_types=1);

/*
 * Human-readable labels for the event history (en). The key is the raw event_type
 * stored in events.event_type. Maps ALL known types; unknown ones fall back to a
 * humanized version of the code (see Event::label()).
 */

return [
    'widget_opened' => 'Opened the chat',
    'contact_created' => 'Contact created',
    'lead_captured' => 'Lead captured',
    'lead_created' => 'Lead created',
    'started_celia' => 'Started a chat with Celia',
    'used_matcher' => 'Used the matcher',
    'program_interest' => 'Interested in a program',
    'viewed_program' => 'Viewed a program',
    'viewed_catalog' => 'Viewed the catalog',
    'viewed_certification' => 'Viewed the certification',
    'viewed_duration' => 'Viewed the duration',
    'viewed_price' => 'Viewed the price',
    'viewed_microcredential_definition' => 'Viewed what a microcredential is',
    'clicked_enrollment' => 'Went to enrollment',
    'corporate_interest' => 'Corporate interest detected',
    'corporate_contact' => 'Viewed the corporate contact',
    'corporate_form' => 'Opened the corporate form',
    'unresolved_question' => 'Unresolved question',
    'lead_transferred' => 'Follow-up transferred',
    'recontacted' => 'Reached out again',
    'email_sent' => 'Email sent',
];
