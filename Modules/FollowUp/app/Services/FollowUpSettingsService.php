<?php

declare(strict_types=1);

namespace Modules\FollowUp\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\Audit\Services\AuditService;
use Modules\Crm\Support\UserTimezone;
use Modules\FollowUp\Models\FollowUpAdvisorSetting;
use Modules\FollowUp\Models\FollowUpSetting;
use Modules\Institutions\Models\Bot;

final class FollowUpSettingsService
{
    public function __construct(private readonly AuditService $audit, private readonly ActionInvalidator $invalidator, private readonly FollowUpEventRecorder $events) {}

    /** @param array<string,mixed> $input */
    public function saveInstitution(User $actor, array $input): FollowUpSetting
    {
        $this->authorize($actor);
        $data = Validator::make($input, $this->rules(false))->validate();
        $this->validateWindow($data);

        return DB::transaction(function () use ($actor, $data): FollowUpSetting {
            $setting = FollowUpSetting::query()->where('institution_id', $actor->institution_id)->lockForUpdate()->first() ?? new FollowUpSetting;
            $creating = ! $setting->exists;
            $setting->fill($data);
            $setting->institution_id = $actor->institution_id;
            $setting->lock_version = $creating ? 1 : $setting->lock_version + 1;
            $setting->created_by_user_id ??= $actor->id;
            $setting->updated_by_user_id = $actor->id;
            $setting->save();
            $this->invalidator->forInstitution('institution_settings_changed');
            $this->events->record('settings.changed', reason: 'institution_settings_changed', safeData: ['settings_id' => (int) $setting->id, 'lock_version' => $setting->lock_version]);
            $this->audit->log('follow_up.settings_changed', $setting, ['fields' => array_keys($data), 'lock_version' => $setting->lock_version]);

            return $setting;
        }, 3);
    }

    /** @param array<string,mixed> $input */
    public function saveAdvisor(User $actor, Bot $bot, array $input): FollowUpAdvisorSetting
    {
        $this->authorize($actor);
        abort_unless((int) $bot->institution_id === (int) $actor->institution_id, 404);
        $data = Validator::make($input, $this->rules(true))->validate();
        $this->validateWindow($data, true);
        $institution = app(FollowUpSettingsResolver::class)->institution((int) $actor->institution_id);
        $this->validateTightening($data, $institution);

        return DB::transaction(function () use ($actor, $bot, $data): FollowUpAdvisorSetting {
            $setting = FollowUpAdvisorSetting::query()->where('bot_id', $bot->id)->lockForUpdate()->first() ?? new FollowUpAdvisorSetting;
            $creating = ! $setting->exists;
            $setting->fill($data);
            $setting->institution_id = $actor->institution_id;
            $setting->bot_id = $bot->id;
            $setting->lock_version = $creating ? 1 : $setting->lock_version + 1;
            $setting->created_by_user_id ??= $actor->id;
            $setting->updated_by_user_id = $actor->id;
            $setting->save();
            $this->invalidator->forInstitution('advisor_settings_changed');
            $this->events->record('settings.changed', reason: 'advisor_settings_changed', safeData: ['advisor_settings_id' => (int) $setting->id, 'bot_id' => (int) $bot->id, 'lock_version' => $setting->lock_version]);
            $this->audit->log('follow_up.advisor_settings_changed', $setting, ['bot_id' => (int) $bot->id, 'fields' => array_keys($data), 'lock_version' => $setting->lock_version]);

            return $setting;
        }, 3);
    }

    /** @return array<string,mixed> */
    private function rules(bool $advisor): array
    {
        $number = fn (string $type, int $max): array => [$advisor ? 'nullable' : 'required', $type, 'min:1', 'max:'.$max];
        $array = [$advisor ? 'nullable' : 'required', 'array', 'min:1'];
        $text = [$advisor ? 'nullable' : 'required', 'string'];

        return [
            'enabled' => ['required', 'boolean'],
            'mode' => $advisor ? ['prohibited'] : ['required', Rule::in(FollowUpSetting::MODES)],
            'first_follow_up_hours' => $number('integer', 2160),
            'minimum_interval_hours' => $number('integer', 2160),
            'maximum_attempts' => $number('integer', 20),
            'allowed_days' => $array,
            'allowed_days.*' => ['integer', 'between:1,7', 'distinct'],
            'business_hours_start' => [$advisor ? 'nullable' : 'required', 'date_format:H:i'],
            'business_hours_end' => [$advisor ? 'nullable' : 'required', 'date_format:H:i'],
            'timezone' => [...$text, fn (string $attribute, mixed $value, \Closure $fail) => UserTimezone::isValid($value) ?: $fail('La zona horaria debe ser un identificador IANA válido.')],
            'allowed_channels' => $array,
            'allowed_channels.*' => [Rule::in(['whatsapp', 'instagram', 'messenger', 'email']), 'distinct'],
            'policy_version' => $advisor ? ['prohibited'] : ['required', 'string', 'max:32'],
            'pause_on_human' => $advisor ? ['required', 'accepted'] : ['prohibited'],
        ];
    }

    /** @param array<string,mixed> $data */
    private function validateWindow(array $data, bool $nullable = false): void
    {
        $start = $data['business_hours_start'] ?? null;
        $end = $data['business_hours_end'] ?? null;
        if ($nullable && $start === null && $end === null) {
            return;
        }
        if (! is_string($start) || ! is_string($end) || $start >= $end) {
            throw ValidationException::withMessages(['business_hours_end' => 'La hora final debe ser posterior a la inicial; no se admiten franjas nocturnas ambiguas.']);
        }
    }

    /** @param array<string,mixed> $data */
    private function validateTightening(array $data, FollowUpSetting $institution): void
    {
        $errors = [];
        if (isset($data['first_follow_up_hours']) && $data['first_follow_up_hours'] < $institution->first_follow_up_hours) {
            $errors['first_follow_up_hours'] = 'El asesor no puede adelantar el primer seguimiento institucional.';
        }
        if (isset($data['minimum_interval_hours']) && $data['minimum_interval_hours'] < $institution->minimum_interval_hours) {
            $errors['minimum_interval_hours'] = 'El asesor no puede reducir el intervalo institucional.';
        }
        if (isset($data['maximum_attempts']) && $data['maximum_attempts'] > $institution->maximum_attempts) {
            $errors['maximum_attempts'] = 'El asesor no puede superar el máximo institucional.';
        }
        if (isset($data['allowed_days']) && array_diff((array) $data['allowed_days'], (array) $institution->allowed_days) !== []) {
            $errors['allowed_days'] = 'Los días del asesor deben pertenecer a los días institucionales.';
        }
        if (isset($data['allowed_channels']) && array_diff((array) $data['allowed_channels'], (array) $institution->allowed_channels) !== []) {
            $errors['allowed_channels'] = 'Los canales del asesor deben pertenecer a los canales institucionales.';
        }
        if (isset($data['business_hours_start']) && $data['business_hours_start'] < $institution->business_hours_start) {
            $errors['business_hours_start'] = 'El horario del asesor debe estar dentro del institucional.';
        }
        if (isset($data['business_hours_end']) && $data['business_hours_end'] > $institution->business_hours_end) {
            $errors['business_hours_end'] = 'El horario del asesor debe estar dentro del institucional.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function authorize(User $actor): void
    {
        abort_unless($actor->isActive() && $actor->canManageSettings() && (int) $actor->institution_id === app(\Modules\Core\Tenancy\CurrentInstitution::class)->idOrFail(), 403);
    }
}
