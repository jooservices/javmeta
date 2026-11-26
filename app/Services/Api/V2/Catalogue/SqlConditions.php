<?php

declare(strict_types=1);

namespace App\Services\Api\V2\Catalogue;

/** A growing WHERE clause with its positional bindings, kept in textual order. */
final class SqlConditions
{
    /** @var list<string> */
    private array $parts = [];

    /** @var list<mixed> */
    private array $bindings = [];

    public function add(string $sql, mixed ...$bindings): void
    {
        $this->parts[] = $sql;

        foreach ($bindings as $binding) {
            $this->bindings[] = $binding;
        }
    }

    public function isEmpty(): bool
    {
        return $this->parts === [];
    }

    public function sql(): string
    {
        return $this->parts === [] ? 'TRUE' : implode(' AND ', $this->parts);
    }

    /** @return list<mixed> */
    public function bindings(): array
    {
        return $this->bindings;
    }

    /** @param list<string|int> $items */
    public static function pgArray(array $items): string
    {
        return '{' . implode(',', array_map(static fn(string|int $item): string => '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], (string) $item) . '"', $items)) . '}';
    }

    public static function like(string $text): string
    {
        return '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($text)) . '%';
    }
}
