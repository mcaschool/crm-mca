<?php

declare(strict_types=1);

namespace Modules\CollaborationCore\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Modules\CollaborationCore\Contracts\AuthenticatedSessionContextFactory;
use Modules\CollaborationCore\Data\AuthenticatedSessionContext;
use Modules\CollaborationCore\Enums\AuthenticationLevel;
use Modules\CollaborationCore\Exceptions\ContractException;

final class ContextFactory implements AuthenticatedSessionContextFactory
{
    public function __construct(private ApplicationAccess $access) {}

    public function fromAuthenticatedRequest(Request $request): AuthenticatedSessionContext
    {
        $user = $request->user();
        if (! $user instanceof User || ! $request->hasSession()) {
            throw new ContractException('unauthenticated', 401);
        }
        $user = $user->fresh() ?? throw new ContractException('unauthenticated', 401);
        if (! $this->access->allows($user, 'collaboration')) {
            throw new ContractException('forbidden', 403);
        }
        // Existing Laravel login authenticates only after the TOTP challenge.
        // No mobile bearer flow is exposed in this phase.
        if (! $user->hasTwoFactorEnabled() || ! MfaSessionProof::valid($request->session(), $user)) {
            throw new ContractException('two_factor_required', 403);
        }
        $id = $request->attributes->get('collaboration.request_id');
        if (! is_string($id)) {
            $id = (string) Str::ulid();
            $request->attributes->set('collaboration.request_id', $id);
        }

        return new AuthenticatedSessionContext((int) $user->institution_id, (int) $user->id,
            hash('sha256', $request->session()->getId()), $id, AuthenticationLevel::Mfa);
    }
}
