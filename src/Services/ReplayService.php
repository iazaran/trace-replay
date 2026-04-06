<?php

namespace TraceReplay\Services;

use Illuminate\Support\Facades\Http;
use TraceReplay\Models\Trace;

class ReplayService
{
    public function __construct(protected PayloadMasker $masker) {}

    public function replay(Trace $trace, ?string $overrideUrl = null): array
    {
        // Prefer the dedicated 'HTTP Request' step; fall back to the first step with a payload
        $initialStep = $trace->steps()
            ->where('label', 'HTTP Request')
            ->whereNotNull('request_payload')
            ->first()
            ?? $trace->steps()->whereNotNull('request_payload')->first();

        if (! $initialStep || empty($initialStep->request_payload)) {
            throw new \Exception('Cannot replay trace: No request payload found on any step.');
        }

        $payload = $initialStep->request_payload;
        $method = strtoupper($payload['method'] ?? 'GET');
        $uri = $payload['uri'] ?? '/';
        $headers = $payload['headers'] ?? [];
        $body = $payload['body'] ?? [];
        $query = $payload['query'] ?? [];

        // Remove host headers so they don't interfere with the target
        unset($headers['host'], $headers['Host']);

        $baseUrl = $overrideUrl ?? config('trace-replay.replay.default_base_url');
        $targetUrl = rtrim($baseUrl, '/').'/'.ltrim($uri, '/');

        // Symfony normalises all header names to lowercase, so 'Content-Type' never exists
        // in the stored headers array — only 'content-type' does.
        $isJson = str_contains($headers['content-type'][0] ?? '', 'json');

        $response = Http::withHeaders($headers)
            ->timeout((int) config('trace-replay.replay.timeout', 30))
            ->withQueryParameters($query)
            ->send($method, $targetUrl, $isJson ? ['json' => $body] : ['form_params' => $body]);

        $replayBody = $response->json() ?? $response->body();

        $replayResponsePayload = $this->masker->mask([
            'status' => $response->status(),
            'headers' => $response->headers(),
            'body' => $replayBody,
        ]);

        $originalResponsePayload = $this->masker->mask($initialStep->response_payload ?? []);

        $originalBody = $originalResponsePayload['body'] ?? $originalResponsePayload;
        $replayBody2 = $replayResponsePayload['body'] ?? $replayResponsePayload;

        return [
            'original' => $originalResponsePayload,
            'replay' => $replayResponsePayload,
            'diff' => (\is_array($originalBody) && \is_array($replayBody2))
                ? $this->generateDiff($originalBody, $replayBody2)
                : ['status' => $originalBody === $replayBody2 ? 'unchanged' : 'changed', 'original' => $originalBody, 'replay' => $replayBody2],
        ];
    }

    protected function generateDiff(array $original, array $replay): array
    {
        // Simple manual structural diffing
        $diff = [];

        foreach ($original as $key => $value) {
            if (! array_key_exists($key, $replay)) {
                $diff[$key] = ['status' => 'removed', 'original' => $value];
            } elseif ($replay[$key] !== $value) {
                if (is_array($value) && is_array($replay[$key])) {
                    $diff[$key] = $this->generateDiff($value, $replay[$key]);
                } else {
                    $diff[$key] = ['status' => 'changed', 'original' => $value, 'replay' => $replay[$key]];
                }
            } else {
                $diff[$key] = ['status' => 'unchanged', 'value' => $value];
            }
        }

        foreach ($replay as $key => $value) {
            if (! array_key_exists($key, $original)) {
                $diff[$key] = ['status' => 'added', 'replay' => $value];
            }
        }

        return $diff;
    }
}
