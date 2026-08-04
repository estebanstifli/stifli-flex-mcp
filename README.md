# StifLi Flex MCP

MCP Server for WordPress with built-in Undo, plus optional AI Copilot and AI Chat Agent.

- WordPress plugin (pure PHP, no build step)
- OAuth 2.1 + PKCE for external MCP clients
- 122+ built-in tools (WordPress + WooCommerce + integrations)
- Full change tracking with rollback/redo

## Quick Links

- Plugin URL: https://github.com/estebanstifli/stifli-flex-mcp
- Documentation: https://andromedanova.com/stifli-flex-mcp.html
- WordPress readme: readme.txt

## Video

Watch: Claude to WordPress MCP Connector in 1 minute.

[![Watch the video](https://img.youtube.com/vi/AcmvwRzoOSM/hqdefault.jpg)](https://youtu.be/AcmvwRzoOSM)

## What Is Included

StifLi Flex MCP is modular. You can enable only what you need:

1. MCP Server (always active): JSON-RPC 2.0 + SSE endpoint for MCP clients.
2. AI Copilot (optional addon): writing assistant inside Gutenberg and Classic editor.
3. AI Chat Agent (optional addon): conversational admin for site management.
4. Automations, SEO, Plugin Integrations (optional addons).

## MCP Server Highlights

- Compatible with ChatGPT, Claude Desktop, LibreChat, Cursor, Cline, Roo Code, Windsurf, Claude Code
- Standard-based discovery and auth flow
- OAuth 2.1 with PKCE (S256)
- Dynamic Client Registration (RFC 7591)
- Discovery support (RFC 9728 and RFC 8414)
- Per-tool capability checks and tool profiles

## Undo and Safety

Every AI change is tracked with before/after snapshots:

- One-click rollback
- Redo support
- Session rollback
- Full audit trail

This covers changes made by MCP clients, AI Chat Agent, Copilot, and automations.

## Install

1. Upload `stifli-flex-mcp` to `/wp-content/plugins/` (or install from WP directory).
2. Activate plugin.
3. Choose addons in first-activation setup.
4. Go to `StifLi Flex MCP -> MCP Server`.
5. Copy SSE URL and connect your MCP client.

## Connect External Clients

1. Open `StifLi Flex MCP -> MCP Server`.
2. Copy the SSE URL.
3. Paste into your MCP client (ChatGPT/Claude Desktop/etc.).
4. Complete OAuth in browser.

## Notes

- WooCommerce tools load automatically when WooCommerce is installed.
- Addons can be changed later from `MCP Server -> Add-ons`.
- For full FAQ, service disclosures, and complete changelog, see readme.txt.
