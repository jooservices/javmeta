<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Observability\TelemetrySanitizer;
use App\Support\NodeIdentity;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Throwable;

final class NotificationRenderer
{
    public function __construct(
        private readonly NotificationRegistry $registry,
        private readonly NotificationTemplateService $templates,
        private readonly NotificationTemplateValidator $validator,
        private readonly ?TelemetrySanitizer $sanitizer = null,
    ) {}

    /** @param array<string, mixed> $values
     *  @return array{key: string, channel: string, title: string, body: string, title_template: string, body_template: string, severity: string, used_default: bool}
     */
    public function render(string $key, array $values = []): array
    {
        $definition = $this->registry->get($key);
        if ($definition === null) {
            throw new InvalidArgumentException('The notification type is not supported.');
        }

        $source = $definition;
        $usedDefault = true;
        try {
            $stored = $this->templates->findStored($key);
            if ($stored !== null && $stored['enabled']) {
                $this->validator->assertValid($key, $stored['title_template'], $stored['body_template']);
                $source = $stored;
                $usedDefault = false;
            }
        } catch (Throwable) {
            // A missing or damaged DB record must never stop an alert from being sent.
        }

        return $this->renderTemplates($key, $source, $values, $usedDefault, true);
    }

    /** @param array<string, mixed> $values
     *  @return array{key: string, channel: string, title: string, body: string, title_template: string, body_template: string, severity: string, used_default: bool}
     */
    public function preview(string $key, string $title, string $body, array $values = []): array
    {
        $definition = $this->registry->get($key);
        if ($definition === null) {
            throw new InvalidArgumentException('The notification type is not supported.');
        }
        $this->validator->assertValid($key, $title, $body);

        return $this->renderTemplates($key, [...$definition, 'title_template' => $title, 'body_template' => $body], $values, false, false);
    }

    public function markSent(string $key): void
    {
        $this->templates->markSent($key);
    }

    /** @return array{title_template: string, body_template: string} */
    public function templates(string $key): array
    {
        $definition = $this->registry->get($key);
        if ($definition === null) {
            throw new InvalidArgumentException('The notification type is not supported.');
        }

        try {
            $stored = $this->templates->findStored($key);
            if ($stored !== null && $stored['enabled']) {
                $this->validator->assertValid($key, $stored['title_template'], $stored['body_template']);

                return [
                    'title_template' => $stored['title_template'],
                    'body_template' => $stored['body_template'],
                ];
            }
        } catch (Throwable) {
            // A missing or damaged DB record must never stop an alert from being sent.
        }

        return [
            'title_template' => $definition['title_template'],
            'body_template' => $definition['body_template'],
        ];
    }

    /** @param array<string, mixed> $source
     *  @param array<string, mixed> $values
     *  @return array{key: string, channel: string, title: string, body: string, title_template: string, body_template: string, severity: string, used_default: bool}
     */
    private function renderTemplates(string $key, array $source, array $values, bool $usedDefault, bool $live): array
    {
        $severity = strtolower((string) ($values['severity'] ?? $source['default_severity']));
        $resolved = strtolower((string) ($values['alert_state'] ?? '')) === 'resolved' || $severity === 'resolved';
        if ($resolved) {
            $severity = 'resolved';
        }
        if (! in_array($severity, ['critical', 'warning', 'notice', 'info', 'resolved'], true)) {
            $severity = (string) $source['default_severity'];
        }

        $labels = [
            'critical' => ['CRITICAL', '🔴'],
            'warning' => ['WARNING', '🟠'],
            'notice' => ['NOTICE', '🟡'],
            'info' => ['INFO', '🔵'],
            'resolved' => ['RESOLVED', '🟢'],
        ];
        [$level, $marker] = $labels[$severity];
        $samples = $this->registry->samples($key);
        $variables = $live
            ? array_fill_keys(array_keys($samples), '')
            : array_map(fn(mixed $value): string => $this->stringValue($value), $samples);
        foreach ($values as $name => $value) {
            if (array_key_exists($name, $variables) && (is_scalar($value) || $value === null)) {
                $variables[$name] = $this->stringValue($value);
            }
        }
        foreach (['blocked_sources', 'exhausted_sources', 'failing_checks', 'newly_blocked', 'recovered_sources', 'source_slugs', 'worst_sources'] as $listName) {
            if (isset($variables[$listName])) {
                $variables[$listName] = $this->limitedList($variables[$listName]);
            }
        }
        $variables['level'] = $level;
        $variables['level_marker'] = $marker;
        $variables['state_prefix'] = $resolved ? 'Back to normal. ' : '';
        $variables['time'] = $this->timeValue($variables['time'] ?? null, $live);
        if (array_key_exists('node', $variables)) {
            $node = $values['node'] ?? ($live ? NodeIdentity::current() : $variables['node']);
            $variables['node'] = $this->stringValue($node);
        }

        $escape = in_array($source['channel'], ['telegram', 'openobserve'], true);
        $title = $this->replace((string) $source['title_template'], $variables, $escape);
        $body = $this->replace((string) $source['body_template'], $variables, $escape);

        return [
            'key' => $key,
            'channel' => (string) $source['channel'],
            'title' => $title,
            'body' => $body,
            'title_template' => (string) $source['title_template'],
            'body_template' => (string) $source['body_template'],
            'severity' => $severity,
            'used_default' => $usedDefault,
        ];
    }

    /** @param array<string, string|int|float|bool> $variables */
    private function replace(string $template, array $variables, bool $escape): string
    {
        return preg_replace_callback('/\{([a-z][a-z0-9_]*)\}/', static function (array $match) use ($variables, $escape): string {
            $value = (string) ($variables[$match[1]] ?? '');
            if (! $escape) {
                return $value;
            }

            return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }, $template) ?? $template;
    }

    private function stringValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value)) {
            return number_format($value, 0, '.', ',');
        }
        if (is_float($value)) {
            return number_format($value, 1, '.', ',');
        }

        if (! is_scalar($value)) {
            return '';
        }

        $text = (string) $value;
        try {
            return ($this->sanitizer ?? app(TelemetrySanitizer::class))->sanitizeText($text) ?? '';
        } catch (Throwable) {
            return '[redacted]';
        }
    }

    private function limitedList(string $value): string
    {
        $items = array_values(array_filter(array_map('trim', explode(',', $value)), static fn(string $item): bool => $item !== ''));
        $visible = array_slice($items, 0, 5);
        if (count($items) > 5) {
            $visible[] = '+' . (count($items) - 5) . ' more';
        }

        return implode(', ', $visible);
    }

    private function timeValue(mixed $sample, bool $live): string
    {
        if (! $live && is_string($sample)) {
            return $sample;
        }

        $timezone = (string) config('javmeta_alerts.hourly_report.timezone', 'America/Mexico_City');
        if ($timezone === 'America/Mexico_City') {
            return CarbonImmutable::now($timezone)->format('M j, H:i') . ' (Mexico)';
        }

        return CarbonImmutable::now($timezone)->format('M j, H:i') . ' (' . $timezone . ')';
    }
}
