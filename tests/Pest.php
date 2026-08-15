<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Pest — enlace de casos base
|--------------------------------------------------------------------------
| Las pruebas Feature (incluida la suite de aislamiento multi-institucion) y
| las de cada modulo usan TestCase + RefreshDatabase sobre la BD MySQL de prueba.
*/

uses(TestCase::class, RefreshDatabase::class)->in('Feature');
uses(TestCase::class)->in('Unit');

// Pruebas de cada modulo (Modules/*/tests). Migran la BD igual que Feature.
uses(TestCase::class, RefreshDatabase::class)->in(
    __DIR__.'/../Modules/Ai/tests',
    __DIR__.'/../Modules/Audit/tests',
    __DIR__.'/../Modules/Catalog/tests',
    __DIR__.'/../Modules/Chat/tests',
    __DIR__.'/../Modules/Core/tests',
    __DIR__.'/../Modules/Crm/tests',
    __DIR__.'/../Modules/Identity/tests',
    __DIR__.'/../Modules/Institutions/tests',
    __DIR__.'/../Modules/Integrations/tests',
    __DIR__.'/../Modules/Notifications/tests',
);
