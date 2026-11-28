<?php

declare(strict_types=1);

namespace App\Http\Api\V2;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/** Turns any exception of a /api/v2 request into an RFC 9457 problem+json answer with a stable code. */
final class ProblemRenderer
{
    private const TITLES = [
        400 => 'Bad request', 401 => 'Unauthorized', 403 => 'Forbidden', 404 => 'Not found', 405 => 'Method not allowed', 409 => 'Conflict',
        412 => 'Precondition failed', 419 => 'CSRF token mismatch', 422 => 'Validation failed', 428 => 'Precondition required', 429 => 'Too many requests', 500 => 'Server error',
    ];

    public static function handles(Request $request): bool
    {
        return $request->is('api/v2/*') || $request->is('api/v2');
    }

    public function render(Throwable $exception, Request $request): JsonResponse
    {
        $problem = $this->describe($exception);
        $body = [
            'type' => '/problems/' . $problem['code'],
            'title' => self::TITLES[$problem['status']] ?? 'Error',
            'status' => $problem['status'],
            'code' => $problem['code'],
            'detail' => $problem['detail'],
            'instance' => $request->getRequestUri(),
            'trace_id' => (string) $request->attributes->get('trace_id', $request->headers->get('X-Request-Id', '')),
        ];

        if ($problem['errors'] !== null) {
            $body['errors'] = $problem['errors'];
        }

        return new JsonResponse($body, $problem['status'], $problem['headers'] + ['Content-Type' => 'application/problem+json']);
    }

    /** @return array{status: int, code: string, detail: string, errors: list<array{field: string, code: string}>|null, headers: array<string, string>} */
    private function describe(Throwable $exception): array
    {
        return match (true) {
            $exception instanceof ProblemException => ['status' => $exception->status(), 'code' => $exception->problemCode(), 'detail' => $exception->getMessage(), 'errors' => $exception->errors(), 'headers' => $exception->headers()],
            $exception instanceof ValidationException => ['status' => 422, 'code' => 'validation_failed', 'detail' => 'The request is invalid.', 'errors' => $this->fieldErrors($exception), 'headers' => []],
            $exception instanceof AuthenticationException => ['status' => 401, 'code' => 'authentication_required', 'detail' => 'Sign in to continue.', 'errors' => null, 'headers' => []],
            $exception instanceof ModelNotFoundException => ['status' => 404, 'code' => 'not_found', 'detail' => 'The resource was not found.', 'errors' => null, 'headers' => []],
            $exception instanceof ThrottleRequestsException => ['status' => 429, 'code' => 'too_many_requests', 'detail' => 'Too many requests. Try again later.', 'errors' => null, 'headers' => array_map('strval', $exception->getHeaders())],
            $exception instanceof HttpExceptionInterface => $this->http($exception),
            default => ['status' => 500, 'code' => 'internal_error', 'detail' => 'Unexpected error.', 'errors' => null, 'headers' => []],
        };
    }

    /** @return array{status: int, code: string, detail: string, errors: null, headers: array<string, string>} */
    private function http(HttpExceptionInterface $exception): array
    {
        $status = $exception->getStatusCode();
        $code = match ($status) {
            404 => 'not_found',
            405 => 'method_not_allowed',
            419 => 'csrf_mismatch',
            default => 'request_error',
        };

        return ['status' => $status, 'code' => $code, 'detail' => $exception->getMessage() !== '' ? $exception->getMessage() : (self::TITLES[$status] ?? 'Error'), 'errors' => null, 'headers' => array_map('strval', $exception->getHeaders())];
    }

    /** @return list<array{field: string, code: string}> */
    private function fieldErrors(ValidationException $exception): array
    {
        $out = [];

        foreach ($exception->validator->failed() as $field => $rules) {
            foreach (array_keys($rules) as $rule) {
                $out[] = ['field' => (string) $field, 'code' => strtolower((string) $rule)];
            }
        }

        return $out;
    }
}
