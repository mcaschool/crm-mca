<?php

declare(strict_types=1);

namespace Modules\TeamChat\Http;

use Illuminate\Http\Request;
use Modules\CollaborationCore\Contracts\ActorContextFactory;
use Modules\CollaborationCore\Data\ActorContext;
use Modules\CollaborationCore\Exceptions\ContractException;
use Modules\CollaborationCore\Services\WidgetApplications;
use Modules\CollaborationCore\Services\WidgetPreferences;
use Modules\CollaborationCore\Services\WidgetSessions;
use Modules\CollaborationRealtime\Services\WidgetCoordinator;
use Modules\TeamChat\Services\ChatAccess;

final class WidgetController
{
    /** @return array<string,mixed> */
    private function input(Request $r): array
    {
        return $r->validate(['locale' => 'sometimes|in:es,en', 'app' => 'required|string|max:64', 'origin' => 'required|string|max:255', 'challenge' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{43}$/D'], 'state' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{32,128}$/D']]);
    }

    private function actor(Request $r): ActorContext
    {
        return $r->attributes->get('collaboration.actor') ?? app(ActorContextFactory::class)->fromAuthenticatedRequest($r);
    }

    /** @return array<string,mixed> */
    private function binding(Request $r): array
    {
        $b = $r->attributes->get('widget.binding');
        if (! is_array($b)) {
            throw new ContractException('unauthenticated', 401);
        }

        return $b;
    }

    public function configuration(Request $r): \Illuminate\Http\JsonResponse
    {
        $v = $r->validate(['app' => 'required|string|max:64', 'origin' => 'required|string|max:255']);
        $app = app(WidgetApplications::class)->resolve($v['app'], $v['origin']);

        return response()->json(['data' => ['id' => $app->id, 'name' => $app->name, 'environment' => $app->environment, 'appearance' => json_decode($app->appearance, true)]], 200, ['Cache-Control' => 'no-store']);
    }

    public function frame(Request $r): \Illuminate\Http\Response
    {
        $v = $r->validate(['app' => 'required|string|max:64', 'origin' => 'required|string|max:255', 'locale' => 'sometimes|in:es,en', 'channel' => ['required', 'string', 'regex:/^[a-zA-Z0-9-]{32,64}$/D']]);
        $app = app(WidgetApplications::class)->resolve($v['app'], $v['origin']);
        app()->setLocale($v['locale'] ?? 'es');
        $nonce = bin2hex(random_bytes(16));
        \Illuminate\Support\Facades\Vite::useCspNonce($nonce);
        $central = rtrim((string) config('collaboration_widget.central_origin'), '/');
        $socket = (config('teamchat.reverb_scheme') === 'https' ? 'wss' : 'ws').'://'.config('teamchat.reverb_host').':'.config('teamchat.reverb_port');

        return response()->view('teamchat::widget-frame', ['widget' => ['app' => $app->id, 'origin' => $v['origin'], 'channel' => $v['channel'], 'locale' => app()->getLocale(), 'central' => $central, 'appearance' => json_decode($app->appearance, true), 'environment' => $app->environment, 'capabilities' => json_decode($app->capabilities, true)], 'nonce' => $nonce])
            ->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer')
            ->header('Content-Security-Policy', "default-src 'none'; script-src 'self' 'nonce-$nonce'; style-src 'self' 'unsafe-inline'; img-src 'self' blob: data:; media-src 'self' blob:; font-src 'self'; connect-src 'self' $socket; frame-ancestors ".$v['origin']."; base-uri 'none'; form-action 'self'")
            ->header('Permissions-Policy', 'camera=(), geolocation=()')->header('X-Content-Type-Options', 'nosniff');
    }

    public function authorizeForm(Request $r): \Illuminate\Http\Response
    {
        $v = $this->input($r);
        $app = app(WidgetApplications::class)->resolve($v['app'], $v['origin']);

        return response()->view('teamchat::widget-authorize', ['values' => $v, 'application' => $app])->header('X-Frame-Options', 'DENY')->header('Content-Security-Policy', "frame-ancestors 'none'; base-uri 'none'")->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function authorize(Request $r): \Illuminate\Http\Response|\Illuminate\Http\JsonResponse
    {
        $v = $this->input($r);
        if ($r->boolean('automatic') && $v['origin'] !== rtrim((string) config('collaboration_widget.central_origin'), '/')) {
            throw new ContractException('forbidden', 403);
        }
        $code = app(WidgetSessions::class)->issue($this->actor($r), $v['app'], $v['origin'], $v['challenge']);
        if ($r->boolean('automatic')) {
            return response()->json(['data' => ['code' => $code]], 200, ['Cache-Control' => 'no-store']);
        }
        $nonce = bin2hex(random_bytes(16));

        return response()->view('teamchat::widget-callback', ['payload' => ['type' => 'mca.widget.code', 'state' => $v['state'], 'app' => $v['app'], 'code' => $code], 'origin' => rtrim((string) config('collaboration_widget.central_origin'), '/'), 'nonce' => $nonce])
            ->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer')->header('Content-Security-Policy', "default-src 'none'; script-src 'nonce-$nonce'; frame-ancestors 'none'; base-uri 'none'");
    }

    public function exchange(Request $r): \Illuminate\Http\JsonResponse
    {
        if ($r->header('Origin') !== rtrim((string) config('collaboration_widget.central_origin'), '/')) {
            throw new ContractException('forbidden', 403);
        }
        $v = $r->validate(['app' => 'required|string|max:64', 'origin' => 'required|string|max:255', 'code' => 'required|string|size:64', 'verifier' => 'required|string|min:43|max:128']);

        return response()->json(['data' => app(WidgetSessions::class)->exchange($v['app'], $v['origin'], $v['code'], $v['verifier'])], 200, ['Cache-Control' => 'no-store']);
    }

    public function content(Request $r): \Illuminate\Http\Response
    {
        $this->binding($r);
        $v = $r->validate(['locale' => 'sometimes|in:es,en']);
        app()->setLocale($v['locale'] ?? 'es');

        return response()->view('teamchat::chat-content')->header('Cache-Control', 'no-store');
    }

    public function heartbeat(Request $r): \Illuminate\Http\JsonResponse
    {
        $b = $this->binding($r);
        $v = $r->validate(['visible' => 'required|boolean', 'focused' => 'required|boolean', 'interacted' => 'required|boolean']);

        return response()->json(['data' => app(WidgetCoordinator::class)->heartbeat($b['actor'], $b['session'], $v)], 200, ['Cache-Control' => 'no-store']);
    }

    public function claim(Request $r): \Illuminate\Http\JsonResponse
    {
        $b = $this->binding($r);
        $v = $r->validate(['notification' => 'required|string|max:64']);

        return response()->json(['data' => app(WidgetCoordinator::class)->claim($b['actor'], $b['session'], $v['notification'])], 200, ['Cache-Control' => 'no-store']);
    }

    public function ack(Request $r): \Illuminate\Http\JsonResponse
    {
        $b = $this->binding($r);
        $v = $r->validate(['event' => 'required|string|max:64', 'lease' => 'required|string|size:48']);
        app(WidgetCoordinator::class)->acknowledge($b['actor'], $b['session'], $v['event'], $v['lease']);

        return response()->json(['data' => ['acknowledged' => true]], 200, ['Cache-Control' => 'no-store']);
    }

    public function renew(Request $r): \Illuminate\Http\JsonResponse
    {
        $b = $this->binding($r);

        return response()->json(['data' => app(WidgetSessions::class)->renew($b['actor'], $b['session'])], 200, ['Cache-Control' => 'no-store']);
    }

    public function revoke(Request $r): \Illuminate\Http\JsonResponse
    {
        $b = $this->binding($r);
        app(WidgetSessions::class)->revoke($b['actor'], $b['session']->id);
        app(WidgetCoordinator::class)->remove($b['actor'], $b['session']->id);

        return response()->json(['data' => ['revoked' => true]], 200, ['Cache-Control' => 'no-store']);
    }

    public function preferences(Request $r): \Illuminate\Http\JsonResponse
    {
        $b = $this->binding($r);
        $resource = (string) $r->input('resource_id', '');
        if ($resource !== '') {
            app(ChatAccess::class)->conversation($b['actor'], $resource);
        }
        if ($r->isMethod('PUT')) {
            $v = $r->validate(['resource_id' => 'nullable|string|max:64', 'visual' => 'sometimes|boolean', 'sound' => 'sometimes|boolean', 'muted' => 'sometimes|boolean', 'snooze_minutes' => 'sometimes|integer|min:0|max:10080']);
            app(WidgetPreferences::class)->set($b['actor'], $resource, $v);
        }

        return response()->json(['data' => app(WidgetPreferences::class)->get($b['actor'], $resource)], 200, ['Cache-Control' => 'no-store']);
    }

    public function state(Request $r): \Illuminate\Http\JsonResponse
    {
        $b = $this->binding($r);
        $unread = app(ChatAccess::class)->unreadCount($b['actor']);

        return response()->json(['data' => ['unread' => $unread]], 200, ['Cache-Control' => 'no-store']);
    }
}
