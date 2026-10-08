<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\Catalog\Models\Program;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Crm\Models\Contact;
use Modules\Crm\Models\Event;
use Modules\Crm\Models\Lead;
use Modules\Institutions\Models\Bot;
use Modules\Institutions\Models\Institution;
use Modules\Social\Models\MetaConnection;
use Modules\Social\Models\MetaLeadForm;
use Modules\Social\Models\MetaLeadPage;
use Modules\Social\Models\MetaLeadReceipt;
use Modules\Social\Services\MetaLeadPageService;

/**
 * «Prueba completa de recepción»: la limpieza del contacto de prueba en Meta es ESTRICTA y
 * VERIFICABLE. Solo se supera si Meta confirma el borrado, o si una lectura del MISMO id creado por
 * esta ejecución confirma que ya no existe (#100/33) y el mismo token sigue leyendo el formulario.
 * Permisos, token, red, límites, errores de Meta o respuestas ambiguas → fallida. Meta simulado.
 */
const RCT_TEST_ID = 'TEST_RCT_1';

/** @return array{0: Institution, 1: MetaLeadPage, 2: MetaLeadForm} */
function rctCtx(): array
{
    $inst = Institution::factory()->create();
    app(CurrentInstitution::class)->set($inst->id);
    $connection = MetaConnection::query()->create(['token' => 'USER_TOKEN_R', 'status' => 'active', 'connected_at' => now()]);
    $page = MetaLeadPage::query()->create([
        'meta_connection_id' => $connection->id, 'page_id' => 'page_r', 'name' => 'Academia R', 'page_token' => 'PAGE_TOKEN_R',
        'selected' => true, 'access_status' => 'verified', 'receiving_enabled' => false,
    ]);
    $program = Program::factory()->create(['code' => 'RC-1', 'line' => 'maestrias', 'status' => 'active', 'name_es' => 'Maestría R']);
    $form = MetaLeadForm::query()->create([
        'meta_lead_page_id' => $page->id, 'form_id' => 'form_r', 'name' => 'Formulario R', 'program_id' => $program->id,
        'destination' => 'program', 'bot_id' => Bot::factory()->create()->id, 'is_active' => true,
    ]);

    return [$inst, $page, $form];
}

/** Error de Graph con su forma real: {error: {message, type, code, error_subcode?, fbtrace_id}}. */
function rctError(int $code, ?int $subcode = null, string $type = 'OAuthException', int $status = 400): array
{
    return [array_filter(['error' => array_filter([
        'message' => 'mensaje de Meta (no se usa)', 'type' => $type, 'code' => $code, 'error_subcode' => $subcode, 'fbtrace_id' => 'Axyz',
    ], fn ($v) => $v !== null)]), $status];
}

const RCT_NOT_FOUND = 'not_found'; // atajo: #100/33 GraphMethodException 400

/**
 * Meta simulado. delete|verify|control: [cuerpo, estado] | 'network' | RCT_NOT_FOUND.
 * El contacto de prueba es el de Meta: correo de prueba y valores ficticios en el resto.
 *
 * @param  array<string, mixed>  $o
 */
function rctMeta(array $o = []): void
{
    app()->instance('rct.meta', $o + [
        'create' => [['id' => RCT_TEST_ID], 200],
        'lead' => [['id' => RCT_TEST_ID, 'platform' => 'fb', 'field_data' => [
            ['name' => 'full_name', 'values' => ['<test lead: dummy data for full_name>']],
            ['name' => 'email', 'values' => ['test@meta.com']],
            ['name' => 'phone_number', 'values' => ['<test lead: dummy data for phone_number>']],
        ]], 200],
        'delete' => [['success' => true], 200],
        'verify' => RCT_NOT_FOUND,
        'control' => [['id' => 'form_r'], 200],
    ]);
    if (app()->bound('rct.faked')) {
        return;
    }
    app()->instance('rct.faked', true);
    Http::fake(function (HttpRequest $r) {
        $m = app('rct.meta');
        $path = (string) parse_url($r->url(), PHP_URL_PATH);
        $fields = (string) ($r->data()['fields'] ?? '');
        $key = match (true) {
            $r->method() === 'POST' && str_ends_with($path, '/test_leads') => 'create',
            $r->method() === 'DELETE' => 'delete',
            str_ends_with($path, '/form_r') => 'control',
            $fields === 'id' => 'verify',
            default => 'lead',
        };
        $spec = $m[$key];
        if ($spec === 'network') {
            return (Http::failedConnection())($r);
        }
        if ($spec === RCT_NOT_FOUND) {
            $spec = rctError(100, 33, 'GraphMethodException');
        }

        return Http::response($spec[0], $spec[1]);
    });
}

/** @return array{status: string, detail: string, at: string, form: string, cleanup?: string} */
function rctRun(): array
{
    return app(MetaLeadPageService::class)->receptionTest(MetaLeadPage::query()->sole());
}

function rctNoLocalData(): void
{
    expect(Contact::query()->count())->toBe(0)
        ->and(Lead::query()->count())->toBe(0)
        ->and(MetaLeadReceipt::query()->count())->toBe(0)
        ->and(Event::query()->whereIn('event_type', ['meta_lead_received', 'lead_intake'])->count())->toBe(0)
        ->and(MetaLeadPage::query()->sole()->receiving_enabled)->toBeFalse();
}

function rctSent(string $method, string $suffix): int
{
    return Http::recorded(fn (HttpRequest $r) => $r->method() === $method && str_ends_with((string) parse_url($r->url(), PHP_URL_PATH), $suffix))->count();
}

// ───────────────────────────── Limpieza satisfactoria

it('1) 13) 15) 18) borrado con la respuesta oficial de éxito → superada, sin datos locales y con la recepción apagada', function () {
    rctCtx();
    rctMeta();

    $result = rctRun();

    expect($result)->toMatchArray(['status' => 'passed', 'cleanup' => 'deleted'])
        ->and($result['detail'])->toContain('Maestría R')->toContain('contacto de prueba eliminado en Meta')
        ->and(MetaLeadPage::query()->sole()->access_result['reception_test']['status'])->toBe('passed')
        ->and(rctSent('DELETE', '/'.RCT_TEST_ID))->toBe(1)
        ->and(rctSent('GET', '/form_r'))->toBe(0); // con confirmación explícita no hace falta comprobar
    rctNoLocalData();
});

it('1b) el «true» desnudo de versiones antiguas de Graph también es confirmación oficial', function () {
    rctCtx();
    rctMeta(['delete' => ['true', 200]]);

    expect(rctRun())->toMatchArray(['status' => 'passed', 'cleanup' => 'deleted']);
});

it('2) 3) 16) DELETE respondido con «no existe» (#100/33, la forma que antes se leía como fallo) → se comprueba el MISMO id y se supera como ya ausente', function () {
    rctCtx();
    rctMeta(['delete' => RCT_NOT_FOUND, 'verify' => RCT_NOT_FOUND]);
    Log::spy();

    $result = rctRun();

    expect($result)->toMatchArray(['status' => 'passed', 'cleanup' => 'absent'])
        // La comprobación lee exactamente el id creado por ESTA ejecución, y el control usa el formulario de la prueba.
        ->and(rctSent('GET', '/'.RCT_TEST_ID))->toBe(2) // lectura del contacto + comprobación tras borrar
        ->and(Http::recorded(fn (HttpRequest $r) => $r->method() === 'GET' && ($r->data()['fields'] ?? null) === 'id' && str_ends_with((string) parse_url($r->url(), PHP_URL_PATH), '/'.RCT_TEST_ID))->count())->toBe(1)
        ->and(rctSent('GET', '/form_r'))->toBe(1);
    rctNoLocalData();
    // Registro seguro: estado y códigos de Graph; ni token, ni id del contacto, ni mensaje de Meta.
    Log::shouldHaveReceived('info')->withArgs(function (string $msg, array $ctx = []): bool {
        $json = (string) json_encode($ctx);

        return str_contains($msg, 'ya ausente') && $ctx['delete'] === ['status' => 400, 'code' => 100, 'subcode' => 33, 'type' => 'GraphMethodException']
            && ! str_contains($json, RCT_TEST_ID) && ! str_contains($json, 'PAGE_TOKEN') && ! str_contains($json, 'mensaje de Meta');
    });
});

it('10) 2xx con cuerpo vacío no es una confirmación oficial: solo se supera si la comprobación confirma la ausencia', function () {
    rctCtx();
    rctMeta(['delete' => ['', 200]]);
    expect(rctRun())->toMatchArray(['status' => 'passed', 'cleanup' => 'absent']);

    // 11) vacío (o success:false) + comprobación ambigua → fallida.
    rctMeta(['delete' => ['', 204], 'verify' => rctError(200, null, 'OAuthException', 403)]);
    expect(rctRun())->toMatchArray(['status' => 'failed', 'cleanup' => 'unknown']);
    rctMeta(['delete' => [['success' => false], 200], 'verify' => [['other' => 'x'], 200]]);
    expect(rctRun())->toMatchArray(['status' => 'failed', 'cleanup' => 'unknown']);
});

// ───────────────────────────── Limpieza NO satisfactoria

it('4) 19) el contacto de prueba sigue existiendo tras el intento → fallida con un mensaje claro y seguro', function () {
    rctCtx();
    rctMeta(['delete' => rctError(200, null, 'OAuthException', 403), 'verify' => [['id' => RCT_TEST_ID], 200]]);

    $result = rctRun();

    expect($result)->toMatchArray(['status' => 'failed', 'cleanup' => 'present'])
        ->and($result['detail'])->toContain('Meta mantiene el contacto de prueba')
        ->and($result['detail'])->not->toContain(RCT_TEST_ID)->not->toContain('PAGE_TOKEN')
        ->and(MetaLeadPage::query()->sole()->access_result['reception_test']['status'])->toBe('failed');
    rctNoLocalData();
});

it('5) 6) 7) 8) 9) 12) 14) 17) permisos, token, red, límites, 5xx, respuestas no interpretables o errores genéricos NUNCA se toman por «ya eliminado»', function (array $meta, string $reasonText) {
    rctCtx();
    rctMeta($meta);

    $result = rctRun();

    expect($result['status'])->toBe('failed')
        ->and($result['cleanup'])->toBe('unknown')
        ->and($result['detail'])->toContain('no se pudo confirmar en Meta')->toContain($reasonText)
        ->and(MetaLeadPage::query()->sole()->access_result['reception_test']['status'])->toBe('failed');
    rctNoLocalData();
})->with([
    'permiso denegado al borrar y al comprobar' => [['delete' => rctError(200, null, 'OAuthException', 403), 'verify' => rctError(10, null, 'OAuthException', 403)], 'permiso'],
    'token vencido' => [['delete' => rctError(190, 463), 'verify' => rctError(190, 463)], 'caducó'],
    'token inválido' => [['delete' => rctError(190), 'verify' => rctError(190, null, 'OAuthException', 401)], 'caducó'],
    'red al borrar y al comprobar' => [['delete' => 'network', 'verify' => 'network'], 'red'],
    'límite de peticiones' => [['delete' => rctError(4, null, 'OAuthException', 400), 'verify' => rctError(613)], 'limitó'],
    'error 5xx de Meta' => [['delete' => [['error' => ['code' => 2, 'type' => 'OAuthException', 'message' => 'x']], 503], 'verify' => [['error' => ['code' => 1, 'message' => 'x']], 500]], 'error interno'],
    '5xx sin cuerpo' => [['delete' => ['', 502], 'verify' => ['<html>Bad gateway</html>', 502]], 'error interno'],
    'respuesta no interpretable' => [['delete' => ['<html>?</html>', 200], 'verify' => ['no-json', 200]], 'no reconocida'],
    'error genérico de Graph (#100 sin subcódigo 33)' => [['delete' => rctError(100), 'verify' => rctError(100, 2018001, 'GraphMethodException')], 'Meta respondió con un error'],
    '«no existe» pero el mismo token ya no lee el formulario (permisos)' => [['delete' => RCT_NOT_FOUND, 'verify' => RCT_NOT_FOUND, 'control' => rctError(10, null, 'OAuthException', 403)], 'permiso'],
    '«no existe» pero el control falla por red' => [['delete' => RCT_NOT_FOUND, 'verify' => RCT_NOT_FOUND, 'control' => 'network'], 'red'],
    '«no existe» con estado 5xx (no es el par exacto en 4xx)' => [['delete' => RCT_NOT_FOUND, 'verify' => [['error' => ['code' => 100, 'error_subcode' => 33, 'type' => 'GraphMethodException']], 500]], 'error interno'],
]);

it('16) un «no existe» de OTRO objeto no cuenta: el control debe devolver exactamente el formulario de la prueba', function () {
    rctCtx();
    rctMeta(['delete' => RCT_NOT_FOUND, 'verify' => RCT_NOT_FOUND, 'control' => [['id' => 'otro_formulario'], 200]]);

    expect(rctRun())->toMatchArray(['status' => 'failed', 'cleanup' => 'unknown']);
});

it('16b) si Meta devuelve un contacto distinto del creado por esta ejecución, la prueba falla antes de procesarlo', function () {
    rctCtx();
    rctMeta(['lead' => [['id' => 'OTRO_LEAD', 'field_data' => [['name' => 'email', 'values' => ['test@meta.com']]]], 200]]);

    expect(rctRun())->toMatchArray(['status' => 'failed'])
        ->and(rctSent('DELETE', '/'.RCT_TEST_ID))->toBe(1); // se limpia igualmente el que creó esta ejecución
    rctNoLocalData();
});

it('14) 20) una fase anterior fallida no deja datos y se sigue limpiando Meta; el teléfono ficticio sigue sin intentarse guardar', function () {
    rctCtx();
    // Correo real inválido: la capa común del CRM lo rechaza (falla la fase del CRM).
    rctMeta(['lead' => [['id' => RCT_TEST_ID, 'field_data' => [
        ['name' => 'email', 'values' => ['no-es-un-correo']],
        ['name' => 'phone_number', 'values' => ['<test lead: dummy data for phone_number>']],
    ]], 200]]);

    $failed = rctRun();
    expect($failed['status'])->toBe('failed')->and($failed['detail'])->toContain('email')
        ->and(rctSent('DELETE', '/'.RCT_TEST_ID))->toBe(1);
    rctNoLocalData();

    // 20) con el contacto de prueba real de Meta (teléfono ficticio, correo válido) la fase del CRM pasa.
    rctMeta();
    expect(rctRun()['status'])->toBe('passed');
    rctNoLocalData();
});

it('aislamiento: la prueba de una empresa no crea ni toca datos de otra', function () {
    $other = Institution::factory()->create();
    app(CurrentInstitution::class)->runFor($other->id, fn () => Contact::factory()->create(['email' => 'test@meta.com']));
    rctCtx();
    rctMeta();

    expect(rctRun()['status'])->toBe('passed');
    expect(app(CurrentInstitution::class)->runFor($other->id, fn () => Contact::query()->count()))->toBe(1);
    rctNoLocalData();
});
