<?php

declare(strict_types=1);
require __DIR__.'/../vendor/autoload.php';
if (($_ENV['APP_ENV'] ?? '') !== 'testing'
    || ($_ENV['DB_HOST'] ?? '') !== '127.0.0.1'
    || ($_ENV['DB_PORT'] ?? '') !== '33317'
    || ($_ENV['DB_DATABASE'] ?? '') !== 'crm_mca_collaboration_core_test'
    || is_file(__DIR__.'/../bootstrap/cache/config.php')) {
    throw new RuntimeException('Collaboration tests require the dedicated local test server and no cached config.');
}
