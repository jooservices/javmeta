<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use JOOservices\LaravelController\Traits\HasApiResponses;
use RuntimeException;

abstract class ApiProblemException extends RuntimeException
{
    /** @param array<string, string> $headers */
    public function __construct(
        private readonly string $problemType,
        private readonly string $title,
        private readonly int $status,
        string $detail = '',
        private readonly mixed $errors = null,
        private readonly array $headers = [],
    ) {
        parent::__construct($detail !== '' ? $detail : $title, $status);
    }

    public function type(): string
    {
        return $this->problemType;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function render(Request $request): JsonResponse
    {
        unset($request);

        $response = (new class {
            use HasApiResponses;
        })->respondWithProblem(
            title: $this->title,
            status: $this->status,
            detail: $this->getMessage(),
            errors: $this->errors,
            type: $this->problemType,
        );

        return $response->withHeaders($this->headers);
    }
}
