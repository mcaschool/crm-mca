<?php

declare(strict_types=1);

namespace Modules\Social\Livewire;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Modules\Social\Livewire\Concerns\ManagesSocialPosts;
use Modules\Social\Models\SocialPost;

/**
 * Calendario editorial (independiente del calendario de reuniones): vista mensual y agenda del
 * mes con las publicaciones programadas, en curso y ya publicadas, en la zona de quien mira.
 * Cada publicación muestra red, tipo, hora y estado y se abre para consultarla, editarla o
 * reprogramarla. Los borradores no tienen fecha y viven en Programadas.
 */
#[Layout('layouts.app')]
class PublisherCalendar extends Component
{
    use ManagesSocialPosts;

    /** Mes visible (Y-m). */
    #[Url(as: 'mes')]
    public string $month = '';

    #[Url(as: 'vista')]
    public string $mode = 'month';

    public function mount(): void
    {
        $this->authorizePublishing();
        if (! preg_match('/^\d{4}-\d{2}$/', $this->month)) {
            $this->month = now($this->viewerTz())->format('Y-m');
        }
        if (! in_array($this->mode, ['month', 'list'], true)) {
            $this->mode = 'month';
        }
    }

    public function setMode(string $mode): void
    {
        $this->mode = in_array($mode, ['month', 'list'], true) ? $mode : 'month';
    }

    public function previousMonth(): void
    {
        $this->month = $this->cursor()->subMonthNoOverflow()->format('Y-m');
    }

    public function nextMonth(): void
    {
        $this->month = $this->cursor()->addMonthNoOverflow()->format('Y-m');
    }

    public function thisMonth(): void
    {
        $this->month = now($this->viewerTz())->format('Y-m');
    }

    private function cursor(): Carbon
    {
        return Carbon::createFromFormat('Y-m-d', $this->month.'-01', $this->viewerTz())->startOfDay();
    }

    public function render(): View
    {
        $tz = $this->viewerTz();
        $cursor = $this->cursor();
        $gridStart = $cursor->copy()->startOfMonth()->startOfWeek(Carbon::MONDAY);
        $gridEnd = $cursor->copy()->endOfMonth()->endOfWeek(Carbon::SUNDAY);
        $ref = 'COALESCE(published_at, scheduled_at, created_at)';

        $posts = SocialPost::query()
            ->with('targets')
            ->where('status', '!=', SocialPost::DRAFT)
            ->whereRaw("$ref BETWEEN ? AND ?", [$gridStart->copy()->utc(), $gridEnd->copy()->utc()])
            ->orderByRaw("$ref ASC")
            ->get();

        $byDay = $posts->groupBy(fn (SocialPost $p) => $p->referenceAt()?->copy()->setTimezone($tz)->toDateString());
        $days = [];
        for ($d = $gridStart->copy(); $d->lte($gridEnd); $d->addDay()) {
            $days[] = $d->copy();
        }

        return view('social::publisher-calendar', [
            'cursor' => $cursor,
            'days' => $days,
            'byDay' => $byDay,
            'monthPosts' => $posts->filter(fn (SocialPost $p) => $p->referenceAt()?->copy()->setTimezone($tz)->format('Y-m') === $this->month),
            'todayLocal' => now($tz)->toDateString(),
            'detail' => $this->detailPost(),
            'tz' => $tz,
            'timezones' => \DateTimeZone::listIdentifiers(),
        ]);
    }
}
