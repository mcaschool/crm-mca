<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Institutions\Models\Institution;
use Modules\Social\Models\MetaLeadPage;
use Modules\Social\Models\SocialChannel;
use Modules\Social\Models\SocialMessage;
use Modules\Social\Models\SocialWhatsAppTemplate;
use Modules\Social\Services\DeliveryReconciler;
use Modules\Social\Services\MetaLeadFormService;
use Modules\Social\Services\MetaLeadPageService;
use Modules\Social\Services\NormalizedMessage;
use Modules\Social\Services\SocialIngestService;
use Modules\Social\Services\SocialPostScheduler;
use Modules\Social\Services\WhatsAppCoexistenceService;
use Modules\Social\Services\WhatsAppTemplateService;

it('4C retained ingestion uses the current scope and channel owner across worker resets', function (string $provider) {
    Http::fake();
    $a = Institution::factory()->create();
    $b = Institution::factory()->create();
    $ingest = app(SocialIngestService::class);
    foreach ([$a, $b] as $tenant) {
        app(CurrentInstitution::class)->runFor($tenant->id, fn () => SocialChannel::factory()->create([
            'provider' => $provider, 'external_id' => '4c-'.$tenant->id,
        ]));
    }
    foreach ([$a, $b, $a] as $tenant) {
        app()->forgetScopedInstances(); // Same container reset used by QueueServiceProvider's worker.
        $context = app(CurrentInstitution::class);
        $context->set($tenant->id === $a->id ? $b->id : $a->id);
        $outerId = $context->id();
        $message = new NormalizedMessage($provider, '4c-'.$tenant->id, 'same-contact', null, 'contact', null, 'same-message', 'text', 'test', null, null);
        $result = $ingest->ingest($message);
        expect($result->status)->toBeIn(['created', 'duplicate'])
            ->and($context->id())->toBe($outerId)->and($context->isGlobal())->toBeFalse();
        $context->runFor($tenant->id, fn () => expect(SocialMessage::query()->count())->toBe(1));
    }
    app(CurrentInstitution::class)->runGlobally(fn () => expect(SocialMessage::query()->count())->toBe(2));
})->with(['whatsapp', 'instagram', 'messenger']);

it('4C retained scheduled services resolve current context after reset', function (string $class, string $method) {
    Http::preventStrayRequests();
    $service = app($class);
    app()->forgetScopedInstances();
    $context = app(CurrentInstitution::class);
    expect($service->{$method}())->not->toBeNull();
    expect($context->isResolved())->toBeFalse()->and($context->isGlobal())->toBeFalse();
})->with([
    [DeliveryReconciler::class, 'run'],
    [SocialPostScheduler::class, 'tick'],
    [MetaLeadFormService::class, 'poll'],
]);

it('4C retained page ownership check does not use an expired scope', function () {
    $service = app(MetaLeadPageService::class);
    app()->forgetScopedInstances();
    $page = new MetaLeadPage(['page_id' => '4c-unknown', 'institution_id' => 123]);
    expect($service->takenElsewhere($page))->toBeFalse()
        ->and(app(CurrentInstitution::class)->isResolved())->toBeFalse();
});

it('4C retained WhatsApp template and account webhooks update only their server resolved owner', function (string $kind) {
    Http::preventStrayRequests();
    $a = Institution::factory()->create();
    $b = Institution::factory()->create();
    $templateService = app(WhatsAppTemplateService::class);
    $accountService = app(WhatsAppCoexistenceService::class);
    [$channel, $template] = app(CurrentInstitution::class)->runFor($a->id, function () {
        $channel = SocialChannel::factory()->create(['provider' => 'whatsapp', 'external_id' => '4c-phone', 'credentials' => ['waba_id' => '4c-waba']]);
        $template = SocialWhatsAppTemplate::factory()->pending()->create(['social_channel_id' => $channel->id, 'meta_template_id' => '4c-template', 'name' => 'test_template', 'language' => 'es']);

        return [$channel, $template];
    });
    app()->forgetScopedInstances();
    $context = app(CurrentInstitution::class);
    $context->set($b->id); // Must never determine the webhook's institution.
    if ($kind === 'template') {
        $payload = ['entry' => [['id' => '4c-waba', 'changes' => [['field' => 'message_template_status_update', 'value' => [
            'event' => 'APPROVED', 'message_template_id' => '4c-template', 'message_template_name' => 'test_template', 'message_template_language' => 'es',
        ]]]]]];
        expect($templateService->handleWebhook($payload))->toBe(1);
        $context->runFor($a->id, fn () => expect($template->fresh()->status)->toBe('APPROVED'));
    } else {
        $payload = ['entry' => [['id' => '4c-waba', 'changes' => [['field' => 'account_update', 'value' => [
            'event' => 'ACCOUNT_OFFBOARDED', 'metadata' => ['phone_number_id' => '4c-phone'],
        ]]]]]];
        expect($accountService->handleWebhook($payload)['account'])->toBe(1);
        $context->runFor($a->id, fn () => expect($channel->fresh()->connection_status)->toBe('offboarded'));
    }
    expect($context->id())->toBe($b->id)->and(SocialChannel::query()->count())->toBe(0);
})->with(['template', 'account']);
