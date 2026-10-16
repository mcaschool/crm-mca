<?php

declare(strict_types=1);

namespace Modules\CollaborationCore\Integration;

use Illuminate\Support\Facades\DB;
use Modules\CollaborationCore\Services\Revocation;

/** Security-sensitive Eloquent saves and their outbox records commit together, even without an outer transaction.
 * Bulk SQL updates bypass Eloquent; they must not be used for these security transitions.
 */
trait AtomicCollaborationRevocation
{
    /** @param array<string,mixed> $options */
    public function save(array $options = [])
    {
        $user = $this instanceof \App\Models\User;
        $fields = $user ? ['status', 'institution_id', 'password', 'two_factor_secret', 'two_factor_confirmed_at'] : ['status', 'collaboration_enabled'];
        if (! $this->exists || ! $this->isDirty($fields)) {
            return parent::save($options);
        }

        return DB::transaction(function () use ($user, $options) {
            $before = DB::table($this->getTable())->where('id', $this->getKey())->firstOrFail();
            $ids = $user ? array_filter([(int) $before->institution_id, (int) $this->getAttribute('institution_id')]) : [(int) $this->getKey()];
            DB::table('institutions')->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
            $before = DB::table($this->getTable())->where('id', $this->getKey())->lockForUpdate()->firstOrFail();
            if ($user && $before->institution_id && ! in_array((int) $before->institution_id, $ids, true)) {
                throw new \Modules\CollaborationCore\Exceptions\ContractException('forbidden', 403);
            }
            $saved = parent::save($options);
            if ($saved) {
                $after = $this->fresh() ?? throw new \RuntimeException('Security subject disappeared during save.');
                if ($after instanceof \App\Models\User) {
                    app(Revocation::class)->userChanged($before, $after);
                } else {
                    app(Revocation::class)->institutionChanged($before, $after);
                }
            }

            return $saved;
        });
    }
}
