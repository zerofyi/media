# `zerofyi/media`

> Production-grade Laravel media suite — zero-orphan storage lifecycle, automated WebP conversion, responsive variant generation, SVG sanitization, and atomic Eloquent orchestration.

[![Latest Version on Packagist](https://img.shields.io/packagist/v/zerofyi/media.svg?style=flat-square)](https://packagist.org/packages/zerofyi/media)
[![Total Downloads](https://img.shields.io/packagist/dt/zerofyi/media.svg?style=flat-square)](https://packagist.org/packages/zerofyi/media)
[![License](https://img.shields.io/packagist/l/zerofyi/media.svg?style=flat-square)](https://packagist.org/packages/zerofyi/media)

---

## Features

- **Zero-Orphan Guarantee** — Atomic DB transactions rollback and purge all disk files (master, variants, raw original) if anything fails during upload or replacement.
- **Accurate Cascade Deletion** — Assets track their exact variant paths in the database. Deletion uses those stored paths, not config-derived guesses, so no files are ever left behind regardless of config changes.
- **Stable Record Identity** — Replacing an asset updates its files but preserves its ULID, so external references (cached URLs, foreign keys) remain valid.
- **WebP Conversion** — JPEG / PNG / BMP are automatically converted to optimised WebP. Native WebP uploads are copied without re-encoding, preventing generational quality loss.
- **Responsive Variant Presets** — Generate `thumb`, `sm`, `md`, and `lg` variants in a single call. All variants are always WebP.
- **SVG Sanitization** — SVG files are passed through `enshrined/svg-sanitize` to strip XSS payloads before storage.
- **Layered Security** — Magic-byte sniffing, MIME whitelist, double-extension attack detection, pixel-count (decompression bomb) limits, and path-traversal guards.
- **Polymorphic Eloquent Trait** — Attach media to any model (`Post`, `Product`, `User`) with a single `use HasAssets`.
- **Laravel 13 Ready** — Built with `#[Fillable]` attributes, anonymous migrations, and `casts()` method conventions.

---

## Requirements

| Dependency | Version |
|---|---|
| PHP | `^8.2 \| ^8.3 \| ^8.4 \| ^8.5` |
| Laravel | `^10 \| ^11 \| ^12 \| ^13` |
| intervention/image | `^4.2` |
| enshrined/svg-sanitize | `^0.22` |
| GD or Imagick extension | Either (Imagick preferred) |

---

## Installation

```bash
composer require zerofyi/media
```

### Publish assets

```bash
# Publish everything (recommended for a fresh install)
php artisan vendor:publish --tag=media

# Or publish individually
php artisan vendor:publish --tag=media-config
php artisan vendor:publish --tag=media-migrations
php artisan vendor:publish --tag=media-model
```

### Run migrations

```bash
php artisan migrate
```

---

## Configuration

After publishing, edit `config/media.php`:

```php
return [
    // Storage disk (must be defined in config/filesystems.php)
    'disk' => env('MEDIA_DISK', 'public'),

    // Maximum upload size
    'max_size_kb' => (int) env('MEDIA_MAX_KB', 5120),       // 5 MB default

    // Decompression bomb guard
    'max_pixel_count' => (int) env('MEDIA_MAX_PIXELS', 25_000_000),

    // Eloquent model (swap for your published App\Models\Asset)
    'model' => \Zerofyi\Media\Models\Asset::class,

    // Allowed MIME types
    'allowed_mime' => [
        'image/jpeg', 'image/png', 'image/gif',
        'image/webp', 'image/bmp', 'image/svg+xml',
    ],

    // Default WebP encoding quality
    'default_quality' => 85,

    // Named responsive variant presets
    'variants' => [
        'thumb' => ['width' => 200,  'height' => 200,  'fit' => 'cover',      'quality' => 75],
        'sm'    => ['width' => 480,  'height' => null,  'fit' => 'scale_down', 'quality' => 80],
        'md'    => ['width' => 768,  'height' => null,  'fit' => 'scale_down', 'quality' => 85],
        'lg'    => ['width' => 1200, 'height' => null,  'fit' => 'scale_down', 'quality' => 85],
    ],

    // Variants generated when variants: true is passed
    'default_variants' => ['thumb', 'sm', 'md'],
];
```

---

## Usage

### Basic upload via `AssetService`

```php
use Zerofyi\Media\Services\AssetService;

$asset = app(AssetService::class)->store(
    file:     $request->file('image'),
    slug:     'my-photo',
    folder:   'products',
);

echo $asset->url;           // Public URL of the master WebP
echo $asset->original_url;  // null unless keepOriginal: true
```

### Upload with variants

```php
$asset = app(AssetService::class)->store(
    file:     $request->file('image'),
    slug:     'my-photo',
    folder:   'products',
    variants: true,          // generates thumb, sm, md (default_variants)
    // variants: ['thumb', 'lg']  // or pick specific presets
);

echo $asset->variantUrl('thumb');  // 200×200 cover crop WebP
echo $asset->variantUrl('md');     // 768px wide WebP
```

### Keep the raw original

```php
$asset = app(AssetService::class)->store(
    file:         $request->file('image'),
    slug:         'my-photo',
    folder:       'products',
    keepOriginal: true,
);

echo $asset->original_url;  // URL to the untouched source file
```

### Restrict to specific MIME types per call

```php
$asset = app(AssetService::class)->store(
    file:         $request->file('avatar'),
    slug:         'avatar',
    folder:       'avatars',
    allowedTypes: ['image/jpeg', 'image/png'],  // overrides config for this call
);
```

### Attach to a model with `HasAssets`

```php
// app/Models/Product.php
use Zerofyi\Media\Traits\HasAssets;

class Product extends Model
{
    use HasAssets;
}
```

```php
// In a controller
$product->uploadAsset(
    file:     $request->file('image'),
    slug:     $product->slug,
    folder:   'products',
    type:     'gallery',       // stored in assets.type for your own filtering
    variants: ['thumb', 'sm'],
);

// Fetch
$product->assets;                              // MorphMany – all assets
$product->primaryAsset;                        // MorphOne – most recently uploaded
$product->assets()->where('type', 'gallery');  // filtered query
```

### Replace an asset

```php
$updated = app(AssetService::class)->replace(
    asset:    $asset,
    file:     $request->file('image'),
    slug:     'new-photo',
    folder:   'products',
    variants: true,
);

// Or via the trait shortcut
$product->replaceAsset($asset, $request->file('image'), 'new-photo', 'products');
```

The asset's ULID is preserved during replacement. Old files (master, variants, original) are purged from disk only after the database record is successfully updated.

### Delete an asset

```php
app(AssetService::class)->delete($asset);

// Or via trait
$product->deleteAsset($asset);
```

All tracked files are removed: master, every variant in `assets.variants`, and the raw original.

### URL helpers on the `Asset` model

```php
$asset->url;               // Master file URL
$asset->original_url;      // Raw original URL (null if not kept)
$asset->variantUrl('sm');  // Named variant URL, falls back to master
$asset->allVariantUrls();  // ['thumb' => '...', 'sm' => '...', ...]
```

---

## Security

| Check | Detail |
|---|---|
| MIME whitelist | Only types listed in `allowed_mime` are accepted. |
| Magic-byte verification | File headers are compared against known signatures for JPEG, PNG, GIF, WebP, and BMP. A file claiming to be a JPEG but opening with PNG bytes is rejected. |
| Double-extension guard | `shell.php.jpg` is rejected before the filename reaches disk. |
| SVG sanitization | All SVG files are sanitized by `enshrined/svg-sanitize` to strip JavaScript, `<script>` tags, event handlers, and `data:` URI payloads. |
| Pixel limit | Images whose `width × height` exceeds `max_pixel_count` are rejected, preventing decompression bomb attacks against the image library. |
| Path traversal | Folder names containing `..` throw immediately. |
| Filename sanitization | Output filenames are `Str::slug()` normalized + ULID suffixed. No client-supplied name ever reaches the filesystem. |

---

## Customising the Asset model

Publish the model stub and extend it:

```bash
php artisan vendor:publish --tag=media-model
```

Then update `config/media.php`:

```php
'model' => \App\Models\Asset::class,
```

Your `App\Models\Asset` extends `Zerofyi\Media\Models\Asset`, giving you full access to add scopes, relationships, and accessors:

```php
class Asset extends BaseAsset
{
    public function scopeGallery($query)
    {
        return $query->where('type', 'gallery');
    }
}
```

---

## Changing the storage disk per call

```php
app(AssetService::class)->store(
    file:   $request->file('image'),
    slug:   'photo',
    folder: 'avatars',
    disk:   's3',  // overrides config('media.disk') for this call
);
```

---

## Handling exceptions

All validation and storage failures throw `Zerofyi\Media\Exceptions\ImageStorageException` (a `RuntimeException`). The exception carries a typed code for programmatic handling:

```php
use Zerofyi\Media\Exceptions\ImageStorageException;

try {
    $asset = app(AssetService::class)->store(...);
} catch (ImageStorageException $e) {
    match ($e->getCode()) {
        ImageStorageException::CODE_INVALID_TYPE    => ..., // Wrong MIME type
        ImageStorageException::CODE_SIZE_EXCEEDED   => ..., // File too large
        ImageStorageException::CODE_INVALID_CONTENT => ..., // Magic bytes / SVG / traversal
        ImageStorageException::CODE_STORAGE_FAILURE => ..., // Disk write failed
    };
}
```

---

## License

MIT — see [LICENSE](LICENSE).