# Copilot Instructions for Easy Visual MCP

## Project Overview
- **Easy Visual MCP** is a WordPress plugin that exposes WordPress management tools via a JSON-RPC endpoint, designed for integration with LLMs (e.g., ChatGPT) and automation clients.
- The plugin provides endpoints for tool discovery (`tools/list`), tool execution (`tools/call`), and supports both HTTP POST and SSE (Server-Sent Events) for real-time streaming.
- All endpoints are registered under `/wp-json/easy-visual-mcp/v1/`.

## Architecture & Key Files
- **Entry point:** `easy-visual-mcp.php` (loads all helpers, models, and the main module)
- **Main logic:** `mod.php` (REST API registration, authentication, JSON-RPC dispatch, SSE streaming, admin UI)
- **Tool definitions & dispatch:** `models/model.php` (`EasyVisualMcpModel`)
- **Helpers:** `models/utils.php`, `models/frame.php`, `models/dispatcher.php`, `models/req.php`
- **Controller stub:** `controller.php`
- **Examples:** `examples/wordpress-mcp.http` (HTTP request samples)

## Tooling & Extensibility
- Tools are defined in `EasyVisualMcpModel::getTools()` as arrays with `name`, `description`, and `inputSchema` (JSON Schema-like).
- Tool execution is routed via `EasyVisualMcpModel::dispatchTool($tool, $args, $id)`.
- To add a new tool, extend the `$tools` array and add a case in `dispatchTool`.
- Each tool can specify required WordPress capabilities (see `getToolCapability`).

## Authentication & Security
- By default, endpoints are public (read-only for most tools).
- Admins can configure a Bearer token in the plugin settings (see `mod.php` and admin UI).
- If a token is set, it must be provided via `Authorization: Bearer <token>` header or `?token=` query param.
- Token can be mapped to a specific WP user for permission scoping.

## Developer Workflows
- **No build step**: This is a pure PHP plugin, no compilation required.
- **Testing**: Use HTTP clients (e.g., REST Client, curl, Postman) to POST to `/messages` or connect to `/sse`.
- **Debugging**: Enable `WP_DEBUG` in WordPress to log detailed events and errors (see `mod.php`).
- **Admin UI**: Settings and token management are available in the WordPress admin panel.

## Project Conventions
- All REST endpoints are registered in `mod.php` using the `rest_api_init` hook.
- Tool schemas use a simplified JSON Schema for parameter validation.
- Permission checks are centralized in `getToolCapability` and enforced before tool execution.
- Use `EasyVisualMcpUtils::getArrayValue` for safe array access.
- All new tools should be documented in the `readme.txt` and, if needed, in `TODO.md`.

## Integration Points
- Designed for LLM/AI integration: tools are discoverable and callable via JSON-RPC 2.0.
- SSE endpoint is required for streaming/real-time integrations (e.g., ChatGPT Connectors).
- Example HTTP requests and environment variables are in `examples/` and `.vscode/rest-client.env.json`.

## References
- See `readme.txt` for endpoint usage, authentication, and integration examples.
- See `MIGRACION_TOOLS.md` for migration notes and tool porting checklist.
- See `TODO.md` for current development priorities and open tasks.

---

**Example: Adding a new tool**
1. Add a new entry to the `$tools` array in `models/model.php`.
2. Implement the tool logic in the `dispatchTool` method.
3. Document the tool in `readme.txt` and update `TODO.md` if needed.

---

For questions about project-specific conventions or unclear patterns, review the admin UI, `readme.txt`, and the main PHP files listed above.