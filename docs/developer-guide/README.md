# Developer guide

This guide describes the checked-in Laravel 13 / PHP 8.5 application, not unmerged feature branches or a verified staging deployment. The UI is plain ES modules in `public/ui`; there is no frontend build step.

| Task | Start here |
| --- | --- |
| Understand data flow and boundaries | [Architecture](architecture.md) |
| Run unit, feature and UI checks | [Testing](testing.md) |
| Add a setting, filter, MCP tool, view or source | [Extension checklists](extending.md) |
| Call the API with curl; understand keys, errors and limits | [API cookbook](api-cookbook.md) |
| Connect an AI client or call tools | [MCP](mcp.md) |
| Understand documentation corrections and evidence | [Verified differences](verified-differences.md) |
| Use the product | [Vietnamese user guide](../user-guide/README.md) |

Operational procedures remain canonical in [settings](../03-operations/settings.md), [API contract](../03-operations/api-contract.md), [performer identity](../03-operations/performer-identity.md), [performance](../03-operations/performance.md) and the [staging runbook](../03-operations/staging-runbook.md). Deployment and merging belong to the tech lead / QA.
