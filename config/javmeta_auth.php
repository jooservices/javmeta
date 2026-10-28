<?php

declare(strict_types=1);

return [
    'owner_admin_token' => env('JAVMETA_OWNER_ADMIN_TOKEN', ''),
    // Plaintext API key (jvm_...) with the mcp scope required for mcp:serve. Empty → MCP refuses tools.
    'mcp_api_key' => env('JAVMETA_MCP_API_KEY', ''),
];
