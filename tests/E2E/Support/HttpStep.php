<?php

declare(strict_types=1);

namespace Tests\E2E\Support;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;
use Tests\E2E\Context;
use Tests\E2E\Step;

abstract class HttpStep implements Step
{
    /** @param array<string, string> $headers @param array<string, mixed> $payload */
    protected function exchange(Context $context, string $method, string $path, array $headers = [], array $payload = []): HttpResult
    {
        $url = rtrim($context->baseUrl, '/') . $path;
        $requestData = [
            'method' => strtoupper($method),
            'url' => $url,
            'headers' => ['Accept' => 'application/json', ...$headers],
            'body' => strtoupper($method) === 'GET' ? null : $payload,
            'query' => strtoupper($method) === 'GET' ? $payload : null,
        ];

        try {
            $request = Http::acceptJson()->withHeaders($headers)->timeout(15);
            $response = match (strtoupper($method)) {
                'GET' => $request->get($url, $payload),
                'POST' => $request->post($url, $payload),
                'DELETE' => $request->delete($url, $payload),
                default => throw new \InvalidArgumentException('Unsupported E2E HTTP method.'),
            };
        } catch (Throwable $exception) {
            throw new StepFailure(
                'HTTP request failed (' . $exception::class . ').',
                $context->withExchange($requestData, ['error' => $exception::class]),
                $exception,
            );
        }

        $body = $response->json();
        $responseData = [
            'status' => $response->status(),
            'headers' => $response->headers(),
            'body' => $body ?? $response->body(),
        ];

        return new HttpResult($context->withExchange($requestData, $responseData), $response);
    }

    protected function expectStatus(Context $context, Response $response, int $expected): void
    {
        if ($response->status() !== $expected) {
            throw new StepFailure("Expected HTTP {$expected}; received {$response->status()}.", $context);
        }
    }

    protected function credential(Context $context, string $purpose = 'consumer'): ApiKeyCredential
    {
        $credential = $context->key($purpose);
        if ($credential === null) {
            throw new StepFailure("No {$purpose} API key is available in the journey context.", $context);
        }

        return $credential;
    }
}
