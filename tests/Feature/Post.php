<?php

declare(strict_types=1);

namespace Zerofyi\Media\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Zerofyi\Media\Models\Asset;
use Zerofyi\Media\Tests\Support\MakesUploadedFiles;
use Zerofyi\Media\Tests\TestCase;
use Zerofyi\Media\Traits\HasAssets;

/**
 * Minimal stub model used only in these tests.
 */
class Post extends Model
{
    use HasAssets;

    protected $table    = 'posts';
    protected $fillable = ['title'];
}

class HasAssetsTraitTest extends TestCase
{
    use MakesUploadedFiles;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        // Create a minimal posts table for the stub model.
        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('posts');
        parent::tearDown();
    }

    private function makePost(): Post
    {
        return Post::create(['title' => 'Test Post']);
    }

    // -------------------------------------------------------------------------
    // uploadAsset
    // -------------------------------------------------------------------------

    public function test_upload_asset_attaches_asset_to_model(): void
    {
        $post  = $this->makePost();
        $file  = $this->makeJpeg();
        $asset = $post->uploadAsset($file, 'photo', 'posts');

        $this->assertInstanceOf(Asset::class, $asset);
        $this->assertSame($post->getMorphClass(), $asset->assetable_type);
        $this->assertSame($post->getKey(), $asset->assetable_id);
    }

    public function test_assets_relationship_returns_all_attached_assets(): void
    {
        $post = $this->makePost();

        $post->uploadAsset($this->makeJpeg(), 'photo1', 'posts');
        $post->uploadAsset($this->makeJpeg(), 'photo2', 'posts');

        $this->assertCount(2, $post->assets);
    }

    public function test_primary_asset_returns_most_recent(): void
    {
        $post = $this->makePost();

        $first  = $post->uploadAsset($this->makeJpeg(), 'first', 'posts');
        $second = $post->uploadAsset($this->makeJpeg(), 'second', 'posts');

        $this->assertSame($second->id, $post->primaryAsset->id);
    }

    public function test_upload_asset_with_type(): void
    {
        $post  = $this->makePost();
        $asset = $post->uploadAsset($this->makeJpeg(), 'photo', 'posts', type: 'banner');

        $this->assertSame('banner', $asset->type);
    }

    public function test_upload_asset_with_explicit_uploaded_by(): void
    {
        $post  = $this->makePost();
        $asset = $post->uploadAsset($this->makeJpeg(), 'photo', 'posts', uploadedBy: 99);

        $this->assertSame(99, $asset->uploaded_by);
    }

    // -------------------------------------------------------------------------
    // replaceAsset
    // -------------------------------------------------------------------------

    public function test_replace_asset_updates_path_and_preserves_relationship(): void
    {
        $post    = $this->makePost();
        $asset   = $post->uploadAsset($this->makeJpeg(), 'photo', 'posts');
        $oldPath = $asset->path;

        $updated = $post->replaceAsset($asset, $this->makeJpeg(), 'photo2', 'posts');

        $this->assertNotSame($oldPath, $updated->path);
        $this->assertSame($asset->uuid, $updated->uuid);
        Storage::disk('local')->assertMissing($oldPath);
    }

    // -------------------------------------------------------------------------
    // deleteAsset
    // -------------------------------------------------------------------------

    public function test_delete_asset_removes_record_and_file(): void
    {
        $post  = $this->makePost();
        $asset = $post->uploadAsset($this->makeJpeg(), 'photo', 'posts');
        $path  = $asset->path;

        $result = $post->deleteAsset($asset);

        $this->assertTrue($result);
        $this->assertDatabaseMissing('assets', ['id' => $asset->id]);
        Storage::disk('local')->assertMissing($path);
    }
}