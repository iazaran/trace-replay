<?php

namespace TraceReplay\Services\Ai\Drivers;

use Illuminate\Support\Facades\Http;
use TraceReplay\Services\Ai\AiDriverInterface;
use TraceReplay\Services\Ai\Drivers\Concerns\InteractsWithAiProvider;

class AnthropicDriver implements AiDriverInterface
{
    use InteractsWithAiProvider;

    public const DEFAULT_MODEL = 'claude-opus-5-5';

    /**
     * Output cap for models that think by default (Claude 5 generation, Fable,
     * Mythos): thinking counts against max_tokens, so 1,024 leaves no answer.
     */
    public const THINKING_MODEL_MAX_TOKENS = 16000;

    /** Output cap for earlier models, unchanged from 1.3.1. */
    public const LEGACY_MODEL_MAX_TOKENS = 1024;

    /**
     * Models that accept the server-side refusal fallback
     * (beta "server-side-fallback-2026-07-01", `fallbacks: "default"`).
     *
     * @var array<int, string>
     */
    public const FALLBACK_MODELS = [
        'claude-fable-5-2',
        'claude-fable-5-1',
        'claude-opus-5-5',
        'claude-opus-5',
        'claude-sonnet-5-5',
    ];

    public function complete(string $prompt): ?string
    {
        $apiKey = config('trace-replay.ai.api_key');

        if (! $apiKey) {
            return null;
        }

        $model = $this->resolveModel(config('trace-replay.ai.model'));

        $maxTokens = (int) config('trace-replay.ai.max_tokens');
        if ($maxTokens <= 0) {
            $maxTokens = $this->thinksByDefault($model)
                ? static::THINKING_MODEL_MAX_TOKENS
                : static::LEGACY_MODEL_MAX_TOKENS;
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

        // If the model's safety classifiers decline, let the API retry on
        // Anthropic's recommended model for that refusal category.
        if (\in_array($model, static::FALLBACK_MODELS, true)) {
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

        $stopReason = $response->json('stop_reason');

        if ($stopReason === 'refusal') {
            $this->logUnsuccessfulResponse('anthropic', $response, 'refusal');

            return null;
        }

        $text = $this->extractText((array) $response->json('content', []));

        // Hitting max_tokens returns a partial answer (or none, when thinking
        // used the whole budget): keep what there is, but say why.
        if ($stopReason === 'max_tokens' || $text === null) {
            $this->logUnsuccessfulResponse('anthropic', $response, $stopReason === 'max_tokens' ? 'max_tokens' : 'no_text');
        }

        return $text;
    }

    /**
     * Only claude-* ids are valid for this API. Anything else, such as the
     * gpt-4o default that configs published before 1.3.2 carry, counts as
     * unset and is replaced by the default model.
     */
    protected function resolveModel(mixed $configured): string
    {
        if (\is_string($configured) && str_starts_with($configured, 'claude')) {
            return $configured;
        }

        if (\is_string($configured) && trim($configured) !== '') {
            try {
                logger()->warning('[TraceReplay] anthropic AI driver ignored a non-Claude model; using the default', [
                    'configured_model' => $configured,
                    'model' => static::DEFAULT_MODEL,
                ]);
            } catch (\Throwable) {
            }
        }

        return static::DEFAULT_MODEL;
    }

    /**
     * Claude 5 generation models (Opus/Sonnet/Haiku 5.x) and Fable/Mythos run
     * with thinking on unless told otherwise.
     */
    protected function thinksByDefault(string $model): bool
    {
        return (bool) preg_match('/^claude-(fable|mythos)-|^claude-(opus|sonnet|haiku)-5(\b|-)/', $model);
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
