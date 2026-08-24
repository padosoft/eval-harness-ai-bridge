<?php

declare(strict_types=1);

namespace Padosoft\EvalHarnessAiBridge\Tests;

use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase as OrchestraTestCase;
use Padosoft\EvalHarness\EvalHarnessServiceProvider;
use RuntimeException;

abstract class TestCase extends OrchestraTestCase
{
    /**
     * @param  Application  $app
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            EvalHarnessServiceProvider::class,
        ];
    }

    protected function container(): Application
    {
        if ($this->app === null) {
            throw new RuntimeException('The Testbench application has not been booted.');
        }

        return $this->app;
    }

    /**
     * @template T of object
     *
     * @param  class-string<T>  $abstract
     * @return T
     */
    protected function resolve(string $abstract): object
    {
        return $this->container()->make($abstract);
    }
}
