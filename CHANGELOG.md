# Changelog

All notable changes to `zerofyi/media` are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
This project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [1.0.0] – 2026-08-22

### Added

- **`ImageStorageService::upload()`** — Validate, process, and persist images with layered
  security (MIME whitelist, magic-byte verification, double-extension guard, pixel-count limit,
  SVG sanitization, path-traversal protection).
- **WebP pipeline** — Automatic WebP re-encoding for JPEG / PNG / BMP; native WebP uploads
  are copied without re-encoding to prevent generational quality loss.
- **Responsive variant presets** — `thumb` (200×200 cover), `sm` (480px), `md` (768px),
  `lg` (1200px). All variants are stored as WebP. Individual variant failures log a warning
  and are skipped; they never abort the upload.
- **`ImageStorageService::delete()`** — Cascade-deletes the master file, all tracked variant
  files (using the stored `variants` map, not config-derived guesses), and the raw original.
- **`AssetService::store()`** — Orchestrates upload and atomic DB insertion. On transaction
  failure all physical files (master, variants, original) are purged before re-throwing.
- **`AssetService::replace()`** — Uploads new files, updates the existing DB record while
  preserving its ULID (stable record identity), then purges old files only after the DB
  update succeeds.
- **`AssetService::delete()`** — Purges all physical files using the model's stored
  `variants` and `original_path`, then deletes the DB record.
- **`Asset` Eloquent model** — Polymorphic, with `#[Fillable]` attribute (Laravel 13 style),
  `casts()` method convention, `url`, `original_url` accessors, `variantUrl()`, and
  `allVariantUrls()` helpers. ULID is explicitly managed by the service layer.
- **`HasAssets` trait** — `uploadAsset()`, `replaceAsset()`, `deleteAsset()` convenience
  wrappers. `uploaded_by` accepts an explicit value, defaulting to `auth()->id()` so CLI,
  queue, and API contexts can pass an ID without breaking existing callers.
- **`StoredImageResult` value object** — Immutable `readonly` DTO returned by
  `ImageStorageService::upload()`.
- **`ImageStorageException`** — Typed `RuntimeException` with four error codes:
  `CODE_INVALID_TYPE`, `CODE_SIZE_EXCEEDED`, `CODE_INVALID_CONTENT`, `CODE_STORAGE_FAILURE`.
- **`MediaServiceProvider`** — Auto-discovery via `extra.laravel.providers`. Tag-based
  publishing: `media`, `media-config`, `media-migrations`, `media-model`. Migration
  duplicate guard via `glob()` (compatible with anonymous migration classes).
- **Intervention Image v4.2** — Uses `ImageManager::imagick()` / `ImageManager::gd()`
  static factories. Imagick is preferred when the extension is available.
- **`enshrined/svg-sanitize` v0.22** — SVG XSS sanitization.
- Support for PHP 8.2, 8.3, 8.4, 8.5 and Laravel 10 – 13.

### Security

- Magic-byte header verification for JPEG, PNG, GIF, WebP (RIFF+WEBP two-part check), BMP.
- Double-extension attack detection (`shell.php.jpg` → rejected).
- Decompression bomb prevention via configurable pixel-count limit.
- SVG XSS sanitization strips `<script>`, event handlers, and `data:` URI payloads.
- Path-traversal guard on all folder arguments.
- Output filenames are `Str::slug()` normalized and suffixed with a ULID; no client-supplied
  name ever reaches the filesystem.