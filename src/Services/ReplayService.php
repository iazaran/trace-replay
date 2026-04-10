<?php

namespace TraceReplay\Services;

use Illuminate\Support\Facades\Http;
use TraceReplay\Models\Trace;
use TraceReplay\Models\TraceStep;

class ReplayService
{
    public function __construct(protected PayloadMasker $masker) {}

    public function replay(Trace $trace, ?string $overrideUrl = null): array
    {
        $initialStep = $this->resolveInitialHttpStep($trace);

        $payload = $initialStep->request_payload;
        $method = strtoupper($payload['method'] ?? 'GET');

        $mutatingMethods = ['POST', 'PUT', 'PATCH', 'DELETE'];
        if (in_array($method, $mutatingMethods, true) && ! config('trace-replay.replay.allow_mutating_methods', false)) {
            throw new \Exception("Replaying mutating methods ({$method}) is disabled for safety. Enable 'replay.allow_mutating_methods' in config to override.");
        }

        $uri = $payload['uri'] ?? '/';
        $headers = $payload['headers'] ?? [];
        $body = $payload['body'] ?? [];
        $query = $payload['query'] ?? [];
        $baseUrl = $this->determineBaseUrl($payload, $overrideUrl);

        if (! $baseUrl) {
            throw new \Exception("Cannot determine target host for replay. Set TRACE_REPLAY_REPLAY_URL or pass an override_url.");
        }

        unset($headers['host'], $headers['Host'], $headers['cookie'], $headers['Cookie']);

        $targetUrl = $this->buildTargetUrl($uri, $baseUrl, $query);

        $isJson = str_contains($headers['content-type'][0] ?? '', 'json');

        $options = [];
        if (! in_array($method, ['GET', 'HEAD', 'OPTIONS'], true) && ! empty($body)) {
            $options = $isJson ? ['json' => $body] : ['form_params' => $body];
        }

        $normalizedHeaders = $this->normalizeHeaders($headers);
        $normalizedHeaders['X-TraceReplay-Skip'] = '1';
        $normalizedHeaders['X-TraceReplay-Origin-Trace'] = $trace->id;

        $response = Http::withHeaders($normalizedHeaders)
            ->timeout((int) config('trace-replay.replay.timeout', 30))
            ->send($method, $targetUrl, $options);

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

    protected function resolveInitialHttpStep(Trace $trace): TraceStep
    {
        $initialStep = $trace->steps()
            ->where('label', 'HTTP Request')
            ->whereNotNull('request_payload')
            ->first()
            ?? $trace->steps()->whereNotNull('request_payload')->first();

        if (! $initialStep || empty($initialStep->request_payload)) {
            throw new \Exception('Cannot replay trace: No request payload found on any step.');
        }

        return $initialStep;
    }

    protected function determineBaseUrl(array $payload, ?string $overrideUrl): ?string
    {
        if ($overrideUrl) {
            return $overrideUrl;
        }

        // First, try to use the host from the recorded payload (preserves original port)
        if (! empty($payload['host'])) {
            return $payload['host'];
        }

        // Fallback: extract from full_url if host wasn't recorded
        if (! empty($payload['full_url'])) {
            $parts = parse_url($payload['full_url']);
            if ($parts && isset($parts['scheme'], $parts['host'])) {
                $port = isset($parts['port']) ? ':'.$parts['port'] : '';

                return "{$parts['scheme']}://{$parts['host']}{$port}";
            }
        }

        // Last resort: use configured default base URL
        if ($configured = config('trace-replay.replay.default_base_url')) {
            return $configured;
        }

        return null;
    }

    protected function buildTargetUrl(string $uri, string $baseUrl, array $query): string
    {
        $target = filter_var($uri, FILTER_VALIDATE_URL)
            ? $uri
            : rtrim($baseUrl, '/').'/'.ltrim($uri, '/');

        if (! empty($query)) {
            $separator = str_contains($target, '?') ? '&' : '?';
            $target .= $separator.http_build_query($query);
        }

        return $target;
    }

    protected function normalizeHeaders(array $headers): array
    {
        $normalized = [];

        foreach ($headers as $name => $value) {
            $normalized[$name] = is_array($value) ? implode(', ', $value) : $value;
        }

        return $normalized;
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
                    // Handle scalar vs array type changes (Recommendation 37)
                    $diff[$key] = [
                        'status' => is_array($value) !== is_array($replay[$key]) ? 'type_changed' : 'changed',
                        'original' => $value,
                        'replay' => $replay[$key],
                    ];

                    if (is_array($value) !== is_array($replay[$key])) {
                        $diff[$key]['original_type'] = gettype($value);
                        $diff[$key]['replay_type'] = gettype($replay[$key]);
                    }
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
