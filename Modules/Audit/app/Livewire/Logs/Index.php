<?php

declare(strict_types=1);

namespace Modules\Audit\Livewire\Logs;

use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Modules\Audit\Models\AuditLog;

/**
 * Auditoría de seguridad: vista de SOLO LECTURA para el Administrador. Lista los
 * eventos (orden cronológico inverso) con actor, acción, entidad, IP y fecha, con
 * filtros por tipo de acción, por actor y por rango de fechas, y paginación.
 *
 * SOLO LECTURA: no edita ni borra filas (la purga por retención la hará el cron del
 * Sub-bloque 4). El aislamiento por institución lo da el scope global de AuditLog.
 */
#[Layout('layouts.app')]
class Index extends Component
{
    use WithPagination;

    #[Url(as: 'accion')]
    public string $action = '';

    #[Url(as: 'actor')]
    public string $actor = '';

    #[Url(as: 'desde')]
    public string $from = '';

    #[Url(as: 'hasta')]
    public string $to = '';

    public function mount(): void
    {
        $this->authorize('viewAny', AuditLog::class);
    }

    public function updatedAction(): void
    {
        $this->resetPage();
    }

    public function updatedActor(): void
    {
        $this->resetPage();
    }

    public function updatedFrom(): void
    {
        $this->resetPage();
    }

    public function updatedTo(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset(['action', 'actor', 'from', 'to']);
        $this->resetPage();
    }

    /**
     * @return Builder<AuditLog>
     */
    private function baseQuery(): Builder
    {
        return AuditLog::query()
            ->with('user:id,name,email')
            ->when($this->action !== '', fn (Builder $q) => $q->where('action', $this->action))
            ->when($this->actor !== '', fn (Builder $q) => $q->where('user_id', (int) $this->actor))
            ->when($this->validDate($this->from), fn (Builder $q) => $q->where('created_at', '>=', Carbon::parse($this->from)->startOfDay()))
            ->when($this->validDate($this->to), fn (Builder $q) => $q->where('created_at', '<=', Carbon::parse($this->to)->endOfDay()))
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }

    private function validDate(string $value): bool
    {
        return $value !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1;
    }

    /**
     * Opciones para el filtro de acción: solo las acciones REALMENTE presentes.
     *
     * @return array<int, string>
     */
    private function actionOptions(): array
    {
        return AuditLog::query()->select('action')->distinct()->orderBy('action')->pluck('action')->all();
    }

    /**
     * Opciones para el filtro de actor: usuarios que aparecen como actor.
     *
     * @return \Illuminate\Support\Collection<int, User>
     */
    private function actorOptions()
    {
        $ids = AuditLog::query()->whereNotNull('user_id')->distinct()->pluck('user_id')->all();

        return User::query()->whereIn('id', $ids)->orderBy('name')->get(['id', 'name']);
    }

    public function render(): View
    {
        return view('audit::livewire.logs.index', [
            'logs' => $this->baseQuery()->paginate(25),
            'actionOptions' => $this->actionOptions(),
            'actorOptions' => $this->actorOptions(),
        ]);
    }
}
