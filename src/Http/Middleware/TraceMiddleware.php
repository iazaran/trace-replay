<?php

namespace TraceReplay\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Throwable;
use TraceReplay\Facades\TraceReplay;
use TraceReplay\Services\PayloadMasker;

class TraceMiddleware
{
    public function handle(Request $request, Closure $next): SymfonyResponse
    {
        if (! config('trace-replay.enabled')) {
            return $next($request);
        }

        if ($this->shouldSkipInstrumentation($request)) {
            return $next($request);
        }

        if ($traceParent = $request->header('traceparent')) {
            TraceReplay::setTraceParent($traceParent);
        }

        // Request::path() returns '/' for the root URI, or 'foo/bar' (no leading slash) for others.
        $path = $request->path();
        $uri = $path === '/' ? '/' : '/'.$path;
        $trace = TraceReplay::start('HTTP '.strtoupper($request->method()).' '.$uri, [], 'http');

        if (! $trace) {
            return $next($request);
        }

        $masker = app(PayloadMasker::class);

        // Capture the full request payload on the HTTP step
        $requestPayload = [
            'method' => $request->method(),
            'uri' => $uri,
            'full_url' => $masker->maskUrl($request->fullUrl()),
            'host' => $request->getSchemeAndHttpHost(),
            'headers' => $masker->mask($request->headers->all()),
            'body' => $masker->mask($request->all()),
            'query' => $masker->mask($request->query->all()),
        ];

        try {
            /** @var SymfonyResponse $response */
            $response = TraceReplay::step('HTTP Request', fn () => $next($request), [
                'request_payload' => $requestPayload,
            ]);

            return $response;
        } catch (Throwable $e) {
            // Capture exception at trace level for proper error reporting
            TraceReplay::captureException($e);
            throw $e;
        }
    }

    public function terminate(Request $request, SymfonyResponse $response): void
    {
        if (! config('trace-replay.enabled') || $this->shouldSkipInstrumentation($request)) {
            return;
        }

        if (! TraceReplay::getCurrentTrace()) {
            return;
        }

        $httpStatus = $response->getStatusCode();
        $status = ($httpStatus >= 400) ? 'error' : 'success';

        // Capture response on the last step
        $masker = app(PayloadMasker::class);

        $responsePayload = [
            'status' => $httpStatus,
            'headers' => $masker->mask($response->headers->all()),
        ];

        // Try to decode JSON body; fall back to truncated text.
        $maxSize = (int) config('trace-replay.max_payload_size', 65536);
        try {
            $content = $response->getContent();
        } catch (Throwable) {
            $content = false;
        }

        if ($content === false) {
            $responsePayload['body'] = '[TraceReplay: Response body unavailable for streamed or binary response]';
            TraceReplay::captureResponseOnLastStep($responsePayload, $httpStatus);
            TraceReplay::end($status);

            return;
        }

        if (strlen($content) > $maxSize) {
            $content = substr($content, 0, $maxSize)."\n\n[TraceReplay: Payload truncated for size]";
        }

        $decoded = json_decode($content, true);
        $responsePayload['body'] = (json_last_error() === JSON_ERROR_NONE && is_array($decoded))
            ? $masker->mask($decoded)
            : $content;

        TraceReplay::captureResponseOnLastStep($responsePayload, $httpStatus);
        TraceReplay::end($status);
    }

    protected function shouldSkipInstrumentation(Request $request): bool
    {
        if ($request->headers->has('X-TraceReplay-Skip')) {
            return true;
        }

        $routeName = $request->route()?->getName();
        if ($routeName && str_starts_with($routeName, 'trace-replay.')) {
            return true;
        }

        $path = ltrim($request->path(), '/');
        $prefixes = [
            config('trace-replay.route_prefix', 'trace-replay'),
            config('trace-replay.api.route_prefix', 'api/trace-replay'),
        ];

        foreach ($prefixes as $prefix) {
            $prefix = trim((string) $prefix, '/');

            if ($prefix !== '' && ($path === $prefix || str_starts_with($path, $prefix.'/'))) {
                return true;
            }
        }

        return false;
    }
}
