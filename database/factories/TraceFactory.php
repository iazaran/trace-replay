<?php

namespace TraceReplay\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use TraceReplay\Models\Trace;

class TraceFactory extends Factory
{
    protected $model = Trace::class;

    public function definition(): array
    {
        $statuses = ['success', 'error', 'processing'];
        $status   = $statuses[array_rand($statuses)];

        return [
            'name'         => implode(' ', array_map(fn() => fake()->word(), range(1, 3))),
            'status'       => $status,
            'http_status'  => $status === 'success' ? 200 : ($status === 'error' ? 500 : null),
            'duration_ms'  => round(rand(10, 5000) + rand(0, 99) / 100, 2),
            'ip_address'   => fake()->ipv4(),
            'user_agent'   => fake()->userAgent(),
            'tags'         => [],
            'started_at'   => now(),
            'completed_at' => $status !== 'processing' ? now()->addMilliseconds(rand(10, 500)) : null,
        ];
    }

    public function success(): static
    {
        return $this->state(['status' => 'success', 'http_status' => 200]);
    }

    public function failed(): static
    {
        return $this->state(['status' => 'error', 'http_status' => 500]);
    }

    public function processing(): static
    {
        return $this->state(['status' => 'processing', 'http_status' => null, 'completed_at' => null]);
    }
}

