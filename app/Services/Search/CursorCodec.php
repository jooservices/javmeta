<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\Exceptions\InvalidFilterException;
use JsonException;

/**
 * Opaque keyset cursor (AD-11): a base64url JSON payload of
 * {sort, last_sort, last_id} signed with HMAC-SHA256 over the app key.
 *
 * The client never reads or edits the cursor; a tampered, malformed or
 * foreign-deployment cursor is rejected with 400 invalid_filter instead of
 * producing a wrong page. Decoding returns strictly typed values so the
 * repository can build the keyset WHERE clause without re-parsing.
 */
final class CursorCodec
{
    /** @var list<string> */
    public const SORTS = ['relevance', 'release_date', 'update_date', 'rating'];

    /** @param list<string>|null $allowedSorts */
    public function encode(string $sort, int|float|string|null $lastSort, int $lastId, ?array $allowedSorts = null): string
    {
        $allowedSorts ??= self::SORTS;
        if (! in_array($sort, $allowedSorts, true)) {
            throw new InvalidFilterException('Unsupported cursor sort.');
        }

        if ($lastId < 1) {
            throw new InvalidFilterException('Invalid cursor id.');
        }

        $payload = $this->base64UrlEncode(json_encode([
            'sort' => $sort,
            'last_sort' => $lastSort,
            'last_id' => $lastId,
        ], JSON_THROW_ON_ERROR));

        return $payload . '.' . $this->base64UrlEncode($this->signature($payload));
    }

    /**
     * @return array{sort: string, last_sort: int|float|string|null, last_id: int}
     */
    /** @param list<string>|null $allowedSorts */
    public function decode(string $cursor, ?array $allowedSorts = null): array
    {
        $allowedSorts ??= self::SORTS;
        $parts = explode('.', $cursor, 2);

        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            throw new InvalidFilterException('Malformed cursor.');
        }

        [$payload, $signature] = $parts;

        if (! hash_equals($this->base64UrlEncode($this->signature($payload)), $signature)) {
            throw new InvalidFilterException('Cursor signature mismatch.');
        }

        try {
            $decoded = json_decode($this->base64UrlDecode($payload), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidFilterException('Cursor payload is not valid JSON.');
        }

        if (! is_array($decoded)) {
            throw new InvalidFilterException('Cursor payload is not an object.');
        }

        $sort = $decoded['sort'] ?? null;
        $lastId = $decoded['last_id'] ?? null;
        $lastSort = $decoded['last_sort'] ?? null;

        if (! is_string($sort) || ! in_array($sort, $allowedSorts, true)) {
            throw new InvalidFilterException('Cursor carries an unsupported sort.');
        }

        if (! is_int($lastId) || $lastId < 1) {
            throw new InvalidFilterException('Cursor carries an invalid id.');
        }

        if (! is_int($lastSort) && ! is_float($lastSort) && ! is_string($lastSort) && $lastSort !== null) {
            throw new InvalidFilterException('Cursor carries an invalid sort value.');
        }

        return ['sort' => $sort, 'last_sort' => $lastSort, 'last_id' => $lastId];
    }

    private function signature(string $payload): string
    {
        return hash_hmac('sha256', $payload, (string) config('app.key'), true);
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        if ($decoded === false) {
            throw new InvalidFilterException('Cursor payload is not valid base64url.');
        }

        return $decoded;
    }
}
