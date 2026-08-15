<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Crm\Enums\LeadStatus;
use Modules\Crm\Livewire\Leads\Show;
use Modules\Crm\Models\Contact;
use Modules\Crm\Models\Lead;
use Modules\Institutions\Models\Bot;
use Modules\Institutions\Models\Institution;

function crmUser(string $role): User
{
    $institution = Institution::factory()->create();
    $user = User::factory()->create(['institution_id' => $institution->id, 'role' => $role]);
    app(CurrentInstitution::class)->set($institution->id);

    return $user;
}

function makeLead(int $institutionId): Lead
{
    return app(CurrentInstitution::class)->runFor($institutionId, function () {
        $contact = Contact::factory()->create();
        $bot = Bot::factory()->create();

        return Lead::factory()->create(['contact_id' => $contact->id, 'bot_id' => $bot->id]);
    });
}

it('los tres roles del CRM acceden a los leads', function () {
    foreach (['admin', 'marketing', 'admissions'] as $role) {
        $this->actingAs(crmUser($role))->get('/crm/leads')->assertOk();
        $this->actingAs(crmUser($role))->get('/crm/contacts')->assertOk();
    }
});

it('cambiar el estado de un lead desde el panel persiste', function () {
    $user = crmUser('marketing');
    $this->actingAs($user);
    $lead = makeLead($user->institution_id);

    Livewire::test(Show::class, ['lead' => $lead])
        ->set('status', 'qualified')
        ->call('changeStatus');

    expect($lead->fresh()->status)->toBe(LeadStatus::Qualified);
});

it("no permite cambiar un lead 'enrolled' (terminal) desde el panel", function () {
    $user = crmUser('admin');
    $this->actingAs($user);
    $lead = makeLead($user->institution_id);
    app(CurrentInstitution::class)->runFor($user->institution_id, fn () => $lead->update(['status' => 'enrolled']));

    Livewire::test(Show::class, ['lead' => $lead->fresh()])
        ->set('status', 'contacted')
        ->call('changeStatus')
        ->assertHasErrors('status');

    expect($lead->fresh()->status)->toBe(LeadStatus::Enrolled);
});

it('la accion destructiva (delete) es solo para Admin', function () {
    $institution = Institution::factory()->create();
    app(CurrentInstitution::class)->set($institution->id);
    $lead = makeLead($institution->id);

    $admin = User::factory()->create(['institution_id' => $institution->id, 'role' => 'admin']);
    $marketing = User::factory()->create(['institution_id' => $institution->id, 'role' => 'marketing']);
    $admissions = User::factory()->create(['institution_id' => $institution->id, 'role' => 'admissions']);

    expect(Gate::forUser($admin)->allows('delete', $lead))->toBeTrue();
    expect(Gate::forUser($marketing)->allows('delete', $lead))->toBeFalse();
    expect(Gate::forUser($admissions)->allows('delete', $lead))->toBeFalse();
});
