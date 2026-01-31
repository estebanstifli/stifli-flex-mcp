=== StifLi Flex MCP ===
Contributors: estebandezafra
Donate link: https://github.com/estebanstifli/stifli-flex-mcp
Tags: mcp, chatgpt, ai, automation, rest-api
Requires at least: 5.8
Tested up to: 6.9
Stable tag: 1.0.5
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Transform your WordPress site into an AI-powered Model Context Protocol (MCP) server with 117 tools for ChatGPT, Claude, and other AI agents.

== Description ==

StifLi Flex MCP transforms your WordPress site into a powerful Model Context Protocol (MCP) server, exposing 117 tools that AI agents like ChatGPT, Claude, and LibreChat can use to manage your WordPress and WooCommerce site.

**Key Features:**

* 55 WordPress tools (posts, pages, comments, media, taxonomies, options)
* 61 WooCommerce tools (products, orders, coupons, shipping, taxes, webhooks)
* JSON-RPC 2.0 compliant REST endpoint
* Server-Sent Events (SSE) support for real-time streaming
* Profile-based tool management (8 predefined profiles + custom profiles)
* WordPress Application Passwords authentication (recommended by WordPress.org)
* Compatible with ChatGPT Custom Connectors, Claude Desktop, LibreChat
* Granular permissions control

**Demo & Installation Tutorial:**

https://youtu.be/KHr1zt2R8Ew

**Predefined Profiles:**

* WordPress Read Only - Safe read-only access to WordPress data
* WordPress Full Management - Complete WordPress CRUD operations
* WooCommerce Read Only - Query WooCommerce data without modifications
* WooCommerce Store Management - Full store management capabilities
* Complete E-commerce - All WooCommerce tools including advanced settings
* Complete Site - All 117 tools enabled
* Safe Mode - Non-sensitive read-only access
* Development/Debug - Diagnostic and configuration tools

**Use Cases:**

* Automate content publishing with AI assistants
* Manage WooCommerce stores through conversational interfaces
* Build AI-powered WordPress dashboards
* Create automated workflows for content management
* Enable AI agents to query and modify WordPress data

**Security Features:**

* WordPress Application Passwords (native WordPress 5.6+ feature)
* HTTP Basic Authentication (industry standard)
* Tool-level capability checks
* Profile-based tool restrictions

== Installation ==

1. Upload the `stifli-flex-mcp` folder to `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to StifLi Flex MCP → Settings for setup instructions
4. Create an Application Password in your WordPress profile (Users → Profile → Application Passwords)
5. Use HTTP Basic Authentication with your username and application password

**Endpoints:**

* HTTP JSON-RPC: `https://yoursite.com/wp-json/stifli-flex-mcp/v1/messages`
* SSE Streaming: `https://yoursite.com/wp-json/stifli-flex-mcp/v1/sse`

== Frequently Asked Questions ==

= What is MCP? =

Model Context Protocol (MCP) is a standard protocol for exposing tools and data to AI agents. This plugin implements an MCP-compatible server for WordPress.

= How do I connect ChatGPT? =

1. Create an Application Password in your WordPress profile (Users → Profile)
2. Create a custom connector in ChatGPT
3. Use the SSE endpoint URL with HTTP Basic Authentication
4. ChatGPT will discover all available tools automatically

= Is this safe for production sites? =

Yes, when configured properly:
* Uses WordPress Application Passwords (native security feature)
* Each Application Password is tied to a WordPress user with specific permissions
* Use profile-based restrictions (e.g., "WordPress Read Only")
* Review enabled tools in the Tools Management tab
* You can revoke Application Passwords at any time

= What permissions do AI agents have? =

Permissions are determined by:
1. The WordPress user's Application Password used for authentication
2. The active profile (which tools are enabled)
3. Individual tool capability requirements

= Can I customize which tools are available? =

Yes! You can:
* Apply predefined profiles
* Manually enable/disable individual tools
* Create custom profiles
* Export/import profile configurations

= Does this work with WooCommerce? =

Yes! The plugin includes 61 WooCommerce-specific tools. WooCommerce tools will only function when WooCommerce is installed and active.

= How do I troubleshoot connection issues? =

1. Check the Settings tab for test commands
2. Test with PowerShell scripts (included in examples/)
3. Verify your hosting doesn't block SSE connections
4. Check WAF/CDN settings (may block long-lived connections)
5. Review debug.log with WP_DEBUG enabled

= Can I use this without WooCommerce? =

Absolutely! The 55 WordPress tools work independently. WooCommerce tools are optional.

== Screenshots ==

1. Settings tab - Setup instructions and endpoint URLs
2. Profiles tab - Manage tool configurations
3. WordPress Tools tab - Enable/disable WordPress tools
4. WooCommerce Tools tab - Manage WooCommerce tools


== Changelog ==
= 1.0.5 =
* Fix: Resolved object persistence issue in PHP 8.1+ causing 404 errors on API endpoints.
* Fix: Deferred WooCommerce detection to ensure tools load correctly regardless of plugin load order.
* New: Added "Demo & Installation Tutorial" video to description.

= 1.0.4 =
* New: Debug logging system with dedicated log file (wp-content/uploads/sflmcp-logs/)
* New: Logs tab in admin UI to enable/disable logging and view debug logs
* New: Clear logs and refresh functionality from admin panel
* New: "WordPress Full Management" profile now active by default on fresh installs
* Security: Log directory protected with .htaccess and index.php


= 1.0.3 =
* Security: Replaced custom token authentication with WordPress Application Passwords
* Security: Removed wp_set_current_user calls for compliance with WordPress.org guidelines
* Removed: User management tools (wp_create_user, wp_update_user, wp_delete_user)
* Removed: Customer management tools (wc_get_customers, wc_create_customer, wc_update_customer, wc_delete_customer)
* Updated: Settings page now guides users to create Application Passwords
* Improved: Authentication uses native WordPress security features

= 1.0.0 =
* Initial public release
* 55 WordPress management tools (posts, pages, comments, media, taxonomies, options)
* 61 WooCommerce tools (products, orders, coupons, shipping, taxes, webhooks)
* Profile-based tool management with 8 predefined profiles
* WordPress Application Passwords authentication
* JSON-RPC 2.0 compliant REST API endpoint
* Server-Sent Events (SSE) support for real-time streaming
* Full internationalization support (i18n/l10n ready)
* Granular permission control per tool
* Profile import/export functionality
* Compatible with ChatGPT Custom Connectors, Claude Desktop, LibreChat

== Upgrade Notice ==

= 1.0.0 =
Initial release. Install and configure your MCP server to start automating WordPress with AI agents.
