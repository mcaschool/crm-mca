<?php

declare(strict_types=1);

namespace Modules\Social\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;
use Modules\Social\Models\SocialChannel;
use Modules\Social\Models\SocialWhatsAppTemplate;
use Modules\Social\Services\WhatsAppTemplateMediaService;
use Modules\Social\Services\WhatsAppTemplateService;
use Modules\Social\Services\WhatsAppTemplateValidator;
use RuntimeException;

/**
 * Plantillas de WhatsApp: listado con búsqueda/filtros/sync, diseñador visual con vista
 * previa en vivo y detalle con motivo de rechazo. Acceso Admin (misma policy que
 * Canales). Toda operación va contra el canal whatsapp elegido (WABA en credentials).
 */
#[Layout('layouts.app')]
class WhatsAppTemplates extends Component
{
    use WithFileUploads;

    /** Vista activa: list | create | detail. */
    public string $view = 'list';

    public ?int $channelId = null;

    public string $search = '';

    /** all | approved | review | rejected | paused | archived */
    public string $filter = 'all';

    public ?int $detailId = null;

    public ?string $flash = null;

    public ?string $flashError = null;

    // ------------------------- estado del diseñador -------------------------

    public string $name = '';

    public string $category = 'UTILITY';

    public string $language = 'es';

    /** '' (sin header) | TEXT | IMAGE | VIDEO | DOCUMENT */
    public string $headerFormat = '';

    public string $headerText = '';

    public string $body = '';

    public string $footer = '';

    /** @var array<int, array{type: string, text: string, url: string, phone: string}> */
    public array $buttons = [];

    /** @var array<int, string> Ejemplo obligatorio por variable {{n}}. */
    public array $examples = [];

    /**
     * Archivo de ejemplo para HEADER de media (se sube a la Uploads API al enviar).
     *
     * @var \Livewire\Features\SupportFileUploads\TemporaryUploadedFile|null
     */
    public $headerFile = null;

    /** @var array<int, string> */
    public array $builderErrors = [];

    /** Filtro visual → estados de Meta (un estado futuro desconocido cae en "Todas"). */
    private const FILTERS = [
        'approved' => ['APPROVED'],
        'review' => ['PENDING', 'IN_APPEAL'],
        'rejected' => ['REJECTED'],
        'paused' => ['PAUSED', 'DISABLED', 'FLAGGED', 'LOCKED', 'LIMIT_EXCEEDED'],
        'archived' => ['ARCHIVED', 'DELETED', 'PENDING_DELETION'],
    ];

    public function mount(): void
    {
        $this->authorize('viewAny', SocialChannel::class);
        $this->channelId = SocialChannel::query()->where('provider', 'whatsapp')->value('id');
    }

    public function updatedChannelId(): void
    {
        $this->view = 'list';
        $this->detailId = null;
    }

    public function sync(WhatsAppTemplateService $templates): void
    {
        $this->flash = null;
        $this->flashError = null;
        $channel = $this->channel();
        if ($channel === null) {
            return;
        }

        try {
            $result = $templates->sync($channel);
            $this->flash = __(':n plantillas sincronizadas con Meta.', ['n' => $result['synced']]);
        } catch (RuntimeException $e) {
            $this->flashError = $e->getMessage();
        }
    }

    public function startCreate(): void
    {
        $this->authorize('create', SocialChannel::class);
        $this->reset('name', 'headerFormat', 'headerText', 'body', 'footer', 'buttons', 'examples', 'headerFile', 'builderErrors');
        $this->category = 'UTILITY';
        $this->language = 'es';
        $this->view = 'create';
        $this->flash = null;
        $this->flashError = null;
    }

    public function addButton(string $type): void
    {
        if (count($this->buttons) >= WhatsAppTemplateValidator::MAX_BUTTONS) {
            return;
        }
        $this->buttons[] = ['type' => strtoupper($type), 'text' => '', 'url' => '', 'phone' => ''];
    }

    public function removeButton(int $index): void
    {
        unset($this->buttons[$index]);
        $this->buttons = array_values($this->buttons);
    }

    /**
     * Variables {{n}} detectadas en vivo (body + header de texto).
     *
     * @return array<int, int>
     */
    public function detectedVariables(): array
    {
        $validator = new WhatsAppTemplateValidator;
        $text = $this->body.($this->headerFormat === 'TEXT' ? ' '.$this->headerText : '');

        return $validator->variables($text);
    }

    public function submit(WhatsAppTemplateValidator $validator, WhatsAppTemplateService $templates, WhatsAppTemplateMediaService $media): void
    {
        $this->authorize('create', SocialChannel::class);
        $this->builderErrors = [];
        $channel = $this->channel();
        if ($channel === null) {
            $this->builderErrors = [__('Configura primero un canal de WhatsApp.')];

            return;
        }

        // Unicidad local por canal + nombre + idioma (Meta también la exige).
        $duplicate = SocialWhatsAppTemplate::query()
            ->where('social_channel_id', $channel->id)
            ->where('name', $this->name)
            ->where('language', $this->language)
            ->exists();
        if ($duplicate) {
            $this->builderErrors = [__('Ya existe una plantilla con ese nombre e idioma en este canal.')];

            return;
        }

        $input = $this->builderInput();

        // HEADER de media: subir el archivo de EJEMPLO (Uploads API → handle) antes de validar.
        if (in_array($this->headerFormat, ['IMAGE', 'VIDEO', 'DOCUMENT'], true)) {
            if ($this->headerFile === null) {
                $this->builderErrors = [__('Sube un archivo de ejemplo para el encabezado: Meta lo exige para revisar la plantilla.')];

                return;
            }
            try {
                $input['headerExample'] = $media->uploadExample($channel, $this->headerFile, $this->headerFormat);
            } catch (RuntimeException $e) {
                $this->builderErrors = [$e->getMessage()];

                return;
            }
        }

        $errors = $validator->validate($input);
        if ($errors !== []) {
            $this->builderErrors = $errors;

            return;
        }

        try {
            $template = $templates->create($channel, $input);
        } catch (RuntimeException $e) {
            $this->builderErrors = [$e->getMessage()];

            return;
        }

        $this->flash = __('Plantilla enviada a revisión de WhatsApp. Estado inicial: :status.', ['status' => $template->status]);
        $this->view = 'list';
    }

    public function show(int $id): void
    {
        $this->detailId = $id;
        $this->view = 'detail';
        $this->flash = null;
        $this->flashError = null;
    }

    public function refreshTemplate(WhatsAppTemplateService $templates): void
    {
        $template = $this->detailTemplate();
        if ($template === null) {
            return;
        }
        try {
            $templates->refresh($template);
            $this->flash = __('Plantilla actualizada desde Meta.');
        } catch (RuntimeException $e) {
            $this->flashError = $e->getMessage();
        }
    }

    public function back(): void
    {
        $this->view = 'list';
        $this->detailId = null;
    }

    public function render(): View
    {
        $channels = SocialChannel::query()->where('provider', 'whatsapp')->orderBy('display_name')->get();
        $channel = $this->channel();

        $templates = collect();
        if ($channel !== null && $this->view === 'list') {
            $query = SocialWhatsAppTemplate::query()
                ->where('social_channel_id', $channel->id)
                ->orderBy('name')
                ->orderBy('language');
            if (trim($this->search) !== '') {
                $query->where('name', 'like', '%'.trim($this->search).'%');
            }
            if (isset(self::FILTERS[$this->filter])) {
                $query->whereIn('status', self::FILTERS[$this->filter]);
            }
            $templates = $query->get();
        }

        return view('social::whatsapp-templates', [
            'channels' => $channels,
            'channel' => $channel,
            'templates' => $templates,
            'detail' => $this->view === 'detail' ? $this->detailTemplate() : null,
            'variables' => $this->view === 'create' ? $this->detectedVariables() : [],
        ]);
    }

    private function channel(): ?SocialChannel
    {
        return $this->channelId !== null
            ? SocialChannel::query()->where('provider', 'whatsapp')->find($this->channelId)
            : null;
    }

    private function detailTemplate(): ?SocialWhatsAppTemplate
    {
        return $this->detailId !== null
            ? SocialWhatsAppTemplate::query()->find($this->detailId)
            : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function builderInput(): array
    {
        return [
            'name' => trim($this->name),
            'language' => trim($this->language),
            'category' => $this->category,
            'headerFormat' => $this->headerFormat,
            'headerText' => trim($this->headerText),
            'body' => trim($this->body),
            'footer' => trim($this->footer),
            'buttons' => $this->buttons,
            'examples' => $this->examples,
            'headerExample' => '',
        ];
    }
}
