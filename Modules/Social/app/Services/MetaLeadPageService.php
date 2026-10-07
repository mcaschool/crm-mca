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
 * elegirla, comprobar el acceso y activar o parar la recepción de contactos.
 *
 * La recepción solo se activa con una comprobación VERIFICADA: la Página responde, se pueden
 * listar sus formularios y Meta permitió leer sus contactos (o un contacto de prueba).
 */
final class MetaLeadPageService
{
    public function __construct(
        private readonly MetaLeadAccessCheck $check,
        private readonly MetaLeadFormService $forms,
        private readonly CurrentInstitution $tenancy,
    ) {}

    /** Usar (o dejar de usar) la Página para formularios. Una Página solo la usa UNA empresa. */
    public function select(MetaLeadPage $page, bool $on): void
    {
        if ($on) {
            if (! $page->available) {
                throw new DomainException(__('Esta Página ya no está incluida en tu conexión con Meta. Pulsa «Reconectar Meta» y selecciónala al autorizar.'));
            }
            $takenElsewhere = $this->tenancy->runGlobally(fn (): bool => MetaLeadPage::query()
                ->where('page_id', $page->page_id)
                ->where('institution_id', '!=', $page->institution_id)
                ->where('selected', true)
                ->exists());
            if ($takenElsewhere) {
                throw new DomainException(__('Esta Página ya la usa otra empresa en el CRM. Una Página solo puede enviar sus contactos a una empresa.'));
            }
        }

        $page->forceFill(['selected' => $on] + ($on ? [] : ['receiving_enabled' => false]))->save();
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

        $result = $this->check->runForLeadPage($page, null, $testLead);
        $steps = collect($result['steps'])->keyBy('step');
        $forms = $steps->get('forms');
        $formCount = ($forms['ok'] ?? false) ? count((array) ($forms['response']['data'] ?? [])) : null;
        $leadsReadable = ($steps->get('form_leads')['ok'] ?? false) === true || ($steps->get('test_lead_read')['ok'] ?? false) === true;
        $issues = MetaLeadAccessGuidance::issues($result);

        $status = match (true) {
            ($steps->get('page')['ok'] ?? false) === true && ($forms['ok'] ?? false) === true && $leadsReadable && $issues === [] => 'verified',
            $issues === [] && $formCount === 0 => 'incomplete', // sin formularios no se puede probar la lectura
            default => 'failed',
        };

        $page->forceFill([
            'access_status' => $status,
            'access_result' => [
                'verdict' => collect($result['verdict'])->map(fn (array $v): string => $v['status'])->all(),
                'issues' => $issues,
                'forms' => $formCount,
                'leads_readable' => $leadsReadable,
                'test_lead' => $testLead,
            ],
            'access_checked_at' => now(),
            // Una comprobación que ya no pasa detiene la recepción.
            'receiving_enabled' => $status === 'verified' ? $page->receiving_enabled : false,
            'last_error' => null,
        ])->save();

        if ($status === 'verified' && ($formCount ?? 0) > 0) {
            $this->forms->syncForms($page); // trae los formularios en la misma comprobación
        }

        return $page;
    }

    /** Activar / parar la recepción de contactos de la Página. */
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
            // Los formularios ya activos reciben desde ahora (no se importa el histórico).
            MetaLeadForm::query()->where('meta_lead_page_id', $page->getKey())->where('is_active', true)
                ->whereNull('receiving_since')->update(['receiving_since' => now()]);
        }

        $page->forceFill(['receiving_enabled' => $on])->save();
    }
}
