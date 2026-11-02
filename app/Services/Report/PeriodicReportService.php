<?php

declare(strict_types=1);

namespace App\Services\Report;

use App\Contracts\ReportChannel;
use App\Models\ReportSnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Throwable;

final class PeriodicReportService
{
    public function __construct(
        private readonly ReportDataBuilder $data,
        private readonly ReportRenderer $renderer,
        private readonly ReportChannel $channel,
        private readonly ReportMetrics $metrics,
    ) {}

    /** @return array{data: array<string, mixed>, text: string, last: array<string, mixed>|null, history: list<array<string, mixed>>} */
    public function preview(): array
    {
        $data = $this->data->build();

        return [
            'data' => $data,
            'text' => $this->renderer->render($data),
            'last' => $this->snapshotRow(ReportSnapshot::query()->orderByDesc('id')->first()),
            'history' => ReportSnapshot::query()->orderByDesc('id')->limit(20)->get()->map(fn(ReportSnapshot $row): array => $this->snapshotRow($row) ?? [])->all(),
        ];
    }

    /** @return array{sent: bool, duplicate: bool, text: string, result: string} */
    public function sendNow(): array
    {
        return $this->send(null);
    }

    /** @return array{sent: bool, duplicate: bool, text: string, result: string} */
    public function sendScheduled(CarbonImmutable $slot): array
    {
        return $this->send($slot);
    }

    /** @return array{sent: bool, duplicate: bool, text: string, result: string} */
    private function send(?CarbonImmutable $scheduledFor): array
    {
        $slot = $scheduledFor?->utc()->format('Y-m-d-H-i');
        $lock = Cache::lock('javmeta:report:send:' . ($slot ?? 'manual'), 300);
        if (! $lock->get()) {
            return ['sent' => false, 'duplicate' => true, 'text' => '', 'result' => 'locked'];
        }

        try {
            if ($scheduledFor !== null && ReportSnapshot::query()->where('scheduled_for', $scheduledFor->utc())->exists()) {
                return ['sent' => false, 'duplicate' => true, 'text' => '', 'result' => 'duplicate'];
            }
            if ($scheduledFor !== null && ! Cache::add('javmeta:report:reserved-slot:' . $slot, 1, now()->addDays(2))) {
                return ['sent' => false, 'duplicate' => true, 'text' => '', 'result' => 'duplicate'];
            }

            $data = $this->data->build();
            $text = $this->renderer->render($data);
            [$subject, $body] = $this->splitText($text);
            try {
                $sent = in_array('telegram', (array) config('javmeta_report.channels', ['telegram']), true)
                    && $this->channel->deliver($subject, $body, ['plain_report' => true]);
            } catch (Throwable) {
                $sent = false;
            }
            $result = $sent ? 'sent' : 'failed';
            ReportSnapshot::query()->create([
                'scheduled_for' => $scheduledFor?->utc(),
                'result' => $result,
                'channel' => 'telegram',
                'rendered_text' => $text,
                'raw_numbers' => $data,
            ]);
            try {
                $this->pruneHistory();
            } catch (Throwable) {
                // A retention cleanup failure must not change a delivered report's result.
            }
            $this->metrics->record($result, $sent ? now()->timestamp : null);

            return ['sent' => $sent, 'duplicate' => false, 'text' => $text, 'result' => $result];
        } catch (Throwable) {
            try {
                $this->metrics->record('failed');
            } catch (Throwable) {
                // Metrics are best effort and must not mask the send result.
            }

            return ['sent' => false, 'duplicate' => false, 'text' => '', 'result' => 'failed'];
        } finally {
            $lock->release();
        }
    }

    /** @return array{0: string, 1: string} */
    private function splitText(string $text): array
    {
        $lines = explode("\n", $text);
        $subject = (string) array_shift($lines);
        $body = trim(implode("\n", $lines));

        return [$subject, $body !== '' ? $body : 'No report data is available for this window.'];
    }

    private function pruneHistory(): void
    {
        ReportSnapshot::query()
            ->where('created_at', '<', now()->subDays(max(1, (int) config('javmeta_report.history_days', 30))))
            ->delete();
    }

    /** @return array<string, mixed>|null */
    private function snapshotRow(?ReportSnapshot $snapshot): ?array
    {
        if ($snapshot === null) {
            return null;
        }

        return [
            'id' => (int) $snapshot->getKey(),
            'result' => (string) $snapshot->getAttribute('result'),
            'channel' => (string) $snapshot->getAttribute('channel'),
            'scheduled_for' => $snapshot->getAttribute('scheduled_for'),
            'created_at' => $snapshot->getAttribute('created_at'),
            'rendered_text' => (string) $snapshot->getAttribute('rendered_text'),
            'raw_numbers' => $snapshot->getAttribute('raw_numbers'),
        ];
    }
}
