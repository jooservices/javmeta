<?php

declare(strict_types=1);

// Defaults only: the values are settings in the database (docs/03-operations/settings.md) and are edited in the UI.
// Downloads and the file server are OFF until the owner approves docs/decisions/downloads-and-capture-decision.md.
return [
    'enabled' => false,
    'qbittorrent_url' => 'http://127.0.0.1:8080',
    'qbittorrent_username' => 'admin',
    'qbittorrent_password' => '',
    'category' => 'javmeta',
    'save_path' => '',
    'timeout' => 10,
    'files_enabled' => false,
    'root_folders' => [],
    'link_ttl_minutes' => 60,
    'allowed_networks' => ['127.0.0.1', '::1', '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16'],
];
