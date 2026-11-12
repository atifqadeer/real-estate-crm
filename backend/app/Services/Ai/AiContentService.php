<?php
namespace App\Services\Ai;

use App\Models\AiContentGeneration;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

/**
 * AI content assistant (prompt section 10). Provider-agnostic: any
 * OpenAI-compatible chat-completions endpoint via config
 * (AI_API_KEY / AI_API_URL / AI_MODEL). With no key configured the
 * assistant is honestly unavailable — it never fabricates output.
 *
 * Guardrails from the prompt: the model is instructed to use ONLY the
 * facts the user supplies (never invent features, prices, approvals,
 * legal status, returns or availability), and every suggestion is
 * marked AI-generated and returns as a draft for human review — this
 * service has no path to publishing.
 */
class AiContentService
{
    public function configured(): bool
    {
        return !empty(config('services.ai.key'));
    }

    public function suggest(Organization $organization, User $user, array $input): array
    {
        if (!$this->configured()) {
            throw ValidationException::withMessages(['ai' =>
                'AI assistant is not configured on this server. Set AI_API_KEY (and optionally AI_API_URL / AI_MODEL) in the server .env to enable it.']);
        }
        $platform = $input['platform'] ?? 'facebook';
        $language = $input['language'] ?? 'en';
        $facts = collect($input['property'] ?? [])
            ->filter(fn ($v) => $v !== null && $v !== '')
            ->map(fn ($v, $k) => "- {$k}: {$v}")->implode("\n");
        $objective = $input['objective'] ?? 'Promote this property to potential buyers.';
        $langInstruction = $language === 'ur'
            ? 'Write the captions in Urdu (Urdu script). Hashtags may stay in English.'
            : 'Write the captions in English.';

        $system = 'You are a social media copywriter for a Pakistani real estate marketing agency. '
            . 'STRICT RULES: Use ONLY the property facts provided. Never invent or guess prices, features, amenities, approvals, legal/NOC status, possession, returns, discounts or availability that are not in the facts. '
            . 'If a fact is missing, write around it instead of making it up. '
            . 'Return ONLY valid JSON with this shape: {"variations":[{"caption":"...","hashtags":["#...", "..."],"cta":"..."}, ...]} with exactly 3 variations.';
        $prompt = "Platform: {$platform}\nMarketing objective: {$objective}\n{$langInstruction}\nProperty facts:\n{$facts}";

        $base = rtrim((string) config('services.ai.url', 'https://api.openai.com/v1'), '/');
        $model = (string) config('services.ai.model', 'gpt-4o-mini');
        $response = Http::withToken((string) config('services.ai.key'))->acceptJson()
            ->post("{$base}/chat/completions", [
                'model' => $model,
                'response_format' => ['type' => 'json_object'],
                'messages' => [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => $prompt],
                ],
            ]);
        if ($response->failed()) {
            throw ValidationException::withMessages(['ai' =>
                'The AI provider rejected the request (' . $response->status() . '). Check AI_API_KEY / AI_MODEL configuration.']);
        }
        $content = $response->json('choices.0.message.content');
        $decoded = is_string($content) ? json_decode($content, true) : null;
        $variations = $decoded['variations'] ?? null;
        if (!is_array($variations) || $variations === []) {
            throw ValidationException::withMessages(['ai' => 'The AI provider returned an unexpected format. Please try again.']);
        }
        $suggestions = collect($variations)->map(fn ($v) => [
            'caption' => (string) ($v['caption'] ?? ''),
            'hashtags' => array_values(array_map('strval', $v['hashtags'] ?? [])),
            'cta' => (string) ($v['cta'] ?? ''),
            'ai_generated' => true,
            'needs_review' => true,
        ])->all();

        $record = AiContentGeneration::create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'platform' => $platform,
            'language' => $language,
            'model' => $model,
            'inputs' => $input,
            'suggestions' => $suggestions,
            'created_at' => now(),
        ]);
        return ['generation_id' => $record->id, 'model' => $model, 'suggestions' => $suggestions];
    }
}
