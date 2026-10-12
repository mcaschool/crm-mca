<?php

declare(strict_types=1);

namespace Modules\Crm\Livewire\Video;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Modules\Audit\Services\AuditService;
use Modules\Crm\Livewire\Video\Concerns\ManagesVideoRooms;
use Modules\Crm\Models\VideoRoom;
use Modules\Crm\Services\Agenda\AgendaDirectory;
use Modules\Crm\Services\Video\GoogleMeetClient;
use Modules\Crm\Services\Video\VideoCapacity;
use Modules\Crm\Services\Video\VideoConnections;
use Modules\Crm\Services\Video\VideoProviderException;
use Modules\Crm\Services\Video\ZoomClient;
use Modules\Integrations\Models\Integration;

/**
 * Configuración › Integraciones › Videoconferencias: la conexión institucional de Google Meet y
 * la de Zoom, sus pruebas, la capacidad efectiva de Zoom y las salas asignadas a cada asesor.
 * Solo administración (IntegrationPolicy). Los secretos se escriben, nunca se vuelven a mostrar.
 */
#[Layout('layouts.app')]
class Connections extends Component
{
    use ManagesVideoRooms;

    public string $googleClientId = '';

    public string $googleClientSecret = '';

    public string $googleAccessType = 'TRUSTED';

    public string $zoomAccountId = '';

    public string $zoomClientId = '';

    public string $zoomClientSecret = '';

    public string $zoomHostEmail = '';

    public int $zoomConcurrency = 1;

    public function mount(VideoConnections $connections, VideoCapacity $capacity): void
    {
        $this->authorize('viewAny', Integration::class);
        $google = $connections->get(VideoConnections::GOOGLE);
        $zoom = $connections->get(VideoConnections::ZOOM);
        $this->googleClientId = (string) $google?->secret('client_id', '');
        $this->googleAccessType = $google?->secret('access_type') === 'OPEN' ? 'OPEN' : 'TRUSTED';
        $this->zoomAccountId = (string) $zoom?->secret('account_id', '');
        $this->zoomClientId = (string) $zoom?->secret('client_id', '');
        $this->zoomHostEmail = (string) $zoom?->secret('host_email', '');
        $this->zoomConcurrency = $capacity->limit();
    }

    public function saveGoogle(VideoConnections $connections, AuditService $audit): void
    {
        $integration = $connections->getOrNew(VideoConnections::GOOGLE);
        $this->authorize($integration->exists ? 'update' : 'create', $integration->exists ? $integration : Integration::class);
        $hasSecret = $integration->secret('client_secret') !== null;
        $this->validate([
            'googleClientId' => ['required', 'string', 'max:200', 'regex:/^[A-Za-z0-9._-]+$/'],
            'googleClientSecret' => [$hasSecret ? 'nullable' : 'required', 'string', 'max:200'],
            'googleAccessType' => ['required', 'in:TRUSTED,OPEN'],
        ]);

        $changedClient = $integration->secret('client_id') !== trim($this->googleClientId) || trim($this->googleClientSecret) !== '';
        $integration->replaceSecrets(['client_id' => trim($this->googleClientId), 'client_secret' => trim($this->googleClientSecret)]);
        if (! $integration->exists) {
            $integration->save();
        }
        // Otro cliente OAuth invalida la autorización anterior: hay que volver a dar consentimiento.
        $connections->put($integration, ['access_type' => $this->googleAccessType], $changedClient ? ['access_token', 'refresh_token', 'access_token_expires_at'] : []);
        if ($changedClient) {
            $integration->forceFill(['status' => 'pending'])->save();
        }
        $audit->log('integration.updated', $integration, $changedClient ? ['credentials_rotated' => ['client_id', 'client_secret']] : []);
        $this->googleClientSecret = '';
        $this->notify($changedClient ? __('Credenciales guardadas. Conecta la cuenta institucional para dar el consentimiento.') : __('Configuración guardada.'));
    }

    public function testGoogle(VideoConnections $connections, GoogleMeetClient $google): void
    {
        $integration = $this->existing($connections, VideoConnections::GOOGLE);
        try {
            $email = $google->test($integration);
            $connections->recordTest($integration, true, __('Conectada como :email.', ['email' => $email]), 'active');
            $this->notify(__('Conexión correcta.'));
        } catch (VideoProviderException $e) {
            $connections->recordFailure($integration, $e, 'google.test');
            $this->notify($e->userMessage(), true);
        }
    }

    public function disconnectGoogle(VideoConnections $connections, GoogleMeetClient $google, AuditService $audit): void
    {
        $integration = $this->existing($connections, VideoConnections::GOOGLE);
        $google->disconnect($integration);
        $audit->log('integration.disconnected', $integration, ['provider' => 'google_meet']);
        $this->notify(__('Google Meet desconectado. Las salas ya asignadas siguen funcionando; no se pueden crear ni revisar salas hasta reconectar.'));
    }

    public function saveZoom(VideoConnections $connections, ZoomClient $zoom, AuditService $audit): void
    {
        $integration = $connections->getOrNew(VideoConnections::ZOOM);
        $this->authorize($integration->exists ? 'update' : 'create', $integration->exists ? $integration : Integration::class);
        $hasSecret = $integration->secret('client_secret') !== null;
        $max = max(1, (int) config('crm.video.zoom.max_concurrency', 2));
        $this->validate([
            'zoomAccountId' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9_-]+$/'],
            'zoomClientId' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9_-]+$/'],
            'zoomClientSecret' => [$hasSecret ? 'nullable' : 'required', 'string', 'max:200'],
            'zoomHostEmail' => ['nullable', 'email', 'max:191'],
            'zoomConcurrency' => ['required', 'integer', 'min:1', 'max:'.$max],
        ]);

        $rotated = trim($this->zoomClientSecret) !== '' || $integration->secret('client_id') !== trim($this->zoomClientId) || $integration->secret('account_id') !== trim($this->zoomAccountId);
        $integration->replaceSecrets([
            'account_id' => trim($this->zoomAccountId),
            'client_id' => trim($this->zoomClientId),
            'client_secret' => trim($this->zoomClientSecret),
        ]);
        if (! $integration->exists) {
            $integration->save();
        }
        $connections->put($integration, ['host_email' => trim($this->zoomHostEmail), 'concurrency' => $this->zoomConcurrency], $rotated ? ['access_token', 'access_token_expires_at'] : []);
        $audit->log('integration.updated', $integration, ['concurrency' => $this->zoomConcurrency] + ($rotated ? ['credentials_rotated' => ['account_id', 'client_id', 'client_secret']] : []));
        $this->zoomClientSecret = '';
        $this->testZoom($connections, $zoom);
    }

    public function testZoom(VideoConnections $connections, ZoomClient $zoom): void
    {
        $integration = $this->existing($connections, VideoConnections::ZOOM);
        try {
            $host = $zoom->test($integration);
            $message = $host['type'] === 2
                ? __('Conectada. Anfitrión institucional: :email (con licencia).', ['email' => $host['email']])
                : __('Conectada. Anfitrión institucional: :email (SIN licencia: las reuniones tienen el límite de duración de Zoom Basic).', ['email' => $host['email']]);
            $connections->recordTest($integration, true, $message, 'active');
            $this->notify($message);
        } catch (VideoProviderException $e) {
            $connections->recordFailure($integration, $e, 'zoom.test');
            $this->notify($e->userMessage(), true);
        }
    }

    public function disconnectZoom(VideoConnections $connections, ZoomClient $zoom, AuditService $audit): void
    {
        $integration = $this->existing($connections, VideoConnections::ZOOM);
        $zoom->disconnect($integration);
        $audit->log('integration.disconnected', $integration, ['provider' => 'zoom']);
        $this->notify(__('Zoom desconectado. Las reuniones ya asignadas siguen funcionando; no se pueden crear ni revisar hasta reconectar.'));
    }

    public function render(VideoConnections $connections, VideoCapacity $capacity): View
    {
        $google = $connections->get(VideoConnections::GOOGLE);
        $zoom = $connections->get(VideoConnections::ZOOM);
        $advisors = app(AgendaDirectory::class)->assignableUsers();
        $rooms = VideoRoom::query()->whereIn('user_id', $advisors->pluck('id'))->where('status', '!=', 'replaced')
            ->orderByDesc('id')->get()->unique(fn (VideoRoom $r) => $r->user_id.'|'.$r->provider)
            ->keyBy(fn (VideoRoom $r) => $r->user_id.'|'.$r->provider);

        return view('crm::livewire.video.connections', [
            'google' => $google,
            'zoom' => $zoom,
            'googleMasked' => $google?->maskedConfig() ?? [],
            'zoomMasked' => $zoom?->maskedConfig() ?? [],
            'advisors' => $advisors,
            'rooms' => $rooms,
            'zoomLimit' => $capacity->limit(),
            'zoomMax' => max(1, (int) config('crm.video.zoom.max_concurrency', 2)),
            'redirectUri' => route('integrations.video.google.callback'),
        ]);
    }

    private function existing(VideoConnections $connections, string $type): Integration
    {
        $integration = $connections->get($type);
        abort_unless($integration !== null, 404);
        $this->authorize('update', $integration);

        return $integration;
    }

    private function notify(string $message, bool $error = false): void
    {
        $this->videoNotice = $message;
        $this->videoNoticeError = $error;
    }
}
