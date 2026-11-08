<?php

declare(strict_types=1);

namespace App\Logging;

use App\Observability\OpenObserveClient;
use App\Observability\TelemetrySanitizer;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Throwable;

final class OpenObserveLogHandler extends AbstractProcessingHandler
{
    public function __construct(
        private readonly OpenObserveClient $client,
        private readonly TelemetrySanitizer $sanitizer,
        int|string|Level $level = Level::Debug,
        bool $bubble = true,
    ) {
        parent::__construct($level, $bubble);
    }

    protected function write(LogRecord $record): void
    {
        if (! $this->client->enabled()) {
            return;
        }

        try {
            $this->client->ingestLogs([[
                '_timestamp' => (int) ((float) $record->datetime->format('U.u') * 1_000_000),
                'level' => $record->level->getName(),
                'message' => $this->sanitizer->sanitizeText($record->message, 1000),
                'channel' => $record->channel,
                'context' => $this->sanitizer->sanitizeContext($record->context),
                'extra' => $this->sanitizer->sanitizeContext($record->extra),
                ...$this->exceptionFields($record->context['exception'] ?? null),
            ]]);
        } catch (Throwable) {
            // Fail-open: never break the app logger.
        }
    }

    /**
     * The cause of an error: class, message, file, line and a short trace, so a failure can be debugged from OpenObserve alone.
     *
     * @return array<string, mixed>
     */
    private function exceptionFields(mixed $exception): array
    {
        if (! $exception instanceof Throwable) {
            return [];
        }

        $base = function_exists('base_path') ? base_path() . '/' : '';
        $strip = static fn(string $path): string => $base !== '' && str_starts_with($path, $base) ? substr($path, strlen($base)) : $path;
        $frames = [];
        foreach (array_slice($exception->getTrace(), 0, 10) as $frame) {
            $frames[] = $strip((string) ($frame['file'] ?? '[internal]')) . ':' . ($frame['line'] ?? 0) . ' ' . (isset($frame['class']) ? $frame['class'] . '::' : '') . $frame['function'];
        }

        return [
            'exception_class' => $exception::class,
            'exception_message' => $this->sanitizer->sanitizeText($exception->getMessage(), 800),
            'exception_file' => $strip($exception->getFile()),
            'exception_line' => $exception->getLine(),
            'exception_trace' => $this->sanitizer->sanitizeText(implode("\n", $frames), 2500),
            'exception_previous' => $exception->getPrevious() !== null ? $exception->getPrevious()::class : null,
        ];
    }
}
