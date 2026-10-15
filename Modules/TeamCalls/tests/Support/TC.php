<?php

declare(strict_types=1);

namespace Modules\TeamCalls\Tests\Support;

use App\Models\User;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Institutions\Models\Institution;
use Modules\TeamCalls\Contracts\CallEventPublisher;
use Modules\TeamCalls\Contracts\ConversationAccess;
use Modules\TeamCalls\Contracts\MediaRoomService;
use Modules\TeamCalls\Contracts\Presence;
use Modules\TeamCalls\Contracts\PushNotifier;
use Modules\TeamCalls\Enums\CallMedia;
use Modules\TeamCalls\Models\Call;
use Modules\TeamCalls\Services\CallService;
use Modules\TeamCalls\Support\CallTarget;

/** Utilidades de las pruebas de TeamCalls (instituciones, usuarios y dobles de CollaborationCore). */
final class TC
{
    public static RecordingCollaboration $rec;

    public static FakeConversationAccess $conversations;

    /** Enlaza los dobles en el contenedor (sustituyen a los adaptadores provisionales). */
    public static function fakes(): void
    {
        self::$browsers = [];
        self::$browser = null;
        self::$rec = new RecordingCollaboration;
        self::$conversations = new FakeConversationAccess;
        foreach ([CallEventPublisher::class, MediaRoomService::class, Presence::class, PushNotifier::class] as $port) {
            app()->instance($port, self::$rec);
        }
        app()->instance(ConversationAccess::class, self::$conversations);
        app()->forgetInstance(CallService::class);
    }

    public static function institution(): Institution
    {
        $institution = Institution::factory()->create();
        app(CurrentInstitution::class)->set($institution->id);

        return $institution;
    }

    /** @param array<string,mixed> $over */
    public static function user(Institution $institution, array $over = []): User
    {
        return User::factory()->create(array_merge([
            'institution_id' => $institution->id, 'role' => 'admissions', 'department' => 'Admisiones', 'status' => 'active',
        ], $over));
    }

    /** Atributos de un usuario SIN segundo factor (la fábrica los crea con el 2FA activado). */
    public const NO_2FA = ['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null];

    /** @var array<string, array<string,mixed>> sesiones guardadas por navegador */
    private static array $browsers = [];

    private static ?string $browser = null;

    /**
     * Cambia de navegador (= de sesión y, por tanto, de dispositivo para la API web). Cada nombre
     * conserva su propia sesión: volver a «beto-pc» recupera el mismo dispositivo.
     */
    public static function browser(string $name): void
    {
        $store = app('session')->driver();
        if (self::$browser !== null) {
            self::$browsers[self::$browser] = $store->all();
        }
        $store->flush();
        $store->replace(self::$browsers[$name] ?? []);
        self::$browser = $name;
    }

    public static function service(): CallService
    {
        return app(CallService::class);
    }

    /** @param list<int> $to */
    public static function call(User $from, array $to, string $key = 'key-0001', CallMedia $media = CallMedia::Video): Call
    {
        return self::service()->start($from, CallTarget::users($to), $media, $key, 'pc-'.$from->id);
    }

    public static function fresh(Call $call): Call
    {
        return Call::query()->with('participants')->findOrFail($call->id);
    }

    /** Estado de cada participante: [user_id => state]. @return array<int,string> */
    public static function states(Call $call): array
    {
        return self::fresh($call)->participants->mapWithKeys(fn ($p) => [$p->user_id => $p->state->value])->all();
    }
}
