<?php

declare(strict_types=1);

use Modules\Crm\Support\LeadIntakeChannel;

/** Detección centralizada del origen WhatsApp: prefijo whatsapp_ y channel/source. */
it('reconoce cualquier form con prefijo whatsapp_ (lista abierta)', function () {
    foreach (['whatsapp_maestrias', 'whatsapp_diplomas', 'whatsapp_micromba', 'whatsapp_pe', 'whatsapp_lo_que_sea'] as $form) {
        expect(LeadIntakeChannel::isWhatsApp(null, null, $form))->toBeTrue();
    }
});

it('NO activa por «whatsapp» en otra posición ni sin guion bajo', function () {
    expect(LeadIntakeChannel::isWhatsApp(null, null, 'solicitud_whatsapp_web'))->toBeFalse();
    expect(LeadIntakeChannel::isWhatsApp(null, null, 'mi_whatsapp'))->toBeFalse();
    expect(LeadIntakeChannel::isWhatsApp(null, null, 'whatsapp'))->toBeFalse(); // sin guion bajo
    expect(LeadIntakeChannel::isWhatsApp(null, null, 'web_form'))->toBeFalse();
});

it('activa por channel o source = whatsapp, tolerando espacios y mayúsculas', function () {
    expect(LeadIntakeChannel::isWhatsApp('  WhatsApp  ', null, 'web_form'))->toBeTrue();
    expect(LeadIntakeChannel::isWhatsApp(null, 'WHATSAPP', 'microcredenciales_inscripcion'))->toBeTrue();
});

it('con valores nulos/ vacíos no activa', function () {
    expect(LeadIntakeChannel::isWhatsApp(null, null, null))->toBeFalse();
    expect(LeadIntakeChannel::isWhatsApp('', '', ''))->toBeFalse();
});
