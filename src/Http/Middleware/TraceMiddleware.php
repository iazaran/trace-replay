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

        // Recommendation 27: Skip trace-replay dashboard routes reliably by name
        if ($request->route() && str_starts_with($request->route()->getName() ?? '', 'trace-replay.')) {
            return $next($request);
        }

        $masker = app(PayloadMasker::class);
        $reqBody = $masker->mask($request->all());

        // W3C Trace Context propagation (Recommendation 17)
        if ($traceParent = $request->header('traceparent')) {
            TraceReplay::setTraceParent($traceParent);
        }

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

        // Try to decode JSON body; fall back to truncated text (Recommendation 28)
        $maxSize = (int) config('trace-replay.max_payload_size', 65536);
        $content = $response->getContent();

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
}
