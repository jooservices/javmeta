<?php

declare(strict_types=1);

namespace Tests\E2E\Support;

final class Redactor
{
    private const MASK = '[REDACTED]';

    private const SECRET_HEADERS = ['x-api-key', 'x-owner-token'];

    /** @param list<string> $secrets */
    public function redact(mixed $value, array $secrets = []): mixed
    {
        if (is_array($value)) {
            $redacted = [];
            foreach ($value as $key => $item) {
                $redacted[$key] = is_string($key) && in_array(strtolower($key), self::SECRET_HEADERS, true)
                    ? self::MASK
                    : $this->redact($item, $secrets);
            }

            return $redacted;
        }

        if (! is_string($value)) {
            return $value;
        }

        return $this->redactText($value, $secrets);
    }

    /** @param list<string> $secrets */
    public function redactText(string $text, array $secrets = []): string
    {
        foreach ($secrets as $secret) {
            if ($secret !== '') {
                $text = str_replace($secret, self::MASK, $text);
            }
        }

        return preg_replace('/\bjvm_[A-Za-z0-9_-]+\b/i', self::MASK, $text) ?? $text;
    }

    /** @param array<string, mixed>|null $request @param array<string, mixed>|null $response @param list<string> $secrets */
    public function formatExchange(?array $request, ?array $response, array $secrets = []): string
    {
        if ($request === null && $response === null) {
            return 'No HTTP exchange recorded.';
        }

        return json_encode(
            $this->redact(['request' => $request, 'response' => $response], $secrets),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
        ) ?: 'HTTP exchange could not be encoded.';
    }
}
