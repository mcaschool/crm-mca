<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Crm\Models\MeetingProfile;
use Modules\Crm\Support\UserTimezone;
use Modules\Institutions\Models\Institution;
use Modules\Social\Livewire\Inbox;
use Modules\Social\Models\SocialChannel;
use Modules\Social\Models\SocialConversation;
use Modules\Social\Models\SocialMessage;

/*
| Bandeja social: las fechas se guardan en UTC y se PRESENTAN en la zona de quien mira (perfil de
| reuniones → institución → agenda → aplicación), con cambio de día y horario de verano. Nada se
| reescribe en la base de datos y nada sale hacia Meta.
*/

beforeEach(function () {
    Http::fake();
});

/** @return array{0:User,1:SocialConversation} */
function tzInbox(string $institutionTz, ?string $profileTz, array $messagesUtc): array
{
    $institution = Institution::factory()->create(['timezone' => $institutionTz]);
    app(CurrentInstitution::class)->set($institution->id);
    $user = User::factory()->create(['institution_id' => $institution->id, 'role' => 'admin', 'status' => 'active']);
    if ($profileTz !== null) {
        MeetingProfile::query()->create(['user_id' => $user->id, 'timezone' => $profileTz]);
    }
    $channel = SocialChannel::factory()->create(['provider' => 'whatsapp', 'display_name' => 'Admisiones', 'external_id' => 'WA_TZ', 'credentials' => ['token' => 'X']]);
    $conv = SocialConversation::factory()->create(['social_channel_id' => $channel->id, 'provider' => 'whatsapp', 'contact_name' => 'Persona',
        'external_conversation_id' => 'WA_TZ_C', 'contact_external_id' => 'WA_TZ_C', 'unread_count' => 0,
        'created_at' => Carbon::parse($messagesUtc[0], 'UTC'), 'last_message_at' => Carbon::parse(end($messagesUtc), 'UTC')]);
    foreach ($messagesUtc as $i => $at) {
        SocialMessage::query()->create(['social_conversation_id' => $conv->id, 'external_message_id' => 'tz'.$i, 'direction' => 'inbound', 'type' => 'text',
            'body' => 'Mensaje '.$i, 'status' => 'received', 'sender_type' => 'contact', 'provider_timestamp' => Carbon::parse($at, 'UTC')]);
    }

    return [$user, $conv];
}

it('presenta las horas en la zona del perfil del usuario y cambia de día al cruzar la medianoche local', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-20 12:00:00', 'UTC'));
    // 03:30 UTC del 15/10 = 22:30 del 14/10 en Bogotá (UTC−5).
    [$user, $conv] = tzInbox('America/New_York', 'America/Bogota', ['2026-10-15 03:30:00', '2026-10-15 15:10:00']);

    $html = Livewire::actingAs($user)->test(Inbox::class)->call('select', $conv->id)->html();

    expect($html)->toContain('>22:30</time>')->toContain('>10:10</time>')
        ->toContain('Miércoles 14 de octubre')->toContain('Jueves 15 de octubre')
        ->toContain('14/10/2026 22:30')        // Primer contacto (created_at)
        ->toContain('15/10/2026 10:10')        // Último mensaje
        ->not->toContain('>03:30</time>');

    // Lo guardado sigue en UTC.
    expect((string) DB::table('social_messages')->where('external_message_id', 'tz0')->value('provider_timestamp'))->toStartWith('2026-10-15 03:30:00');
    Carbon::setTestNow();
});

it('sin perfil usa la zona de la institución y respeta el horario de verano', function () {
    Carbon::setTestNow(Carbon::parse('2026-03-20 12:00:00', 'UTC'));
    // Nueva York pasa a EDT el 08/03/2026 a las 07:00 UTC: 06:30 UTC = 01:30 EST; 07:30 UTC = 03:30 EDT.
    [$user, $conv] = tzInbox('America/New_York', null, ['2026-03-08 06:30:00', '2026-03-08 07:30:00']);

    $html = Livewire::actingAs($user)->test(Inbox::class)->call('select', $conv->id)->html();

    expect($html)->toContain('>01:30</time>')->toContain('>03:30</time>')->toContain('America/New_York');
    Carbon::setTestNow();
});

it('ignora zonas no válidas y resuelve en orden perfil → institución → agenda → aplicación', function () {
    $institution = Institution::factory()->create(['timezone' => 'Europe/Madrid']);
    app(CurrentInstitution::class)->set($institution->id);
    $user = User::factory()->create(['institution_id' => $institution->id]);

    expect(UserTimezone::for($user))->toBe('Europe/Madrid');

    MeetingProfile::query()->create(['user_id' => $user->id, 'timezone' => 'Marte/Olympus']);
    expect(UserTimezone::for($user))->toBe('Europe/Madrid');

    MeetingProfile::query()->where('user_id', $user->id)->update(['timezone' => 'America/Mexico_City']);
    expect(UserTimezone::for($user))->toBe('America/Mexico_City');

    $institution->forceFill(['timezone' => 'zona-invalida'])->save();
    MeetingProfile::query()->where('user_id', $user->id)->delete();
    config(['crm.agenda.default_timezone' => 'America/Lima']);
    expect(UserTimezone::for($user->fresh()))->toBe('America/Lima');
    expect(UserTimezone::for(null))->toBe('America/Lima');
});

it('la macro userTz devuelve una copia en la zona de quien mira sin modificar la fecha original', function () {
    $institution = Institution::factory()->create(['timezone' => 'America/Bogota']);
    app(CurrentInstitution::class)->set($institution->id);
    $this->actingAs(User::factory()->create(['institution_id' => $institution->id]));

    $utc = Carbon::parse('2026-10-15 03:30:00', 'UTC');
    $local = $utc->userTz();

    expect($local->format('Y-m-d H:i e'))->toBe('2026-10-14 22:30 America/Bogota')
        ->and($utc->format('Y-m-d H:i e'))->toBe('2026-10-15 03:30 UTC');
});
