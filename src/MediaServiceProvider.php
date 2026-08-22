<?php

declare(strict_types=1);

namespace Zerofyi\Media;

use Illuminate\Support\ServiceProvider;
use Zerofyi\Media\Services\AssetService;
use Zerofyi\Media\Services\ImageStorageService;

final class MediaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/media.php', 'media');

        $this->app->singleton(
            ImageStorageService::class,
            fn (): ImageStorageService => new ImageStorageService()
        );

        $this->app->singleton(
            AssetService::class,
            fn ($app): AssetService => new AssetService($app->make(ImageStorageService::class))
        );

        // Register the "Media" Facade alias so callers can use Media::store(…) etc.
        $this->app->alias(AssetService::class, 'media');
    }

    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        // ── Config ────────────────────────────────────────────────────────────
        $this->publishes([
            __DIR__ . '/../config/media.php' => config_path('media.php'),
        ], ['media-config', 'media']);

        // ── Migration ─────────────────────────────────────────────────────────
        // Only register when the migration does not yet exist, so repeated
        // vendor:publish calls cannot create duplicate migration files.
        if (! $this->assetsTableMigrationExists()) {
            $this->publishes([
                __DIR__ . '/../database/migrations/create_assets_table.php.stub' =>
                    database_path('migrations/' . date('Y_m_d_His') . '_create_assets_table.php'),
            ], ['media-migrations', 'media']);
        }

        // ── Model Stub ────────────────────────────────────────────────────────
        $this->publishes([
            __DIR__ . '/../stubs/Asset.php.stub' => app_path('Models/Asset.php'),
        ], ['media-model', 'media']);
    }

    /**
     * Determine whether an assets migration has already been published.
     */
    private function assetsTableMigrationExists(): bool
    {
        return count((array) glob(database_path('migrations/*_create_assets_table.php'))) > 0;
    }
}