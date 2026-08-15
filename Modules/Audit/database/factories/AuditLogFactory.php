<?php

declare(strict_types=1);

namespace Modules\Audit\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Audit\Models\AuditLog;

/**
 * @extends Factory<AuditLog>
 */
class AuditLogFactory extends Factory
{
    protected $model = AuditLog::class;

    public function definition(): array
    {
        return [
            'user_id' => null,
            'action' => 'updated',
            'auditable_type' => 'Modules\\Integrations\\Models\\Integration',
            'auditable_id' => 1,
            'changes' => ['api_key' => '[REDACTED]'],
            'ip' => $this->faker->ipv4(),
        ];
    }
}
