<?php

declare(strict_types=1);

namespace Modules\Social\Livewire;

use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Modules\Social\Livewire\Concerns\ManagesSocialPosts;
use Modules\Social\Models\SocialChannel;
use Modules\Social\Models\SocialPost;
use Modules\Social\Services\SocialPublishService;

/**
 * Historial del Publicador: todas las publicaciones que el CRM tiene registradas (las anteriores
 * a la programación incluidas), con búsqueda, filtros por red, cuenta, fecha y estado, y el
 * resultado independiente de cada red. Solo lectura de lo almacenado: no inventa publicaciones
 * externas ni consulta a Meta para listar.
 */
#[Layout('layouts.app')]
class PublisherHistory extends Component
{
    use ManagesSocialPosts;
    use WithPagination;

    public const PER_PAGE = 15;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'red')]
    public string $network = '';

    #[Url(as: 'cuenta')]
    public string $channel = '';

    #[Url(as: 'estado')]
    public string $status = '';

    #[Url(as: 'desde')]
    public string $from = '';

    #[Url(as: 'hasta')]
    public string $to = '';

    public function mount(): void
    {
        $this->authorizePublishing();
    }

    public function updating(string $name): void
    {
        if (in_array($name, ['search', 'network', 'channel', 'status', 'from', 'to'], true)) {
            $this->resetPage();
        }
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'network', 'channel', 'status', 'from', 'to']);
        $this->resetPage();
    }

    /** @return Builder<SocialPost> */
    private function query(): Builder
    {
        $tz = $this->viewerTz();
        $term = trim($this->search);
        // Fecha de referencia: publicada → programada → creada (en la zona de quien mira).
        $ref = 'COALESCE(published_at, scheduled_at, created_at)';

        return SocialPost::query()
            ->with(['targets.channel', 'creator'])
            ->when($term !== '', fn (Builder $q) => $q->where('caption', 'like', '%'.addcslashes($term, '\\%_').'%'))
            ->when(in_array($this->network, ['facebook', 'instagram'], true), fn (Builder $q) => $q->where(fn (Builder $w) => $w
                ->whereJsonContains('networks', $this->network)
                ->orWhereHas('targets', fn (Builder $t) => $t->where('network', $this->network))))
            ->when(ctype_digit($this->channel), fn (Builder $q) => $q->whereHas('targets', fn (Builder $t) => $t->where('social_channel_id', (int) $this->channel)))
            ->when($this->status !== '', fn (Builder $q) => $this->status === SocialPost::PREPARING
                ? $q->whereIn('status', [SocialPost::PREPARING, SocialPost::PENDING])
                : $q->where('status', $this->status))
            ->when(preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->from) === 1, fn (Builder $q) => $q->whereRaw("$ref >= ?", [Carbon::parse($this->from, $tz)->startOfDay()->utc()]))
            ->when(preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->to) === 1, fn (Builder $q) => $q->whereRaw("$ref <= ?", [Carbon::parse($this->to, $tz)->endOfDay()->utc()]))
            ->orderByRaw("$ref DESC")
            ->orderByDesc('id');
    }

    public function render(): View
    {
        $channels = SocialChannel::query()
            ->whereIn('provider', array_values(SocialPublishService::NETWORK_PROVIDER))
            ->orderBy('provider')->orderBy('display_name')
            ->get(['id', 'provider', 'display_name']);

        return view('social::publisher-history', [
            'posts' => $this->query()->paginate(self::PER_PAGE),
            'channels' => $channels,
            'filtered' => trim($this->search) !== '' || $this->network !== '' || $this->channel !== '' || $this->status !== '' || $this->from !== '' || $this->to !== '',
            'statusOptions' => collect(SocialPost::STATUS_LABELS)->except(SocialPost::PENDING)->all(),
            'detail' => $this->detailPost(),
            'tz' => $this->viewerTz(),
            'timezones' => \DateTimeZone::listIdentifiers(),
        ]);
    }
}
