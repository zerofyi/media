<?php

declare(strict_types=1);

namespace Zerofyi\Media\Tests\Unit;

use Illuminate\Support\Facades\Storage;
use Zerofyi\Media\Exceptions\ImageStorageException;
use Zerofyi\Media\Services\ImageStorageService;
use Zerofyi\Media\Tests\Support\MakesUploadedFiles;
use Zerofyi\Media\Tests\TestCase;
use Zerofyi\Media\ValueObjects\StoredImageResult;

class ImageStorageServiceTest extends TestCase
{
    use MakesUploadedFiles;

    private ImageStorageService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->service = new ImageStorageService();
    }

    // -------------------------------------------------------------------------
    // Happy path — upload
    // -------------------------------------------------------------------------

    public function test_upload_jpeg_returns_stored_result(): void
    {
        $file   = $this->makeJpeg();
        $result = $this->service->upload($file, 'my-photo', 'products');

        $this->assertInstanceOf(StoredImageResult::class, $result);
        $this->assertNotEmpty($result->uuid);
        $this->assertNotEmpty($result->path);
        $this->assertSame('image/webp', $result->mime); // converted by default
        $this->assertSame('local', $result->disk);
        $this->assertGreaterThan(0, $result->size);
        $this->assertSame(100, $result->width);
        $this->assertSame(100, $result->height);
        Storage::disk('local')->assertExists($result->path);
    }

    public function test_upload_png_converts_to_webp(): void
    {
        $file   = $this->makePng();
        $result = $this->service->upload($file, 'photo', 'avatars');

        $this->assertStringEndsWith('.webp', $result->path);
        $this->assertSame('image/webp', $result->mime);
    }

    public function test_upload_with_convert_to_webp_false_preserves_original_format(): void
    {
        $file   = $this->makeJpeg();
        $result = $this->service->upload($file, 'photo', 'products', convertToWebp: false);

        $this->assertStringEndsWith('.jpg', $result->path);
        $this->assertSame('image/jpeg', $result->mime);
    }

    public function test_upload_keeps_original_when_requested(): void
    {
        $file   = $this->makeJpeg();
        $result = $this->service->upload($file, 'photo', 'products', keepOriginal: true);

        $this->assertNotNull($result->originalPath);
        Storage::disk('local')->assertExists($result->originalPath);
    }

    public function test_upload_without_keep_original_has_null_original_path(): void
    {
        $file   = $this->makeJpeg();
        $result = $this->service->upload($file, 'photo', 'products', keepOriginal: false);

        $this->assertNull($result->originalPath);
    }

    public function test_upload_generates_requested_variants(): void
    {
        $file   = $this->makeJpeg();
        $result = $this->service->upload($file, 'photo', 'products', variants: ['thumb', 'sm']);

        $this->assertArrayHasKey('thumb', $result->variants);
        $this->assertArrayHasKey('sm', $result->variants);
        $this->assertArrayNotHasKey('md', $result->variants);
        Storage::disk('local')->assertExists($result->variants['thumb']);
        Storage::disk('local')->assertExists($result->variants['sm']);
    }

    public function test_upload_with_variants_true_generates_default_variants(): void
    {
        $file   = $this->makeJpeg();
        $result = $this->service->upload($file, 'photo', 'products', variants: true);

        foreach (['thumb', 'sm', 'md'] as $key) {
            $this->assertArrayHasKey($key, $result->variants);
        }
    }

    public function test_upload_svg_returns_svg_result(): void
    {
        $file   = $this->makeSvg();
        $result = $this->service->upload($file, 'logo', 'icons');

        $this->assertSame('image/svg+xml', $result->mime);
        $this->assertStringEndsWith('.svg', $result->path);
        $this->assertEmpty($result->variants); // SVGs never get variants
        Storage::disk('local')->assertExists($result->path);
    }

    public function test_upload_filename_is_slug_prefixed(): void
    {
        $file   = $this->makeJpeg();
        $result = $this->service->upload($file, 'My Cool Product!!', 'products');

        $this->assertStringStartsWith('products/my-cool-product', $result->path);
    }

    public function test_upload_empty_slug_uses_img_prefix(): void
    {
        $file   = $this->makeJpeg();
        $result = $this->service->upload($file, '', 'products');

        $this->assertStringContains('/img_', $result->path);
    }

    public function test_upload_trims_duplicate_slashes_in_folder(): void
    {
        $file   = $this->makeJpeg();
        $result = $this->service->upload($file, 'photo', 'products//sub///dir');

        $this->assertStringStartsWith('products/sub/dir/', $result->path);
    }

    // -------------------------------------------------------------------------
    // Security — MIME
    // -------------------------------------------------------------------------

    public function test_upload_rejects_disallowed_mime_type(): void
    {
        $this->expectException(ImageStorageException::class);
        $this->expectExceptionCode(ImageStorageException::CODE_INVALID_TYPE);

        $file = $this->makeJpeg();
        $this->service->upload($file, 'photo', 'test', allowedTypes: ['image/png']);
    }

    public function test_upload_per_call_allowed_types_restricts_correctly(): void
    {
        $file = $this->makePng();
        // Should succeed with png allowed.
        $result = $this->service->upload($file, 'photo', 'test', allowedTypes: ['image/png']);
        $this->assertNotNull($result);
    }

    // -------------------------------------------------------------------------
    // Security — double extension
    // -------------------------------------------------------------------------

    public function test_upload_rejects_double_extension_filename(): void
    {
        $this->expectException(ImageStorageException::class);
        $this->expectExceptionCode(ImageStorageException::CODE_INVALID_CONTENT);

        $file = $this->makeDoubleExtension('shell.php.jpg');
        $this->service->upload($file, 'photo', 'test');
    }

    // -------------------------------------------------------------------------
    // Security — file size
    // -------------------------------------------------------------------------

    public function test_upload_rejects_file_exceeding_max_size(): void
    {
        $this->expectException(ImageStorageException::class);
        $this->expectExceptionCode(ImageStorageException::CODE_SIZE_EXCEEDED);

        $file = $this->makeJpeg();
        $this->service->upload($file, 'photo', 'test', maxSizeKb: 1); // 1 KB limit
    }

    // -------------------------------------------------------------------------
    // Security — magic bytes
    // -------------------------------------------------------------------------

    public function test_upload_rejects_file_with_mismatched_magic_bytes(): void
    {
        $this->expectException(ImageStorageException::class);
        $this->expectExceptionCode(ImageStorageException::CODE_INVALID_CONTENT);

        $file = $this->makeMismatchedFile(); // PNG bytes, JPEG MIME
        $this->service->upload($file, 'photo', 'test');
    }

    // -------------------------------------------------------------------------
    // Security — path traversal
    // -------------------------------------------------------------------------

    public function test_upload_rejects_path_traversal_in_folder(): void
    {
        $this->expectException(ImageStorageException::class);
        $this->expectExceptionCode(ImageStorageException::CODE_INVALID_CONTENT);

        $file = $this->makeJpeg();
        $this->service->upload($file, 'photo', '../etc/passwd');
    }

    public function test_upload_rejects_encoded_path_traversal(): void
    {
        $this->expectException(ImageStorageException::class);
        $this->expectExceptionCode(ImageStorageException::CODE_INVALID_CONTENT);

        $file = $this->makeJpeg();
        $this->service->upload($file, 'photo', '%2e%2e/etc');
    }

    public function test_upload_rejects_null_byte_in_folder(): void
    {
        $this->expectException(ImageStorageException::class);
        $this->expectExceptionCode(ImageStorageException::CODE_INVALID_CONTENT);

        $file = $this->makeJpeg();
        $this->service->upload($file, 'photo', "products\0evil");
    }

    // -------------------------------------------------------------------------
    // Security — SVG XSS
    // -------------------------------------------------------------------------

    public function test_upload_svg_strips_script_tags(): void
    {
        $malicious = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script><rect width="10" height="10"/></svg>';
        $file      = $this->makeSvg('xss.svg', $malicious);
        $result    = $this->service->upload($file, 'icon', 'icons');

        $stored = Storage::disk('local')->get($result->path);
        $this->assertStringNotContainsString('<script>', (string) $stored);
        $this->assertStringNotContainsString('alert', (string) $stored);
    }

    public function test_upload_rejects_svg_that_sanitizes_to_empty(): void
    {
        $this->expectException(ImageStorageException::class);
        $this->expectExceptionCode(ImageStorageException::CODE_INVALID_CONTENT);

        // A completely malicious SVG that sanitizes down to nothing.
        $file = $this->makeSvg('bad.svg', '<svg><script>alert(1)</script></svg>');
        $this->service->upload($file, 'icon', 'icons');
    }

    // -------------------------------------------------------------------------
    // delete
    // -------------------------------------------------------------------------

    public function test_delete_removes_master_file(): void
    {
        $file   = $this->makeJpeg();
        $result = $this->service->upload($file, 'photo', 'products');

        Storage::disk('local')->assertExists($result->path);

        $this->service->delete($result->path, $result->disk);

        Storage::disk('local')->assertMissing($result->path);
    }

    public function test_delete_removes_variants(): void
    {
        $file   = $this->makeJpeg();
        $result = $this->service->upload($file, 'photo', 'products', variants: ['thumb', 'sm']);

        foreach ($result->variants as $variantPath) {
            Storage::disk('local')->assertExists($variantPath);
        }

        $this->service->delete($result->path, $result->disk, $result->variants);

        foreach ($result->variants as $variantPath) {
            Storage::disk('local')->assertMissing($variantPath);
        }
    }

    public function test_delete_removes_original_when_kept(): void
    {
        $file   = $this->makeJpeg();
        $result = $this->service->upload($file, 'photo', 'products', keepOriginal: true);

        Storage::disk('local')->assertExists($result->originalPath);

        $this->service->delete($result->path, $result->disk, [], $result->originalPath);

        Storage::disk('local')->assertMissing($result->originalPath);
    }

    public function test_delete_continues_after_one_missing_variant(): void
    {
        $file   = $this->makeJpeg();
        $result = $this->service->upload($file, 'photo', 'products', variants: ['thumb', 'sm']);

        // Manually remove one variant to simulate a partial orphan.
        Storage::disk('local')->delete($result->variants['thumb']);

        // Should not throw; should still delete the remaining files.
        $deleted = $this->service->delete($result->path, $result->disk, $result->variants);

        Storage::disk('local')->assertMissing($result->path);
        Storage::disk('local')->assertMissing($result->variants['sm']);
    }

    // -------------------------------------------------------------------------
    // url helper
    // -------------------------------------------------------------------------

    public function test_url_returns_null_for_empty_path(): void
    {
        $this->assertNull($this->service->url(null));
        $this->assertNull($this->service->url(''));
    }

    public function test_url_returns_string_for_valid_path(): void
    {
        $url = $this->service->url('products/photo.webp', 'local');
        $this->assertIsString($url);
        $this->assertStringContainsString('products/photo.webp', $url);
    }
}