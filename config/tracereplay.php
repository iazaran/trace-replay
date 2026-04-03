<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Enable / Disable TraceReplay
    |--------------------------------------------------------------------------
    | Set TRACEREPLAY_ENABLED=false in production .env to completely disable
    | all tracing with zero overhead.
    */
    'enabled' => env('TRACEREPLAY_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Sampling Rate
    |--------------------------------------------------------------------------
    | A float between 0.0 and 1.0 controlling what fraction of HTTP requests
    | are traced. 1.0 = trace every request, 0.1 = trace 10% at random.
    | Manual TraceReplay::start() calls are never sampled.
    */
    'sample_rate' => env('TRACEREPLAY_SAMPLE_RATE', 1.0),

    /*
    |--------------------------------------------------------------------------
    | Multi-Tenant / Project ID
    |--------------------------------------------------------------------------
    | Optionally set a static project UUID, or override determineProjectId()
    | in a custom TraceReplayManager binding for dynamic multi-tenancy.
    */
    'project_id' => env('TRACEREPLAY_PROJECT_ID', null),

    /*
    |--------------------------------------------------------------------------
    | Storage & Queueing
    |--------------------------------------------------------------------------
    | When queue.enabled is true, step persistence is offloaded to a queue
    | worker to avoid adding latency to the request lifecycle.
    */
    'queue' => [
        'enabled' => env('TRACEREPLAY_QUEUE_ENABLED', false),
        'connection' => env('TRACEREPLAY_QUEUE_CONNECTION', env('QUEUE_CONNECTION', 'sync')),
        'queue' => env('TRACEREPLAY_QUEUE_NAME', 'default'),
    ],

    /*
    |--------------------------------------------------------------------------
    | DB Query Tracking
    |--------------------------------------------------------------------------
    | When enabled, each step records the number and total time of DB queries
    | executed within the step closure.
    */
    'track_db_queries' => env('TRACEREPLAY_TRACK_DB', true),

    /*
    |--------------------------------------------------------------------------
    | Data Masking
    |--------------------------------------------------------------------------
    | Fields whose values will be replaced with '********' in all captured
    | payloads (request bodies, response bodies, state snapshots).
    */
    'mask_fields' => [
        'password',
        'password_confirmation',
        'token',
        'api_key',
        'authorization',
        'secret',
        'credit_card',
        'cvv',
        'ssn',
        'private_key',
    ],

    /*
    |--------------------------------------------------------------------------
    | Replay Engine
    |--------------------------------------------------------------------------
    */
    'replay' => [
        'default_base_url' => env('TRACEREPLAY_REPLAY_URL', env('APP_URL', 'http://localhost')),
        'timeout' => env('TRACEREPLAY_REPLAY_TIMEOUT', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Retention / Auto-Pruning
    |--------------------------------------------------------------------------
    | Traces older than `retention_days` will be deleted by the artisan command:
    |   php artisan tracereplay:prune
    | Set to null to disable pruning.
    */
    'retention_days' => env('TRACEREPLAY_RETENTION_DAYS', 30),

    /*
    |--------------------------------------------------------------------------
    | Dashboard Route Middleware
    |--------------------------------------------------------------------------
    | Protect the TraceReplay dashboard. For production use, add 'auth' or a
    | custom gate middleware, e.g. ['web', 'auth', 'can:view-tracereplay'].
    */
    'middleware' => ['web'],
    'api_middleware' => ['api'],

    /*
    |--------------------------------------------------------------------------
    | Dashboard IP Allowlist
    |--------------------------------------------------------------------------
    | When non-empty, only requests from these IP addresses can access the
    | dashboard. CIDR notation is not evaluated — exact match only.
    | Leave empty to allow all IPs (rely on middleware for auth instead).
    */
    'allowed_ips' => array_filter(explode(',', env('TRACEREPLAY_ALLOWED_IPS', ''))),

    /*
    |--------------------------------------------------------------------------
    | Failure Notifications
    |--------------------------------------------------------------------------
    | When on_failure is true and a trace ends with status=error, a
    | notification is dispatched via the configured channels.
    */
    'notifications' => [
        'on_failure' => env('TRACEREPLAY_NOTIFY_ON_FAILURE', false),
        'channels' => ['mail'],           // 'mail', 'slack'
        'mail' => [
            'to' => env('TRACEREPLAY_NOTIFY_EMAIL', null),
        ],
        'slack' => [
            'webhook_url' => env('TRACEREPLAY_SLACK_WEBHOOK', null),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | AI Integration (Optional)
    |--------------------------------------------------------------------------
    | When openai_api_key is set, the "AI Fix" button in the dashboard will
    | call the OpenAI API directly and stream the response. When null, users
    | receive a copyable prompt instead (no external call is made).
    */
    'ai' => [
        'openai_api_key' => env('TRACEREPLAY_OPENAI_KEY', null),
        'model' => env('TRACEREPLAY_OPENAI_MODEL', 'gpt-4o'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Auto-Tracing: Jobs & Artisan Commands
    |--------------------------------------------------------------------------
    | When enabled, queued jobs and artisan commands are automatically wrapped
    | in traces without any manual instrumentation.
    */
    'auto_trace' => [
        'jobs' => env('TRACEREPLAY_AUTO_TRACE_JOBS', true),
        'commands' => env('TRACEREPLAY_AUTO_TRACE_COMMANDS', false),
        // Artisan commands to exclude from auto-tracing (exact names)
        'exclude_commands' => [
            'queue:work', 'queue:listen', 'horizon', 'schedule:run',
            'schedule:work', 'tracereplay:prune', 'tracereplay:export',
        ],
    ],
];
