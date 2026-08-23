# Changelog

All notable changes to `zerofyi/media` are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
This project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [1.0.0] – 2026-08-23

### Added

- **`ImageStorageService::upload()`** — Validate, process, and persist images with a layered
  security stack: MIME whitelist, magic-byte verification, double-extension guard, pixel-count
  limit (decompression bomb protection), SVG sanitization, and path-traversal blocking.
- **WebP pipeline** — Automatic WebP re-encoding for JPEG / PNG / BMP uploads.
  Native WebP uploads are copied byte-for-byte without re-encoding to prevent generational
  quality loss (`preserveIfWebp: true`). Both behaviours are opt-out per call.
- **Responsive variant presets** — Built-in `thumb` (200×200 cover), `sm` (480 px),
  `md` (768 px), `lg` (1200 px). All variants are always stored as WebP. Individual variant
  failures are logged and skipped; they never abort the parent upload.
- **`ImageStorageService::delete()`** — Cascade-deletes the master file, all tracked variant
  files (read from the stored `variants` JSON map, not re-derived from config), and the raw
  original when present.
- **`AssetService::store()`** — Orchestrates upload and atomic DB insertion. If the DB
  transaction fails after files are written, every physical file (master, variants, original)
  is purged before the exception is re-thrown — zero-orphan guarantee.
- **`AssetService::replace()`** — Writes new files to disk, updates the existing DB record
  in a transaction while preserving its UUID (stable identity), then purges the old files
  only after the DB update succeeds. On DB failure the new files are purged and the old ones
  remain untouched.
- **`AssetService::delete()`** — Purges all physical files using the model's stored paths,
  then removes the DB record.
- **`Asset` Eloquent model** — Polymorphic asset model using the `#[Fillable]` attribute
  (Laravel 12+ native), `casts()` method, `url` / `original_url` accessors,
  `variantUrl(string $key)`, `allVariantUrls()`, and `findByPk(int $id)` (safe PK lookup
  that works correctly alongside `HasUuids`).
- **`HasAssets` trait** — `uploadAsset()`, `replaceAsset()`, `deleteAsset()` helpers plus
  `assets()` (MorphMany) and `primaryAsset()` (MorphOne → latestOfMany). The `uploadedBy`
  parameter defaults safely to `auth()->id()` when a session is active and `null` in CLI /
  queue contexts (`auth()->check()` guard applied).
- **`StoredImageResult` value object** — Immutable `final readonly` DTO returned by
  `ImageStorageService::upload()` and consumed by `AssetService`.
- **`ImageStorageException`** — Typed `RuntimeException` with four named codes:
  `CODE_INVALID_TYPE` (1001), `CODE_SIZE_EXCEEDED` (1002), `CODE_INVALID_CONTENT` (1003),
  `CODE_STORAGE_FAILURE` (1004).
- **`Media` facade** — Proxies `AssetService` for static-style access. Registered
  automatically via package auto-discovery.
- **`MediaServiceProvider`** — Auto-discovery via `extra.laravel.providers`. Tag-based
  publishing: `media`, `media-config`, `media-migrations`, `media-model`. A migration
  duplicate guard uses `glob()` to prevent repeated `vendor:publish` from creating duplicate
  migration files.
- **Security layer** — Magic-byte header verification for JPEG, PNG, GIF, WebP (RIFF + WEBP
  two-part check), and BMP. Double-extension detection (`shell.php.jpg` → rejected).
  Decompression bomb prevention via configurable pixel-count limit (default 25 MP).
  SVG XSS sanitization via `enshrined/svg-sanitize`. Path-traversal guard on all folder
  arguments including URL-encoded variants (`%2e%2e`, `%252e`) and null bytes.
- **Quality and size overrides** — `quality` and `maxSizeKb` can be set per call, overriding
  the global config values.
- **Per-call disk override** — `disk` can be set per call, enabling multi-disk applications
  (local + S3 in the same codebase) without touching config.
- **Per-call MIME restriction** — `allowedTypes` narrows the accepted MIME set for a single
  call (e.g. avatar endpoints that only accept JPEG/PNG).
- **`keepOriginal` mode** — Stores a byte-identical copy of the raw uploaded file under
  `Originals/{folder}/` before any processing. The path is persisted in `assets.original_path`.
- **Image driver auto-detection** — Imagick used when the extension is loaded; GD otherwise.
  No configuration needed.
- **`composer test` scripts** — `test`, `test:unit`, `test:feature`, `test:coverage`.
- Support for **PHP 8.2, 8.3, 8.4, 8.5** and **Laravel 12, 13**.

### Compatibility note

This package requires **Laravel 12 or 13**. Laravel 10 and 11 are not supported because the
`#[Fillable]` attribute (`Illuminate\Database\Eloquent\Attributes\Fillable`) was introduced
in Laravel 12. If you are on Laravel 10 or 11, you must replace the attribute with a
`$fillable` array in the `Asset` model.

---

[1.0.0]: https://github.com/zerofyi/media/releases/tag/v1.0.0