<?php

declare(strict_types=1);

namespace App\Services\Backup;

use Illuminate\Support\Facades\File;
use Illuminate\Support\ConfigurationUrlParser;
use RuntimeException;
use Throwable;

/**
 * One scheduled logical backup: a PostgreSQL custom-format dump and a MongoDB gzip archive, written into a dated folder,
 * summed up in a manifest the status command reads, then cleaned down to the configured retention. A failed dump never
 * refreshes the manifest. Connection values are read from the existing application config and passed to the runner as
 * arguments or environment variables; they are never printed.
 */
final class LogicalBackupService
{
    private const FOLDER_PATTERN = '/^\d{8}T\d{6}Z$/';

    private ?int $lastExitCode = null;

    public function __construct(private readonly CommandRunner $runner) {}

    /**
     * @return array{
     *     completed: bool,
     *     created_at: ?string,
     *     manifest_path: string,
     *     stores: array<string, array{file: string, bytes: int, sha256: string}>,
     *     failed_store: ?string,
     *     failed_exit_code: ?int,
     * }
     */
    public function run(): array
    {
        $this->lastExitCode = null;
        $dir = $this->configuredDir();
        $manifestPath = (string) config('backup.manifest_path', '');

        if ($dir === '' || $manifestPath === '') {
            return $this->outcome(false, null, '', [], 'storage', -1);
        }

        try {
            if (! File::isDirectory($dir) && ! File::makeDirectory($dir, 0700, true, true)) {
                return $this->outcome(false, null, '', [], 'storage', -1);
            }
        } catch (Throwable) {
            return $this->outcome(false, null, '', [], 'storage', -1);
        }

        $runDir = $dir . DIRECTORY_SEPARATOR . now()->utc()->format('Ymd\THis\Z');
        try {
            if (File::isDirectory($runDir) || ! File::makeDirectory($runDir, 0700, true, true)) {
                return $this->outcome(false, null, '', [], 'storage', -1);
            }
        } catch (Throwable) {
            return $this->outcome(false, null, '', [], 'storage', -1);
        }

        try {
            $postgres = $this->dumpPostgres($runDir . DIRECTORY_SEPARATOR . 'postgres.dump');
        } catch (Throwable) {
            $this->lastExitCode = -1;
            $postgres = null;
        }

        if ($postgres === null) {
            $this->cleanup($runDir);

            return $this->outcome(false, null, '', [], 'postgres', $this->lastExitCode);
        }

        try {
            $mongo = $this->dumpMongo($runDir . DIRECTORY_SEPARATOR . 'mongo.archive.gz');
        } catch (Throwable) {
            $this->lastExitCode = -1;
            $mongo = null;
        }

        if ($mongo === null) {
            $this->cleanup($runDir);

            return $this->outcome(false, null, '', [], 'mongo', $this->lastExitCode);
        }

        $this->lastExitCode = null;
        $createdAt = now()->utc()->toIso8601String();
        $stores = ['postgres' => $postgres, 'mongo' => $mongo];
        try {
            $this->writeManifest($manifestPath, $createdAt, $stores);
        } catch (Throwable) {
            $this->cleanup($runDir);

            return $this->outcome(false, null, '', [], 'manifest', -1);
        }

        try {
            $this->prune($dir, max(1, (int) config('javmeta_backup.logical_keep', 7)));
        } catch (Throwable) {
            // A retention error does not invalidate the completed dumps or their manifest.
        }

        return $this->outcome(true, $createdAt, $manifestPath, $stores, null, null);
    }

    /**
     * @return array{file: string, bytes: int, sha256: string}|null
     */
    private function dumpPostgres(string $file): ?array
    {
        $connection = (new ConfigurationUrlParser())->parseConfiguration((array) config('database.connections.pgsql', []));
        $password = $this->string($connection['password'] ?? null, '');
        $command = [
            'pg_dump', '-Fc', '--no-password',
            '-h', $this->string($connection['host'] ?? null, '127.0.0.1'),
            '-p', $this->string($connection['port'] ?? null, '5432'),
            '-U', $this->string($connection['username'] ?? null, 'postgres'),
            '-d', $this->string($connection['database'] ?? null, 'laravel'),
            '-f', $file,
        ];
        $env = $password !== '' ? ['PGPASSWORD' => $password] : [];
        $sslMode = $this->string($connection['sslmode'] ?? null, '');
        if ($sslMode !== '') {
            $env['PGSSLMODE'] = $sslMode;
        }

        return $this->dump($command, $env, $file);
    }

    /**
     * @return array{file: string, bytes: int, sha256: string}|null
     */
    private function dumpMongo(string $file): ?array
    {
        $connection = (array) config('database.connections.mongodb', []);
        $uri = trim((string) ($connection['dsn'] ?? ''));
        $database = $this->string($connection['database'] ?? null, 'javmeta_archive');
        if ($uri === '') {
            return $this->dump([
                'mongodump',
                '--host', $this->string(config('mongodb.host', null), 'mongo'),
                '--port', $this->string(config('mongodb.port', null), '27017'),
                '--db', $database,
                '--archive', $file,
                '--gzip',
            ], [], $file);
        }

        $uri = $this->mongoUriForDatabase($uri, $database);

        $configPath = dirname($file) . DIRECTORY_SEPARATOR . 'mongodump.yaml';
        $config = 'uri: ' . json_encode($uri, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
        try {
            $handle = @fopen($configPath, 'x');
            if ($handle === false) {
                throw new RuntimeException('The MongoDB dump configuration could not be secured.');
            }

            try {
                if (! chmod($configPath, 0600)) {
                    throw new RuntimeException('The MongoDB dump configuration could not be secured.');
                }

                if (fwrite($handle, $config) !== strlen($config)) {
                    throw new RuntimeException('The MongoDB dump configuration could not be written.');
                }
            } finally {
                fclose($handle);
            }

            return $this->dump(['mongodump', '--config', $configPath, '--archive', $file, '--gzip'], [], $file);
        } finally {
            if (is_file($configPath) && ! File::delete($configPath)) {
                throw new RuntimeException('The MongoDB dump configuration could not be removed.');
            }
        }
    }

    private function mongoUriForDatabase(string $uri, string $database): string
    {
        if (preg_match('~^(mongodb(?:\\+srv)?://[^/?#]+)(?:/([^?#]*))?(\\?[^#]*)?$~i', $uri, $matches) !== 1) {
            throw new RuntimeException('The MongoDB connection URI is invalid.');
        }

        $originalDatabase = rawurldecode($matches[2] ?? '');
        $query = ($matches[3] ?? '') === '?' ? '' : ($matches[3] ?? '');
        if (preg_match('/[?&]authSource=/i', $query) !== 1) {
            $authSource = $originalDatabase !== '' ? $originalDatabase : 'admin';
            $query .= ($query === '' ? '?' : '&') . 'authSource=' . rawurlencode($authSource);
        }

        return $matches[1] . '/' . rawurlencode($database) . $query;
    }

    /**
     * @param  list<string>  $command
     * @param  array<string, string>  $env
     * @return array{file: string, bytes: int, sha256: string}|null
     */
    private function dump(array $command, array $env, string $file): ?array
    {
        try {
            $result = $this->runner->run($command, $env);
        } catch (Throwable) {
            $this->lastExitCode = -1;

            return null;
        }

        if (! $result->successful() || ! is_file($file)) {
            $this->lastExitCode = $result->successful() ? -1 : $result->exitCode();

            return null;
        }

        if (! chmod($file, 0600)) {
            $this->lastExitCode = -1;

            return null;
        }

        $bytes = filesize($file);
        $sha256 = hash_file('sha256', $file);
        if ($bytes === false || $bytes <= 0 || $sha256 === false) {
            $this->lastExitCode = $result->exitCode() === 0 ? -1 : $result->exitCode();

            return null;
        }

        return [
            'file' => basename(dirname($file)) . '/' . basename($file),
            'bytes' => $bytes,
            'sha256' => (string) $sha256,
        ];
    }

    /**
     * @param  array<string, array{file: string, bytes: int, sha256: string}>  $stores
     */
    private function writeManifest(string $manifestPath, string $createdAt, array $stores): void
    {
        if ($manifestPath === '') {
            throw new RuntimeException('The backup manifest path is not configured.');
        }

        $contents = (string) json_encode([
            'created_at' => $createdAt,
            'kind' => 'logical',
            'stores' => $stores,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        File::ensureDirectoryExists(dirname($manifestPath));
        $temporary = $manifestPath . '.tmp';
        try {
            if (File::put($temporary, $contents . "\n") === false) {
                throw new RuntimeException('The backup manifest could not be written.');
            }

            if (! File::move($temporary, $manifestPath)) {
                throw new RuntimeException('The backup manifest could not be replaced.');
            }
        } finally {
            try {
                File::delete($temporary);
            } catch (Throwable) {
                // A leftover temporary file must not mask the backup result.
            }
        }
    }

    private function prune(string $dir, int $keep): void
    {
        $folders = [];
        foreach (File::directories($dir) as $path) {
            if (preg_match(self::FOLDER_PATTERN, (string) basename($path)) === 1) {
                $folders[] = $path;
            }
        }

        sort($folders);
        foreach (array_slice($folders, 0, max(0, count($folders) - $keep)) as $path) {
            try {
                File::deleteDirectory($path);
            } catch (Throwable) {
                // Retention is best-effort; a leftover folder never fails the backup run.
            }
        }
    }

    private function cleanup(string $dir): void
    {
        try {
            File::deleteDirectory($dir);
        } catch (Throwable) {
            // Never mask the real dump failure with a cleanup error.
        }
    }

    private function configuredDir(): string
    {
        return rtrim((string) config('javmeta_backup.logical_dir', ''), DIRECTORY_SEPARATOR);
    }

    private function string(mixed $value, string $default): string
    {
        return is_scalar($value) && (string) $value !== '' ? (string) $value : $default;
    }

    /**
     * @param  array<string, array{file: string, bytes: int, sha256: string}>  $stores
     * @return array{
     *     completed: bool,
     *     created_at: ?string,
     *     manifest_path: string,
     *     stores: array<string, array{file: string, bytes: int, sha256: string}>,
     *     failed_store: ?string,
     *     failed_exit_code: ?int,
     * }
     */
    private function outcome(bool $completed, ?string $createdAt, string $manifestPath, array $stores, ?string $failedStore, ?int $failedExitCode): array
    {
        return [
            'completed' => $completed,
            'created_at' => $createdAt,
            'manifest_path' => $manifestPath,
            'stores' => $stores,
            'failed_store' => $failedStore,
            'failed_exit_code' => $failedExitCode,
        ];
    }
}
