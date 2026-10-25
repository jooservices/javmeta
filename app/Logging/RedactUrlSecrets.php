<?php

declare(strict_types=1);

namespace App\Logging;

use App\Services\Settings\SettingUrl;
use Illuminate\Log\Logger;
use Monolog\LogRecord;
use Throwable;

/** Redact before formatting so local files and remote sinks have the same protection. */
final class RedactUrlSecrets
{
    public function __invoke(Logger $logger): void
    {
        $logger->pushProcessor(fn(LogRecord $record): LogRecord => $record->with(
            message: SettingUrl::redactText($record->message),
            context: $this->context($record->context),
            extra: $this->context($record->extra),
        ));
    }

    /** @param array<string|int, mixed> $context
     * @return array<string|int, mixed>
     */
    private function context(array $context): array
    {
        foreach ($context as $key => $value) {
            if ($value instanceof Throwable) {
                $original = (string) $value;
                $clean = SettingUrl::redactText($original);
                // Keep structured exception metadata unless it would expose URL credentials.
                $value = $clean === $original ? $value : $clean;
            }
            $context[$key] = is_array($value) ? $this->context($value) : (is_string($value) ? SettingUrl::redactText($value) : $value);
        }

        return $context;
    }
}
