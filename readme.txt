=== Flex MCP - Connect ChatGPT, Claude and AI agents with MCP, Undo, Abilities and Tools. ===

Contributors: estebandezafra
Donate link: https://github.com/estebanstifli/stifli-flex-mcp
Tags:  mcp, abilities, chatgpt, claude, gemini
Requires at least: 5.9
Tested up to: 7.1
Stable tag: 3.6.1
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

The most secure MCP Server for WordPress with Undo, plus AI Copilot & Chat Agent. ChatGPT, Claude, Gemini, OpenRouter & Mistral.

== Description ==

**Flex MCP** connects ChatGPT, Claude Desktop and other Model Context Protocol (MCP) clients to WordPress. Manage content, WooCommerce, media and settings through conversation, with OAuth 2.1 authentication, tool permissions and built-in Undo for tracked changes.

= Main features =

* **MCP Server** - 122+ built-in tools for WordPress and WooCommerce, plus tools from supported plugin integrations.
* **Control and recovery** - WordPress capability checks, tool profiles, activity logs, rollback, redo and session rollback.
* **AI Copilot** - Write, rewrite and update content inside Gutenberg or the Classic Editor.
* **AI Chat Agent** - Manage your site from a conversational interface in WordPress admin.
* **Multimedia** - Generate images and videos, or search stock images using configured providers.
* **Automations and SEO** - Run scheduled or event-driven workflows and connect Google Search Console.
* **WordPress Abilities** - Discover and import abilities registered by compatible plugins on WordPress 6.9+.

The MCP Server is always active. Copilot, Chat Agent, automations, SEO and plugin integrations are optional add-ons; enable only what you need during setup or later from **MCP Server > Add-ons**.

= Connections and providers =

External MCP clients connect through OAuth 2.1 with PKCE. Use the messages endpoint for MCP 2026-07-28 or SSE for supported legacy clients. Application Passwords are also available.

The built-in assistants support OpenAI, Anthropic Claude and Google Gemini, with OpenRouter and Mistral available through installed WordPress AI Client connectors. Provider credentials are required for these features; provider charges may apply. See External Services below.

= Learn more =

* [Documentation and setup](https://andromedanova.com/stifli-flex-mcp.html)
* [Source code and technical reference](https://github.com/estebanstifli/stifli-flex-mcp)
* [WP MCP Hub](https://andromedanova.com/wp-mcp-hub.html) - Free, open-source local management for multiple WordPress MCP sites.

Watch the one-minute Claude connection demo:

https://youtu.be/AcmvwRzoOSM

== Installation ==

= Quick Start (MCP Server) =

1. Upload the `stifli-flex-mcp` folder to `/wp-content/plugins/` or install from the WordPress plugin directory
2. Activate the plugin
3. Choose the addons you want on the first-activation screen. Leave them unchecked for the lightweight MCP Server-only setup.
4. Continue to **Flex MCP → MCP Server**
5. Connect your MCP client through OAuth 2.1 using the messages URL for MCP 2026-07-28, or the SSE URL for legacy connectors

Multimedia tools and Logs & Roll Back are included in the MCP Server layer and are always available. Addons can be changed later from **MCP Server → Add-ons**; disabled addons do not load their PHP classes, menus, hooks, or background jobs.

= Quick Start (AI Copilot) =

1. Enable **AI Copilot** on the first-activation screen or from **MCP Server → Add-ons**
2. Go to **Flex MCP → AI Copilot** and make sure it’s enabled
3. Go to **Flex MCP → AI Chat Agent → Settings** and enter your API key
4. Open any post or page in the editor — the Copilot widget appears automatically
5. Start writing with AI!

= Quick Start (AI Chat Agent) =

1. Enable **AI Chat Agent** on the first-activation screen or from **MCP Server → Add-ons**
2. Go to **Flex MCP → AI Chat Agent**
3. Open the **Settings** tab and select your AI provider (OpenAI, Claude, Gemini, or installed WordPress AI Client connectors like OpenRouter/Mistral)
4. Enter your API key
5. Start chatting!

That's it — no external tools, no complex configuration. Your AI agent is ready.

= Connect External MCP Clients =

To connect external AI clients (ChatGPT, Claude Desktop, LibreChat):

1. Go to **Flex MCP → MCP Server**
2. Copy the SSE URL shown on the Settings page
3. Paste it in your AI client:
   * **Claude Desktop:** Customize → Connectors → Add custom connector → Paste the URL
   * **ChatGPT:** Settings → Apps & Connectors → Advanced settings → Enable Developer mode → Create app → Paste the URL → Choose OAuth
4. A browser window will open — log in to WordPress and click "Authorize"
5. Done! Your AI client can now manage your WordPress site

No API keys, no passwords — OAuth 2.1 handles everything securely and automatically.

== Frequently Asked Questions ==

= Can I upload files attached to a ChatGPT conversation? =

Yes. For images, use `wp_upload_image` with a conversation `file` instead of slow base64 transfer. Its existing `image_data` + `filename` input remains available for older clients. For documents or image optimization, enable `wp_upload_media` in **MCP Server > WordPress Tools**. Refresh your client's tools after updating. Documents must be allowed by WordPress; executable files, HTML, XML and SVG are blocked. Conversation file downloads are limited to 20 MiB or the lower site limit.

With `wp_upload_media`, request JPEG/WebP conversion, maximum dimensions and a target size, for example JPEG at 1600 pixels wide and no more than 400 KiB. Processing may reduce quality and dimensions; if the target cannot be met, the tool returns an error without leaving a successful oversized upload. Legacy base64 image uploads remain compatible.

= What is the AI Copilot? =

The AI Copilot is a floating assistant that appears inside the WordPress editor (Gutenberg or Classic). It reads the context of what you’re editing and helps you write, rewrite, optimize, generate tags, create excerpts, and even generate images — all without leaving the editor. Every change can be undone with one click.

= How is the Copilot different from the Chat Agent? =

The **Copilot** lives inside the post/page editor and is focused on writing and content editing. It works directly with the editor fields (title, content blocks, excerpt, tags, etc.).

The **Chat Agent** is a standalone admin page where you can manage your entire WordPress site through conversation — create posts, manage WooCommerce orders, check settings, install plugins, and more.

Both use the same AI provider and API key.

= How do I set up the AI Chat Agent? =

1. Go to Flex MCP → AI Chat Agent → Settings
2. Choose your AI provider (OpenAI, Claude, Gemini, or installed WordPress AI Client connectors like OpenRouter/Mistral)
3. Enter your API key (you get this from your AI provider's website)
4. Go to the Chat tab and start talking!

= Which AI provider should I use? =

OpenAI, Claude, and Gemini all work great, and you can also use OpenRouter or Mistral via WordPress AI Client connectors. Here's a quick comparison:

* **OpenAI (GPT-4o / GPT-4.5)** — Best overall balance of speed and quality
* **Claude (Opus / Sonnet)** — Excellent at understanding complex instructions and writing
* **Gemini (2.5 Pro / Flash)** — Great value, fast responses

You can switch providers at any time from the Settings tab.

= What can the AI agent do with my site? =

The agent has access to 122+ tools covering:

* **Content** — Create, edit, delete posts, pages, and comments
* **Media** — Upload, list, and manage images and files
* **AI Generation** — Generate images (DALL·E, Imagen) and videos (Sora, Veo) with AI
* **WooCommerce** — Products, orders, coupons, customers, shipping, taxes
* **Taxonomies** — Categories, tags, custom taxonomies
* **Settings** — Site options, menus, navigation
* **System** — Plugins, themes, site health

You control which tools are available through Profiles.

= Is it safe to let AI manage my site? =

Yes, with multiple layers of protection:

* **OAuth 2.1 with PKCE** — Industry-standard secure authentication for external AI clients, no shared passwords
* **Tool confirmations** — In "Ask User" mode, you approve every action before it executes
* **Permission checks** — Every tool verifies WordPress capabilities before running  
* **Profiles** — Restrict which tools are available (e.g., "Read Only" profiles)
* **Token management** — Revoke access for any client instantly from the admin panel

= What is MCP? =

Model Context Protocol (MCP) is a standard for connecting AI agents to data sources and tools. This plugin implements an MCP server so external AI clients like ChatGPT or Claude Desktop can discover and use your WordPress tools. This is in addition to the built-in AI Chat Agent.

= Does this work with WooCommerce? =

Yes! The plugin includes 61 WooCommerce tools. They activate automatically when WooCommerce is installed. Ask your AI agent "Show me today's orders" and it just works.

= Can I create my own tools? =

Yes, through WordPress Abilities. Legacy Custom Tools have been retired for security reasons; use plugins that register WordPress Abilities with explicit schemas and permission callbacks, then import them from the Abilities tab.

= What happens if the AI makes a mistake? =

No worries — Flex MCP is the only MCP server with a built-in **Roll Back** system. Every change made by any AI (ChatGPT, Claude, the Chat Agent, Copilot, or automations) is tracked with a full before/after snapshot. Go to **Logs & Roll Back** in your admin panel and undo any change with one click. You can even roll back an entire session at once.

= Can the AI generate images? =

Yes! The `wp_generate_image` tool supports multiple providers:

* **OpenAI** — gpt-image-1 (default), gpt-image-1.5, gpt-image-2, gpt-image-1-mini, DALL·E 3, DALL·E 2
* **Google Gemini** — gemini-2.5-flash-image (default), gemini-3.1-flash-image-preview, gemini-3-pro-image-preview, Imagen 4

Just ask your AI agent "Generate an image of..." or configure defaults in **Flex MCP → Multimedia Settings → Images**.

= Can the AI search stock images too? =

Yes! The optional `wp_search_image` tool can search Unsplash, Pexels, and Pixabay and return one image with rich attribution metadata.

The tool response includes both text JSON and structured output with fields such as:

* `url`, `thumbnail_url`, `caption`, `alt_text`
* `author`, `author_url`, `source_url`
* image dimensions, license/metadata fields, and provider-specific fields like Unsplash `download_location`

Configure everything in **Flex MCP → Multimedia Settings → Search Image**:

* Global enable/disable toggle for `wp_search_image`
* Per-provider enable + API keys (Unsplash, Pexels, Pixabay)
* Preferred Image Bank (specific provider or random)
* Image Selection mode: `most_relevant`, `random_top10`, `random_top20`
* Extra parameters: orientation, safe search, Pixabay language, Pexels locale, and timeout

= Can the AI generate videos? =

Yes! The `wp_generate_video` tool supports:

* **OpenAI Sora** — Text-to-video and image-to-video generation
* **Google Veo** — Veo 2 and Veo 3 models

Video generation runs asynchronously in the background. Configure providers and API keys in **Flex MCP → Multimedia Settings → Videos**.

= Where do I configure API keys for image/video generation? =

Go to **Flex MCP → Multimedia Settings**. API keys are shared between the Images and Videos tabs — enter your OpenAI or Gemini key once and it works for both.

= What are WordPress Abilities? =

WordPress 6.9 introduced the Abilities API, letting plugins register standardized capabilities. If you have plugins that support Abilities, Flex MCP can auto-discover and import them from **MCP Server → Abilities** tab.

= How do I connect ChatGPT or Claude Desktop? =

It takes less than a minute:

1. Go to **Flex MCP → MCP Server** and copy the SSE URL
2. Paste it in your AI client:
   * **Claude Desktop:** Customize → Connectors → Add custom connector
   * **ChatGPT:** Settings → Apps & Connectors → Advanced settings → Enable Developer mode → Create app → Paste the URL → Choose OAuth
3. Authorize when the browser window opens (you only need to do this once)

The plugin uses OAuth 2.1 — no API keys or passwords needed. Your session stays active for up to 90 days.

= OAuth works but Claude/ChatGPT says "Authorization failed" =

This is usually caused by Cloudflare's "Block AI Bots" setting (enabled by default on new domains) or similar WAF rules from Sucuri, Wordfence, SiteGround, WP Engine, etc.

**What happens:** The OAuth consent screen works fine (it runs in your browser), but after the token exchange, the AI backend servers (Anthropic, OpenAI) try to reach your MCP endpoint — and the firewall blocks them as bot traffic, returning a 403 before the request ever reaches WordPress.

**How to confirm:** Check your firewall logs. You'll see the OAuth/token requests succeed but subsequent MCP requests from Anthropic or OpenAI IPs are blocked.

**Option 1 — Disable AI bot blocking:**

* **Cloudflare:** Dashboard → Security → Settings → turn off "Block AI Bots". Note: this is all-or-nothing — you cannot allow only Anthropic/OpenAI while blocking others.
* **Sucuri / Wordfence / other WAFs:** Whitelist the AI provider's IP ranges or user agents (e.g., `python-httpx` for Anthropic, `ChatGPT-User` for OpenAI).

**Option 2 — Use Application Passwords (bypasses the firewall):**

If you cannot change your firewall settings, use WordPress Application Passwords instead of OAuth. This connects directly from Claude Desktop on your machine, bypassing the AI provider's proxy entirely:

1. Go to **Users → Your Profile** in WordPress admin
2. Scroll to **Application Passwords** section
3. Enter a name (e.g., "Claude Desktop") and click **Add New Application Password**
4. Copy the generated password (shown only once)
5. In `claude_desktop_config.json`, configure the MCP server with your username and the application password as HTTP Basic Auth headers

This method works even behind strict firewalls because all requests come from your own computer.

== Screenshots ==

1. AI Copilot - Floating assistant inside the WordPress editor with quick actions
2. AI Chat Agent - Chat with AI directly from WordPress admin
3. AI Chat Agent - Settings and provider configuration
4. MCP Server - Endpoint URLs and authentication setup
5. MCP Server - Tool profiles management
6. MCP Server - WordPress and WooCommerce tools management
7. MCP Server - Plugin Integrations

== External Services ==

This plugin connects to third-party AI services to power the AI Chat Agent, AI Copilot, image generation, and video generation features. **No data is transmitted until you explicitly configure an API key and initiate a request.**

**What data is sent:** Your WordPress content (post text, metadata, product details) as included in AI prompts, and MCP tool execution results when using the MCP server with external AI clients.

**When data is sent:** Only when you have configured an API key for a provider AND actively send a message to the AI agent or Copilot, or when an external MCP client makes an authenticated request to the MCP server endpoint.

**Supported services and their policies:**

* **OpenAI** — Used for GPT models (AI Chat Agent, AI Copilot), GPT Image / DALL·E (image generation), and Sora (video generation)
  [Terms of Use](https://openai.com/policies/terms-of-use) | [Privacy Policy](https://openai.com/policies/privacy-policy)

* **Anthropic Claude** — Used for Claude AI models (AI Chat Agent, AI Copilot)
  [Terms of Service](https://www.anthropic.com/legal/consumer-terms) | [Privacy Policy](https://www.anthropic.com/legal/privacy)

* **Google Gemini** — Used for Gemini AI models (AI Chat Agent, AI Copilot), Gemini Image + Imagen 4 (image generation), and Veo 2/3 (video generation)
  [Terms of Service](https://ai.google.dev/terms) | [Privacy Policy](https://policies.google.com/privacy)

* **Google Search Console** - Used only when you connect your Google account in the SEO settings, for read-only site/search performance data.
  [Terms of Service](https://policies.google.com/terms) | [Privacy Policy](https://policies.google.com/privacy)

When using the MCP server with external AI clients (ChatGPT, Claude Desktop, LibreChat, etc.), requests arrive at your WordPress MCP endpoint. For CIMD clients, the plugin retrieves the client's public HTTPS metadata document during OAuth authorization/token processing and may cache it for up to one hour. It sends no WordPress cookies or access tokens with that metadata request. The public HTML verification tool requests a page from your own site. Tools that explicitly use other external services follow the settings and disclosures above.

When you call `wp_upload_media` with a URL or conversation file, or `wp_upload_image` with a conversation file, WordPress downloads it from the supplied HTTPS address and up to three validated redirects. Those hosts receive your server's download request, including any signed parameters in the URL, but no WordPress cookies or OAuth tokens. The file is saved in your Media Library and may become publicly accessible. Base64 uploads do not require an external download.

== Changelog ==
= 3.6.1 =
* MCP: Added standard read-only, destructive, idempotent and open-world annotations to discovered tools, with reviewed classifications for built-in tools and integrations. WordPress Abilities use their declared metadata; unknown or invalid classifications default conservatively. Authorization and capability checks are unchanged.
* Media: `wp_upload_image` now accepts ChatGPT conversation image attachments directly through `openai/fileParams`, while preserving the existing base64 input.
* Media: Added `wp_upload_media` for ChatGPT attachments, HTTPS URLs and base64, with WordPress MIME validation, bounded image resizing/conversion/compression, structured results and rollback support. New installations and upgrades do not enable this tool automatically.

= 3.6.0 =
* Identity: Recent MCP versions include the configured site URL, description and a domain/path display title without renaming the server or tools; older identity formats are preserved.
* Fix: Plugin-setting reads preserve empty strings and null values in sensitive fields while continuing to hide configured secrets and sensitive containers.
* MCP: Added 2026-07-28 stateless discovery, request metadata/header validation, result types, private cache hints and protocol-specific HTTP errors while retaining legacy 2025 clients and SSE.
* Tasks: Added the negotiated Tasks extension adapter with flat task handles, result polling, cancellation and owner isolation over the existing media worker.
* OAuth: Added RFC 9207 issuer responses, REST discovery alternatives, public-PKCE CIMD, optional approval for new clients and an Authorization-header diagnostic.
* Security: Added identity-aware rate limits with Retry-After, explicit trusted-proxy handling, and removal of Authorization fragments from authentication logs.
* Safety: Added dry-run previews and optimistic state checks for option/settings updates, plus public HTML verification after edits.
* Profiles: Added the optional Compact Site editorial profile without changing existing active profiles or enabling the new tool on upgrades.
* Diagnostics: Added opt-in, bounded local protocol statistics with 30-day retention and manual deletion.
* Developer: Added isolated PHP regressions and validation of emitted responses against official MCP and Tasks JSON schemas. Live WordPress/client interoperability remains a deployment check.

[View the complete changelog](https://plugins.svn.wordpress.org/stifli-flex-mcp/trunk/changelog.txt).
