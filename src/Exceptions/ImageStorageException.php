<?php

declare(strict_types=1);

namespace Zerofyi\Media\Exceptions;

use RuntimeException;
use Throwable;

final class ImageStorageException extends RuntimeException
{
    public const CODE_INVALID_TYPE = 1001;
    public const CODE_SIZE_EXCEEDED = 1002;
    public const CODE_INVALID_CONTENT = 1003;
    public const CODE_STORAGE_FAILURE = 1004;

    public static function invalidType(string $mime, array $allowed): self
    {
        return new self(
            sprintf('Invalid file type "%s". Allowed: %s.', $mime, implode(', ', $allowed)),
            self::CODE_INVALID_TYPE
        );
    }

    public static function sizeExceeded(float $actualKb, int $maxKb): self
    {
        return new self(
            sprintf('File size (%.2f KB) exceeds the maximum allowed size (%d KB).', $actualKb, $maxKb),
            self::CODE_SIZE_EXCEEDED
        );
    }

    public static function invalidContent(string $reason): self
    {
        return new self(
            sprintf('File content validation failed: %s', $reason),
            self::CODE_INVALID_CONTENT
        );
    }

    public static function storageFailed(string $path, string $disk, ?Throwable $previous = null): self
    {
        return new self(
            sprintf('Failed to store file at "%s" on disk "%s".', $path, $disk),
            self::CODE_STORAGE_FAILURE,
            $previous
        );
    }
}