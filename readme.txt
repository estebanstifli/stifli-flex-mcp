=== StifLi Flex MCP - AI Copilot, Chat Agent and MCP Server === 

Contributors: estebandezafra
Donate link: https://github.com/estebanstifli/stifli-flex-mcp
Tags: ai copilot, mcp, chatgpt, ai writing, woocommerce ai
Requires at least: 5.8
Tested up to: 6.9
Stable tag: 3.1.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

AI Copilot for the WordPress editor, AI Chat Agent for full site management & MCP server for external AI clients. OpenAI, Claude & Gemini.

== Description ==

**StifLi Flex MCP** brings the power of AI directly into your WordPress workflow. Write faster, edit smarter, and manage your entire site through natural conversation — without leaving the editor.

Three powerful tools in one plugin:

1. **AI Copilot** — A floating assistant inside the Gutenberg and Classic editors that writes, rewrites, and optimizes your content in real time
2. **AI Chat Agent** — A full conversational interface to manage posts, WooCommerce, settings, and more
3. **MCP Server** — Connect ChatGPT, Claude Desktop, or any MCP client directly to your site

Choose between OpenAI (GPT-5.4), Anthropic (Claude 4.6 Opus/Sonnet), or Google (Gemini 3.1 Pro/Flash). No external tools, no complex setup — just your API key.

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

**💡 What Can You Do With the Copilot?**

Here are just a few examples of what you can ask while editing a post or page:

* ✏️ "Rewrite the introduction to sound more professional and engaging"
* 📊 "Add a comparison table below the second paragraph with pros and cons"
* 🌍 "Translate the third paragraph into French"
* 🔤 "Bold the most important keywords for SEO throughout the article"
* 🖼️ "Generate an image that illustrates the idea in paragraph four and insert it right above"
* 📝 "Write a compelling meta description and set it as the excerpt"
* 🏷️ "Suggest 5 relevant tags based on the content and add them"
* 📐 "Split this long paragraph into three shorter ones with subheadings"
* 🔗 "Add a call-to-action block at the end with a link to the pricing page"
* 💬 "Turn the bullet list into a FAQ block with questions and answers"
* 🎨 "Add a custom CSS class to the hero image block for full-width display"
* 🛒 "Update the product short description to highlight free shipping and set the sale price to $19.99"

The Copilot reads your full content, understands context, and applies changes directly in the editor — no copy-pasting, no switching tabs.

**🤖 AI Chat Agent — Your WordPress AI Assistant**

The built-in AI Chat Agent gives you a powerful conversational interface to manage your entire WordPress site:

* **Talk to your site** — "Show me the last 5 orders", "Create a blog post about SEO tips", "What plugins are installed?"
* **Multi-provider** — Choose between OpenAI (GPT-5.4, GPT-5.3), Anthropic (Claude 4.6 Opus/Sonnet, Claude 4.5 Haiku), or Google (Gemini 3.1 Pro, Gemini 3 Flash)
* **117+ tools at its disposal** — The AI agent can read posts, create content, manage WooCommerce products, check orders, update settings, and much more
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
* 🏷️ "Create a 20% discount coupon valid for the next 7 days"
* 🖼️ "Show me the last 10 images uploaded to the media library"
* ⚙️ "What is my site's tagline and timezone?"
* 📦 "Update the price of product #123 to $29.99"
* 💬 "Show me all pending comments so I can review them"
* 🧩 "What plugins are currently active?"
* 🎨 "Generate a hero image for my latest blog post about technology"
* 🎬 "Create a 5-second promotional video for my new product"

The AI agent understands context, chains multiple operations, and works with your site's real data in real time.

**🎨 AI Image & Video Generation**

Generate stunning images and videos directly from your AI agent or the dedicated Multimedia Settings page:

* **Image Generation** — "Generate a hero image for my blog post about AI" using OpenAI (gpt-image-1, DALL·E 2/3) or Google Gemini (Imagen 4)
* **Video Generation** — "Create a 5-second product showcase video" using OpenAI Sora or Google Veo 2/3
* **Auto-save to Media Library** — Generated images and videos are automatically saved and ready to use
* **Multi-provider** — Choose your preferred provider and model per generation type
* **Multimedia Settings** — Dedicated admin page to configure providers, API keys, default sizes, quality, and post-processing options

**🚀 Extend With Custom Tools**

Transform ANY WordPress plugin into an AI tool! Custom Tools lets you write simple PHP snippets that expose plugin functionality to your AI agent:

* Query Contact Form 7 submissions through conversation
* Get Yoast SEO scores and recommendations
* Control WP Super Cache settings with natural language
* Access Advanced Custom Fields data
* Build custom WooCommerce reports

No coding experience required — use the built-in examples as templates.

**🧩 Code Snippet Management — Design and Develop Through Conversation**

Create, edit, activate, and manage code snippets on your WordPress site entirely through AI — no manual coding required. Compatible with the three most popular snippet plugins: **WPCode**, **Code Snippets**, and **Woody Code Snippets**.

* **Add functionality instantly** — "Add a PHP snippet that redirects users after login based on their role"
* **Custom CSS on demand** — "Create a CSS snippet that hides the sidebar on mobile devices"
* **JavaScript injection** — "Add a JS snippet that shows a sticky banner with a 10% discount code"
* **Full lifecycle management** — List, create, edit, activate, deactivate, and delete snippets from conversation
* **Automatic provider detection** — Works with whichever snippet plugin you have installed, no extra configuration
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

Run AI workflows automatically when specific events happen:

* **WordPress Triggers** — New post published, user registered, comment posted
* **WooCommerce Triggers** — New order received, order status changed, order completed, refunded
* **Conditional Logic** — Run only when conditions are met (post type, status, category)
* **Dynamic Prompts** — Use placeholders like `{{post.title}}` for context-aware AI
* **Rate Limiting** — Prevent runaway executions with configurable cooldowns
* **Test Mode** — Preview your prompt with real trigger data before going live

**📡 Full MCP Server — Connect External AI Clients**

StifLi Flex MCP also works as a standards-compliant Model Context Protocol (MCP) server, so you can connect external AI clients:

* **ChatGPT** — via Apps & Connectors with OAuth 2.1 authentication
* **Claude Desktop** — via Connectors with automatic OAuth flow
* **LibreChat** — full MCP integration
* **Any MCP-compatible client** — JSON-RPC 2.0 + SSE + OAuth 2.1

Just copy the SSE URL from the Settings page, paste it into your AI client, and authorize. That's it — no tokens to manage, no passwords to share. The server handles discovery, registration, and authentication automatically following the latest security standards (OAuth 2.1, PKCE, RFC 9728, RFC 8414, RFC 7591).

The server exposes 117+ tools (55 WordPress + 61 WooCommerce + 1 Core + Abilities + Custom Tools) that external AI agents can discover and execute.

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
* Complete Site — all 117+ tools enabled
* Safe Mode — non-sensitive reads only
* Development/Debug — diagnostic tools

**Demo & Installation Tutorial:**

https://youtu.be/KHr1zt2R8Ew

== Installation ==

= Quick Start (AI Copilot) =

1. Upload the `stifli-flex-mcp` folder to `/wp-content/plugins/` or install from the WordPress plugin directory
2. Activate the plugin
3. Go to **StifLi Flex MCP → AI Copilot** and make sure it’s enabled
4. Go to **StifLi Flex MCP → AI Chat Agent → Settings** and enter your API key
5. Open any post or page in the editor — the Copilot widget appears automatically
6. Start writing with AI!

= Quick Start (AI Chat Agent) =

1. Go to **StifLi Flex MCP → AI Chat Agent**
2. Open the **Settings** tab and select your AI provider (OpenAI, Claude, or Gemini)
3. Enter your API key
4. Start chatting!

That's it — no external tools, no complex configuration. Your AI agent is ready.

= Optional: MCP Server for External Clients =

If you also want to connect external AI clients (ChatGPT, Claude Desktop, LibreChat):

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
2. Choose your AI provider (OpenAI, Claude, or Gemini)
3. Enter your API key (you get this from your AI provider's website)
4. Go to the Chat tab and start talking!

= Which AI provider should I use? =

All three providers work great. Here's a quick comparison:

* **OpenAI (GPT-4o / GPT-4.5)** — Best overall balance of speed and quality
* **Claude (Opus / Sonnet)** — Excellent at understanding complex instructions and writing
* **Gemini (2.5 Pro / Flash)** — Great value, fast responses

You can switch providers at any time from the Settings tab.

= What can the AI agent do with my site? =

The agent has access to 117+ tools covering:

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

Absolutely! Go to **MCP Server → Custom Tools** and create PHP-powered tools that expose any plugin's functionality to your AI agent. Built-in examples included.

= Can the AI generate images? =

Yes! The `wp_generate_image` tool supports multiple providers:

* **OpenAI** — gpt-image-1 (recommended), DALL·E 3, DALL·E 2
* **Google Gemini** — Imagen 4

Just ask your AI agent "Generate an image of..." or configure defaults in **StifLi Flex MCP → Multimedia Settings → Images**.

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

== Screenshots ==

1. AI Copilot - Floating assistant inside the WordPress editor with quick actions
2. AI Copilot - Visual feedback with green highlights and Keep/Undo banners
3. AI Chat Agent - Chat with AI directly from WordPress admin
4. AI Chat Agent - Settings and provider configuration
5. MCP Server - Endpoint URLs and authentication setup
6. MCP Server - Tool profiles management
7. MCP Server - WordPress and WooCommerce tools management

== Changelog ==
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