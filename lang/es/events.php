<?php

declare(strict_types=1);

/*
 * Etiquetas legibles del historial de eventos (es). La clave es el event_type
 * crudo guardado en events.event_type. Mapea TODOS los tipos conocidos; los que
 * no estan aqui caen a una version "humanizada" del codigo (ver Event::label()).
 */

return [
    'widget_opened' => 'Abrió el chat',
    'contact_created' => 'Contacto creado',
    'lead_captured' => 'Lead capturado',
    'lead_created' => 'Lead creado',
    'started_celia' => 'Inició conversación con Celia',
    'used_matcher' => 'Usó el emparejador',
    'program_interest' => 'Interés en un programa',
    'viewed_program' => 'Vio un programa',
    'viewed_catalog' => 'Vio el catálogo',
    'viewed_certification' => 'Vio la certificación',
    'viewed_duration' => 'Vio la duración',
    'viewed_price' => 'Vio el precio',
    'viewed_microcredential_definition' => 'Vio qué es una microcredencial',
    'clicked_enrollment' => 'Fue a inscripciones',
    'corporate_interest' => 'Interés corporativo detectado',
    'corporate_contact' => 'Vio el contacto corporativo',
    'corporate_form' => 'Abrió el formulario corporativo',
    'unresolved_question' => 'Pregunta sin resolver',
    'lead_transferred' => 'Seguimiento transferido',
    'recontacted' => 'Volvió a contactar',
    'email_sent' => 'Correo enviado',
];
