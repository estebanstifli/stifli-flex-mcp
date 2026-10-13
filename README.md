# Flex MCP - Connect ChatGPT, Claude and AI agents with MCP, Undo, Abilities and Tools

[![WordPress 5.9+](https://img.shields.io/badge/WordPress-5.9%2B-21759B?logo=wordpress&logoColor=white)](https://wordpress.org)
[![Tested up to 7.0](https://img.shields.io/badge/Tested%20up%20to-7.0-21759B?logo=wordpress&logoColor=white)](https://wordpress.org)
[![PHP 7.4+](https://img.shields.io/badge/PHP-7.4%2B-777BB4?logo=php&logoColor=white)](https://www.php.net)
[![MCP 2026-07-28](https://img.shields.io/badge/MCP-2026--07--28-0A7CFF)](https://modelcontextprotocol.io/specification/2026-07-28/)
[![OAuth 2.1 + PKCE](https://img.shields.io/badge/OAuth-2.1%20%2B%20PKCE-4CAF50)](https://datatracker.ietf.org/doc/html/rfc7636)
[![License GPLv2+](https://img.shields.io/badge/License-GPLv2%2B-blue.svg)](https://www.gnu.org/licenses/gpl-2.0.html)

Flex MCP is a WordPress plugin that turns your site into a secure Model Context Protocol (MCP) server for ChatGPT, Claude Desktop, Gemini, and other MCP clients. It includes built-in Undo, OAuth 2.1 authentication, and 122+ tools for WordPress, WooCommerce, SEO, media, and automations.

For multiple sites, [WP MCP Hub](https://andromedanova.com/wp-mcp-hub.html) provides a free, open-source local entry point with credentials stored under operating-system control.

[Watch the one-minute Claude connection demo](https://youtu.be/AcmvwRzoOSM).

## What You Can Enable

1. MCP Server (always active)
2. AI Copilot (optional add-on)
3. AI Chat Agent (optional add-on)
4. Automations, SEO, and Plugin Integrations (optional add-ons)

Default install is lightweight: MCP Server only.

## MCP 3.6.1 Compatibility and Controls

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

### Standard MCP Tool Annotations

Every tool returned by `tools/list` includes boolean `readOnlyHint`, `destructiveHint`, `idempotentHint` and `openWorldHint`, alongside the existing `intent` and `requires_confirmation` fields. Core tools and optional integrations use an explicit reviewed policy, not naming-prefix guesses.

- Hints describe all supported modes: a tool accepting `dry_run` remains a write tool, `fetch` can mutate remote systems, and stock increments are not idempotent.
- `destructiveHint: false` means read-only or purely additive, not merely "does not delete". Updates, replacements, refunds and mixed create/update tools are treated conservatively. Built-in writes do not promise safe automatic retries.
- `openWorldHint` distinguishes local-data operations from external APIs, file downloads, notifications and other external interactions. A read-only hint does not imply public data or permission to execute.
- Imported WordPress Abilities use their live `meta.annotations.readonly`, `destructive` and `idempotent` boolean declarations, resolved through the original Ability slug. Read-only Abilities retain confirmation. Missing, null or invalid declarations use conservative defaults; optional boolean `meta.annotations.openWorldHint` can describe external interaction, otherwise it defaults to `true`.
- Unknown tools default to non-read-only, potentially destructive, non-idempotent and open-world, with confirmation required. Missing built-in classifications and invalid Ability hints are reported through the existing debug logger.

Classification corrections include `fetch`, `yoast_reindex`, Elementor widget additions/updates and WooCommerce bulk category assignment/deletion. Refund queries, sales reports and webhook details are sensitive reads. Annotations are advisory: capability checks, Ability permission callbacks, enabled-tool filtering and authorization remain unchanged. Refresh the connector's catalog to see updated hints.

### Conversation Files and Media Uploads

For images attached to or generated in ChatGPT, prefer **`wp_upload_image` with `file`**. It advertises the same `openai/fileParams` contract and downloads the image directly, without transferring base64 through the model. `filename` is optional for this path; `alt_text`, `title` and `post_id` remain available. It rejects non-image files before insertion and uses the same download limits and safeguards as `wp_upload_media`. The tool must be enabled, but does not require enabling `wp_upload_media`.

Existing clients can still call `wp_upload_image` with `image_data` and `filename`; this legacy base64 path and its response remain unchanged. Supply either `file` or `image_data`, never both. Refresh the connector's tool catalog to discover the new file input. Conversation-image uploads support Undo; restoring them afterwards requires a fresh upload because temporary file sources are not retained.

`wp_upload_media` accepts exactly one source: `file` (a ChatGPT conversation attachment), `url` (public HTTPS), or `content_base64` (raw base64 or a data URL). Its descriptor declares `_meta["openai/fileParams"]: ["file"]` following the [OpenAI file-input contract](https://developers.openai.com/plugins/reference#define-file-inputs). The file object declares `download_url`, `file_id`, `mime_type` and `file_name`; only the first two are required. JSON-string file objects are normalized for legacy clients, but the advertised schema remains an object.

```json
{
  "name": "wp_upload_media",
  "arguments": {
    "file": {
      "download_url": "https://example.com/temporary-download",
      "file_id": "file-example",
      "file_name": "photo.jfif"
    },
    "convert_to": "jpeg",
    "max_width": 1600,
    "max_kb": 400,
    "alt_text": "Product photograph"
  }
}
```

ChatGPT supplies the temporary file URL; users do not need to publish the attachment first. Other MCP clients can use `url` or `content_base64` with `filename`. File IDs are not download credentials and cannot be used alone.

- Requires `upload_files`; setting `post_id` additionally requires permission to edit that post.
- Supports WordPress-permitted documents, audio, video and raster images. Executable/active formats such as PHP, JavaScript, HTML, XML and SVG remain blocked, even if enabled by another plugin. Filename/MIME hints never grant permission to upload a type.
- Downloads use safe WordPress HTTP, validate every redirect (maximum three), send no WordPress credentials, and stream into temporary files. The limit is 20 MiB or the lower WordPress upload limit. Images are limited to 40 megapixels.
- Image-only options on `wp_upload_media`: `convert_to` (`jpeg`/`webp`), `max_width`, `max_height` (1–10000), `quality` (10–100, default 82), and `max_kb` (10–20480 KiB). Processing can flatten animated images and JPEG removes transparency. `wp_upload_image_from_url` is unchanged.
- With `max_kb`, processing tries lower quality and then progressively smaller dimensions, down to 40% of the initial dimension bounds, with at most 28 encoding attempts. PNG keeps lossless encoding; JPEG/WebP usually reach smaller sizes. An impossible target returns an error, not a successful oversized upload.
- Returns structured `id`, `url`, `filename`, `mime_type`, `size_bytes`, `optimized`, plus image `width`/`height`. New uploads are recorded for rollback; temporary URLs and base64 are omitted from MCP diagnostics and rollback arguments. Automatic redo is not available for this tool: upload again with a fresh URL or base64 after undoing. Expired URLs also require a new client-supplied URL.
- On upgrades the new tool starts disabled. Enable it under **MCP Server > WordPress Tools**, or apply **WordPress Full Management** / **Complete Site**. Existing profiles and tool toggles are preserved. Refresh the client's tool discovery after enabling it.

### Development Checks

The plugin remains pure PHP with no production build or Node dependencies. Isolated checks use WordPress doubles, not a running site:

```powershell
php .dev/test-mcp-protocol.php
php .dev/test-upload-media.php
npm ci --prefix .dev/schema-check --ignore-scripts --no-audit --no-fund
node .dev/test-mcp-schema.cjs
```

The schema check downloads the versioned official MCP and Tasks schemas. Test a real WordPress installation, OAuth flow and target MCP clients before deployment; local contract tests do not establish live interoperability.

To additionally measure real image output, enable PHP GD, set `SFLMCP_TEST_WP_ROOT` to a local WordPress source directory, and run `php .dev/test-upload-media.php --real-images`. This uses WordPress's GD editor without loading that site's configuration or database. HTTP and Media Library persistence remain isolated doubles.

## Clients and Providers

External MCP clients include ChatGPT, Claude Desktop, LibreChat, Cursor, Cline, Roo Code, Windsurf and Claude Code; connection support depends on the client's protocol version.

The built-in assistants support OpenAI, Anthropic Claude and Google Gemini, plus OpenRouter and Mistral through installed WordPress AI Client connectors. These features require provider credentials and may incur provider charges.

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

## Quick Start

1. Upload the `stifli-flex-mcp` plugin folder to `wp-content/plugins`, or install from WordPress.
2. Activate the plugin.
3. Choose add-ons in first-activation setup.
4. Go to Flex MCP > MCP Server.
5. Copy the messages URL for MCP 2026-07-28 clients, or the SSE URL for legacy connectors.
6. Add the URL to your MCP client and complete OAuth.

## Architecture at a Glance

1. MCP client connects to WordPress endpoint
2. OAuth 2.1 validates and scopes access
3. Tool dispatch executes WordPress or WooCommerce operations
4. Changelog stores before/after snapshots
5. Rollback and redo recover state when needed

## Documentation

- Documentation: https://andromedanova.com/stifli-flex-mcp.html
- Repository: https://github.com/estebanstifli/stifli-flex-mcp
- WordPress directory description, installation, FAQ and service disclosures: [readme.txt](readme.txt)
- Full release history: [changelog.txt](changelog.txt)

### Updating the WordPress.org Listing

WordPress.org processes `readme.txt`, not this GitHub README. Keep its Description concise and include only the latest release under Changelog. Add each release to `changelog.txt` as well, keeping all previous entries.

Publish both text files to SVN `trunk` and the release directory matching `Stable tag` (currently `tags/3.6.1`). The readme links to [the full changelog in SVN trunk](https://plugins.svn.wordpress.org/stifli-flex-mcp/trunk/changelog.txt), so the URL remains valid across releases. The new file must be added to SVN before that link becomes available.

## Notes

- WooCommerce tools load automatically when WooCommerce is active.
- Add-ons can be enabled or disabled later from MCP Server > Add-ons.
- This plugin is pure PHP (no npm/webpack build pipeline).
