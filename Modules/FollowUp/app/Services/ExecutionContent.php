<?php

declare(strict_types=1);

namespace Modules\FollowUp\Services;

use Illuminate\Validation\ValidationException;
use Modules\FollowUp\Models\FollowUpAction;
use Modules\Social\Models\SocialWhatsAppTemplate;

final class ExecutionContent
{
    /** @return array{text:string,template:?SocialWhatsAppTemplate,parameters:array<int,string>,hash:string} */
    public function build(FollowUpAction $action): array
    {
        $payload = $action->draft_payload;
        if (! $action->draft_expires_at?->isFuture() || $action->draft_generated_at === null) {
            throw ValidationException::withMessages(['execution' => 'draft_validity_unknown']);
        }
        if ($action->draft_state !== 'review' || ($payload['recommended_action'] ?? null) !== 'draft_message' || trim((string) ($payload['draft_text'] ?? '')) === '') {
            throw ValidationException::withMessages(['execution' => 'draft_not_sendable']);
        }
        $text = (string) $payload['draft_text'];
        $parameters = [];
        $template = null;
        if ($action->social_whatsapp_template_id !== null) {
            $template = SocialWhatsAppTemplate::query()->where('social_channel_id', $action->opportunity->social_channel_id)->findOrFail($action->social_whatsapp_template_id);
            $valid = $template->isApproved() && $template->meta_template_id && $template->last_synced_at?->gt(now()->subHour())
                && ! $template->hasMediaHeader() && str_starts_with($template->language, (string) $payload['language'])
                && $template->category === ($action->purpose === 'operational' ? 'UTILITY' : 'MARKETING');
            if (! $valid) {
                throw ValidationException::withMessages(['execution' => 'template_not_verified']);
            }
            // Only deterministic contact-name substitution; no model-generated template variables.
            $bindings = $action->execution_parameters ?? [];
            $variables = $template->positionalVariables();
            if (array_keys($bindings) !== $variables || array_diff($bindings, ['contact_name']) !== []) {
                throw ValidationException::withMessages(['execution' => 'template_variables_invalid']);
            }
            foreach ($bindings as $key => $binding) {
                $name = $action->opportunity->contact->first_name ?? $action->opportunity->socialConversation?->contact_name;
                if (! is_string($name) || trim($name) === '' || mb_strlen($name) > 80 || preg_match('/[\r\n{}]/', $name)) {
                    throw ValidationException::withMessages(['execution' => 'template_variable_unavailable']);
                }
                $parameters[(int) $key] = $name;
            }
            foreach ($template->components as $component) {
                if (! in_array(strtoupper((string) ($component['type'] ?? '')), ['BODY', 'FOOTER'], true)) {
                    throw ValidationException::withMessages(['execution' => 'template_component_unsupported']);
                }
            }
            $text = preg_replace_callback('/\{\{(\d+)\}\}/', fn ($m) => $parameters[(int) $m[1]] ?? '', (string) ($template->component('BODY')['text'] ?? '')) ?? '';
            if ($text === '' || str_contains($text, '{{')) {
                throw ValidationException::withMessages(['execution' => 'template_body_invalid']);
            }
        }
        $hash = hash('sha256', json_encode([$action->draft_version, $payload, $action->context_fingerprint, $action->policy_version, $action->eligibility_version, $template?->getAttributes(), $parameters], JSON_THROW_ON_ERROR));

        return compact('text', 'template', 'parameters', 'hash');
    }
}
