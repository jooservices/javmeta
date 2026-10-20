<?php

declare(strict_types=1);

namespace App\Services\Mcp;

use App\Observability\ObservabilityEmitter;
use App\Services\Auth\ApiKeyService;
use InvalidArgumentException;
use Throwable;

/**
 * Shared JSON-RPC 2.0 dispatch for the javmeta MCP tools. Transport-agnostic:
 * used by the stdio server (mcp:serve) and the HTTP endpoint (POST /api/v1/mcp).
 */
final class McpRequestHandler
{
    public function __construct(
        private readonly McpToolService $tools,
        private readonly ApiKeyService $apiKeys,
    ) {}

    public function isAuthorized(): bool
    {
        $plain = (string) config('javmeta_auth.mcp_api_key', '');
        if ($plain === '') {
            return false;
        }

        return $this->apiKeys->findActiveByPlaintext($plain) !== null;
    }

    /**
     * Process one decoded JSON-RPC message.
     *
     * @param  array<string, mixed>  $message
     * @return array<string, mixed>|null  Response payload, or null for notifications
     */
    public function handle(array $message, bool $httpAuthorized = false): ?array
    {
        $id = $message['id'] ?? null;
        $method = $message['method'] ?? null;

        if (! is_string($method)) {
            return $this->error($id, -32600, 'Invalid Request');
        }

        try {
            $result = match ($method) {
                'initialize' => [
                    'protocolVersion' => '2024-11-05',
                    'capabilities' => ['tools' => (object) []],
                    'serverInfo' => ['name' => 'javmeta', 'version' => '0.1.0-beta'],
                ],
                'notifications/initialized', 'initialized' => null,
                'tools/list' => ['tools' => $this->tools->definitions()],
                'tools/call' => $this->callTool(
                    is_array($message['params'] ?? null) ? $message['params'] : [],
                    $httpAuthorized,
                ),
                'ping' => (object) [],
                default => throw new InvalidArgumentException("Method not found: {$method}"),
            };
        } catch (Throwable $e) {
            if ($method === 'notifications/initialized' || $method === 'initialized') {
                return null;
            }

            return $this->error($id, -32601, $e->getMessage());
        }

        if ($result === null || ! array_key_exists('id', $message)) {
            return null;
        }

        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    /**
     * Build a JSON-RPC error payload.
     *
     * @return array<string, mixed>
     */
    public function error(mixed $id, int $code, string $message): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => ['code' => $code, 'message' => $message],
        ];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array{content: list<array{type: string, text: string}>, isError?: bool}
     */
    private function callTool(array $params, bool $httpAuthorized): array
    {
        if (! $httpAuthorized && ! $this->isAuthorized()) {
            return [
                'content' => [['type' => 'text', 'text' => json_encode(['error' => 'unauthorized'], JSON_UNESCAPED_UNICODE) ?: '{}']],
                'isError' => true,
            ];
        }

        $name = $params['name'] ?? null;
        $arguments = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];

        if (is_string($name) && $name !== '') {
            app(ObservabilityEmitter::class)->emitOps('mcp_tool', ['tool' => $name]);
        }

        $payload = is_string($name) && $name !== ''
            ? $this->tools->call($name, $arguments)
            : ['error' => 'tool name required'];

        return [
            'content' => [[
                'type' => 'text',
                'text' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '{}',
            ]],
            'isError' => isset($payload['error']),
        ];
    }
}
