=== StifLi Flex MCP - MCP Server with undo for ChatGPT, Claude & Gemini === 

Contributors: estebandezafra
Donate link: https://github.com/estebanstifli/stifli-flex-mcp
Tags:  mcp, chatgpt, claude, woocommerce ai, copilot
Requires at least: 5.9
Tested up to: 7.0
Stable tag: 3.4.5
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

The most secure MCP Server for WordPress with Undo, plus AI Copilot & Chat Agent. ChatGPT, Claude, Gemini, OpenRouter & Mistral.

== Description ==

**StifLi Flex MCP** is the most secure MCP Server for WordPress with built-in Undo. Connect ChatGPT, Claude Desktop, Gemini, and other MCP clients safely, roll back changes when needed, and manage your site through natural conversation without losing control.

**🛰️ Manage many WordPress MCP sites from one place (free and open source)**

If you are an agency or manage many WordPress installations, **WP MCP Hub** gives you one local central point for managing multiple WordPress MCP servers.

* **Open source and free** — no paid hub, site limits, or subscription
* **One stable AI client setup** — connect once and route to the right site
* **Local-first architecture** — credentials stay in your operating system vault

Explore WP MCP Hub:
* https://andromedanova.com/wp-mcp-hub.html
* https://github.com/estebanstifli/wp-mcp-hub

Choose the layers your site needs without loading the rest:

1. **MCP Server (always active)** — Connect ChatGPT, Claude Desktop, or any MCP client directly to your site. Includes Multimedia and Logs & Roll Back.
2. **AI Copilot (optional addon)** — A floating assistant inside the Gutenberg and Classic editors that writes, rewrites, and optimizes your content in real time.
3. **AI Chat Agent (optional addon)** — A full conversational interface to manage posts, WooCommerce, settings, and more.
4. **Automations, SEO, and Plugin Integrations (optional addons)** — Enable only the workflows and integrations used on your site.

On first activation, a setup screen lets you select the addons to load. The default is the MCP Server layer only. You can change the selection later from **StifLi Flex MCP → MCP Server → Add-ons**.

**🎬 Video: Claude to WordPress MCP Connector in 1 Minute**

https://youtu.be/AcmvwRzoOSM

**📚 Documentation**

[StifLi Flex MCP Documentation](https://andromedanova.com/stifli-flex-mcp.html)

Released in December 2025, **StifLi Flex MCP** was the first MCP plugin for WordPress and remains the most complete WordPress MCP platform for ChatGPT, Claude Desktop, and other MCP clients.
It starts with 122+ built-in MCP tools, and with supported integrations such as All Sources Images, Stifli Backup Tools, AiPatch Security Scanner, Notification for Telegram, WPCode, Code Snippets, Woody Snippets, Advanced Custom Fields, Yoast SEO, Rank Math, WPForms, Gravity Forms, Forminator, The Events Calendar, and Elementor, it can exceed 200 total tools depending on the plugins you install.

**📡 MCP Server — Connect ChatGPT, Claude Desktop, and Other MCP Clients**

StifLi Flex MCP includes a full standards-compliant MCP server for WordPress, so ChatGPT, Claude Desktop, LibreChat, and other MCP clients can connect directly to your site and use real WordPress tools through OAuth 2.1.

* **ChatGPT** — Connect through Apps & Connectors with OAuth 2.1 authentication
* **Claude Desktop** — Connect through Connectors with automatic OAuth flow
* **LibreChat and other MCP clients** — Use the same MCP endpoint and discovery flow
* **Zero shared secrets** — No custom API keys or passwords for external MCP clients
* **Standards-based** — Automatic discovery, registration, and authentication with OAuth 2.1, PKCE, RFC 9728, RFC 8414, and RFC 7591

Just copy the SSE URL from the Settings page, paste it into ChatGPT, Claude Desktop, or another MCP client, and authorize.

**✍️ AI Copilot — Your Writing Assistant Inside the Editor**

The AI Copilot lives as a floating widget right inside the WordPress post and page editor. It understands the full context of what you're editing — title, content, categories, tags, featured image, and even WooCommerce product fields — and helps you write better, faster.

* **Rewrite, expand, or optimize content** — Ask the Copilot to improve your text and it applies the changes directly into the editor
* **One-click quick actions** — "⚡ Optimize content", "🏷️ Generate tags", "📝 Write excerpt", "🖼️ Generate image" — one tap, instant results
* **Real-time editing** — The Copilot sets titles, excerpts, tags, slugs, and categories directly in the editor. No copy-pasting
* **Content block operations** — Insert, update, replace, or delete Gutenberg blocks through conversation
* **Visual feedback** — Changed fields and blocks are highlighted with a green border so you always see what the AI modified
* **Keep or Undo** — Every change shows a floating banner: keep it or undo with a single click. You stay in control
* **Image generation** — Ask the Copilot to generate an image and it sets it as the featured image or inserts it as a block, automatically
* **Works with Gutenberg and Classic Editor** — Full support for both editors
* **Context-aware** — The Copilot reads your current post content, blocks, metadata, and editor state to give relevant suggestions
* **WooCommerce-aware** — When editing a product, the Copilot sees prices, stock, SKU, attributes, and product type

Choose OpenAI (GPT-5.4), Anthropic (Claude 4.6 Opus/Sonnet), or Google (Gemini 3.1 Pro/Flash), and optionally use WordPress AI Client connectors like OpenRouter and Mistral when installed. No complex setup — just your API key or connector credentials.

**💡 What Can You Do With the Copilot?**

Here are just a few examples of what you can ask while editing a post or page:

* ✏️ "Rewrite the introduction to sound more professional and engaging"
* 📊 "Add a comparison table below the second paragraph with pros and cons"
* 🖼️ "Generate an image that illustrates the idea in paragraph four and insert it right above"
* 📝 "Write a compelling meta description and set it as the excerpt"
* 🛒 "Update the product short description to highlight free shipping and set the sale price to $19.99"

The Copilot reads your full content, understands context, and applies changes directly in the editor — no copy-pasting, no switching tabs.

**🤖 AI Chat Agent — Your WordPress AI Assistant**

The built-in AI Chat Agent gives you a powerful conversational interface to manage your entire WordPress site:

* **Talk to your site** — "Show me the last 5 orders", "Create a blog post about SEO tips", "What plugins are installed?"
* **Multi-provider** — Built-in OpenAI (GPT-5.4, GPT-5.3), Anthropic (Claude 4.6 Opus/Sonnet, Claude 4.5 Haiku), Google (Gemini 3.1 Pro, Gemini 3 Flash) + optional WordPress AI Client connectors (OpenRouter, Mistral)
* **122+ MCP tools at its disposal** — The AI agent can read posts, create content, manage WooCommerce products, check orders, inspect SEO data, update settings, and much more
* **Smart suggestions** — After each response, get contextual follow-up suggestions
* **Conversation history** — Auto-saved across sessions with multi-tab support
* **Safe by design** — Choose "Always Allow" or "Ask User" mode for tool execution confirmations
* **Advanced tuning** — Control temperature, max tokens, top_p, system prompts

**💡 What Can You Do With It?**

Here are just a few examples of what you can ask your AI agent:

* 📝 "Write a 500-word blog post about healthy eating and publish it as draft"
* 🛒 "Show me today's WooCommerce orders and their total revenue"
* 🔍 "What are the top 10 most commented posts on my site?"
* 📊 "List all products with stock below 5 units"
* 🎨 "Generate a hero image for my latest blog post about technology"

The AI agent understands context, chains multiple operations, and works with your site's real data in real time.

**🎨 AI Image & Video Generation**

Generate stunning images and videos directly from your AI agent or the dedicated Multimedia Settings page:

* **Image Generation** — "Generate a hero image for my blog post about AI" using OpenAI (GPT Image family + DALL·E 2/3) or Google Gemini (Gemini Image + Imagen 4)
* **Image Search** — "Find a real stock image for my post" with `wp_search_image` (Unsplash, Pexels, Pixabay) including attribution metadata
* **Video Generation** — "Create a 5-second product showcase video" using OpenAI Sora or Google Veo 2/3


**🧩 Code Snippet Management — Design and Develop Through Conversation**

Create, edit, activate, and manage code snippets on your WordPress site entirely through AI — no manual coding required. Compatible with the three most popular snippet plugins: **WPCode**, **Code Snippets**, and **Woody Code Snippets**.

* **Add functionality instantly** — "Add a PHP snippet that redirects users after login based on their role"
* **Custom CSS on demand** — "Create a CSS snippet that hides the sidebar on mobile devices"
* **JavaScript injection** — "Add a JS snippet that shows a sticky banner with a 10% discount code"
* **Full lifecycle management** — List, create, edit, activate, deactivate, and delete snippets from conversation
* **Safe by design** — PHP code is sanitized automatically, removing stray `<?php` tags and markdown artifacts from AI-generated output

This opens up powerful possibilities: customize your theme's appearance, add tracking scripts, inject schema markup for SEO, modify WooCommerce checkout behavior, add custom shortcodes — all through natural language. Ask your AI agent to build it, test it, and activate it, without ever touching a code editor.

**🧠 WordPress Abilities Integration** (WordPress 6.9+)

Automatically discover and import abilities registered by other plugins into your AI agent's toolkit. If a plugin supports the WordPress Abilities API, StifLi Flex MCP can detect, import, and expose it as an AI tool — zero configuration needed.

**⏰ Automation Tasks — Let AI Work While You Sleep**

Schedule AI-powered tasks to run automatically on your WordPress site:

* **Scheduled Tasks** — Create daily, weekly, or monthly automated workflows
* **Templates** — Quick-start with pre-built templates (Daily Sales Report, Trending Article, Weekly Summary)
* **Smart Scheduling** — Flexible presets from "Every hour" to "Monthly" with custom times and timezones
* **Detected Tools Mode** — AI automatically identifies which tools are needed, saving tokens significantly
* **Output Actions** — Send results via email, webhook, draft post, or custom hooks
* **Execution Logs** — Full history with token usage, duration, and detailed results

**🎯 Event Automations — Trigger AI on WordPress Events**

Run AI workflows automatically when specific events happen

**⏪ Roll Back — The Only MCP Server With Undo**

Mistakes happen. You asked ChatGPT to update your landing page and the result isn't what you expected? No problem — **roll back the change with one click** and your site is restored instantly.

StifLi Flex MCP is the **only MCP server for WordPress that tracks every change and lets you undo it**. Every modification made by any AI — whether from ChatGPT, Claude Desktop, the built-in Chat Agent, the Copilot, or automated tasks — is recorded with a full before/after snapshot.

* **One-click Undo** — Roll back any change from the Logs & Roll Back page in your admin panel
* **Redo support** — Changed your mind? Re-apply a rolled-back change just as easily
* **Session rollback** — Undo an entire AI conversation's changes at once, in the correct order
* **Full audit trail** — See exactly what was changed, when, by whom, and from which source
* **Works across everything** — Posts, pages, products, orders, options, menus, media, code snippets, and more
* **AI-accessible** — Your AI agent can also query and rollback changes through dedicated tools

💡 Real-world examples:

* 🛒 "ChatGPT updated all my product prices but used the wrong currency — roll it back!"
* 📝 "Claude rewrote my About page and I prefer the original — undo!"
* ⚙️ "An automation changed my site settings at 3 AM — I can see exactly what happened and revert it"
* 🎨 "The AI-generated image doesn't match my brand — remove it and restore the previous one"
* 🔗 "I told the AI to delete a menu item by mistake — bring it back!"

**🛡️ Security — OAuth 2.1 Built In**

StifLi Flex MCP uses **OAuth 2.1 with PKCE** — the latest industry-standard security protocol — to authenticate external AI clients. No API keys to copy, no passwords to share. Just paste the URL, authorize once, and you're connected.

* **OAuth 2.1 with PKCE (S256)** — The most modern and secure authentication standard, used by Google, Microsoft, and GitHub
* **Dynamic Client Registration (RFC 7591)** — AI clients register automatically, no manual setup needed
* **Auto-discovery (RFC 9728 + RFC 8414)** — Clients find your server's auth endpoints automatically
* **Token auto-refresh** — Sessions stay active for up to 90 days without re-authorization
* **Application Passwords fallback** — Still supported for advanced setups and legacy clients
* Per-tool capability checks linked to WordPress roles
* Profile-based tool restrictions (8 predefined profiles + custom)
* Tool execution confirmations in AI Chat Agent

**📋 Tool Profiles**

* WordPress Read Only — safe read-only access
* WordPress Full Management — complete CRUD operations
* WooCommerce Read Only — query store data
* WooCommerce Store Management — products, orders, coupons
* Complete E-commerce — all WooCommerce tools
* Complete Site — all 122+ tools enabled
* Safe Mode — non-sensitive reads only
* Development/Debug — diagnostic tools

**🌐 Supported AI Platforms**

StifLi Flex MCP integrates with:

**Built-in AI Chat Agent + WordPress AI Client connectors:**
* OpenAI — GPT-5.4, GPT-5.3, GPT-5.4 Mini
* Anthropic Claude — Opus, Sonnet, Haiku
* Google Gemini — Pro, Flash, Flash-Lite
* OpenRouter and Mistral — via WordPress AI Client connectors (when installed)

**MCP Server (External Clients via OAuth 2.1):**
* Claude Desktop, ChatGPT, LibreChat, Cursor, Cline, Roo Code, Windsurf, Claude Code

**Cloud & Local Providers (via MCP clients):**
* Groq, Azure OpenAI, AWS Bedrock
* Ollama, LM Studio, self-hosted solutions


**📐 MCP Spec Compliance**

StifLi Flex MCP implements the [Model Context Protocol (MCP) 2025-11-25 specification](https://modelcontextprotocol.io/specification/2025-11-25/) for lifecycle and tool operations over JSON-RPC 2.0, while keeping legacy SSE compatibility for older MCP clients.

== Installation ==

= Quick Start (MCP Server) =

1. Upload the `stifli-flex-mcp` folder to `/wp-content/plugins/` or install from the WordPress plugin directory
2. Activate the plugin
3. Choose the addons you want on the first-activation screen. Leave them unchecked for the lightweight MCP Server-only setup.
4. Continue to **StifLi Flex MCP → MCP Server**
5. Copy the SSE URL and connect your MCP client through OAuth 2.1

Multimedia tools and Logs & Roll Back are included in the MCP Server layer and are always available. Addons can be changed later from **MCP Server → Add-ons**; disabled addons do not load their PHP classes, menus, hooks, or background jobs.

= Quick Start (AI Copilot) =

1. Enable **AI Copilot** on the first-activation screen or from **MCP Server → Add-ons**
2. Go to **StifLi Flex MCP → AI Copilot** and make sure it’s enabled
3. Go to **StifLi Flex MCP → AI Chat Agent → Settings** and enter your API key
4. Open any post or page in the editor — the Copilot widget appears automatically
5. Start writing with AI!

= Quick Start (AI Chat Agent) =

1. Enable **AI Chat Agent** on the first-activation screen or from **MCP Server → Add-ons**
2. Go to **StifLi Flex MCP → AI Chat Agent**
3. Open the **Settings** tab and select your AI provider (OpenAI, Claude, Gemini, or installed WordPress AI Client connectors like OpenRouter/Mistral)
4. Enter your API key
5. Start chatting!

That's it — no external tools, no complex configuration. Your AI agent is ready.

= Connect External MCP Clients =

To connect external AI clients (ChatGPT, Claude Desktop, LibreChat):

1. Go to **StifLi Flex MCP → MCP Server**
2. Copy the SSE URL shown on the Settings page
3. Paste it in your AI client:
   * **Claude Desktop:** Customize → Connectors → Add custom connector → Paste the URL
   * **ChatGPT:** Settings → Apps & Connectors → Advanced settings → Enable Developer mode → Create app → Paste the URL → Choose OAuth
4. A browser window will open — log in to WordPress and click "Authorize"
5. Done! Your AI client can now manage your WordPress site

No API keys, no passwords — OAuth 2.1 handles everything securely and automatically.

== Frequently Asked Questions ==

= What is the AI Copilot? =

The AI Copilot is a floating assistant that appears inside the WordPress editor (Gutenberg or Classic). It reads the context of what you’re editing and helps you write, rewrite, optimize, generate tags, create excerpts, and even generate images — all without leaving the editor. Every change can be undone with one click.

= How is the Copilot different from the Chat Agent? =

The **Copilot** lives inside the post/page editor and is focused on writing and content editing. It works directly with the editor fields (title, content blocks, excerpt, tags, etc.).

The **Chat Agent** is a standalone admin page where you can manage your entire WordPress site through conversation — create posts, manage WooCommerce orders, check settings, install plugins, and more.

Both use the same AI provider and API key.

= How do I set up the AI Chat Agent? =

1. Go to StifLi Flex MCP → AI Chat Agent → Settings
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

No worries — StifLi Flex MCP is the only MCP server with a built-in **Roll Back** system. Every change made by any AI (ChatGPT, Claude, the Chat Agent, Copilot, or automations) is tracked with a full before/after snapshot. Go to **Logs & Roll Back** in your admin panel and undo any change with one click. You can even roll back an entire session at once.

= Can the AI generate images? =

Yes! The `wp_generate_image` tool supports multiple providers:

* **OpenAI** — gpt-image-1 (default), gpt-image-1.5, gpt-image-2, gpt-image-1-mini, DALL·E 3, DALL·E 2
* **Google Gemini** — gemini-2.5-flash-image (default), gemini-3.1-flash-image-preview, gemini-3-pro-image-preview, Imagen 4

Just ask your AI agent "Generate an image of..." or configure defaults in **StifLi Flex MCP → Multimedia Settings → Images**.

= Can the AI search stock images too? =

Yes! The optional `wp_search_image` tool can search Unsplash, Pexels, and Pixabay and return one image with rich attribution metadata.

The tool response includes both text JSON and structured output with fields such as:

* `url`, `thumbnail_url`, `caption`, `alt_text`
* `author`, `author_url`, `source_url`
* image dimensions, license/metadata fields, and provider-specific fields like Unsplash `download_location`

Configure everything in **StifLi Flex MCP → Multimedia Settings → Search Image**:

* Global enable/disable toggle for `wp_search_image`
* Per-provider enable + API keys (Unsplash, Pexels, Pixabay)
* Preferred Image Bank (specific provider or random)
* Image Selection mode: `most_relevant`, `random_top10`, `random_top20`
* Extra parameters: orientation, safe search, Pixabay language, Pexels locale, and timeout

= Can the AI generate videos? =

Yes! The `wp_generate_video` tool supports:

* **OpenAI Sora** — Text-to-video and image-to-video generation
* **Google Veo** — Veo 2 and Veo 3 models

Video generation runs asynchronously in the background. Configure providers and API keys in **StifLi Flex MCP → Multimedia Settings → Videos**.

= Where do I configure API keys for image/video generation? =

Go to **StifLi Flex MCP → Multimedia Settings**. API keys are shared between the Images and Videos tabs — enter your OpenAI or Gemini key once and it works for both.

= What are WordPress Abilities? =

WordPress 6.9 introduced the Abilities API, letting plugins register standardized capabilities. If you have plugins that support Abilities, StifLi Flex MCP can auto-discover and import them from **MCP Server → Abilities** tab.

= How do I connect ChatGPT or Claude Desktop? =

It takes less than a minute:

1. Go to **StifLi Flex MCP → MCP Server** and copy the SSE URL
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

When using the MCP server with external AI clients (ChatGPT, Claude Desktop, LibreChat, etc.), API requests are made by the AI client's backend servers to your WordPress MCP endpoint. The plugin itself does not send data to third parties in this scenario — the external MCP client initiates all communication.

== Changelog ==
= 3.4.5 =
* New: Added WP MCP Hub links and a free, open-source multi-site management message to the plugin documentation.
* New: Added a dismissible WP MCP Hub notice and a persistent footer message across StifLi Flex MCP admin pages.

= 3.4.4 =
* New: Added `elementor_update_widget` to update Elementor widget settings by `element_id` with undo compatibility.
* New: Added `elementor_export_template` to export Elementor template/page data as JSON for portability workflows.
* New: Added `wc_bulk_assign_product_categories` and `wc_bulk_delete_products` for WooCommerce bulk product operations.
* Improvement: Expanded `wc_batch_update_products` to support `create`, `update`, and `delete` operations (plus legacy `updates` compatibility).
* Improvement: Added compatibility aliases for automation scripts (`wc_batch_update_products.update[].id` as alias of `product_id`, and `elementor_export_template.post_id` as alias of `template_id`).
* Improvement: Expanded `mcp_ping` with `connection_context` and `builder_versions` to improve connector diagnostics.
* Improvement: `mcp_ping.connection_context.session_id` now falls back to the active ChangeTracker session when transport context is empty, improving `mcp_rollback_session` operability.
* Improvement: Added `session_id` filter to `mcp_get_changelog` schema and runtime filtering, so rollback targets can be discovered from tool outputs.
* Improvement: Added enriched `sflmcp_tool_context` hook payload while preserving legacy hook compatibility.
* Improvement: Hardened `wp_update_post` and `wp_update_page` responses with requested-vs-saved checks, including dropped-field reporting.
* Improvement: Added `.mcpb` one-click bundle download flow from OAuth settings to simplify Claude Desktop connector setup.
* Improvement: Added `README.md` optimized for GitHub rendering, including linked video thumbnail preview.

= 3.4.3 =
* Fix: WordPress 6.9+ Abilities compatibility for schema-less abilities. When no arguments are provided and the ability has no effective input schema, MCP now calls `execute(null)` instead of `execute([])`.
* Fix: Plugin Integrations tab CSS now uses the same base visual style as the Tools tabs, restoring card/table layout consistency in `admin.php?page=sflmcp-server&tab=plugins`.

= 3.4.2 =
* New: Added `wp_get_cron_schedule` to inspect scheduled WP-Cron events with next-run timestamps, overdue status, and optional hook filtering.
* New: Added `wp_get_error_log_tail` to read recent lines from `wp_debug` or plugin logs with optional keyword filtering.
* Improvement: Added proactive OAuth notices for Plain permalink mode and stale static `.well-known` metadata files that can break connector discovery.

= 3.4.1 =
* Improvement: Enhanced post and page management tools.
* Improvement: Improved search results and WooCommerce order listings.
* Improvement: Improved WordPress taxonomy and post metadata tools.
* Improvement: Added integration refinements for MCP tool execution.

= 3.4.0 =
* New: Added modular installation with a first-activation selector for AI Chat Agent, AI Copilot, Automations, SEO, and Plugin Integrations.
* Improvement: New installations load only MCP Server by default, including Multimedia and Logs & Roll Back; disabled addons no longer register their PHP classes, menus, hooks, cron workers, or automation tables.
* Improvement: Added an Add-ons tab under MCP Server settings so administrators can change layers at any time while preserving all modules on existing installations until explicitly changed.

= 3.3.13 =
* Security: Retired legacy Custom Tools runtime execution to prevent lower-privileged users from invoking administrator-defined custom workflows through tool execution endpoints.
* Security: Existing legacy Custom Tools are disabled automatically during upgrade, and `custom_*` tool calls now return a deprecation error instead of dispatching HTTP requests or WordPress action hooks.
* Improvement: Removed Custom Tools from MCP tool discovery and admin surfaces. Use WordPress Abilities for custom MCP extensions with explicit permission callbacks.

= 3.3.12 =
* Security: Hardened `elementor_add_widget` raw settings path by requiring `unfiltered_html` before accepting caller-supplied raw `settings`.
* Security: Tightened non-curated widget validation to fail closed when Elementor widget registry is unavailable, preventing permissive fallback acceptance.
* Improvement: Updated tool schema/docs to explicitly state the raw settings capability requirement.

= 3.3.11 =
* New: Added `elementor_add_widget`, a structural Elementor write tool for inserting widgets or containers into existing Elementor pages.
* Improvement: Supports raw Elementor settings for registered widget slugs and curated flat parameters for container, heading, text-editor, button, image, image-box, icon-box, icon-list, video, divider, and spacer widgets.
* Security: Validates `edit_post` on the target page, validates parent containers/sections/columns when `parent_id` is supplied, and rejects unknown non-curated widget types unless Elementor's registry is unavailable.

= 3.3.10 =
* Security: Added object-level `edit_post` and `delete_post` checks to post, page, and media MCP tools before mutating or deleting specific content.
* Improvement: Single-object post, media, post meta, and SEO tools now accept common ID aliases (`ID`, `id`, `post_id`, and `attachment_id` where relevant) for better MCP client compatibility.
* Improvement: Post, page, media, and term update tools now preserve existing text fields when optional text arguments are sent as empty strings.
* Improvement: Rank Math and Yoast SEO tools now read and update the WordPress slug, returning the slug actually saved by WordPress.
* Cleanup: Expanded uninstall cleanup to remove OAuth, automation, event, ability, changelog, and dynamic plugin options across single-site and multisite installs.

= 3.3.9 =
* Security: Added explicit WordPress capability enforcement for sensitive WooCommerce read tools, including orders, order notes, refunds, reports, taxes, shipping, payment gateways, system status, settings, webhooks, and coupons.
* Improvement: Enriched ACF MCP responses with field group metadata, field definitions, labels, types, return formats, nested sub-fields, structuredContent, and normalized object values for posts, users, terms, dates, and plugin objects.

= 3.3.8 =
* New: Added `wp_css_get_global` tool to read active theme Additional CSS (Customizer) with hash and optional metadata/statistics output.
* New: Added `wp_css_set_global` and `wp_css_set_scoped` tools to manage global and scoped CSS with validation, optimistic concurrency (`expected_hash`), and dry-run mode (`validate_only`).
* Improvement: Integrated `wp_css_set_global` and `wp_css_set_scoped` with ChangeTracker so they appear in Logs (`sflmcp-logs`) with rollback/redo support.

= 3.3.7 =
* New: Added optional `wp_search_image` module (`class-search-image.php`) with lazy loading from the model only when enabled in `sflmcp_tools`, protected by `upload_files` capability.
* New: `wp_search_image` now returns MCP-friendly output in both text JSON and `structuredContent`, including URL, thumbnail URL, caption, alt text, author, author URL, source URL, dimensions, license/metadata, and provider-specific fields such as Unsplash `download_location`.
* New: Added a dedicated **Search Image** tab in Multimedia Settings with tool toggle, provider toggles + API keys (Unsplash/Pexels/Pixabay), preferred bank, image selection mode (`most_relevant`, `random_top10`, `random_top20`), and extra search parameters (orientation, safe search, language/locale, timeout).

= 3.3.6 =
* New: Added SEO optimization tools for GSC-backed post context, title/meta suggestions, and safe Yoast/Rank Math metadata updates with rollback support.
* New: Added MCP resources for site info, post types, recent posts, and SEO summary through resources/list and resources/read.
* Improvement: SEO and Google Search Console modules now load lazily only when enabled, connected, or opened in the SEO admin page.

= 3.3.5 =
* New: Added Google Search Console support under a new SEO admin page, with Google OAuth connection, encrypted tokens, connection testing, cache controls, tool toggles, and 5 read-only SEO data tools.
* Improvement: Google Search Console performance queries now return compact summaries with capped row output to prevent excessive MCP token usage.
* New: Added Elementor compatibility as a plugin integration with 7 dedicated tools for cloning pages, replacing text/images/links, reading page outlines, listing local templates, and importing templates.
* Improvement: Various reliability and compatibility improvements across WordPress content handling, WooCommerce order tools, OAuth discovery, and plugin integrations.

= 3.3.4 =
* Improvement: Various improvements and content updates in the plugin documentation and onboarding resources.

= 3.3.3 =
* Improvement: Upgraded the Abilities admin table with sortable columns, row selection, and bulk actions (enable, disable, remove).
* Improvement: Upgraded Discover Abilities with category filtering and bulk import actions for selected or visible abilities.
* Improvement: Added a dedicated bulk abilities backend action and reused shared import/category normalization logic.
* Fix: Moved the OAuth global reset action to the visible Connected Clients area in MCP Server Settings and removed duplicate placement.
* Fix: Hardened `wp_update_nav_menu_item` updates with a safer merge flow that preserves existing values unless explicitly changed.

= 3.3.2 =
* Fixed: `wp_create_post` and `wp_update_post` now correctly apply `post_category` and `tax_input` (including `post_tag`) when creating or updating posts.

= 3.3.1 =
* Improvement: Enhanced "Alternative: Application Passwords" with in-page generation from MCP Server Settings (no navigation to profile required).

= 3.3.0 =
* New: Compatibility with The Events Calendar plugin, including integrated event tools for listing, reading, creating/updating, and trashing events and related entities.

= 3.2.9 =
* Improvement: Updated image generation model catalog in Multimedia Settings. Added new OpenAI and Gemini image models while keeping previous models available for user selection.
* Improvement: Set default image models to cost-effective options (`gpt-image-1` and `gemini-2.5-flash-image`) and refreshed pricing guidance in the UI.
* New tools: `wc_get_variation`, `wc_batch_update_variations`, `wc_get_product_attributes`, `wc_get_attribute_terms`, `wc_create_product_attribute`, `wc_set_product_attributes`, `wc_get_coupon`, `wc_get_coupon_count`, `wc_empty_coupon_trash`.
* Tool improvements: `wp_get_taxonomies` (slug/name/label output), `wp_get_term_meta` (structured payload with secret redaction), `wc_get_product_variations` (normalized variation rows), `wc_update_product_variation` (ownership validation), `wc_delete_product_variation` (ownership validation), `wc_get_coupons` (status filtering, including trash), `wc_delete_coupon` (clear trash vs permanent outcome), `wc_get_coupon_count` (status-based counting, including trash).

= 3.2.8 =
* Improvement: Official compatibility update for WordPress 7.0 with WordPress AI Client integration in AI Chat Agent.
* Improvement: Improved compatibility with external AI Client connectors such as OpenRouter and Mistral (plus any installed AI Client provider).

= 3.2.7 =
* Improvement: Updated MCP protocol reference and compatibility to the 2025-11-25 specification.
* Improvement: Improved `wp_generate_image` reliability with async task handling plus safer media persistence/post-processing.
* Improvement: Improved `wp_generate_video` reliability with async task handling, atomic file save, and background metadata processing.

= 3.2.6 =
* New: Expanded `mcp_ping` with optional diagnostics (`diagnostics`, `timeout_sec`) to surface site URL, REST endpoint, HTTPS state, DNS resolution, and lightweight reachability checks without forcing remote calls by default.
* New: Upgraded `wp_get_posts`, `wp_get_post`, `wp_get_comments`, `wp_get_users`, `search`, `wc_get_products`, and `wc_get_orders` with richer optional outputs and standardized `include_pagination` metadata wrappers.
* New: Added opt-in enrichment flags for common read tools, including author, featured media, taxonomy context, avatar/registration data, product images/categories/attributes, and order item or totals summaries.
* New: Improved `search` and `fetch` with broader filters, query-param support, custom request/response headers, and targeted remote inspection controls (`head_only`, `include_headers`, `extract_text`, `max_bytes`, `timeout_sec`).

= 3.2.5 =
* New: The AI Chat Agent token usage panel now shows three separate bars for billable input tokens, cached tokens, and output tokens.
* Improvement: Normalized token accounting across OpenAI, Claude, and Gemini providers so the three bars reflect provider-specific cache semantics more truthfully.
* New: Upgraded `wp_get_site_health` into a richer site audit tool with selectable depth levels (`0` basic, `1` medium, `2` deep) to balance diagnostic detail and timeout risk.

= 3.2.4 =
* New: Added `wp_get_plugin_settings` to inspect plugin-related `wp_options` by `plugin_slug`/prefixes with prepared SQL + limit controls and strict recursive redaction of secrets/tokens/passwords.
* New: Generalized term tools - added `wp_update_term` and extended `wp_create_term`/`wp_delete_term` with optional slug/parent/description plus per-taxonomy capability checks (existing `wp_*_category` and `wp_*_tag` tools kept as aliases).

= 3.2.3 =
* New: Generalized term tools — added `wp_update_term` and extended `wp_create_term`/`wp_delete_term` with optional slug/parent/description plus per-taxonomy capability checks (existing `wp_*_category` and `wp_*_tag` tools kept as aliases).
* New: Term meta tools `wp_get_term_meta` (with secret redaction), `wp_update_term_meta`, `wp_delete_term_meta`.
* New: `wp_reorder_menu_items` tool to batch-update `menu_order`/`parent` for navigation menu items in one call (with one-click rollback).
* Security: `wp_create_post`/`wp_update_post` now validate `post_type` exists and is public/show_ui, enforce post-type-aware capabilities, and require `edit_others_posts` cap when assigning a different `post_author`.
* Infrastructure: New DB migration seeds the new tools into existing installs and attaches them to the "WordPress Full Management" profile.

= 3.2.2 =
* Security: Added centralized recursive secret redaction for MCP outputs.
* Security: Applied redaction to sensitive reads (`wp_get_option`, `wp_get_settings`, `wp_get_post_meta`, `wp_get_user_meta`) and masked email/IP fields in `wp_get_comments`.
* Security: Hardened `wp_update_option` and `wp_update_settings` with hard denylist, sensitive-pattern blocking, and optional allowlist via `sflmcp_writable_options`.
* Security: Removed `wp_delete_option` tool (destructive operation without reliable undo), including migration cleanup for existing installs.
* Security: Hardened `wp_upload_image_from_url` with SSRF protection (private/reserved IP blocking), HTTPS requirement, 20MB limit (filterable), MIME allowlist, and image validation.
* New: Added `wp_set_featured_image` tool and support for `featured_media` in `wp_create_post` and `wp_update_post`.
* OAuth: Dynamic client registration now returns HTTP 500 on DB insert failures, logs internal DB errors, and avoids exposing SQL internals.
* Infrastructure: Added `sflmcp_db_version` upgrade flow for versioned DB migrations.

= 3.2.1 =
* fix bug

= 3.2.0 =
* **🗂️ Tools UI overhaul** — Tools are now organized into collapsible category groups with expand/collapse controls, read/write mode badges, and token count per category for easier management
* **🧩 New "Plugins" tab** — Dedicated integrations hub with 12 pre-loaded plugin integrations ready to connect:
  * **All Sources Images** — Find stock images and generate AI images; set featured or inline images in posts *(Recommended)*
  * **AiPatch Security Scanner** — AI-powered security auditing and vulnerability scanning *(Recommended)*
  * **Notification for Telegram** — Send Telegram notifications from MCP tools
  * **WPCode** — Manage code snippets (insert headers/footers) via AI
  * **Code Snippets** — Create, activate, and manage PHP/CSS/JS snippets via AI
  * **Woody Snippets** — Alternative snippet provider with full snippet_* tool support
  * **Advanced Custom Fields (ACF)** — Read and update ACF fields and field groups
  * **Yoast SEO** — Read and update Yoast metadata; trigger reindexing
  * **Rank Math** — Manage Rank Math SEO metadata and head output
  * **WPForms** — List forms and read form entries
  * **Gravity Forms** — List forms, read entries, and update submissions
  * **Forminator** — List forms and read form entries


= 3.1.5 =
* Fixed: OAuth re-authorization crash when reconnecting previously authorized clients (ChatGPT, Claude Desktop) 


= 3.1.4 =
* **🌐 WebMCP — Browser AI (Beta)** — Use Chrome's built-in Gemini Nano to edit posts directly, no API key needed!
* Note: Beta feature — Gemini Nano is a compact on-device model with limited reasoning; works best for simple editing tasks

= 3.1.3 =
* Fixed: Minor bug fixes and stability improvements


= 3.1.2 =
* **⏪ Roll Back — Undo Any AI Change Instantly!**
* New: Full change tracking — every modification by ChatGPT, Claude, AI Chat Agent, Copilot, or automations is recorded
* New: One-click rollback — undo any change from the Logs & Roll Back admin page
* New: Redo support — re-apply a rolled-back change if you change your mind
* New: Session rollback — undo all changes from an entire AI conversation at once (LIFO order)
* New: Before/after snapshots — see exactly what changed with full state comparison
* New: Source tracking — every change shows where it came from (MCP Connection, Chat Agent, Copilot, Automation, Event, WP Admin)
* New: 5 MCP tools — `mcp_get_changelog`, `mcp_get_change_detail`, `mcp_rollback_change`, `mcp_redo_change`, `mcp_rollback_session`
* New: Changelog admin page with filters, search, detail modal, CSV export, and automatic purge
* New: Works across 60+ mutating tools — posts, pages, products, orders, options, menus, media, snippets, and more
* New: File backup & restore — even deleted media files can be recovered
* Improved: The only MCP server for WordPress with built-in undo capabilities

= 3.1.1 =
* Compatibility: Tested with WordPress 7.0 RC

= 3.1.0 =
* **🔐 OAuth 2.1 Authentication** — Connect ChatGPT, Claude Desktop, and any MCP client with one click!
* New: Full OAuth 2.1 implementation with PKCE (S256) — the most secure authentication standard
* New: Dynamic Client Registration (RFC 7591) — AI clients register automatically, zero manual setup
* New: Auto-discovery via RFC 9728 (Protected Resource Metadata) and RFC 8414 (Authorization Server Metadata)
* New: Automatic token refresh — sessions stay active for up to 90 days without re-authorization
* New: Auto-approve for returning clients — authorize once, connect instantly on future sessions
* New: Simplified Settings page — just copy the URL and paste it in your AI client
* New: "View More Details" panel with connected clients, active tokens, and troubleshooting
* New: One-click client deletion and token revocation from the admin panel
* Improved: No more API keys or passwords needed for external AI clients
* Improved: Full compatibility with Claude Desktop Connectors and ChatGPT Apps & Connectors
* Improved: Standards-compliant OpenID Connect discovery fallback for maximum client compatibility
* Security: PKCE S256 challenge on every authorization flow
* Security: Short-lived authorization codes (10 min) with single-use enforcement
* Security: Access tokens expire in 24 hours, refresh tokens in 90 days
* Security: Application Passwords still supported as fallback for advanced setups

= 3.0.3 =
* Fixed: MCP Server connection with Claude Desktop and other SSE-based clients now works correctly
* Fixed: Scheduled automation tasks running more frequently than configured and producing intermittent errors

= 3.0.2 =
* **🧩 Code Snippets Management** — 7 new MCP tools for managing code snippets directly from AI agents!
* New: snippet_list, snippet_get, snippet_create, snippet_update, snippet_delete, snippet_activate, snippet_deactivate tools
* New: Multi-provider support — compatible with WPCode, Code Snippets (v2/v3), and Woody Code Snippets plugins
* New: Automatic provider detection — seamlessly works with whichever snippet plugin is installed
* New: LLM-friendly input normalization — maps common AI output variants for code_type, location, and scope parameters
* New: PHP code sanitization — automatically strips `<?php`, `?>` tags and markdown code fences from AI-generated code
* New: Code Snippets v3.x full namespace support — resolves namespaced functions and classes automatically
* New: Woody Code Snippets scope mapping — translates locations to Woody's dual scope/location system
* Security: Rate limiting (30 requests/minute per IP) on MCP endpoints to prevent abuse
* Security: SSRF protection on fetch tool — blocks requests to private/reserved IP ranges (127.x, 10.x, 172.16.x, 192.168.x)
* Improved: Snippet tools added to WordPress Full Management profile (auto-migrated for existing installs)

= 3.0.1 =
* **✍️ AI Copilot — New floating writing assistant for the WordPress editor!**
* New: AI Copilot widget available inside the Gutenberg and Classic editors
* New: Quick action chips — Optimize content, Generate tags, Write excerpt, Generate image
* New: Direct editing — the Copilot sets titles, excerpts, tags, categories, and slugs in the editor
* New: Block operations — insert, update, replace, and delete Gutenberg blocks through conversation
* New: Visual feedback — green highlight on changed fields and blocks with auto-dismiss
* New: Keep/Undo banner on every AI change for full user control
* New: Image generation workflow — generate an image and set it as featured or insert as block
* New: AI Copilot settings page with enable/disable toggle and tools mode selection
* New: Full context awareness — reads post content, blocks, metadata, and WooCommerce product fields


= 2.2.2 =
* **📊 Token Usage Bars** — Real-time speedometer-style token bars in the AI Chat Agent showing input, output, and cached tokens per interaction.

= 2.2.1 =
* **🤖 Updated AI Models for All Providers** — Refreshed the full model catalog across OpenAI, Anthropic (Claude), and Google Gemini.
* New: OpenAI GPT-5.4 series — GPT-5.4 Pro, GPT-5.4, GPT-5.4 Mini, GPT-5.4 Nano (1M context, Computer Use support)
* New: OpenAI GPT-5.3 and GPT-5.3 Mini added as stable production models
* New: Anthropic Claude Sonnet 4.6 and Claude Opus 4.6 (1M context, 128K output, Extended Thinking)
* New: Anthropic Claude Sonnet 4.5, Claude Opus 4.5, and Claude Haiku 4.5
* New: Google Gemini 3.1 Pro, Gemini 3 Flash, and Gemini 3.1 Flash-Lite (latest generation)
* Updated: Google Gemini 2.5 Pro, Flash, and Flash-Lite remain as stable production models
* Updated: Default models changed — GPT-5.4 (OpenAI), Claude Sonnet 4.6 (Claude), Gemini 3 Flash (Gemini)
* Removed: Deprecated models — GPT-5 Nano, Gemini 2.0 Flash/Flash-Lite, older Claude 3.x aliases

= 2.2.0 =
* **🆕 AI Image Generation** — Generate images directly from your AI agent using `wp_generate_image`!
* **🆕 AI Video Generation** — Generate videos with `wp_generate_video` using cutting-edge AI models!
* New: wp_generate_image tool with multi-provider support (OpenAI gpt-image-1, DALL·E 2/3, Google Gemini Imagen 4)
* New: wp_generate_video tool with multi-provider support (OpenAI Sora, Google Veo 2/3)
* New: Multimedia Settings admin page with dedicated Images and Videos tabs
* New: Post-processing options — auto-save generated media to Media Library, auto-insert into posts
* New: Configurable default providers, models, image sizes, and quality settings


= 2.1.0 =
* **🆕 Automation Tasks** — Schedule AI tasks to run automatically on a recurring basis!
* **🆕 Event Automations** — Trigger AI workflows when WordPress events occur (new post, new user, etc.)
* New: Automation Tasks admin with create, edit, duplicate, delete, and run-now functionality
* New: 4 schedule presets (hourly, daily, weekly, monthly) with custom time and timezone support
* New: Pre-built automation templates (Daily Sales Report, Trending Article, Weekly Summary, and more)
* New: "Detected Tools" mode — AI identifies required tools during test, saves tokens significantly
* New: Output actions — Email, Webhook, Draft Post, or Custom Hook
* New: Execution Logs tab with full history, token usage, and detailed results
* New: Event Automations with WordPress triggers (post published, user registered, comment posted)
* New: Conditional logic for event triggers (post type, status, category filters)
* New: Dynamic placeholders in prompts (`{{post.title}}`, `{{user.email}}`, etc.)
* New: Rate limiting per automation to prevent runaway executions
* New: Test mode for event automations — preview AI response with real trigger data
* New: Tools count display in AI Chat Agent header with quick configure link
* Improved: Cron tasks now execute with proper user permissions (task creator or admin fallback)
* Improved: Complete log entry format fixes for database consistency
* Improved: Database migration for automation logs table columns
* Technical: New tables `wp_sflmcp_automation_tasks`, `wp_sflmcp_automation_logs`, `wp_sflmcp_event_automations`, `wp_sflmcp_event_logs`, `wp_sflmcp_event_triggers`

= 2.0.3 =
* ** Encrypted API Keys** - API keys are now stored encrypted (AES-256-CBC) in the database for improved security
* ** Prompt Caching (Claude)** - Enabled Anthropic prompt caching on system prompt and tools, reducing token usage and latency on repeated requests
* ** Provider Usage Logging** - Real-time logging of input/output/cached tokens for Claude, OpenAI, and Gemini
* ** Rate Limit Awareness** - Captures and logs rate limit headers from all three providers for better diagnostics on 429 errors
* New: Conversation history trimming with configurable "Max Tool Cycles in History" setting to control payload size
* New: Smart trim algorithm with safe cut points — never orphans tool_result references
* New: API key visibility toggle (eye icon) in chat settings
* New: Token estimation utilities (`estimateTokensFromString`, `estimateTokensFromJson`)
* Improved: Auto-save on all chat settings (removed manual "Save Settings" button)
* Improved: Compact request logging — summaries instead of full body dumps, reducing log noise
* Improved: HTTP request layer now returns headers and status code alongside body (`make_request_with_meta`)
* Improved: JSON encoding with `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE` for cleaner payloads

= 2.0.2 =
* **🆕 WordPress Abilities Integration** (WordPress 6.9+) - Auto-discover and import abilities from other plugins!
* New: Abilities tab in admin (appears only on WordPress 6.9+)
* New: Discover button to scan all registered abilities from themes/plugins
* New: Import, enable/disable, and delete individual abilities
* New: Abilities exposed as MCP tools (ability_* prefix) for AI agents
* New: Database table wp_sflmcp_abilities for persistent ability storage
* Improved: Plugin description updated to reflect 117+ tools
* Improved: Admin menu reordered — AI Chat Agent first, MCP Server second  
* Improved: Renamed "AI Chat" to "AI Chat Agent" across the UI
* Technical: Uses wp_get_abilities(), wp_get_ability(), $ability->execute() APIs

= 2.0.1 =
* **🆕 Built-in AI Chat Client** - Chat with AI directly from your WordPress admin panel!
* New: Multi-provider support - OpenAI, Claude (Anthropic), and Google Gemini
* New: Support for latest models including GPT-4.5, Claude 4 Opus/Sonnet, Gemini 2.5 Pro/Flash
* New: Smart suggestion chips that appear after AI responses
* New: Conversation history auto-saved per user (7-day retention)
* New: Stop button to cancel AI responses mid-generation
* New: Tool permission modes - "Always Allow" or "Ask User" for confirmations
* New: Advanced settings tab with temperature, max tokens, top_p, frequency/presence penalty
* New: Customizable system prompt for AI behavior
* New: Tool display options (Full details, Compact, or Hidden)
* New: Multilingual suggestions - AI responds in the same language you use
* Improved: Sequential tool execution for better reliability across all providers
* Improved: Claude 4.5 model compatibility (temperature/top_p handling)
* Improved: Gemini API message format conversion
* Fixed: Claude multiple tool_use error handling
* Fixed: Gemini "content" vs "parts" API format issue

= 1.0.5 =
* **New: Custom Tools** - Turn any WordPress plugin into an AI tool! Copy-paste examples included, no coding expertise required.
* New: Custom Tools management tab with code editor, enable/disable toggle, and built-in examples
* New: Pre-built Custom Tool examples (WooCommerce product lookup, CF7 forms, Yoast SEO, WP Super Cache)
* New: Custom Tools support input schemas for structured AI interactions
* Improved: All admin styles externalized for WordPress.org compliance
* Fix: Resolved object persistence issue in PHP 8.1+ causing 404 errors on API endpoints
* Fix: Deferred WooCommerce detection to ensure tools load correctly regardless of plugin load order
* Fix: WooCommerce module dispatch now correctly handles tool routing

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
