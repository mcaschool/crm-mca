<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\CollaborationCore\Data\InstitutionContext;
use Modules\CollaborationCore\Exceptions\ContractException;
use Modules\CollaborationCore\Services\InstitutionExecution;
use Modules\CollaborationRealtime\Contracts\TransactionalEventRecorder;
use Modules\CollaborationRealtime\Data\DeliveryAudience;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Institutions\Models\Institution;

require_once __DIR__.'/../../../../tests/RealtimeFixtures.php';

it('4C retained institution execution establishes and restores the live scoped context', function () {
    $a = Institution::factory()->create();
    $b = Institution::factory()->create();
    $execution = app(InstitutionExecution::class);
    app()->forgetScopedInstances();
    $current = app(CurrentInstitution::class);
    $current->set($b->id);
    expect(fn () => $execution->run(new InstitutionContext($a->id), function () use ($a) {
        expect(app(CurrentInstitution::class)->id())->toBe($a->id);
        throw new RuntimeException('operation failure');
    }))->toThrow(RuntimeException::class, 'operation failure');
    expect($current->id())->toBe($b->id)->and($current->isGlobal())->toBeFalse();
});

it('4C retained recorder checks the live tenant after worker scope renewal', function () {
    realtimePolicies();
    $a = Institution::factory()->create();
    $b = Institution::factory()->create();
    $user = User::factory()->create(['institution_id' => $a->id, 'role' => 'member']);
    $actor = realtimeActor($user);
    $recorder = app(TransactionalEventRecorder::class);
    app()->forgetScopedInstances();
    $current = app(CurrentInstitution::class);
    $current->set($b->id);
    expect(fn () => DB::transaction(fn () => $recorder->append(new InstitutionContext($a->id), realtimeEvent($actor, '4c-forbidden'), new DeliveryAudience($a->id, [$user->id]))))
        ->toThrow(ContractException::class);
    expect(DB::table('collab_events')->where('id', '4c-forbidden')->exists())->toBeFalse();
    $current->set($a->id);
    $id = DB::transaction(fn () => $recorder->append(new InstitutionContext($a->id), realtimeEvent($actor, '4c-allowed'), new DeliveryAudience($a->id, [$user->id])));
    expect($id)->toBe('4c-allowed')->and($current->id())->toBe($a->id);
});
