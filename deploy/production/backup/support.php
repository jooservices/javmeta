<?php

declare(strict_types=1);

require dirname(__DIR__, 3) . '/vendor/autoload.php';

use Dotenv\Dotenv;

try {
    switch ($argv[1] ?? '') {
        case 'env':
            $values = Dotenv::parse((string) file_get_contents($argv[2]));
            foreach (['DB_HOST', 'DB_PORT', 'DB_USERNAME', 'DB_PASSWORD', 'DB_DATABASE', 'DB_SSLMODE', 'MONGO_URI', 'MONGO_DATABASE', 'BACKUP_DIR', 'BACKUP_KEEP', 'JAVMETA_BACKUP_MANIFEST'] as $key) {
                printf("CFG_%s=%s\n", $key, escapeshellarg((string) ($values[$key] ?? '')));
            }
            break;
        case 'mongo':
            // JSON's escaped slash is not a valid YAML double-quoted escape.
            echo 'uri: ' . json_encode(getenv('BACKUP_MONGO_URI'), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
            break;
        case 'manifest':
            echo json_encode([
                'created_at' => getenv('BACKUP_CREATED_AT'),
                'producer' => 'xcrawler-node-backup-v1',
                'postgresql' => 'verified',
                'postgres_dump' => getenv('BACKUP_DUMP_PATH'),
                'mongodb' => getenv('BACKUP_MONGO_STATUS'),
                'elasticsearch' => 'excluded (derived; rebuild with es:setup --reindex)',
            ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
            break;
        case 'owns':
            $path = $argv[2] . '/manifest.json';
            $data = ! is_link($path) && is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
            exit(is_array($data) && ($data['producer'] ?? '') === 'xcrawler-node-backup-v1' ? 0 : 1);
        case 'dump':
            $data = json_decode((string) file_get_contents($argv[2]), true, 512, JSON_THROW_ON_ERROR);
            echo $data['postgres_dump'];
            break;
        case 'counts':
            // pg_restore emits COPY with SQL-quoted identifiers; count actual COPY records, not TOC estimates.
            $table = null;
            $rows = 0;
            while (($line = fgets(STDIN)) !== false) {
                if ($table === null && preg_match('/^COPY (.+) \(.+\) FROM stdin;\r?\n$/', $line, $match) === 1) {
                    $table = $match[1];
                    $rows = 0;
                } elseif ($table !== null && rtrim($line, "\r\n") === '\\.') {
                    echo "DO \$verify\$ BEGIN IF (SELECT count(*) FROM {$table}) <> {$rows} THEN RAISE EXCEPTION 'Backup row count mismatch'; END IF; END \$verify\$;\n";
                    $table = null;
                } elseif ($table !== null) {
                    ++$rows;
                }
            }
            if ($table !== null) {
                throw new RuntimeException('Incomplete COPY data.');
            }
            break;
        default:
            exit(1);
    }
} catch (Throwable) {
    fwrite(STDERR, "Backup metadata processing failed.\n");
    exit(1);
}
