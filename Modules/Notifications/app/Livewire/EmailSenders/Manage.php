<?php

declare(strict_types=1);

namespace Modules\Notifications\Livewire\EmailSenders;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Modules\Notifications\Models\EmailSender;
use Modules\Notifications\Services\SenderMailer;

/**
 * Gestion de remitentes de correo saliente (Ajustes, solo Admin). CRUD + "Probar
 * envio". Aplica el patron de secretos del sistema: la contrasena SMTP se cifra en
 * reposo, jamas se re-muestra (solo enmascarada), y guardar con la contrasena vacia
 * CONSERVA la actual. Aislado por el scope global de institucion.
 */
#[Layout('layouts.app')]
class Manage extends Component
{
    public bool $showForm = false;

    public ?int $editingId = null;

    // --- Campos del formulario ---
    public string $name = '';

    public string $from_address = '';

    public string $host = '';

    public string $port = '465';

    public string $username = '';

    /** Secreto: se deja vacio; en blanco conserva la contrasena actual. */
    public string $password = '';

    public string $encryption = 'ssl';

    public string $status = 'active';

    // --- Probar envio ---
    public string $testTo = '';

    public function mount(): void
    {
        $this->authorize('viewAny', EmailSender::class);
    }

    /** @return Collection<int, EmailSender> */
    private function senders(): Collection
    {
        return EmailSender::query()->orderBy('name')->get();
    }

    public function newSender(): void
    {
        $this->authorize('create', EmailSender::class);
        $this->resetForm();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $sender = EmailSender::query()->findOrFail($id);
        $this->authorize('update', $sender);

        $this->editingId = $sender->getKey();
        $this->name = (string) $sender->name;
        $this->from_address = (string) $sender->from_address;
        $this->status = (string) $sender->status;
        // Metadatos NO secretos se prellenan; la contrasena NUNCA (queda vacia).
        $this->host = (string) ($sender->secret('host') ?? '');
        $this->port = (string) ($sender->secret('port') ?? '465');
        $this->username = (string) ($sender->secret('username') ?? '');
        $this->encryption = (string) ($sender->secret('encryption') ?? 'ssl');
        $this->password = '';

        $this->showForm = true;
    }

    public function save(): void
    {
        $creating = $this->editingId === null;
        $sender = $creating ? null : EmailSender::query()->findOrFail($this->editingId);

        $this->authorize($creating ? 'create' : 'update', $creating ? EmailSender::class : $sender);

        $domain = (string) config('crm.mail.sender_domain', 'mcaschool.education');
        $hasPassword = ! $creating && $sender?->secret('password') !== null;

        $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'from_address' => [
                'required', 'email', 'max:190',
                function (string $attr, mixed $value, callable $fail) use ($domain): void {
                    if (! Str::endsWith(Str::lower((string) $value), '@'.Str::lower($domain))) {
                        $fail("La dirección debe pertenecer al dominio @{$domain}.");
                    }
                    // Unicidad por institucion (el scope global la acota).
                    $exists = EmailSender::query()
                        ->where('from_address', $value)
                        ->when($this->editingId !== null, fn ($q) => $q->where('id', '!=', $this->editingId))
                        ->exists();
                    if ($exists) {
                        $fail('Ya existe un remitente con esa dirección.');
                    }
                },
            ],
            'host' => ['required', 'string', 'max:190'],
            'port' => ['required', 'integer', 'min:1', 'max:65535'],
            'username' => ['required', 'string', 'max:190'],
            'password' => [$hasPassword ? 'nullable' : 'required', 'string', 'max:500'],
            'encryption' => ['required', 'in:tls,ssl,none'],
            'status' => ['required', 'in:active,inactive'],
        ]);

        if ($sender === null) {
            $sender = new EmailSender;
        }
        $sender->fill([
            'name' => $this->name,
            'from_address' => Str::lower($this->from_address),
            'status' => $this->status,
        ]);
        $sender->save();

        // replaceSecrets: los vacios se ignoran (conservan el actual). La contrasena
        // en blanco al editar mantiene la existente; el resto (metadatos) se actualiza.
        $sender->replaceSecrets([
            'host' => $this->host,
            'port' => $this->port,
            'username' => $this->username,
            'password' => $this->password,
            'encryption' => $this->encryption,
        ]);
        $sender->save();

        $this->showForm = false;
        $this->resetForm();
        session()->flash('status', 'Remitente guardado.');
    }

    public function delete(int $id): void
    {
        $sender = EmailSender::query()->findOrFail($id);
        $this->authorize('delete', $sender);
        $sender->delete();
        session()->flash('status', 'Remitente eliminado.');
    }

    /** Envia un correo de prueba por el SMTP del remitente y sella el resultado. */
    public function test(int $id, SenderMailer $mailer): void
    {
        $sender = EmailSender::query()->findOrFail($id);
        $this->authorize('test', $sender);

        $this->validate(
            ['testTo' => ['required', 'email', 'max:190']],
            [],
            ['testTo' => 'correo de prueba'],
        );

        $ok = $mailer->sendTest($sender, $this->testTo);

        session()->flash('status', $ok
            ? "Correo de prueba enviado a {$this->testTo}."
            : 'No se pudo enviar la prueba. Revisa las credenciales SMTP y el mensaje del último intento.');
    }

    private function resetForm(): void
    {
        $this->reset(['editingId', 'name', 'from_address', 'host', 'username', 'password']);
        $this->port = '465';
        $this->encryption = 'ssl';
        $this->status = 'active';
        $this->resetErrorBag();
    }

    public function render(): View
    {
        return view('notifications::livewire.email-senders.manage', [
            'senders' => $this->senders(),
            'domain' => (string) config('crm.mail.sender_domain', 'mcaschool.education'),
        ]);
    }
}
