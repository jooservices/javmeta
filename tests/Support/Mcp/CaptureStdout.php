<?php

declare(strict_types=1);

namespace Tests\Support\Mcp;

use php_user_filter;

/** Write filter for the STDOUT constant: collects what is written and keeps it away from the real stdout. */
final class CaptureStdout extends php_user_filter
{
    public static string $captured = '';

    /** @var resource|null */
    private static $filter;

    public static function start(): void
    {
        self::$captured = '';
        if (! in_array('tests.capture_stdout', stream_get_filters(), true)) {
            stream_filter_register('tests.capture_stdout', self::class);
        }
        self::$filter = stream_filter_append(STDOUT, 'tests.capture_stdout', STREAM_FILTER_WRITE);
    }

    public static function stop(): string
    {
        if (is_resource(self::$filter)) {
            stream_filter_remove(self::$filter);
            self::$filter = null;
        }

        return self::$captured;
    }

    /**
     * @param resource $in
     * @param resource $out
     * @param int $consumed
     */
    public function filter($in, $out, &$consumed, bool $closing): int
    {
        while (($bucket = stream_bucket_make_writeable($in)) !== null) {
            self::$captured .= $bucket->data;
            $consumed += $bucket->datalen;
        }

        return PSFS_PASS_ON;
    }
}
