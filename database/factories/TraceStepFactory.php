<?php

namespace TraceReplay\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use TraceReplay\Models\Trace;
use TraceReplay\Models\TraceStep;

class TraceStepFactory extends Factory
{
    protected $model = TraceStep::class;

    public function definition(): array
    {
        return [
            'trace_id' => Trace::factory(),
            'label' => $this->faker->sentence(3),
            'type' => 'step',
            'step_order' => 1,
            'status' => 'success',
            'duration_ms' => $this->faker->randomFloat(2, 1, 500),
            'memory_usage' => $this->faker->numberBetween(1024, 1024 * 1024),
            'db_query_count' => 0,
            'db_query_time_ms' => 0,
            'db_queries' => null,
            'cache_calls' => null,
            'cache_hit_count' => 0,
            'cache_miss_count' => 0,
            'http_calls' => null,
            'mail_calls' => null,
            'error_reason' => null,
        ];
    }

    /**
     * Indicate that the step is a checkpoint.
     */
    public function checkpoint(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'checkpoint',
            'status' => 'checkpoint',
            'duration_ms' => 0,
        ]);
    }

    /**
     * Indicate that the step failed.
     */
    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'error',
            'error_reason' => [
                'message' => 'Something went wrong',
                'file' => 'ExampleController.php',
                'line' => 42,
            ],
        ]);
    }
}
