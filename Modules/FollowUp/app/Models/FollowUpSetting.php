<?php

declare(strict_types=1);

namespace Modules\FollowUp\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Tenancy\Concerns\BelongsToInstitution;

/**
 * @property int $institution_id
 * @property bool $enabled
 * @property string $mode
 * @property int $first_follow_up_hours
 * @property int $minimum_interval_hours
 * @property int $maximum_attempts
 * @property array<int,int> $allowed_days
 * @property string $business_hours_start
 * @property string $business_hours_end
 * @property string $timezone
 * @property array<int,string> $allowed_channels
 * @property string $policy_version
 * @property int $lock_version
 * @property int|null $created_by_user_id
 * @property int|null $updated_by_user_id
 */
class FollowUpSetting extends Model
{
    use BelongsToInstitution;

    public const MODES = ['manual', 'supervised', 'controlled_auto'];

    protected $fillable = ['institution_id', 'enabled', 'mode', 'first_follow_up_hours', 'minimum_interval_hours', 'maximum_attempts', 'allowed_days', 'business_hours_start', 'business_hours_end', 'timezone', 'allowed_channels', 'policy_version', 'lock_version', 'created_by_user_id', 'updated_by_user_id'];

    protected $attributes = ['enabled' => false, 'mode' => 'manual', 'first_follow_up_hours' => 24, 'minimum_interval_hours' => 24, 'maximum_attempts' => 3, 'business_hours_start' => '09:00:00', 'business_hours_end' => '18:00:00', 'timezone' => 'UTC', 'policy_version' => '2.0', 'lock_version' => 1];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'allowed_days' => 'array', 'allowed_channels' => 'array', 'first_follow_up_hours' => 'integer', 'minimum_interval_hours' => 'integer', 'maximum_attempts' => 'integer', 'lock_version' => 'integer'];
    }
}
