<?php

namespace TraceReplay\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use TraceReplay\TraceReplayServiceProvider;

class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }

    protected function getPackageProviders($app)
    {
        return [
            TraceReplayServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app)
    {
        // Setup default database to use sqlite :memory:
        $app['config']->set('database.default', 'testbench');
        $app['config']->set('database.connections.testbench', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $app['config']->set('trace-replay.enabled', true);
        $app['config']->set('trace-replay.batch_persistence', false);
        $app['config']->set('trace-replay.middleware', ['web']);
        // API tests error because they don't send auth headers, so we set a token and authenticate in tests
        $app['config']->set('trace-replay.api.token', 'test-token');
        $app['config']->set('trace-replay.api.middleware', ['api']);
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
    }
}
