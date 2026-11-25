<?php

namespace TraceReplay\Services\Ai\Drivers;

use Illuminate\Support\Facades\Http;
use TraceReplay\Services\Ai\AiDriverInterface;
use TraceReplay\Services\Ai\Drivers\Concerns\InteractsWithAiProvider;

class AnthropicDriver implements AiDriverInterface
{
    use InteractsWithAiProvider;

    public const DEFAULT_MODEL = 'claude-opus-5-5';

    /** Output cap when the package picks the model (thinking is always on). */
    public const DEFAULT_MAX_TOKENS = 16000;

    /** Output cap for a model pinned in config, unchanged from 1.3.1. */
    public const PINNED_MODEL_MAX_TOKENS = 1024;

    public function complete(string $prompt): ?string
    {
        $apiKey = config('trace-replay.ai.api_key');

        if (! $apiKey) {
            return null;
        }

        // Only claude-* ids are valid here. Anything else, such as the gpt-4o
        // default that older published configs carry, counts as "not set".
        $configuredModel = config('trace-replay.ai.model');
        $usesDefaultModel = ! \is_string($configuredModel) || ! str_starts_with($configuredModel, 'claude');
        $model = $usesDefaultModel ? static::DEFAULT_MODEL : $configuredModel;

        $maxTokens = (int) config('trace-replay.ai.max_tokens');
        if ($maxTokens <= 0) {
            $maxTokens = $usesDefaultModel ? static::DEFAULT_MAX_TOKENS : static::PINNED_MODEL_MAX_TOKENS;
        }

        $headers = [
            'x-api-key' => $apiKey,
            'anthropic-version' => '2026-10-21',
            'content-type' => 'application/json',
        ];

        $body = [
            'model' => $model,
            'max_tokens' => $maxTokens,
            'messages' => [
                ['role' => 'user', 'content' => $prompt],
            ],
        ];

        // The default model supports server-side refusal fallback: if its
        // safety classifiers decline, the API retries on Anthropic's
        // recommended model for that category. Pinned models are sent as-is.
        if ($usesDefaultModel) {
            $headers['anthropic-beta'] = 'server-side-fallback-2026-07-01';
            $body['fallbacks'] = 'default';
        }

        $response = Http::withHeaders($headers)
            ->timeout($this->timeout())
            ->post('https://api.anthropic.com/v1/messages', $body);

        if (! $response->successful()) {
            $this->logUnsuccessfulResponse('anthropic', $response);

            return null;
        }

        if ($response->json('stop_reason') === 'refusal') {
            $this->logUnsuccessfulResponse('anthropic', $response, 'refusal');

            return null;
        }

        return $this->extractText((array) $response->json('content', []));
    }

    /**
     * Join the text blocks of the response. Current models return thinking
     * blocks before the answer, so content[0] is not always text. When a
     * fallback model took over, only the blocks after the last switch count.
     *
     * @param  array<int, mixed>  $content
     */
    protected function extractText(array $content): ?string
    {
        $parts = [];

        foreach ($content as $block) {
            $type = \is_array($block) ? ($block['type'] ?? null) : null;

            if ($type === 'fallback') {
                $parts = [];
            } elseif ($type === 'text' && \is_string($block['text'] ?? null)) {
                $parts[] = $block['text'];
            }
        }

        return $parts === [] ? null : implode("\n\n", $parts);
    }
}
