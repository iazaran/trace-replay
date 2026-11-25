<?php

namespace TraceReplay\Services\Ai\Drivers;

use Illuminate\Support\Facades\Http;
use TraceReplay\Services\Ai\AiDriverInterface;
use TraceReplay\Services\Ai\Drivers\Concerns\InteractsWithAiProvider;

class OpenAiDriver implements AiDriverInterface
{
    use InteractsWithAiProvider;

    public const DEFAULT_MODEL = 'gpt-4o';

    public function complete(string $prompt): ?string
    {
        $apiKey = config('trace-replay.ai.api_key');
        $model = config('trace-replay.ai.model') ?: static::DEFAULT_MODEL;
        $baseUrl = rtrim((string) (config('trace-replay.ai.base_url') ?: 'https://api.openai.com/v1'), '/');

        if (! $apiKey) {
            return null;
        }

        $response = Http::withToken($apiKey)
            ->timeout($this->timeout())
            ->post($baseUrl.'/chat/completions', [
                'model' => $model,
                'messages' => [
                    ['role' => 'user', 'content' => $prompt],
                ],
            ]);

        if ($response->successful()) {
            return $response->json('choices.0.message.content');
        }

        $this->logUnsuccessfulResponse('openai', $response);

        return null;
    }
}
