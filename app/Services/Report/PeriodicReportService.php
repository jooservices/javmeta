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
        return $this->previewType('periodic');
    }

    /** @return array{data: array<string, mixed>, text: string, last: array<string, mixed>|null, history: list<array<string, mixed>>} */
    public function previewDaily(): array
    {
        return $this->previewType('daily');
    }

    /** @return array{sent: bool, duplicate: bool, text: string, result: string} */
    public function sendDailyNow(): array
    {
        return $this->send(null, 'daily');
    }

    /** @return array{sent: bool, duplicate: bool, text: string, result: string} */
    public function sendDailyScheduled(CarbonImmutable $slot): array
    {
        return $this->send($slot, 'daily');
    }

    /** @return array{data: array<string, mixed>, text: string, last: array<string, mixed>|null, history: list<array<string, mixed>>} */
    private function previewType(string $type): array
    {
        $query = ReportSnapshot::query()->where('report_type', $type);
        $data = $type === 'daily' ? $this->data->build(null, 1440) : $this->data->build();

        return [
            'data' => $data,
            'text' => $this->renderer->render($data),
            'last' => $this->snapshotRow((clone $query)->orderByDesc('id')->first()),
            'history' => (clone $query)->orderByDesc('id')->limit(20)->get()->map(fn(ReportSnapshot $row): array => $this->snapshotRow($row) ?? [])->all(),
        ];
    }

    /** @return array{sent: bool, duplicate: bool, text: string, result: string} */
    public function sendNow(): array
    {
        return $this->send(null, 'periodic');
    }

    /** @return array{sent: bool, duplicate: bool, text: string, result: string} */
    public function sendScheduled(CarbonImmutable $slot): array
    {
        return $this->send($slot, 'periodic');
    }

    /** @return array{sent: bool, duplicate: bool, text: string, result: string} */
    private function send(?CarbonImmutable $scheduledFor, string $type): array
    {
        $slot = $scheduledFor?->utc()->format('Y-m-d-H-i');
        $lock = Cache::lock('javmeta:report:send:' . $type . ':' . ($slot ?? 'manual'), 300);
        if (! $lock->get()) {
            return ['sent' => false, 'duplicate' => true, 'text' => '', 'result' => 'locked'];
        }

        $slotReservationKey = $slot === null ? null : 'javmeta:report:reserved-slot:' . $type . ':' . $slot;
        $slotReserved = false;
        $snapshotStored = false;
        $policyDispatcher = null;
        $policyDecision = null;

        try {
            if ($scheduledFor !== null && ReportSnapshot::query()->where('report_type', $type)->where('scheduled_for', $scheduledFor->utc())->exists()) {
                return ['sent' => false, 'duplicate' => true, 'text' => '', 'result' => 'duplicate'];
            }
            if ($slotReservationKey !== null) {
                $slotReserved = Cache::add($slotReservationKey, 1, now()->addDays(2));
                if (! $slotReserved) {
                    return ['sent' => false, 'duplicate' => true, 'text' => '', 'result' => 'duplicate'];
                }
            }

            $policyDispatcher = app(\App\Services\Notifications\NotificationPolicyDispatcher::class);
            $policyKey = $type === 'daily' ? 'report.daily' : 'report.periodic';
            $policyDecision = $policyDispatcher->prepare($policyKey, [
                'notification_cause' => $type . '_report',
                'notification_digest' => true,
                'notification_scheduled' => $scheduledFor !== null,
            ]);
            if ($policyDecision !== null && ! $policyDecision['allowed']) {
                return ['sent' => false, 'duplicate' => true, 'text' => '', 'result' => 'suppressed'];
            }

            $data = $type === 'daily' ? $this->data->build(null, 1440) : $this->data->build();
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
                'report_type' => $type,
                'result' => $result,
                'channel' => 'telegram',
                'rendered_text' => $text,
                'raw_numbers' => $data,
            ]);
            $snapshotStored = true;
            try {
                $this->pruneHistory();
            } catch (Throwable) {
                // A retention cleanup failure must not change a delivered report's result.
            }
            $this->metrics->record($result, $sent ? now()->timestamp : null);
            if ($policyDecision !== null) {
                if ($sent) {
                    $policyDispatcher->recordSent($policyDecision);
                } else {
                    $policyDispatcher->release($policyDecision);
                }
            }

            return ['sent' => $sent, 'duplicate' => false, 'text' => $text, 'result' => $result];
        } catch (Throwable) {
            if ($slotReserved && ! $snapshotStored && $slotReservationKey !== null) {
                try {
                    Cache::forget($slotReservationKey);
                } catch (Throwable) {
                    // A failed cache cleanup must not mask the original report failure.
                }
            }
            if ($policyDispatcher !== null && $policyDecision !== null) {
                $policyDispatcher->release($policyDecision);
            }
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
