<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Crm\Enums\InterestLevel;
use Modules\Crm\Enums\LeadStatus;
use Modules\Institutions\Models\Institution;
use Modules\Notifications\Support\AttachmentValidator;

/*
| Internacionalización integral (etapa 7): los mensajes que generan los componentes y servicios
| (avisos de guardado, errores de adjuntos, archivos del Publicador, reservas, confirmaciones) se
| presentan en el idioma activo. Los valores guardados y los enums no cambian: se traducen al
| presentarlos. Y ninguna clave __() del código queda sin traducción inglesa.
*/

afterEach(fn () => app()->setLocale('es'));

it('todas las claves __() del código y de las vistas tienen traducción inglesa', function () {
    $en = json_decode((string) file_get_contents(lang_path('en.json')), true);
    $dirs = array_merge([app_path(), resource_path('views')], glob(base_path('Modules/*/app')) ?: [], glob(base_path('Modules/*/resources/views')) ?: []);
    // Vista heredada de Breeze sin ruta (la ruta /dashboard es el Livewire de Inicio).
    $ignoredFiles = [realpath(resource_path('views/dashboard.blade.php'))];

    $missing = [];
    foreach ($dirs as $dir) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $file) {
            if (! str_ends_with($file->getFilename(), '.php') || in_array(realpath($file->getPathname()), $ignoredFiles, true)) {
                continue;
            }
            preg_match_all("~(?<![A-Za-z_])__\(\s*'((?:[^'\\\\]|\\\\.)*)'~u", (string) file_get_contents($file->getPathname()), $m);
            foreach ($m[1] as $key) {
                $key = str_replace(["\\'", '\\\\'], ["'", '\\'], $key);
                // Claves de los archivos PHP de idioma (validation.required, matcher.meta.…), que viven en lang/en/*.php.
                if ($key === '' || array_key_exists($key, $en) || preg_match('~^[a-z_]+\.[a-z0-9_.]*$~', $key)) {
                    continue;
                }
                // Claves de módulo (teamchat::chat.title): deben existir en el idioma inglés del módulo. Un prefijo
                // terminado en «.» es una clave dinámica (__('modulo::archivo.'.$x)) y no se puede resolver aquí.
                if (preg_match('~^[a-z_]+::[a-z_]+\.[a-z0-9_.]*$~', $key)) {
                    if (str_ends_with($key, '.') || \Illuminate\Support\Facades\Lang::has($key, 'en', false)) {
                        continue;
                    }
                }
                $missing[$key] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname());
            }
        }
    }

    expect($missing)->toBe([]);
});

it('los errores de adjuntos se presentan en español e inglés con el nombre y el límite', function () {
    config(['crm.mail.attachments.max_file_bytes' => 1024 * 1024, 'crm.mail.attachments.max_total_bytes' => 10 * 1024 * 1024]);
    $big = UploadedFile::fake()->create('informe.pdf', 2048, 'application/pdf');      // 2 MB > 1 MB
    $exe = UploadedFile::fake()->create('virus.exe', 1, 'application/x-msdownload');

    app()->setLocale('es');
    $es = app(AttachmentValidator::class)->validate([$big, $exe]);
    expect($es)->toContain('«informe.pdf» supera el máximo de 1 MB por archivo.')
        ->and(implode(' ', $es))->toContain('«virus.exe» no es un tipo de archivo permitido.');

    app()->setLocale('en');
    $en = app(AttachmentValidator::class)->validate([$big, $exe]);
    expect($en)->toContain('“informe.pdf” exceeds the 1 MB per-file limit.')
        ->and(implode(' ', $en))->toContain('“virus.exe” is not an allowed file type.');
});

it('estados e interés se traducen al presentarse sin cambiar los valores guardados', function () {
    expect(LeadStatus::Qualified->label())->toBe('En seguimiento')       // el enum no cambia
        ->and(InterestLevel::Medium->label())->toBe('Medio');

    app()->setLocale('en');
    expect(__(LeadStatus::Qualified->label()))->toBe('In follow-up')
        ->and(__(InterestLevel::Medium->label()))->toBe('Medium')
        ->and((string) $this->blade('<x-ui.lead-status :status="$s" />', ['s' => LeadStatus::Contacted]))->toContain('Contacted');
});

it('los avisos de los componentes salen en el idioma del usuario', function () {
    $institution = Institution::factory()->create();
    app(CurrentInstitution::class)->set($institution->id);
    $admin = User::factory()->create(['institution_id' => $institution->id, 'role' => 'admin', 'status' => 'active', 'preferred_language' => 'en']);

    app()->setLocale('en');
    Livewire::actingAs($admin)->test(\Modules\Identity\Livewire\Users\Form::class, ['user' => $admin])
        ->set('status', 'inactive')->call('save')
        ->assertHasErrors('status')->assertSee('You cannot deactivate your own account.');
});
