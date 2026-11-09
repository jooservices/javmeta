<?php

declare(strict_types=1);

namespace App\Data\Ui;

/** The JSON payload for one owner marks or feed operation. */
final readonly class UiMarksResult
{
    /**
     * @param array{data: list<array<string, mixed>>}|array{data: array<string, mixed>}|array{data: list<array<string, mixed>>, meta: array{total: int}}|array{data: list<array<string, mixed>>, meta: array{total: int, pagination: array{current_page: int, per_page: int, last_page: int, has_more: bool}}}|array{data: list<array<string, mixed>>, meta: array{follows: int, new_since_last_visit: int, last_visit: string|null}} $payload
     */
    public function __construct(public array $payload) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->payload;
    }
}
