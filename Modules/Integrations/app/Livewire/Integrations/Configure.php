<?php

declare(strict_types=1);

namespace Modules\Integrations\Livewire\Integrations;

use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Modules\Integrations\Models\Integration;
use Modules\Integrations\Support\IntegrationCatalog;

/**
 * Configuracion de una integracion (un tipo). Aplica las 7 barreras de secretos:
 *  - los inputs de campos SECRETOS empiezan SIEMPRE vacios (nunca value= con el
 *    secreto); solo se muestra el enmascarado del valor actual como referencia,
 *  - guardar con un secreto vacio CONSERVA el actual (replaceSecrets),
 *  - el secreto se cifra en reposo (encrypted:array) y nunca se re-muestra completo.
 */
#[Layout('layouts.app')]
class Configure extends Component
{
    public string $type = '';

    public string $name = '';

    public ?string $provider = null;

    /** @var array<string, string> Valores de entrada; los secretos van vacios. */
    public array $inputs = [];

    private ?Integration $integration = null;

    public function mount(string $type): void
    {
        abort_unless(IntegrationCatalog::exists($type), 404);
        $this->authorize('create', Integration::class);

        $this->type = $type;
        $meta = IntegrationCatalog::for($type);
        $this->name = $meta['label'];

        // Integracion existente de esta institucion (scope global -> auto-acotada).
        $this->integration = Integration::query()->where('type', $type)->first();

        if ($this->integration !== null) {
            $this->name = $this->integration->name;
            $this->provider = $this->integration->provider;

            // SOLO se prellenan los campos NO secretos (metadatos). Los secretos
            // quedan vacios: se muestran enmascarados, nunca en un input.
            foreach ($meta['fields'] as $field) {
                if (! $field['secret']) {
                    $this->inputs[$field['key']] = (string) ($this->integration->secret($field['key']) ?? '');
                }
            }
        }

        if ($this->provider === null && $meta['providers'] !== []) {
            $this->provider = $meta['providers'][0];
        }
    }

    public function save(): mixed
    {
        $meta = IntegrationCatalog::for($this->type);
        abort_if($meta === null, 404);

        $integration = $this->integration ?? Integration::query()->where('type', $this->type)->first();
        $creating = $integration === null;

        $this->authorize($creating ? 'create' : 'update', $creating ? Integration::class : $integration);

        $rules = ['name' => ['required', 'string', 'max:120']];
        if ($meta['providers'] !== []) {
            $rules['provider'] = ['required', Rule::in($meta['providers'])];
        }

        foreach ($meta['fields'] as $field) {
            $key = 'inputs.'.$field['key'];
            if ($field['secret']) {
                // Secreto: obligatorio solo al crear (o si aun no existe). Al editar,
                // vacio = conservar el actual.
                $hasExisting = ! $creating && $integration->secret($field['key']) !== null;
                $rules[$key] = ($field['required'] && ! $hasExisting) ? ['required', 'string'] : ['nullable', 'string'];
            } else {
                $rules[$key] = $field['required'] ? ['required', 'string'] : ['nullable', 'string'];
            }
        }

        $this->validate($rules);

        if ($creating) {
            $integration = new Integration;
            $integration->type = $this->type;
        }

        $integration->name = $this->name;
        $integration->provider = $meta['providers'] !== [] ? $this->provider : null;

        // replaceSecrets: los vacios se ignoran (conservan el actual). Guarda tanto
        // secretos como metadatos dentro de config (todo cifrado en reposo).
        $integration->replaceSecrets($this->inputs);
        $integration->save();

        session()->flash('status', __('Integracion guardada.'));

        return redirect()->route('integrations.index');
    }

    public function render(): View
    {
        $meta = IntegrationCatalog::for($this->type);

        return view('integrations::livewire.integrations.configure', [
            'meta' => $meta,
            'maskedPreview' => $this->integration?->maskedConfig() ?? [],
            'editing' => $this->integration !== null,
        ]);
    }
}
