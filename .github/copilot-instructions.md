# Copilot Instructions for StifLi Flex MCP

## Project Overview
**StifLi Flex MCP** is a WordPress plugin exposing WordPress/WooCommerce management via JSON-RPC 2.0, designed for LLM integration (ChatGPT, Claude, etc.). It implements the Model Context Protocol (MCP) specification with **124 tools** (58 WordPress + 65 WooCommerce + 1 Core), tool discovery (`tools/list`), execution (`tools/call`), and SSE streaming at `/wp-json/stifli-flex-mcp/v1/`.

**Critical Context**: This is a **pure PHP WordPress plugin** – no build step, no npm/webpack. Edit files → reload WordPress.

## Architecture & Data Flow

### Request Flow (JSON-RPC 2.0)
1. Client → `/wp-json/stifli-flex-mcp/v1/messages` (POST) or `/sse` (GET/POST for streaming)
2. `mod.php::canAccessMCP()` → token validation (Bearer header or `?token=` query param)
3. `mod.php::handleDirectJsonRPC()` → method routing (`initialize`, `tools/list`, `tools/call`)
4. `models/model.php::dispatchTool()` → tool execution with capability check
5. Response → JSON-RPC result or error (stored in `wp_sflmcp_queue` for SSE clients)

### Key Components
- **`stifli-flex-mcp.php`**: Bootstrap (activation hooks, table creation, seeding), loads helpers/models, initializes `StifliFlexMcp`, registers cron (`sflmcp_clean_queue` hourly)
- **`mod.php`**: Core logic – REST API registration (`/messages`, `/sse`), auth (`canAccessMCP`), JSON-RPC dispatch, SSE streaming loop, **four-tab admin UI** (Settings, Profiles, WordPress Tools, WooCommerce Tools)
- **`models/model.php`**: Tool registry (`getTools()`), dispatch logic (`dispatchTool()`), capability mapping (`getToolCapability()`), tools filtering (`getToolsList()`), intent classification
- **WooCommerce modules** (`models/woocommerce/*.php`): Separate tool definitions + dispatch logic for products, orders, customers/coupons, system/settings
- **Helpers**: `utils.php` (safe array access, token estimation, table naming), `dispatcher.php` (filter wrapper), `frame.php` (logging stub), `req.php` (unused controller stub)

### Database Schema (4 Tables)
- **`wp_sflmcp_queue`**: Session-based message queue for SSE (fields: `session_id`, `message_id`, `payload`, `expires_at`); cleaned hourly by cron
- **`wp_sflmcp_tools`**: Tool registry with enable/disable state (fields: `tool_name`, `tool_description`, `category`, `enabled`, `token_estimate`)
- **`wp_sflmcp_profiles`**: Tool profile configurations (fields: `profile_name`, `profile_description`, `is_system`, `is_active`)
- **`wp_sflmcp_profile_tools`**: Many-to-many mapping of profiles → tools (fields: `profile_id`, `tool_name`)

### SSE Streaming (Critical for ChatGPT Connectors)
- SSE endpoint: `/wp-json/stifli-flex-mcp/v1/sse` (GET or POST accepted)
- **Connection flow**: Sends `event: endpoint` with `/messages` URL → client POSTs JSON-RPC to `/messages` → responses buffered in `wp_sflmcp_queue` → SSE polls every 200ms and streams `event: message`
- Sends `event: heartbeat` every 10s, `event: bye` on disconnect/timeout (5min idle)
- **Critical**: Disables output buffering (`ob_end_flush()`, `X-Accel-Buffering: no`) to prevent CDN/proxy blocking SSE streams
- Messages in queue expire after 5 min; `sflmcp_clean_queue` cron (hourly) purges old rows

## Tool Development Pattern

### Adding a New Tool (4-Step Pattern)
```php
// 1. Define in getTools() (models/model.php ~line 163)
'my_new_tool' => array(
    'name' => 'my_new_tool',
    'description' => 'Does X with Y. Returns Z.',
    'inputSchema' => array(
        'type' => 'object',
        'properties' => array(
            'param1' => array('type' => 'string', 'description' => 'Parameter description'),
        ),
        'required' => array('param1'),
    ),
),

// 2. Add capability if mutating (models/model.php::getToolCapability ~line 1168)
'my_new_tool' => 'edit_posts', // or null for public/read-only

// 3. Implement in dispatchTool() (models/model.php::dispatchTool ~line 1253)
case 'my_new_tool':
    $param1 = $args['param1'] ?? '';
    // ... logic using WP functions ...
    $addResultText($r, "Success message");
    return $r;

// 4. Seed into wp_sflmcp_tools (stifli-flex-mcp.php::stifli_flex_mcp_seed_initial_tools ~line 375)
array('my_new_tool', 'Does X with Y. Returns Z.', 'WordPress - YourCategory', 1),
```

**Important**: Only enabled tools (where `enabled = 1` in `wp_sflmcp_tools`) are returned by `getToolsList()`. Users toggle tools in admin UI (4 tabs: Settings, Profiles, WordPress Tools, WooCommerce Tools).

### WooCommerce Tools Pattern
WooCommerce tools follow a **modular architecture**:
- Tool definitions: `models/woocommerce/wc-{module}.php::getTools()` (e.g., `wc-products.php`, `wc-orders.php`)
- Dispatch logic: Same file, `dispatch()` method with switch statement
- Auto-loaded when `class_exists('WooCommerce')` (see `models/model.php` ~line 6)
- Main model delegates to WC modules via `StifliFlexMcp_WC_Products::dispatch()` etc.

### Intent Classification (models/model.php::getIntentForTool ~line 23)
- **`read`**: Public tools (no confirmation) – e.g., `wp_get_posts`, `wp_get_users`, `wc_get_products`
- **`sensitive_read`**: Requires confirmation – `wp_get_option`, `wp_get_post_meta`, `wp_get_user_meta`, `fetch`, `wc_get_customers`, `wc_get_system_status`
- **`write`**: Requires confirmation – all `wp_create_*`, `wp_update_*`, `wp_delete_*`, `wc_create_*`, `wc_update_*`, plugin/theme install

### Profile System (8 Predefined Profiles)
Profiles control which tools are available to clients. Managed in admin UI Profiles tab:
- **WordPress Read Only**: Safe read-only access (posts, pages, users, taxonomies, media)
- **WordPress Full Management**: All 58 WordPress CRUD tools (removed 5 for WordPress.org compliance)
- **WooCommerce Read Only**: Query products, orders, customers without modifications
- **WooCommerce Store Management**: Products, stock, orders, coupons (no advanced settings)
- **Complete E-commerce**: All 65 WooCommerce tools (tax, shipping, webhooks)
- **Complete Site**: All 129 tools enabled
- **Safe Mode**: Non-sensitive reads only (no options, settings, user_meta, system status)
- **Development/Debug**: Diagnostic tools (site health, settings, system status)

Profiles stored in `wp_sflmcp_profiles` + `wp_sflmcp_profile_tools` (many-to-many). Seeded on activation via `stifli_flex_mcp_seed_system_profiles()` (~line 511).

## Authentication & Security Patterns

### Token Validation (mod.php::canAccessMCP)
```php
// Priority order:
// 1. If no token configured → allow public access
// 2. Check Authorization: Bearer <token>
// 3. Fallback to ?token=<token> query param
// 4. On match → wp_set_current_user() to mapped user or admin
// 5. Apply filters 'allow_SFLMCP' (extensible)
```

### Capability Enforcement (models/model.php::dispatchTool)
```php
$required_cap = $this->getToolCapability($tool);
if ($required_cap && !current_user_can($required_cap)) {
    $r['error'] = array('code' => -32603, 'message' => 'Insufficient permissions');
    return $r;
}
```

## Critical Developer Workflows

### Testing with PowerShell Scripts (.dev directory)
The `.dev/` directory contains testing scripts (excluded from distribution):
```powershell
# Test connectivity
.\.dev\test-mcp-ping.ps1

# List all available tools
.\.dev\test-tools-list.ps1

# Test authentication
.\.dev\test-query-auth.ps1 -Token "your_token"

# Test image upload from base64
.\.dev\test-upload-base64.ps1
```

### Testing with REST Client (.dev examples)
Manual REST testing pattern (PowerShell with `Invoke-RestMethod`):
```powershell
$headers = @{
    "Authorization" = "Bearer YOUR_TOKEN"
    "Content-Type" = "application/json"
}
$body = @{
    jsonrpc = "2.0"
    method = "tools/call"
    params = @{
        name = "mcp_ping"
        arguments = @{}
    }
    id = 1
} | ConvertTo-Json

Invoke-RestMethod -Uri "https://your-site.test/wp-json/stifli-flex-mcp/v1/messages" `
    -Method POST -Headers $headers -Body $body
```

### Debugging (WP_DEBUG + SFLMCP_DEBUG)
- Enable WordPress debug: `define('WP_DEBUG', true);` in `wp-config.php`
- Enable plugin debug: `define('SFLMCP_DEBUG', true);` in `wp-config.php` (see `stifli-flex-mcp.php` ~line 22)
- Logs use `stifli_flex_mcp_log()` function (writes to `error_log` when `SFLMCP_DEBUG` enabled)
- Key log points: `mod.php` (auth flow, SSE events, token masking), `models/model.php` (tool dispatch, capability checks)
- Check `wp-content/debug.log` or server error_log

### Admin UI (4-Tab Interface)
- **Location**: WordPress Admin → StifLi Flex MCP (top-level menu, dashicons-rest-api icon)
- **Four Tabs** (registered in `mod.php::registerAdmin()` ~line 1000):
  1. **Settings**: Generate/revoke tokens (AJAX handlers), map token to WP user, view endpoint URLs
  2. **Profiles**: Create/edit/delete/duplicate profiles, apply profiles, import/export JSON configs (AJAX: `sflmcp_create_profile`, etc.)
  3. **WordPress Tools**: Enable/disable WordPress tools by category (63 tools)
  4. **WooCommerce Tools**: Enable/disable WooCommerce tools by category (65 tools)
- **AJAX Handlers**: All in `mod.php` (`ajax_generate_token`, `ajax_create_profile`, `ajax_apply_profile`, etc.)

## Project-Specific Conventions

### Safe Array Access Pattern
Always use `StifliFlexMcpUtils::getArrayValue($arr, 'key', $default)` instead of direct array access to prevent notices.

### Result Construction (dispatchTool)
```php
// Use helper closure to add text results:
$addResultText($r, "Human-readable output");
// Or set structured result:
$r['result'] = array('content' => [array('type' => 'text', 'text' => '...')]);
```

### Error Handling (JSON-RPC codes)
- `-32700`: Parse error (invalid JSON)
- `-32600`: Invalid Request (method missing)
- `-32601`: Method not found
- `-32603`: Internal error / Insufficient permissions
- `-44001`: Custom "Method not found" fallback
- `-44000`: Internal exception

### HTML Sanitization
Use `$cleanHtml = function($v) { return wp_kses_post( wp_unslash( $v ) ); };` for user-provided HTML content.

### JSON-RPC ID Handling
The `id` field in JSON-RPC 2.0 can be **string, int, or null**. All methods handling `$id` (e.g., `handleCallback`, `dispatchTool`, `rpcError`) accept mixed types without type hints to comply with the spec.

## Migration & Extension

### Porting Tools from ai-copilot (see .dev/MIGRACION_TOOLS.md)
- Replace `WaicUtils` → `StifliFlexMcpUtils`
- Replace `WaicFrame` → `StifliFlexMcpFrame`
- Update tool array in `getTools()`
- Copy/adapt dispatch case blocks
- Test with PowerShell scripts in `.dev/`

### Current Status (.dev/TODO.md priorities)
- ✅ Basic tools (posts, users, comments, taxonomies, media, plugins)
- 🚧 OpenAI/ChatGPT `functions` adapter (in-progress)
- ⏳ Token validation for mutating tools (partially done)
- ⏳ Strict parameter validation against schemas

### Development Files (.dev directory - NOT distributed)
- `MIGRACION_TOOLS.md`: Tool migration checklist from ai-copilot
- `TODO.md`: Development roadmap and priorities
- `PERFILES_DESIGN.md`: Profile system design docs
- `WOOCOMMERCE_TOOLS.md`: WooCommerce tools documentation
- `build-plugin.ps1`: ZIP build script for WordPress.org distribution
- `count_tools.php`: Tool counting utility
- Test scripts: `test-*.ps1` (PowerShell REST API tests)

## External Dependencies & Integration

### WordPress APIs Used
- `wp_insert_post()`, `wp_update_post()`, `wp_delete_post()`
- `get_posts()`, `get_comments()`, `get_users()`
- `wp_insert_term()`, `wp_delete_term()`, `get_terms()`
- `wp_upload_bits()` for media (from `aiwu_image` tool)
- `activate_plugin()`, `deactivate_plugins()`, `plugins_api()`
- Plugin/Theme Upgrader API for installs

### LLM Integration Notes
- **ChatGPT Connectors**: MUST use SSE endpoint (not just `/messages`)
- **Tool Discovery**: Call `tools/list` first, then `tools/call` with `name` + `arguments`
- **Protocol Version**: Advertises `2025-06-18` in `initialize` response
- **Capabilities**: `tools.listChanged = true`, `prompts/resources = false`

## Quick Reference

### File Locations for Common Tasks
- Add tool: `models/model.php` → `getTools()` + `dispatchTool()`
- Add capability: `models/model.php` → `getToolCapability()`
- Modify auth: `mod.php` → `canAccessMCP()`
- Admin UI: `mod.php` → `registerAdmin()`, `settingsPage()`
- Test requests: `.dev/test-*.ps1` PowerShell scripts
- Queue storage: `mod.php::storeMessage()` / `fetchMessages()` (table `wp_sflmcp_queue`)
- Queue cleanup cron: `stifli-flex-mcp.php::stifli_flex_mcp_clean_queue()` (hook `sflmcp_clean_queue` hourly)
- Tools database: `stifli-flex-mcp.php::stifli_flex_mcp_maybe_create_tools_table()`, `stifli_flex_mcp_seed_initial_tools()` (table `wp_sflmcp_tools`)

### No Build Step
Pure PHP plugin – edit files, reload WordPress. No npm/composer/webpack required.