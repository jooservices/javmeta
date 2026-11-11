<?php

declare(strict_types=1);

namespace App\Data\Photos;

use App\Exceptions\InvalidFilterException;

/** Bounded, URL-safe gallery identity, with legacy key decoding and row-based v2 IDs. */
final class GalleryId
{
    public const MAX_ENCODED_LENGTH = 8192;

    public function __construct(
        public readonly string $type,
        public readonly int $ownerId,
        public readonly string $source,
        public readonly string $galleryKey,
        public readonly ?int $mediaId = null,
    ) {}

    public static function fromMediaId(string $type, int $ownerId, string $source, int $mediaId): self
    {
        return new self($type, $ownerId, $source, '', $mediaId);
    }

    public function encode(): string
    {
        if (! in_array($this->type, ['performer', 'movie'], true) || $this->ownerId < 1 || $this->source === '') {
            throw new InvalidFilterException(__('photos.invalid_gallery'));
        }

        if (($this->mediaId !== null && $this->mediaId < 1)
            || ($this->mediaId === null && ($this->galleryKey === '' || str_contains($this->source, '|')))) {
            throw new InvalidFilterException(__('photos.invalid_gallery'));
        }

        $raw = $this->mediaId === null
            ? $this->type . '|' . $this->ownerId . '|' . $this->source . '|' . $this->galleryKey
            : implode('|', [
                'v2',
                $this->type,
                $this->ownerId,
                self::base64UrlEncode($this->source),
                $this->mediaId,
            ]);
        $encoded = self::base64UrlEncode($raw);

        if (strlen($encoded) > self::MAX_ENCODED_LENGTH) {
            throw new InvalidFilterException(__('photos.invalid_gallery'));
        }

        return $encoded;
    }

    public static function decode(string $id): self
    {
        if ($id === '' || strlen($id) > self::MAX_ENCODED_LENGTH || preg_match('/\A[A-Za-z0-9_-]+\z/', $id) !== 1) {
            throw new InvalidFilterException(__('photos.invalid_gallery'));
        }

        $padded = strtr($id, '-_', '+/');
        $pad = strlen($padded) % 4;
        if ($pad > 0) {
            $padded .= str_repeat('=', 4 - $pad);
        }
        $raw = base64_decode($padded, true);
        if ($raw === false || ! self::isSafeText($raw)) {
            throw new InvalidFilterException(__('photos.invalid_gallery'));
        }

        if (str_starts_with($raw, 'v2|')) {
            $parts = explode('|', $raw, 5);
            if (count($parts) !== 5 || ! in_array($parts[1], ['performer', 'movie'], true)) {
                throw new InvalidFilterException(__('photos.invalid_gallery'));
            }

            $ownerId = filter_var($parts[2], FILTER_VALIDATE_INT);
            $source = self::base64UrlDecode($parts[3]);
            $mediaId = filter_var($parts[4], FILTER_VALIDATE_INT);
            if ($ownerId === false || $ownerId < 1 || $source === null || ! self::isSafeText($source) || $mediaId === false || $mediaId < 1) {
                throw new InvalidFilterException(__('photos.invalid_gallery'));
            }

            return self::fromMediaId($parts[1], $ownerId, $source, $mediaId);
        }

        $parts = explode('|', $raw, 4);
        $ownerId = $parts[1] ?? null;
        $validatedOwnerId = is_string($ownerId) ? filter_var($ownerId, FILTER_VALIDATE_INT) : false;
        if (count($parts) !== 4 || ! in_array($parts[0], ['performer', 'movie'], true) || $validatedOwnerId === false || $validatedOwnerId < 1 || $parts[2] === '' || $parts[3] === '') {
            throw new InvalidFilterException(__('photos.invalid_gallery'));
        }

        return new self($parts[0], $validatedOwnerId, $parts[2], $parts[3]);
    }

    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $value): ?string
    {
        if ($value === '' || preg_match('/\A[A-Za-z0-9_-]+\z/', $value) !== 1) {
            return null;
        }

        $padded = strtr($value, '-_', '+/');
        $pad = strlen($padded) % 4;
        if ($pad > 0) {
            $padded .= str_repeat('=', 4 - $pad);
        }

        $decoded = base64_decode($padded, true);

        return $decoded === false ? null : $decoded;
    }

    private static function isSafeText(string $value): bool
    {
        return ! str_contains($value, "\0") && mb_check_encoding($value, 'UTF-8');
    }
}
