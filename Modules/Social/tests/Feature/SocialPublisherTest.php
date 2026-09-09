<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Livewire\Livewire;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Institutions\Models\Institution;
use Modules\Social\Livewire\Publisher;
use Modules\Social\Models\SocialChannel;
use Modules\Social\Models\SocialPost;
use Modules\Social\Services\SocialPublishService;

/**
 * Publicador (Bloque 5): imagen + descripción → Facebook Página + Instagram, con Http::fake
 * (sin Meta real). Verifica el flujo IG de 2 pasos, la foto de FB, el éxito PARCIAL en ambos
 * sentidos, el fallo total, que se envía la URL pública (no binario), que solo se publica en
 * redes con canal, y el scoping por institución.
 */
beforeEach(function () {
    config(['social.graph_version' => 'v26.0']);
    Sleep::fake(); // el polling del contenedor IG no duerme de verdad en tests
});

/**
 * @return array{0: Institution, 1: User, 2: SocialChannel, 3: SocialChannel}
 */
function publisherCtx(): array
{
    $institution = Institution::factory()->create();
    app(CurrentInstitution::class)->set($institution->id);
    $user = User::factory()->create(['institution_id' => $institution->id, 'role' => 'marketing']);

    $fb = SocialChannel::factory()->create(['provider' => 'messenger', 'external_id' => 'PAGE_1', 'is_active' => true, 'credentials' => ['token' => 'FB_TOKEN']]);
    $ig = SocialChannel::factory()->create(['provider' => 'instagram', 'external_id' => 'IGU_1', 'is_active' => true, 'credentials' => ['token' => 'IG_TOKEN']]);

    return [$institution, $user, $fb, $ig];
}

function makePost(): SocialPost
{
    return SocialPost::factory()->create([
        'image_public_url' => 'https://cdn.mca.test/foto.jpg',
        'caption' => 'Nueva microcredencial 🎓',
    ]);
}

function publisher(): SocialPublishService
{
    return app(SocialPublishService::class);
}

/**
 * Fake que responde OK a las 3 llamadas del flujo IG y a la foto de FB. IG y FB comparten
 * host (graph.facebook.com), así que se distingue por PATH: /photos = Facebook; /media_publish
 * y /media = Instagram; el resto (GET del contenedor) = status FINISHED.
 */
function fakeAllOk(): void
{
    Http::fake(function ($request) {
        $url = $request->url();
        if (str_contains($url, '/photos')) {
            return Http::response(['id' => 'PHOTO_1', 'post_id' => 'FB_POST_1'], 200);
        }
        if (str_contains($url, '/media_publish')) {
            return Http::response(['id' => 'IG_POST_1'], 200);
        }
        if (str_contains($url, '/media')) {
            return Http::response(['id' => 'IG_CONT_1'], 200);
        }

        return Http::response(['status_code' => 'FINISHED'], 200);
    });
}

// ----------------------------------------------------------------------------------
// Instagram: flujo de 2 pasos (media → status FINISHED → media_publish)
// ----------------------------------------------------------------------------------
it('Instagram: hace el flujo de 2 pasos con URL pública y publica', function () {
    publisherCtx();
    fakeAllOk();

    $post = publisher()->publish(makePost(), ['instagram']);

    $t = $post->targets->firstWhere('network', 'instagram');
    expect($t->status)->toBe('published');
    expect($t->container_id)->toBe('IG_CONT_1');
    expect($t->external_post_id)->toBe('IG_POST_1');
    expect($post->status)->toBe('published');

    // Paso 1: contenedor con la URL PÚBLICA (no binario) y el caption. Host graph.facebook.com
    // (Page token EAA), nodo = IG User ID.
    Http::assertSent(fn ($r) => $r->url() === 'https://graph.facebook.com/v26.0/IGU_1/media'
        && $r['image_url'] === 'https://cdn.mca.test/foto.jpg'
        && $r['caption'] === 'Nueva microcredencial 🎓'
        && $r->hasHeader('Authorization', 'Bearer IG_TOKEN'));
    // Paso 2: publicar el contenedor.
    Http::assertSent(fn ($r) => str_contains($r->url(), '/media_publish') && $r['creation_id'] === 'IG_CONT_1');
});

// ----------------------------------------------------------------------------------
// Contenedor IG: IN_PROGRESS es normal al inicio → se espera con backoff acotado
// (el caso "FINISHED inmediato → éxito" lo cubre el test del flujo de 2 pasos de arriba)
// ----------------------------------------------------------------------------------
it('Instagram: contenedor IN_PROGRESS que pasa a FINISHED publica correctamente', function () {
    publisherCtx();
    $statusCalls = 0;
    Http::fake(function ($request) use (&$statusCalls) {
        $url = $request->url();
        if (str_contains($url, '/media_publish')) {
            return Http::response(['id' => 'IG_POST_WAIT'], 200);
        }
        if (str_contains($url, '/media')) {
            return Http::response(['id' => 'IG_CONT_WAIT'], 200);
        }
        $statusCalls++;

        return Http::response(['status_code' => $statusCalls < 3 ? 'IN_PROGRESS' : 'FINISHED'], 200);
    });

    $post = publisher()->publish(makePost(), ['instagram']);

    $t = $post->targets->firstWhere('network', 'instagram');
    expect($t->status)->toBe('published');
    expect($t->external_post_id)->toBe('IG_POST_WAIT');
    expect($t->container_id)->toBe('IG_CONT_WAIT');
    expect($statusCalls)->toBe(3); // 2× IN_PROGRESS + 1× FINISHED
});

it('Instagram: contenedor en ERROR falla de inmediato conservando el containerId', function () {
    publisherCtx();
    Http::fake(function ($request) {
        $url = $request->url();
        if (str_contains($url, '/media_publish')) {
            return Http::response(['id' => 'NO_DEBERIA_LLEGAR'], 200);
        }
        if (str_contains($url, '/media')) {
            return Http::response(['id' => 'IG_CONT_ERR'], 200);
        }

        return Http::response(['status_code' => 'ERROR'], 200);
    });

    $post = publisher()->publish(makePost(), ['instagram']);

    $t = $post->targets->firstWhere('network', 'instagram');
    expect($t->status)->toBe('failed');
    expect($t->container_id)->toBe('IG_CONT_ERR');
    expect($t->error_message)->toContain('ERROR');
    Http::assertNotSent(fn ($r) => str_contains($r->url(), '/media_publish'));
});

it('Instagram: contenedor EXPIRED falla de inmediato conservando el containerId', function () {
    publisherCtx();
    Http::fake(function ($request) {
        $url = $request->url();
        if (str_contains($url, '/media_publish')) {
            return Http::response(['id' => 'NO_DEBERIA_LLEGAR'], 200);
        }
        if (str_contains($url, '/media')) {
            return Http::response(['id' => 'IG_CONT_EXP'], 200);
        }

        return Http::response(['status_code' => 'EXPIRED'], 200);
    });

    $post = publisher()->publish(makePost(), ['instagram']);

    $t = $post->targets->firstWhere('network', 'instagram');
    expect($t->status)->toBe('failed');
    expect($t->container_id)->toBe('IG_CONT_EXP');
    expect($t->error_message)->toContain('EXPIRED');
    Http::assertNotSent(fn ($r) => str_contains($r->url(), '/media_publish'));
});

it('Instagram: IN_PROGRESS persistente agota el backoff y falla con mensaje claro', function () {
    publisherCtx();
    Http::fake(function ($request) {
        $url = $request->url();
        if (str_contains($url, '/media_publish')) {
            return Http::response(['id' => 'NO_DEBERIA_LLEGAR'], 200);
        }
        if (str_contains($url, '/media')) {
            return Http::response(['id' => 'IG_CONT_SLOW'], 200);
        }

        return Http::response(['status_code' => 'IN_PROGRESS'], 200);
    });

    $post = publisher()->publish(makePost(), ['instagram']);

    $t = $post->targets->firstWhere('network', 'instagram');
    expect($t->status)->toBe('failed');
    expect($t->container_id)->toBe('IG_CONT_SLOW');
    expect($t->error_message)->toContain('continúa procesando');
    Sleep::assertSleptTimes(5); // backoff completo: 1s, 2s, 3s, 5s, 8s
    Http::assertNotSent(fn ($r) => str_contains($r->url(), '/media_publish'));
});

it('Instagram: reintenta media_publish tras el error transitorio 2207027 y publica', function () {
    publisherCtx();
    $publishCalls = 0;
    Http::fake(function ($request) use (&$publishCalls) {
        $url = $request->url();
        if (str_contains($url, '/media_publish')) {
            $publishCalls++;

            return $publishCalls === 1
                ? Http::response(['error' => ['message' => 'Media ID is not available', 'code' => 9007, 'error_subcode' => 2207027]], 400)
                : Http::response(['id' => 'IG_POST_RETRY'], 200);
        }
        if (str_contains($url, '/media')) {
            return Http::response(['id' => 'IG_CONT_RETRY'], 200);
        }

        return Http::response(['status_code' => 'FINISHED'], 200);
    });

    $post = publisher()->publish(makePost(), ['instagram']);

    $t = $post->targets->firstWhere('network', 'instagram');
    expect($t->status)->toBe('published');
    expect($t->external_post_id)->toBe('IG_POST_RETRY');
    expect($publishCalls)->toBe(2);
});

// ----------------------------------------------------------------------------------
// Facebook: foto en Página
// ----------------------------------------------------------------------------------
it('Facebook: publica la foto con url pública + message y guarda el post id', function () {
    publisherCtx();
    fakeAllOk();

    $post = publisher()->publish(makePost(), ['facebook']);

    $t = $post->targets->firstWhere('network', 'facebook');
    expect($t->status)->toBe('published');
    expect($t->external_post_id)->toBe('FB_POST_1');
    expect($post->status)->toBe('published');

    Http::assertSent(fn ($r) => $r->url() === 'https://graph.facebook.com/v26.0/PAGE_1/photos'
        && $r['url'] === 'https://cdn.mca.test/foto.jpg'
        && $r['message'] === 'Nueva microcredencial 🎓'
        && $r['published'] === true
        && $r->hasHeader('Authorization', 'Bearer FB_TOKEN'));
});

// ----------------------------------------------------------------------------------
// Éxito PARCIAL: FB ok + IG falla → 'partial'
// ----------------------------------------------------------------------------------
it('éxito parcial: Facebook ok e Instagram falla → post partial', function () {
    publisherCtx();
    Http::fake(function ($request) {
        if (str_contains($request->url(), '/photos')) {
            return Http::response(['post_id' => 'FB_POST_2'], 200);
        }

        return Http::response(['error' => ['message' => 'Aspect ratio not supported', 'code' => 100]], 400);
    });

    $post = publisher()->publish(makePost(), ['facebook', 'instagram']);

    expect($post->status)->toBe('partial');
    expect($post->targets->firstWhere('network', 'facebook')->status)->toBe('published');
    $ig = $post->targets->firstWhere('network', 'instagram');
    expect($ig->status)->toBe('failed');
    expect($ig->error_message)->toContain('Aspect ratio');
});

it('éxito parcial inverso: Facebook falla e Instagram ok → post partial', function () {
    publisherCtx();
    Http::fake(function ($request) {
        $url = $request->url();
        if (str_contains($url, '/photos')) {
            return Http::response(['error' => ['message' => 'FB caído', 'code' => 1]], 500);
        }
        if (str_contains($url, '/media_publish')) {
            return Http::response(['id' => 'IG_POST_3'], 200);
        }
        if (str_contains($url, '/media')) {
            return Http::response(['id' => 'IG_CONT_3'], 200);
        }

        return Http::response(['status_code' => 'FINISHED'], 200);
    });

    $post = publisher()->publish(makePost(), ['facebook', 'instagram']);

    expect($post->status)->toBe('partial');
    expect($post->targets->firstWhere('network', 'facebook')->status)->toBe('failed');
    expect($post->targets->firstWhere('network', 'instagram')->status)->toBe('published');
});

// ----------------------------------------------------------------------------------
// Ambas fallan → 'failed'
// ----------------------------------------------------------------------------------
it('si ambas redes fallan, el post queda en failed', function () {
    publisherCtx();
    Http::fake(['*' => Http::response(['error' => ['message' => 'no autorizado']], 400)]);

    $post = publisher()->publish(makePost(), ['facebook', 'instagram']);

    expect($post->status)->toBe('failed');
    expect($post->targets->where('status', 'failed')->count())->toBe(2);
});

// ----------------------------------------------------------------------------------
// Solo redes con canal configurado
// ----------------------------------------------------------------------------------
it('solo publica en las redes que tienen canal en la institución', function () {
    $institution = Institution::factory()->create();
    app(CurrentInstitution::class)->set($institution->id);
    // Solo canal de Facebook (messenger); sin Instagram.
    SocialChannel::factory()->create(['provider' => 'messenger', 'external_id' => 'PAGE_9', 'is_active' => true, 'credentials' => ['token' => 'T']]);
    Http::fake(['graph.facebook.com/*' => Http::response(['post_id' => 'FB_ONLY'], 200)]);

    $post = publisher()->publish(makePost(), ['facebook', 'instagram']);

    expect($post->targets)->toHaveCount(1);
    expect($post->targets->first()->network)->toBe('facebook');
    expect($post->status)->toBe('published');
    Http::assertNotSent(fn ($r) => str_contains($r->url(), 'graph.instagram.com'));
});

// ----------------------------------------------------------------------------------
// Pantalla: subir imagen y publicar en ambas → resultado por red
// ----------------------------------------------------------------------------------
it('desde la pantalla, sube imagen y publica en ambas redes', function () {
    [, $user] = publisherCtx();
    config(['social.instagram_publish_enabled' => true]); // IG habilitado (App Review aprobado)
    Storage::fake('public');
    fakeAllOk();

    Livewire::actingAs($user)->test(Publisher::class)
        ->set('image', UploadedFile::fake()->image('promo.jpg', 1080, 1080))
        ->set('caption', 'Promo de microcredenciales')
        ->call('publish')
        ->assertHasNoErrors();

    $post = SocialPost::query()->first();
    expect($post)->not->toBeNull();
    expect($post->status)->toBe('published');
    expect($post->targets)->toHaveCount(2);
    Storage::disk('public')->assertExists($post->image_path);
});

// ----------------------------------------------------------------------------------
// Instagram deshabilitado temporalmente (permiso pendiente de App Review): solo FB
// ----------------------------------------------------------------------------------
it('con IG deshabilitado (default), la pantalla publica SOLO en Facebook aunque haya canal IG', function () {
    [, $user] = publisherCtx(); // canales FB + IG presentes; flag default = false
    Storage::fake('public');
    fakeAllOk();

    Livewire::actingAs($user)->test(Publisher::class)
        ->set('toInstagram', true) // aunque el toggle llegara en true, la barandilla lo ignora
        ->set('image', UploadedFile::fake()->image('promo.jpg', 1080, 1080))
        ->set('caption', 'Solo Facebook por ahora')
        ->call('publish')
        ->assertHasNoErrors();

    $post = SocialPost::query()->first();
    expect($post->targets)->toHaveCount(1);
    expect($post->targets->first()->network)->toBe('facebook');
    expect($post->status)->toBe('published');

    // Ninguna llamada al flujo de publicación de Instagram.
    Http::assertNotSent(fn ($r) => str_contains($r->url(), '/media'));
});

// ----------------------------------------------------------------------------------
// Scoping: un usuario solo publica con los canales de SU institución
// ----------------------------------------------------------------------------------
it('un usuario de otra institución no tiene canales y no publica', function () {
    publisherCtx(); // institución A con canales

    // Institución B SIN canales.
    $institutionB = Institution::factory()->create();
    app(CurrentInstitution::class)->set($institutionB->id);
    $userB = User::factory()->create(['institution_id' => $institutionB->id, 'role' => 'marketing']);

    Storage::fake('public');
    Http::fake();

    Livewire::actingAs($userB)->test(Publisher::class)
        ->set('image', UploadedFile::fake()->image('promo.jpg', 1080, 1080))
        ->set('caption', 'x')
        ->call('publish')
        ->assertHasErrors('image');

    Http::assertNothingSent();
    expect(SocialPost::query()->count())->toBe(0);
});
