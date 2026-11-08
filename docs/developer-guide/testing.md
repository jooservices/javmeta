# Tests and documentation checks

[Guide index](README.md)

Run from the ticket worktree on this workstation. The long-lived shared runtime supplies PHP 8.5 and Node. Do not create another container. Install dependencies with the runtime if `vendor/` is missing:

```bash
/home/joos/Workspaces/scripts/docker/runtime php@8.5 composer install --no-interaction --prefer-dist
```

Start with the changed areas:

```bash
/home/joos/Workspaces/scripts/docker/runtime php@8.5 php vendor/bin/phpunit tests/Unit/Docs/SettingsReferenceTest.php tests/Feature/Docs/ApiCookbookTest.php
/home/joos/Workspaces/scripts/docker/runtime node node ui-tests/render-smoke.mjs
/home/joos/Workspaces/scripts/docker/runtime node node ui-tests/docs-cookbook.mjs
```

Run the complete unit/feature suite before handing over:

```bash
/home/joos/Workspaces/scripts/docker/runtime php@8.5 php vendor/bin/phpunit
```

`phpunit.xml` forces SQLite `:memory:` and testing mode, array cache/session, fixture-safe endpoints and no Telegram credentials. `Tests\TestCase` pins SQLite before boot. Do not copy a live database configuration into a test worktree. Feature tests use factories and bind dependency monitors, archive/search fakes and `FakeConfigStore` where appropriate. An application running on PostgreSQL/Mongo/Elasticsearch needs separate integration verification; an in-memory test is not a production timing measurement.

The render smoke test renders views with fake API responses and checks escaping, labels, filters, pagination and UI states. It does not open a browser or prove pixel layout. It includes 375 px viewport state checks; visual mobile testing remains QA's browser check. The cookbook transport test runs the published curl script against a loopback capture server with fixture credentials; `ApiCookbookTest` separately exercises those exact method/path/body examples against the Laravel application with SQLite. Neither test calls staging or creates real API keys there.

After changing a setting definition, regenerate the reference and run its test:

```bash
/home/joos/Workspaces/scripts/docker/runtime php@8.5 php tools/docs/settings-reference.php > docs/user-guide/settings-reference.md
/home/joos/Workspaces/scripts/docker/runtime php@8.5 php vendor/bin/phpunit tests/Unit/Docs/SettingsReferenceTest.php
```

The generator reads only the schema and does not boot Laravel, read defaults or retrieve saved secrets. Its byte-for-byte test rejects an out-of-date reference; the second test checks every label, description, key and group. API route drift is caught by `tests/Feature/Api/OpenApiContractTest.php`; MCP input documentation drift by `tests/Feature/Mcp/McpToolServiceTest.php`. Run these when extending a public interface:

```bash
/home/joos/Workspaces/scripts/docker/runtime php@8.5 php vendor/bin/phpunit tests/Feature/Api/OpenApiContractTest.php tests/Feature/Mcp/McpParityTest.php tests/Feature/Mcp/McpToolServiceTest.php
```

Keep failures and their evidence in the handoff. Do not lower assertions or run PHPCS/PHPMD for this assignment.
