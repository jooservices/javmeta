<?php

declare(strict_types=1);

/*
| Adding a node from the Command Center (docs/decisions/node-enrollment.md). These are defaults; the values are
| database settings (group "nodes") edited on the Settings page.
*/
return [
    'enrollment_ttl_minutes' => 30,
    // The repository and ref a joining node installs. The node must be able to read the repository.
    'release_repo_url' => '',
    'release_ref' => '',
    'roles' => ['worker'],
];
