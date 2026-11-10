<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Institutions\Models\Institution;
use Modules\Social\Models\MetaConnection;
use Modules\Social\Models\MetaLeadPage;
use Modules\Social\Models\SocialConversation;
use Modules\Social\Models\SocialPost;
use Modules\Social\Models\SocialPostTarget;
use Modules\Social\Support\SocialPendingWork;

/**
 * Las comprobaciones `when()` no pueden dar falsos negativos: cuando hay trabajo para un comando,
 * el planificador debe lanzarlo con la misma cadencia de siempre.
 */
function sgCommandEvent(string $needle): Event
{
    $event = collect(app(Schedule::class)->events())->first(fn (Event $e) => ! $e instanceof CallbackEvent && str_contains((string) $e->command, $needle));
    expect($event)->not->toBeNull();

    return $event;
}

beforeEach(function () {
    $inst = Institution::factory()->create();
    app(CurrentInstitution::class)->set($inst->id);
});

it('publicador: solo con publicaciones vencidas, interrumpidas o videos de Instagram por reanudar', function () {
    expect(SocialPendingWork::publishing())->toBeFalse();

    $post = SocialPost::factory()->create(['status' => SocialPost::SCHEDULED, 'scheduled_at' => now()->addMinutes(5)]);
    expect(SocialPendingWork::publishing())->toBeFalse('programada para dentro de 5 min: todavía no');

    $post->forceFill(['scheduled_at' => now()->subSecond()])->save();
    expect(SocialPendingWork::publishing())->toBeTrue('vencida: sí')
        ->and(sgCommandEvent('social:publish-scheduled')->filtersPass(app()))->toBeTrue();

    $post->forceFill(['status' => SocialPost::PREPARING, 'scheduled_at' => null, 'locked_at' => now()->subMinutes(5)])->save();
    expect(SocialPendingWork::publishing())->toBeFalse('en preparación hace 5 min: en curso');

    $post->forceFill(['locked_at' => now()->subMinutes(20)])->save();
    expect(SocialPendingWork::publishing())->toBeTrue('interrumpida > 15 min: recuperar');

    $post->forceFill(['status' => SocialPost::PUBLISHED, 'locked_at' => null])->save();
    expect(SocialPendingWork::publishing())->toBeFalse();

    $target = SocialPostTarget::factory()->create(['social_post_id' => $post->id, 'network' => 'instagram', 'status' => 'processing', 'container_id' => 'IG_C_1', 'last_attempt_at' => null]);
    expect(SocialPendingWork::publishing())->toBeTrue('video de Instagram procesándose: reanudar');

    $target->forceFill(['last_attempt_at' => now()])->save();
    expect(SocialPendingWork::publishing())->toBeFalse('reintentado hace un instante: esperar');

    $target->forceFill(['last_attempt_at' => now()->subSeconds(61)])->save();
    expect(SocialPendingWork::publishing())->toBeTrue();
});

it('formularios de Meta: solo con una Página recibiendo y sin kill-switch', function () {
    expect(SocialPendingWork::metaLeadForms())->toBeFalse();

    $connection = MetaConnection::query()->create(['token' => 'USER_TOKEN_A', 'status' => 'active', 'connected_at' => now()]);
    $page = MetaLeadPage::query()->create([
        'meta_connection_id' => $connection->id, 'page_id' => 'page_123', 'name' => 'MCA School', 'page_token' => 'PAGE_TOKEN_A',
        'selected' => true, 'access_status' => 'unchecked', 'receiving_enabled' => false,
    ]);
    expect(SocialPendingWork::metaLeadForms())->toBeFalse('sin recepción activa');

    $page->forceFill(['receiving_enabled' => true, 'access_status' => 'verified', 'available' => true])->save();
    expect(SocialPendingWork::metaLeadForms())->toBeTrue()
        ->and(sgCommandEvent('social:meta-leads-poll')->filtersPass(app()))->toBeTrue();

    config(['social.meta.lead_forms_kill_switch' => true]);
    expect(SocialPendingWork::metaLeadForms())->toBeFalse('kill-switch');
});

it('perfiles: solo con conversaciones de Messenger/Instagram con nombre o foto incompletos', function () {
    expect(SocialPendingWork::contactProfiles())->toBeFalse();

    $wa = SocialConversation::factory()->create(['provider' => 'whatsapp', 'contact_name' => null]);
    expect(SocialPendingWork::contactProfiles())->toBeFalse('WhatsApp no resuelve perfiles por API');

    $ig = SocialConversation::factory()->create(['provider' => 'instagram', 'contact_name' => null, 'contact_avatar_url' => null]);
    expect(SocialPendingWork::contactProfiles())->toBeTrue()
        ->and(sgCommandEvent('social:refresh-contact-profiles')->filtersPass(app()))->toBeTrue();

    $ig->forceFill(['contact_name' => 'Ana', 'contact_avatar_url' => 'https://example.test/a.jpg'])->save();
    expect(SocialPendingWork::contactProfiles())->toBeFalse('perfil completo');
    expect($wa->exists)->toBeTrue();
});
