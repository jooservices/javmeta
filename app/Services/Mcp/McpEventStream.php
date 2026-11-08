<?php

declare(strict_types=1);

namespace App\Services\Mcp;

use Closure;

/** Server-sent-events body of the MCP GET endpoint: announces the POST endpoint, then sends keep-alive comments. */
final class McpEventStream
{
    /** @var Closure(int): void */
    private readonly Closure $pause;

    /** @param Closure(int): void|null $pause waits between keep-alives; defaults to sleep() */
    public function __construct(
        private readonly string $endpoint = '/api/v1/mcp',
        private readonly int $keepAlives = 3,
        private readonly int $intervalSeconds = 10,
        ?Closure $pause = null,
    ) {
        $this->pause = $pause ?? static function (int $seconds): void {
            sleep($seconds);
        };
    }

    public function __invoke(): void
    {
        echo 'event: endpoint' . "\n";
        echo 'data: ' . json_encode(['uri' => $this->endpoint]) . "\n\n";
        $this->flush();

        for ($beat = 0; $beat < $this->keepAlives; $beat++) {
            echo ": keep-alive\n\n";
            $this->flush();
            ($this->pause)($this->intervalSeconds);
        }
    }

    private function flush(): void
    {
        if (ob_get_level() > 0) {
            ob_flush();
        }
        flush();
    }
}
