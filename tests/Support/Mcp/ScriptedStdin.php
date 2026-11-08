<?php

declare(strict_types=1);

namespace Tests\Support\Mcp;

/**
 * Replaces the `php://` stream wrapper so `php://stdin` yields scripted input; every other `php://` path
 * is delegated to the built-in wrapper. Always call restore() (tearDown) after install().
 */
final class ScriptedStdin
{
    public static string $input = '';

    /** @var resource|null */
    public $context;

    /** @var resource|null */
    private $handle;

    public static function install(string $input): void
    {
        self::$input = $input;
        stream_wrapper_unregister('php');
        stream_wrapper_register('php', self::class);
    }

    public static function restore(): void
    {
        if (in_array('php', stream_get_wrappers(), true)) {
            @stream_wrapper_unregister('php');
        }
        stream_wrapper_restore('php');
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        stream_wrapper_unregister('php');
        stream_wrapper_restore('php');
        try {
            if ($path === 'php://stdin') {
                $handle = fopen('php://memory', 'w+');
                if ($handle !== false) {
                    fwrite($handle, self::$input);
                    rewind($handle);
                }
            } else {
                $handle = @fopen($path, $mode);
            }
        } finally {
            stream_wrapper_unregister('php');
            stream_wrapper_register('php', self::class);
        }
        $this->handle = $handle === false ? null : $handle;

        return $this->handle !== null;
    }

    public function stream_read(int $count): string|false
    {
        return $this->handle === null ? false : fread($this->handle, $count);
    }

    public function stream_write(string $data): int
    {
        return $this->handle === null ? 0 : (int) fwrite($this->handle, $data);
    }

    public function stream_eof(): bool
    {
        return $this->handle === null || feof($this->handle);
    }

    /** @return array<int|string, mixed>|false */
    public function stream_stat(): array|false
    {
        return $this->handle === null ? false : fstat($this->handle);
    }

    public function stream_close(): void
    {
        if ($this->handle !== null) {
            fclose($this->handle);
        }
    }
}
