<?php

declare(strict_types=1);

namespace Modules\Crm\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Modules\Audit\Services\AuditService;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Crm\Services\Video\GoogleMeetClient;
use Modules\Crm\Services\Video\VideoConnections;
use Modules\Crm\Services\Video\VideoProviderException;
use Modules\Integrations\Models\Integration;

/**
 * Consentimiento OAuth de la cuenta institucional de Google Meet (solo administración). El `state`
 * es de un solo uso, ligado a la sesión, al usuario y a la institución, y caduca en 10 minutos; el
 * verificador PKCE nunca sale del servidor.
 */
class GoogleMeetOAuthController extends Controller
{
    private const SESSION = 'crm_video_google_oauth';

    public function start(Request $request, GoogleMeetClient $google, VideoConnections $connections, CurrentInstitution $context): RedirectResponse
    {
        $integration = $connections->get(VideoConnections::GOOGLE);
        abort_unless($integration !== null, 404);
        Gate::authorize('update', $integration);

        try {
            $auth = $google->authorizationRequest($integration, $this->redirectUri());
        } catch (VideoProviderException $e) {
            return redirect()->route('integrations.video')->with('video_status', $e->userMessage());
        }
        $request->session()->put(self::SESSION, [
            'state' => hash('sha256', $auth['state']),
            'verifier' => $auth['verifier'],
            'institution_id' => $context->idOrFail('videoconferencias'),
            'user_id' => $request->user()?->id,
            'expires' => now()->addMinutes(10)->getTimestamp(),
        ]);

        return redirect()->away($auth['url']);
    }

    public function callback(Request $request, GoogleMeetClient $google, VideoConnections $connections, CurrentInstitution $context, AuditService $audit): RedirectResponse
    {
        $pending = $request->session()->pull(self::SESSION); // un solo uso
        $integration = $connections->get(VideoConnections::GOOGLE);
        abort_unless($integration instanceof Integration, 404);
        Gate::authorize('update', $integration);

        $state = (string) $request->query('state', '');
        $valid = is_array($pending)
            && is_string($pending['state'] ?? null) && $state !== '' && hash_equals($pending['state'], hash('sha256', $state))
            && ($pending['institution_id'] ?? null) === $context->idOrFail('videoconferencias')
            && ($pending['user_id'] ?? null) === $request->user()?->id
            && (int) ($pending['expires'] ?? 0) >= now()->getTimestamp();
        if (! $valid) {
            return redirect()->route('integrations.video')->with('video_status', __('La solicitud de conexión no es válida o caducó. Vuelve a intentarlo.'));
        }
        if ($request->query('error') !== null || ! is_string($request->query('code')) || $request->query('code') === '') {
            return redirect()->route('integrations.video')->with('video_status', __('Google no concedió el acceso. No se cambió nada.'));
        }

        try {
            $email = $google->completeAuthorization($integration, (string) $request->query('code'), (string) $pending['verifier'], $this->redirectUri());
        } catch (VideoProviderException $e) {
            $connections->recordFailure($integration, $e, 'google.connect');

            return redirect()->route('integrations.video')->with('video_status', $e->userMessage());
        }
        $audit->log('integration.connected', $integration, ['provider' => 'google_meet']);

        return redirect()->route('integrations.video')->with('video_status', __('Google Meet conectado como :email.', ['email' => $email]));
    }

    private function redirectUri(): string
    {
        return route('integrations.video.google.callback');
    }
}
