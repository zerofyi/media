<?php

declare(strict_types=1);

namespace Zerofyi\Media\Traits;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Http\UploadedFile;
use Zerofyi\Media\Models\Asset;
use Zerofyi\Media\Services\AssetService;

trait HasAssets
{
    /**
     * All assets polymorphically attached to this model.
     */
    public function assets(): MorphMany
    {
        /** @var class-string<Model> $modelClass */
        $modelClass = config('media.model', Asset::class);

        return $this->morphMany($modelClass, 'assetable');
    }

    /**
     * The most recently attached asset.
     * Use a typed scope on your custom Asset model if you need a "featured" concept.
     */
    public function primaryAsset(): MorphOne
    {
        /** @var class-string<Model> $modelClass */
        $modelClass = config('media.model', Asset::class);

        return $this->morphOne($modelClass, 'assetable')->latestOfMany();
    }

    /**
     * Upload an image and automatically attach it to this model.
     *
     * @param  bool|array<string>  $variants
     * @param  int|null            $uploadedBy  Defaults to the authenticated user's ID.
     *                                          Pass an explicit value for CLI, queue, or API contexts.
     */
    public function uploadAsset(
        UploadedFile $file,
        string $slug,
        string $folder,
        ?string $type = null,
        bool|array $variants = false,
        bool $keepOriginal = false,
        ?int $uploadedBy = null,
    ): Model {
        return app(AssetService::class)->store(
            file:        $file,
            slug:        $slug,
            folder:      $folder,
            attributes:  [
                'type'           => $type,
                'assetable_type' => $this->getMorphClass(),
                'assetable_id'   => $this->getKey(),
                'uploaded_by'    => $uploadedBy ?? auth()->id(),
            ],
            variants:    $variants,
            keepOriginal: $keepOriginal,
        );
    }

    /**
     * Replace the most recent asset attached to this model.
     *
     * @param  bool|array<string>  $variants
     * @param  int|null            $uploadedBy
     */
    public function replaceAsset(
        Model $asset,
        UploadedFile $file,
        string $slug,
        string $folder,
        ?string $type = null,
        bool|array $variants = false,
        bool $keepOriginal = false,
        ?int $uploadedBy = null,
    ): Model {
        return app(AssetService::class)->replace(
            asset:       $asset,
            file:        $file,
            slug:        $slug,
            folder:      $folder,
            attributes:  [
                'type'        => $type,
                'uploaded_by' => $uploadedBy ?? auth()->id(),
            ],
            variants:    $variants,
            keepOriginal: $keepOriginal,
        );
    }

    /**
     * Delete an asset and all its physical files.
     */
    public function deleteAsset(Model $asset): bool
    {
        return app(AssetService::class)->delete($asset);
    }
}