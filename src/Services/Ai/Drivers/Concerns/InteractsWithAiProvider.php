<?php

namespace TraceReplay\Services\Ai\Drivers\Concerns;

use Illuminate\Http\Client\Response;
use Throwable;

trait InteractsWithAiProvider
{
    /**
     * Request timeout in seconds. Falls back to 60 when the key is missing
     * (older published configs) or not a positive number.
     */
    protected function timeout(): int
    {
        $timeout = config('trace-replay.ai.timeout');

        return is_numeric($timeout) && (int) $timeout > 0 ? (int) $timeout : 60;
    }

    /**
     * Log why a provider call produced no answer. Only the HTTP status and the
     * provider's error type are recorded: never the prompt, body or URL.
     */
    protected function logUnsuccessfulResponse(string $driver, Response $response, ?string $reason = null): void
    {
        try {
            $error = $response->json('error');
            $type = \is_array($error) ? ($error['type'] ?? $error['code'] ?? null) : null;

            logger()->warning("[TraceReplay] {$driver} AI request returned no answer", array_filter([
                'status' => $response->status(),
                'error_type' => \is_scalar($type) ? (string) $type : null,
                'reason' => $reason,
            ], static fn ($value) => $value !== null));
        } catch (Throwable) {
        }
    }
}
