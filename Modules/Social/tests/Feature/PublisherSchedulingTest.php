<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Livewire\Livewire;
use Modules\Audit\Models\AuditLog;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Institutions\Models\Institution;
use Modules\Social\Livewire\Publisher;
use Modules\Social\Livewire\PublisherCalendar;
use Modules\Social\Livewire\PublisherHistory;
use Modules\Social\Livewire\PublisherScheduled;
use Modules\Social\Models\SocialChannel;
use Modules\Social\Models\SocialPost;
use Modules\Social\Models\SocialPostTarget;
use Modules\Social\Services\SocialPostScheduler;

/*
| Publicador · historial, borradores, programación automática y calendario editorial.
| Meta SIEMPRE simulado (Http::fake): ninguna prueba publica de verdad. Cubre borradores,
| programar y ejecutar con el programador, reprogramar y cancelar, zonas horarias, prevención
| de duplicados, resultado independiente por red con reintento solo de la red fallida, fallos
| de la API, permisos, aislamiento por institución, historial con filtros y calendario.
*/

beforeEach(function () {
    config(['social.graph_version' => 'v26.0', 'social.fake_publish' => null]);
    Sleep::fake();
    Storage::fake('public');
});

/**
 * @return array{0: Institution, 1: User, 2: SocialChannel, 3: SocialChannel}
 */
function psCtx(string $role = 'marketing', string $tz = 'Europe/Madrid'): array
{
    $institution = Institution::factory()->create(['timezone' => $tz]);
    app(CurrentInstitution::class)->set($institution->id);
    $user = User::factory()->create(['institution_id' => $institution->id, 'role' => $role, 'status' => 'active']);
    config(['auth.two_factor.exempt_emails' => [mb_strtolower($user->email)]]);

    $fb = SocialChannel::factory()->create(['provider' => 'messenger', 'external_id' => 'PAGE_1', 'is_active' => true, 'credentials' => ['token' => 'FB_TOKEN']]);
    $ig = SocialChannel::factory()->create(['provider' => 'instagram', 'external_id' => 'IGU_1', 'is_active' => true, 'credentials' => ['token' => 'IG_TOKEN']]);

    return [$institution, $user, $fb, $ig];
}

/**
 * Meta simulado. FB: /photos. IG: /media (contenedor), /media_publish, GET estado y permalink.
 * $igFail = true → Instagram rechaza el contenedor.
 */
function psFakeMeta(bool $igFail = false): void
{
    $GLOBALS['ps_ig_fail'] = $igFail;
    Http::fake(function ($request) {
        $igFail = $GLOBALS['ps_ig_fail'] ?? false;
        $url = $request->url();
        if ($request->method() === 'DELETE') {
            return Http::response(['success' => true], 200);
        }
        if (str_contains($url, '/photos')) {
            return Http::response(['id' => 'PHOTO_1', 'post_id' => 'PAGE_1_POST_1'], 200);
        }
        if (str_contains($url, '/media_publish')) {
            return Http::response(['id' => 'IG_MEDIA_1'], 200);
        }
        if (str_contains($url, '/media')) {
            return $igFail
                ? Http::response(['error' => ['message' => 'Invalid image', 'code' => 9004]], 400)
                : Http::response(['id' => 'IG_CONT_1'], 200);
        }
        if (str_contains($url, 'permalink')) {
            return Http::response(['permalink' => 'https://www.instagram.com/p/ABC/'], 200);
        }
        if ($request->method() === 'POST') {
            return Http::response(['success' => true], 200); // edición del texto de un post de Página
        }

        return Http::response(['status_code' => 'FINISHED'], 200);
    });
}

function psCount(string $needle): int
{
    return Http::recorded(fn ($request) => str_contains($request->url(), $needle))->count();
}

/** Programada (Post de imagen) para dentro de $minutes, en ambas redes. */
function psScheduled(int $minutes = 30, array $networks = ['facebook', 'instagram'], array $attrs = []): SocialPost
{
    return SocialPost::factory()->create(array_merge([
        'content_type' => 'post',
        'media_type' => 'image',
        'image_public_url' => 'https://cdn.mca.test/foto.jpg',
        'caption' => 'Programada de prueba',
        'status' => SocialPost::SCHEDULED,
        'networks' => $networks,
        'scheduled_at' => now()->addMinutes($minutes),
        'timezone' => 'Europe/Madrid',
    ], $attrs));
}

function psTick(): array
{
    return app(SocialPostScheduler::class)->tick();
}

// ----------------------------------------------------------------------------------
// Borradores
// ----------------------------------------------------------------------------------
it('guarda un borrador sin publicar: archivo persistente, destinos y sin llamadas a Meta', function () {
    [, $user] = psCtx();
    psFakeMeta();

    Livewire::actingAs($user)->test(Publisher::class)
        ->set('image', UploadedFile::fake()->image('promo.jpg', 1080, 1080))
        ->set('caption', 'Borrador de microcredenciales')
        ->set('toInstagram', false)
        ->call('saveDraft')
        ->assertHasNoErrors()
        ->assertSet('noticeTone', 'success');

    $post = SocialPost::query()->sole();
    expect($post->status)->toBe('draft')
        ->and($post->networks)->toBe(['facebook'])
        ->and($post->media_name)->toBe('promo.jpg')
        ->and($post->targets()->count())->toBe(0);
    Storage::disk('public')->assertExists($post->media_path);
    Http::assertNothingSent();
    expect(AuditLog::query()->where('action', 'social_post.draft_saved')->exists())->toBeTrue();
});

it('un borrador vacío no se guarda', function () {
    [, $user] = psCtx();

    Livewire::actingAs($user)->test(Publisher::class)->call('saveDraft')->assertHasErrors('image');

    expect(SocialPost::query()->count())->toBe(0);
});

it('recupera un borrador, cambia texto, destinos y archivo; el archivo anterior se borra', function () {
    [, $user] = psCtx();
    Livewire::actingAs($user)->test(Publisher::class)
        ->set('image', UploadedFile::fake()->image('uno.jpg', 800, 800))
        ->set('caption', 'Versión 1')
        ->call('saveDraft');
    $post = SocialPost::query()->sole();
    $oldPath = $post->media_path;

    Livewire::withQueryParams(['editar' => $post->id])->actingAs($user)->test(Publisher::class)
        ->assertSet('postId', $post->id)
        ->assertSet('caption', 'Versión 1')
        ->assertSee('Editando un borrador')
        ->assertSee('uno.jpg')
        ->set('caption', 'Versión 2')
        ->set('toFacebook', false)
        ->set('image', UploadedFile::fake()->image('dos.jpg', 800, 800))
        ->call('saveDraft')
        ->assertHasNoErrors();

    $post->refresh();
    expect(SocialPost::query()->count())->toBe(1)
        ->and($post->caption)->toBe('Versión 2')
        ->and($post->networks)->toBe(['instagram'])
        ->and($post->media_name)->toBe('dos.jpg');
    Storage::disk('public')->assertMissing($oldPath);
    Storage::disk('public')->assertExists($post->media_path);
});

it('una publicación ya publicada no se abre en el editor', function () {
    [, $user] = psCtx();
    $post = psScheduled(attrs: ['status' => SocialPost::PUBLISHED]);

    Livewire::withQueryParams(['editar' => $post->id])->actingAs($user)->test(Publisher::class)
        ->assertSet('postId', null)
        ->assertSee('ya no se puede editar');
});

it('publicar ya un borrador desde el editor usa el reclamo atómico y deja el resultado por red', function () {
    [, $user] = psCtx();
    psFakeMeta();
    Livewire::actingAs($user)->test(Publisher::class)
        ->set('image', UploadedFile::fake()->image('a.jpg', 800, 800))->set('caption', 'Ya')->call('saveDraft');
    $post = SocialPost::query()->sole();

    Livewire::withQueryParams(['editar' => $post->id])->actingAs($user)->test(Publisher::class)
        ->call('publish')
        ->assertHasNoErrors()
        ->assertSet('postId', null);

    $post->refresh();
    expect($post->status)->toBe('published')
        ->and($post->targets()->where('status', 'published')->count())->toBe(2)
        ->and($post->published_at)->not->toBeNull();
    expect(psCount('/photos'))->toBe(1)->and(psCount('/media_publish'))->toBe(1);
});

// ----------------------------------------------------------------------------------
// Programar y ejecutar
// ----------------------------------------------------------------------------------
it('programa desde el editor: guarda en UTC según la zona elegida y no llama a Meta', function () {
    Carbon::setTestNow('2026-10-14 08:00:00');
    [, $user] = psCtx();
    psFakeMeta();

    Livewire::actingAs($user)->test(Publisher::class)
        ->set('image', UploadedFile::fake()->image('p.jpg', 1080, 1080))
        ->set('caption', 'Programada')
        ->call('setWhen', 'schedule')
        ->set('scheduleDate', '2026-10-20')
        ->set('scheduleTime', '09:30')
        ->set('scheduleTz', 'America/Mexico_City')
        ->call('schedule')
        ->assertHasNoErrors()
        ->assertRedirect(route('social.publisher.scheduled'));

    $post = SocialPost::query()->sole();
    expect($post->status)->toBe('scheduled')
        ->and($post->timezone)->toBe('America/Mexico_City')
        ->and($post->scheduled_at->utc()->format('Y-m-d H:i'))->toBe('2026-10-20 15:30'); // CDMX = UTC-6
    Http::assertNothingSent();
    Carbon::setTestNow();
});

it('rechaza programar en el pasado, en una hora inexistente (cambio de hora) o con zona inválida', function (string $date, string $time, string $tz, string $msg) {
    Carbon::setTestNow('2026-10-14 08:00:00');
    [, $user] = psCtx();

    Livewire::actingAs($user)->test(Publisher::class)
        ->set('image', UploadedFile::fake()->image('p.jpg', 800, 800))
        ->call('setWhen', 'schedule')
        ->set('scheduleDate', $date)->set('scheduleTime', $time)->set('scheduleTz', $tz)
        ->call('schedule')
        ->assertHasErrors('scheduleDate')
        ->assertSee($msg);

    expect(SocialPost::query()->count())->toBe(0);
    Carbon::setTestNow();
})->with([
    'pasado' => ['2026-10-13', '10:00', 'Europe/Madrid', 'al menos 2 minutos en el futuro'],
    'hueco del cambio de hora' => ['2027-03-28', '02:30', 'Europe/Madrid', 'no existe en esa zona horaria'],
    'zona inválida' => ['2026-10-20', '10:00', 'Marte/Olympus', 'zona horaria válida'],
]);

it('al programar con Instagram bloquea el PNG antes de guardar nada', function () {
    [, $user] = psCtx();

    Livewire::actingAs($user)->test(Publisher::class)
        ->set('image', UploadedFile::fake()->image('p.png', 800, 800))
        ->call('setWhen', 'schedule')
        ->set('scheduleDate', now()->addDay()->format('Y-m-d'))->set('scheduleTime', '10:00')
        ->call('schedule')
        ->assertHasErrors('image');

    expect(SocialPost::query()->count())->toBe(0);
});

it('el programador publica lo vencido y nada antes de su hora', function () {
    psCtx();
    psFakeMeta();
    $post = psScheduled(30);

    expect(psTick()['published'])->toBe(0);
    Http::assertNothingSent();

    $this->travel(31)->minutes();
    $out = psTick();

    $post->refresh();
    expect($out['published'])->toBe(1)
        ->and($post->status)->toBe('published')
        ->and($post->run_id)->toBeNull()
        ->and($post->attempts)->toBe(1)
        ->and($post->published_at)->not->toBeNull();
    $ig = $post->targets()->where('network', 'instagram')->sole();
    expect($ig->permalink)->toBe('https://www.instagram.com/p/ABC/')
        ->and($post->targets()->where('network', 'facebook')->sole()->external_post_id)->toBe('PAGE_1_POST_1');
});

it('el comando programado funciona sin sesión iniciada y una segunda pasada no duplica', function () {
    psCtx();
    psFakeMeta();
    $post = psScheduled(5);
    $this->travel(6)->minutes();
    auth()->logout();

    $this->artisan('social:publish-scheduled')->assertSuccessful();
    $this->artisan('social:publish-scheduled')->assertSuccessful();

    expect($post->refresh()->status)->toBe('published')
        ->and(psCount('/photos'))->toBe(1)
        ->and(psCount('/media_publish'))->toBe(1);
});

it('dos reclamos simultáneos: solo uno gana y un run_id ajeno no ejecuta nada', function () {
    psCtx();
    psFakeMeta();
    $post = psScheduled(-1);
    $scheduler = app(SocialPostScheduler::class);

    $first = $scheduler->claim($post, [SocialPost::SCHEDULED], requireDue: true);
    $second = $scheduler->claim($post, [SocialPost::SCHEDULED], requireDue: true);

    expect($first)->toBeString()->and($second)->toBeNull();
    expect($scheduler->run((int) $post->id, 'otro-run'))->toBeNull();
    Http::assertNothingSent();

    expect($scheduler->run((int) $post->id, $first)?->status)->toBe('published');
});

it('una ejecución interrumpida no se reintenta sola: sus redes quedan fallidas con aviso', function () {
    psCtx();
    psFakeMeta();
    $post = psScheduled(-30, attrs: ['status' => SocialPost::PREPARING]);
    $post->forceFill(['run_id' => 'perdido', 'locked_at' => now()->subMinutes(20)])->save();

    expect(psTick()['recovered'])->toBe(1);

    $post->refresh();
    expect($post->status)->toBe('failed')
        ->and($post->run_id)->toBeNull()
        ->and($post->targets()->where('status', 'failed')->count())->toBe(2)
        ->and($post->last_error)->toContain('Comprueba la publicación en la red');
    Http::assertNothingSent();
});

// ----------------------------------------------------------------------------------
// Programadas: reprogramar, cancelar, devolver a borrador
// ----------------------------------------------------------------------------------
it('una programación cancelada nunca se ejecuta', function () {
    [, $user] = psCtx();
    psFakeMeta();
    $post = psScheduled(10);

    Livewire::actingAs($user)->test(PublisherScheduled::class)
        ->call('cancelPost', $post->id)
        ->assertSet('noticeTone', 'success');

    $this->travel(2)->hours();
    psTick();

    $post->refresh();
    expect($post->status)->toBe('cancelled')
        ->and($post->cancelled_by)->toBe($user->id);
    Http::assertNothingSent();
    expect(AuditLog::query()->where('action', 'social_post.cancelled')->exists())->toBeTrue();
});

it('reprograma: a la hora antigua no sale, a la nueva sí', function () {
    [, $user] = psCtx(tz: 'UTC');
    psFakeMeta();
    $post = psScheduled(10);
    $new = now()->addHours(3);

    Livewire::actingAs($user)->test(PublisherScheduled::class)
        ->call('startReschedule', $post->id)
        ->assertSet('rescheduleId', $post->id)
        ->set('rescheduleDate', $new->format('Y-m-d'))
        ->set('rescheduleTime', $new->format('H:i'))
        ->set('rescheduleTz', 'UTC')
        ->call('saveReschedule')
        ->assertHasNoErrors();

    $this->travel(20)->minutes();
    psTick();
    expect($post->refresh()->status)->toBe('scheduled');
    Http::assertNothingSent();

    $this->travel(3)->hours();
    psTick();
    expect($post->refresh()->status)->toBe('published');
});

it('devolver a borrador saca la publicación de la cola y permite recuperar una cancelada', function () {
    [, $user] = psCtx();
    psFakeMeta();
    $post = psScheduled(5);
    $cancelled = psScheduled(5, attrs: ['status' => SocialPost::CANCELLED]);

    Livewire::actingAs($user)->test(PublisherScheduled::class)
        ->call('toDraft', $post->id)
        ->call('toDraft', $cancelled->id);

    $this->travel(1)->hour();
    psTick();
    expect($post->refresh()->status)->toBe('draft')->and($post->scheduled_at)->toBeNull()
        ->and($cancelled->refresh()->status)->toBe('draft');
    Http::assertNothingSent();
});

it('no se modifica una publicación que ya se está procesando', function () {
    [, $user] = psCtx();
    $post = psScheduled(-1, attrs: ['status' => SocialPost::PREPARING]);

    Livewire::actingAs($user)->test(PublisherScheduled::class)
        ->call('cancelPost', $post->id)->assertSet('noticeTone', 'warning')
        ->call('toDraft', $post->id)
        ->call('deleteDraft', $post->id)
        ->call('publishNow', $post->id);

    expect($post->refresh()->status)->toBe('preparing');
});

it('publicar ya una programada desde Programadas la ejecuta una sola vez', function () {
    [, $user] = psCtx();
    psFakeMeta();
    $post = psScheduled(120);

    Livewire::actingAs($user)->test(PublisherScheduled::class)
        ->call('publishNow', $post->id)
        ->assertSet('noticeTone', 'success');

    $this->travel(3)->hours();
    psTick();
    expect($post->refresh()->status)->toBe('published')->and(psCount('/photos'))->toBe(1);
});

it('elimina un borrador y su archivo, pero no una publicación con resultado', function () {
    [, $user] = psCtx();
    Storage::disk('public')->put('social-posts/draft.jpg', 'x');
    $draft = SocialPost::factory()->create(['status' => 'draft', 'image_path' => null, 'media_path' => 'social-posts/draft.jpg', 'media_type' => 'image']);
    $published = psScheduled(attrs: ['status' => SocialPost::PUBLISHED]);

    Livewire::actingAs($user)->test(PublisherScheduled::class)
        ->call('deleteDraft', $draft->id)
        ->call('deleteDraft', $published->id)
        ->assertSet('noticeTone', 'warning');

    expect(SocialPost::query()->whereKey($draft->id)->exists())->toBeFalse()
        ->and(SocialPost::query()->whereKey($published->id)->exists())->toBeTrue();
    Storage::disk('public')->assertMissing('social-posts/draft.jpg');
});

// ----------------------------------------------------------------------------------
// Resultado independiente por red, fallos y reintentos seguros
// ----------------------------------------------------------------------------------
it('Facebook publicado + Instagram fallido = parcial; reintentar solo Instagram no republica en Facebook', function () {
    [, $user] = psCtx();
    psFakeMeta(igFail: true);
    $post = psScheduled(-1);
    psTick();

    $post->refresh();
    $ig = $post->targets()->where('network', 'instagram')->sole();
    expect($post->status)->toBe('partial')
        ->and($post->targets()->where('network', 'facebook')->sole()->status)->toBe('published')
        ->and($ig->status)->toBe('failed')
        ->and($ig->error_message)->not->toBe('');

    $GLOBALS['ps_ig_fail'] = false; // Instagram ya acepta
    Livewire::actingAs($user)->test(PublisherHistory::class)
        ->call('retryTarget', $ig->id)
        ->assertSet('noticeTone', 'success');

    expect($ig->refresh()->status)->toBe('published')
        ->and($ig->attempts)->toBe(2)
        ->and($post->refresh()->status)->toBe('published')
        ->and(psCount('/photos'))->toBe(1); // Facebook solo en la ejecución original
});

it('una red publicada no se puede reintentar ni volver a enviar', function () {
    [, $user] = psCtx();
    psFakeMeta();
    $post = psScheduled(-1);
    psTick();
    $before = Http::recorded()->count();
    $fb = $post->targets()->where('network', 'facebook')->sole();

    Livewire::actingAs($user)->test(PublisherHistory::class)->call('retryTarget', $fb->id);

    expect(Http::recorded()->count())->toBe($before);
});

it('un canal desconectado deja esa red fallida con su motivo (no se omite en silencio)', function () {
    [, , , $ig] = psCtx();
    psFakeMeta();
    $ig->update(['is_active' => false]);
    $post = psScheduled(-1);
    psTick();

    $t = $post->targets()->where('network', 'instagram')->sole();
    expect($t->status)->toBe('failed')
        ->and($t->error_message)->toContain('No hay una cuenta de Instagram activa')
        ->and($post->refresh()->status)->toBe('partial');
});

it('respeta el límite de 50 publicaciones de Instagram en 24 h sin llamar a Meta', function () {
    [, , , $ig] = psCtx();
    psFakeMeta();
    foreach (range(1, 50) as $i) {
        $old = psScheduled(attrs: ['status' => SocialPost::PUBLISHED]);
        SocialPostTarget::factory()->create(['social_post_id' => $old->id, 'network' => 'instagram', 'social_channel_id' => $ig->id, 'status' => 'published', 'published_at' => now()->subHours(2)]);
    }
    $post = psScheduled(-1, ['instagram']);
    psTick();

    $t = $post->targets()->sole();
    expect($t->status)->toBe('failed')->and($t->error_message)->toContain('50 publicaciones');
    expect(psCount('/media'))->toBe(0);
});

it('reanuda un video de Instagram en proceso sin crear otro contenedor y lo caduca a las 24 h', function () {
    [, , , $ig] = psCtx();
    psFakeMeta();
    $post = psScheduled(attrs: ['status' => SocialPost::PROCESSING, 'content_type' => 'reel', 'media_type' => 'video']);
    $t = SocialPostTarget::factory()->create(['social_post_id' => $post->id, 'network' => 'instagram', 'social_channel_id' => $ig->id, 'status' => 'processing', 'container_id' => 'IG_CONT_9']);
    $t->forceFill(['last_attempt_at' => now()->subMinutes(2)])->save();

    expect(psTick()['resumed'])->toBe(1);
    expect($t->refresh()->status)->toBe('published')->and($post->refresh()->status)->toBe('published');
    expect(Http::recorded(fn ($request) => $request->method() === 'POST' && str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), '/media'))->count())->toBe(0);

    $old = psScheduled(attrs: ['status' => SocialPost::PROCESSING, 'content_type' => 'reel', 'media_type' => 'video']);
    $stale = SocialPostTarget::factory()->create(['social_post_id' => $old->id, 'network' => 'instagram', 'social_channel_id' => $ig->id, 'status' => 'processing', 'container_id' => 'IG_CONT_OLD']);
    $stale->forceFill(['created_at' => now()->subHours(25), 'last_attempt_at' => now()->subHours(1)])->save();
    psTick();
    expect($stale->refresh()->status)->toBe('failed')->and($stale->error_message)->toContain('caducó (24 h)');
});

// ----------------------------------------------------------------------------------
// Operaciones remotas que Meta admite (solo Facebook)
// ----------------------------------------------------------------------------------
it('edita el texto y elimina en Facebook; Instagram e Historias no ofrecen esas acciones', function () {
    [, $user] = psCtx();
    psFakeMeta();
    $post = psScheduled(-1);
    psTick();
    $fb = $post->targets()->where('network', 'facebook')->sole();
    $igT = $post->targets()->where('network', 'instagram')->sole();

    $c = Livewire::actingAs($user)->test(PublisherHistory::class)->call('openPost', $post->id)
        ->assertSee('Editar texto')->assertSee('Eliminar de Facebook')
        ->assertSee('Instagram no permite editar la descripción por API');
    expect($c->instance()->canDeleteRemote($igT))->toBeFalse();

    $c->call('startRemoteEdit', $fb->id)->set('remoteCaption', 'Texto corregido')->call('saveRemoteEdit')->assertHasNoErrors();
    Http::assertSent(fn ($r) => $r->method() === 'POST' && str_contains($r->url(), '/PAGE_1_POST_1') && $r['message'] === 'Texto corregido');

    $c->call('deleteRemote', $fb->id);
    Http::assertSent(fn ($r) => $r->method() === 'DELETE' && str_contains($r->url(), '/PAGE_1_POST_1'));
    expect($fb->refresh()->remote_deleted_at)->not->toBeNull()->and($fb->statusLabel())->toBe('Eliminado de la red');

    $story = psScheduled(-1, ['facebook'], ['content_type' => 'story', 'status' => SocialPost::PUBLISHED]);
    $st = SocialPostTarget::factory()->create(['social_post_id' => $story->id, 'network' => 'facebook', 'status' => 'published', 'external_post_id' => 'STORY_1', 'social_channel_id' => $fb->social_channel_id]);
    expect($c->instance()->canDeleteRemote($st->load('channel', 'post')))->toBeFalse();
});

// ----------------------------------------------------------------------------------
// Permisos y aislamiento
// ----------------------------------------------------------------------------------
it('Admisiones no accede al Publicador ni a sus acciones', function () {
    [, $user] = psCtx('admissions');

    foreach (['/social/publicar', '/social/publicar/historial', '/social/publicar/programadas', '/social/publicar/calendario'] as $url) {
        $this->actingAs($user)->get($url)->assertForbidden();
    }
    Livewire::actingAs($user)->test(PublisherScheduled::class)->assertForbidden();
});

it('una institución no ve ni toca las publicaciones de otra', function () {
    psCtx();
    $foreign = psScheduled(10);

    [, $userB] = psCtx();
    psFakeMeta();

    Livewire::actingAs($userB)->test(PublisherHistory::class)
        ->assertDontSee('Programada de prueba')
        ->call('openPost', $foreign->id)->assertSet('detailId', null)
        ->call('cancelPost', $foreign->id)
        ->call('publishNow', $foreign->id);

    app(CurrentInstitution::class)->set($foreign->institution_id);
    expect($foreign->refresh()->status)->toBe('scheduled');
    Http::assertNothingSent();
});

// ----------------------------------------------------------------------------------
// Historial, filtros y calendario
// ----------------------------------------------------------------------------------
it('el historial incluye las publicaciones anteriores y filtra por red, estado, texto y fecha', function () {
    [, $user] = psCtx(tz: 'UTC');
    $legacy = SocialPost::factory()->create(['caption' => 'Antigua inmediata', 'status' => 'published', 'networks' => null, 'published_at' => now()->subDays(40)]);
    SocialPostTarget::factory()->create(['social_post_id' => $legacy->id, 'network' => 'facebook', 'status' => 'published']);
    psScheduled(60, ['instagram'], ['caption' => 'Solo Instagram programada']);
    SocialPost::factory()->create(['caption' => 'Borrador suelto', 'status' => 'draft', 'networks' => ['facebook']]);

    Livewire::actingAs($user)->test(PublisherHistory::class)
        ->assertSee('Antigua inmediata')->assertSee('Solo Instagram programada')->assertSee('Borrador suelto')
        ->set('network', 'instagram')->assertSee('Solo Instagram programada')->assertDontSee('Antigua inmediata')
        ->set('network', '')->set('status', 'draft')->assertSee('Borrador suelto')->assertDontSee('Solo Instagram programada')
        ->set('status', '')->set('search', 'antigua')->assertSee('Antigua inmediata')->assertDontSee('Borrador suelto')
        ->set('search', '')->set('from', now()->subDays(41)->format('Y-m-d'))->set('to', now()->subDays(39)->format('Y-m-d'))
        ->assertSee('Antigua inmediata')->assertDontSee('Solo Instagram programada')
        ->call('clearFilters')->assertSee('Borrador suelto');
});

it('el historial pagina de 15 en 15', function () {
    [, $user] = psCtx();
    SocialPost::factory()->count(17)->create(['status' => 'published']);

    $c = Livewire::actingAs($user)->test(PublisherHistory::class);
    expect($c->viewData('posts')->count())->toBe(15)->and($c->viewData('posts')->total())->toBe(17);
});

it('el calendario muestra cada publicación en su día local, con vista de agenda y sin borradores', function () {
    Carbon::setTestNow('2026-10-14 08:00:00');
    [, $user] = psCtx(tz: 'America/Mexico_City');
    // 2026-10-21 03:00 UTC = 20 oct 21:00 en Ciudad de México (el día local manda).
    $p = psScheduled(attrs: ['scheduled_at' => Carbon::parse('2026-10-21 03:00:00', 'UTC'), 'caption' => 'Noche en CDMX']);
    SocialPost::factory()->create(['caption' => 'Borrador fuera', 'status' => 'draft', 'networks' => ['facebook']]);

    $c = Livewire::actingAs($user)->test(PublisherCalendar::class)->set('month', '2026-10');
    expect($c->viewData('byDay')['2026-10-20']->pluck('id')->all())->toBe([$p->id])
        ->and(isset($c->viewData('byDay')['2026-10-21']))->toBeFalse();
    $c->assertSeeHtml('wire:click="openPost('.$p->id.')"')->assertSee('21:00')
        ->call('setMode', 'list')->assertSee('Noche en CDMX')->assertDontSee('Borrador fuera')
        ->call('nextMonth')->assertSet('month', '2026-11')->assertSee('Nada en este mes.');
    Carbon::setTestNow();
});

it('las cuatro pantallas renderizan con sus pestañas', function () {
    [, $user] = psCtx('admin');
    psScheduled(60, attrs: ['caption' => 'Visible en programadas']);

    foreach (['/social/publicar', '/social/publicar/historial', '/social/publicar/programadas', '/social/publicar/calendario'] as $url) {
        $html = $this->actingAs($user)->get($url)->assertOk()->getContent();
        expect($html)->toContain('class="ui-tabs"')->toContain('Calendario editorial')->toContain('Programadas');
    }
    $this->get('/social/publicar/programadas')->assertSee('Visible en programadas');
    $this->get('/social/publicar')->assertSee('Guardar borrador')->assertSee('Programar');
});
