<?php

declare(strict_types=1);

namespace Tests;

use App\Jobs\IndexMovieJob;
use App\Jobs\IndexPerformerJob;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Queue;

abstract class TestCase extends BaseTestCase
{
    protected bool $fakeSearchIndexJobs = true;

    protected function setUp(): void
    {
        // Docker Compose injects APP_ENV / DB_* into the real process environment.
        // Pin them before the app boots so feature tests use sqlite :memory: and
        // never hit live Postgres / ES. Do not reconfigure the DB after boot —
        // a second sqlite :memory: connection would be an empty database.
        putenv('APP_ENV=testing');
        putenv('DB_CONNECTION=sqlite');
        putenv('DB_DATABASE=:memory:');
        putenv('DB_URL');
        $_ENV['APP_ENV'] = 'testing';
        $_ENV['DB_CONNECTION'] = 'sqlite';
        $_ENV['DB_DATABASE'] = ':memory:';
        $_ENV['DB_URL'] = '';
        $_SERVER['APP_ENV'] = 'testing';
        $_SERVER['DB_CONNECTION'] = 'sqlite';
        $_SERVER['DB_DATABASE'] = ':memory:';
        $_SERVER['DB_URL'] = '';

        parent::setUp();

        // Sync keeps crawl jobs inline; search index jobs are faked unless a test opts out.
        config()->set('queue.default', 'sync');

        if ($this->fakeSearchIndexJobs) {
            Queue::fake([IndexMovieJob::class, IndexPerformerJob::class]);
        }
    }
}
