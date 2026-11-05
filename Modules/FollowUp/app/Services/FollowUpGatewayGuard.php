<?php

declare(strict_types=1);

namespace Modules\FollowUp\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\FollowUp\Data\PolicyRequest;
use Modules\FollowUp\Models\FollowUpAction;
use Modules\Social\Models\SocialConversation;
use Modules\Social\Models\SocialMessage;
use Modules\Social\Models\SocialWhatsAppTemplate;

/** Protect the internal gateway contract as well as its coordinator entry point. */
final class FollowUpGatewayGuard
{
    public function prepare(SocialConversation $conversation, string $text, int $botId, int $actionId, ?SocialWhatsAppTemplate $template): string
    {
        if (DB::transactionLevel() < 1) {
            $this->reject();
        }
        $action = FollowUpAction::query()->lockForUpdate()->find($actionId);
        if ($action === null || $action->status !== 'claimed' || ! $action->execution_token || $action->dispatch_at !== null
            || $action->social_message_id !== null || $action->institution_id !== $conversation->institution_id
            || $action->opportunity->social_conversation_id !== $conversation->id || $action->opportunity->bot_id !== $botId) {
            $this->reject();
        }
        $decision = app(FollowUpPolicyEngine::class)->evaluate(new PolicyRequest($action->opportunity, 'send', $action->channel, $action->purpose, now(), $action->expires_at, $action->social_whatsapp_template_id, action: $action), false);
        if (! $decision->allows()) {
            $this->reject();
        }
        $content = app(ExecutionContent::class)->build($action);
        if ($text !== $content['text'] || ! $this->sameTemplate($template, $content['template'])) {
            $this->reject();
        }

        return $content['hash'];
    }

    /** @param array<int,string> $parameters */
    public function dispatch(SocialMessage $message, ?SocialWhatsAppTemplate $template, array $parameters): void
    {
        $action = FollowUpAction::query()->where('social_message_id', $message->id)->find($message->ai_meta['follow_up_action_id'] ?? 0);
        if ($action === null || $action->status !== 'sending' || ! $action->execution_token || $action->dispatch_at === null
            || $message->direction !== 'outbound' || $action->institution_id !== $message->institution_id
            || $action->opportunity->social_conversation_id !== $message->social_conversation_id || $action->opportunity->bot_id !== $message->ai_bot_id) {
            $this->reject();
        }
        // Dispatch is already committed. Validate that the exact reserved content is
        // used, without treating a later retry as a new policy authorization.
        $content = app(ExecutionContent::class)->build($action);
        if ($message->body !== $content['text'] || ! $this->sameTemplate($template, $content['template']) || $parameters !== $content['parameters']
            || ($message->ai_meta['follow_up_content_hash'] ?? null) !== $content['hash']) {
            $this->reject();
        }
    }

    private function sameTemplate(?SocialWhatsAppTemplate $given, ?SocialWhatsAppTemplate $canonical): bool
    {
        if ($canonical === null) {
            return $given === null;
        }

        // These fields determine the provider request and the persisted preview.
        // Comparing IDs alone accepts a model modified in memory after approval.
        return $given !== null && $given->id === $canonical->id
            && $given->meta_template_id === $canonical->meta_template_id
            && $given->name === $canonical->name && $given->language === $canonical->language
            && $given->components === $canonical->components;
    }

    private function reject(): never
    {
        throw ValidationException::withMessages(['execution' => 'follow_up_gateway_unauthorized']);
    }
}
