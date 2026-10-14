<?php

declare(strict_types=1);

use Modules\Audit\Support\AuditPresenter;
use Symfony\Component\Finder\Finder;

/*
| Auditoría (etapa 7): toda acción que el código registra tiene etiqueta propia y se presenta en el
| idioma activo (ES/EN). Las etiquetas son solo presentación: el identificador guardado en
| audit_logs.action no cambia y los registros históricos no se reescriben.
*/

/** @return array<string, array{label:string, group:string}> */
function auditActions(): array
{
    return (new ReflectionClass(AuditPresenter::class))->getConstant('ACTIONS');
}

/** Acciones que el código pasa a AuditService::log()/logAuth() (literales en los archivos que lo usan). */
function auditActionsInCode(): array
{
    $prefixes = '(?:auth|2fa|account|user|integration|contact|lead|incompany_lead|lead_intake|video_room|retention|program|meta|meta_lead_page)';
    $found = [];
    $files = Finder::create()->files()->name('*.php')->in(array_merge([base_path('app')], glob(base_path('Modules/*/app')) ?: []));
    foreach ($files as $file) {
        $src = $file->getContents();
        if (! preg_match('~->log(?:Auth)?\(~', $src) || ! str_contains($src, 'Audit')) {
            continue;
        }
        // Literales dentro de una llamada log()/logAuth() (incluye ternarios: log($x ? 'a.b' : 'c.d', …)).
        preg_match_all('~->log(?:Auth)?\(([^;]{0,240})~s', $src, $calls);
        foreach ($calls[1] as $args) {
            preg_match_all("~'(".$prefixes.'\.[a-z0-9_]+)\'~', $args, $m);
            foreach ($m[1] as $action) {
                $found[$action] = $file->getRelativePathname();
            }
        }
    }

    return $found;
}

it('cada acción que el código audita tiene etiqueta propia (no el texto técnico de reserva)', function () {
    $inCode = auditActionsInCode();
    expect(count($inCode))->toBeGreaterThan(20);

    $missing = array_diff_key($inCode, auditActions());
    expect($missing)->toBe([]);
});

it('las etiquetas de acciones y entidades se presentan en español y en inglés', function () {
    $en = json_decode((string) file_get_contents(lang_path('en.json')), true);
    $entities = (new ReflectionClass(AuditPresenter::class))->getConstant('ENTITIES');

    foreach (auditActions() as $action => $meta) {
        expect(array_key_exists($meta['label'], $en))->toBeTrue("sin inglés: {$action}");
    }
    foreach ($entities as $class => $label) {
        expect(array_key_exists($label, $en))->toBeTrue("sin inglés: {$class}");
    }

    app()->setLocale('es');
    expect(AuditPresenter::actionLabel('contact.phone_revealed'))->toBe('Teléfono del contacto revelado')
        ->and(AuditPresenter::actionLabel('incompany_lead.received'))->toBe('Lead InCompany recibido')
        ->and(AuditPresenter::actionLabel('video_room.assigned'))->toBe('Sala de videoconferencia asignada');

    app()->setLocale('en');
    expect(AuditPresenter::actionLabel('contact.phone_revealed'))->toBe($en['Teléfono del contacto revelado'])
        ->and(AuditPresenter::actionLabel('incompany_lead.received'))->toBe($en['Lead InCompany recibido'])
        ->and(AuditPresenter::actionLabel('video_room.assigned'))->toBe($en['Sala de videoconferencia asignada'])
        ->and(AuditPresenter::entityLabel('Modules\Crm\Models\VideoRoom', 7))->toBe($en['Sala de videoconferencia'].' #7');
    app()->setLocale('es');
});

it('una acción desconocida sigue mostrándose legible sin romper la vista', function () {
    expect(AuditPresenter::actionLabel('algo.nuevo_evento'))->toBe('Algo nuevo evento')
        ->and(AuditPresenter::actionGroup('algo.nuevo_evento'))->toBe('neutral');
});
