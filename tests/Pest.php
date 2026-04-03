<?php

use TraceReplay\Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
| The default TestCase class for all Pest tests in this package.
| Orchestra\Testbench boots a full Laravel application for each test.
*/

uses(TestCase::class)->in('Feature');

