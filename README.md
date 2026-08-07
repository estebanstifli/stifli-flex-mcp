# StifLi Flex MCP - MCP Server for WordPress with Undo

[![WordPress 5.9+](https://img.shields.io/badge/WordPress-5.9%2B-21759B?logo=wordpress&logoColor=white)](https://wordpress.org)
[![Tested up to 7.0](https://img.shields.io/badge/Tested%20up%20to-7.0-21759B?logo=wordpress&logoColor=white)](https://wordpress.org)
[![PHP 7.4+](https://img.shields.io/badge/PHP-7.4%2B-777BB4?logo=php&logoColor=white)](https://www.php.net)
[![MCP 2025-11-25](https://img.shields.io/badge/MCP-2025--11--25-0A7CFF)](https://modelcontextprotocol.io/specification/2025-11-25/)
[![OAuth 2.1 + PKCE](https://img.shields.io/badge/OAuth-2.1%20%2B%20PKCE-4CAF50)](https://datatracker.ietf.org/doc/html/rfc7636)
[![License GPLv2+](https://img.shields.io/badge/License-GPLv2%2B-blue.svg)](https://www.gnu.org/licenses/gpl-2.0.html)

StifLi Flex MCP is a WordPress plugin that turns your site into a secure Model Context Protocol (MCP) server for ChatGPT, Claude Desktop, Gemini, and other MCP clients. It includes built-in Undo, OAuth 2.1 authentication, and 122+ tools for WordPress, WooCommerce, SEO, media, and automations.

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
4. Go to StifLi Flex MCP > MCP Server.
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
