# MCP usage

[Guide index](README.md) · [Canonical tool/input table](../03-operations/mcp.md)

HTTP JSON-RPC is `POST /api/v1/mcp`, authenticated with `X-API-Key`. Both HTTP and stdio require an active key that allows the `mcp` scope; an unrestricted key also works. Use a key restricted to `mcp` when only MCP is needed. The scope grants access to the tool server; REST family scopes are not rechecked for individual tools. The stdio entry point is `mcp:serve`; it reads the client key from `JAVMETA_MCP_API_KEY` as a transport credential, checks that the key is active and allows `mcp` at startup and before each tool call, and does not treat it as a feature setting.

The [tested cookbook](api-cookbook.md) includes real curl examples for `tools/list`, `lookup_movies`, `get_movie` and `search` with `entity: all`. Replace the fixture movie code and query with records in your own development database. `initialize` and `notifications/initialized` are also supported and covered by `McpHttpEndpointTest`.

For a strict filtered query use `lookup_movies`: multiple `actress_id` values mean co-stars, and `genre_all: true` requires every selected genre. For exploratory natural language use `search`; semantic results fall back to keyword search if vectors or the search service are unavailable. `get_catalog`, `suggest`, `get_release_calendar`, `get_related_movies`, `get_performer`, `lookup_performers`, `get_counts`, `crawl_status` and `system_status` cover the remaining read paths. Consult the canonical input table instead of inferring parameter support from REST.

Decode tool data from `result.content[0].text` (a JSON string). Tool validation/execution failures set `result.isError: true`; malformed JSON-RPC or unknown methods use a top-level `error` object. HTTP 200 alone does not prove a successful tool call. The server's advertised protocol version and exact message behavior are tested in `tests/Feature/Mcp/McpHttpEndpointTest.php`; do not assume SSE/session behavior just because a client calls the transport “streamable HTTP”.
