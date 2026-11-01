<?php

declare(strict_types=1);

namespace JavMeta\Deploy;

use MongoDB\Driver\Command;
use MongoDB\Driver\Manager;
use PDO;
use Redis;
use RedisException;
use RuntimeException;
use Throwable;

if (is_file(dirname(__DIR__, 2) . '/vendor/autoload.php')) {
    require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
}

// Standalone native preflight. Never report transport exceptions: URIs may contain secrets.
function answer(string $key): string
{
    return (string) getenv($key);
}

function tcp(string $host, int $port): void
{
    $socket = @stream_socket_client('tcp://' . $host . ':' . $port, $code, $message, 5);
    if ($socket === false) {
        throw new RuntimeException('TCP unavailable');
    }
    fclose($socket);
}

/** @param list<string> $headers */
function request(string $url, array $headers = [], ?string $body = null): string
{
    $parts = parse_url($url);
    if (!is_array($parts) || !isset($parts['host'], $parts['scheme']) || !in_array($parts['scheme'], ['http', 'https'], true)) {
        throw new RuntimeException('Invalid HTTP URL');
    }
    tcp($parts['host'], (int) ($parts['port'] ?? ($parts['scheme'] === 'https' ? 443 : 80)));
    $handle = curl_init($url);
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    if ($parts['scheme'] === 'https' && answer('ELASTICSEARCH_CA') !== '' && str_starts_with($url, answer('ELASTICSEARCH_HOST'))) {
        curl_setopt($handle, CURLOPT_CAINFO, answer('ELASTICSEARCH_CA'));
    }
    if ($body !== null) {
        curl_setopt($handle, CURLOPT_POST, true);
        curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
    }
    $result = curl_exec($handle);
    $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    if (!is_string($result) || $status < 200 || $status >= 300) {
        throw new RuntimeException('HTTP authentication/response failed');
    }

    return $result;
}

if (realpath($_SERVER['SCRIPT_FILENAME']) !== __FILE__) {
    return;
}

try {
    switch ($argv[1] ?? '') {
        case 'postgres':
            tcp(answer('DB_HOST'), (int) answer('DB_PORT'));
            $connection = new PDO('pgsql:host=' . answer('DB_HOST') . ';port=' . answer('DB_PORT') . ';dbname=' . answer('DB_DATABASE') . ';sslmode=' . answer('DB_SSLMODE'), answer('DB_USERNAME'), answer('DB_PASSWORD'), [PDO::ATTR_TIMEOUT => 5]);
            $connection->query('SELECT 1')->fetchColumn();
            $process = proc_open(['psql', '-X', '-At', '-c', 'SHOW timezone'], [1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'a']], $pipes, null, array_merge(getenv(), [
                'PGHOST' => answer('DB_HOST'), 'PGPORT' => answer('DB_PORT'),
                'PGDATABASE' => answer('DB_DATABASE'), 'PGUSER' => answer('DB_USERNAME'),
                'PGPASSWORD' => answer('DB_PASSWORD'), 'PGSSLMODE' => answer('DB_SSLMODE'), 'PGCONNECT_TIMEOUT' => '5',
                'PGOPTIONS' => '-c statement_timeout=5000',
            ]));
            if (!is_resource($process)) {
                throw new RuntimeException('PostgreSQL psql validation failed');
            }
            $timezone = trim((string) stream_get_contents($pipes[1]));
            fclose($pipes[1]);
            if (proc_close($process) !== 0) {
                throw new RuntimeException('PostgreSQL psql validation failed');
            }
            if (!in_array($timezone, ['UTC', 'Etc/UTC'], true)) {
                throw new RuntimeException('Database timezone MUST be UTC; stale claims cannot be reclaimed. Supply runtime admin credentials to repair.');
            }
            break;
        case 'mongo':
            $manager = new Manager(answer('MONGO_URI'), ['serverSelectionTimeoutMS' => 5000, 'connectTimeoutMS' => 5000]);
            $manager->executeCommand(answer('MONGO_DATABASE'), new Command(['ping' => 1]));
            break;
        case 'redis':
            tcp(answer('REDIS_HOST'), (int) answer('REDIS_PORT'));
            $redis = new Redis();
            $redis->connect(answer('REDIS_HOST'), (int) answer('REDIS_PORT'), 5);
            $redis->auth(answer('REDIS_USERNAME') === '' ? answer('REDIS_PASSWORD') : [answer('REDIS_USERNAME'), answer('REDIS_PASSWORD')]);
            $redis->select((int) answer('REDIS_DB'));
            $key = answer('REDIS_PREFIX') . 'wizard-probe:' . bin2hex(random_bytes(8));
            if (!$redis->set($key, 'verified', ['ex' => 30]) || $redis->get($key) !== 'verified') {
                throw new RuntimeException('Redis prefix SET/GET failed');
            }
            $redis->del($key);
            $denied = false;
            try {
                $redis->get('wizard-outside:' . bin2hex(random_bytes(8)));
                $denied = str_contains((string) $redis->getLastError(), 'NOPERM');
            } catch (RedisException $exception) {
                $denied = str_contains($exception->getMessage(), 'NOPERM');
            }
            if (!$denied) {
                throw new RuntimeException('Redis ACL must deny keys outside the prefix');
            }
            break;
        case 'elasticsearch':
            if (!preg_match('#^https?://#', answer('ELASTICSEARCH_HOST'))) {
                throw new RuntimeException('Elasticsearch requires HTTP(S)');
            }
            request(rtrim(answer('ELASTICSEARCH_HOST'), '/') . '/_security/_authenticate', ['Authorization: ApiKey ' . answer('ELASTICSEARCH_API_KEY')]);
            break;
        case 'openobserve':
            request(rtrim(answer('OPENOBSERVE_URL'), '/') . '/api/' . rawurlencode(answer('OPENOBSERVE_ORG')) . '/' . rawurlencode(answer('OPENOBSERVE_STREAM_LOGS')) . '/_json', ['Content-Type: application/json', 'Authorization: Basic ' . base64_encode(answer('OPENOBSERVE_EMAIL') . ':' . answer('OPENOBSERVE_PASSWORD'))], json_encode([['message' => 'Installation connectivity probe', 'service' => answer('OPENOBSERVE_SERVICE_NAME')]], JSON_THROW_ON_ERROR));
            break;
        case 'sentry':
            $dsn = parse_url(answer('JAVMETA_SENTRY_DSN'));
            if (!is_array($dsn) || !isset($dsn['scheme'], $dsn['host'], $dsn['user'], $dsn['path'])) {
                throw new RuntimeException('Invalid Sentry DSN');
            }
            $project = basename($dsn['path']);
            $prefix = substr($dsn['path'], 0, -strlen($project));
            request($dsn['scheme'] . '://' . $dsn['host'] . (isset($dsn['port']) ? ':' . $dsn['port'] : '') . $prefix . 'api/' . $project . '/envelope/', ['Content-Type: application/x-sentry-envelope', 'X-Sentry-Auth: Sentry sentry_version=7,sentry_key=' . $dsn['user']], "{}\n{\"type\":\"client_report\"}\n{\"timestamp\":" . time() . ",\"discarded_events\":[]}\n");
            break;
        case 'flare':
            $result = json_decode(request(answer('FLARESOLVERR_URL'), ['Content-Type: application/json'], '{"cmd":"sessions.list"}'), true, flags: JSON_THROW_ON_ERROR);
            if (($result['status'] ?? '') !== 'ok') {
                throw new RuntimeException('FlareSolverr invalid response');
            }
            break;
        case 'http':
            $playwright = ($argv[2] ?? '') === 'playwright';
            $url = $playwright ? answer('PLAYWRIGHT_URL') : answer('EMBEDDER_URL');
            request(rtrim($url, '/') . ($playwright ? '/health' : '/healthz'));
            break;
        case 'telegram':
            request(rtrim(answer('TELEGRAM_API_BASE'), '/') . '/bot' . rawurlencode(answer('TELEGRAM_BOT_TOKEN')) . '/getMe');
            break;
        case 'ollama':
            request(rtrim(answer('OLLAMA_URL'), '/') . '/api/tags');
            break;
        default:
            throw new RuntimeException('Unknown validator');
    }
    echo "TCP and protocol probe passed.\n";
} catch (Throwable $exception) {
    $safe = ['TCP unavailable', 'Invalid HTTP URL', 'HTTP authentication/response failed', 'Redis ping failed', 'Elasticsearch requires HTTP(S)', 'Invalid Sentry DSN', 'FlareSolverr invalid response', 'Unknown validator', 'PostgreSQL psql validation failed', 'Database timezone MUST be UTC; stale claims cannot be reclaimed. Supply runtime admin credentials to repair.', 'Redis prefix SET/GET failed', 'Redis ACL must deny keys outside the prefix'];
    echo in_array($exception->getMessage(), $safe, true) ? $exception->getMessage() : 'Authentication, TLS or required client dependency failed';
    echo "\n";
    exit(1);
}
