<?php

declare(strict_types=1);

namespace JavMeta\Deploy;

/** @param array<string, string> $answers */
function mongoUri(array $answers): string
{
    $query = [];
    parse_str((string) parse_url($answers['MONGO_URI'] ?? '', PHP_URL_QUERY), $query);
    $query['authSource'] = $answers['MONGO_AUTH_SOURCE'];

    return 'mongodb://' . rawurlencode($answers['MONGO_USERNAME']) . ':' . rawurlencode($answers['MONGO_PASSWORD'])
        . '@' . $answers['MONGO_HOST'] . ':' . $answers['MONGO_PORT'] . '/' . rawurlencode($answers['MONGO_DATABASE'])
        . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
}

if (realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    echo mongoUri(getenv());
}
