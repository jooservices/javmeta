<?php

declare(strict_types=1);

namespace App\Services\Api\V2\Administration;

use App\Http\Api\V2\ProblemException;
use App\Repositories\Api\V2\AdministrationRepository;
use App\Services\Api\V2\Contracts\AuditLog;
use App\Services\Report\PeriodicReportService;
use App\Services\Reports\ReportService;
use App\Services\Settings\SettingsService;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use JOOservices\LaravelConfig\Contracts\ConfigStore;
use Throwable;

/** Report schedule, its next runs, the delivery history, sending a report now, and the data reports. */
final class ReportAdministration
{
    private const EXTRA_PATH = 'admin.report_schedule';

    private const SECTIONS = ['clusters', 'hosts', 'services', 'workers'];

    private const TIME = '/^([01]\d|2[0-3]):[0-5]\d$/';

    public function __construct(
        private readonly SettingsService $settings,
        private readonly ConfigStore $store,
        private readonly AdministrationRepository $repository,
        private readonly PeriodicReportService $periodic,
        private readonly ReportService $reports,
        private readonly AuditLog $audit,
    ) {}

    /** @return array<string, array<string, mixed>> */
    public function schedule(): array
    {
        $extra = $this->extra();
        $quietFrom = (string) $this->settings->get('report.quiet_hours_start');
        $quietTo = (string) $this->settings->get('report.quiet_hours_end');

        return [
            'periodic' => [
                'enabled' => (bool) $this->settings->get('report.enabled'),
                'every_minutes' => (int) $this->settings->get('report.interval_minutes'),
                'include' => $extra['periodic_include'] ?? self::SECTIONS,
            ],
            'daily' => [
                'enabled' => $extra['daily_enabled'] ?? true,
                'time' => (string) ($this->settings->get('report.daily_time') ?: '08:00'),
                'timezone' => (string) ($this->settings->get('report.daily_timezone') ?: 'Asia/Ho_Chi_Minh'),
                'include' => $extra['daily_include'] ?? self::SECTIONS,
            ],
            'quiet_mode' => [
                'enabled' => $extra['quiet_enabled'] ?? ($quietFrom !== '' && $quietTo !== ''),
                'from' => $extra['quiet_from'] ?? ($quietFrom !== '' ? $quietFrom : '23:00'),
                'to' => $extra['quiet_to'] ?? ($quietTo !== '' ? $quietTo : '07:00'),
            ],
        ];
    }

    /**
     * Saves the schedule: the fields the scheduler reads go to the settings, the rest to the config store.
     *
     * @param array<string, mixed> $input
     * @return array<string, array<string, mixed>>
     */
    public function updateSchedule(string $actor, array $input): array
    {
        $next = $this->merge($this->schedule(), $input);
        $this->assertValid($next);

        $quiet = $next['quiet_mode'];
        $this->settings->set('report.enabled', $next['periodic']['enabled']);
        $this->settings->set('report.interval_minutes', $next['periodic']['every_minutes']);
        $this->settings->set('report.daily_time', $next['daily']['time']);
        $this->settings->set('report.daily_timezone', $next['daily']['timezone']);
        $this->settings->set('report.quiet_hours_start', $quiet['enabled'] ? $quiet['from'] : '');
        $this->settings->set('report.quiet_hours_end', $quiet['enabled'] ? $quiet['to'] : '');
        $this->store->set(self::EXTRA_PATH, [
            'periodic_include' => $next['periodic']['include'],
            'daily_enabled' => $next['daily']['enabled'],
            'daily_include' => $next['daily']['include'],
            'quiet_enabled' => $quiet['enabled'],
            'quiet_from' => $quiet['from'],
            'quiet_to' => $quiet['to'],
        ], 'array');
        $this->audit->record($actor, 'report_schedule.update', 'reports/schedule', ['sections' => array_keys($input)]);

        return $this->schedule();
    }

    /** @return array<string, mixed> */
    public function previews(): array
    {
        $schedule = $this->schedule();
        $every = max(15, $schedule['periodic']['every_minutes']);
        $zone = (string) ($this->settings->get('report.timezone') ?: 'UTC');
        $now = CarbonImmutable::now($zone);
        $start = $now->startOfDay();
        $slot = intdiv((int) $start->diffInMinutes($now), $every) + 1;
        $next = [];
        for ($i = 0; $i < 3; ++$i) {
            $next[] = Timestamps::iso($start->addMinutes(($slot + $i) * $every));
        }

        return [
            'periodic_next' => $next,
            'daily_next' => $schedule['daily']['time'] . ' ' . $schedule['daily']['timezone'],
            'include' => $schedule['periodic']['include'],
        ];
    }

    /** @return array{data: list<array<string, mixed>>, total: int} */
    public function deliveries(int $page, int $perPage): array
    {
        $result = $this->repository->reportDeliveries($page, $perPage);

        return ['data' => array_map($this->delivery(...), $result['rows']), 'total' => $result['total']];
    }

    /** @return array<string, mixed> */
    public function sendNow(string $actor): array
    {
        $result = $this->periodic->sendNow();
        $latest = $this->repository->latestReportDelivery();

        if (in_array($result['result'], ['locked', 'duplicate', 'suppressed'], true) || $latest === null) {
            throw ProblemException::conflict('report_not_sent', 'A report is already being sent or the policy suppressed it.');
        }
        $this->audit->record($actor, 'report.send_now', 'reports/deliveries/' . $latest['id'], ['result' => $result['result']]);

        return $this->delivery($latest) + ['note' => $result['sent'] ? 'The report was sent.' : 'The channel did not accept the report.'];
    }

    /** @return array<string, mixed> */
    public function data(string $type): array
    {
        if (! in_array($type, ['quality', 'cost', 'sources'], true)) {
            throw ProblemException::notFound('report_not_found', 'The report type must be quality, cost or sources.');
        }

        return Cache::remember('admin-report:' . $type, 60, fn(): array => match ($type) {
            'quality' => $this->reports->quality(),
            'cost' => $this->reports->cost(),
            default => $this->reports->sources(),
        });
    }

    /** @return array<string, mixed> */
    private function extra(): array
    {
        try {
            return (array) $this->store->get(self::EXTRA_PATH, []);
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param array<string, array<string, mixed>> $current
     * @param array<string, mixed> $input
     * @return array<string, array<string, mixed>>
     */
    private function merge(array $current, array $input): array
    {
        foreach (['periodic', 'daily', 'quiet_mode'] as $block) {
            $current[$block] = array_replace($current[$block], (array) ($input[$block] ?? []));
        }

        return $current;
    }

    /** @param array<string, array<string, mixed>> $schedule */
    private function assertValid(array $schedule): void
    {
        $errors = [];
        if ($schedule['periodic']['every_minutes'] < 15) {
            $errors[] = ['field' => 'periodic.every_minutes', 'code' => 'too_small', 'min' => 15];
        } else {
            try {
                $this->settings->assertValid('report.interval_minutes', $schedule['periodic']['every_minutes']);
            } catch (InvalidArgumentException) {
                $errors[] = ['field' => 'periodic.every_minutes', 'code' => 'invalid'];
            }
        }
        if (! $this->isZone((string) $schedule['daily']['timezone'])) {
            $errors[] = ['field' => 'daily.timezone', 'code' => 'invalid_timezone'];
        }
        foreach ([['daily.time', $schedule['daily']['time']], ['quiet_mode.from', $schedule['quiet_mode']['from']], ['quiet_mode.to', $schedule['quiet_mode']['to']]] as [$field, $time]) {
            if (preg_match(self::TIME, (string) $time) !== 1) {
                $errors[] = ['field' => $field, 'code' => 'invalid_time'];
            }
        }

        if ($errors !== []) {
            throw ProblemException::invalid('The schedule is invalid.', $errors);
        }
    }

    private function isZone(string $name): bool
    {
        return in_array($name, DateTimeZone::listIdentifiers(), true);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function delivery(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'scheduled_for' => Timestamps::iso($row['scheduled_for']),
            'result' => (string) $row['result'],
            'channel' => (string) $row['channel'],
            'rendered_text' => (string) $row['rendered_text'],
            'created_at' => Timestamps::iso($row['created_at']),
        ];
    }
}
