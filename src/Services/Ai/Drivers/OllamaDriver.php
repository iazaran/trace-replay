<?php

namespace TraceReplay\Services\Ai\Drivers;

use Illuminate\Support\Facades\Http;
use TraceReplay\Services\Ai\AiDriverInterface;
use TraceReplay\Services\Ai\Drivers\Concerns\InteractsWithAiProvider;

class OllamaDriver implements AiDriverInterface
{
    use InteractsWithAiProvider;

    public const DEFAULT_URL = 'http://localhost:11434/api/generate';

    public const DEFAULT_MODEL = 'llama3';

    public function complete(string $prompt): ?string
    {
        $url = config('trace-replay.ai.base_url') ?: static::DEFAULT_URL;
        $model = config('trace-replay.ai.model') ?: static::DEFAULT_MODEL;

        $response = Http::timeout($this->timeout())
            ->post($url, [
                'model' => $model,
                'prompt' => $prompt,
                'stream' => false,
            ]);

        if ($response->successful()) {
            return $response->json('response');
        }

        $this->logUnsuccessfulResponse('ollama', $response);

        return null;
    }
}
