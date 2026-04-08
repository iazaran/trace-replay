<?php

namespace TraceReplay\Services\Ai\Drivers;

use Illuminate\Support\Facades\Http;
use TraceReplay\Services\Ai\AiDriverInterface;

class OllamaDriver implements AiDriverInterface
{
    public function complete(string $prompt): ?string
    {
        $url = config('trace-replay.ai.base_url', 'http://localhost:11434/api/generate');
        $model = config('trace-replay.ai.model', 'llama3');

        $response = Http::timeout(60)
            ->post($url, [
                'model' => $model,
                'prompt' => $prompt,
                'stream' => false,
            ]);

        if ($response->successful()) {
            return $response->json('response');
        }

        return null;
    }
}
