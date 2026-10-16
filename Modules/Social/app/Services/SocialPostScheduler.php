<?php

declare(strict_types=1);

namespace Modules\Social\Services;

use Illuminate\Support\Str;
use Modules\Audit\Services\AuditService;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Social\Models\SocialPost;
use Modules\Social\Models\SocialPostTarget;

/**
 * Programación automática del Publicador, sin servicios externos ni procesos permanentes: el
 * cron de Hostinger ejecuta `schedule:run` cada minuto y este servicio publica lo que ya venció.
 *
 * Garantías contra publicaciones duplicadas:
 *  1. RECLAMO ATÓMICO: una publicación pasa de scheduled (o draft) a preparing con un UPDATE
 *     condicionado al estado; solo una ejecución lo consigue y queda su run_id.
 *  2. La ejecución verifica su run_id antes de llamar a Meta (nadie más la tomó).
 *  3. Por red, SocialPublishService nunca reenvía una red ya publicada o en proceso.
 *  4. Una ejecución interrumpida (preparing > 15 min) NO se reintenta sola: sus redes sin
 *     resultado quedan «fallidas» con aviso de comprobar la red antes de reintentar.
 * Una publicación cancelada nunca se ejecuta: el reclamo exige el estado «scheduled».
 */
final class SocialPostScheduler
{
    // Resolve tenancy at use time: a retained service may outlive a worker's scoped container.

    /** Minutos tras los que una ejecución en «preparing» se considera interrumpida. */
    public const STALE_MINUTES = 15;

    /** Espera mínima entre consultas de un contenedor de Instagram en proceso. */
    private const RESUME_EVERY_SECONDS = 60;

    public function __construct(
        private readonly SocialPublishService $publisher,
        private readonly AuditService $audit,
    ) {}

    /**
     * Reclama una publicación para ejecutarla. Devuelve el run_id o null si otra ejecución la
     * tomó, se canceló o (para scheduled) aún no venció.
     *
     * @param  array<int, string>  $from  estados de origen admitidos
     */
    public function claim(SocialPost $post, array $from, bool $requireDue): ?string
    {
        $runId = (string) Str::uuid();
        $claimed = SocialPost::query()
            ->whereKey($post->getKey())
            ->whereIn('status', $from)
            ->when($requireDue, fn ($q) => $q->whereNotNull('scheduled_at')->where('scheduled_at', '<=', now()))
            ->update([
                'status' => SocialPost::PREPARING,
                'run_id' => $runId,
                'locked_at' => now(),
                'attempts' => (int) $post->attempts + 1,
                'updated_at' => now(),
            ]);

        return $claimed === 1 ? $runId : null;
    }

    /**
     * Ejecuta una publicación reclamada con ese run_id (en la institución activa). Si el run_id ya
     * no coincide (otra ejecución, cancelación), no hace nada.
     */
    public function run(int $postId, string $runId): ?SocialPost
    {
        $post = SocialPost::query()->whereKey($postId)->where('status', SocialPost::PREPARING)->where('run_id', $runId)->first();
        if ($post === null) {
            return null;
        }

        $post = $this->publisher->execute($post);
        $post->run_id = null;
        $post->locked_at = null;
        $post->save();

        $this->audit->log('social_post.executed', $post, [
            'status' => $post->status,
            'networks' => $post->targets->mapWithKeys(fn (SocialPostTarget $t) => [$t->network => $t->status])->all(),
        ]);

        return $post;
    }

    /**
     * Publicar ya (desde Programadas, el calendario o el editor): reclama desde draft o scheduled
     * y ejecuta en el momento. Null si no se pudo reclamar (ya en curso, cancelada…).
     */
    public function publishNow(SocialPost $post): ?SocialPost
    {
        $runId = $this->claim($post, SocialPost::EDITABLE, requireDue: false);

        return $runId === null ? null : $this->run((int) $post->getKey(), $runId);
    }

    /**
     * Una pasada del programador (todas las instituciones): recupera ejecuciones interrumpidas,
     * reanuda videos de Instagram en proceso y publica lo vencido, dentro del tiempo dado.
     *
     * @return array{published:int, skipped:int, resumed:int, recovered:int}
     */
    public function tick(int $limit = 20, int $budgetSeconds = 50): array
    {
        $deadline = microtime(true) + $budgetSeconds;
        $out = ['published' => 0, 'skipped' => 0, 'resumed' => 0, 'recovered' => 0];

        $out['recovered'] = $this->recoverStale();

        /** @var array<int, array{id:int, institution_id:int}> $due */
        $due = app(CurrentInstitution::class)->runGlobally(fn () => SocialPost::query()
            ->where('status', SocialPost::SCHEDULED)
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', now())
            ->orderBy('scheduled_at')
            ->limit(max(1, $limit))
            ->get(['id', 'institution_id'])
            ->map(fn (SocialPost $p) => ['id' => (int) $p->id, 'institution_id' => (int) $p->institution_id])
            ->all());

        foreach ($due as $row) {
            if (microtime(true) > $deadline) {
                break; // el resto, en la siguiente pasada (cada minuto)
            }
            $done = app(CurrentInstitution::class)->runFor($row['institution_id'], function () use ($row): bool {
                $post = SocialPost::query()->find($row['id']);
                $runId = $post !== null ? $this->claim($post, [SocialPost::SCHEDULED], requireDue: true) : null;

                return $runId !== null && $this->run($row['id'], $runId) !== null;
            });
            $done ? $out['published']++ : $out['skipped']++;
        }

        if (microtime(true) < $deadline) {
            $out['resumed'] = $this->resumeProcessing($deadline);
        }

        return $out;
    }

    /** Reanuda contenedores de Instagram que Meta seguía procesando (sin crear otros). */
    private function resumeProcessing(float $deadline): int
    {
        /** @var array<int, array{id:int, institution_id:int}> $rows */
        $rows = app(CurrentInstitution::class)->runGlobally(fn () => SocialPostTarget::query()
            ->where('network', 'instagram')
            ->where('status', 'processing')
            ->whereNotNull('container_id')
            ->where(fn ($q) => $q->whereNull('last_attempt_at')->orWhere('last_attempt_at', '<=', now()->subSeconds(self::RESUME_EVERY_SECONDS)))
            ->orderBy('id')
            ->limit(20)
            ->get(['id', 'institution_id'])
            ->map(fn (SocialPostTarget $t) => ['id' => (int) $t->id, 'institution_id' => (int) $t->institution_id])
            ->all());

        $n = 0;
        foreach ($rows as $row) {
            if (microtime(true) > $deadline) {
                break;
            }
            app(CurrentInstitution::class)->runFor($row['institution_id'], function () use ($row): void {
                $target = SocialPostTarget::query()->find($row['id']);
                if ($target !== null) {
                    $this->publisher->resumeTarget($target);
                }
            });
            $n++;
        }

        return $n;
    }

    /**
     * Ejecuciones interrumpidas (el proceso cayó con la publicación en «preparing»): no se sabe
     * si Meta llegó a publicar, así que NO se reintenta sola. Las redes sin resultado quedan
     * «fallidas» con un aviso para comprobar la red antes de reintentar desde el Historial.
     */
    private function recoverStale(): int
    {
        /** @var array<int, array{id:int, institution_id:int}> $rows */
        $rows = app(CurrentInstitution::class)->runGlobally(fn () => SocialPost::query()
            ->where('status', SocialPost::PREPARING)
            ->where('locked_at', '<', now()->subMinutes(self::STALE_MINUTES))
            ->get(['id', 'institution_id'])
            ->map(fn (SocialPost $p) => ['id' => (int) $p->id, 'institution_id' => (int) $p->institution_id])
            ->all());

        foreach ($rows as $row) {
            app(CurrentInstitution::class)->runFor($row['institution_id'], function () use ($row): void {
                $post = SocialPost::query()->find($row['id']);
                if ($post === null || $post->status !== SocialPost::PREPARING) {
                    return;
                }
                $message = __('La ejecución se interrumpió antes de confirmar el resultado. Comprueba la publicación en la red antes de reintentar.');
                $post->targets()->where('status', 'pending')->update(['status' => 'failed', 'error_message' => $message, 'updated_at' => now()]);
                $existing = $post->targets()->pluck('network')->all();
                foreach (array_diff($post->destinationNetworks(), $existing) as $network) {
                    $post->targets()->create(['network' => $network, 'status' => 'failed', 'error_message' => $message]);
                }
                $post->run_id = null;
                $post->locked_at = null;
                $this->publisher->refreshStatus($post);
                $this->audit->log('social_post.interrupted', $post);
            });
        }

        return count($rows);
    }
}
