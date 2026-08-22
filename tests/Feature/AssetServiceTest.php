<?php

declare(strict_types=1);

namespace Zerofyi\Media\Tests\Feature;

use Illuminate\Support\Facades\Storage;
use Zerofyi\Media\Exceptions\ImageStorageException;
use Zerofyi\Media\Models\Asset;
use Zerofyi\Media\Services\AssetService;
use Zerofyi\Media\Tests\Support\MakesUploadedFiles;
use Zerofyi\Media\Tests\TestCase;

class AssetServiceTest extends TestCase
{
    use MakesUploadedFiles;

    private AssetService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->service = app(AssetService::class);
    }

    // -------------------------------------------------------------------------
    // store()
    // -------------------------------------------------------------------------

    public function test_store_creates_db_record_and_writes_file(): void
    {
        $file  = $this->makeJpeg();
        $asset = $this->service->store($file, 'photo', 'products');

        $this->assertDatabaseHas('assets', ['path' => $asset->path]);
        Storage::disk('local')->assertExists($asset->path);
    }

    public function test_store_returns_asset_model_instance(): void
    {
        $file  = $this->makeJpeg();
        $asset = $this->service->store($file, 'photo', 'products');

        $this->assertInstanceOf(Asset::class, $asset);
    }

    public function test_store_persists_correct_metadata(): void
    {
        $file  = $this->makeJpeg('my-image.jpg');
        $asset = $this->service->store($file, 'my-image', 'photos');

        $this->assertSame('image/webp', $asset->mime_type);
        $this->assertSame('local', $asset->disk);
        $this->assertSame('my-image.jpg', $asset->original_name);
        $this->assertSame(100, $asset->width);
        $this->assertSame(100, $asset->height);
        $this->assertGreaterThan(0, $asset->size);
        $this->assertNotNull($asset->uuid);
    }

    public function test_store_attaches_extra_attributes(): void
    {
        $file  = $this->makeJpeg();
        $asset = $this->service->store($file, 'photo', 'products', attributes: [
            'type'        => 'gallery',
            'uploaded_by' => 42,
        ]);

        $this->assertSame('gallery', $asset->type);
        $this->assertSame(42, $asset->uploaded_by);
    }

    public function test_store_generates_variants_and_persists_paths(): void
    {
        $file  = $this->makeJpeg();
        $asset = $this->service->store($file, 'photo', 'products', variants: ['thumb', 'sm']);

        $this->assertIsArray($asset->variants);
        $this->assertArrayHasKey('thumb', $asset->variants);
        $this->assertArrayHasKey('sm', $asset->variants);
        Storage::disk('local')->assertExists($asset->variants['thumb']);
        Storage::disk('local')->assertExists($asset->variants['sm']);
    }

    public function test_store_keeps_original_when_requested(): void
    {
        $file  = $this->makeJpeg();
        $asset = $this->service->store($file, 'photo', 'products', keepOriginal: true);

        $this->assertNotNull($asset->original_path);
        Storage::disk('local')->assertExists($asset->original_path);
    }

    public function test_store_purges_disk_files_when_db_insert_fails(): void
    {
        // Point config to a non-existent model to force a DB failure.
        config(['media.model' => 'NonExistentModel\\Asset']);

        $file = $this->makeJpeg();

        try {
            $this->service->store($file, 'photo', 'products');
        } catch (\Throwable) {
            // Expected.
        }

        // No files should remain on disk.
        $files = Storage::disk('local')->allFiles();
        $this->assertEmpty($files, 'Orphaned files found after DB failure: ' . implode(', ', $files));
    }

    public function test_store_url_accessor_returns_string(): void
    {
        $file  = $this->makeJpeg();
        $asset = $this->service->store($file, 'photo', 'products');

        $this->assertIsString($asset->url);
    }

    // -------------------------------------------------------------------------
    // replace()
    // -------------------------------------------------------------------------

    public function test_replace_updates_db_record(): void
    {
        $file1  = $this->makeJpeg('first.jpg');
        $asset  = $this->service->store($file1, 'first', 'products');
        $oldPath = $asset->path;

        $file2  = $this->makeJpeg('second.jpg');
        $updated = $this->service->replace($asset, $file2, 'second', 'products');

        $this->assertNotSame($oldPath, $updated->path);
        $this->assertDatabaseHas('assets', ['path' => $updated->path]);
        $this->assertDatabaseMissing('assets', ['path' => $oldPath]);
    }

    public function test_replace_preserves_uuid(): void
    {
        $file1 = $this->makeJpeg();
        $asset = $this->service->store($file1, 'photo', 'products');
        $uuid  = $asset->uuid;

        $file2   = $this->makeJpeg();
        $updated = $this->service->replace($asset, $file2, 'photo2', 'products');

        $this->assertSame($uuid, $updated->uuid);
    }

    public function test_replace_purges_old_master_file(): void
    {
        $file1   = $this->makeJpeg();
        $asset   = $this->service->store($file1, 'photo', 'products');
        $oldPath = $asset->path;

        $file2 = $this->makeJpeg();
        $this->service->replace($asset, $file2, 'photo2', 'products');

        Storage::disk('local')->assertMissing($oldPath);
    }

    public function test_replace_purges_old_variants(): void
    {
        $file1  = $this->makeJpeg();
        $asset  = $this->service->store($file1, 'photo', 'products', variants: ['thumb']);
        $oldVariantPath = $asset->variants['thumb'];

        $file2 = $this->makeJpeg();
        $this->service->replace($asset, $file2, 'photo2', 'products', variants: ['thumb']);

        Storage::disk('local')->assertMissing($oldVariantPath);
    }

    public function test_replace_purges_new_files_when_db_update_fails(): void
    {
        $file1 = $this->makeJpeg();
        $asset = $this->service->store($file1, 'photo', 'products');

        // Make the update fail by setting an invalid model (replace calls $asset->update()).
        // We achieve this by sealing the asset's connection — simpler to use a mock.
        $mockAsset = $this->createPartialMock(Asset::class, ['update', 'fresh']);
        $mockAsset->path          = $asset->path;
        $mockAsset->disk          = $asset->disk;
        $mockAsset->variants      = $asset->variants;
        $mockAsset->original_path = $asset->original_path;
        $mockAsset->method('update')->willThrowException(new \RuntimeException('DB down'));

        $file2 = $this->makeJpeg();
        $filesBefore = Storage::disk('local')->allFiles();

        try {
            $this->service->replace($mockAsset, $file2, 'photo2', 'products');
        } catch (\Throwable) {
            // Expected.
        }

        $filesAfter = Storage::disk('local')->allFiles();

        // The number of files on disk must be the same as before the failed replace.
        $this->assertCount(count($filesBefore), $filesAfter, 'New files were orphaned after DB failure.');
    }

    // -------------------------------------------------------------------------
    // delete()
    // -------------------------------------------------------------------------

    public function test_delete_removes_record_and_files(): void
    {
        $file  = $this->makeJpeg();
        $asset = $this->service->store($file, 'photo', 'products', variants: ['thumb']);
        $path  = $asset->path;
        $thumb = $asset->variants['thumb'];
        $id    = $asset->id;

        $result = $this->service->delete($asset);

        $this->assertTrue($result);
        $this->assertDatabaseMissing('assets', ['id' => $id]);
        Storage::disk('local')->assertMissing($path);
        Storage::disk('local')->assertMissing($thumb);
    }

    // -------------------------------------------------------------------------
    // getModelClass()
    // -------------------------------------------------------------------------

    public function test_get_model_class_returns_configured_class(): void
    {
        $this->assertSame(Asset::class, $this->service->getModelClass());
    }

    // -------------------------------------------------------------------------
    // Asset model — URL helpers
    // -------------------------------------------------------------------------

    public function test_asset_variant_url_falls_back_to_master_for_unknown_key(): void
    {
        $file  = $this->makeJpeg();
        $asset = $this->service->store($file, 'photo', 'products');

        // No variants generated; variantUrl should fall back to the master URL.
        $url = $asset->variantUrl('thumb');
        $this->assertSame($asset->url, $url);
    }

    public function test_asset_all_variant_urls_returns_keyed_array(): void
    {
        $file  = $this->makeJpeg();
        $asset = $this->service->store($file, 'photo', 'products', variants: ['thumb', 'sm']);

        $urls = $asset->allVariantUrls();

        $this->assertArrayHasKey('thumb', $urls);
        $this->assertArrayHasKey('sm', $urls);
        $this->assertIsString($urls['thumb']);
        $this->assertIsString($urls['sm']);
    }

    public function test_asset_original_url_returns_null_when_not_kept(): void
    {
        $file  = $this->makeJpeg();
        $asset = $this->service->store($file, 'photo', 'products');

        $this->assertNull($asset->original_url);
    }

    public function test_asset_original_url_returns_string_when_kept(): void
    {
        $file  = $this->makeJpeg();
        $asset = $this->service->store($file, 'photo', 'products', keepOriginal: true);

        $this->assertIsString($asset->original_url);
    }
}