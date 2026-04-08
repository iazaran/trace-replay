<?php

namespace TraceReplay\Services\Ai\Drivers;

use Illuminate\Support\Facades\Http;
use TraceReplay\Services\Ai\AiDriverInterface;

class OpenAiDriver implements AiDriverInterface
{
    public function complete(string $prompt): ?string
    {
        $apiKey = config('trace-replay.ai.api_key');
        $model = config('trace-replay.ai.model', 'gpt-4o');

        if (! $apiKey) {
            return null;
        }

        $response = Http::withToken($apiKey)
            ->timeout(60)
            ->post('https://api.openai.com/v1/chat/completions', [
                'model' => $model,
                'messages' => [
                    ['role' => 'user', 'content' => $prompt],
                ],
            ]);

        if ($response->successful()) {
            return $response->json('choices.0.message.content');
        }

        return null;
    }
}
