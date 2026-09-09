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
 * Publicador multiformato: REEL e HISTORIA (imagen/video) en Facebook + Instagram, con
 * Http::fake (sin Meta real). Instagram reutiliza el flujo contenedor→status→media_publish;
 * Facebook usa start→upload(rupload, file_url)→finish. El POST clásico se cubre en
 * SocialPublisherTest (comportamiento intacto).
 */
beforeEach(function () {
    config(['social.graph_version' => 'v26.0', 'social.meta_app_id' => 'APP_1']);
    Sleep::fake();
});

/**
 * @return array{0: Institution, 1: User}
 */
function mfCtx(): array
{
    $institution = Institution::factory()->create();
    app(CurrentInstitution::class)->set($institution->id);
    $user = User::factory()->create(['institution_id' => $institution->id, 'role' => 'marketing']);

    // El canal de Facebook lleva DOS credenciales: Page token (todo) + User token de subida
    // (SOLO Resumable Upload del Post de video). Distintos a propósito para los asserts.
    SocialChannel::factory()->create(['provider' => 'messenger', 'external_id' => 'PAGE_1', 'is_active' => true, 'credentials' => ['token' => 'FB_TOKEN', 'video_upload_token' => 'FB_UPLOAD_TOKEN']]);
    SocialChannel::factory()->create(['provider' => 'instagram', 'external_id' => 'IGU_1', 'is_active' => true, 'credentials' => ['token' => 'IG_TOKEN']]);

    return [$institution, $user];
}

function mfService(): SocialPublishService
{
    return app(SocialPublishService::class);
}

/** Fake que responde OK a todos los flujos de video/historia de FB e IG, distinguido por path. */
function mfFakeVideoOk(): void
{
    Http::fake(function ($request) {
        $url = $request->url();
        if (str_contains($url, 'rupload.facebook.com')) {
            return Http::response(['success' => true], 200);
        }
        // Facebook Video API (Post de video): sesión → binario → publicación.
        if (str_contains($url, 'graph-video.facebook.com')) {
            return Http::response(['id' => 'FB_VIDEO_POST_1'], 200);
        }
        if (str_contains($url, '/uploads')) {
            return Http::response(['id' => 'upload:SESS_1'], 200);
        }
        if (str_contains($url, '/upload:')) {
            return Http::response(['h' => 'HANDLE_1'], 200);
        }
        if (str_contains($url, '/video_reels') || str_contains($url, '/video_stories')) {
            return ($request['upload_phase'] ?? null) === 'start'
                ? Http::response(['video_id' => 'VID_1', 'upload_url' => 'https://rupload.facebook.com/video-upload/v26.0/VID_1'], 200)
                : Http::response(['success' => true, 'post_id' => 'FB_POST_V1'], 200);
        }
        if (str_contains($url, '/photo_stories')) {
            return Http::response(['success' => true, 'post_id' => 'FB_STORY_1'], 200);
        }
        if (str_contains($url, '/photos')) {
            return Http::response(['id' => 'PHOTO_9'], 200);
        }
        if (str_contains($url, '/media_publish')) {
            return Http::response(['id' => 'IG_POST_V1'], 200);
        }
        if (str_contains($url, '/media')) {
            return Http::response(['id' => 'IG_CONT_V1'], 200);
        }

        return Http::response(['status_code' => 'FINISHED'], 200);
    });
}

// ----------------------------------------------------------------------------------
// INSTAGRAM REEL
// ----------------------------------------------------------------------------------
it('IG Reel: crea el contenedor REELS con video_url y publica', function () {
    mfCtx();
    mfFakeVideoOk();

    $post = mfService()->publish(SocialPost::factory()->reel()->create(['caption' => 'Mi reel']), ['instagram']);

    $t = $post->targets->firstWhere('network', 'instagram');
    expect($t->status)->toBe('published');
    expect($t->external_post_id)->toBe('IG_POST_V1');
    expect($t->container_id)->toBe('IG_CONT_V1');

    Http::assertSent(fn ($r) => $r->url() === 'https://graph.facebook.com/v26.0/IGU_1/media'
        && $r['media_type'] === 'REELS'
        && $r['video_url'] === 'https://cdn.example.test/reel.mp4'
        && $r['caption'] === 'Mi reel'
        && $r['share_to_feed'] === true
        && $r->hasHeader('Authorization', 'Bearer IG_TOKEN'));
    Http::assertSent(fn ($r) => str_contains($r->url(), '/IGU_1/media_publish') && $r['creation_id'] === 'IG_CONT_V1');
});

it('IG Reel: IN_PROGRESS que pasa a FINISHED publica correctamente', function () {
    mfCtx();
    $statusCalls = 0;
    Http::fake(function ($request) use (&$statusCalls) {
        $url = $request->url();
        if (str_contains($url, '/media_publish')) {
            return Http::response(['id' => 'IG_POST_V2'], 200);
        }
        if (str_contains($url, '/media')) {
            return Http::response(['id' => 'IG_CONT_V2'], 200);
        }
        $statusCalls++;

        return Http::response(['status_code' => $statusCalls < 3 ? 'IN_PROGRESS' : 'FINISHED'], 200);
    });

    $post = mfService()->publish(SocialPost::factory()->reel()->create(), ['instagram']);

    expect($post->targets->firstWhere('network', 'instagram')->status)->toBe('published');
    expect($statusCalls)->toBe(3);
});

it('IG Reel: contenedor en ERROR falla conservando el containerId', function () {
    mfCtx();
    Http::fake(function ($request) {
        $url = $request->url();
        if (str_contains($url, '/media_publish')) {
            return Http::response(['id' => 'X'], 200);
        }
        if (str_contains($url, '/media')) {
            return Http::response(['id' => 'IG_CONT_E'], 200);
        }

        return Http::response(['status_code' => 'ERROR'], 200);
    });

    $t = mfService()->publish(SocialPost::factory()->reel()->create(), ['instagram'])
        ->targets->firstWhere('network', 'instagram');

    expect($t->status)->toBe('failed');
    expect($t->container_id)->toBe('IG_CONT_E');
    Http::assertNotSent(fn ($r) => str_contains($r->url(), '/media_publish'));
});

it('IG Reel: contenedor EXPIRED falla conservando el containerId', function () {
    mfCtx();
    Http::fake(function ($request) {
        $url = $request->url();
        if (str_contains($url, '/media_publish')) {
            return Http::response(['id' => 'X'], 200);
        }
        if (str_contains($url, '/media')) {
            return Http::response(['id' => 'IG_CONT_X'], 200);
        }

        return Http::response(['status_code' => 'EXPIRED'], 200);
    });

    $t = mfService()->publish(SocialPost::factory()->reel()->create(), ['instagram'])
        ->targets->firstWhere('network', 'instagram');

    expect($t->status)->toBe('failed');
    expect($t->container_id)->toBe('IG_CONT_X');
    expect($t->error_message)->toContain('EXPIRED');
});

it('IG Reel: IN_PROGRESS agotado queda en processing recuperable (no failed) con su containerId', function () {
    mfCtx();
    Http::fake(function ($request) {
        $url = $request->url();
        if (str_contains($url, '/media_publish')) {
            return Http::response(['id' => 'X'], 200);
        }
        if (str_contains($url, '/media')) {
            return Http::response(['id' => 'IG_CONT_S'], 200);
        }

        return Http::response(['status_code' => 'IN_PROGRESS'], 200);
    });

    $post = mfService()->publish(SocialPost::factory()->reel()->create(), ['instagram']);
    $t = $post->targets->firstWhere('network', 'instagram');

    expect($t->status)->toBe('processing');
    expect($t->container_id)->toBe('IG_CONT_S');
    expect($t->error_message)->toBe('Instagram continúa procesando el video.');
    expect($post->status)->toBe('processing');
    Sleep::assertSleptTimes(3); // backoff síncrono de VIDEO acotado: 3,5,8 (~16s)
    Http::assertNotSent(fn ($r) => str_contains($r->url(), '/media_publish'));
});

it('IG Historia de video: IN_PROGRESS agotado también queda en processing recuperable', function () {
    mfCtx();
    Http::fake(function ($request) {
        $url = $request->url();
        if (str_contains($url, '/media_publish')) {
            return Http::response(['id' => 'X'], 200);
        }
        if (str_contains($url, '/media')) {
            return Http::response(['id' => 'IG_CONT_SV2'], 200);
        }

        return Http::response(['status_code' => 'IN_PROGRESS'], 200);
    });

    $t = mfService()->publish(SocialPost::factory()->storyVideo()->create(), ['instagram'])
        ->targets->firstWhere('network', 'instagram');

    expect($t->status)->toBe('processing');
    expect($t->container_id)->toBe('IG_CONT_SV2');
    expect($t->error_message)->toBe('Instagram continúa procesando el video.');
});

it('IG Reel: fallo en media_publish deja el target failed con el containerId', function () {
    mfCtx();
    Http::fake(function ($request) {
        $url = $request->url();
        if (str_contains($url, '/media_publish')) {
            return Http::response(['error' => ['message' => 'Application does not have permission', 'code' => 10]], 400);
        }
        if (str_contains($url, '/media')) {
            return Http::response(['id' => 'IG_CONT_P'], 200);
        }

        return Http::response(['status_code' => 'FINISHED'], 200);
    });

    $t = mfService()->publish(SocialPost::factory()->reel()->create(), ['instagram'])
        ->targets->firstWhere('network', 'instagram');

    expect($t->status)->toBe('failed');
    expect($t->container_id)->toBe('IG_CONT_P');
    expect($t->error_message)->toContain('permission');
});

// ----------------------------------------------------------------------------------
// INSTAGRAM STORY (imagen y video)
// ----------------------------------------------------------------------------------
it('IG Historia de imagen: contenedor STORIES con image_url (sin caption) y publica', function () {
    mfCtx();
    mfFakeVideoOk();

    $t = mfService()->publish(SocialPost::factory()->storyImage()->create(), ['instagram'])
        ->targets->firstWhere('network', 'instagram');

    expect($t->status)->toBe('published');
    expect($t->external_post_id)->toBe('IG_POST_V1');

    Http::assertSent(fn ($r) => $r->url() === 'https://graph.facebook.com/v26.0/IGU_1/media'
        && $r['media_type'] === 'STORIES'
        && $r['image_url'] === 'https://cdn.example.test/story.jpg'
        && ! array_key_exists('caption', $r->data()));
});

it('IG Historia de video: contenedor STORIES con video_url, procesamiento asíncrono y publica', function () {
    mfCtx();
    $statusCalls = 0;
    Http::fake(function ($request) use (&$statusCalls) {
        $url = $request->url();
        if (str_contains($url, '/media_publish')) {
            return Http::response(['id' => 'IG_STORY_V1'], 200);
        }
        if (str_contains($url, '/media')) {
            return Http::response(['id' => 'IG_CONT_SV'], 200);
        }
        $statusCalls++;

        return Http::response(['status_code' => $statusCalls < 2 ? 'IN_PROGRESS' : 'FINISHED'], 200);
    });

    $t = mfService()->publish(SocialPost::factory()->storyVideo()->create(), ['instagram'])
        ->targets->firstWhere('network', 'instagram');

    expect($t->status)->toBe('published');
    expect($t->external_post_id)->toBe('IG_STORY_V1');
    Http::assertSent(fn ($r) => str_contains($r->url(), '/IGU_1/media')
        && ! str_contains($r->url(), 'media_publish')
        && $r['media_type'] === 'STORIES'
        && $r['video_url'] === 'https://cdn.example.test/story.mp4');
});

// ----------------------------------------------------------------------------------
// FACEBOOK REEL (start → upload por file_url → finish)
// ----------------------------------------------------------------------------------
it('FB Reel: hace start, sube por file_url y publica con finish', function () {
    mfCtx();
    mfFakeVideoOk();

    $t = mfService()->publish(SocialPost::factory()->reel()->create(['caption' => 'Mi reel']), ['facebook'])
        ->targets->firstWhere('network', 'facebook');

    expect($t->status)->toBe('published');
    expect($t->external_post_id)->toBe('FB_POST_V1');

    Http::assertSent(fn ($r) => str_contains($r->url(), '/PAGE_1/video_reels') && $r['upload_phase'] === 'start');
    Http::assertSent(fn ($r) => str_contains($r->url(), 'rupload.facebook.com')
        && $r->hasHeader('file_url', 'https://cdn.example.test/reel.mp4')
        && $r->hasHeader('Authorization', 'OAuth FB_TOKEN'));
    Http::assertSent(fn ($r) => str_contains($r->url(), '/PAGE_1/video_reels')
        && $r['upload_phase'] === 'finish'
        && $r['video_id'] === 'VID_1'
        && $r['video_state'] === 'PUBLISHED'
        && $r['description'] === 'Mi reel');
    // El Reel usa /video_reels, nunca la Video API de posts.
    Http::assertNotSent(fn ($r) => str_contains($r->url(), 'graph-video.facebook.com'));
});

it('FB Reel: error en la fase start deja el target failed', function () {
    mfCtx();
    Http::fake(['*' => Http::response(['error' => ['message' => 'Permissions error', 'code' => 200]], 400)]);

    $t = mfService()->publish(SocialPost::factory()->reel()->create(), ['facebook'])
        ->targets->firstWhere('network', 'facebook');

    expect($t->status)->toBe('failed');
    expect($t->error_message)->toContain('Permissions');
});

it('FB Reel: fallo en la subida (rupload) devuelve mensaje claro de descarga', function () {
    mfCtx();
    Http::fake(function ($request) {
        if (str_contains($request->url(), 'rupload.facebook.com')) {
            return Http::response(['success' => false], 500);
        }

        return Http::response(['video_id' => 'VID_1', 'upload_url' => 'https://rupload.facebook.com/video-upload/v26.0/VID_1'], 200);
    });

    $t = mfService()->publish(SocialPost::factory()->reel()->create(), ['facebook'])
        ->targets->firstWhere('network', 'facebook');

    expect($t->status)->toBe('failed');
    expect($t->error_message)->toBe('Meta no pudo descargar el video desde el servidor.');
});

it('FB Reel: error en la fase finish deja el target failed', function () {
    mfCtx();
    Http::fake(function ($request) {
        $url = $request->url();
        if (str_contains($url, 'rupload.facebook.com')) {
            return Http::response(['success' => true], 200);
        }
        if (($request['upload_phase'] ?? null) === 'start') {
            return Http::response(['video_id' => 'VID_1', 'upload_url' => 'https://rupload.facebook.com/video-upload/v26.0/VID_1'], 200);
        }

        return Http::response(['error' => ['message' => 'The video duration is too long', 'code' => 100]], 400);
    });

    $t = mfService()->publish(SocialPost::factory()->reel()->create(), ['facebook'])
        ->targets->firstWhere('network', 'facebook');

    expect($t->status)->toBe('failed');
    expect($t->error_message)->toBe('El video excede o no alcanza la duración permitida.');
});

// ----------------------------------------------------------------------------------
// FACEBOOK STORIES (foto y video)
// ----------------------------------------------------------------------------------
it('FB Historia de foto: sube la foto sin publicar y la publica como historia', function () {
    mfCtx();
    mfFakeVideoOk();

    $t = mfService()->publish(SocialPost::factory()->storyImage()->create(), ['facebook'])
        ->targets->firstWhere('network', 'facebook');

    expect($t->status)->toBe('published');
    expect($t->external_post_id)->toBe('FB_STORY_1');

    Http::assertSent(fn ($r) => str_contains($r->url(), '/PAGE_1/photos')
        && $r['url'] === 'https://cdn.example.test/story.jpg'
        && $r['published'] === false);
    Http::assertSent(fn ($r) => str_contains($r->url(), '/PAGE_1/photo_stories') && $r['photo_id'] === 'PHOTO_9');
});

it('FB Historia de video: start, upload por file_url y finish guardan el post_id', function () {
    mfCtx();
    mfFakeVideoOk();

    $t = mfService()->publish(SocialPost::factory()->storyVideo()->create(), ['facebook'])
        ->targets->firstWhere('network', 'facebook');

    expect($t->status)->toBe('published');
    expect($t->external_post_id)->toBe('FB_POST_V1');

    Http::assertSent(fn ($r) => str_contains($r->url(), '/PAGE_1/video_stories') && $r['upload_phase'] === 'start');
    Http::assertSent(fn ($r) => str_contains($r->url(), 'rupload.facebook.com')
        && $r->hasHeader('file_url', 'https://cdn.example.test/story.mp4'));
    Http::assertSent(fn ($r) => str_contains($r->url(), '/PAGE_1/video_stories')
        && $r['upload_phase'] === 'finish'
        && $r['video_id'] === 'VID_1'
        && ! array_key_exists('video_state', $r->data()));
});

// ----------------------------------------------------------------------------------
// Éxito parcial multiformato + errores amigables
// ----------------------------------------------------------------------------------
it('Reel dual con IG fallando queda partial y traduce el error de formato', function () {
    mfCtx();
    Http::fake(function ($request) {
        $url = $request->url();
        if (str_contains($url, 'rupload.facebook.com')) {
            return Http::response(['success' => true], 200);
        }
        if (str_contains($url, '/video_reels')) {
            return ($request['upload_phase'] ?? null) === 'start'
                ? Http::response(['video_id' => 'VID_1', 'upload_url' => 'https://rupload.facebook.com/video-upload/v26.0/VID_1'], 200)
                : Http::response(['success' => true, 'post_id' => 'FB_POST_V1'], 200);
        }

        // Contenedor IG rechaza el video por formato.
        return Http::response(['error' => ['message' => 'The video format is unsupported.', 'code' => 352]], 400);
    });

    $post = mfService()->publish(SocialPost::factory()->reel()->create(), ['facebook', 'instagram']);

    expect($post->status)->toBe('partial');
    expect($post->targets->firstWhere('network', 'facebook')->status)->toBe('published');
    $ig = $post->targets->firstWhere('network', 'instagram');
    expect($ig->status)->toBe('failed');
    expect($ig->error_message)->toBe('El formato del video no es compatible.');
});

// ----------------------------------------------------------------------------------
// PANTALLA (Livewire): Reel y Historia
// ----------------------------------------------------------------------------------
it('desde la pantalla, un Reel con video se publica en ambas redes', function () {
    [, $user] = mfCtx();
    Storage::fake('public');
    mfFakeVideoOk();

    Livewire::actingAs($user)->test(Publisher::class)
        ->call('setContentType', 'reel')
        ->set('video', UploadedFile::fake()->create('mi-reel.mp4', 2048, 'video/mp4'))
        ->set('caption', 'Reel de prueba')
        ->call('publish')
        ->assertHasNoErrors();

    $post = SocialPost::query()->first();
    expect($post->content_type)->toBe('reel');
    expect($post->media_type)->toBe('video');
    expect($post->media_mime)->toBe('video/mp4');
    expect($post->status)->toBe('published');
    expect($post->targets)->toHaveCount(2);
    Storage::disk('public')->assertExists($post->media_path);
});

it('desde la pantalla, una Historia de imagen solo a Instagram (sin caption)', function () {
    [, $user] = mfCtx();
    Storage::fake('public');
    mfFakeVideoOk();

    Livewire::actingAs($user)->test(Publisher::class)
        ->call('setContentType', 'story')
        ->set('image', UploadedFile::fake()->image('story.jpg', 1080, 1920))
        ->set('toFacebook', false)
        ->call('publish')
        ->assertHasNoErrors();

    $post = SocialPost::query()->first();
    expect($post->content_type)->toBe('story');
    expect($post->media_type)->toBe('image');
    expect($post->caption)->toBeNull();
    expect($post->targets)->toHaveCount(1);
    expect($post->targets->first()->network)->toBe('instagram');
    expect($post->targets->first()->status)->toBe('published');
});

it('desde la pantalla, una Historia de video solo a Facebook', function () {
    [, $user] = mfCtx();
    Storage::fake('public');
    mfFakeVideoOk();

    Livewire::actingAs($user)->test(Publisher::class)
        ->call('setContentType', 'story')
        ->call('setStoryMedia', 'video')
        ->set('video', UploadedFile::fake()->create('story.mp4', 1024, 'video/mp4'))
        ->set('toInstagram', false)
        ->call('publish')
        ->assertHasNoErrors();

    $post = SocialPost::query()->first();
    expect($post->content_type)->toBe('story');
    expect($post->media_type)->toBe('video');
    expect($post->targets)->toHaveCount(1);
    expect($post->targets->first()->network)->toBe('facebook');
    expect($post->targets->first()->status)->toBe('published');
});

it('validación: el Reel exige video y rechaza archivos que no son MP4', function () {
    [, $user] = mfCtx();
    Storage::fake('public');
    Http::fake();

    $component = Livewire::actingAs($user)->test(Publisher::class)
        ->call('setContentType', 'reel')
        ->call('publish')
        ->assertHasErrors(['video' => 'required']);

    $component->set('video', UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf'))
        ->call('publish')
        ->assertHasErrors('video');

    Http::assertNothingSent();
    expect(SocialPost::query()->count())->toBe(0);
});

// ----------------------------------------------------------------------------------
// REANUDACIÓN ("Continuar"): usa solo el container existente; nunca crea otro
// ----------------------------------------------------------------------------------
/**
 * Los Http::fake se APILAN (el primer closure que responde gana), así que para simular una
 * segunda fase (reanudar) hay que descartar la factory HTTP fakeada de la primera fase.
 */
function mfFreshHttp(): void
{
    Http::clearResolvedInstances();
    app()->forgetInstance(\Illuminate\Http\Client\Factory::class);
}

/** Deja un target de Reel IG en estado processing con container IG_CONT_R1 (requiere mfCtx previo). */
function mfProcessingTarget(): \Modules\Social\Models\SocialPostTarget
{
    Http::fake(function ($request) {
        $url = $request->url();
        if (str_contains($url, '/media_publish')) {
            return Http::response(['id' => 'X'], 200);
        }
        if (str_contains($url, '/media')) {
            return Http::response(['id' => 'IG_CONT_R1'], 200);
        }

        return Http::response(['status_code' => 'IN_PROGRESS'], 200);
    });

    return mfService()->publish(SocialPost::factory()->reel()->create(), ['instagram'])
        ->targets->firstWhere('network', 'instagram');
}

it('Continuar: el contenedor pasa a FINISHED y publica SIN crear otro contenedor', function () {
    mfCtx();
    $t = mfProcessingTarget();

    mfFreshHttp();
    $created = 0;
    Http::fake(function ($request) use (&$created) {
        $url = $request->url();
        if ($request->method() === 'POST' && str_ends_with($url, '/IGU_1/media')) {
            $created++; // reanudar JAMÁS debe crear un contenedor nuevo

            return Http::response(['id' => 'CONTAINER_NUEVO_PROHIBIDO'], 200);
        }
        if (str_contains($url, '/media_publish')) {
            return Http::response(['id' => 'IG_POST_RESUMED'], 200);
        }

        return Http::response(['status_code' => 'FINISHED'], 200);
    });

    $t = mfService()->resumeTarget($t);

    expect($t->status)->toBe('published');
    expect($t->external_post_id)->toBe('IG_POST_RESUMED');
    expect($t->container_id)->toBe('IG_CONT_R1');
    expect($t->error_message)->toBeNull();
    expect($created)->toBe(0);
    expect($t->post->fresh()->status)->toBe('published');
    Http::assertSent(fn ($r) => str_contains($r->url(), '/media_publish') && $r['creation_id'] === 'IG_CONT_R1');
});

it('Continuar mientras sigue IN_PROGRESS: permanece en processing con su containerId', function () {
    mfCtx();
    $t = mfProcessingTarget();

    mfFreshHttp();
    Http::fake(['*' => Http::response(['status_code' => 'IN_PROGRESS'], 200)]);

    $t = mfService()->resumeTarget($t);

    expect($t->status)->toBe('processing');
    expect($t->container_id)->toBe('IG_CONT_R1');
    expect($t->error_message)->toBe('Instagram continúa procesando el video.');
    expect($t->post->fresh()->status)->toBe('processing');
});

it('Continuar con contenedor en ERROR: pasa a failed', function () {
    mfCtx();
    $t = mfProcessingTarget();

    mfFreshHttp();
    Http::fake(['*' => Http::response(['status_code' => 'ERROR'], 200)]);

    $t = mfService()->resumeTarget($t);

    expect($t->status)->toBe('failed');
    expect($t->error_message)->toContain('ERROR');
    expect($t->post->fresh()->status)->toBe('failed');
});

it('Continuar con contenedor EXPIRED: pasa a failed', function () {
    mfCtx();
    $t = mfProcessingTarget();

    mfFreshHttp();
    Http::fake(['*' => Http::response(['status_code' => 'EXPIRED'], 200)]);

    $t = mfService()->resumeTarget($t);

    expect($t->status)->toBe('failed');
    expect($t->error_message)->toContain('EXPIRED');
});

it('doble Continuar no duplica media_publish (idempotente)', function () {
    mfCtx();
    $t = mfProcessingTarget();

    mfFreshHttp();
    $publishCalls = 0;
    Http::fake(function ($request) use (&$publishCalls) {
        if (str_contains($request->url(), '/media_publish')) {
            $publishCalls++;

            return Http::response(['id' => 'IG_POST_ONCE'], 200);
        }

        return Http::response(['status_code' => 'FINISHED'], 200);
    });

    $t = mfService()->resumeTarget($t);
    expect($t->status)->toBe('published');

    // Segundo Continuar: el target ya no está en processing, así que es un no-op total.
    $t = mfService()->resumeTarget($t);

    expect($publishCalls)->toBe(1);
    expect($t->status)->toBe('published');
    expect($t->external_post_id)->toBe('IG_POST_ONCE');
});

it('un usuario de OTRA institución no puede reanudar el target (scope por tenant)', function () {
    mfCtx();
    $t = mfProcessingTarget();

    $institutionB = Institution::factory()->create();
    app(CurrentInstitution::class)->set($institutionB->id);
    $userB = User::factory()->create(['institution_id' => $institutionB->id, 'role' => 'marketing']);

    mfFreshHttp();
    $publishCalls = 0;
    Http::fake(function ($request) use (&$publishCalls) {
        if (str_contains($request->url(), '/media_publish')) {
            $publishCalls++;
        }

        return Http::response(['status_code' => 'FINISHED'], 200);
    });

    Livewire::actingAs($userB)->test(Publisher::class)->call('resumeTarget', $t->id);

    expect($publishCalls)->toBe(0);
    app(CurrentInstitution::class)->set($t->institution_id);
    expect($t->fresh()->status)->toBe('processing'); // intacto
});

it('un target de Facebook no puede pasar por la reanudación de Instagram', function () {
    mfCtx();
    Http::fake();

    $post = SocialPost::factory()->reel()->create();
    $fb = new \Modules\Social\Models\SocialPostTarget;
    $fb->social_post_id = $post->id;
    $fb->network = 'facebook';
    $fb->status = 'processing';
    $fb->container_id = 'NO_APLICA';
    $fb->save();

    $fb = mfService()->resumeTarget($fb);

    expect($fb->status)->toBe('processing'); // sin cambios: la guarda lo rechaza
    Http::assertNothingSent();
});

// ----------------------------------------------------------------------------------
// FB rupload: sin upload_url NO se reconstruye la URL — fallo claro
// ----------------------------------------------------------------------------------
it('FB Reel: si start no devuelve upload_url, falla sin construir la URL de rupload a mano', function () {
    mfCtx();
    Http::fake(function ($request) {
        if (($request['upload_phase'] ?? null) === 'start') {
            return Http::response(['video_id' => 'VID_1'], 200); // SIN upload_url
        }

        return Http::response(['success' => true], 200);
    });

    $t = mfService()->publish(SocialPost::factory()->reel()->create(), ['facebook'])
        ->targets->firstWhere('network', 'facebook');

    expect($t->status)->toBe('failed');
    expect($t->error_message)->toBe('Facebook no devolvió la URL de subida del video.');
    Http::assertNotSent(fn ($r) => str_contains($r->url(), 'rupload.facebook.com'));
});

// ----------------------------------------------------------------------------------
// PostVideoService: defensa en profundidad (fuera de Livewire)
// ----------------------------------------------------------------------------------
it('PostVideoService acepta hasta 250MB (limite general del CRM)', function () {
    Storage::fake('public');

    $ok = UploadedFile::fake()->create('grande.mp4', 200000, 'video/mp4'); // ~195 MB

    $stored = app(\Modules\Social\Services\PostVideoService::class)->store($ok);

    expect($stored['mime'])->toBe('video/mp4');
    Storage::disk('public')->assertExists($stored['path']);
});

it('PostVideoService rechaza más de 250MB aunque se invoque directamente (sin Livewire)', function () {
    Storage::fake('public');

    $big = UploadedFile::fake()->create('gigante.mp4', 260000, 'video/mp4'); // ~254 MB

    expect(fn () => app(\Modules\Social\Services\PostVideoService::class)->store($big))
        ->toThrow(RuntimeException::class, 'supera el tamaño máximo permitido (250 MB)');
    expect(Storage::disk('public')->allFiles('social-posts'))->toBe([]);
});

it('PostVideoService limpia el archivo huérfano si el guardado quedó parcial', function () {
    $disk = Mockery::mock(\Illuminate\Contracts\Filesystem\Filesystem::class);
    Storage::shouldReceive('disk')->with('public')->andReturn($disk);
    $disk->shouldReceive('putFileAs')->once()->andReturn('social-posts/parcial.mp4');
    $disk->shouldReceive('exists')->with('social-posts/parcial.mp4')->andReturn(true);
    $disk->shouldReceive('size')->with('social-posts/parcial.mp4')->andReturn(5); // distinto del tamaño real (0)
    $disk->shouldReceive('delete')->once()->with('social-posts/parcial.mp4');

    $file = UploadedFile::fake()->create('v.mp4', 0, 'video/mp4');

    expect(fn () => app(\Modules\Social\Services\PostVideoService::class)->store($file))
        ->toThrow(RuntimeException::class, 'no se guardó completo');
});

// ----------------------------------------------------------------------------------
// Límites de tamaño por formato y redes (Reel 250MB · Historia con IG 100MB · FB-only 250MB)
// ----------------------------------------------------------------------------------
it('Reel entre 100 y 250MB pasa la validación y se publica en ambas redes', function () {
    [, $user] = mfCtx();
    Storage::fake('public');
    mfFakeVideoOk();

    Livewire::actingAs($user)->test(Publisher::class)
        ->call('setContentType', 'reel')
        ->set('video', UploadedFile::fake()->create('grande.mp4', 200000, 'video/mp4')) // ~195 MB
        ->call('publish')
        ->assertHasNoErrors();

    $post = SocialPost::query()->first();
    expect($post->status)->toBe('published');
    expect($post->targets)->toHaveCount(2);
});

it('Reel de más de 250MB se rechaza sin publicar y con mensaje comprensible', function () {
    [, $user] = mfCtx();
    Storage::fake('public');
    Http::fake();

    // >250 MB muere en la PUERTA de subida temporal de Livewire (config max:256000):
    // la propiedad video queda vacía y publish la exige con un mensaje claro (no la
    // clave técnica de validación).
    Livewire::actingAs($user)->test(Publisher::class)
        ->call('setContentType', 'reel')
        ->set('video', UploadedFile::fake()->create('gigante.mp4', 260000, 'video/mp4')) // ~254 MB
        ->call('publish')
        ->assertHasErrors('video')
        ->assertSee('Selecciona un video MP4 (máximo 250 MB).');

    Http::assertNothingSent();
    expect(SocialPost::query()->count())->toBe(0);
});

it('Historia de video con Instagram (solo IG) de más de 100MB se rechaza ANTES de llamar a Meta', function () {
    [, $user] = mfCtx();
    Storage::fake('public');
    Http::fake();

    Livewire::actingAs($user)->test(Publisher::class)
        ->call('setContentType', 'story')
        ->call('setStoryMedia', 'video')
        ->set('toFacebook', false)
        ->set('video', UploadedFile::fake()->create('story.mp4', 150000, 'video/mp4')) // ~146 MB
        ->call('publish')
        ->assertHasErrors('video')
        ->assertSee('Las Historias de Instagram admiten videos de hasta 100 MB.');

    Http::assertNothingSent();
    expect(SocialPost::query()->count())->toBe(0);
});

it('Historia de video dual FB+IG de más de 100MB también se rechaza (manda el límite de IG)', function () {
    [, $user] = mfCtx();
    Storage::fake('public');
    Http::fake();

    Livewire::actingAs($user)->test(Publisher::class)
        ->call('setContentType', 'story')
        ->call('setStoryMedia', 'video')
        ->set('video', UploadedFile::fake()->create('story.mp4', 150000, 'video/mp4'))
        ->call('publish')
        ->assertHasErrors('video');

    Http::assertNothingSent();
    expect(SocialPost::query()->count())->toBe(0);
});

it('Historia de video SOLO Facebook usa el límite general (150MB pasa, no aplica el de IG)', function () {
    [, $user] = mfCtx();
    Storage::fake('public');
    mfFakeVideoOk();

    Livewire::actingAs($user)->test(Publisher::class)
        ->call('setContentType', 'story')
        ->call('setStoryMedia', 'video')
        ->set('toInstagram', false)
        ->set('video', UploadedFile::fake()->create('story.mp4', 150000, 'video/mp4')) // ~146 MB
        ->call('publish')
        ->assertHasNoErrors();

    $post = SocialPost::query()->first();
    expect($post->targets)->toHaveCount(1);
    expect($post->targets->first()->network)->toBe('facebook');
    expect($post->targets->first()->status)->toBe('published');
});

it('la UI muestra el límite correcto ANTES de elegir archivo, según formato y redes', function () {
    [, $user] = mfCtx();

    $c = Livewire::actingAs($user)->test(Publisher::class);

    $c->call('setContentType', 'reel')
        ->assertSee('MP4 · Máximo 250 MB · 3–90 s · Vertical 9:16 recomendado');

    $c->call('setContentType', 'story')
        ->call('setStoryMedia', 'video')
        ->assertSee('MP4 · Máximo 100 MB para Instagram · 3–60 s · Vertical 9:16 recomendado');

    $c->set('toInstagram', false)
        ->assertSee('MP4 · Máximo 250 MB · 3–60 s · Vertical 9:16 recomendado');
});

// ----------------------------------------------------------------------------------
// POST de VIDEO: FB usa la Video API oficial (resumable + handle); IG reutiliza REELS
// ----------------------------------------------------------------------------------
it('Post: muestra el selector Imagen/Video', function () {
    [, $user] = mfCtx();

    Livewire::actingAs($user)->test(Publisher::class)
        ->assertSee('Contenido')
        ->assertSee('Imagen')
        ->assertSee('Video');
});

it('Post de video en Facebook: Resumable Upload con el USER token y publicación con el PAGE token', function () {
    mfCtx();
    Storage::fake('public');
    Storage::disk('public')->put('social-posts/post-video.mp4', 'contenido-mp4');
    mfFakeVideoOk();

    $t = mfService()->publish(SocialPost::factory()->postVideo()->create(['caption' => 'Video del taller']), ['facebook'])
        ->targets->firstWhere('network', 'facebook');

    expect($t->status)->toBe('published');
    expect($t->external_post_id)->toBe('FB_VIDEO_POST_1');

    // App ID sale de config (SOCIAL_META_APP_ID): jamás se descubre con GET /app.
    Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/app'));
    // Fases 1-2 (Resumable Upload) con el USER token — distinto del Page token.
    Http::assertSent(fn ($r) => str_contains($r->url(), '/APP_1/uploads')
        && $r->hasHeader('Authorization', 'Bearer FB_UPLOAD_TOKEN')
        && $r['file_type'] === 'video/mp4'
        && $r['file_length'] === strlen('contenido-mp4'));
    Http::assertSent(fn ($r) => str_contains($r->url(), '/upload:SESS_1')
        && $r->hasHeader('Authorization', 'OAuth FB_UPLOAD_TOKEN')
        && $r->hasHeader('file_offset', '0'));
    // Fase 3 (publicación en la Página) con el PAGE token de siempre.
    Http::assertSent(fn ($r) => str_contains($r->url(), 'graph-video.facebook.com')
        && str_contains($r->url(), '/PAGE_1/videos')
        && $r->hasHeader('Authorization', 'Bearer FB_TOKEN')
        && $r['fbuploader_video_file_chunk'] === 'HANDLE_1'
        && $r['description'] === 'Video del taller');
    // El Page token NUNCA se usa en el resumable upload (sin fallback).
    Http::assertNotSent(fn ($r) => (str_contains($r->url(), '/uploads') || str_contains($r->url(), '/upload:'))
        && ($r->hasHeader('Authorization', 'Bearer FB_TOKEN') || $r->hasHeader('Authorization', 'OAuth FB_TOKEN')));
    Http::assertNotSent(fn ($r) => str_contains($r->url(), '/video_reels'));
});

it('sin video_upload_token: el Post de video FB falla controlado y Reel/Historia siguen funcionando con el Page token', function () {
    mfCtx();
    Storage::fake('public');
    Storage::disk('public')->put('social-posts/post-video.mp4', 'contenido-mp4');
    // Canal de Facebook SOLO con Page token (sin autorización de subida).
    SocialChannel::query()->where('provider', 'messenger')->first()
        ->update(['credentials' => ['token' => 'FB_TOKEN']]);
    mfFakeVideoOk();

    // Post de video → fallo controlado, sin fallback y sin llamadas de subida.
    $t = mfService()->publish(SocialPost::factory()->postVideo()->create(), ['facebook'])
        ->targets->firstWhere('network', 'facebook');

    expect($t->status)->toBe('failed');
    expect($t->error_message)->toBe('El canal de Facebook no tiene configurada la autorización necesaria para subir videos.');
    Http::assertNotSent(fn ($r) => str_contains($r->url(), '/uploads') || str_contains($r->url(), '/upload:'));
    Http::assertNotSent(fn ($r) => str_contains($r->url(), 'graph-video.facebook.com'));

    // Reel y Historia de video: intactos con SOLO el Page token.
    $reel = mfService()->publish(SocialPost::factory()->reel()->create(), ['facebook'])
        ->targets->firstWhere('network', 'facebook');
    expect($reel->status)->toBe('published');

    $story = mfService()->publish(SocialPost::factory()->storyVideo()->create(), ['facebook'])
        ->targets->firstWhere('network', 'facebook');
    expect($story->status)->toBe('published');
});

it('Post de video en Instagram reutiliza el contenedor REELS con share_to_feed', function () {
    mfCtx();
    mfFakeVideoOk();

    $post = SocialPost::factory()->postVideo()->create(['caption' => 'Video del taller']);
    $post = mfService()->publish($post, ['instagram']);

    expect($post->fresh()->content_type)->toBe('post'); // NO se guarda como reel
    expect($post->fresh()->media_type)->toBe('video');
    expect($post->targets->firstWhere('network', 'instagram')->status)->toBe('published');

    Http::assertSent(fn ($r) => str_ends_with($r->url(), '/IGU_1/media')
        && $r['media_type'] === 'REELS'
        && $r['share_to_feed'] === true
        && $r['caption'] === 'Video del taller');
});

it('Post de video dual puede quedar partial (FB ok, IG falla)', function () {
    mfCtx();
    Storage::fake('public');
    Storage::disk('public')->put('social-posts/post-video.mp4', 'contenido-mp4');
    Http::fake(function ($request) {
        $url = $request->url();
        if (str_contains($url, 'graph-video.facebook.com')) {
            return Http::response(['id' => 'FB_VIDEO_POST_1'], 200);
        }
        if (str_ends_with($url, '/app')) {
            return Http::response(['id' => 'APP_1'], 200);
        }
        if (str_contains($url, '/uploads')) {
            return Http::response(['id' => 'upload:SESS_1'], 200);
        }
        if (str_contains($url, '/upload:')) {
            return Http::response(['h' => 'HANDLE_1'], 200);
        }

        // Contenedor IG rechaza.
        return Http::response(['error' => ['message' => 'The video format is unsupported.', 'code' => 352]], 400);
    });

    $post = mfService()->publish(SocialPost::factory()->postVideo()->create(), ['facebook', 'instagram']);

    expect($post->status)->toBe('partial');
    expect($post->targets->firstWhere('network', 'facebook')->status)->toBe('published');
    expect($post->targets->firstWhere('network', 'instagram')->status)->toBe('failed');
});

it('desde la pantalla, un Post de video se persiste como post+video y publica en ambas redes', function () {
    [, $user] = mfCtx();
    Storage::fake('public');
    mfFakeVideoOk();

    Livewire::actingAs($user)->test(Publisher::class)
        ->call('setPostMedia', 'video')
        ->set('video', UploadedFile::fake()->create('taller.mp4', 2048, 'video/mp4'))
        ->set('caption', 'Video del taller')
        ->call('publish')
        ->assertHasNoErrors();

    $post = SocialPost::query()->first();
    expect($post->content_type)->toBe('post');
    expect($post->media_type)->toBe('video');
    expect($post->status)->toBe('published');
    expect($post->targets)->toHaveCount(2);
});

// ----------------------------------------------------------------------------------
// Identificación del video elegido en la UI (nombre original + tamaño + máximo vigente)
// ----------------------------------------------------------------------------------
it('la UI identifica el video elegido: nombre original, tamaño real y máximo aplicable', function () {
    [, $user] = mfCtx();
    Storage::fake('public');

    Livewire::actingAs($user)->test(Publisher::class)
        ->call('setContentType', 'reel')
        ->set('video', UploadedFile::fake()->create('workshop-liderazgo.mp4', 189030, 'video/mp4')) // 184.6 MB
        ->assertSee('workshop-liderazgo.mp4')
        ->assertSee('184.6 MB')
        ->assertSee('Máximo 250 MB');
});

it('el máximo mostrado cambia de 250 a 100 MB al activar Instagram en Historia de video, validando sin llamar a Meta', function () {
    [, $user] = mfCtx();
    Storage::fake('public');
    Http::fake();

    $c = Livewire::actingAs($user)->test(Publisher::class)
        ->call('setContentType', 'story')
        ->call('setStoryMedia', 'video')
        ->set('toInstagram', false)
        ->set('video', UploadedFile::fake()->create('story.mp4', 150000, 'video/mp4')); // 146.5 MB

    $c->assertSee('story.mp4')
        ->assertSee('146.5 MB')
        ->assertSee('Máximo 250 MB')
        ->assertHasNoErrors();

    // Activar Instagram: el máximo vigente pasa a 100 MB y el archivo ya elegido se
    // re-valida al instante, sin enviar nada a Meta.
    $c->set('toInstagram', true)
        ->assertSee('Máximo 100 MB para Instagram')
        ->assertHasErrors('video')
        ->assertSee('Las Historias de Instagram admiten videos de hasta 100 MB.');

    Http::assertNothingSent();
});

it('cambiar el tipo de medio o de publicación limpia el upload anterior', function () {
    [, $user] = mfCtx();
    Storage::fake('public');

    $c = Livewire::actingAs($user)->test(Publisher::class)
        ->call('setPostMedia', 'video')
        ->set('video', UploadedFile::fake()->create('v.mp4', 1024, 'video/mp4'));

    // Post Video → Post Imagen: el video se descarta.
    $c->call('setPostMedia', 'image')->assertSet('video', null);

    // Post Imagen → Reel → Post Video: cada salto limpia el archivo incompatible.
    $c->set('image', UploadedFile::fake()->image('foto.jpg', 100, 100))
        ->call('setContentType', 'reel')
        ->assertSet('image', null)
        ->set('video', UploadedFile::fake()->create('r.mp4', 1024, 'video/mp4'))
        ->call('setContentType', 'post')
        ->assertSet('video', null);
});

// ----------------------------------------------------------------------------------
// Rollup con processing: nunca partial mientras algo siga en curso
// ----------------------------------------------------------------------------------
it('rollup: Facebook published + Instagram processing = post processing (no partial)', function () {
    mfCtx();
    Http::fake(function ($request) {
        $url = $request->url();
        if (str_contains($url, 'rupload.facebook.com')) {
            return Http::response(['success' => true], 200);
        }
        if (str_contains($url, '/video_reels')) {
            return ($request['upload_phase'] ?? null) === 'start'
                ? Http::response(['video_id' => 'VID_1', 'upload_url' => 'https://rupload.facebook.com/video-upload/v26.0/VID_1'], 200)
                : Http::response(['success' => true, 'post_id' => 'FB_POST_V1'], 200);
        }
        if (str_contains($url, '/media_publish')) {
            return Http::response(['id' => 'X'], 200);
        }
        if (str_contains($url, '/media')) {
            return Http::response(['id' => 'IG_CONT_MIX'], 200);
        }

        return Http::response(['status_code' => 'IN_PROGRESS'], 200); // IG nunca termina aquí
    });

    $post = mfService()->publish(SocialPost::factory()->reel()->create(), ['facebook', 'instagram']);

    expect($post->status)->toBe('processing');
    expect($post->targets->firstWhere('network', 'facebook')->status)->toBe('published');
    expect($post->targets->firstWhere('network', 'instagram')->status)->toBe('processing');
});
