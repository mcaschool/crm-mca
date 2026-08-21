<?php

declare(strict_types=1);

use App\Models\User;
use Livewire\Livewire;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Institutions\Models\Institution;
use Modules\Notifications\Livewire\EmailTemplates\Manage;
use Modules\Notifications\Models\EmailTemplate;
use Modules\Notifications\Support\EmailBodyTransport;

it('REPRO: guardar plantilla con HTML de modo código (documento completo) no revienta', function () {
    $institution = Institution::factory()->create();
    $admin = User::factory()->create(['institution_id' => $institution->id, 'role' => 'admin']);

    app(CurrentInstitution::class)->set($institution->id);
    test()->actingAs($admin);

    $html = '<!DOCTYPE html><html><head><meta charset="utf-8"><style>.x{color:red}</style></head><body>'
        .'<!--[if mso]><table><tr><td><![endif]-->'
        .'<table width="600" align="center" cellpadding="0" cellspacing="0" bgcolor="#f4f7fb" style="border-collapse:collapse">'
        .'<tr><td style="padding:24px;background-color:#ffffff"><h1 style="color:#13253D;font-family:Arial">Boletín ñandú €5 🎓</h1>'
        .'<table width="100%"><tr><td style="padding:6px"><a href="https://mcaschool.education/" style="color:#1E5AA8">Programa</a></td></tr></table>'
        .'<a href="https://mcaschool.education/" style="display:inline-block;background:#1E5AA8;color:#fff;padding:12px 22px;border-radius:8px;text-decoration:none">Ver</a>'
        .'</td></tr></table>'
        .'<!--[if mso]></td></tr></table><![endif]--></body></html>';

    Livewire::test(Manage::class, ['scope' => 'shared'])
        ->call('newTemplate')
        ->set('name', 'Boletín diseño')
        ->set('subject', 'Hola [Nombre]')
        ->set('body', $html)
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('showForm', false);

    app(CurrentInstitution::class)->runFor($institution->id, function () {
        $t = EmailTemplate::query()->where('name', 'Boletín diseño')->first();
        expect($t)->not->toBeNull();
        expect($t->body)->toContain('<table')->toContain('background: #1E5AA8')
            ->and($t->body)->not->toContain('<script')->and($t->body)->not->toContain('<style');
    });
});

it('el marcador base64 NO contiene @ (Blade escapa @@ y rompería la decodificación)', function () {
    // Regresión: '@@B64@@' se convertía en '@B64@@' al compilar el JS dentro del Blade,
    // así el marcador emitido por el navegador no casaba con la constante PHP.
    expect(\Modules\Notifications\Support\EmailBodyTransport::MARKER)->not->toContain('@');

    $t = new \Modules\Notifications\Support\EmailBodyTransport;
    expect($t->decode($t::MARKER.base64_encode('<b>hola ñ €</b>')))->toBe('<b>hola ñ €</b>');
    expect($t->decode('<p>texto sin marcador</p>'))->toBe('<p>texto sin marcador</p>'); // retrocompatible
});

it('el cuerpo llega BASE64 (como lo envía el editor real) y se decodifica, sanea y guarda', function () {
    $institution = Institution::factory()->create();
    $admin = User::factory()->create(['institution_id' => $institution->id, 'role' => 'admin']);

    app(CurrentInstitution::class)->set($institution->id);
    test()->actingAs($admin);

    $design = '<table width="600" bgcolor="#f4f7fb"><tr><td style="padding:20px;background:#1E5AA8;color:#fff">Botón</td></tr></table><script>evil()</script>';
    // Como lo manda el editor: marcador + base64 (payload WAF-safe, sin HTML crudo).
    $encoded = EmailBodyTransport::MARKER.base64_encode($design);
    expect($encoded)->not->toContain('<script')->and($encoded)->not->toContain('<table'); // el payload no lleva HTML

    Livewire::test(Manage::class, ['scope' => 'shared'])
        ->call('newTemplate')
        ->set('name', 'Base64 diseño')
        ->set('subject', 'Asunto')
        ->set('body', $encoded)
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('showForm', false);

    app(CurrentInstitution::class)->runFor($institution->id, function () {
        $t = EmailTemplate::query()->where('name', 'Base64 diseño')->first();
        expect($t)->not->toBeNull();
        expect($t->body)->toContain('<table')->toContain('background: #1E5AA8')
            ->and($t->body)->not->toContain('<script')->and($t->body)->not->toContain('evil(');
    });
});
