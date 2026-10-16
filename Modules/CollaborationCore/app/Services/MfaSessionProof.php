<?php

declare(strict_types=1);

namespace Modules\CollaborationCore\Services;

use App\Models\User;
use Illuminate\Contracts\Session\Session;

final class MfaSessionProof
{
    public static function fingerprint(User $user): string
    {
        return hash('sha256', $user->getRawOriginal('password').'|'.$user->getRawOriginal('two_factor_secret'));
    }

    public static function record(Session $session, User $user): void
    {
        $session->put('auth.mfa_user_id', (int) $user->id);
        $session->put('auth.mfa_fingerprint', self::fingerprint($user));
    }

    public static function valid(Session $session, User $user): bool
    {
        return (int) $session->get('auth.mfa_user_id', 0) === (int) $user->id
            && hash_equals(self::fingerprint($user), (string) $session->get('auth.mfa_fingerprint', ''));
    }
}
