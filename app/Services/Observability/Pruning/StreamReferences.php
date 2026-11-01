<?php

declare(strict_types=1);

namespace App\Services\Observability\Pruning;

use RuntimeException;

final class StreamReferences
{
    public function __construct(private readonly PruneApi $api) {}

    /** @param list<array<string, mixed>> $candidates
     * @param callable(string): void $report
     */
    public function check(array $candidates, callable $report): void
    {
        $org = $this->api->orgPath();
        $v2 = '/api/v2/' . rawurlencode((string) config('openobserve.org'));
        $families = [
            'dashboards' => [$org . '/dashboards', 'dashboards'],
            'functions' => [$org . '/functions', 'list'],
            'pipelines' => [$org . '/pipelines', 'list'],
            'alerts' => [$v2 . '/alerts', 'list'],
            'destinations' => [$org . '/alerts/destinations', ''],
        ];
        $failures = [];
        foreach ($families as $family => [$path, $key]) {
            try {
                // v1.0.4 defaults to only the default folder unless title is present.
                // An empty title matches every dashboard across all folders, without a page limit.
                $body = $this->api->request('GET', $path, $family === 'dashboards' ? ['title' => ''] : []);
                $objects = $this->api->collection($key === '' ? ['items' => $body] : $body, $key === '' ? 'items' : $key);
                foreach ($objects as $object) {
                    $detail = $this->detail($family, $object, $org, $v2);
                    $matches = $this->matches($detail, array_column($candidates, 'name'));
                    // Only stream names may leave this boundary; destinations can contain credentials.
                    unset($detail);
                    if ($matches !== []) {
                        throw new RuntimeException('References candidates: ' . implode(', ', $matches));
                    }
                }
                $checked = count($objects);
                unset($body, $objects, $object);
                $report("Checked {$family}: {$checked} objects, complete, no candidate references.");
            } catch (RuntimeException $exception) {
                $report("Could not clear {$family} reference check.");
                $failures[] = $family . ': ' . $exception->getMessage();
            }
        }
        if ($failures !== []) {
            throw new RuntimeException('Reference checks failed: ' . implode('; ', $failures));
        }
    }

    /** @param array<string, mixed> $object
     * @return array<mixed>
     */
    private function detail(string $family, array $object, string $org, string $v2): array
    {
        if ($family === 'dashboards') {
            $id = $object['dashboard_id'] ?? null;
            if (! is_string($id) || $id === '') {
                throw new RuntimeException('Dashboard ID unavailable.');
            }

            $detail = $this->api->request('GET', $org . '/dashboards/' . rawurlencode($id), ['folder' => $object['folder_id'] ?? 'default']);
            $version = $detail['version'] ?? null;
            if (! is_int($version) || ! is_array($detail['v' . $version] ?? null)) {
                throw new RuntimeException('Dashboard detail unavailable or unsupported.');
            }

            return $detail;
        }
        if ($family === 'alerts') {
            $id = $object['alert_id'] ?? null;
            if (! is_string($id) || $id === '') {
                throw new RuntimeException('Alert ID unavailable.');
            }

            $detail = $this->api->request('GET', $v2 . '/alerts/' . rawurlencode($id));
            if (! is_string($detail['stream_name'] ?? null) || ! is_array($detail['query_condition'] ?? null)) {
                throw new RuntimeException('Alert detail unavailable or unsupported.');
            }

            return $detail;
        }

        return $object;
    }

    /** @param array<mixed> $object
     * @param list<string> $names
     * @return list<string>
     */
    public function matches(array $object, array $names): array
    {
        $matches = [];
        $visit = static function (mixed $value) use (&$visit, $names, &$matches): void {
            if (is_array($value)) {
                foreach ($value as $key => $nested) {
                    if (is_string($key)) {
                        $visit($key);
                    }
                    $visit($nested);
                }

                return;
            }
            if (! is_string($value)) {
                return;
            }
            foreach ($names as $name) {
                if (preg_match('/(?<![a-zA-Z0-9_])' . preg_quote($name, '/') . '(?![a-zA-Z0-9_])/', $value) === 1) {
                    $matches[$name] = $name;
                }
            }
        };
        $visit($object);

        return array_values($matches);
    }
}
