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

    /**
     * @param  array<string,mixed>  $input
     */
    public function saveInstitution(User $actor, array $input): FollowUpSetting
    {
        $this->authorize($actor);
        $data = Validator::make($input, $this->rules(false))->validate();
        $data = $this->normalizeCadence($data);
        $this->validateWindow($data);
        $this->validateCadence($data);

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

    /**
     * @param  array<string,mixed>  $input
     */
    public function saveAdvisor(User $actor, Bot $bot, array $input): FollowUpAdvisorSetting
    {
        $this->authorize($actor);
        abort_unless((int) $bot->institution_id === (int) $actor->institution_id, 404);
        $data = Validator::make($input, $this->rules(true))->validate();
        $data = $this->normalizeCadence($data);
        $this->validateWindow($data, true);
        $institution = app(FollowUpSettingsResolver::class)->institution((int) $actor->institution_id);
        $this->validateTightening($data, $institution);
        $this->validateCadence($data, $institution->whatsapp_cadence);
        if ($data['enabled'] && $bot->status !== 'active') {
            throw ValidationException::withMessages(['enabled' => 'No se puede habilitar el seguimiento de un asesor inactivo.']);
        }
        if (isset($data['allowed_channel_ids'])) {
            $data['allowed_channel_ids'] = array_map('intval', $data['allowed_channel_ids']);
            $channels = \Modules\Social\Models\SocialChannel::query()->whereIn('id', $data['allowed_channel_ids'])->get();
            if ($channels->count() !== count($data['allowed_channel_ids']) || $channels->contains(fn ($channel) => \Modules\FollowUp\Support\AdvisorChannels::restriction($channel, $bot, $institution->allowed_channels) !== null)) {
                throw ValidationException::withMessages(['allowed_channel_ids' => 'Selecciona únicamente canales asignados, conectados y permitidos para este asesor.']);
            }
        }

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
            $this->invalidator->forAdvisor((int) $bot->id, 'advisor_settings_changed');
            $this->events->record('settings.changed', reason: 'advisor_settings_changed', safeData: ['advisor_settings_id' => (int) $setting->id, 'bot_id' => (int) $bot->id, 'lock_version' => $setting->lock_version]);
            $this->audit->log('follow_up.advisor_settings_changed', $setting, ['bot_id' => (int) $bot->id, 'fields' => array_keys($data), 'lock_version' => $setting->lock_version]);

            return $setting;
        }, 3);
    }

    /**
     * @return array<string,mixed>
     */
    private function rules(bool $advisor): array
    {
        $number = fn (string $type, int $max): array => [$advisor ? 'nullable' : 'required', $type, 'min:1', 'max:'.$max];
        $array = [$advisor ? 'nullable' : 'required', 'array', 'min:1'];
        $text = [$advisor ? 'nullable' : 'required', 'string'];

        return [
            'whatsapp_cadence' => ['sometimes', 'nullable', 'array:enabled,first,second,third,minimum,margin,maximum'],
            'whatsapp_cadence.enabled' => ['required_with:whatsapp_cadence', 'boolean'],
            'whatsapp_cadence.first' => ['required_with:whatsapp_cadence', 'integer', 'between:1,22'],
            'whatsapp_cadence.second' => ['required_with:whatsapp_cadence', 'integer', 'between:2,22'],
            'whatsapp_cadence.third' => ['required_with:whatsapp_cadence', 'integer', 'between:3,23'],
            'whatsapp_cadence.minimum' => ['required_with:whatsapp_cadence', 'integer', 'between:2,12'],
            'whatsapp_cadence.margin' => ['required_with:whatsapp_cadence', 'integer', 'between:30,180'],
            'whatsapp_cadence.maximum' => ['required_with:whatsapp_cadence', 'integer', 'between:1,3'],
            'enabled' => ['required', 'boolean'],
            'mode' => [$advisor ? 'nullable' : 'required', Rule::in(FollowUpSetting::MODES)],
            'allowed_channel_ids' => $advisor ? ['nullable', 'array'] : ['prohibited'],
            'allowed_channel_ids.*' => ['integer', 'min:1', 'distinct'],
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

    /**
     * @param  array<string,mixed>  $data
     */
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

    /**
     * @param  array<string,mixed>  $data
     */
    private function validateTightening(array $data, FollowUpSetting $institution): void
    {
        $errors = [];
        if (isset($data['mode']) && app(FollowUpSettingsResolver::class)->restrictedMode($institution->mode, $data['mode']) !== $data['mode']) {
            $errors['mode'] = 'El modo del asesor no puede superar la autonomía institucional.';
        }
        if (isset($data['timezone']) && $data['timezone'] !== $institution->timezone) {
            $errors['timezone'] = 'El horario del asesor debe usar la zona horaria institucional.';
        }
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
        if (isset($data['business_hours_start']) && $data['business_hours_start'] < substr($institution->business_hours_start, 0, 5)) {
            $errors['business_hours_start'] = 'El horario del asesor debe estar dentro del institucional.';
        }
        if (isset($data['business_hours_end']) && $data['business_hours_end'] > substr($institution->business_hours_end, 0, 5)) {
            $errors['business_hours_end'] = 'El horario del asesor debe estar dentro del institucional.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * @param  array<string,mixed>  $data
     * @param  array<string,mixed>|null  $institution
     */
    private function validateCadence(array $data, ?array $institution = null): void
    {
        $c = $data['whatsapp_cadence'] ?? null;
        if ($c === null) {
            return;
        }
        if ($c['minimum'] > $c['second'] - $c['first'] || $c['minimum'] > $c['third'] - $c['second'] || $c['third'] * 60 + $c['margin'] >= 1440) {
            throw ValidationException::withMessages(['whatsapp_cadence' => 'Separa los contactos por el mínimo absoluto y conserva el margen antes de 24 horas.']);
        }
        if ($institution !== null) {
            $effective = WhatsAppCadence::effective($institution, $c);
            foreach ($c as $key => $value) {
                if ($effective[$key] !== $value) {
                    throw ValidationException::withMessages(['whatsapp_cadence' => 'El asesor no puede relajar la cadencia institucional.']);
                }
            }
        }
    }

    /**
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    private function normalizeCadence(array $data): array
    {
        if (isset($data['whatsapp_cadence'])) {
            foreach ($data['whatsapp_cadence'] as $key => $value) {
                $data['whatsapp_cadence'][$key] = $key === 'enabled' ? (bool) $value : (int) $value;
            }
        }

        return $data;
    }

    private function authorize(User $actor): void
    {
        abort_unless($actor->isActive() && $actor->canManageSettings() && (int) $actor->institution_id === app(\Modules\Core\Tenancy\CurrentInstitution::class)->idOrFail(), 403);
    }
}
