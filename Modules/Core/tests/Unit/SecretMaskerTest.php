<?php

declare(strict_types=1);

use Modules\Core\Support\SecretMasker;

it('enmascara conservando 3 al inicio y 4 al final', function () {
    expect(SecretMasker::mask('sk-proj-AbCdEf1234567890-8Jk2'))->toBe('sk-••••8Jk2');
});

it('enmascara por completo los secretos cortos (< 8)', function () {
    expect(SecretMasker::mask('abc123'))->toBe('••••');
    expect(SecretMasker::mask('1234567'))->toBe('••••');
});

it('reconoce claves secretas por su nombre', function () {
    expect(SecretMasker::isSecretKey('api_key'))->toBeTrue();
    expect(SecretMasker::isSecretKey('client_secret'))->toBeTrue();
    expect(SecretMasker::isSecretKey('access_token'))->toBeTrue();
    expect(SecretMasker::isSecretKey('password'))->toBeTrue();
    expect(SecretMasker::isSecretKey('base_url'))->toBeFalse();
    expect(SecretMasker::isSecretKey('model'))->toBeFalse();
});

it('enmascara solo las claves secretas y deja los metadatos en claro', function () {
    $preview = SecretMasker::maskConfig([
        'api_key' => 'sk-proj-AbCdEf1234567890-8Jk2',
        'base_url' => 'https://api.openai.com/v1',
        'model' => 'gpt-5-mini',
    ]);

    expect($preview['api_key'])->toBe('sk-••••8Jk2');
    expect($preview['base_url'])->toBe('https://api.openai.com/v1');
    expect($preview['model'])->toBe('gpt-5-mini');
});
