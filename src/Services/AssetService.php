<?php

declare(strict_types=1);

namespace Zerofyi\Media\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Throwable;
use Zerofyi\Media\Models\Asset;

final class AssetService
{
    public function __construct(
        private readonly ImageStorageService $storageService,
    ) {}

    /**
     * Resolve the configured Asset model class.
     *
     * @return class-string<Model>
     */
    public function getModelClass(): string
    {
        return config('media.model', Asset::class);
    }

    /**
     * Upload an image and atomically persist its DB record.
     *
     * Zero-orphan guarantee: if the DB insert fails for any reason, ALL
     * physical files written during this call (master, variants, original) are
     * purged before the exception is re-thrown.
     *
     * @param  array<string, mixed> $attributes  Extra columns (type, uploaded_by, assetable_*, …)
     * @param  bool|array<string>   $variants
     * @throws \Zerofyi\Media\Exceptions\ImageStorageException
     * @throws Throwable
     */
    public function store(
        UploadedFile $file,
        string $slug,
        string $folder,
        array $attributes = [],
        bool|array $variants = false,
        bool $convertToWebp = true,
        bool $preserveIfWebp = true,
        bool $keepOriginal = false,
        ?int $quality = null,
        ?int $maxSizeKb = null,
        ?string $disk = null,
        ?array $allowedTypes = null,
    ): Model {
        $result = $this->storageService->upload(
            file:           $file,
            slug:           $slug,
            folder:         $folder,
            variants:       $variants,
            convertToWebp:  $convertToWebp,
            preserveIfWebp: $preserveIfWebp,
            keepOriginal:   $keepOriginal,
            quality:        $quality,
            maxSizeKb:      $maxSizeKb,
            disk:           $disk,
            allowedTypes:   $allowedTypes,
        );

        try {
            return DB::transaction(function () use ($result, $attributes): Model {
                $modelClass = $this->getModelClass();

                return $modelClass::create(array_merge($attributes, [
                    'uuid'          => $result->uuid,
                    'disk'          => $result->disk,
                    'path'          => $result->path,
                    'original_name' => $result->originalName,
                    'mime_type'     => $result->mime,
                    'size'          => $result->size,
                    'width'         => $result->width,
                    'height'        => $result->height,
                    'original_path' => $result->originalPath,
                    'variants'      => $result->variants,
                ]));
            });
        } catch (Throwable $e) {
            $this->storageService->delete(
                path:         $result->path,
                disk:         $result->disk,
                variants:     $result->variants,
                originalPath: $result->originalPath,
            );

            throw $e;
        }
    }

    /**
     * Replace an existing Asset with a newly uploaded file.
     *
     * Sequence:
     *   1. Write new physical files.
     *   2. Update the DB record (UUID is intentionally preserved – it is the record's identity).
     *   3. On DB failure → purge newly uploaded files and re-throw.
     *   4. On DB success → purge old master, variants, and original from disk.
     *
     * @param  array<string, mixed> $attributes
     * @param  bool|array<string>   $variants
     * @throws \Zerofyi\Media\Exceptions\ImageStorageException
     * @throws Throwable
     */
    public function replace(
        Model $asset,
        UploadedFile $file,
        string $slug,
        string $folder,
        array $attributes = [],
        bool|array $variants = false,
        bool $convertToWebp = true,
        bool $preserveIfWebp = true,
        bool $keepOriginal = false,
        ?int $quality = null,
        ?int $maxSizeKb = null,
        ?string $disk = null,
        ?array $allowedTypes = null,
    ): Model {
        // Capture old file references BEFORE the model is mutated.
        $oldPath         = (string) $asset->path;
        $oldDisk         = (string) $asset->disk;
        $oldVariants     = (array) ($asset->variants ?? []);
        $oldOriginalPath = $asset->original_path ? (string) $asset->original_path : null;

        // 1. Upload new files to disk.
        $result = $this->storageService->upload(
            file:           $file,
            slug:           $slug,
            folder:         $folder,
            variants:       $variants,
            convertToWebp:  $convertToWebp,
            preserveIfWebp: $preserveIfWebp,
            keepOriginal:   $keepOriginal,
            quality:        $quality,
            maxSizeKb:      $maxSizeKb,
            disk:           $disk,
            allowedTypes:   $allowedTypes,
        );

        // 2. Update DB record. Note: the UUID is NOT changed – it is the stable identity
        //    of this record. The new UUID embedded in the filename is purely for uniqueness
        //    on disk and is not exposed as the model's identifier.
        try {
            DB::transaction(function () use ($asset, $result, $attributes): void {
                $asset->update(array_merge($attributes, [
                    'disk'          => $result->disk,
                    'path'          => $result->path,
                    'original_name' => $result->originalName,
                    'mime_type'     => $result->mime,
                    'size'          => $result->size,
                    'width'         => $result->width,
                    'height'        => $result->height,
                    'original_path' => $result->originalPath,
                    'variants'      => $result->variants,
                ]));
            });
        } catch (Throwable $e) {
            // DB failed → purge the newly uploaded files so nothing is orphaned.
            $this->storageService->delete(
                path:         $result->path,
                disk:         $result->disk,
                variants:     $result->variants,
                originalPath: $result->originalPath,
            );

            throw $e;
        }

        // 3. DB succeeded → purge the OLD files using the captured references.
        $this->storageService->delete(
            path:         $oldPath,
            disk:         $oldDisk,
            variants:     $oldVariants,
            originalPath: $oldOriginalPath,
        );

        return $asset->fresh();
    }

    /**
     * Delete all physical files then remove the DB record.
     *
     * Uses the stored `variants` and `original_path` from the model so that
     * only files that actually exist on disk are targeted — regardless of what
     * the current config says.
     */
    public function delete(Model $asset): bool
    {
        $this->storageService->delete(
            path:         (string) $asset->path,
            disk:         (string) $asset->disk,
            variants:     (array) ($asset->variants ?? []),
            originalPath: $asset->original_path ? (string) $asset->original_path : null,
        );

        return (bool) $asset->delete();
    }
}