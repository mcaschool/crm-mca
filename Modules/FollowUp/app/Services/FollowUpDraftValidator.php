<?php

declare(strict_types=1);

namespace Modules\FollowUp\Services;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Modules\Ai\Support\TextLanguage;

final class FollowUpDraftValidator
{
    /** @param array<string,string> $sources
     * @param  list<array{direction:string,text:string}>  $history
     * @return array<string,mixed>
     */
    public function validate(string $json, string $locale, array $sources, array $history): array
    {
        $data = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        if (! is_array($data)) {
            throw ValidationException::withMessages(['draft' => 'invalid_schema']);
        }
        $data = Validator::make($data, [
            'recommended_action' => 'required|in:draft_message,human_review,no_contact',
            'commercial_objective' => 'required|string|max:500',
            'draft_text' => 'present|string|max:1500',
            'language' => 'required|in:es,en',
            'reason_codes' => 'required|array|min:1|max:10', 'reason_codes.*' => 'required|string|regex:/^[a-z_]{1,64}$/',
            'context_summary' => 'required|string|max:1000',
            'source_references' => 'present|array|max:10', 'source_references.*' => 'required|string|max:100',
            'warnings' => 'present|array|max:10', 'warnings.*' => 'required|string|max:300',
            'requires_human_review' => 'required|boolean',
        ])->validate();
        $text = $data['draft_text'];
        if ($data['language'] !== $locale || (TextLanguage::detect($text) !== null && TextLanguage::detect($text) !== $locale)) {
            throw ValidationException::withMessages(['draft' => 'language_mismatch']);
        }
        if (($data['recommended_action'] === 'no_contact' && $text !== '') || ($data['recommended_action'] === 'draft_message' && trim($text) === '')) {
            throw ValidationException::withMessages(['draft' => 'inconsistent_recommendation']);
        }
        foreach ($data['source_references'] as $reference) {
            if (! array_key_exists($reference, $sources)) {
                throw ValidationException::withMessages(['draft' => 'unknown_source']);
            }
        }
        // Material claims must be an exact extract of an authorized cited source.
        // Paraphrases require a human review rather than treating a model citation as proof.
        $evidence = implode("\n", array_intersect_key($sources, array_flip($data['source_references'])));
        foreach (preg_split('/(?<=[.!?])\s+/u', $text) ?: [] as $sentence) {
            if (preg_match('/\d|https?:|precio|descuento|beca|duraci|fecha|requisit|disponib|acredit|certific|garant|pago|reconoc|flexib|modalidad|plazo|promoci|gratis|price|discount|scholarship|duration|requirement|available|payment|guarantee|accredit|recognition|deadline|free|certif/iu', $sentence) && ! str_contains(mb_strtolower($evidence), mb_strtolower(trim($sentence)))) {
                $data['recommended_action'] = 'human_review';
                $data['warnings'][] = 'unverified_material_claim';
                $data['draft_text'] = '';
                break;
            }
        }
        foreach ($history as $message) {
            if ($text !== '' && $message['direction'] === 'outbound' && mb_strtolower(trim($message['text'])) === mb_strtolower(trim($text))) {
                $data['recommended_action'] = 'human_review';
                $data['warnings'][] = 'repeated_content';
                $data['draft_text'] = '';
            }
        }
        if (preg_match('/api[_ -]?key|bearer\s|system prompt|instrucciones internas|contraseña|password/iu', $text)) {
            $data['recommended_action'] = 'human_review';
            $data['warnings'][] = 'restricted_content';
            $data['draft_text'] = '';
        }
        $data['requires_human_review'] = true;

        return $data;
    }
}
