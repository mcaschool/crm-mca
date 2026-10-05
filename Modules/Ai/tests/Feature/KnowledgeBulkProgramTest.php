<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Modules\Ai\Livewire\Knowledge\Library;
use Modules\Ai\Models\KnowledgeSource;
use Modules\Ai\Services\KnowledgeSyncService;
use Modules\Catalog\Models\Program;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Institutions\Models\Institution;

/**
 * Carga masiva de conocimiento por línea (espacio Programa Académico): el programa de cada
 * archivo sale de «Programa: CÓDIGO» o, si falta, de la URL de su ficha; los que no se pueden
 * asignar se rechazan con motivo sin afectar a los demás.
 *
 * @return array{0: Institution, 1: User}
 */
function bulkCtx(): array
{
    $inst = Institution::factory()->create();
    app(CurrentInstitution::class)->set($inst->id);
    Storage::fake('knowledge');

    return [$inst, User::factory()->create(['institution_id' => $inst->id, 'role' => 'admin'])];
}

/** .md con Codigo y, opcionalmente, Programa en el comentario y contenido extra (URLs). */
function bulkMd(string $code, ?string $program = null, string $body = ''): UploadedFile
{
    $prog = $program !== null ? " · Programa: {$program}" : '';

    return UploadedFile::fake()->createWithContent(
        $code.'.md',
        "<!-- Codigo: {$code}{$prog} · Idioma: es · Categoria: diplomas_avanzados · Prioridad: 3 -->\n# Fuente {$code}\n\n## Resumen\nContenido.\n{$body}"
    );
}

/** @param  array<int, UploadedFile>  $files */
function bulkUpload(User $admin, array $files): \Livewire\Features\SupportTesting\Testable
{
    return Livewire::actingAs($admin)->test(Library::class)
        ->set('programLine', 'diplomas_avanzados')
        ->set('programAuto', true)
        ->set('programDocs', $files)
        ->call('uploadProgramDocs');
}

function bulkProgram(string $code, string $url, string $status = 'active'): Program
{
    return Program::factory()->create(['code' => $code, 'name_es' => 'Diploma '.$code, 'url' => $url, 'status' => $status, 'line' => 'diplomas_avanzados']);
}

it('sube varios archivos con campo Programa y asigna cada uno a su programa', function () {
    [, $admin] = bulkCtx();
    $p1 = bulkProgram('DA-001', 'https://mcaschool.education/es/programs/da-uno/');
    $p2 = bulkProgram('DA-002', 'https://mcaschool.education/es/programs/da-dos/');

    bulkUpload($admin, [bulkMd('KB-DA-UNO', 'DA-001'), bulkMd('KB-DA-DOS', 'DA-002')])
        ->assertHasNoErrors()
        ->assertSee('DA-001 · Diploma DA-001')
        ->assertSee('DA-002 · Diploma DA-002')
        ->assertDontSee('Rechazado');

    expect(KnowledgeSource::query()->where('code', 'KB-DA-UNO')->value('program_id'))->toBe($p1->id)
        ->and(KnowledgeSource::query()->where('code', 'KB-DA-DOS')->value('program_id'))->toBe($p2->id);
    $src = KnowledgeSource::query()->where('code', 'KB-DA-UNO')->firstOrFail();
    expect($src->type)->toBe('programa_academico')->and($src->category)->toBe('diplomas_avanzados');
});

it('sin campo Programa resuelve por la URL (http/https, mayúsculas y barra final no importan)', function () {
    [, $admin] = bulkCtx();
    $p = bulkProgram('PE-001', 'https://mcaschool.education/es/programs/Programa-Ejecutivo-Direccion/');

    bulkUpload($admin, [bulkMd('PE-DIR', null, 'Ficha: [ver](http://MCASCHOOL.education/es/programs/programa-ejecutivo-direccion). Admisiones: https://mcaschool.education/es/admisiones/')])
        ->assertHasNoErrors()
        ->assertSee('PE-001 · Diploma PE-001');

    expect(KnowledgeSource::query()->where('code', 'PE-DIR')->value('program_id'))->toBe($p->id);
});

it('rechaza con motivo (código inexistente, inactivo, sin coincidencia, ambiguo) sin afectar a los demás', function () {
    [, $admin] = bulkCtx();
    $ok = bulkProgram('DA-001', 'https://mcaschool.education/es/programs/da-uno/');
    bulkProgram('DA-003', 'https://mcaschool.education/es/programs/da-tres/', 'inactive');
    bulkProgram('DA-004', 'https://mcaschool.education/es/programs/da-cuatro/');
    bulkProgram('DA-005', 'https://mcaschool.education/es/programs/da-cinco/');

    bulkUpload($admin, [
        bulkMd('KB-OK', 'DA-001'),
        bulkMd('KB-NOEXISTE', 'DA-099'),
        bulkMd('KB-INACTIVO', 'DA-003'),
        bulkMd('KB-SINMATCH', null, 'Sin enlaces a fichas: https://mcaschool.education/es/admisiones/'),
        bulkMd('KB-AMBIGUO', null, 'https://mcaschool.education/es/programs/da-cuatro/ y https://mcaschool.education/es/programs/da-cinco/'),
    ])
        ->assertHasNoErrors()
        ->assertSee('Programa DA-099 no existe')
        ->assertSee('Programa DA-003 está inactivo')
        ->assertSee('No se encontró programa para este archivo')
        ->assertSee('Varios programas coinciden (DA-004, DA-005)')
        ->assertSee('1 nuevos · 0 actualizados · 4 rechazados');

    expect(KnowledgeSource::query()->pluck('code')->all())->toBe(['KB-OK'])
        ->and(KnowledgeSource::query()->value('program_id'))->toBe($ok->id);
});

it('nunca asigna un programa de otra institución (ni por código ni por URL)', function () {
    [$inst, $admin] = bulkCtx();
    $other = Institution::factory()->create();
    app(CurrentInstitution::class)->runFor($other->id, fn () => bulkProgram('DA-007', 'https://mcaschool.education/es/programs/da-siete/'));
    app(CurrentInstitution::class)->set($inst->id);

    bulkUpload($admin, [
        bulkMd('KB-OTRA-COD', 'DA-007'),
        bulkMd('KB-OTRA-URL', null, 'https://mcaschool.education/es/programs/da-siete/'),
    ])
        ->assertSee('Programa DA-007 no existe')
        ->assertSee('No se encontró programa para este archivo');

    expect(KnowledgeSource::query()->count())->toBe(0);
});

it('re-subir actualiza la misma fuente (upsert por Codigo), sin duplicar', function () {
    [, $admin] = bulkCtx();
    bulkProgram('DA-001', 'https://mcaschool.education/es/programs/da-uno/');
    $p2 = bulkProgram('DA-002', 'https://mcaschool.education/es/programs/da-dos/');

    bulkUpload($admin, [bulkMd('KB-DA-X', 'DA-001')]);
    bulkUpload($admin, [bulkMd('KB-DA-X', 'DA-002')])->assertSee('Actualizado');

    expect(KnowledgeSource::query()->where('code', 'KB-DA-X')->count())->toBe(1)
        ->and(KnowledgeSource::query()->where('code', 'KB-DA-X')->value('program_id'))->toBe($p2->id);
});

it('la subida manual con programa elegido sigue igual (y aplica ese programa a todos los archivos)', function () {
    [, $admin] = bulkCtx();
    $chosen = bulkProgram('DA-001', 'https://mcaschool.education/es/programs/da-uno/');
    bulkProgram('DA-002', 'https://mcaschool.education/es/programs/da-dos/');

    Livewire::actingAs($admin)->test(Library::class)
        ->assertSet('programAuto', false)
        ->set('programLine', 'diplomas_avanzados')
        ->set('programId', (string) $chosen->id)
        ->set('programDocs', [bulkMd('KB-M1', 'DA-002'), bulkMd('KB-M2')]) // el «Programa» del archivo se ignora
        ->call('uploadProgramDocs')
        ->assertHasNoErrors();

    expect(KnowledgeSource::query()->pluck('program_id')->unique()->values()->all())->toBe([$chosen->id]);
});

it('en modo automático no exige programa elegido; en manual sí', function () {
    [, $admin] = bulkCtx();
    bulkProgram('DA-001', 'https://mcaschool.education/es/programs/da-uno/');

    bulkUpload($admin, [bulkMd('KB-A', 'DA-001')])->assertHasNoErrors(['programId']);
    Livewire::actingAs($admin)->test(Library::class)
        ->set('programLine', 'diplomas_avanzados')->set('programDocs', [bulkMd('KB-B', 'DA-001')])
        ->call('uploadProgramDocs')->assertHasErrors(['programId' => 'required']);
});

it('el parser lee «Programa» y un campo extra no provoca rechazo', function () {
    bulkCtx();
    $info = app(KnowledgeSyncService::class)->inspect(
        "<!-- Codigo: KB-1 · Programa: DA-002 · Autor: Equipo · Idioma: es · Categoria: Diplomas Avanzados · Prioridad: 4 -->\n# T\n\n## S\nx",
        'kb-1.md'
    );

    expect($info['code'])->toBe('KB-1')
        ->and($info['program'])->toBe('DA-002')
        ->and($info['category'])->toBe('Diplomas Avanzados')
        ->and($info['priority'])->toBe(4)
        ->and($info['language'])->toBe('es')
        ->and($info['has_code_meta'])->toBeTrue();
});
