<?php

declare(strict_types=1);

namespace Zerofyi\Media\ValueObjects;

final readonly class StoredImageResult
{
    /**
     * @param array<string, string> $variants Map of preset => relative path
     */
    public function __construct(
        public string $uuid,
        public string $path,
        public string $url,
        public string $filename,
        public ?string $originalName,
        public int $size,
        public string $mime,
        public string $disk,
        public ?int $width = null,
        public ?int $height = null,
        public ?string $originalPath = null,
        public array $variants = [],
    ) {}

    public function toArray(): array
    {
        return [
            'uuid' => $this->uuid,
            'path' => $this->path,
            'url' => $this->url,
            'filename' => $this->filename,
            'original_name' => $this->originalName,
            'size' => $this->size,
            'mime' => $this->mime,
            'disk' => $this->disk,
            'width' => $this->width,
            'height' => $this->height,
            'original_path' => $this->originalPath,
            'variants' => $this->variants,
        ];
    }
}