# Flex MCP - Connect ChatGPT, Claude and AI agents with MCP, Undo, Abilities and Tools

[![WordPress 5.9+](https://img.shields.io/badge/WordPress-5.9%2B-21759B?logo=wordpress&logoColor=white)](https://wordpress.org)
[![Tested up to 7.0](https://img.shields.io/badge/Tested%20up%20to-7.0-21759B?logo=wordpress&logoColor=white)](https://wordpress.org)
[![PHP 7.4+](https://img.shields.io/badge/PHP-7.4%2B-777BB4?logo=php&logoColor=white)](https://www.php.net)
[![MCP 2026-07-28](https://img.shields.io/badge/MCP-2026--07--28-0A7CFF)](https://modelcontextprotocol.io/specification/2026-07-28/)
[![OAuth 2.1 + PKCE](https://img.shields.io/badge/OAuth-2.1%20%2B%20PKCE-4CAF50)](https://datatracker.ietf.org/doc/html/rfc7636)
[![License GPLv2+](https://img.shields.io/badge/License-GPLv2%2B-blue.svg)](https://www.gnu.org/licenses/gpl-2.0.html)

Flex MCP is a WordPress plugin that turns your site into a secure Model Context Protocol (MCP) server for ChatGPT, Claude Desktop, Gemini, and other MCP clients. It includes built-in Undo, OAuth 2.1 authentication, and 122+ tools for WordPress, WooCommerce, SEO, media, and automations.

## Managing Many WordPress Sites?

If you are an agency or manage many WordPress installations, use **WP MCP Hub** as one central point for managing them all.

WP MCP Hub is **free and open source**: connect one local AI client entry to multiple WordPress MCP servers while keeping credentials under local operating-system control.

- Product page: https://andromedanova.com/wp-mcp-hub.html
- GitHub repo: https://github.com/estebanstifli/wp-mcp-hub

## Why This MCP Server for WordPress

- MCP server for WordPress with real tool execution over JSON-RPC 2.0 and SSE
- OAuth 2.1 + PKCE authentication for external clients (no shared secrets)
- Built-in rollback and redo for AI-driven changes
- 122+ tools out of the box, and 200+ with supported integrations
- Modular architecture: enable only the add-ons your site needs

## Watch the 1-Minute Demo

Claude to WordPress MCP Connector in 1 minute:

[![Watch the video](https://img.youtube.com/vi/AcmvwRzoOSM/hqdefault.jpg)](https://youtu.be/AcmvwRzoOSM)

## What You Can Enable

1. MCP Server (always active)
2. AI Copilot (optional add-on)
3. AI Chat Agent (optional add-on)
4. Automations, SEO, and Plugin Integrations (optional add-ons)

Default install is lightweight: MCP Server only.

## MCP 3.6.0 Compatibility and Controls

- MCP `2026-07-28`: stateless POST requests to `/wp-json/stifli-flex-mcp/v1/messages`, `server/discover`, per-request capabilities, result discriminators and private cache hints. Send `MCP-Protocol-Version` and `Mcp-Method`; also send `Mcp-Name` for tool calls and resource reads. Include `io.modelcontextprotocol/protocolVersion` and an object-valued `io.modelcontextprotocol/clientCapabilities` in `params._meta`.
- Server identity for `2025-11-25` and `2026-07-28` includes `websiteUrl`, a description and a display title containing the configured domain, port and site path. Server and tool names remain unchanged; older identity formats are preserved.
- Legacy clients retain `initialize` and SSE support for `2025-11-25`, `2025-06-18` and `2025-03-26`. The new protocol does not use the legacy initialization handshake or standalone GET streams. Subscriptions are not advertised.
- Image/video tasks use the `io.modelcontextprotocol/tasks` extension only when declared by the client. Other clients retain the existing repeat-call background-job fallback. WordPress cron must be running for media jobs.
- OAuth supports RFC 9207 issuer responses and HTTPS Client ID Metadata Documents (CIMD) for public PKCE clients only, not private-key JWT authentication. Metadata retrieval is size/time limited, uses WordPress safe HTTP, and does not follow redirects.
- OAuth settings include optional administrator approval for new clients and an Authorization-header diagnostic. Existing registrations remain authorized; administrator approval does not replace user consent. Pending clients must be approved or deleted even if the setting is later disabled.
- Alternative discovery URLs are `/wp-json/stifli-flex-mcp/v1/.well-known/oauth-authorization-server` and `/wp-json/stifli-flex-mcp/v1/.well-known/oauth-protected-resource?endpoint=messages` (use `endpoint=sse` for legacy SSE). These are useful when a proxy blocks root `.well-known` routes.
- Rate limits are 180 requests/minute per authenticated WordPress user and 30/minute per unauthenticated source IP; HTTP 429 includes `Retry-After`. The `sflmcp_authenticated_rate_limit` filter adjusts the authenticated limit. `CF-Connecting-IP` is trusted only when `REMOTE_ADDR` appears in the exact-IP list supplied by `sflmcp_trusted_proxy_ips`; other forwarded-IP headers are ignored.
- `wp_update_option` and `wp_update_settings` accept `dry_run: true`. Preview responses contain redacted before/after values, blocked options and a `state_hash`. Repeat the same intended update with that hash as `expected_hash` to reject changes detected since preview. This is a pre-write check, not an atomic database transaction. Existing option denylist and WordPress capabilities still apply.
- `wp_verify_rendered_page` checks public, same-origin HTML: HTTP status, title, canonical URL, description and expected text. It requires PHP DOM, rejects query parameters, does not follow redirects, sends no cookies and does not execute JavaScript or verify visual layout. Use a final public permalink. Existing installations receive the tool disabled until enabled manually or by applying a profile containing it.
- The optional **Compact Site** profile limits discovery to 12 editorial tools, including post writes and HTML verification. It is not a read-only profile. Upgrades preserve the active profile and tool toggles; manually reapplying a profile applies its current tool set. Compact discovery is not a replacement for capability checks or integration access controls.
- **Logs & Roll Back > Protocol** offers opt-in local statistics for versions, self-reported clients, methods, errors and mean request latency. Counts are bounded and retained for 30 days, with manual deletion. Tool arguments, Authorization headers, tokens and source IPs are not collected by this feature. Debug logging is separate.

Plugin-setting reads preserve empty strings and `null` in sensitive fields, so clients can distinguish unconfigured values from `[REDACTED]` configured secrets. Nonempty secrets and sensitive containers remain hidden.

### Development Checks

The plugin remains pure PHP with no production build or Node dependencies. Isolated checks use WordPress doubles, not a running site:

```powershell
php .dev/test-mcp-protocol.php
npm ci --prefix .dev/schema-check --ignore-scripts --no-audit --no-fund
node .dev/test-mcp-schema.cjs
```

The schema check downloads the versioned official MCP and Tasks schemas. Test a real WordPress installation, OAuth flow and target MCP clients before deployment; local contract tests do not establish live interoperability.

## Supported MCP Clients and AI Platforms

### External MCP clients

- ChatGPT Apps and Connectors
- Claude Desktop Connectors
- LibreChat
- Cursor, Cline, Roo Code, Windsurf, Claude Code

### Built-in AI Chat Agent providers

- OpenAI
- Anthropic Claude
- Google Gemini
- OpenRouter and Mistral through WordPress AI Client connectors

## Tool Coverage

- WordPress tools: posts, pages, comments, taxonomies, options, media, menus
- WooCommerce tools: products, variations, orders, coupons, shipping, taxes, webhooks
- SEO tools: Google Search Console and SEO metadata workflows
- Multimedia tools: image and video generation, stock image search
- Rollback tools: changelog, rollback, redo, session rollback

## Security and Control

- OAuth 2.1 with PKCE (S256)
- Dynamic Client Registration (RFC 7591)
- Discovery support (RFC 9728 and RFC 8414)
- Per-tool capability checks mapped to WordPress roles
- Tool profile restrictions (read-only, full management, safe mode, custom)
- Confirmation modes in AI Chat Agent

## Why Undo Matters

Every AI mutation is tracked with before/after snapshots:

- One-click rollback
- Redo support
- Session rollback
- Full audit trail

This applies to changes initiated from ChatGPT, Claude, built-in Chat Agent, Copilot, and automations.

## Quick Start

1. Upload the stifli-flex-mcp plugin folder to wp-content/plugins, or install from WordPress.
2. Activate the plugin.
3. Choose add-ons in first-activation setup.
4. Go to Flex MCP > MCP Server.
5. Copy the SSE URL.
6. Paste it into ChatGPT or Claude Desktop and complete OAuth.

## Common Use Cases

- Manage WordPress content through natural language
- Run WooCommerce catalog and order operations from MCP clients
- Generate and insert AI images and videos
- Build automations that call MCP tools on schedules or events
- Revert AI mistakes safely with rollback/redo

## Architecture at a Glance

1. MCP client connects to WordPress endpoint
2. OAuth 2.1 validates and scopes access
3. Tool dispatch executes WordPress or WooCommerce operations
4. Changelog stores before/after snapshots
5. Rollback and redo recover state when needed

## Documentation

- Documentation: https://andromedanova.com/stifli-flex-mcp.html
- Repository: https://github.com/estebanstifli/stifli-flex-mcp
- WordPress readme and full changelog: readme.txt

## Notes

- WooCommerce tools load automatically when WooCommerce is active.
- Add-ons can be enabled or disabled later from MCP Server > Add-ons.
- This plugin is pure PHP (no npm/webpack build pipeline).
