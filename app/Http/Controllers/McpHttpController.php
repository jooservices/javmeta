<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Mcp\McpRequestHandler;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Streamable-HTTP MCP endpoint (JSON-RPC 2.0 over HTTP).
 * POST /api/v1/mcp → request/response; GET → SSE stream (advertised endpoint + keep-alive).
 * Auth: an active API key allowed to use the `mcp` scope via `X-API-Key` (AuthenticateApiKey middleware).
 */
final class McpHttpController
{
    public function __invoke(Request $request, McpRequestHandler $handler): Response
    {
        if ($request->isMethod('GET')) {
            return $this->stream();
        }

        try {
            $message = json_decode((string) $request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return response()->json($handler->error(null, -32700, 'Parse error'), Response::HTTP_BAD_REQUEST);
        }

        if (! is_array($message)) {
            return response()->json($handler->error(null, -32600, 'Invalid Request'), Response::HTTP_BAD_REQUEST);
        }

        /** @var array<string, mixed> $message */
        $httpAuthorized = $request->attributes->get('api_key') !== null;
        $response = $handler->handle($message, $httpAuthorized);

        if ($response === null) {
            return response()->json(null, Response::HTTP_ACCEPTED);
        }

        return response()->json($response);
    }

    private function stream(): Response
    {
        return response()->stream(function (): void {
            echo 'event: endpoint' . "\n";
            echo 'data: ' . json_encode(['uri' => '/api/v1/mcp']) . "\n\n";
            ob_flush();
            flush();

            for ($i = 0; $i < 3; $i++) {
                echo ": keep-alive\n\n";
                ob_flush();
                flush();
                sleep(10);
            }
        }, Response::HTTP_OK, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
        ]);
    }
}
