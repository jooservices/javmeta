<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Library\LibraryScanner;
use App\Services\Settings\SettingsService;
use Illuminate\Console\Command;

final class LibraryScanCommand extends Command
{
    protected $signature = 'library:scan {--force : Scan now, whatever the switch and the hour}';

    protected $description = 'Scan the library folders (settings: library) and match the files to movies. Runs hourly from the scheduler but only scans at the configured hour.';

    public function handle(LibraryScanner $scanner, SettingsService $settings): int
    {
        if (! (bool) $this->option('force')) {
            if (! (bool) $settings->get('library.enabled')) {
                $this->line('The library scan is switched off.');

                return self::SUCCESS;
            }
            $timezone = (string) config('javmeta_alerts.hourly_report.timezone', 'Asia/Ho_Chi_Minh');
            if ((int) now($timezone)->format('G') !== (int) $settings->get('library.scan_hour')) {
                return self::SUCCESS;
            }
        }

        $r = $scanner->scan();
        $this->line(sprintf('Folders %d, files %d (new %d), matched %d, unmatched %d, missing %d.', $r['folders'], $r['seen'], $r['new'], $r['matched'], $r['unmatched'], $r['missing']));
        foreach ($r['errors'] as $error) {
            $this->components->warn($error);
        }

        return self::SUCCESS;
    }
}
