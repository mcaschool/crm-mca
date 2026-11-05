<?php

declare(strict_types=1);

namespace Modules\FollowUp\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Tenancy\Concerns\BelongsToInstitution;
use Modules\Crm\Models\Contact;
use Modules\Crm\Models\Lead;
use Modules\FollowUp\Database\Factories\FollowUpOpportunityFactory;
use Modules\Institutions\Models\Bot;
use Modules\Social\Models\SocialChannel;
use Modules\Social\Models\SocialConversation;
use Modules\Social\Models\SocialMessage;

/**
 * @property int $institution_id
 * @property int $bot_id
 * @property int $social_channel_id
 * @property int $social_conversation_id
 * @property int|null $contact_id
 * @property int|null $lead_id
 * @property int|null $source_last_message_id
 * @property string $provider
 * @property string $status
 * @property string $classification
 * @property string $priority
 * @property int $score
 * @property array<int,string> $reason_codes
 * @property array<string,int|bool|string|null>|null $context_snapshot
 * @property int $cycle
 * @property \Carbon\CarbonInterface|null $last_inbound_at
 * @property \Carbon\CarbonInterface|null $last_outbound_at
 * @property \Carbon\CarbonInterface $source_last_message_at
 * @property \Carbon\CarbonInterface $detected_at
 * @property \Carbon\CarbonInterface $last_evaluated_at
 * @property \Carbon\CarbonInterface|null $next_evaluation_at
 * @property \Carbon\CarbonInterface|null $cancelled_at
 * @property string|null $cancellation_reason
 * @property int|null $commercial_owner_id
 * @property int|null $commercial_activity_id
 * @property int|null $commercial_reviewed_message_id
 * @property int|null $commercial_program_id
 * @property int|null $crm_conversation_id
 * @property int $commercial_version
 * @property bool $commercial_hold
 * @property string|null $commercial_link_source
 * @property string|null $commercial_fingerprint
 * @property string|null $commercial_close_reason
 * @property array<string,mixed>|null $commercial_recommendation
 * @property array<string,mixed>|null $commercial_result
 * @property \Carbon\CarbonInterface|null $commercial_updated_at
 */
class FollowUpOpportunity extends Model
{
    use BelongsToInstitution;

    /** @use HasFactory<FollowUpOpportunityFactory> */
    use HasFactory;

    public const STATUSES = ['open', 'cancelled', 'suppressed', 'paused', 'closed'];

    protected $hidden = ['commercial_link_evidence'];

    public const PRIORITIES = ['low', 'medium', 'high'];

    protected $fillable = [
        'institution_id', 'bot_id', 'social_channel_id', 'social_conversation_id',
        'contact_id', 'lead_id', 'source_last_message_id', 'provider', 'status',
        'classification', 'priority', 'score', 'reason_codes', 'context_snapshot',
        'cycle', 'last_inbound_at', 'last_outbound_at', 'source_last_message_at',
        'detected_at', 'last_evaluated_at', 'next_evaluation_at', 'cancelled_at',
        'cancellation_reason',
    ];

    protected function casts(): array
    {
        return [
            'commercial_version' => 'integer', 'commercial_hold' => 'boolean',
            'commercial_recommendation' => 'array', 'commercial_result' => 'array',
            'commercial_updated_at' => 'datetime', 'commercial_linked_at' => 'datetime',
            'commercial_link_evidence' => 'encrypted',
            'reason_codes' => 'array',
            'context_snapshot' => 'array',
            'score' => 'integer',
            'cycle' => 'integer',
            'last_inbound_at' => 'datetime',
            'last_outbound_at' => 'datetime',
            'source_last_message_at' => 'datetime',
            'detected_at' => 'datetime',
            'last_evaluated_at' => 'datetime',
            'next_evaluation_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Bot, $this> */
    public function bot(): BelongsTo
    {
        return $this->belongsTo(Bot::class);
    }

    /** @return BelongsTo<SocialChannel, $this> */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(SocialChannel::class, 'social_channel_id');
    }

    /** @return BelongsTo<SocialConversation, $this> */
    public function socialConversation(): BelongsTo
    {
        return $this->belongsTo(SocialConversation::class);
    }

    /** @return BelongsTo<Contact, $this> */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /** @return BelongsTo<\App\Models\User, $this> */
    public function commercialOwner(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'commercial_owner_id')->where('users.institution_id', app(\Modules\Core\Tenancy\CurrentInstitution::class)->idOrFail());
    }

    /** @return BelongsTo<\Modules\Crm\Models\Activity, $this> */
    public function commercialActivity(): BelongsTo
    {
        return $this->belongsTo(\Modules\Crm\Models\Activity::class, 'commercial_activity_id');
    }

    /** @return BelongsTo<Lead, $this> */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    /** @return BelongsTo<SocialMessage, $this> */
    public function sourceLastMessage(): BelongsTo
    {
        return $this->belongsTo(SocialMessage::class, 'source_last_message_id');
    }

    /** @return HasMany<FollowUpCycle, $this> */
    public function cycles(): HasMany
    {
        return $this->hasMany(FollowUpCycle::class);
    }

    /** @return HasMany<FollowUpAction, $this> */
    public function actions(): HasMany
    {
        return $this->hasMany(FollowUpAction::class);
    }

    protected static function newFactory(): FollowUpOpportunityFactory
    {
        return FollowUpOpportunityFactory::new();
    }
}
