<?php

declare(strict_types=1);

/*
 * Copy the live-captured HTML fixtures shipped with jooservices/crawlerx into
 * tests/Fixtures/crawlerx/<javmeta source slug>/ so the crawlerx contract tests
 * run against real pages without network access. Re-run after upgrading
 * crawlerx; SOURCE.json records which crawlerx version the copies came from.
 *
 *   docker/ci/run php tools/sync-crawlerx-fixtures.php
 */

$root = dirname(__DIR__);
$from = $root . '/vendor/jooservices/crawlerx/tests/Fixtures';
$to = $root . '/tests/Fixtures/crawlerx';

if (! is_dir($from)) {
    fwrite(STDERR, "crawlerx fixtures not found at {$from}. Run composer install first.\n");
    exit(1);
}

$installed = json_decode((string) file_get_contents($root . '/vendor/composer/installed.json'), true);
$version = 'unknown';
foreach ($installed['packages'] ?? [] as $package) {
    if (($package['name'] ?? null) === 'jooservices/crawlerx') {
        $version = (string) ($package['version'] ?? 'unknown');
    }
}

$copied = 0;
$kept = [];
foreach (glob($from . '/*/*.meta.json') ?: [] as $metaPath) {
    $bodyPath = substr($metaPath, 0, -strlen('.meta.json'));
    if (! is_file($bodyPath)) {
        continue;
    }

    // Fixture folders use adapter names (AvfanProfiles, JavLibrary); javmeta uses snake_case slugs.
    $folder = basename(dirname($metaPath));
    $slug = strtolower((string) preg_replace('/(?<=[a-z])(?=[A-Z][a-z])/', '_', $folder));
    $slug = $slug === 'javlibrary' || $slug === 'jav_library' ? 'javlibrary' : $slug;

    $targetDir = $to . '/' . $slug;
    if (! is_dir($targetDir) && ! mkdir($targetDir, 0775, true) && ! is_dir($targetDir)) {
        fwrite(STDERR, "Cannot create {$targetDir}\n");
        exit(1);
    }

    copy($bodyPath, $targetDir . '/' . basename($bodyPath));
    copy($metaPath, $targetDir . '/' . basename($metaPath));
    $kept[$targetDir . '/' . basename($bodyPath)] = true;
    $kept[$targetDir . '/' . basename($metaPath)] = true;
    $copied++;
}

if ($copied === 0) {
    fwrite(STDERR, "No crawlerx fixtures copied from {$from}. Leaving existing fixtures untouched.\n");
    exit(1);
}

// Fixtures crawlerx no longer ships (renamed or replaced samples) must not linger as stale contract cases.
$removed = 0;
foreach (glob($to . '/*/*') ?: [] as $existing) {
    if (is_file($existing) && ! isset($kept[$existing])) {
        unlink($existing);
        $removed++;
    }
}

file_put_contents($to . '/SOURCE.json', json_encode([
    'package' => 'jooservices/crawlerx',
    'version' => $version,
    'fixtures' => $copied,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

fwrite(STDOUT, "Copied {$copied} fixtures from jooservices/crawlerx {$version}" . ($removed > 0 ? ", removed {$removed} stale files" : '') . ".\n");
