<?php

declare(strict_types=1);

namespace Modules\Crm\Livewire\Leads;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Modules\Crm\Enums\InterestLevel;
use Modules\Crm\Exceptions\InvalidContactDataException;
use Modules\Crm\Models\Lead;
use Modules\Crm\Services\ContactService;
use Modules\Crm\Services\EventService;
use Modules\Crm\Services\LeadService;
use Modules\Crm\Support\ContactDataNormalizer;
use Modules\Institutions\Models\Bot;

/**
 * Alta MANUAL de un lead desde el panel (p. ej. un prospecto que llega por otro
 * canal). Reutiliza ContactService (dedup por institution+email) y LeadService
 * (granularidad D4). La captura por el widget sigue siendo la via principal; esto
 * es un complemento operativo. Gating: LeadPolicy::create (Admin/Admisiones/Academico).
 */
#[Layout('layouts.app')]
class Create extends Component
{
    public string $first_name = '';

    public string $last_name = '';

    public string $email = '';

    public string $phone = '';

    public string $country = '';

    public string $preferred_language = 'es';

    public string $area = '';

    public string $goal = '';

    public string $interest_level = 'medium';

    public string $notes = '';

    public function mount(): void
    {
        $this->authorize('create', Lead::class);
    }

    /**
     * @return array<string, array<int, string>>
     */
    protected function rules(): array
    {
        return [
            // Longitudes = las del esquema (capa común); el formato lo remata ContactService.
            'first_name' => ['required', 'string', 'max:'.ContactDataNormalizer::MAX['first_name']],
            'last_name' => ['nullable', 'string', 'max:'.ContactDataNormalizer::MAX['last_name']],
            'email' => ['required', 'email', 'max:'.ContactDataNormalizer::MAX['email']],
            'phone' => ['nullable', 'string', 'max:'.ContactDataNormalizer::MAX['phone']],
            'country' => ['nullable', 'string', 'size:2'],
            'preferred_language' => ['required', 'in:es,en'],
            'area' => ['nullable', 'string', 'max:80'], // leads.area / leads.goal son varchar(80)
            'goal' => ['nullable', 'string', 'max:80'],
            'interest_level' => ['required', 'in:low,medium,high'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function save(ContactService $contacts, LeadService $leads, EventService $events): mixed
    {
        $this->authorize('create', Lead::class);
        $this->validate();

        // Asesor de origen: el bot de IA activo (Celia). Si no hay, no se puede crear.
        $bot = Bot::query()->where('type', 'ia')->where('status', 'active')->orderBy('id')->first();
        if ($bot === null) {
            $this->addError('email', __('No hay un asesor activo configurado para asignar el lead.'));

            return null;
        }

        // La capa común valida antes de escribir: lo que rechaza se muestra junto a su campo.
        try {
            $contact = $contacts->createOrUpdate([
                'first_name' => $this->first_name,
                'last_name' => $this->last_name,
                'email' => $this->email,
                'phone' => $this->phone,
                'country' => $this->country,
                'preferred_language' => $this->preferred_language,
            ]);
        } catch (InvalidContactDataException $e) {
            foreach ($e->errors as $field => $why) {
                $this->addError($field, $why);
            }

            return null;
        }

        $lead = $leads->recordIntent($contact, [
            'bot_id' => $bot->getKey(),
            'area' => $this->area,
            'goal' => $this->goal,
            'source' => 'manual',
            'interest_level' => $this->interest_level,
            'product_type' => 'microcredential',
        ]);

        if (trim($this->notes) !== '') {
            $lead->notes = trim($this->notes);
            $lead->save();
        }

        // Rastro: alta manual (quien la hizo).
        $actor = auth()->user();
        $events->record('lead_created', [
            'contact_id' => $contact->getKey(),
            'bot_id' => $bot->getKey(),
            'data' => ['source' => 'manual', 'by_name' => $actor?->name],
        ]);

        session()->flash('status', __('Lead creado.'));

        return $this->redirectRoute('crm.leads.show', $lead, navigate: false);
    }

    public function render(): View
    {
        return view('crm::livewire.leads.create', [
            'areas' => config('crm.microcredential_areas', []),
            'interestLevels' => InterestLevel::cases(),
        ]);
    }
}
