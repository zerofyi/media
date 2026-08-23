# `zerofyi/media`

> Production-grade Laravel media suite — zero-orphan storage lifecycle, automated WebP conversion, responsive variant generation, SVG sanitization, and atomic Eloquent orchestration.

[![Latest Version on Packagist](https://img.shields.io/packagist/v/zerofyi/media.svg?style=flat-square)](https://packagist.org/packages/zerofyi/media)
[![Total Downloads](https://img.shields.io/packagist/dt/zerofyi/media.svg?style=flat-square)](https://packagist.org/packages/zerofyi/media)
[![PHP Version](https://img.shields.io/packagist/php-v/zerofyi/media?style=flat-square)](https://packagist.org/packages/zerofyi/media)
[![License](https://img.shields.io/packagist/l/zerofyi/media.svg?style=flat-square)](https://packagist.org/packages/zerofyi/media)

---

## Table of Contents

1. [What This Package Does](#1-what-this-package-does)
2. [Requirements](#2-requirements)
3. [Installation](#3-installation)
4. [Configuration Reference](#4-configuration-reference)
5. [Database Migration](#5-database-migration)
6. [Core Concepts](#6-core-concepts)
7. [AssetService — Full API Reference](#7-assetservice--full-api-reference)
   - [store()](#71-store)
   - [replace()](#72-replace)
   - [delete()](#73-delete)
   - [getModelClass()](#74-getmodelclass)
8. [Using the Facade](#8-using-the-facade)
9. [HasAssets Trait — Full API Reference](#9-hasassets-trait--full-api-reference)
   - [uploadAsset()](#91-uploadasset)
   - [replaceAsset()](#92-replaceasset)
   - [deleteAsset()](#93-deleteasset)
   - [assets()](#94-assets)
   - [primaryAsset()](#95-primaryasset)
10. [Asset Model — Attributes & Helpers](#10-asset-model--attributes--helpers)
11. [Variant Presets — Configuration & Usage](#11-variant-presets--configuration--usage)
12. [Storage Disks](#12-storage-disks)
13. [Security Layer Explained](#13-security-layer-explained)
14. [Exception Handling](#14-exception-handling)
15. [Customising the Asset Model](#15-customising-the-asset-model)
16. [Using with Queues](#16-using-with-queues)
17. [Common Real-World Recipes](#17-common-real-world-recipes)
18. [File & Folder Layout on Disk](#18-file--folder-layout-on-disk)
19. [Changelog](#19-changelog)
20. [License](#20-license)

---

## 1. What This Package Does

`zerofyi/media` gives you a complete, self-contained image-upload pipeline for Laravel applications. It handles every step so you don't have to:

- **Validates** the upload (MIME, file size, magic bytes, pixel count, double-extension attacks, SVG XSS).
- **Processes** the image (converts JPEG/PNG/BMP to WebP, optionally keeps a raw original).
- **Generates responsive variants** (thumb, sm, md, lg) in a single call.
- **Writes** all files to any Laravel filesystem disk (local, S3, GCS, …).
- **Persists** a database record atomically — if the DB insert fails, every file written during that call is automatically purged (zero-orphan guarantee).
- **Tracks** exact variant paths in the DB so deletion is accurate regardless of future config changes.
- **Exposes** clean URL accessors and a polymorphic Eloquent relationship so any model (`Post`, `Product`, `User`) can own assets.

---

## 2. Requirements

| Dependency | Version |
|---|---|
| PHP | `^8.2 \| ^8.3 \| ^8.4 \| ^8.5` |
| Laravel | `^12.0 \| ^13.0` |
| `intervention/image` | `^4.2` |
| `enshrined/svg-sanitize` | `^0.22` |
| GD **or** Imagick PHP extension | Either (Imagick preferred) |

> **Laravel 10 / 11 not supported.** This package uses the `#[Fillable]` attribute
> (`Illuminate\Database\Eloquent\Attributes\Fillable`) which was introduced in Laravel 12.
> If you are on an older version, replace the attribute with a `$fillable` array in the
> published `Asset` model stub.

---

## 3. Installation

```bash
composer require zerofyi/media
```

Laravel's package auto-discovery registers the service provider and the `Media` facade alias automatically. No manual registration is needed.

### Publish assets

```bash
# Publish everything at once (recommended for a fresh install)
php artisan vendor:publish --tag=media

# Or publish individually
php artisan vendor:publish --tag=media-config      # config/media.php
php artisan vendor:publish --tag=media-migrations  # database/migrations/*_create_assets_table.php
php artisan vendor:publish --tag=media-model       # app/Models/Asset.php stub
```

### Run the migration

```bash
php artisan migrate
```

---

## 4. Configuration Reference

After publishing, edit `config/media.php`. Every key can also be set through environment variables.

```php
return [

    /*
    |--------------------------------------------------------------------------
    | Storage Disk
    |--------------------------------------------------------------------------
    | The Laravel filesystem disk that images are written to.
    | Must be defined in config/filesystems.php.
    |
    | ENV: MEDIA_DISK
    | Default: 'public'
    */
    'disk' => env('MEDIA_DISK', 'public'),

    /*
    |--------------------------------------------------------------------------
    | Maximum Upload Size
    |--------------------------------------------------------------------------
    | Maximum allowed file size in kilobytes.
    | Files larger than this are rejected before any processing.
    |
    | ENV: MEDIA_MAX_KB
    | Default: 5120 (5 MB)
    */
    'max_size_kb' => (int) env('MEDIA_MAX_KB', 5120),

    /*
    |--------------------------------------------------------------------------
    | Maximum Pixel Count
    |--------------------------------------------------------------------------
    | Decompression bomb guard. Images whose width × height exceeds this
    | value are rejected before the image library ever decodes them.
    |
    | ENV: MEDIA_MAX_PIXELS
    | Default: 25,000,000 (≈ 5000×5000)
    */
    'max_pixel_count' => (int) env('MEDIA_MAX_PIXELS', 25_000_000),

    /*
    |--------------------------------------------------------------------------
    | Eloquent Asset Model
    |--------------------------------------------------------------------------
    | The model class used to create and query asset records.
    | After publishing and extending the model stub, swap this to your class.
    |
    | Default: Zerofyi\Media\Models\Asset::class
    */
    'model' => \Zerofyi\Media\Models\Asset::class,

    /*
    |--------------------------------------------------------------------------
    | Allowed MIME Types
    |--------------------------------------------------------------------------
    | Only files whose server-detected MIME type is in this list are accepted.
    | Remove types you don't need (e.g. remove 'image/svg+xml' if you don't
    | want SVG uploads).
    |
    | Default: jpeg, png, gif, webp, bmp, svg
    */
    'allowed_mime' => [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'image/bmp',
        'image/svg+xml',
    ],

    /*
    |--------------------------------------------------------------------------
    | Default WebP Quality
    |--------------------------------------------------------------------------
    | Quality setting applied when encoding images to WebP.
    | Range: 1 (worst) – 100 (best). 80–90 is recommended for production.
    |
    | Default: 85
    */
    'default_quality' => 85,

    /*
    |--------------------------------------------------------------------------
    | Responsive Variant Presets
    |--------------------------------------------------------------------------
    | Named presets that generate resized WebP copies of the master image.
    | You may add, rename, or remove presets freely.
    |
    | Keys:
    |   width   (int|null)    Target width in pixels. null = unconstrained.
    |   height  (int|null)    Target height in pixels. null = unconstrained.
    |   fit     (string)      'cover'      — crop to exact width×height.
    |                         'scale_down' — proportional resize, never upscale.
    |   quality (int)         WebP quality for this variant (1–100).
    */
    'variants' => [
        'thumb' => ['width' => 200,  'height' => 200,  'fit' => 'cover',      'quality' => 75],
        'sm'    => ['width' => 480,  'height' => null,  'fit' => 'scale_down', 'quality' => 80],
        'md'    => ['width' => 768,  'height' => null,  'fit' => 'scale_down', 'quality' => 85],
        'lg'    => ['width' => 1200, 'height' => null,  'fit' => 'scale_down', 'quality' => 85],
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Variants
    |--------------------------------------------------------------------------
    | The preset keys generated when you pass variants: true to store() / replace().
    | Must be a subset of the keys defined above in 'variants'.
    |
    | Default: ['thumb', 'sm', 'md']
    */
    'default_variants' => ['thumb', 'sm', 'md'],

];
```

### Environment variables quick reference

| Variable | Default | Description |
|---|---|---|
| `MEDIA_DISK` | `public` | Storage disk name |
| `MEDIA_MAX_KB` | `5120` | Max upload size in KB |
| `MEDIA_MAX_PIXELS` | `25000000` | Max pixel count (decompression bomb guard) |

---

## 5. Database Migration

The migration creates the `assets` table with the following schema:

| Column | Type | Nullable | Notes |
|---|---|---|---|
| `id` | bigint | No | Auto-increment primary key |
| `uuid` | string | No | Unique UUID (stable record identity) |
| `disk` | string | No | Filesystem disk name |
| `path` | string | No | Relative path to the master file |
| `original_name` | string | Yes | Client-supplied original filename |
| `mime_type` | string(100) | No | MIME type of the stored file |
| `size` | bigint unsigned | No | Size of the master file in bytes |
| `width` | int unsigned | Yes | Pixel width (null for SVG) |
| `height` | int unsigned | Yes | Pixel height (null for SVG) |
| `original_path` | string | Yes | Path to raw original (when keepOriginal: true) |
| `variants` | json | Yes | Map of `presetKey => storedPath` |
| `type` | string | Yes | Free-form label for your own categorization |
| `assetable_type` | string | Yes | Polymorphic morph type |
| `assetable_id` | bigint | Yes | Polymorphic morph ID |
| `uploaded_by` | bigint | Yes | FK → users.id (nullOnDelete) |
| `created_at` | timestamp | Yes | |
| `updated_at` | timestamp | Yes | |

An index is created on `(disk, path)` for efficient path lookups.

---

## 6. Core Concepts

### Zero-orphan guarantee

When you call `store()`, the package first writes all files to disk, then opens a database transaction. If the transaction fails for any reason (constraint violation, connection loss, etc.), every file written during that call — master, variants, original — is immediately deleted before the exception is re-thrown. No stale files are ever left on disk.

### Stable UUID identity

Every asset record has a `uuid` column. When you call `replace()`, the DB record is updated in-place and the UUID does **not** change. External references (cached URLs, foreign keys, API responses) that point to the UUID remain valid after replacement.

### Variant paths are stored, not derived

Many media packages re-derive variant paths from config at query time, which breaks when you rename a folder or change a preset name. This package stores the exact path of every variant in the `variants` JSON column at upload time. Deletion reads those stored paths, not the current config, so no files are ever left behind.

### Image driver auto-detection

The package checks whether the `imagick` PHP extension is loaded at boot time. If it is, Imagick is used (better colour accuracy, broader format support). Otherwise it falls back to GD. No configuration needed.

---

## 7. AssetService — Full API Reference

Resolve via the service container or use the `Media` facade:

```php
use Zerofyi\Media\Services\AssetService;

$service = app(AssetService::class);
```

---

### 7.1 `store()`

Upload a file, process it, and create a new `Asset` DB record atomically.

```php
public function store(
    UploadedFile $file,
    string       $slug,
    string       $folder,
    array        $attributes    = [],
    bool|array   $variants      = false,
    bool         $convertToWebp  = true,
    bool         $preserveIfWebp = true,
    bool         $keepOriginal   = false,
    ?int         $quality        = null,
    ?int         $maxSizeKb      = null,
    ?string      $disk           = null,
    ?array       $allowedTypes   = null,
): Model
```

#### Parameters

| Parameter | Type | Required | Default | Description |
|---|---|---|---|---|
| `$file` | `UploadedFile` | ✅ Yes | — | The uploaded file from `$request->file('...')` |
| `$slug` | `string` | ✅ Yes | — | Used as a human-readable prefix in the output filename. Slugified automatically. Pass `''` to get an `img_` prefix. |
| `$folder` | `string` | ✅ Yes | — | Relative folder path within the disk (e.g. `'products'`, `'users/avatars'`). Must not contain `..`. |
| `$attributes` | `array` | ❌ No | `[]` | Extra columns to merge into the asset record. Common keys: `type`, `uploaded_by`, `assetable_type`, `assetable_id`. |
| `$variants` | `bool\|array` | ❌ No | `false` | `false` = no variants. `true` = generate `default_variants` from config. `['thumb', 'lg']` = generate only those named presets. |
| `$convertToWebp` | `bool` | ❌ No | `true` | Convert JPEG, PNG, and BMP to WebP. Set to `false` to keep the original format. |
| `$preserveIfWebp` | `bool` | ❌ No | `true` | When the uploaded file is already WebP, copy raw bytes without re-encoding. Only applies when `$convertToWebp` is `true`. |
| `$keepOriginal` | `bool` | ❌ No | `false` | Store an untouched copy of the source file under `Originals/{folder}/`. The path is saved in `assets.original_path`. |
| `$quality` | `?int` | ❌ No | `null` | WebP encoding quality (1–100). `null` uses `config('media.default_quality')` (default 85). |
| `$maxSizeKb` | `?int` | ❌ No | `null` | Per-call file size ceiling in KB. `null` uses `config('media.max_size_kb')` (default 5120). |
| `$disk` | `?string` | ❌ No | `null` | Override the storage disk for this call only. `null` uses `config('media.disk')`. |
| `$allowedTypes` | `?array` | ❌ No | `null` | Restrict accepted MIME types for this call. Must be a subset of `config('media.allowed_mime')`. |

#### Examples

**Minimal:**
```php
$asset = app(AssetService::class)->store(
    file:   $request->file('photo'),
    slug:   'product-hero',
    folder: 'products',
);

echo $asset->url;        // Public URL of the WebP master
echo $asset->mime_type;  // 'image/webp'
echo $asset->width;      // pixels
```

**With default variants:**
```php
$asset = app(AssetService::class)->store(
    file:     $request->file('photo'),
    slug:     'product-hero',
    folder:   'products',
    variants: true,  // generates thumb, sm, md
);

echo $asset->variantUrl('thumb');  // 200×200 cover-crop WebP
echo $asset->variantUrl('md');     // 768px wide WebP
```

**Specific variants only:**
```php
$asset = app(AssetService::class)->store(
    file:     $request->file('photo'),
    slug:     'banner',
    folder:   'banners',
    variants: ['thumb', 'lg'],
);
```

**Keep the raw original:**
```php
$asset = app(AssetService::class)->store(
    file:         $request->file('photo'),
    slug:         'product-hero',
    folder:       'products',
    keepOriginal: true,
);

echo $asset->original_url;  // URL to untouched source file
```

**Preserve original format (no WebP conversion):**
```php
$asset = app(AssetService::class)->store(
    file:          $request->file('photo'),
    slug:          'product-hero',
    folder:        'products',
    convertToWebp: false,
);
```

**Custom quality and per-call size limit:**
```php
$asset = app(AssetService::class)->store(
    file:      $request->file('photo'),
    slug:      'avatar',
    folder:    'avatars',
    quality:   90,
    maxSizeKb: 2048,
);
```

**Store to a different disk for this call:**
```php
$asset = app(AssetService::class)->store(
    file:   $request->file('photo'),
    slug:   'document-cover',
    folder: 'covers',
    disk:   's3',
);
```

**Restrict MIME types per call:**
```php
$asset = app(AssetService::class)->store(
    file:         $request->file('avatar'),
    slug:         'avatar',
    folder:       'avatars',
    allowedTypes: ['image/jpeg', 'image/png'],
);
```

**Attach to a model:**
```php
$asset = app(AssetService::class)->store(
    file:       $request->file('photo'),
    slug:       $product->slug,
    folder:     'products',
    attributes: [
        'type'           => 'gallery',
        'assetable_type' => $product->getMorphClass(),
        'assetable_id'   => $product->getKey(),
        'uploaded_by'    => auth()->id(),
    ],
    variants: true,
);
```

---

### 7.2 `replace()`

Replace an existing asset's files while preserving its UUID and database identity.

```php
public function replace(
    Model        $asset,
    UploadedFile $file,
    string       $slug,
    string       $folder,
    array        $attributes    = [],
    bool|array   $variants      = false,
    bool         $convertToWebp  = true,
    bool         $preserveIfWebp = true,
    bool         $keepOriginal   = false,
    ?int         $quality        = null,
    ?int         $maxSizeKb      = null,
    ?string      $disk           = null,
    ?array       $allowedTypes   = null,
): Model
```

#### Replacement sequence

1. New files are written to disk.
2. The DB record is updated inside a transaction (UUID preserved).
3. **If the DB update fails** → new files are purged, exception is re-thrown, old files remain.
4. **If the DB update succeeds** → old master, variants, and original are purged from disk.

```php
$updated = app(AssetService::class)->replace(
    asset:    $asset,
    file:     $request->file('photo'),
    slug:     'updated-photo',
    folder:   'products',
    variants: true,
);

echo $updated->uuid;  // same as $asset->uuid before replace
```

---

### 7.3 `delete()`

Delete all physical files associated with an asset, then delete the DB record.

```php
public function delete(Model $asset): bool
```

Returns `true` on full success. Each file is attempted independently; one missing file never blocks the rest (logs a warning instead).

```php
app(AssetService::class)->delete($asset);
// or: Media::delete($asset);
// or: $model->deleteAsset($asset);
```

> ⚠️ Never call `$asset->delete()` alone — that removes the DB record but leaves all physical files on disk as orphans.

---

### 7.4 `getModelClass()`

Returns the fully-qualified class name of the configured asset model.

```php
$class = app(AssetService::class)->getModelClass();
// → 'Zerofyi\Media\Models\Asset'  (or your custom class if configured)
```

---

## 8. Using the Facade

```php
use Zerofyi\Media\Facades\Media;

$asset   = Media::store($file, 'my-photo', 'products', variants: true);
$updated = Media::replace($asset, $newFile, 'new-photo', 'products');
Media::delete($asset);
```

---

## 9. HasAssets Trait — Full API Reference

Add `HasAssets` to any Eloquent model to get upload, replace, and delete helpers plus polymorphic relationship methods.

```php
use Zerofyi\Media\Traits\HasAssets;

class Product extends Model
{
    use HasAssets;
}
```

---

### 9.1 `uploadAsset()`

```php
public function uploadAsset(
    UploadedFile $file,
    string       $slug,
    string       $folder,
    ?string      $type        = null,
    bool|array   $variants    = false,
    bool         $keepOriginal = false,
    ?int         $uploadedBy  = null,
): Model
```

| Parameter | Type | Required | Default | Description |
|---|---|---|---|---|
| `$file` | `UploadedFile` | ✅ Yes | — | The file to upload. |
| `$slug` | `string` | ✅ Yes | — | Filename slug prefix. |
| `$folder` | `string` | ✅ Yes | — | Storage folder. |
| `$type` | `?string` | ❌ No | `null` | Free-form label stored in `assets.type`. Use to distinguish `'avatar'`, `'banner'`, `'gallery'`, etc. |
| `$variants` | `bool\|array` | ❌ No | `false` | Variant generation (same as `store()`). |
| `$keepOriginal` | `bool` | ❌ No | `false` | Keep untouched original. |
| `$uploadedBy` | `?int` | ❌ No | `null` | Uploader user ID. `null` falls back to `auth()->id()` when a session is active. **Always pass explicitly in CLI, queue, or API contexts.** |

The asset is automatically attached (sets `assetable_type` and `assetable_id`).

```php
// In a controller
$product->uploadAsset(
    file:     $request->file('image'),
    slug:     $product->slug,
    folder:   'products',
    type:     'gallery',
    variants: ['thumb', 'sm'],
);

// In a queue job (no auth session)
$product->uploadAsset(
    file:       $file,
    slug:       $product->slug,
    folder:     'products',
    uploadedBy: $userId,  // always explicit in queued context
);
```

---

### 9.2 `replaceAsset()`

```php
public function replaceAsset(
    Model        $asset,
    UploadedFile $file,
    string       $slug,
    string       $folder,
    ?string      $type        = null,
    bool|array   $variants    = false,
    bool         $keepOriginal = false,
    ?int         $uploadedBy  = null,
): Model
```

Parameters are identical to `uploadAsset()` plus `$asset` as the first argument.

---

### 9.3 `deleteAsset()`

```php
public function deleteAsset(Model $asset): bool
```

---

### 9.4 `assets()`

```php
public function assets(): MorphMany
```

All assets polymorphically attached to this model.

```php
$product->assets;
$product->assets()->where('type', 'gallery')->get();
$product->assets()->count();
Product::with('assets')->get();
```

---

### 9.5 `primaryAsset()`

```php
public function primaryAsset(): MorphOne
```

The most recently uploaded asset (`latestOfMany()`). Use as a "featured image" or "current avatar".

```php
$product->primaryAsset;
Product::with('primaryAsset')->get();
```

---

## 10. Asset Model — Attributes & Helpers

### Stored attributes

| Attribute | PHP type | Description |
|---|---|---|
| `id` | `int` | Auto-increment PK |
| `uuid` | `string` | Unique UUID — stable across replacements |
| `disk` | `string` | Filesystem disk name |
| `path` | `string` | Relative path to the master file |
| `original_name` | `string\|null` | Client-supplied filename at upload time |
| `mime_type` | `string` | MIME type of the stored file |
| `size` | `int` | Bytes of the master file |
| `width` | `int\|null` | Pixel width (null for SVG) |
| `height` | `int\|null` | Pixel height (null for SVG) |
| `original_path` | `string\|null` | Path to raw original (null if not kept) |
| `variants` | `array\|null` | Map of `presetKey => storedPath` |
| `type` | `string\|null` | Free-form label |
| `assetable_type` | `string\|null` | Polymorphic morph type |
| `assetable_id` | `int\|string\|null` | Polymorphic morph ID |
| `uploaded_by` | `int\|null` | FK to users.id |

### Computed accessors

| Accessor | Returns | Description |
|---|---|---|
| `$asset->url` | `string\|null` | Public URL of the master file |
| `$asset->original_url` | `string\|null` | Public URL of the raw original, or `null` |

### Methods

#### `variantUrl(string $presetKey): ?string`

Returns the public URL for a named variant. Falls back to `$asset->url` if that variant was not generated.

```php
$asset->variantUrl('thumb');  // 200×200 cover WebP
$asset->variantUrl('xl');     // falls back to master URL (xl not generated)
```

#### `allVariantUrls(): array`

```php
$asset->allVariantUrls();
// ['thumb' => 'https://...', 'sm' => 'https://...', 'md' => 'https://...']
```

#### `findByPk(int $id): ?static`

Safe numeric PK lookup. Because `HasUuids` overrides `find()` to search the `uuid` column, `Asset::find(42)` always returns `null`. Use this method instead:

```php
// ✅ Correct — find by UUID
Asset::find('550e8400-e29b-41d4-a716-446655440000');

// ✅ Correct — find by numeric PK
Asset::findByPk(42);
Asset::where('id', 42)->firstOrFail();

// ❌ Always returns null — HasUuids searches the uuid column
Asset::find(42);
```

### Relationships

#### `assetable(): MorphTo`

```php
$asset->assetable;  // Product, Post, User, etc.
```

#### `uploader(): BelongsTo`

```php
$asset->uploader->name;  // 'John Doe'
```

---

## 11. Variant Presets — Configuration & Usage

### Defining presets

```php
// config/media.php
'variants' => [
    'thumb' => ['width' => 200,  'height' => 200,  'fit' => 'cover',      'quality' => 75],
    'sm'    => ['width' => 480,  'height' => null,  'fit' => 'scale_down', 'quality' => 80],
    'md'    => ['width' => 768,  'height' => null,  'fit' => 'scale_down', 'quality' => 85],
    'lg'    => ['width' => 1200, 'height' => null,  'fit' => 'scale_down', 'quality' => 85],
],
```

### Adding a custom preset (e.g. OG image)

```php
'og' => ['width' => 1200, 'height' => 630, 'fit' => 'cover', 'quality' => 90],
```

```php
$asset = Media::store($file, 'post-cover', 'posts', variants: ['og', 'thumb']);
echo $asset->variantUrl('og');  // 1200×630 cover crop
```

### Variant `variants` parameter behaviour

| Value | Behaviour |
|---|---|
| `false` (default) | No variants generated |
| `true` | Generates all keys in `config('media.default_variants')` |
| `['thumb', 'lg']` | Generates only those two presets |

### Variant failures are non-fatal

If a single variant fails (e.g. memory limit), a warning is logged and the upload continues. The `variants` array on the resulting record simply omits the failed key. The master file is always written.

---

## 12. Storage Disks

### Local / public disk (default)

```php
// .env
MEDIA_DISK=public
// Then run: php artisan storage:link
```

### Amazon S3

```php
// config/filesystems.php
's3' => [
    'driver' => 's3',
    'key'    => env('AWS_ACCESS_KEY_ID'),
    'secret' => env('AWS_SECRET_ACCESS_KEY'),
    'region' => env('AWS_DEFAULT_REGION'),
    'bucket' => env('AWS_BUCKET'),
    'url'    => env('AWS_URL'),
],

// .env
MEDIA_DISK=s3
```

### Per-call disk override

```php
$asset = Media::store($file, 'photo', 'products', disk: 's3');
```

### Multi-disk in one application

```php
$avatar  = Media::store($file, 'avatar', 'avatars',  disk: 'local');
$product = Media::store($file, 'photo',  'products', disk: 's3');

// Each record stores its own disk — deletion always uses the stored disk
Media::delete($avatar);   // reads $avatar->disk  = 'local'
Media::delete($product);  // reads $product->disk = 's3'
```

---

## 13. Security Layer Explained

Every upload passes through all of the following checks before any file is written:

### 1. MIME whitelist
Server-detected MIME type (not the client header) must be in `config('media.allowed_mime')`.

### 2. Double-extension attack detection
Filenames like `shell.php.jpg` are rejected when the inner extension is in the dangerous list (`php`, `phar`, `asp`, `sh`, `exe`, …).

### 3. File size check
Raw byte count compared against `max_size_kb` before the image library touches the file.

### 4. Pixel count check (decompression bomb guard)
For raster images, `width × height` is compared against `max_pixel_count`. The check uses `getimagesize()` which reads only the image header — not the full file — so memory usage is minimal.

### 5. Magic-byte verification
The first 12 bytes of the file are read and compared against known signatures for each MIME type. A JPEG content-type with PNG bytes is rejected. WebP uses a two-part check (`RIFF` at byte 0 + `WEBP` at byte 8).

### 6. SVG sanitization
SVG files skip the pixel/magic-byte checks (SVG is XML, not raster) but are passed through `enshrined/svg-sanitize`, which strips `<script>` tags, `on*` event handlers, `javascript:` URIs, and `data:` URI payloads.

### 7. Path-traversal guard
Folder arguments are checked for `..`, `%2e%2e`, `%252e`, and null bytes (`\0`). Output filenames are generated by the package (`Str::slug() + UUID`) — no part of the client filename ever reaches the filesystem path.

---

## 14. Exception Handling

All validation and storage errors throw `Zerofyi\Media\Exceptions\ImageStorageException` (extends `RuntimeException`).

### Exception codes

| Constant | Value | Thrown when |
|---|---|---|
| `CODE_INVALID_TYPE` | `1001` | MIME type not in the allowed list |
| `CODE_SIZE_EXCEEDED` | `1002` | File size exceeds the maximum |
| `CODE_INVALID_CONTENT` | `1003` | Magic bytes mismatch, double extension, bad SVG, path traversal, unreadable file |
| `CODE_STORAGE_FAILURE` | `1004` | Image encoding or disk write failed |

### Handling in a controller

```php
use Zerofyi\Media\Exceptions\ImageStorageException;
use Zerofyi\Media\Facades\Media;

try {
    $asset = Media::store($request->file('photo'), 'photo', 'products');
} catch (ImageStorageException $e) {
    $message = match ($e->getCode()) {
        ImageStorageException::CODE_INVALID_TYPE    => 'That file type is not allowed.',
        ImageStorageException::CODE_SIZE_EXCEEDED   => 'The file is too large.',
        ImageStorageException::CODE_INVALID_CONTENT => 'The file content is invalid or unsafe.',
        ImageStorageException::CODE_STORAGE_FAILURE => 'Storage is unavailable. Please try again.',
        default                                     => 'Upload failed.',
    };

    return back()->withErrors(['photo' => $message]);
}
```

---

## 15. Customising the Asset Model

### Step 1 — Publish the stub

```bash
php artisan vendor:publish --tag=media-model
```

### Step 2 — Update the config

```php
// config/media.php
'model' => \App\Models\Asset::class,
```

### Step 3 — Extend freely

```php
// app/Models/Asset.php
namespace App\Models;

use Zerofyi\Media\Models\Asset as BaseAsset;

class Asset extends BaseAsset
{
    public function scopeGallery($query)
    {
        return $query->where('type', 'gallery');
    }

    public function product()
    {
        return $this->belongsTo(Product::class, 'assetable_id')
                    ->where('assetable_type', (new Product)->getMorphClass());
    }

    public function isWebp(): bool
    {
        return $this->mime_type === 'image/webp';
    }
}
```

---

## 16. Using with Queues

Always pass `uploadedBy` explicitly in queued jobs — there is no active auth session in a worker:

```php
class ProcessProductImage implements ShouldQueue
{
    public function __construct(
        private readonly Product $product,
        private readonly string  $tempPath,
        private readonly int     $uploadedByUserId,
    ) {}

    public function handle(): void
    {
        $file = new \Illuminate\Http\UploadedFile(
            $this->tempPath,
            basename($this->tempPath),
            mime_content_type($this->tempPath),
            null,
            true,  // test mode — skips is_uploaded_file() check
        );

        $this->product->uploadAsset(
            file:       $file,
            slug:       $this->product->slug,
            folder:     'products',
            type:       'gallery',
            variants:   true,
            uploadedBy: $this->uploadedByUserId,  // always explicit
        );
    }
}
```

---

## 17. Common Real-World Recipes

### Product with a hero image and gallery

```php
class ProductController extends Controller
{
    public function store(StoreProductRequest $request): RedirectResponse
    {
        $product = Product::create($request->validated());

        if ($request->hasFile('hero')) {
            $product->uploadAsset(
                file:     $request->file('hero'),
                slug:     $product->slug . '-hero',
                folder:   'products/heroes',
                type:     'hero',
                variants: true,
            );
        }

        foreach ($request->file('gallery', []) as $image) {
            $product->uploadAsset(
                file:     $image,
                slug:     $product->slug . '-gallery',
                folder:   'products/gallery',
                type:     'gallery',
                variants: ['thumb'],
            );
        }

        return redirect()->route('products.show', $product);
    }
}
```

### User avatar with replace

```php
public function update(Request $request): JsonResponse
{
    $user = $request->user();

    if ($existing = $user->primaryAsset) {
        $user->replaceAsset(
            asset:    $existing,
            file:     $request->file('avatar'),
            slug:     'avatar-' . $user->id,
            folder:   'avatars',
            type:     'avatar',
            variants: ['thumb'],
        );
    } else {
        $user->uploadAsset(
            file:     $request->file('avatar'),
            slug:     'avatar-' . $user->id,
            folder:   'avatars',
            type:     'avatar',
            variants: ['thumb'],
        );
    }

    return response()->json(['avatar_url' => $user->primaryAsset?->variantUrl('thumb')]);
}
```

### Cascade delete on model deletion

```php
// app/Models/Product.php
protected static function booted(): void
{
    static::deleting(function (Product $product) {
        $product->assets->each(fn ($asset) => $product->deleteAsset($asset));
    });
}
```

### SVG-only endpoint

```php
$asset = Media::store(
    file:          $request->file('logo'),
    slug:          'brand-logo',
    folder:        'brand',
    allowedTypes:  ['image/svg+xml'],
    convertToWebp: false,
);
```

### OG image crop

```php
// Add to config/media.php
'og' => ['width' => 1200, 'height' => 630, 'fit' => 'cover', 'quality' => 90],

// Upload
$asset = Media::store($request->file('cover'), 'post-cover', 'posts', variants: ['og', 'md', 'thumb']);
```

```html
<meta property="og:image" content="{{ $asset->variantUrl('og') }}">
```

---

## 18. File & Folder Layout on Disk

Given `folder: 'products'`, `slug: 'my-product'`, UUID `abc-123`, and variants `['thumb', 'sm']`:

```
{disk-root}/
├── products/
│   └── my-product_abc-123.webp              ← master file
├── Variants/
│   └── products/
│       ├── thumb/
│       │   └── thumb_my-product_abc-123.webp
│       └── sm/
│           └── sm_my-product_abc-123.webp
└── Originals/
    └── products/
        └── my-product_abc-123.jpg           ← only when keepOriginal: true
```

Key rules:
- Master files live directly in `{folder}/`.
- Variants live in `Variants/{folder}/{presetKey}/`, prefixed with the preset name.
- Originals live in `Originals/{folder}/`, with the original file extension.
- All variant files are always WebP, regardless of the master format.
- Filenames are `{slug}_{uuid}.{ext}` — no client-supplied name ever appears.

---

## 19. Changelog

See [CHANGELOG.md](CHANGELOG.md).

---

## 20. License

MIT — see [LICENSE](LICENSE).