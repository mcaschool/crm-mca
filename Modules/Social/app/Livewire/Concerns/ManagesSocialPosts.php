<?php

declare(strict_types=1);

namespace Modules\Social\Livewire\Concerns;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Modules\Audit\Services\AuditService;
use Modules\Crm\Support\UserTimezone;
use Modules\Social\Models\SocialPost;
use Modules\Social\Models\SocialPostTarget;
use Modules\Social\Services\MetaContentPublisher;
use Modules\Social\Services\SocialPostScheduler;
use Modules\Social\Services\SocialPublishService;
use Modules\Social\Support\ScheduleInput;

/**
 * Acciones compartidas por Historial, Programadas y Calendario editorial: ver el detalle,
 * cancelar, devolver a borrador, publicar ya, reprogramar, eliminar un borrador, reintentar o
 * reanudar una red y, en Facebook, editar el texto o borrar lo publicado.
 *
 * Seguridad: todas exigen canPublishSocial y trabajan sobre la institución activa (scope global:
 * un id de otra institución no se encuentra). Los cambios de estado son UPDATE condicionados al
 * estado esperado: si la ejecución programada ya tomó la publicación, la acción no se aplica.
 */
trait ManagesSocialPosts
{
    /** Publicación abierta en el detalle. */
    public ?int $detailId = null;

    /** Reprogramación en curso. */
    public ?int $rescheduleId = null;

    public string $rescheduleDate = '';

    public string $rescheduleTime = '';

    public string $rescheduleTz = '';

    /** Edición del texto publicado en Facebook. */
    public ?int $remoteEditId = null;

    public string $remoteCaption = '';

    public ?string $notice = null;

    public ?string $noticeTone = null;

    protected function authorizePublishing(): void
    {
        abort_unless(auth()->user()?->canPublishSocial() ?? false, 403);
    }

    public function openPost(int $id): void
    {
        $this->authorizePublishing();
        $this->detailId = SocialPost::query()->whereKey($id)->exists() ? $id : null;
        $this->reset(['rescheduleId', 'remoteEditId', 'remoteCaption']);
        $this->resetValidation();
    }

    public function closePost(): void
    {
        $this->reset(['detailId', 'rescheduleId', 'remoteEditId', 'remoteCaption']);
        $this->resetValidation();
    }

    public function cancelPost(int $id, AuditService $audit): void
    {
        $this->authorizePublishing();
        $done = SocialPost::query()->whereKey($id)->where('status', SocialPost::SCHEDULED)->update([
            'status' => SocialPost::CANCELLED,
            'cancelled_at' => now(),
            'cancelled_by' => auth()->id(),
            'updated_by' => auth()->id(),
            'updated_at' => now(),
        ]);
        $this->afterTransition($id, $done === 1, 'social_post.cancelled', __('Programación cancelada: no se publicará.'), $audit);
    }

    public function toDraft(int $id, AuditService $audit): void
    {
        $this->authorizePublishing();
        $done = SocialPost::query()->whereKey($id)->whereIn('status', [SocialPost::SCHEDULED, SocialPost::CANCELLED])->update([
            'status' => SocialPost::DRAFT,
            'scheduled_at' => null,
            'cancelled_at' => null,
            'cancelled_by' => null,
            'updated_by' => auth()->id(),
            'updated_at' => now(),
        ]);
        $this->afterTransition($id, $done === 1, 'social_post.returned_to_draft', __('La publicación vuelve a ser un borrador.'), $audit);
    }

    public function publishNow(int $id, SocialPostScheduler $scheduler, AuditService $audit): void
    {
        $this->authorizePublishing();
        $post = SocialPost::query()->find($id);
        if ($post === null || ! $post->isEditable()) {
            $this->flashNotice(__('Esta publicación ya no se puede publicar desde aquí: su estado cambió.'), 'warning');

            return;
        }
        if (! $post->hasMedia() || $post->destinationNetworks() === []) {
            $this->flashNotice(__('Completa el archivo y los destinos en el editor antes de publicar.'), 'warning');

            return;
        }

        $audit->log('social_post.published_now', $post);
        $result = $scheduler->publishNow($post);
        if ($result === null) {
            $this->flashNotice(__('Esta publicación ya se está procesando.'), 'warning');

            return;
        }
        $this->detailId = $id;
        $this->flashNotice($this->resultNotice($result), $result->status === SocialPost::PUBLISHED ? 'success' : 'warning');
    }

    public function deleteDraft(int $id, AuditService $audit): void
    {
        $this->authorizePublishing();
        $post = SocialPost::query()->whereKey($id)->whereIn('status', [SocialPost::DRAFT, SocialPost::CANCELLED])->first();
        if ($post === null || $post->targets()->exists()) {
            $this->flashNotice(__('Solo se pueden eliminar borradores y programaciones canceladas que no llegaron a publicarse.'), 'warning');

            return;
        }

        $audit->log('social_post.deleted', $post, ['status' => $post->status]);
        $path = $post->mediaPath();
        $post->delete();
        // El archivo solo se borra si ninguna otra publicación lo usa.
        if ($path !== null && ! SocialPost::query()->where(fn ($q) => $q->where('media_path', $path)->orWhere('image_path', $path))->exists()) {
            Storage::disk('public')->delete($path);
        }
        $this->closePost();
        $this->flashNotice(__('Borrador eliminado.'), 'success');
    }

    public function startReschedule(int $id): void
    {
        $this->authorizePublishing();
        $post = SocialPost::query()->whereKey($id)->whereIn('status', SocialPost::EDITABLE)->first();
        if ($post === null) {
            return;
        }
        $tz = UserTimezone::isValid($post->timezone) ? (string) $post->timezone : UserTimezone::current();
        $at = ($post->scheduled_at ?? now()->addHour())->copy()->setTimezone($tz);
        $this->detailId = $id;
        $this->rescheduleId = $id;
        $this->rescheduleDate = $at->format('Y-m-d');
        $this->rescheduleTime = $at->format('H:i');
        $this->rescheduleTz = $tz;
        $this->resetValidation();
    }

    public function cancelReschedule(): void
    {
        $this->reset(['rescheduleId', 'rescheduleDate', 'rescheduleTime', 'rescheduleTz']);
        $this->resetValidation();
    }

    public function saveReschedule(AuditService $audit): void
    {
        $this->authorizePublishing();
        $id = (int) $this->rescheduleId;
        $post = SocialPost::query()->whereKey($id)->whereIn('status', SocialPost::EDITABLE)->first();
        if ($post === null) {
            $this->cancelReschedule();
            $this->flashNotice(__('Esta publicación ya no se puede reprogramar: su estado cambió.'), 'warning');

            return;
        }
        if (! $post->hasMedia() || $post->destinationNetworks() === []) {
            $this->addError('rescheduleDate', __('Completa el archivo y los destinos en el editor antes de programar.'));

            return;
        }
        $when = $this->parseSchedule($this->rescheduleDate, $this->rescheduleTime, $this->rescheduleTz, 'rescheduleDate');
        if ($when === null) {
            return;
        }

        $previous = $post->scheduled_at?->toIso8601String();
        $done = SocialPost::query()->whereKey($id)->whereIn('status', SocialPost::EDITABLE)->update([
            'status' => SocialPost::SCHEDULED,
            'scheduled_at' => $when,
            'timezone' => $this->rescheduleTz,
            'updated_by' => auth()->id(),
            'updated_at' => now(),
        ]);
        $this->cancelReschedule();
        $this->afterTransition($id, $done === 1, 'social_post.rescheduled', __('Programada para el :date.', ['date' => $when->copy()->setTimezone($this->viewerTz())->translatedFormat('j M Y · H:i')]), $audit, ['from' => $previous, 'to' => $when->toIso8601String()]);
    }

    public function retryTarget(int $targetId, SocialPublishService $service, AuditService $audit): void
    {
        $this->authorizePublishing();
        $target = SocialPostTarget::query()->find($targetId);
        if ($target === null || $target->status !== 'failed' || $target->post === null || $target->post->isEditable() || $target->post->status === SocialPost::PREPARING) {
            return;
        }
        $audit->log('social_post.retry', $target->post, ['network' => $target->network]);
        $target = $service->retryTarget($target);
        $this->detailId = (int) $target->social_post_id;
        $this->flashNotice($target->status === 'published'
            ? __(':network: publicado.', ['network' => $target->networkLabel()])
            : __(':network: :status.', ['network' => $target->networkLabel(), 'status' => mb_strtolower($target->statusLabel())]), $target->status === 'published' ? 'success' : 'warning');
    }

    public function resumeTarget(int $targetId, SocialPublishService $service): void
    {
        $this->authorizePublishing();
        $target = SocialPostTarget::query()->find($targetId);
        if ($target === null || $target->network !== 'instagram' || $target->status !== 'processing' || (string) $target->container_id === '') {
            return;
        }
        $service->resumeTarget($target);
        $this->detailId = (int) $target->social_post_id;
    }

    /** Borra en Facebook (API oficial) una publicación hecha desde el CRM. Instagram no lo permite con esta conexión. */
    public function deleteRemote(int $targetId, MetaContentPublisher $meta, AuditService $audit): void
    {
        $this->authorizePublishing();
        $target = SocialPostTarget::query()->with('channel')->find($targetId);
        if ($target === null || ! $this->canDeleteRemote($target)) {
            return;
        }

        $result = $meta->deleteFacebookObject($target->channel, (string) $target->external_post_id);
        if ($result->ok) {
            $target->remote_deleted_at = now();
            $target->save();
            $audit->log('social_post.remote_deleted', $target->post, ['network' => 'facebook']);
            $this->flashNotice(__('Publicación eliminada de Facebook. El registro se conserva en el historial.'), 'success');
        } else {
            $this->flashNotice(__('Facebook no permitió eliminarla: :error', ['error' => $result->error]), 'danger');
        }
        $this->detailId = (int) $target->social_post_id;
    }

    public function startRemoteEdit(int $targetId): void
    {
        $this->authorizePublishing();
        $target = SocialPostTarget::query()->find($targetId);
        if ($target === null || ! $this->canEditRemote($target)) {
            return;
        }
        $this->remoteEditId = $targetId;
        $this->remoteCaption = (string) $target->post?->caption;
        $this->detailId = (int) $target->social_post_id;
    }

    public function saveRemoteEdit(MetaContentPublisher $meta, AuditService $audit): void
    {
        $this->authorizePublishing();
        $this->validate(['remoteCaption' => ['required', 'string', 'max:2200']], [], ['remoteCaption' => __('texto')]);
        $target = SocialPostTarget::query()->with(['channel', 'post'])->find((int) $this->remoteEditId);
        if ($target === null || ! $this->canEditRemote($target)) {
            $this->reset(['remoteEditId', 'remoteCaption']);

            return;
        }

        $result = $meta->updateFacebookPostMessage($target->channel, (string) $target->external_post_id, $this->remoteCaption);
        if ($result->ok) {
            $audit->log('social_post.remote_updated', $target->post, ['network' => 'facebook']);
            $this->reset(['remoteEditId', 'remoteCaption']);
            $this->flashNotice(__('Texto actualizado en Facebook. La publicación de Instagram, si existe, conserva su descripción (Instagram no permite editarla).'), 'success');
        } else {
            $this->addError('remoteCaption', __('Facebook no permitió editarla: :error', ['error' => $result->error]));
        }
    }

    public function cancelRemoteEdit(): void
    {
        $this->reset(['remoteEditId', 'remoteCaption']);
        $this->resetValidation();
    }

    /** Facebook: borrar lo publicado (posts, videos y reels); las Historias no tienen borrado por API. */
    public function canDeleteRemote(SocialPostTarget $target): bool
    {
        return $target->network === 'facebook' && $target->status === 'published' && $target->remote_deleted_at === null
            && (string) $target->external_post_id !== '' && $target->channel !== null
            && $target->post !== null && $target->post->content_type !== 'story';
    }

    /** Facebook: editar el texto solo de un Post de imagen (id de post de Página {page}_{post}). */
    public function canEditRemote(SocialPostTarget $target): bool
    {
        return $this->canDeleteRemote($target) && $target->post->content_type === 'post' && $target->post->media_type === 'image'
            && str_contains((string) $target->external_post_id, '_');
    }

    /** Fecha y hora en una zona IANA → instante en UTC (ScheduleInput); el error va a $errorKey. */
    protected function parseSchedule(string $date, string $time, string $tz, string $errorKey): ?Carbon
    {
        $utc = ScheduleInput::toUtc($date, $time, $tz);
        if (is_string($utc)) {
            $this->addError($errorKey, $utc);

            return null;
        }

        return $utc;
    }

    protected function viewerTz(): string
    {
        return UserTimezone::current();
    }

    protected function detailPost(): ?SocialPost
    {
        return $this->detailId === null ? null : SocialPost::query()->with(['targets.channel', 'creator', 'updater', 'canceller'])->find($this->detailId);
    }

    protected function flashNotice(string $message, string $tone): void
    {
        $this->notice = $message;
        $this->noticeTone = $tone;
    }

    private function resultNotice(SocialPost $post): string
    {
        return match ($post->status) {
            SocialPost::PUBLISHED => __('Publicada en todas las redes elegidas.'),
            SocialPost::PARTIAL => __('Publicada solo en parte: revisa la red que falló.'),
            SocialPost::PROCESSING => __('Meta sigue procesando el video: se completará automáticamente.'),
            default => __('No se pudo publicar: revisa el motivo en cada red.'),
        };
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function afterTransition(int $id, bool $applied, string $action, string $message, AuditService $audit, array $changes = []): void
    {
        if (! $applied) {
            $this->flashNotice(__('No se aplicó: la publicación cambió de estado (puede que ya se esté publicando).'), 'warning');

            return;
        }
        $post = SocialPost::query()->find($id);
        if ($post !== null) {
            $audit->log($action, $post, $changes);
        }
        $this->flashNotice($message, 'success');
    }
}
