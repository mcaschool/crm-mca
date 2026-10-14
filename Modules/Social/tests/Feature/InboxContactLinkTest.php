<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Crm\Models\Contact;
use Modules\Crm\Models\Lead;
use Modules\Institutions\Models\Institution;
use Modules\Social\Livewire\Inbox;
use Modules\Social\Models\SocialChannel;
use Modules\Social\Models\SocialConversation;
use Modules\Social\Support\ConversationContactLink;

/*
| Bandeja social ↔ Contactos y Leads (etapa 7): solo relaciones verificables. WhatsApp se vincula
| por el número (wa_id normalizado a E.164) con el contacto de la MISMA institución; Instagram y
| Messenger no tienen identificador verificable y nunca se vinculan (tampoco por nombre). Nada se
| guarda ni se crea; la recepción y el envío no cambian.
*/

beforeEach(function () {
    Http::fake();
});

/** @return array{0:User,1:Institution} */
function linkCtx(): array
{
    $institution = Institution::factory()->create();
    app(CurrentInstitution::class)->set($institution->id);
    $user = User::factory()->create(['institution_id' => $institution->id, 'role' => 'admissions', 'status' => 'active']);

    return [$user, $institution];
}

function linkConversation(string $provider, string $externalId, string $name = 'Camila Restrepo'): SocialConversation
{
    $channel = SocialChannel::factory()->create(['provider' => $provider, 'display_name' => 'Cuenta '.$provider, 'external_id' => strtoupper($provider).'_'.uniqid(), 'credentials' => ['token' => 'X']]);

    return SocialConversation::factory()->create(['social_channel_id' => $channel->id, 'provider' => $provider, 'contact_name' => $name,
        'external_conversation_id' => 'c_'.uniqid(), 'contact_external_id' => $externalId, 'unread_count' => 2, 'last_message_at' => now()]);
}

it('WhatsApp: vincula por el número exacto con el contacto de la institución y muestra sus leads', function () {
    [$user] = linkCtx();
    $contact = Contact::factory()->phoneOnly('+57 300 111 2233')->create(['first_name' => 'Camila', 'last_name' => 'Restrepo']);
    Lead::factory()->create(['contact_id' => $contact->id, 'status' => 'contacted', 'product_type' => 'microcredential']);
    $conv = linkConversation('whatsapp', '573001112233');
    $contactsBefore = Contact::query()->count();

    expect(ConversationContactLink::resolve($conv))->toMatchArray(['state' => 'linked', 'phone' => '+573001112233'])
        ->and(ConversationContactLink::resolve($conv)['contact']->id)->toBe($contact->id);

    Livewire::actingAs($user)->test(Inbox::class)->call('select', $conv->id)
        ->assertSeeHtml('data-testid="inbox-crm-contact"')
        ->assertSeeHtml('href="'.route('crm.contacts.show', $contact).'"')
        ->assertSee('Vinculado por el número de WhatsApp.')
        ->assertSee('Contactado');

    expect(Contact::query()->count())->toBe($contactsBefore);   // no crea contactos
    Http::assertNothingSent();
});

it('WhatsApp sin contacto con ese número (o con un número parecido) no se vincula', function () {
    [$user] = linkCtx();
    Contact::factory()->phoneOnly('+57 300 111 2234')->create();                 // un dígito distinto
    $conv = linkConversation('whatsapp', '573001112233');

    expect(ConversationContactLink::resolve($conv)['state'])->toBe('none');
    Livewire::actingAs($user)->test(Inbox::class)->call('select', $conv->id)
        ->assertDontSeeHtml('data-testid="inbox-crm-contact"')
        ->assertSee('Ningún contacto del CRM tiene este número de WhatsApp.');
});

it('nunca vincula con un contacto de otra institución aunque tenga el mismo número', function () {
    $other = Institution::factory()->create();
    app(CurrentInstitution::class)->set($other->id);
    Contact::factory()->phoneOnly('+57 300 111 2233')->create();

    [$user] = linkCtx();
    $conv = linkConversation('whatsapp', '573001112233');

    expect(ConversationContactLink::resolve($conv)['state'])->toBe('none');
    Livewire::actingAs($user)->test(Inbox::class)->call('select', $conv->id)->assertDontSeeHtml('data-testid="inbox-crm-contact"');
});

it('Instagram y Messenger no se vinculan, ni siquiera si el nombre coincide', function (string $provider) {
    [$user] = linkCtx();
    Contact::factory()->phoneOnly('+57 300 111 2233')->create(['first_name' => 'Camila', 'last_name' => 'Restrepo']);
    $conv = linkConversation($provider, '573001112233', 'Camila Restrepo');

    expect(ConversationContactLink::resolve($conv))->toMatchArray(['state' => 'unsupported', 'contact' => null]);
    Livewire::actingAs($user)->test(Inbox::class)->call('select', $conv->id)
        ->assertDontSeeHtml('data-testid="inbox-crm-contact"')
        ->assertSee('no se vinculan automáticamente');
})->with(['instagram', 'messenger']);

it('sin permiso para ver el contacto, el panel no muestra datos comerciales', function () {
    [$user] = linkCtx();
    $contact = Contact::factory()->phoneOnly('+57 300 111 2233')->create();
    $conv = linkConversation('whatsapp', '573001112233');
    Gate::before(fn ($actor, string $ability, array $args) => $ability === 'view' && ($args[0] ?? null) instanceof Contact ? false : null);

    Livewire::actingAs($user)->test(Inbox::class)->call('select', $conv->id)
        ->assertDontSeeHtml('data-testid="inbox-crm-contact"')
        ->assertDontSeeHtml(route('crm.contacts.show', $contact));
});

it('no puede haber dos contactos con el mismo teléfono en una institución (por eso no hay coincidencias múltiples)', function () {
    linkCtx();
    Contact::factory()->phoneOnly('+57 300 111 2233')->create();

    expect(fn () => Contact::factory()->phoneOnly('+573001112233')->create())->toThrow(QueryException::class);
});
