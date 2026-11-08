<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Mcp\McpRequestHandler;
use Illuminate\Console\Command;
use Throwable;

/**
 * Minimal MCP stdio server (JSON-RPC 2.0) for AI integration.
 * Tools: lookup_movies, get_movie, lookup_performers, get_performer.
 * Auth: JAVMETA_MCP_API_KEY must be active and allow the mcp scope (same store as HTTP).
 */
final class McpServeCommand extends Command
{
    protected $signature = 'mcp:serve';

    protected $description = 'Serve JAVMeta MCP tools over stdin/stdout (JSON-RPC).';

    public function handle(McpRequestHandler $handler): int
    {
        if (! $handler->isAuthorized()) {
            $this->error('MCP auth failed: set JAVMETA_MCP_API_KEY to an active jvm_… API key that allows the mcp scope.');

            return self::FAILURE;
        }

        $stdin = fopen('php://stdin', 'r');
        if ($stdin === false) {
            $this->error('Unable to open stdin.');

            return self::FAILURE;
        }

        while (($line = fgets($stdin)) !== false) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            try {
                $message = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            } catch (Throwable) {
                $this->writeRpc($handler->error(null, -32700, 'Parse error'));

                continue;
            }

            if (! is_array($message)) {
                $this->writeRpc($handler->error(null, -32600, 'Invalid Request'));

                continue;
            }

            /** @var array<string, mixed> $message */
            $response = $handler->handle($message);
            if ($response !== null) {
                $this->writeRpc($response);
            }
        }

        return self::SUCCESS;
    }

    /** @param array<string, mixed> $payload */
    private function writeRpc(array $payload): void
    {
        fwrite(STDOUT, json_encode($payload, JSON_UNESCAPED_UNICODE) . "\n");
    }
}
