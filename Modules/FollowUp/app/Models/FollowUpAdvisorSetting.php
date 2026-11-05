<?php

declare(strict_types=1);

namespace Modules\FollowUp\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Tenancy\Concerns\BelongsToInstitution;
use Modules\Institutions\Models\Bot;

/**
 * @property array<string,mixed>|null $whatsapp_cadence
 * @property int $institution_id
 * @property int $bot_id
 * @property bool $enabled
 * @property string|null $mode
 * @property array<int,int>|null $allowed_channel_ids
 * @property int|null $first_follow_up_hours
 * @property int|null $minimum_interval_hours
 * @property int|null $maximum_attempts
 * @property array<int,int>|null $allowed_days
 * @property string|null $business_hours_start
 * @property string|null $business_hours_end
 * @property string|null $timezone
 * @property array<int,string>|null $allowed_channels
 * @property bool $pause_on_human
 * @property int $lock_version
 * @property int|null $created_by_user_id
 * @property int|null $updated_by_user_id
 */
class FollowUpAdvisorSetting extends Model
{
    use BelongsToInstitution;

    protected $fillable = ['whatsapp_cadence', 'institution_id', 'bot_id', 'enabled', 'mode', 'allowed_channel_ids', 'first_follow_up_hours', 'minimum_interval_hours', 'maximum_attempts', 'allowed_days', 'business_hours_start', 'business_hours_end', 'timezone', 'allowed_channels', 'pause_on_human', 'lock_version', 'created_by_user_id', 'updated_by_user_id'];

    protected $attributes = ['enabled' => false, 'pause_on_human' => true, 'lock_version' => 1];

    protected function casts(): array
    {
        return ['whatsapp_cadence' => 'array', 'enabled' => 'boolean', 'pause_on_human' => 'boolean', 'allowed_days' => 'array', 'allowed_channels' => 'array', 'allowed_channel_ids' => 'array', 'first_follow_up_hours' => 'integer', 'minimum_interval_hours' => 'integer', 'maximum_attempts' => 'integer', 'lock_version' => 'integer'];
    }

    /** @return BelongsTo<Bot, $this> */
    public function bot(): BelongsTo
    {
        return $this->belongsTo(Bot::class);
    }
}
