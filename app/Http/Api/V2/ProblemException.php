<?php

declare(strict_types=1);

namespace App\Http\Api\V2;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

/** A deliberate answer of the v2 API: an HTTP status, a stable machine code and optional field errors. */
final class ProblemException extends RuntimeException implements ShouldntReport
{
    /**
     * @param list<array{field: string, code: string}>|null $errors
     * @param array<string, string> $headers
     */
    private function __construct(
        private readonly int $status,
        private readonly string $problemCode,
        string $detail,
        private readonly ?array $errors = null,
        private readonly array $headers = [],
    ) {
        parent::__construct($detail, $status);
    }

    public static function unauthorized(string $code = 'authentication_required', string $detail = 'Sign in to continue.'): self
    {
        return new self(401, $code, $detail);
    }

    public static function forbidden(string $code = 'forbidden', string $detail = 'You are not allowed to do this.'): self
    {
        return new self(403, $code, $detail);
    }

    public static function notFound(string $code, string $detail): self
    {
        return new self(404, $code, $detail);
    }

    public static function conflict(string $code, string $detail): self
    {
        return new self(409, $code, $detail);
    }

    public static function preconditionFailed(): self
    {
        return new self(412, 'precondition_failed', 'The resource changed since you loaded it.');
    }

    public static function preconditionRequired(): self
    {
        return new self(428, 'precondition_required', 'The If-Match header is required.');
    }

    /** @param list<array{field: string, code: string}> $errors */
    public static function invalid(string $detail, array $errors = []): self
    {
        return new self(422, 'validation_failed', $detail, $errors);
    }

    public static function badRequest(string $code, string $detail): self
    {
        return new self(400, $code, $detail);
    }

    public static function tooManyRequests(int $retryAfter): self
    {
        return new self(429, 'too_many_requests', 'Too many requests. Try again later.', null, ['Retry-After' => (string) $retryAfter]);
    }

    public function status(): int
    {
        return $this->status;
    }

    public function problemCode(): string
    {
        return $this->problemCode;
    }

    /** @return list<array{field: string, code: string}>|null */
    public function errors(): ?array
    {
        return $this->errors;
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return $this->headers;
    }
}
