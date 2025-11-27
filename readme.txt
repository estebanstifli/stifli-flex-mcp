=== StifLi Flex MCP ===
Contributors: estebandezafra
Donate link: https://github.com/estebanstifli/stifli-flex-mcp
Tags: mcp, chatgpt, ai, automation, rest-api
Requires at least: 5.8
Tested up to: 6.8
Stable tag: 1.0.2
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Transform your WordPress site into an AI-powered Model Context Protocol (MCP) server with 124 tools for ChatGPT, Claude, and other AI agents.

== Description ==

StifLi Flex MCP transforms your WordPress site into a powerful Model Context Protocol (MCP) server, exposing 124 tools that AI agents like ChatGPT, Claude, and LibreChat can use to manage your WordPress and WooCommerce site.

**Key Features:**

* 58 WordPress tools (posts, pages, users, comments, media, taxonomies, options)
* 65 WooCommerce tools (products, orders, customers, coupons, shipping, taxes, webhooks)
* JSON-RPC 2.0 compliant REST endpoint
* Server-Sent Events (SSE) support for real-time streaming
* Profile-based tool management (8 predefined profiles + custom profiles)
* Token-based authentication with user mapping
* Compatible with ChatGPT Custom Connectors, Claude Desktop, LibreChat
* Granular permissions control

**Predefined Profiles:**

* WordPress Read Only - Safe read-only access to WordPress data
* WordPress Full Management - Complete WordPress CRUD operations
* WooCommerce Read Only - Query WooCommerce data without modifications
* WooCommerce Store Management - Full store management capabilities
* Complete E-commerce - All WooCommerce tools including advanced settings
* Complete Site - All 124 tools enabled
* Safe Mode - Non-sensitive read-only access
* Development/Debug - Diagnostic and configuration tools

**Use Cases:**

* Automate content publishing with AI assistants
* Manage WooCommerce stores through conversational interfaces
* Build AI-powered WordPress dashboards
* Create automated workflows for content management
* Enable AI agents to query and modify WordPress data

**Security Features:**

* Optional token authentication
* User permission mapping
* Tool-level capability checks
* Public access mode for read-only operations
* Profile-based tool restrictions

== Installation ==

1. Upload the `stifli-flex-mcp` folder to `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to StifLi Flex MCP → Settings to configure your endpoint
4. (Optional) Generate a token for authenticated access
5. Use the provided endpoint URLs to connect your AI client

**Endpoints:**

* HTTP JSON-RPC: `https://yoursite.com/wp-json/stifli-flex-mcp/v1/messages`
* SSE Streaming: `https://yoursite.com/wp-json/stifli-flex-mcp/v1/sse`

== Frequently Asked Questions ==

= What is MCP? =

Model Context Protocol (MCP) is a standard protocol for exposing tools and data to AI agents. This plugin implements an MCP-compatible server for WordPress.

= How do I connect ChatGPT? =

1. Generate a token in Settings
2. Create a custom connector in ChatGPT
3. Use the SSE endpoint URL with your token
4. ChatGPT will discover all available tools automatically

= Is this safe for production sites? =

Yes, when configured properly:
* Use token authentication (disable public access)
* Map tokens to users with minimal permissions
* Use profile-based restrictions (e.g., "WordPress Read Only")
* Review enabled tools in the Tools Management tab

= What permissions do AI agents have? =

Permissions are determined by:
1. The WordPress user mapped to the authentication token
2. The active profile (which tools are enabled)
3. Individual tool capability requirements

= Can I customize which tools are available? =

Yes! You can:
* Apply predefined profiles
* Manually enable/disable individual tools
* Create custom profiles
* Export/import profile configurations

= Does this work with WooCommerce? =

Yes! The plugin includes 65 WooCommerce-specific tools. WooCommerce tools will only function when WooCommerce is installed and active.

= How do I troubleshoot connection issues? =

1. Check the Settings tab for test commands
2. Test with PowerShell scripts (included in examples/)
3. Verify your hosting doesn't block SSE connections
4. Check WAF/CDN settings (may block long-lived connections)
5. Review debug.log with WP_DEBUG enabled

= Can I use this without WooCommerce? =

Absolutely! The 58 WordPress tools work independently. WooCommerce tools are optional.

== Screenshots ==

1. Settings tab - Configure tokens and view endpoint URLs
2. Profiles tab - Manage tool configurations
3. WordPress Tools tab - Enable/disable WordPress tools
4. WooCommerce Tools tab - Manage WooCommerce tools
5. ChatGPT integration example
6. Profile export/import functionality

== Changelog ==

= 1.0.0 =
* Initial public release
* 58 WordPress management tools (posts, pages, users, comments, media, taxonomies, options)
* 65 WooCommerce tools (products, orders, customers, coupons, shipping, taxes, webhooks)
* Profile-based tool management with 8 predefined profiles
* Token-based authentication with WordPress user mapping
* JSON-RPC 2.0 compliant REST API endpoint
* Server-Sent Events (SSE) support for real-time streaming
* Full internationalization support (i18n/l10n ready)
* Granular permission control per tool
* Profile import/export functionality
* Compatible with ChatGPT Custom Connectors, Claude Desktop, LibreChat

== Upgrade Notice ==

= 1.0.0 =
Initial release. Install and configure your MCP server to start automating WordPress with AI agents.
