<?php

namespace TraceReplay\Services\Ai\Drivers;

use Illuminate\Support\Facades\Http;
use TraceReplay\Services\Ai\AiDriverInterface;

class AnthropicDriver implements AiDriverInterface
{
    public function complete(string $prompt): ?string
    {
        $apiKey = config('trace-replay.ai.api_key');
        $model = config('trace-replay.ai.model', 'claude-3-5-sonnet-latest');

        if (! $apiKey) {
            return null;
        }

        $response = Http::withHeaders([
            'x-api-key' => $apiKey,
            'anthropic-version' => '2023-06-01',
            'content-type' => 'application/json',
        ])
            ->timeout(60)
            ->post('https://api.anthropic.com/v1/messages', [
                'model' => $model,
                'max_tokens' => 1024,
                'messages' => [
                    ['role' => 'user', 'content' => $prompt],
                ],
            ]);

        if ($response->successful()) {
            return $response->json('content.0.text');
        }

        return null;
    }
}
