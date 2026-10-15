<?php

declare(strict_types=1);

namespace Modules\CollaborationCore\Services;

use App\Models\User;
use Illuminate\Http\Request;

/** UI/gate read snapshot only. Sensitive Core operations use ApplicationAccess directly. */
final class PanelAccessSnapshot
{
    private ?Request $request = null;

    /** @var array<string,bool> */
    private array $decisions = [];

    public function __construct(private ApplicationAccess $access) {}

    public function allows(User $user, string $application): bool
    {
        if ($this->request !== request()) {
            $this->forget();
            $this->request = request();
        }
        $key = implode(':', [$user->institution_id, $user->id, $application, $user->status, (int) $user->getAttribute('crm_legacy_access')]);

        return $this->decisions[$key] ??= $this->access->allows($user, $application);
    }

    public function forget(): void
    {
        $this->decisions = [];
    }
}
