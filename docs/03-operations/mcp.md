# MCP guide

JAVMeta exposes its tools over JSON-RPC 2.0 at `POST /api/v1/mcp`. Send an active API key in the `X-API-Key` header. The stdio server instead requires `JAVMETA_MCP_API_KEY` to contain an active key. A tool call returns JSON in `result.content[0].text`; invalid tool arguments set `result.isError` to `true` and include an error code and message.

## Tools and inputs

All listed inputs are optional unless marked **required**.

| Tool | Inputs |
|---|---|
| `lookup_movies` | `q`, `code`, `genre`, `actress`, `maker`, `series`, `label` (strings); `released_from`, `released_to` (valid `YYYY-MM-DD` dates); `runtime_min`, `runtime_max` (integers); `censored` (boolean, integer `0`/`1`, or string `0`/`1`/`censored`/`uncensored`); `sort` (`relevance`, `release_date`, `update_date`, `rating`; default `relevance`); `cursor` (string); `per_page` (integer 1–100; default 10); `count_only` (boolean). |
| `get_movie` | `code` (string, **required**): normalized DVD or amateur code. |
| `search` | `q` (string, **required**); `entity` (`movie`, `performer`, or `all`; default `movie`); `per_page` (integer 1–100; default 10). |
| `lookup_performers` | `q`, `cup`, `blood_type`, `location` (strings); `age_min`, `age_max`, `height_min`, `height_max`, `bust_min`, `bust_max`, `waist_min`, `waist_max`, `hip_min`, `hip_max` (integers); `sort` (`name`, `updated_at`, `linked_title_count`; default `name`); `cursor` (string); `per_page` (integer 1–100; default 10); `count_only` (boolean). |
| `get_counts` | No inputs. |
| `crawl_status` | No inputs. |
| `system_status` | No inputs. |
| `get_catalog` | `kind` (`genres`, `makers`, `series`, `labels`, `years`, **required**); `q`, `sort` (`movie_count`, `name`), `per_page` (1–200), `page`. |
| `suggest` | `q` (string, at least two characters, **required**); `limit` (1–20). |
| `get_related_movies` | `code` (string, **required**). |
| `get_release_calendar` | `month` (`YYYY-MM`; default the current month). |
| `get_performer` | `id` (numeric ID or UUID as a string, **required**). |

## Example calls

Set the endpoint and an active API key in your shell:

```bash
MCP_URL="http://localhost:8080/api/v1/mcp"
MCP_API_KEY="your-active-api-key"

mcp_call() {
  curl -sS "$MCP_URL" \
    -H "X-API-Key: $MCP_API_KEY" \
    -H "Content-Type: application/json" \
    --data "$1"
}
```

Call each tool by name with its `arguments` object:

```bash
mcp_call '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"lookup_movies","arguments":{"q":"STARS","per_page":5}}}'
mcp_call '{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"get_movie","arguments":{"code":"STARS-456"}}}'
mcp_call '{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"search","arguments":{"q":"schoolgirl at a tavern","entity":"movie","per_page":5}}}'
mcp_call '{"jsonrpc":"2.0","id":4,"method":"tools/call","params":{"name":"lookup_performers","arguments":{"q":"Yua Mikami","sort":"name","per_page":5}}}'
mcp_call '{"jsonrpc":"2.0","id":5,"method":"tools/call","params":{"name":"get_counts","arguments":{}}}'
mcp_call '{"jsonrpc":"2.0","id":6,"method":"tools/call","params":{"name":"crawl_status","arguments":{}}}'
mcp_call '{"jsonrpc":"2.0","id":7,"method":"tools/call","params":{"name":"system_status","arguments":{}}}'
mcp_call '{"jsonrpc":"2.0","id":8,"method":"tools/call","params":{"name":"get_performer","arguments":{"id":"123"}}}'
```

The `search` tool uses semantic search when vectors are available and falls back to keyword search when the embedder or search service is disabled. The REST movie endpoint exposes the same movie search with `GET /api/v1/movies?q=<query>&semantic=true`; omit `semantic` for keyword search.

The endpoint also supports `initialize` and `tools/list`. Movie and performer detail calls return the same resource fields as `GET /api/v1/movies/{code}` and `GET /api/v1/performers/{id}`.
