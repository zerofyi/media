<?php

declare(strict_types=1);

namespace Zerofyi\Media\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\TestCase as OrchestraTestCase;
use Zerofyi\Media\MediaServiceProvider;

abstract class TestCase extends OrchestraTestCase
{
    use RefreshDatabase;

    protected function getPackageProviders($app): array
    {
        return [MediaServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        // Use SQLite in-memory for speed.
        $app['config']->set('database.default', 'testbench');
        $app['config']->set('database.connections.testbench', [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]);

        // Use the "local" disk backed by a temp directory.
        $app['config']->set('filesystems.default', 'local');
        $app['config']->set('filesystems.disks.local', [
            'driver' => 'local',
            'root'   => storage_path('app'),
        ]);

        $app['config']->set('media.disk', 'local');
        $app['config']->set('media.max_size_kb', 5120);
        $app['config']->set('media.max_pixel_count', 25_000_000);
        $app['config']->set('media.default_quality', 85);
        $app['config']->set('media.allowed_mime', [
            'image/jpeg',
            'image/png',
            'image/gif',
            'image/webp',
            'image/bmp',
            'image/svg+xml',
        ]);
        $app['config']->set('media.variants', [
            'thumb' => ['width' => 200, 'height' => 200, 'fit' => 'cover',      'quality' => 75],
            'sm'    => ['width' => 480, 'height' => null, 'fit' => 'scale_down', 'quality' => 80],
            'md'    => ['width' => 768, 'height' => null, 'fit' => 'scale_down', 'quality' => 85],
            'lg'    => ['width' => 1200,'height' => null, 'fit' => 'scale_down', 'quality' => 85],
        ]);
        $app['config']->set('media.default_variants', ['thumb', 'sm', 'md']);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
    }
}