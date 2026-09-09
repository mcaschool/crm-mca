<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Institutions\Models\Institution;
use Modules\Social\Models\SocialChannel;
use Modules\Social\Models\SocialConversation;
use Modules\Social\Models\SocialMessage;
use Modules\Social\Services\NormalizedMessage;
use Modules\Social\Services\SocialIngestService;

/**
 * Resolución del perfil del contacto (nombre + foto) al ingerir un entrante de Messenger/IG,
 * vía Graph API con el Page token del canal (Http::fake, sin Meta real). Verifica: resolución
 * en el primer mensaje, fallback @username en IG, que un fallo de Graph NO bloquea la ingesta,
 * que no se vuelve a llamar si el contacto ya tiene nombre, y que WhatsApp no dispara Graph.
 */
beforeEach(function () {
    config(['social.graph_version' => 'v26.0']);
});

function contactCtx(string $provider, string $channelExt): SocialChannel
{
    $institution = Institution::factory()->create();
    app(CurrentInstitution::class)->set($institution->id);

    return SocialChannel::factory()->create([
        'provider' => $provider,
        'external_id' => $channelExt,
        'is_active' => true,
        'credentials' => ['token' => 'PAGE_TOKEN'],
    ]);
}

function inbound(string $provider, string $channelExt, string $contactId, string $mid = 'mid_1'): NormalizedMessage
{
    return new NormalizedMessage(
        provider: $provider,
        channelExternalId: $channelExt,
        conversationExternalId: $contactId,
        contactName: null,
        contactExternalId: $contactId,
        contactAvatarUrl: null,
        messageExternalId: $mid,
        type: 'text',
        body: 'Hola',
        attachments: null,
        providerTimestamp: null,
    );
}

function ingestService(): SocialIngestService
{
    return app(SocialIngestService::class);
}

it('Messenger: resuelve nombre y foto del contacto al ingerir el primer mensaje', function () {
    contactCtx('messenger', 'PAGE_1');
    Http::fake(['graph.facebook.com/*' => Http::response(['name' => 'Juan Pérez', 'profile_pic' => 'https://cdn.fb/pic.jpg'], 200)]);

    ingestService()->ingest(inbound('messenger', 'PAGE_1', 'PSID_1'));

    $conv = SocialConversation::query()->first();
    expect($conv->contact_name)->toBe('Juan Pérez');
    expect($conv->contact_avatar_url)->toBe('https://cdn.fb/pic.jpg');

    Http::assertSent(fn ($r) => str_contains($r->url(), 'https://graph.facebook.com/v26.0/PSID_1')
        && $r['fields'] === 'name,profile_pic'
        && $r->hasHeader('Authorization', 'Bearer PAGE_TOKEN'));
});

it('Instagram: usa @username como nombre cuando Graph no devuelve name', function () {
    contactCtx('instagram', 'IGU_1');
    Http::fake(['graph.facebook.com/*' => Http::response(['username' => 'juan.ig', 'profile_pic' => 'https://cdn.ig/pic.jpg'], 200)]);

    ingestService()->ingest(inbound('instagram', 'IGU_1', 'IGSID_1'));

    $conv = SocialConversation::query()->first();
    expect($conv->contact_name)->toBe('@juan.ig');
    expect($conv->contact_avatar_url)->toBe('https://cdn.ig/pic.jpg');
    Http::assertSent(fn ($r) => $r['fields'] === 'name,username,profile_pic');
});

it('si Graph falla, el mensaje se ingiere igual y el contacto queda sin nombre', function () {
    contactCtx('messenger', 'PAGE_1');
    Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'no autorizado']], 400)]);

    $result = ingestService()->ingest(inbound('messenger', 'PAGE_1', 'PSID_1'));

    expect($result->status)->toBe('created');
    $conv = SocialConversation::query()->first();
    expect($conv)->not->toBeNull();
    expect($conv->contact_name)->toBeNull();
    expect(SocialMessage::query()->count())->toBe(1);
});

it('no vuelve a llamar a Graph si el contacto ya tiene nombre', function () {
    contactCtx('messenger', 'PAGE_1');
    Http::fake(['graph.facebook.com/*' => Http::response(['name' => 'Ana', 'profile_pic' => 'https://cdn.fb/a.jpg'], 200)]);

    ingestService()->ingest(inbound('messenger', 'PAGE_1', 'PSID_1', 'mid_1'));
    ingestService()->ingest(inbound('messenger', 'PAGE_1', 'PSID_1', 'mid_2'));

    Http::assertSentCount(1);
    expect(SocialConversation::query()->first()->contact_name)->toBe('Ana');
});

it('una URL de foto muy larga (CDN de Meta) se guarda sin romper la ingesta', function () {
    contactCtx('instagram', 'IGU_1');
    // Las URLs firmadas de Meta superan 255 chars; la columna es TEXT tras la migración.
    $longUrl = 'https://scontent.cdninstagram.com/v/t51.2885-19/'.str_repeat('a', 600).'.jpg?oe=6AA761A8';
    Http::fake(['graph.facebook.com/*' => Http::response(['name' => 'Foto Larga', 'profile_pic' => $longUrl], 200)]);

    $result = ingestService()->ingest(inbound('instagram', 'IGU_1', 'IGSID_9'));

    expect($result->status)->toBe('created');
    $conv = SocialConversation::query()->first();
    expect($conv->contact_name)->toBe('Foto Larga');
    expect($conv->contact_avatar_url)->toBe($longUrl);
});

it('WhatsApp no dispara resolución por Graph', function () {
    contactCtx('whatsapp', 'WA_1');
    Http::fake();

    ingestService()->ingest(inbound('whatsapp', 'WA_1', 'WA_USER'));

    Http::assertNothingSent();
});
