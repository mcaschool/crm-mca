<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Institutions\Models\Institution;
use Modules\Social\Livewire\Publisher;
use Modules\Social\Models\SocialChannel;

/*
| Publicador (modernización visual, etapa 5C): formato, archivo, descripción y destino en el editor;
| resumen, acción principal y resultado por red al lado. Solo presentación: mismas acciones wire:,
| mismas reglas de validación y ningún envío a Meta (Http::fake + assertNothingSent).
*/

beforeEach(function () {
    Http::fake();
});

function publisherAdmin(): User
{
    $institution = Institution::factory()->create();
    app(CurrentInstitution::class)->set($institution->id);
    $user = User::factory()->create(['institution_id' => $institution->id, 'role' => 'admin', 'status' => 'active']);
    config(['auth.two_factor.exempt_emails' => [mb_strtolower($user->email)]]);
    SocialChannel::factory()->create(['provider' => 'messenger', 'external_id' => 'PAGE_1', 'credentials' => ['token' => 'X']]);

    return $user;
}

it('el Publicador usa el kit: cabecera, formato, archivo, descripción, destino y publicación', function () {
    $html = $this->actingAs(publisherAdmin())->get('/social/publicar')->assertOk()->getContent();

    expect($html)->not->toContain('class="sp-')->not->toContain('.sp-wrap')
        ->toMatch('~<div class="mca-pagehead">.*?<h1>Publicador~s')
        ->toContain('class="ui-pub"')->toContain('id="pub-form"')
        ->toContain("wire:click=\"setContentType('post')\" aria-pressed=\"true\"")
        ->toContain("wire:click=\"setContentType('reel')\" aria-pressed=\"false\"")
        ->toContain('class="ui-drop"')->toContain('wire:model="image"')
        ->toContain('id="pub-caption"')->toContain('/ 2200')
        ->toContain('class="ui-nets"')->toContain('wire:model.live="toFacebook"')->toContain('wire:model.live="toInstagram"')
        ->toContain('type="submit" form="pub-form"')
        ->toContain('Aún no has publicado nada');
});

it('publicar sin archivo marca el campo y no envía nada', function () {
    $this->actingAs(publisherAdmin());

    Livewire::test(Publisher::class)->call('publish')
        ->assertHasErrors(['image' => 'required'])
        ->assertSeeHtml('id="pub-file-error"')->assertSeeHtml('ui-drop is-invalid')
        ->assertSee('El campo imagen es obligatorio.');

    Http::assertNothingSent();
});

it('las Historias se publican sin descripción y el resumen lo indica', function () {
    $this->actingAs(publisherAdmin());

    Livewire::test(Publisher::class)->call('setContentType', 'story')
        ->assertSee('Las Historias se publican sin descripción.')
        ->assertDontSeeHtml('id="pub-caption"')
        ->assertSee('No aplica')->assertSee('Publicar Historia');
});

it('el resultado por red usa insignias suaves y conserva «Ver post» y «Continuar»', function () {
    $this->actingAs(publisherAdmin());

    Livewire::test(Publisher::class)
        ->set('result', [
            ['id' => 1, 'network' => 'facebook', 'status' => 'published', 'external_post_id' => '1_2', 'error' => null, 'url' => 'https://www.facebook.com/1_2'],
            ['id' => 2, 'network' => 'instagram', 'status' => 'processing', 'external_post_id' => null, 'error' => 'Sigue procesando', 'url' => null],
        ])
        ->assertSeeHtml('ui-badge ui-tone--success')->assertSee('Publicado')
        ->assertSeeHtml('href="https://www.facebook.com/1_2"')->assertSee('Ver post')
        ->assertSeeHtml('ui-badge ui-tone--warning')->assertSee('Procesando')
        ->assertSeeHtml('wire:click="resumeTarget(2)"')->assertSee('Continuar')
        ->assertSee('Sigue procesando');

    Http::assertNothingSent();
});
