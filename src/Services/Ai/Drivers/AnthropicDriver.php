<?php

namespace TraceReplay\Services\Ai\Drivers;

use Illuminate\Support\Facades\Http;
use TraceReplay\Services\Ai\AiDriverInterface;

class AnthropicDriver implements AiDriverInterface
{
    public const DEFAULT_MODEL = 'claude-opus-5-5';

    /**
     * Models that accept the server-side refusal fallback
     * (beta "server-side-fallback-2026-07-01", `fallbacks: "default"`).
     *
     * @var array<int, string>
     */
    protected const FALLBACK_MODELS = [
        'claude-fable-5-2',
        'claude-fable-5-1',
        'claude-opus-5-5',
        'claude-opus-5',
        'claude-sonnet-5-5',
    ];

    public function complete(string $prompt): ?string
    {
        $apiKey = config('trace-replay.ai.api_key');
        $model = config('trace-replay.ai.model') ?: static::DEFAULT_MODEL;

        if (! $apiKey) {
            return null;
        }

        $headers = [
            'x-api-key' => $apiKey,
            'anthropic-version' => '2026-10-21',
            'content-type' => 'application/json',
        ];

        $body = [
            'model' => $model,
            'max_tokens' => 16000,
            'messages' => [
                ['role' => 'user', 'content' => $prompt],
            ],
        ];

        // On a safety-classifier decline, let the API retry on the model
        // Anthropic recommends for that refusal category.
        if (\in_array($model, static::FALLBACK_MODELS, true)) {
            $headers['anthropic-beta'] = 'server-side-fallback-2026-07-01';
            $body['fallbacks'] = 'default';
        }

        $response = Http::withHeaders($headers)
            ->timeout((int) (config('trace-replay.ai.timeout') ?: 120))
            ->post('https://api.anthropic.com/v1/messages', $body);

        if (! $response->successful() || $response->json('stop_reason') === 'refusal') {
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
