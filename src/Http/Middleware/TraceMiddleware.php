<?php

namespace TraceReplay\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use TraceReplay\Facades\TraceReplay;
use TraceReplay\Services\PayloadMasker;

class TraceMiddleware
{
    public function handle(Request $request, Closure $next): SymfonyResponse
    {
        if (! config('trace-replay.enabled')) {
            return $next($request);
        }

        // Respect sampling rate
        $sampleRate = (float) config('trace-replay.sample_rate', 1.0);
        if ($sampleRate < 1.0 && mt_rand() / mt_getrandmax() > $sampleRate) {
            return $next($request);
        }

        // Skip trace-replay dashboard routes to avoid recursive tracing
        if (str_starts_with($request->path(), 'trace-replay') || str_starts_with($request->path(), 'api/trace-replay')) {
            return $next($request);
        }

        $masker = app(PayloadMasker::class);
        $reqBody = $masker->mask($request->all());

        // Request::path() returns '/' for the root URI, or 'foo/bar' (no leading slash) for others.
        $path = $request->path();
        $uri = $path === '/' ? '/' : '/'.$path;
        $trace = TraceReplay::start('HTTP '.strtoupper($request->method()).' '.$uri);

        if (! $trace) {
            return $next($request);
        }

        // Capture the full request payload on the HTTP step
        $requestPayload = [
            'method' => $request->method(),
            'uri' => $uri,
            'headers' => $masker->mask($request->headers->all()),
            'body' => $reqBody,
            'query' => $masker->mask($request->query->all()),
        ];

        /** @var SymfonyResponse $response */
        $response = TraceReplay::step('HTTP Request', fn () => $next($request), [
            'request_payload' => $requestPayload,
        ]);

        return $response;
    }

    public function terminate(Request $request, SymfonyResponse $response): void
    {
        if (! config('trace-replay.enabled')) {
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

        // Try to decode JSON body; fall back to truncated text
        $content = $response->getContent();
        $decoded = json_decode($content, true);
        $responsePayload['body'] = json_last_error() === JSON_ERROR_NONE
            ? $masker->mask($decoded ?? [])
            : substr($content, 0, 2000);

        TraceReplay::captureResponseOnLastStep($responsePayload, $httpStatus);
        TraceReplay::end($status);
    }
}
