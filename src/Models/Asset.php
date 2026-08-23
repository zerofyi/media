<?php

declare(strict_types=1);

namespace Zerofyi\Media\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Zerofyi\Media\Services\ImageStorageService;

/**
 * @property int                       $id
 * @property string                    $uuid
 * @property string                    $disk
 * @property string                    $path
 * @property string|null               $original_name
 * @property string                    $mime_type
 * @property int                       $size
 * @property int|null                  $width
 * @property int|null                  $height
 * @property string|null               $original_path
 * @property array<string,string>|null $variants
 * @property string|null               $type
 * @property string|null               $assetable_type
 * @property int|string|null           $assetable_id
 * @property int|null                  $uploaded_by
 * @property Carbon|null               $created_at
 * @property Carbon|null               $updated_at
 * @property-read string|null          $url
 * @property-read string|null          $original_url
 */
#[Fillable([
    'uuid',
    'disk',
    'path',
    'original_name',
    'mime_type',
    'size',
    'width',
    'height',
    'original_path',
    'variants',
    'type',
    'assetable_type',
    'assetable_id',
    'uploaded_by',
])]
class Asset extends Model
{
    use HasFactory, HasUuids;

    /**
     * Using $fillable alongside #[Fillable] ensures backward compatibility
     * for older Laravel versions and enables property inheritance for child models.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'uuid',
        'disk',
        'path',
        'original_name',
        'mime_type',
        'size',
        'width',
        'height',
        'original_path',
        'variants',
        'type',
        'assetable_type',
        'assetable_id',
        'uploaded_by',
    ];

    /**
     * Get the columns that should receive a unique identifier.
     *
     * @return array<int, string>
     */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    /**
     * ⚠️  IMPORTANT — HasUuids does NOT override find().
     *
     * HasUuids only overrides resolveRouteBinding() (for route model binding).
     * Eloquent's find() ALWAYS searches the primary key (id), regardless of HasUuids.
     *
     *   Asset::find(42)                         ✅ Finds by numeric PK
     *   Asset::find('some-uuid-string')         ❌ Searches id for a string — returns null
     *   Asset::where('uuid', $uuid)->first()    ✅ Correct UUID lookup
     *   Asset::findByPk(42)                     ✅ Explicit PK lookup (self-documenting)
     */
    public static function findByPk(int $id): ?static
    {
        return static::query()->where('id', $id)->first();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size'     => 'integer',
            'width'    => 'integer',
            'height'   => 'integer',
            'variants' => 'array',
        ];
    }

    public function assetable(): MorphTo
    {
        return $this->morphTo();
    }

    public function uploader(): BelongsTo
    {
        /** @var class-string<Model> $userModel */
        $userModel = config('auth.providers.users.model', 'App\\Models\\User');

        return $this->belongsTo($userModel, 'uploaded_by');
    }

    public function getUrlAttribute(): ?string
    {
        return app(ImageStorageService::class)->url($this->path, $this->disk);
    }

    public function getOriginalUrlAttribute(): ?string
    {
        return $this->original_path
            ? app(ImageStorageService::class)->url($this->original_path, $this->disk)
            : null;
    }

    public function variantUrl(string $presetKey): ?string
    {
        $variantPath = $this->variants[$presetKey] ?? null;

        return $variantPath
            ? app(ImageStorageService::class)->url($variantPath, $this->disk)
            : $this->url;
    }

    /**
     * @return array<string, string|null>
     */
    public function allVariantUrls(): array
    {
        $urls = [];

        foreach (array_keys((array) $this->variants) as $key) {
            $urls[$key] = $this->variantUrl($key);
        }

        return $urls;
    }
}