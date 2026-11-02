<?php

declare(strict_types=1);

namespace App\Services\Observability;

use JOOservices\Client\Client\ClientBuilder;
use JOOservices\Client\Client\HttpClient;
use JOOservices\Client\Response\Response;
use RuntimeException;
use Throwable;

final class ObservabilityImporter
{
    private const TELEGRAM_TEMPLATE_NAME = 'javmeta_telegram';

    private const TELEGRAM_CRITICAL_TEMPLATE_NAME = 'javmeta_telegram_critical';

    private ?HttpClient $client = null;

    /**
     * @return array{dry_run: true, dashboards: int, alerts: int}|array{
     *     dry_run: false,
     *     dashboards: int,
     *     alerts: int,
     *     dashboards_created: int,
     *     dashboards_updated: int,
     *     alerts_created: int,
     *     alerts_updated: int,
     *     alerts_pending: list<string>,
     *     template_created: bool,
     *     destination_created: bool
     * }
     */
    /**
     * Import or update managed dashboards, alert definitions, and the Telegram destination.
     *
     * @return array<string, mixed>
     */
    public function import(bool $dryRun = false): array
    {
        $dashboards = $this->loadDefinitions('dashboards');
        $alerts = $this->loadDefinitions('alerts');
        $this->validateDefinitions($dashboards, $alerts);

        if ($dryRun) {
            return [
                'dry_run' => true,
                'dashboards' => count($dashboards),
                'alerts' => count($alerts),
            ];
        }

        $telegram = $this->telegramConfiguration();
        $this->assertConfiguration($telegram);

        $environment = (string) config('app.env', 'unknown');
        $template = $this->telegramTemplate(self::TELEGRAM_TEMPLATE_NAME, 'WARNING', $telegram['chat_id'], $environment);
        $criticalTemplate = $this->telegramTemplate(self::TELEGRAM_CRITICAL_TEMPLATE_NAME, 'CRITICAL', $telegram['chat_id'], $environment);
        $destination = $this->telegramDestination($telegram, self::TELEGRAM_TEMPLATE_NAME, self::TELEGRAM_TEMPLATE_NAME);
        $criticalDestination = $this->telegramDestination(
            $telegram,
            self::TELEGRAM_CRITICAL_TEMPLATE_NAME,
            self::TELEGRAM_CRITICAL_TEMPLATE_NAME,
        );
        $templates = $this->resources($this->path('/alerts/templates'));
        $destinations = $this->resources($this->path('/alerts/destinations'));
        $dashboardResources = $this->resources($this->path('/dashboards'), 'dashboards');
        $alertResources = $this->resources($this->alertsPath(), 'list');

        $result = [
            'dry_run' => false,
            'dashboards' => count($dashboards),
            'alerts' => count($alerts),
            'template_created' => $this->upsertNamed(
                $this->path('/alerts/templates'),
                $template,
                $templates,
            ),
            'destination_created' => $this->upsertNamed(
                $this->path('/alerts/destinations'),
                $destination,
                $destinations,
            ),
            'dashboards_created' => 0,
            'dashboards_updated' => 0,
            'alerts_created' => 0,
            'alerts_updated' => 0,
            'alerts_pending' => [],
        ];
        $this->upsertNamed($this->path('/alerts/templates'), $criticalTemplate, $templates);
        $this->upsertNamed($this->path('/alerts/destinations'), $criticalDestination, $destinations);

        foreach ($dashboards as $dashboard) {
            if ($this->upsertDashboard($dashboard, $dashboardResources)) {
                $result['dashboards_created']++;
            } else {
                $result['dashboards_updated']++;
            }
        }

        foreach ($alerts as $alert) {
            $alert['destinations'] ??= [self::TELEGRAM_TEMPLATE_NAME];
            try {
                if ($this->upsertAlert($alert, $alertResources)) {
                    $result['alerts_created']++;
                } else {
                    $result['alerts_updated']++;
                }
            } catch (StreamNotReady) {
                // OpenObserve only accepts an alert for a stream that already received data.
                // Report it so a rerun after the first metrics publish completes the import.
                $result['alerts_pending'][] = (string) $alert['name'];
            }
        }

        return $result;
    }

    /** @return list<array<string, mixed>> */
    private function loadDefinitions(string $kind): array
    {
        $paths = glob(base_path("deploy/observability/{$kind}/*.json")) ?: [];
        sort($paths, SORT_STRING);

        if ($paths === []) {
            throw new RuntimeException("No observability {$kind} definitions were found.");
        }

        $definitions = [];
        foreach ($paths as $path) {
            $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($decoded) || array_is_list($decoded)) {
                throw new RuntimeException('Observability definition must be a JSON object: ' . basename($path));
            }
            $definitions[] = $decoded;
        }

        return $definitions;
    }

    /** @param list<array<string, mixed>> $dashboards
     *  @param list<array<string, mixed>> $alerts
     */
    private function validateDefinitions(array $dashboards, array $alerts): void
    {
        $titles = [];
        foreach ($dashboards as $dashboard) {
            $title = $dashboard['title'] ?? null;
            if (! is_string($title) || $title === '') {
                throw new RuntimeException('Each dashboard definition requires a title.');
            }
            if (isset($titles[$title])) {
                throw new RuntimeException('Dashboard titles must be unique: ' . $title);
            }
            $titles[$title] = true;
        }

        $names = [];
        foreach ($alerts as $alert) {
            $name = $alert['name'] ?? null;
            $streamName = $alert['stream_name'] ?? null;
            $sql = $alert['query_condition']['sql'] ?? null;
            if (! is_string($name) || $name === '' || ! is_string($streamName) || $streamName === '' || ! is_string($sql) || $sql === '') {
                throw new RuntimeException('Each alert definition requires a name, stream_name, and SQL query.');
            }
            if (isset($names[$name])) {
                throw new RuntimeException('Alert names must be unique: ' . $name);
            }
            $destinations = $alert['destinations'] ?? [self::TELEGRAM_TEMPLATE_NAME];
            if (! is_array($destinations) || ! array_is_list($destinations) || $destinations === []) {
                throw new RuntimeException('Alert destinations must be a non-empty list when configured.');
            }
            foreach ($destinations as $destinationName) {
                if (! is_string($destinationName) || $destinationName === '') {
                    throw new RuntimeException('Alert destination names must be non-empty strings.');
                }
            }
            $names[$name] = true;
        }
    }

    /** @return array{bot_token: string, chat_id: string, api_base: string} */
    private function telegramConfiguration(): array
    {
        $botToken = $this->firstStringConfig([
            'laravel-notifications.channels.telegram.bot_token',
            'javmeta_alerts.telegram.bot_token',
        ]);
        $chatId = $this->firstStringConfig([
            'laravel-notifications.channels.telegram.chat_id',
            'javmeta_alerts.telegram.chat_id',
        ]);

        return [
            'bot_token' => $botToken,
            'chat_id' => $chatId,
            'api_base' => $this->firstStringConfig([
                'laravel-notifications.channels.telegram.api_base',
                'javmeta_alerts.telegram.api_base',
            ]) ?: 'https://api.telegram.org',
        ];
    }

    /** @param list<string> $keys */
    private function firstStringConfig(array $keys): string
    {
        foreach ($keys as $key) {
            $value = config($key);
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return '';
    }

    /** @param array{bot_token: string, chat_id: string, api_base: string} $telegram */
    private function assertConfiguration(array $telegram): void
    {
        $required = [
            'openobserve.url' => config('openobserve.url'),
            'openobserve.org' => config('openobserve.org'),
            'openobserve.email' => config('openobserve.email'),
            'openobserve.password' => config('openobserve.password'),
            'NOTIFICATION_TELEGRAM_BOT_TOKEN' => $telegram['bot_token'],
            'NOTIFICATION_TELEGRAM_CHAT_ID' => $telegram['chat_id'],
        ];
        $missing = [];
        foreach ($required as $name => $value) {
            if (! is_string($value) || trim($value) === '') {
                $missing[] = $name;
            }
        }

        if ($missing !== []) {
            throw new RuntimeException('Missing required observability importer configuration: ' . implode(', ', $missing) . '.');
        }
    }

    /** @return array<string, mixed> */
    private function telegramTemplate(string $name, string $severity, string $chatId, string $environment): array
    {
        return [
            'name' => $name,
            'title' => 'JAVMETA Telegram ' . strtolower($severity) . ' alert',
            'type' => 'http',
            'body' => json_encode([
                'chat_id' => $chatId,
                'text' => '[javmeta:' . $environment . '] [ALERT][' . $severity . '][OPEN] {alert_name} triggered for {stream_name}. {rows}',
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        ];
    }

    /** @param array{bot_token: string, chat_id: string, api_base: string} $telegram
     *  @return array<string, mixed>
     */
    private function telegramDestination(array $telegram, string $name, string $template): array
    {
        return [
            'name' => $name,
            'url' => rtrim($telegram['api_base'], '/') . '/bot' . $telegram['bot_token'] . '/sendMessage',
            'method' => 'post',
            'type' => 'http',
            'template' => $template,
            'skip_tls_verify' => false,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function resources(string $path, string $listKey = ''): array
    {
        $payload = $this->request('GET', $path);
        if (array_is_list($payload)) {
            return array_values(array_filter($payload, 'is_array'));
        }

        if ($listKey !== '' && isset($payload[$listKey]) && is_array($payload[$listKey])) {
            return array_values(array_filter($payload[$listKey], 'is_array'));
        }

        foreach (['list', 'data', 'items'] as $key) {
            if (isset($payload[$key]) && is_array($payload[$key]) && array_is_list($payload[$key])) {
                return array_values(array_filter($payload[$key], 'is_array'));
            }
        }

        return [];
    }

    /** @param array<string, mixed> $definition
     *  @param list<array<string, mixed>> $existing
     */
    private function upsertNamed(string $collectionPath, array $definition, array $existing): bool
    {
        $name = (string) $definition['name'];
        $match = $this->matchByName($existing, $name);
        if ($match === null) {
            $this->request('POST', $collectionPath, $definition);

            return true;
        }

        $this->request('PUT', $collectionPath . '/' . rawurlencode($name), $definition);

        return false;
    }

    /** @param array<string, mixed> $definition
     *  @param list<array<string, mixed>> $existing
     */
    private function upsertDashboard(array $definition, array $existing): bool
    {
        $match = null;
        foreach ($existing as $resource) {
            if (($resource['title'] ?? null) === $definition['title']) {
                $match = $resource;
                break;
            }
        }

        if ($match === null) {
            $this->request('POST', $this->path('/dashboards'), $definition);

            return true;
        }

        $id = $match['dashboard_id'] ?? $match['dashboardId'] ?? $match['id'] ?? null;
        if (! is_string($id) || $id === '') {
            throw new RuntimeException('OpenObserve returned a dashboard without an id.');
        }

        $hash = $match['hash'] ?? null;
        $folder = $match['folder_id'] ?? null;
        if (! is_string($hash) || $hash === '' || ! is_string($folder) || $folder === '') {
            throw new RuntimeException('OpenObserve returned a dashboard without its hash or folder id.');
        }

        $path = $this->path('/dashboards/' . rawurlencode($id));
        $body = $definition;
        $body['dashboardId'] = $id;
        $query = ['folder' => $folder, 'hash' => $hash];
        $this->request('PUT', $path, $body, $query);

        return false;
    }

    /** @param array<string, mixed> $definition
     *  @param list<array<string, mixed>> $existing
     */
    private function upsertAlert(array $definition, array $existing): bool
    {
        $match = $this->matchByName($existing, (string) $definition['name']);
        if ($match === null) {
            try {
                $this->request('POST', $this->alertsPath(), $definition);
            } catch (RuntimeException $error) {
                if (str_contains($error->getMessage(), 'returned HTTP 404')) {
                    throw new StreamNotReady((string) $definition['stream_name'], previous: $error);
                }

                throw $error;
            }

            return true;
        }

        $id = $match['id'] ?? $match['alert_id'] ?? null;
        if (! is_string($id) || $id === '') {
            throw new RuntimeException('OpenObserve returned an alert without an id.');
        }
        $definition['id'] = $id;
        $this->request('PUT', $this->alertsPath() . '/' . rawurlencode($id), $definition);

        return false;
    }

    /** @param list<array<string, mixed>> $resources
     *  @return array<string, mixed>|null
     */
    private function matchByName(array $resources, string $name): ?array
    {
        foreach ($resources as $resource) {
            if (($resource['name'] ?? null) === $name) {
                return $resource;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $body
     *  @param array<string, scalar|array<array-key, scalar>|null> $query
     *  @return array<array-key, mixed>
     */
    private function request(string $method, string $path, array $body = [], array $query = []): array
    {
        $builder = $this->client()->requestBuilder()
            ->method($method, $path)
            ->withHeader('Accept', 'application/json');
        if ($query !== []) {
            $builder = $builder->withQuery($query);
        }
        if ($body !== []) {
            $builder = $builder->withJson($body);
        }

        $prepared = $builder->build();
        try {
            $response = Response::from($this->client()->send($prepared->toPsr(), $prepared->options()));
        } catch (Throwable $error) {
            throw new RuntimeException("OpenObserve API request failed ({$method} {$path}).", previous: $error);
        }

        if (! $response->successful()) {
            throw new RuntimeException("OpenObserve API request returned HTTP {$response->status()} ({$method} {$path}).");
        }

        if (trim($response->body()) === '') {
            return [];
        }

        try {
            return $response->json();
        } catch (Throwable $error) {
            throw new RuntimeException("OpenObserve API returned invalid JSON ({$method} {$path}).", previous: $error);
        }
    }

    private function client(): HttpClient
    {
        if ($this->client !== null) {
            return $this->client;
        }

        $builder = ClientBuilder::create()
            ->withBaseUri(rtrim((string) config('openobserve.url'), '/'))
            ->withBasicAuth((string) config('openobserve.email'), (string) config('openobserve.password'));
        if (! ClientBuilder::isFaked()) {
            $builder = $builder->withTimeout((float) max(1, (int) config('openobserve.timeout', 3)));
        }

        return $this->client = $builder->build();
    }

    private function alertsPath(): string
    {
        return '/api/v2/' . rawurlencode((string) config('openobserve.org')) . '/alerts';
    }

    private function path(string $suffix): string
    {
        return '/api/' . rawurlencode((string) config('openobserve.org')) . $suffix;
    }
}
