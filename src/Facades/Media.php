<?php

declare(strict_types=1);

namespace Zerofyi\Media\Facades;

use Illuminate\Support\Facades\Facade;
use Zerofyi\Media\Services\AssetService;

/**
 * @method static \Illuminate\Database\Eloquent\Model store(\Illuminate\Http\UploadedFile $file, string $slug, string $folder, array $attributes = [], bool|array $variants = false, bool $convertToWebp = true, bool $preserveIfWebp = true, bool $keepOriginal = false, ?int $quality = null, ?int $maxSizeKb = null, ?string $disk = null, ?array $allowedTypes = null)
 * @method static \Illuminate\Database\Eloquent\Model replace(\Illuminate\Database\Eloquent\Model $asset, \Illuminate\Http\UploadedFile $file, string $slug, string $folder, array $attributes = [], bool|array $variants = false, bool $convertToWebp = true, bool $preserveIfWebp = true, bool $keepOriginal = false, ?int $quality = null, ?int $maxSizeKb = null, ?string $disk = null, ?array $allowedTypes = null)
 * @method static bool delete(\Illuminate\Database\Eloquent\Model $asset)
 * @method static class-string<\Illuminate\Database\Eloquent\Model> getModelClass()
 *
 * @see \Zerofyi\Media\Services\AssetService
 */
class Media extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return AssetService::class;
    }
}