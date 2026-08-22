<?php

declare(strict_types=1);

namespace Zerofyi\Media\Services;

use enshrined\svgSanitize\Sanitizer as SvgSanitizer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Drivers\Imagick\Driver as ImagickDriver;
use Intervention\Image\Encoders\AutoEncoder;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\ImageManager;
use Throwable;
use Zerofyi\Media\Exceptions\ImageStorageException;
use Zerofyi\Media\ValueObjects\StoredImageResult;

final class ImageStorageService
{
    // -------------------------------------------------------------------------
    // Security constants
    // -------------------------------------------------------------------------

    private const MAGIC_BYTES = [
        'image/jpeg' => ["\xFF\xD8\xFF"],
        'image/png'  => ["\x89PNG\r\n\x1A\n"],
        'image/gif'  => ['GIF87a', 'GIF89a'],
        'image/webp' => ['RIFF'],
        'image/bmp'  => ['BM'],
    ];

    private const DANGEROUS_EXTENSIONS = [
        'php', 'php3', 'php4', 'php5', 'php7', 'phtml', 'phar',
        'asp', 'aspx', 'jsp', 'jspx', 'cgi', 'pl', 'py', 'rb',
        'sh', 'bash', 'exe', 'bat', 'cmd', 'com', 'msi', 'htaccess',
    ];

    /**
     * Characters that must never appear in a folder segment, even after decoding.
     * Covers null-byte injection and URL-encoded traversal variants.
     */
    private const FORBIDDEN_FOLDER_PATTERNS = [
        '..',        // classic traversal
        "\0",        // null-byte injection
        '%2e%2e',    // URL-encoded ..
        '%252e',     // double-encoded .
    ];

    // -------------------------------------------------------------------------
    // Dependencies
    // -------------------------------------------------------------------------

    private readonly ImageManager $manager;
    private readonly SvgSanitizer $svgSanitizer;

    public function __construct()
    {
        // Prefer Imagick for better format support and colour accuracy.
        $driver = extension_loaded('imagick')
            ? new ImagickDriver()
            : new GdDriver();

        $this->manager      = new ImageManager($driver);
        $this->svgSanitizer = new SvgSanitizer();
    }

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Validate, process, and persist an uploaded image to the configured disk.
     *
     * @param  bool|array<string> $variants   false = none, true = default_variants, array = named presets
     * @param  bool               $convertToWebp    Re-encode JPEG/PNG/BMP to WebP (default: true)
     * @param  bool               $preserveIfWebp   Copy native WebP without re-encoding (default: true)
     * @param  bool               $keepOriginal     Store an untouched copy under Originals/ (default: false)
     * @param  int|null           $quality          WebP quality 1–100; falls back to config default_quality
     * @param  int|null           $maxSizeKb        Override the per-call size ceiling (KB)
     * @param  string|null        $disk             Override the configured storage disk
     * @param  array<string>|null $allowedTypes     Restrict accepted MIME types for this call
     *
     * @throws ImageStorageException
     */
    public function upload(
        UploadedFile $file,
        string $slug,
        string $folder,
        bool|array $variants = false,
        bool $convertToWebp = true,
        bool $preserveIfWebp = true,
        bool $keepOriginal = false,
        ?int $quality = null,
        ?int $maxSizeKb = null,
        ?string $disk = null,
        ?array $allowedTypes = null,
    ): StoredImageResult {
        $disk         = $disk ?? (string) config('media.disk', 'public');
        $maxSizeKb    = $maxSizeKb ?? (int) config('media.max_size_kb', 5120);
        $quality      = $quality ?? (int) config('media.default_quality', 85);
        $allowedTypes = $this->resolveAllowedTypes($allowedTypes);

        $this->validate($file, $maxSizeKb, $allowedTypes);

        $folder       = $this->sanitizeFolder($folder);
        $uuid         = (string) Str::uuid();
        $originalName = $file->getClientOriginalName();
        $mime         = (string) $file->getMimeType();
        $isSvg        = $mime === 'image/svg+xml';

        // 1. Keep raw original (before any processing)
        $originalPath = null;
        if ($keepOriginal) {
            $origExt      = $file->getClientOriginalExtension() ?: $this->mimeToExtension($file);
            $origFilename = $this->buildFilename($slug, $uuid, $origExt);
            $originalPath = $this->sanitizeFolder("Originals/{$folder}") . "/{$origFilename}";
            $originalBytes = file_get_contents($file->getRealPath());
            if ($originalBytes === false) {
                throw ImageStorageException::invalidContent('Could not read uploaded file for original storage.');
            }
            Storage::disk($disk)->put($originalPath, $originalBytes);
        }

        // 2. Handle SVG separately (no pixel ops, just sanitize)
        if ($isSvg) {
            return $this->handleSvg($file, $slug, $folder, $uuid, $originalName, $disk, $originalPath);
        }

        // 3. Process master raster image
        $finalExt = ($convertToWebp || ($preserveIfWebp && $mime === 'image/webp'))
            ? 'webp'
            : $this->mimeToExtension($file);

        $filename   = $this->buildFilename($slug, $uuid, $finalExt);
        $path       = "{$folder}/{$filename}";
        $outputMime = $finalExt === 'webp' ? 'image/webp' : $mime;

        $width  = null;
        $height = null;

        try {
            $image = method_exists($this->manager, 'decodePath')
                ? $this->manager->decodePath($file->getRealPath())
                : $this->manager->read($file->getRealPath());

            $width  = $image->width();
            $height = $image->height();

            if ($preserveIfWebp && $mime === 'image/webp') {
                // Copy raw bytes — no re-encoding, no generational quality loss.
                $webpBytes = file_get_contents($file->getRealPath());
                if ($webpBytes === false) {
                    throw ImageStorageException::invalidContent('Could not read WebP file for storage.');
                }
                Storage::disk($disk)->put($path, $webpBytes);
            } elseif ($convertToWebp) {
                Storage::disk($disk)->put($path, (string) $image->encode(new WebpEncoder(quality: $quality)));
            } else {
                Storage::disk($disk)->put($path, (string) $image->encode(new AutoEncoder(quality: $quality)));
            }
        } catch (Throwable $e) {
            $this->cleanupPaths([$originalPath, $path], $disk);
            Log::error('Zerofyi\\Media: Master encoding failure', [
                'path'  => $path,
                'error' => $e->getMessage(),
            ]);
            throw ImageStorageException::storageFailed($path, $disk, $e);
        }

        // 4. Generate responsive variants
        $generatedVariants = $this->generateVariants(
            file:             $file,
            filename:         $filename,
            folder:           $folder,
            disk:             $disk,
            variantSelection: $variants,
        );

        return new StoredImageResult(
            uuid:         $uuid,
            path:         $path,
            url:          Storage::disk($disk)->url($path),
            filename:     $filename,
            originalName: $originalName,
            size:         (int) Storage::disk($disk)->size($path),
            mime:         $outputMime,
            disk:         $disk,
            width:        $width,
            height:       $height,
            originalPath: $originalPath,
            variants:     $generatedVariants,
        );
    }

    /**
     * Cascade-delete the master file, every tracked variant, and the raw original.
     *
     * Each file is attempted independently so one missing file never blocks the rest.
     * Returns true only when every deletion succeeds.
     *
     * @param  array<string, string> $variants  Stored variant map from the Asset model (presetKey => path).
     */
    public function delete(
        string $path,
        ?string $disk = null,
        array $variants = [],
        ?string $originalPath = null,
    ): bool {
        $disk    = $disk ?? (string) config('media.disk', 'public');
        $success = true;

        // 1. Delete every variant using the ACTUAL stored paths (not config-derived guesses).
        foreach ($variants as $variantPath) {
            if (! $variantPath) {
                continue;
            }

            try {
                Storage::disk($disk)->delete($variantPath);
            } catch (Throwable $e) {
                $success = false;
                Log::warning('Zerofyi\\Media: Variant deletion failed', [
                    'variant_path' => $variantPath,
                    'error'        => $e->getMessage(),
                ]);
            }
        }

        // 2. Delete the raw original when it was kept.
        if ($originalPath !== null) {
            try {
                Storage::disk($disk)->delete($originalPath);
            } catch (Throwable $e) {
                $success = false;
                Log::warning('Zerofyi\\Media: Original deletion failed', [
                    'original_path' => $originalPath,
                    'error'         => $e->getMessage(),
                ]);
            }
        }

        // 3. Delete the master file last.
        try {
            if (! Storage::disk($disk)->delete($path)) {
                $success = false;
            }
        } catch (Throwable $e) {
            $success = false;
            Log::warning('Zerofyi\\Media: Master deletion failed', [
                'path'  => $path,
                'error' => $e->getMessage(),
            ]);
        }

        return $success;
    }

    /**
     * Generate the public URL for a stored path.
     */
    public function url(?string $path, ?string $disk = null): ?string
    {
        if (empty($path)) {
            return null;
        }

        return Storage::disk($disk ?? (string) config('media.disk', 'public'))->url($path);
    }

    // -------------------------------------------------------------------------
    // Private – processing helpers
    // -------------------------------------------------------------------------

    /**
     * Sanitize and store an SVG file.
     */
    private function handleSvg(
        UploadedFile $file,
        string $slug,
        string $folder,
        string $uuid,
        string $originalName,
        string $disk,
        ?string $originalPath,
    ): StoredImageResult {
        $filename = $this->buildFilename($slug, $uuid, 'svg');
        $path     = "{$folder}/{$filename}";
        $raw      = file_get_contents($file->getRealPath());
        if ($raw === false || $raw === '') {
            $this->cleanupPaths([$originalPath], $disk);
            throw ImageStorageException::invalidContent('Could not read SVG file content.');
        }
        $cleanSvg = $this->svgSanitizer->sanitize($raw);

        if ($cleanSvg === false || $cleanSvg === '') {
            $this->cleanupPaths([$originalPath], $disk);
            throw ImageStorageException::invalidContent('SVG content failed sanitization check.');
        }

        Storage::disk($disk)->put($path, $cleanSvg);

        return new StoredImageResult(
            uuid:         $uuid,
            path:         $path,
            url:          Storage::disk($disk)->url($path),
            filename:     $filename,
            originalName: $originalName,
            size:         (int) Storage::disk($disk)->size($path),
            mime:         'image/svg+xml',
            disk:         $disk,
            width:        null,
            height:       null,
            originalPath: $originalPath,
            variants:     [],
        );
    }

    /**
     * Generate all requested responsive variants.
     * Individual variant failures are logged and skipped — they never abort the upload.
     *
     * The source image is decoded once and cloned per variant to avoid
     * redundant disk reads and decode overhead.
     *
     * @param  bool|array<string>    $variantSelection
     * @return array<string, string>
     */
    private function generateVariants(
        UploadedFile $file,
        string $filename,
        string $folder,
        string $disk,
        bool|array $variantSelection,
    ): array {
        $activeKeys     = $this->resolveActiveVariants($variantSelection);
        $presetVariants = (array) config('media.variants', []);
        $generated      = [];

        if (empty($activeKeys)) {
            return [];
        }

        // Decode once — clone per variant to avoid redundant disk I/O.
        try {
            $sourceImage = method_exists($this->manager, 'decodePath')
                ? $this->manager->decodePath($file->getRealPath())
                : $this->manager->read($file->getRealPath());
        } catch (Throwable $e) {
            Log::warning('Zerofyi\\Media: Could not decode source image for variants', [
                'file'  => $filename,
                'error' => $e->getMessage(),
            ]);

            return [];
        }

        foreach ($activeKeys as $presetKey) {
            if (! isset($presetVariants[$presetKey])) {
                continue;
            }

            $preset          = $presetVariants[$presetKey];
            $variantFolder   = $this->sanitizeFolder("Variants/{$folder}/{$presetKey}");
            $variantFilename = "{$presetKey}_{$filename}";
            $variantPath     = "{$variantFolder}/{$variantFilename}";

            try {
                $image = clone $sourceImage;

                if (($preset['fit'] ?? 'scale_down') === 'cover' && ! empty($preset['height'])) {
                    $image->cover((int) $preset['width'], (int) $preset['height']);
                } else {
                    $image->scaleDown(
                        width:  isset($preset['width'])  ? (int) $preset['width']  : null,
                        height: isset($preset['height']) ? (int) $preset['height'] : null,
                    );
                }

                $encoded = $image->encode(new WebpEncoder(quality: (int) ($preset['quality'] ?? 80)));
                Storage::disk($disk)->put($variantPath, (string) $encoded);

                $generated[$presetKey] = $variantPath;
            } catch (Throwable $e) {
                Log::warning('Zerofyi\\Media: Variant generation failed', [
                    'preset' => $presetKey,
                    'file'   => $filename,
                    'error'  => $e->getMessage(),
                ]);
                // Intentionally continues — a failed variant must never abort the upload.
            }
        }

        return $generated;
    }

    // -------------------------------------------------------------------------
    // Private – validation
    // -------------------------------------------------------------------------

    private function validate(UploadedFile $file, int $maxSizeKb, array $allowedTypes): void
    {
        $this->assertMimeAllowed($file, $allowedTypes);
        $this->assertNoDoubleExtension($file);
        $this->assertFileSize($file, $maxSizeKb);

        if ($file->getMimeType() !== 'image/svg+xml') {
            $this->assertPixelLimit($file);
            $this->assertMagicBytes($file);
        }
    }

    private function assertMimeAllowed(UploadedFile $file, array $allowed): void
    {
        $mime = (string) $file->getMimeType();

        if (! in_array($mime, $allowed, true)) {
            throw ImageStorageException::invalidType($mime, $allowed);
        }
    }

    private function assertNoDoubleExtension(UploadedFile $file): void
    {
        $originalName = $file->getClientOriginalName();
        $innerExt     = strtolower(
            pathinfo(pathinfo($originalName, PATHINFO_FILENAME), PATHINFO_EXTENSION)
        );

        if ($innerExt !== '' && in_array($innerExt, self::DANGEROUS_EXTENSIONS, true)) {
            throw ImageStorageException::invalidContent(
                "Double-extension attack detected in filename \"{$originalName}\"."
            );
        }
    }

    private function assertFileSize(UploadedFile $file, int $maxSizeKb): void
    {
        $actualKb = $file->getSize() / 1024;

        if ($actualKb > $maxSizeKb) {
            throw ImageStorageException::sizeExceeded($actualKb, $maxSizeKb);
        }
    }

    private function assertPixelLimit(UploadedFile $file): void
    {
        $maxPixels = (int) config('media.max_pixel_count', 25_000_000);
        $realPath  = $file->getRealPath();

        if ($realPath === false || ! is_readable($realPath)) {
            throw ImageStorageException::invalidContent('Uploaded file is not readable.');
        }

        // getimagesize() reads only the image header — no full file load into memory.
        // Suppress the warning with @ and check the return value instead.
        $dimensions = @getimagesize($realPath);

        if ($dimensions === false) {
            throw ImageStorageException::invalidContent('Unable to determine image dimensions.');
        }

        [$w, $h] = $dimensions;

        if (($w * $h) > $maxPixels) {
            throw ImageStorageException::invalidContent(
                sprintf(
                    'Image resolution (%dx%d) exceeds the maximum allowed pixel count of %d.',
                    $w,
                    $h,
                    $maxPixels,
                )
            );
        }
    }

    private function assertMagicBytes(UploadedFile $file): void
    {
        $mime = (string) $file->getMimeType();

        if (! array_key_exists($mime, self::MAGIC_BYTES)) {
            return; // Unknown type — MIME validation already passed, nothing more to check.
        }

        $handle = fopen($file->getRealPath(), 'rb');

        if ($handle === false) {
            throw ImageStorageException::invalidContent('Could not open file for magic-byte inspection.');
        }

        $header = fread($handle, 12);
        fclose($handle);

        if ($header === false || $header === '') {
            throw ImageStorageException::invalidContent('Could not read file header bytes.');
        }

        // WebP needs a two-part check: RIFF????WEBP
        if ($mime === 'image/webp') {
            if (! (str_starts_with($header, 'RIFF') && substr($header, 8, 4) === 'WEBP')) {
                throw ImageStorageException::invalidContent(
                    'File content does not match declared MIME type "image/webp".'
                );
            }

            return;
        }

        foreach (self::MAGIC_BYTES[$mime] as $signature) {
            if (str_starts_with($header, $signature)) {
                return;
            }
        }

        throw ImageStorageException::invalidContent(
            "File content does not match declared MIME type \"{$mime}\"."
        );
    }

    // -------------------------------------------------------------------------
    // Private – resolution helpers
    // -------------------------------------------------------------------------

    /**
     * @param  bool|array<string> $variants
     * @return array<string>
     */
    private function resolveActiveVariants(bool|array $variants): array
    {
        $presetKeys = array_keys((array) config('media.variants', []));

        if ($variants === false) {
            return [];
        }

        if ($variants === true) {
            return (array) config('media.default_variants', ['thumb', 'sm', 'md']);
        }

        return array_values(array_intersect($variants, $presetKeys));
    }

    /**
     * @param  array<string>|null $callerTypes
     * @return array<string>
     */
    private function resolveAllowedTypes(?array $callerTypes): array
    {
        $configured = (array) config('media.allowed_mime', [
            'image/jpeg', 'image/png', 'image/gif',
            'image/webp', 'image/bmp', 'image/svg+xml',
        ]);

        if ($callerTypes === null) {
            return $configured;
        }

        $resolved = array_values(array_intersect($callerTypes, $configured));

        return empty($resolved) ? $configured : $resolved;
    }

    /**
     * Normalise a folder string and guard against path-traversal in all known forms.
     */
    private function sanitizeFolder(string $folder): string
    {
        $lower = strtolower($folder);

        foreach (self::FORBIDDEN_FOLDER_PATTERNS as $pattern) {
            if (str_contains($lower, $pattern)) {
                throw ImageStorageException::invalidContent(
                    'Folder path contains a forbidden sequence: "' . $pattern . '".'
                );
            }
        }

        return trim((string) preg_replace('#/+#', '/', $folder), '/');
    }

    private function buildFilename(string $slug, string $uuid, string $ext): string
    {
        $cleanSlug = Str::slug($slug);
        $prefix    = $cleanSlug !== '' ? Str::substr($cleanSlug, 0, 80) . '_' : 'img_';

        return "{$prefix}{$uuid}.{$ext}";
    }

    private function mimeToExtension(UploadedFile $file): string
    {
        $map = [
            'image/jpeg'    => 'jpg',
            'image/png'     => 'png',
            'image/gif'     => 'gif',
            'image/webp'    => 'webp',
            'image/bmp'     => 'bmp',
            'image/svg+xml' => 'svg',
        ];

        return $map[(string) $file->getMimeType()] ?? strtolower($file->getClientOriginalExtension()) ?: 'jpg';
    }

    /**
     * Best-effort cleanup of partially written files during a failed upload.
     *
     * @param array<string|null> $paths
     */
    private function cleanupPaths(array $paths, string $disk): void
    {
        foreach ($paths as $path) {
            if ($path === null || $path === '') {
                continue;
            }

            try {
                if (Storage::disk($disk)->exists($path)) {
                    Storage::disk($disk)->delete($path);
                }
            } catch (Throwable) {
                // Best-effort — ignore individual cleanup failures.
            }
        }
    }
}