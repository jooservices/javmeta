<?php

declare(strict_types=1);

namespace App\Http\Requests\Photos;

use App\Support\Code\NormalizedCode;
use Closure;
use InvalidArgumentException;

/** Safe normalization and validation rules for user supplied photo query text. */
final class PhotoQueryInput
{
    public static function trimmed(mixed $value): mixed
    {
        if ($value === null) {
            return '';
        }
        if (! is_string($value) || ! self::isSafeText($value)) {
            return $value;
        }

        return trim($value);
    }

    public static function validTextRule(): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail): void {
            if (is_string($value) && ! self::isSafeText($value)) {
                $fail(__('photos.invalid_text_filter'));
            }
        };
    }

    public static function validMovieCodeRule(): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value) || $value === '') {
                return;
            }

            try {
                NormalizedCode::from($value);
            } catch (InvalidArgumentException) {
                $fail(__('photos.invalid_movie_code'));
            }
        };
    }

    private static function isSafeText(string $value): bool
    {
        return ! str_contains($value, "\0") && mb_check_encoding($value, 'UTF-8');
    }
}
