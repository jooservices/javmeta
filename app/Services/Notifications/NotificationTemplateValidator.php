<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use InvalidArgumentException;

final class NotificationTemplateValidator
{
    public function __construct(private readonly NotificationRegistry $registry) {}

    public function assertValid(string $key, string $title, string $body): void
    {
        $definition = $this->registry->get($key);
        if ($definition === null) {
            throw new InvalidArgumentException('The notification type is not supported.');
        }

        $allowed = array_column($definition['variables'], 'name');
        foreach ([$title, $body] as $template) {
            $this->assertNoSecret($template);
            preg_match_all('/\{([^{}]+)\}/', $template, $matches);
            $withoutPlaceholders = preg_replace('/\{[^{}]+\}/', '', $template);
            if (is_string($withoutPlaceholders) && (str_contains($withoutPlaceholders, '{') || str_contains($withoutPlaceholders, '}'))) {
                throw new InvalidArgumentException('Template placeholders must use the form {variable}.');
            }

            foreach ($matches[1] as $placeholder) {
                if (! in_array($placeholder, $allowed, true)) {
                    throw new InvalidArgumentException('Unknown template variable: {' . $placeholder . '}.');
                }
            }

            if ($definition['channel'] === 'telegram') {
                $this->assertSafeTelegramMarkup($template);
            }
        }
    }

    private function assertNoSecret(string $template): void
    {
        $patterns = [
            '/\b\d{5,12}:[A-Za-z0-9_-]{20,}\b/',
            '/\bBearer\s+[A-Za-z0-9._~+\/-]{12,}\b/i',
            '/\beyJ[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\b/',
            '/\b(?:api[_-]?key|secret|password|token)\s*[:=]\s*[A-Za-z0-9._-]{16,}\b/i',
            '/(?<![A-Za-z0-9_-])[A-Za-z0-9_-]{40,}(?![A-Za-z0-9_-])/',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $template) === 1) {
                throw new InvalidArgumentException('Template text cannot contain token-like secrets.');
            }
        }
    }

    private function assertSafeTelegramMarkup(string $template): void
    {
        $withoutAllowedTags = preg_replace('/<\/?(?:b|i|u|s|code|pre)>/i', '', $template);
        if (! is_string($withoutAllowedTags) || preg_match('/<[^>]*>/', $withoutAllowedTags) === 1) {
            throw new InvalidArgumentException('Telegram templates allow only b, i, u, s, code, and pre tags without attributes.');
        }

        preg_match_all('/<(\/)?(b|i|u|s|code|pre)>/i', $template, $matches, PREG_SET_ORDER);
        $openTags = [];
        foreach ($matches as $match) {
            $tag = strtolower($match[2]);
            if ($match[1] === '/') {
                if (array_pop($openTags) !== $tag) {
                    throw new InvalidArgumentException('Telegram template tags must be properly nested and closed.');
                }

                continue;
            }

            $openTags[] = $tag;
        }

        if ($openTags !== []) {
            throw new InvalidArgumentException('Telegram template tags must be properly nested and closed.');
        }
    }
}
