<?php

namespace TraceReplay\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use TraceReplay\Models\Trace;

class NotificationService
{
    public function notifyFailure(Trace $trace): void
    {
        $channels = config('tracereplay.notifications.channels', []);

        foreach ($channels as $channel) {
            match ($channel) {
                'mail'  => $this->sendMail($trace),
                'slack' => $this->sendSlack($trace),
                default => null,
            };
        }
    }

    protected function sendMail(Trace $trace): void
    {
        $to = config('tracereplay.notifications.mail.to');
        if (!$to) {
            return;
        }

        $errorStep   = $trace->error_step;
        $dashboardUrl = rtrim(config('app.url', ''), '/') . '/tracereplay/traces/' . $trace->id;

        $subject = "[TraceReplay] Trace Failed: {$trace->name}";
        $body    = $this->buildEmailBody($trace, $errorStep, $dashboardUrl);

        Mail::raw($body, function ($message) use ($to, $subject) {
            $message->to($to)->subject($subject);
        });
    }

    protected function sendSlack(Trace $trace): void
    {
        $webhookUrl = config('tracereplay.notifications.slack.webhook_url');
        if (!$webhookUrl) {
            return;
        }

        $errorStep   = $trace->error_step;
        $dashboardUrl = rtrim(config('app.url', ''), '/') . '/tracereplay/traces/' . $trace->id;

        $payload = [
            'text'        => ":red_circle: *TraceReplay — Trace Failed*",
            'attachments' => [
                [
                    'color'  => 'danger',
                    'fields' => [
                        ['title' => 'Trace',    'value' => $trace->name,               'short' => true],
                        ['title' => 'Status',   'value' => 'Failed',                   'short' => true],
                        ['title' => 'Duration', 'value' => ($trace->duration_ms ?? 0) . ' ms','short' => true],
                        ['title' => 'Failed At','value' => $errorStep?->label ?? 'N/A','short' => true],
                        ['title' => 'Error',    'value' => substr($errorStep?->error_reason ?? 'Unknown', 0, 300)],
                        ['title' => 'Dashboard','value' => $dashboardUrl],
                    ],
                    'footer' => 'TraceReplay | ' . config('app.name'),
                    'ts'     => time(),
                ],
            ],
        ];

        Http::post($webhookUrl, $payload);
    }

    private function buildEmailBody(Trace $trace, $errorStep, string $dashboardUrl): string
    {
        $lines = [
            "TraceReplay has detected a failed trace in your Laravel application.",
            "",
            "Trace:    {$trace->name}",
            "ID:       {$trace->id}",
            "Duration: " . ($trace->duration_ms ?? 0) . " ms",
            "Failed At: " . ($errorStep?->label ?? 'Unknown'),
            "",
            "Error:",
            $errorStep?->error_reason ?? 'No error details captured.',
            "",
            "View in Dashboard:",
            $dashboardUrl,
        ];

        return implode("\n", $lines);
    }
}

