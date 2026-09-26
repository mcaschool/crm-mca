<?php

declare(strict_types=1);

use Modules\Core\Support\PhoneNumber;

/** Normalización canónica: conservadora, sin inventar códigos de país. */
it('canoniza números internacionales claros (+, espacios, guiones)', function () {
    expect(PhoneNumber::normalize('+1 809 555 1234'))->toBe('+18095551234');
    expect(PhoneNumber::normalize('+34-600-111-222'))->toBe('+34600111222');
    expect(PhoneNumber::normalize('+593 982246391'))->toBe('+593982246391');
});

it('acepta el prefijo 00 como internacional (00 → +)', function () {
    expect(PhoneNumber::normalize('0018095551234'))->toBe('+18095551234');
});

it('trata los nacionales AMBIGUOS (sin + ni país) como null (no inventa país)', function () {
    expect(PhoneNumber::normalize('5551234'))->toBeNull();
    expect(PhoneNumber::normalize('809-555-1234'))->toBeNull();
    expect(PhoneNumber::normalize(''))->toBeNull();
    expect(PhoneNumber::normalize(null))->toBeNull();
});

it('normaliza un número internacional sin + SOLO si el origen lo garantiza (WhatsApp)', function () {
    expect(PhoneNumber::normalize('18095551234', assumeInternational: true))->toBe('+18095551234');
    // Sin la garantía, el mismo número es ambiguo.
    expect(PhoneNumber::normalize('18095551234'))->toBeNull();
});

it('rechaza longitudes fuera de los límites E.164 (8–15 dígitos)', function () {
    expect(PhoneNumber::normalize('+1234'))->toBeNull();               // demasiado corto
    expect(PhoneNumber::normalize('+1234567890123456'))->toBeNull();   // demasiado largo
    expect(PhoneNumber::normalize('+abcdef'))->toBeNull();             // sin dígitos
});
