<?php

namespace TraceReplay\Services\Ai\Drivers;

use Illuminate\Support\Facades\Http;
use TraceReplay\Services\Ai\AiDriverInterface;

class OllamaDriver implements AiDriverInterface
{
    public const DEFAULT_URL = 'http://localhost:11434/api/generate';

    public const DEFAULT_MODEL = 'llama3';

    public function complete(string $prompt): ?string
    {
        $url = $this->resolveUrl(config('trace-replay.ai.base_url'));
        $model = config('trace-replay.ai.model') ?: static::DEFAULT_MODEL;

        $response = Http::timeout((int) (config('trace-replay.ai.timeout') ?: 120))
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

    /**
     * Accept either the full generate endpoint or just the Ollama host
     * (e.g. http://localhost:11434), which gets /api/generate appended.
     */
    protected function resolveUrl(mixed $baseUrl): string
    {
        if (! \is_string($baseUrl) || trim($baseUrl) === '') {
            return static::DEFAULT_URL;
        }

        $baseUrl = trim($baseUrl);
        $path = parse_url($baseUrl, PHP_URL_PATH);

        if ($path === null || $path === false || trim($path, '/') === '') {
            return rtrim($baseUrl, '/').'/api/generate';
        }

        return $baseUrl;
    }
}
