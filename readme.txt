Easy Visual MCP
===============

WordPress plugin to expose MCP-style tools via a JSON-RPC endpoint compatible with ChatGPT and other LLM clients.

Key features
------------
- Exposes WordPress tooling (posts, users, comments, post meta, options, taxonomies, media, plugins, search, fetch, etc.)
- REST endpoint: `/wp-json/easy-visual-mcp/v1/messages` (JSON-RPC 2.0)
- Compatible with ChatGPT/LLM integrations (tools/functions discovery)
- Optional token-based authentication and user mapping
- SSE endpoint available for streamable real-time interactions

Installation
------------
1. Copy the `easy-visual-mcp` folder into your `wp-content/plugins/` directory.
2. Activate the plugin from the WordPress admin panel.

Basic usage
-----------
- Primary endpoint:

  POST /wp-json/easy-visual-mcp/v1/messages

- Request body must follow JSON-RPC 2.0. Example: list available tools

  {
    "jsonrpc": "2.0",
    "id": 1,
    "method": "tools/list"
  }

- Calling a tool example:

  {
    "jsonrpc": "2.0",
    "id": 2,
    "method": "tools/call",
    "params": {
      "name": "wp_get_posts",
      "arguments": { "limit": 3 }
    }
  }

- Typical response:

  {
    "jsonrpc": "2.0",
    "id": 2,
    "result": { ... }
  }

ChatGPT / LLM integration (functions/tools)
------------------------------------------

You can register this endpoint as an external function endpoint in ChatGPT or any LLM that supports JSON-RPC tools.

- Endpoint URL: `https://YOUR_DOMAIN/wp-json/easy-visual-mcp/v1/messages`
- Method: POST
- Content-Type: application/json
- Protocol: JSON-RPC 2.0

Typical flow:
1. Register the endpoint in the client.
2. Use `tools/list` to discover available functions/tools.
3. Call tools via `tools/call` with the appropriate parameters.

Authentication
--------------
- By default the endpoint is public (read-only for most tools).
- You can enable an optional token to restrict access. The admin settings allow you to set the token and optionally map it to a specific WP user.
- When enabled, provide the token either as HTTP header:

  Authorization: Bearer YOUR_TOKEN

  or as a query parameter:

  https://your.site/wp-json/easy-visual-mcp/v1/messages?token=YOUR_TOKEN

SSE / streaming support (important for ChatGPT Connectors)
---------------------------------------------------------
- A streamable endpoint is available at:

  GET /wp-json/easy-visual-mcp/v1/sse

- Many connectors (including ChatGPT custom connectors) require a streamable transport (Server-Sent Events) to operate correctly. If your connector can not open a streaming connection, it may be able to perform discovery (`tools/list`) but will fail when trying to execute streaming operations.

Notes and troubleshooting
-------------------------
- If you experience 4xx/5xx errors or the SSE connection times out, check for intermediate proxies, CDNs or WAFs (ModSecurity, Cloudflare) that may block or buffer long-lived requests. For SSE you may need to disable buffering or add an exception for the SSE path.
- If using token-based auth and requests return 401, verify the token in Settings → Easy Visual MCP and test both header and query token variants.

License
-------
GPL v2 or later.

Developed by: your-team
