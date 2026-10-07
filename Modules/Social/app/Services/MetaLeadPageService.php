<?php

declare(strict_types=1);

namespace Modules\Social\Services;

use DomainException;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Social\Models\MetaLeadForm;
use Modules\Social\Models\MetaLeadPage;
use Modules\Social\Support\MetaLeadAccessGuidance;

/**
 * Pasos de una Página en Formularios publicitarios (siempre en el contexto de SU empresa):
 * elegirla, transferirla, comprobar el acceso y activar, pausar o buscar contactos.
 *
 * «Comprobar acceso» distingue dos cosas que Meta autoriza por separado:
 *   - listar los formularios de la Página (para elegirlos);
 *   - leer los contactos de un formulario (lo que de verdad hace falta para recibir).
 * La recepción solo se activa si la Página responde y la LECTURA de contactos se verificó.
 */
final class MetaLeadPageService
{
    public function __construct(
        private readonly MetaLeadAccessCheck $check,
        private readonly MetaLeadFormService $forms,
        private readonly CurrentInstitution $tenancy,
    ) {}

    /** ¿Usa esta Página otra empresa ahora mismo? */
    public function takenElsewhere(MetaLeadPage $page): bool
    {
        return $this->tenancy->runGlobally(fn (): bool => MetaLeadPage::query()
            ->where('page_id', $page->page_id)
            ->where('institution_id', '!=', $page->institution_id)
            ->where('selected', true)
            ->exists());
    }

    /** Usar (o dejar de usar) la Página para formularios. Una Página solo la usa UNA empresa. */
    public function select(MetaLeadPage $page, bool $on): void
    {
        if ($on) {
            if (! $page->available) {
                throw new DomainException(__('Esta Página ya no está incluida en tu conexión con Meta. Pulsa «Reconectar Meta» y selecciónala al autorizar.'));
            }
            if ($this->takenElsewhere($page)) {
                throw new DomainException($page->fullControl()
                    ? __('Esta Página la usa otra empresa en el CRM. Como tienes control total de la Página en Meta, puedes pulsar «Transferir a mi empresa».')
                    : __('Esta Página la usa otra empresa en el CRM. Para transferirla, quien tenga control total de la Página en Meta debe conectar Meta desde tu empresa, o la otra empresa debe dejar de usarla.'));
            }
        }

        $page->forceFill(['selected' => $on, 'released_at' => null] + ($on ? [] : ['receiving_enabled' => false]))->save();
    }

    /**
     * Transferir a esta empresa una Página que usa otra. Solo si la persona que conectó Meta en
     * ESTA empresa tiene control total de la Página (lo dice Meta, no el CRM). La otra empresa deja
     * de recibir de inmediato y conserva sus contactos anteriores; ve el aviso en su panel.
     */
    public function transfer(MetaLeadPage $page): void
    {
        if (! $page->available || ! ($page->metaConnection?->usable() ?? false)) {
            throw new DomainException(__('Conecta Meta e incluye esta Página al autorizar antes de transferirla.'));
        }
        if (! $page->fullControl()) {
            throw new DomainException(__('Para transferir la Página, quien conecta Meta debe tener control total de ella en Meta. Pide a un administrador de la Página que conecte Meta desde tu empresa.'));
        }

        $this->tenancy->runGlobally(fn () => MetaLeadPage::query()
            ->where('page_id', $page->page_id)
            ->where('institution_id', '!=', $page->institution_id)
            ->where('selected', true)
            ->update(['selected' => false, 'receiving_enabled' => false, 'released_at' => now()]));

        $page->forceFill(['selected' => true, 'released_at' => null, 'access_status' => 'unchecked'])->save();
    }

    /**
     * «Comprobar acceso» (o «Probar con un contacto de prueba», que crea y borra un lead de prueba
     * de Meta). Guarda el resultado saneado y el estado: verified | failed | incomplete.
     */
    public function verify(MetaLeadPage $page, bool $testLead = false): MetaLeadPage
    {
        if (! ($page->metaConnection?->usable() ?? false)) {
            throw new DomainException(__('La conexión con Meta no está vigente. Pulsa «Reconectar Meta».'));
        }

        // Si ya conocemos un formulario de la Página, la lectura de contactos se prueba con él
        // aunque Meta no deje listar los formularios (son permisos distintos).
        $knownForm = MetaLeadForm::query()->where('meta_lead_page_id', $page->getKey())
            ->orderByDesc('is_active')->orderBy('id')->value('form_id');

        $result = $this->check->runForLeadPage($page, $knownForm !== null ? (string) $knownForm : null, $testLead);
        $steps = collect($result['steps'])->keyBy('step');
        $forms = $steps->get('forms');
        $formCount = ($forms['ok'] ?? false) ? count((array) ($forms['response']['data'] ?? [])) : null;
        $read = $steps->get($testLead ? 'test_lead_read' : 'form_leads') ?? ($testLead ? $steps->get('test_lead_create') : null);

        $checks = [
            'page' => ($steps->get('page')['ok'] ?? false) === true ? 'ok' : 'fail',
            'list_forms' => $forms === null ? 'na' : (($forms['ok'] ?? false) === true ? 'ok' : 'fail'),
            'read_contacts' => $read === null ? 'na' : (($read['ok'] ?? false) === true ? 'ok' : 'fail'),
        ];
        $issues = MetaLeadAccessGuidance::issues($result);

        $status = match (true) {
            $checks['page'] === 'ok' && $checks['read_contacts'] === 'ok' => 'verified',
            $checks['page'] === 'ok' && $checks['read_contacts'] === 'na' && $checks['list_forms'] === 'ok' && $formCount === 0 => 'incomplete',
            default => 'failed',
        };

        $page->forceFill([
            'access_status' => $status,
            'access_result' => [
                'checks' => $checks,
                'verdict' => collect($result['verdict'])->map(fn (array $v): string => $v['status'])->all(),
                'issues' => $issues,
                'forms' => $formCount,
                'leads_readable' => $checks['read_contacts'] === 'ok',
                'test_lead' => $testLead,
            ],
            'access_checked_at' => now(),
            // Una comprobación que ya no pasa detiene la recepción.
            'receiving_enabled' => $status === 'verified' ? $page->receiving_enabled : false,
            'last_error' => null,
        ])->save();

        if ($checks['list_forms'] === 'ok' && ($formCount ?? 0) > 0) {
            $this->forms->syncForms($page); // trae los formularios en la misma comprobación
        }

        return $page;
    }

    /** Activar / pausar la recepción de contactos de la Página. */
    public function setReceiving(MetaLeadPage $page, bool $on): void
    {
        if ($on) {
            if ($this->forms->killSwitch()) {
                throw new DomainException(__('La recepción de formularios está detenida temporalmente por la plataforma.'));
            }
            if (! $page->selected || ! ($page->metaConnection?->usable() ?? false)) {
                throw new DomainException(__('Conecta Meta y elige esta Página antes de activar la recepción.'));
            }
            if (! $page->verified()) {
                throw new DomainException(__('Antes de activar la recepción, «Comprobar acceso» debe confirmar que el CRM puede leer los contactos de esta Página.'));
            }
            $ready = MetaLeadForm::query()->where('meta_lead_page_id', $page->getKey())->where('is_active', true)->get()
                ->filter(fn (MetaLeadForm $f): bool => $f->hasDestination());
            if ($ready->isEmpty()) {
                throw new DomainException(__('Elige al menos un formulario de esta Página y asígnale un destino antes de activar la recepción.'));
            }
            // Los formularios elegidos reciben desde ahora (no se importa el histórico).
            MetaLeadForm::query()->whereKey($ready->modelKeys())->whereNull('receiving_since')->update(['receiving_since' => now()]);
        }

        $page->forceFill(['receiving_enabled' => $on])->save();
    }
}
