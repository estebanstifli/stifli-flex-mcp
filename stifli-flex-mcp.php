<?php
/*
Plugin Name: StifLi Flex MCP - AI Copilot, Chat Agent and MCP Server
Plugin URI: https://github.com/estebanstifli/stifli-flex-mcp
Description: Transform your WordPress site into a Model Context Protocol (MCP) server. Expose 117+ tools (55 WordPress, 61 WooCommerce, 1 Core + WordPress Abilities) that AI agents like ChatGPT, Claude, and LibreChat can use to manage your WordPress and WooCommerce site via JSON-RPC 2.0.
Version: 3.1.5
Author: estebandestifli
Requires PHP: 7.4
License: GPL v2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Text Domain: stifli-flex-mcp
Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// define debug constant
if ( ! defined( 'SFLMCP_DEBUG' ) ) {
	define( 'SFLMCP_DEBUG', false );	
}

// Debug logging function
if (!function_exists('stifli_flex_mcp_log')) {
	function stifli_flex_mcp_log($message, array $context = []) {
		// Check if logging is enabled via option (admin setting) or constant
		$logging_enabled = get_option('sflmcp_logging_enabled', false);
		if (!$logging_enabled && (!defined('SFLMCP_DEBUG') || SFLMCP_DEBUG !== true)) {
			return;
		}

		if (!is_string($message)) {
			$encoded = wp_json_encode($message);
			if ($encoded !== false) {
				$message = $encoded;
			} else {
				// Fallback to safe string/serialization without using print_r
				if (is_scalar($message)) {
					$message = (string) $message;
				} else {
					$message = maybe_serialize($message);
				}
			}
		}

		if (!empty($context)) {
			$encoded_context = wp_json_encode($context);
			if ($encoded_context !== false) {
				$message .= ' ' . $encoded_context;
			}
		}

		// Write to plugin's own log file
		$log_file = stifli_flex_mcp_get_log_file_path();
		$timestamp = gmdate('Y-m-d H:i:s');
		$log_entry = sprintf("[%s] %s\n", $timestamp, $message);
		
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- logging to plugin-managed file
		file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);
	}
}

// Get log file path
if (!function_exists('stifli_flex_mcp_get_log_file_path')) {
	function stifli_flex_mcp_get_log_file_path() {
		$upload_dir = wp_upload_dir();
		$log_dir = $upload_dir['basedir'] . '/sflmcp-logs';
		
		// Create log directory if it doesn't exist
		if (!file_exists($log_dir)) {
			wp_mkdir_p($log_dir);
			// Add .htaccess to protect log files
			$htaccess = $log_dir . '/.htaccess';
			if (!file_exists($htaccess)) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				file_put_contents($htaccess, "Order deny,allow\nDeny from all");
			}
			// Add index.php for extra protection
			$index = $log_dir . '/index.php';
			if (!file_exists($index)) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				file_put_contents($index, '<?php // Silence is golden.');
			}
		}
		
		return $log_dir . '/sflmcp-debug.log';
	}
}

// Get log file contents
if (!function_exists('stifli_flex_mcp_get_log_contents')) {
	function stifli_flex_mcp_get_log_contents($max_lines = 500) {
		$log_file = stifli_flex_mcp_get_log_file_path();
		
		if (!file_exists($log_file)) {
			return '';
		}
		
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$content = file_get_contents($log_file);
		if ($content === false) {
			return '';
		}
		
		// Limit to last N lines
		$lines = explode("\n", $content);
		if (count($lines) > $max_lines) {
			$lines = array_slice($lines, -$max_lines);
		}
		
		return implode("\n", $lines);
	}
}

// Clear log file
if (!function_exists('stifli_flex_mcp_clear_log')) {
	function stifli_flex_mcp_clear_log() {
		$log_file = stifli_flex_mcp_get_log_file_path();
		
		if (file_exists($log_file)) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents($log_file, '');
			return true;
		}
		
		return false;
	}
}

// Get log file size
if (!function_exists('stifli_flex_mcp_get_log_size')) {
	function stifli_flex_mcp_get_log_size() {
		$log_file = stifli_flex_mcp_get_log_file_path();
		
		if (!file_exists($log_file)) {
			return 0;
		}
		
		return filesize($log_file);
	}
}

// WordPress automatically loads translations from WordPress.org since 4.6+
// No need to manually call load_plugin_textdomain() for plugins hosted on WordPress.org

// Bootstrap: load necessary helpers and classes before the main module
require_once __DIR__ . '/models/utils.php';
require_once __DIR__ . '/models/frame.php';
require_once __DIR__ . '/models/dispatcher.php';
require_once __DIR__ . '/models/req.php';
require_once __DIR__ . '/models/model.php';
require_once __DIR__ . '/models/class-change-tracker.php';
require_once __DIR__ . '/controller.php';
require_once __DIR__ . '/mod.php';

// Load AI Chat Agent
require_once __DIR__ . '/client/providers/class-provider-base.php';
require_once __DIR__ . '/client/providers/class-provider-openai.php';
require_once __DIR__ . '/client/providers/class-provider-claude.php';
require_once __DIR__ . '/client/providers/class-provider-gemini.php';
require_once __DIR__ . '/client/class-client-admin.php';
require_once __DIR__ . '/client/class-automation-admin.php';

// Load Event Automations
require_once __DIR__ . '/client/class-event-trigger-registry.php';
require_once __DIR__ . '/client/class-event-automation-engine.php';
require_once __DIR__ . '/client/class-event-automation-admin.php';

// Load Logs Admin
require_once __DIR__ . '/client/class-logs-admin.php';

// Load AI Copilot
require_once __DIR__ . '/copilot/class-copilot-admin.php';

// Load OAuth 2.1 Authorization Server
require_once __DIR__ . '/oauth/class-oauth-storage.php';
require_once __DIR__ . '/oauth/class-oauth-server.php';

// Initialize Automation Admin
if ( is_admin() ) {
	new StifliFlexMcp_Automation_Admin();
	new StifliFlexMcp_Event_Automation_Admin();
	new StifliFlexMcp_Logs_Admin();
	new StifliFlexMcp_Copilot_Admin();
}

// Initialize Event Automation Engine (for trigger hooks)
add_action( 'init', 'stifli_flex_mcp_init_event_engine', 20 );
function stifli_flex_mcp_init_event_engine() {
	if ( class_exists( 'StifliFlexMcp_Event_Automation_Engine' ) ) {
		$engine = StifliFlexMcp_Event_Automation_Engine::get_instance();
		$engine->init();
	}
}

// Ensure automation cron is always running (for existing installs)
add_action( 'init', 'stifli_flex_mcp_check_automation_cron', 99 );
function stifli_flex_mcp_check_automation_cron() {
	if ( ! wp_next_scheduled( 'sflmcp_process_automation_tasks' ) ) {
		wp_schedule_event( time(), 'every_minute', 'sflmcp_process_automation_tasks' );
	}
}

/*
 * Custom data layer for plugin tables.
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
 */

function stifli_flex_mcp_maybe_create_queue_table() {
	global $wpdb;
	$table = StifliFlexMcpUtils::getPrefixedTable('sflmcp_queue', false);
	$like = $wpdb->esc_like($table);
	$exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $like));
	if ($exists === $table) {
		return;
	}
	$charset_collate = $wpdb->get_charset_collate();
	$sql = sprintf(
		"CREATE TABLE %s (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		session_id VARCHAR(191) NOT NULL,
		message_id VARCHAR(191) DEFAULT NULL,
		payload LONGTEXT NOT NULL,
		created_at DATETIME NOT NULL,
		expires_at DATETIME NOT NULL,
		PRIMARY KEY (id),
		KEY session_created (session_id, created_at),
		KEY expires_at (expires_at)
	) %s;",
		StifliFlexMcpUtils::getPrefixedTable('sflmcp_queue'),
		$charset_collate
	);
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta($sql);
}

function stifli_flex_mcp_maybe_create_tools_table() {
	global $wpdb;
	$table = StifliFlexMcpUtils::getPrefixedTable('sflmcp_tools', false);
	$like = $wpdb->esc_like($table);
	$exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $like));
	if ($exists === $table) {
		return;
	}
	$charset_collate = $wpdb->get_charset_collate();
	$sql = sprintf(
		"CREATE TABLE %s (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		tool_name VARCHAR(191) NOT NULL,
		tool_description TEXT,
		category VARCHAR(100) NOT NULL DEFAULT 'WordPress',
		enabled TINYINT(1) NOT NULL DEFAULT 1,
		token_estimate INT UNSIGNED NOT NULL DEFAULT 0,
		created_at DATETIME NOT NULL,
		updated_at DATETIME NOT NULL,
		PRIMARY KEY (id),
		UNIQUE KEY tool_name (tool_name),
		KEY category (category),
		KEY enabled (enabled)
	) %s;",
		StifliFlexMcpUtils::getPrefixedTable('sflmcp_tools'),
		$charset_collate
	);
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta($sql);
}

function stifli_flex_mcp_maybe_add_tools_token_column() {
	global $wpdb;
	$table = StifliFlexMcpUtils::getPrefixedTable('sflmcp_tools', false);
	$like = $wpdb->esc_like($table);
	$exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $like));
	if ($exists !== $table) {
		return;
	}
	$columns_query = StifliFlexMcpUtils::formatSqlWithTables(
		'SHOW COLUMNS FROM %s LIKE %%s',
		'sflmcp_tools'
	);
	$column = $wpdb->get_var($wpdb->prepare($columns_query, 'token_estimate'));
	if (null === $column) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared -- schema migration for plugin-managed table.
		$wpdb->query(
			sprintf(
				'ALTER TABLE %s ADD COLUMN token_estimate INT UNSIGNED NOT NULL DEFAULT 0 AFTER enabled',
				StifliFlexMcpUtils::getPrefixedTable('sflmcp_tools')
			)
		);
	}
}

function stifli_flex_mcp_sync_tool_token_estimates() {
	global $wpdb;
	$table = StifliFlexMcpUtils::getPrefixedTable('sflmcp_tools', false);
	$like = $wpdb->esc_like($table);
	$exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $like));
	if ($exists !== $table || !class_exists('StifliFlexMcpModel')) {
		return;
	}
	$tools_select = StifliFlexMcpUtils::formatSqlWithTables(
		'SELECT tool_name, tool_description, token_estimate FROM %s WHERE 1 = %%d',
		'sflmcp_tools'
	);
	$rows = $wpdb->get_results($wpdb->prepare($tools_select, 1), ARRAY_A);
	if (!is_array($rows)) {
		return;
	}
	$model = new StifliFlexMcpModel();
	$definitions = $model->getTools();
	if (!is_array($definitions)) {
		$definitions = array();
	}
	$definitionMap = array();
	foreach ($definitions as $definition) {
		$name = isset($definition['name']) ? (string) $definition['name'] : '';
		if ('' === $name) {
			continue;
		}
		$definitionMap[$name] = $definition;
	}
	$now = current_time('mysql', true);
	foreach ($rows as $row) {
		$name = isset($row['tool_name']) ? (string) $row['tool_name'] : '';
		if ('' === $name) {
			continue;
		}
		$current = isset($row['token_estimate']) ? (int) $row['token_estimate'] : 0;
		if (isset($definitionMap[$name])) {
			$estimate = StifliFlexMcpUtils::estimateToolTokenUsage($definitionMap[$name]);
		} else {
			$desc = isset($row['tool_description']) ? (string) $row['tool_description'] : '';
			$fallbackDef = array(
				'name' => $name,
				'description' => $desc,
			);
			$estimate = StifliFlexMcpUtils::estimateToolTokenUsage($fallbackDef);
			if ($estimate <= 0 && '' !== $desc) {
				$estimate = (int) ceil(strlen($desc) / 4);
			}
		}
		if ($estimate < 0) {
			$estimate = 0;
		}
		if ($current === $estimate) {
			continue;
		}
		$wpdb->update(
			StifliFlexMcpUtils::getPrefixedTable('sflmcp_tools', false),
			array(
				'token_estimate' => $estimate,
				'updated_at' => $now,
			),
			array('tool_name' => $name),
			array('%d', '%s'),
			array('%s')
		);
	}
}

add_action('woocommerce_loaded', 'stifli_flex_mcp_sync_tool_token_estimates', 20);
if (did_action('woocommerce_loaded')) {
	stifli_flex_mcp_sync_tool_token_estimates();
}

/*
 * Custom data layer for plugin-managed tables.
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
 */
function stifli_flex_mcp_maybe_create_profiles_table() {
	global $wpdb;
	$table = $wpdb->prefix . 'sflmcp_profiles';
	$like = $wpdb->esc_like($table);
	$exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $like));
	if ($exists === $table) {
		return;
	}
	$charset_collate = $wpdb->get_charset_collate();
	$sql = "CREATE TABLE {$table} (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		profile_name VARCHAR(191) NOT NULL,
		profile_description TEXT,
		is_system TINYINT(1) NOT NULL DEFAULT 0,
		is_active TINYINT(1) NOT NULL DEFAULT 0,
		created_at DATETIME NOT NULL,
		updated_at DATETIME NOT NULL,
		PRIMARY KEY (id),
		UNIQUE KEY profile_name (profile_name),
		KEY is_active (is_active)
	) {$charset_collate};";
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta($sql);
}

function stifli_flex_mcp_maybe_create_profile_tools_table() {
	global $wpdb;
	$table = $wpdb->prefix . 'sflmcp_profile_tools';
	$like = $wpdb->esc_like($table);
	$exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $like));
	if ($exists === $table) {
		return;
	}
	$charset_collate = $wpdb->get_charset_collate();
	$sql = "CREATE TABLE {$table} (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		profile_id BIGINT UNSIGNED NOT NULL,
		tool_name VARCHAR(191) NOT NULL,
		created_at DATETIME NOT NULL,
		PRIMARY KEY (id),
		UNIQUE KEY profile_tool (profile_id, tool_name),
		KEY profile_id (profile_id)
	) {$charset_collate};";
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta($sql);
}

function stifli_flex_mcp_seed_initial_tools() {
	global $wpdb;
	$table = $wpdb->prefix . 'sflmcp_tools';
	
	// Check if already seeded
	$count = $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
	if ($count > 0) {
		return; // Already seeded, don't insert duplicates
	}
	
	$now = current_time('mysql', true);
	
	// Currently implemented tools
	$tools = array(
		// Diagnostics
		array('mcp_ping', 'Simple connectivity check. Returns the current GMT time and the WordPress site name.', 'Core', 1),
		
		// Posts
		array('wp_get_posts', 'List posts with filters (post_type, post_status, search, limit, offset, paged, after, before).', 'WordPress - Posts', 1),
		array('wp_get_post', 'Get a single post by ID.', 'WordPress - Posts', 1),
		array('wp_create_post', 'Create a post. Requires post_title. Supports post_content, post_status, post_type, etc.', 'WordPress - Posts', 1),
		array('wp_update_post', 'Update a post by ID with fields object.', 'WordPress - Posts', 1),
		array('wp_delete_post', 'Delete a post by ID.', 'WordPress - Posts', 1),
		
		// Pages
		array('wp_get_pages', 'List pages with filters (post_status, search, limit, offset, orderby, order).', 'WordPress - Pages', 1),
		array('wp_create_page', 'Create a new page.', 'WordPress - Pages', 1),
		array('wp_update_page', 'Update a page by ID.', 'WordPress - Pages', 1),
		array('wp_delete_page', 'Delete a page by ID.', 'WordPress - Pages', 1),
		
		// Comments
		array('wp_get_comments', 'List comments. Supports post_id, status, search, limit, offset, paged.', 'WordPress - Comments', 1),
		array('wp_create_comment', 'Create a comment. Requires post_id and comment_content.', 'WordPress - Comments', 1),
		array('wp_update_comment', 'Update a comment by comment_ID with fields object.', 'WordPress - Comments', 1),
		array('wp_delete_comment', 'Delete a comment by comment_ID.', 'WordPress - Comments', 1),
		
		// Users
		array('wp_get_users', 'List users with filters (role, search, limit, offset, paged).', 'WordPress - Users', 1),
		// Removed for WordPress.org compliance: wp_create_user, wp_update_user, wp_delete_user
		
		// User Meta
		array('wp_get_user_meta', 'Get user meta by user ID and optional meta key.', 'WordPress - User Meta', 1),
		array('wp_update_user_meta', 'Update user meta by user ID and meta key.', 'WordPress - User Meta', 1),
		array('wp_delete_user_meta', 'Delete user meta by user ID and meta key.', 'WordPress - User Meta', 1),
		
		// Plugins
		array('wp_list_plugins', 'List all installed plugins with status, version, etc.', 'WordPress - Plugins', 1),
		// Removed for WordPress.org compliance (Issue #5): wp_activate_plugin, wp_deactivate_plugin, wp_install_plugin
		
		// Themes
		// Removed for WordPress.org compliance (Issue #6): wp_install_theme, wp_switch_theme
		array('wp_get_themes', 'List all installed themes.', 'WordPress - Themes', 1),
		
		// Media
		array('wp_get_media', 'List media items (attachments) with filters.', 'WordPress - Media', 1),
		array('wp_get_media_item', 'Get a single media item by ID.', 'WordPress - Media', 1),
		array('wp_upload_image_from_url', 'Upload an image from URL and attach to a post.', 'WordPress - Media', 1),
		array('wp_upload_image', 'Upload image from base64 data (AI-generated images).', 'WordPress - Media', 1),
		array('wp_update_media_item', 'Update media item metadata.', 'WordPress - Media', 1),
		array('wp_delete_media_item', 'Delete a media item by ID.', 'WordPress - Media', 1),
		
		// Taxonomies
		array('wp_get_taxonomies', 'List all registered taxonomies.', 'WordPress - Taxonomies', 1),
		array('wp_get_terms', 'Get terms from a taxonomy.', 'WordPress - Taxonomies', 1),
		array('wp_create_term', 'Create a new term in a taxonomy.', 'WordPress - Taxonomies', 1),
		array('wp_delete_term', 'Delete a term by term_id and taxonomy.', 'WordPress - Taxonomies', 1),
		
		// Categories
		array('wp_get_categories', 'List categories.', 'WordPress - Categories', 1),
		array('wp_create_category', 'Create a category.', 'WordPress - Categories', 1),
		array('wp_update_category', 'Update a category by term_id.', 'WordPress - Categories', 1),
		array('wp_delete_category', 'Delete a category by term_id.', 'WordPress - Categories', 1),
		
		// Tags
		array('wp_get_tags', 'List tags.', 'WordPress - Tags', 1),
		array('wp_create_tag', 'Create a tag.', 'WordPress - Tags', 1),
		array('wp_update_tag', 'Update a tag by term_id.', 'WordPress - Tags', 1),
		array('wp_delete_tag', 'Delete a tag by term_id.', 'WordPress - Tags', 1),
		
		// Menus
		array('wp_get_nav_menus', 'List all registered navigation menus.', 'WordPress - Menus', 1),
		array('wp_get_menus', 'List all navigation menus (alias for wp_get_nav_menus).', 'WordPress - Menus', 1),
		array('wp_get_menu', 'Get a specific menu with its items.', 'WordPress - Menus', 1),
		array('wp_create_nav_menu', 'Create a new navigation menu.', 'WordPress - Menus', 1),
		
		// Options y Meta
		array('wp_get_option', 'Get a WordPress option by key.', 'WordPress - Options', 1),
		array('wp_update_option', 'Update a WordPress option.', 'WordPress - Options', 1),
		array('wp_delete_option', 'Delete a WordPress option by key.', 'WordPress - Options', 1),
		array('wp_get_post_meta', 'Get post meta by post_id and meta_key.', 'WordPress - Meta', 1),
		array('wp_update_post_meta', 'Update post meta.', 'WordPress - Meta', 1),
		array('wp_delete_post_meta', 'Delete post meta by post_id and meta_key.', 'WordPress - Meta', 1),
		
		// Post Revisions
		array('wp_get_post_revisions', 'Get revisions for a post by post ID.', 'WordPress - Revisions', 1),
		array('wp_restore_post_revision', 'Restore a post revision by revision ID.', 'WordPress - Revisions', 1),
		
		// Custom Post Types & Site Health
		array('wp_get_post_types', 'Get all registered post types.', 'WordPress - Post Types', 1),
		array('wp_get_site_health', 'Get site health and diagnostic information.', 'WordPress - Health', 1),
		
		// Settings
		array('wp_get_settings', 'Get WordPress settings.', 'WordPress - Settings', 1),
		array('wp_update_settings', 'Update WordPress settings.', 'WordPress - Settings', 1),
		
		// Utilidades
		array('search', 'Search posts by keyword.', 'WordPress - Utilities', 1),
		array('fetch', 'Fetch content from a URL.', 'WordPress - Utilities', 1),
		array('wp_generate_image', 'Generate an image using AI and save it as a WordPress media attachment.', 'WordPress - Utilities', 1),
		array('wp_generate_video', 'Generate a video using AI (Google Veo or OpenAI Sora) and save it as a WordPress media attachment.', 'WordPress - Utilities', 1),
		
		// Changelog / Audit Log
		array('mcp_get_changelog', 'Get the changelog/audit log of MCP tool operations with filters and pagination.', 'WordPress - Changelog', 1),
		array('mcp_get_change_detail', 'Get full detail of a single changelog entry including before/after state.', 'WordPress - Changelog', 1),
		array('mcp_rollback_change', 'Rollback a specific changelog entry to its before-state.', 'WordPress - Changelog', 1),
		array('mcp_redo_change', 'Redo a previously rolled-back changelog entry.', 'WordPress - Changelog', 1),
		array('mcp_rollback_session', 'Rollback all changes made in a specific session (LIFO order).', 'WordPress - Changelog', 1),
	);
	
	// Snippet tools (requires WPCode or Code Snippets plugin)
	$tools[] = array('snippet_list', 'List code snippets. Supports limit, offset, active filter. Requires WPCode, Code Snippets, or Woody Code Snippets.', 'Snippets', 1);
	$tools[] = array('snippet_get', 'Get a single code snippet by ID with full details.', 'Snippets', 1);
	$tools[] = array('snippet_create', 'Create a new code snippet (inactive by default). Requires WPCode, Code Snippets, or Woody Code Snippets.', 'Snippets', 1);
	$tools[] = array('snippet_update', 'Update an existing code snippet by ID.', 'Snippets', 1);
	$tools[] = array('snippet_delete', 'Delete a code snippet by ID.', 'Snippets', 1);
	$tools[] = array('snippet_activate', 'Activate a code snippet by ID.', 'Snippets', 1);
	$tools[] = array('snippet_deactivate', 'Deactivate a code snippet by ID.', 'Snippets', 1);
	
	// WooCommerce tools (available even if WooCommerce is not installed)
	// WooCommerce Products
	$tools[] = array('wc_get_products', 'List WooCommerce products with filters.', 'WooCommerce - Products', 1);
	$tools[] = array('wc_create_product', 'Create a new WooCommerce product.', 'WooCommerce - Products', 1);
	$tools[] = array('wc_update_product', 'Update a WooCommerce product by ID.', 'WooCommerce - Products', 1);
	$tools[] = array('wc_delete_product', 'Delete a WooCommerce product by ID.', 'WooCommerce - Products', 1);
	$tools[] = array('wc_batch_update_products', 'Batch update multiple WooCommerce products.', 'WooCommerce - Products', 1);
	
	// WooCommerce Product Variations
	$tools[] = array('wc_get_product_variations', 'Get variations for a variable product.', 'WooCommerce - Products', 1);
	$tools[] = array('wc_create_product_variation', 'Create a product variation.', 'WooCommerce - Products', 1);
	$tools[] = array('wc_update_product_variation', 'Update a product variation.', 'WooCommerce - Products', 1);
	$tools[] = array('wc_delete_product_variation', 'Delete a product variation.', 'WooCommerce - Products', 1);
	
	// WooCommerce Product Categories
	$tools[] = array('wc_get_product_categories', 'List product categories.', 'WooCommerce - Categories', 1);
	$tools[] = array('wc_create_product_category', 'Create a product category.', 'WooCommerce - Categories', 1);
	$tools[] = array('wc_update_product_category', 'Update a product category.', 'WooCommerce - Categories', 1);
	$tools[] = array('wc_delete_product_category', 'Delete a product category.', 'WooCommerce - Categories', 1);
	
	// WooCommerce Product Tags
	$tools[] = array('wc_get_product_tags', 'List product tags.', 'WooCommerce - Tags', 1);
	$tools[] = array('wc_create_product_tag', 'Create a product tag.', 'WooCommerce - Tags', 1);
	$tools[] = array('wc_update_product_tag', 'Update a product tag.', 'WooCommerce - Tags', 1);
	$tools[] = array('wc_delete_product_tag', 'Delete a product tag.', 'WooCommerce - Tags', 1);
	
	// WooCommerce Product Reviews
	$tools[] = array('wc_get_product_reviews', 'List product reviews.', 'WooCommerce - Reviews', 1);
	$tools[] = array('wc_create_product_review', 'Create a product review.', 'WooCommerce - Reviews', 1);
	$tools[] = array('wc_update_product_review', 'Update a product review.', 'WooCommerce - Reviews', 1);
	$tools[] = array('wc_delete_product_review', 'Delete a product review.', 'WooCommerce - Reviews', 1);
	
	// WooCommerce Orders
	$tools[] = array('wc_get_orders', 'List WooCommerce orders with filters.', 'WooCommerce - Orders', 1);
	$tools[] = array('wc_create_order', 'Create a new WooCommerce order.', 'WooCommerce - Orders', 1);
	$tools[] = array('wc_update_order', 'Update a WooCommerce order by ID.', 'WooCommerce - Orders', 1);
	$tools[] = array('wc_delete_order', 'Delete a WooCommerce order by ID.', 'WooCommerce - Orders', 1);
	$tools[] = array('wc_batch_update_orders', 'Batch update multiple orders.', 'WooCommerce - Orders', 1);
	
	// WooCommerce Order Notes
	$tools[] = array('wc_get_order_notes', 'Get notes for an order.', 'WooCommerce - Orders', 1);
	$tools[] = array('wc_create_order_note', 'Add a note to an order.', 'WooCommerce - Orders', 1);
	$tools[] = array('wc_delete_order_note', 'Delete an order note.', 'WooCommerce - Orders', 1);
	
	// WooCommerce Refunds
	$tools[] = array('wc_create_refund', 'Create a refund for an order.', 'WooCommerce - Refunds', 1);
	$tools[] = array('wc_get_refunds', 'Get refunds for an order or all refunds.', 'WooCommerce - Refunds', 1);
	$tools[] = array('wc_delete_refund', 'Delete a refund by ID.', 'WooCommerce - Refunds', 1);
	
	// WooCommerce Stock Management
	$tools[] = array('wc_update_stock', 'Update product stock quantity.', 'WooCommerce - Stock', 1);
	$tools[] = array('wc_get_low_stock_products', 'Get products with low stock.', 'WooCommerce - Stock', 1);
	$tools[] = array('wc_set_stock_status', 'Set product stock status.', 'WooCommerce - Stock', 1);
	
	// WooCommerce Customers - Removed for WordPress.org compliance
	// wc_get_customers, wc_create_customer, wc_update_customer, wc_delete_customer removed
	
	// WooCommerce Coupons
	$tools[] = array('wc_get_coupons', 'List WooCommerce coupons.', 'WooCommerce - Coupons', 1);
	$tools[] = array('wc_create_coupon', 'Create a new coupon.', 'WooCommerce - Coupons', 1);
	$tools[] = array('wc_update_coupon', 'Update a coupon by ID.', 'WooCommerce - Coupons', 1);
	$tools[] = array('wc_delete_coupon', 'Delete a coupon by ID.', 'WooCommerce - Coupons', 1);
	
	// WooCommerce Reports
	$tools[] = array('wc_get_sales_report', 'Get sales report data.', 'WooCommerce - Reports', 1);
	$tools[] = array('wc_get_top_sellers_report', 'Get top sellers report.', 'WooCommerce - Reports', 1);
	
	// WooCommerce Tax
	$tools[] = array('wc_get_tax_classes', 'List tax classes.', 'WooCommerce - Tax', 1);
	$tools[] = array('wc_get_tax_rates', 'List tax rates.', 'WooCommerce - Tax', 1);
	$tools[] = array('wc_create_tax_rate', 'Create a new tax rate.', 'WooCommerce - Tax', 1);
	$tools[] = array('wc_update_tax_rate', 'Update a tax rate.', 'WooCommerce - Tax', 1);
	$tools[] = array('wc_delete_tax_rate', 'Delete a tax rate.', 'WooCommerce - Tax', 1);
	
	// WooCommerce Shipping
	$tools[] = array('wc_get_shipping_zones', 'List shipping zones.', 'WooCommerce - Shipping', 1);
	$tools[] = array('wc_get_shipping_zone_methods', 'Get shipping methods for a zone.', 'WooCommerce - Shipping', 1);
	$tools[] = array('wc_create_shipping_zone', 'Create a new shipping zone.', 'WooCommerce - Shipping', 1);
	$tools[] = array('wc_update_shipping_zone', 'Update a shipping zone.', 'WooCommerce - Shipping', 1);
	$tools[] = array('wc_delete_shipping_zone', 'Delete a shipping zone.', 'WooCommerce - Shipping', 1);
	
	// WooCommerce Payment Gateways
	$tools[] = array('wc_get_payment_gateways', 'List payment gateways.', 'WooCommerce - Gateways', 1);
	$tools[] = array('wc_update_payment_gateway', 'Update payment gateway settings.', 'WooCommerce - Gateways', 1);
	
	// WooCommerce System
	$tools[] = array('wc_get_system_status', 'Get WooCommerce system status.', 'WooCommerce - System', 1);
	$tools[] = array('wc_run_system_status_tool', 'Run a system status tool.', 'WooCommerce - System', 1);
	
	// WooCommerce Settings
	$tools[] = array('wc_get_settings', 'Get WooCommerce settings.', 'WooCommerce - Settings', 1);
	$tools[] = array('wc_update_setting_option', 'Update a WooCommerce setting option.', 'WooCommerce - Settings', 1);
	
	// WooCommerce Webhooks
	$tools[] = array('wc_get_webhooks', 'List webhooks.', 'WooCommerce - Webhooks', 1);
	$tools[] = array('wc_create_webhook', 'Create a new webhook.', 'WooCommerce - Webhooks', 1);
	$tools[] = array('wc_update_webhook', 'Update a webhook.', 'WooCommerce - Webhooks', 1);
	$tools[] = array('wc_delete_webhook', 'Delete a webhook.', 'WooCommerce - Webhooks', 1);
	
	foreach ($tools as $tool) {
		$wpdb->insert(
			$table,
			array(
				'tool_name' => $tool[0],
				'tool_description' => $tool[1],
				'category' => $tool[2],
				'enabled' => $tool[3],
				'created_at' => $now,
				'updated_at' => $now,
			),
			array('%s', '%s', '%s', '%d', '%s', '%s')
		);
	}
}

/**
 * Upgrade routine: seed snippet tools and update profiles for existing installs.
 * Runs on plugins_loaded; uses a version flag to run once per upgrade.
 */
function stifli_flex_mcp_upgrade_302() {
	global $wpdb;
	$flag = 'sflmcp_upgrade_302_done';
	if ( get_option( $flag ) ) {
		return;
	}

	$table = $wpdb->prefix . 'sflmcp_tools';
	$now   = current_time( 'mysql', true );

	// Seed snippet tools if missing.
	$snippet_tools = array(
		array( 'snippet_list',       'List code snippets. Supports limit, offset, active filter. Requires WPCode, Code Snippets, or Woody Code Snippets.', 'Snippets', 1 ),
		array( 'snippet_get',        'Get a single code snippet by ID with full details.', 'Snippets', 1 ),
		array( 'snippet_create',     'Create a new code snippet (inactive by default). Requires WPCode, Code Snippets, or Woody Code Snippets.', 'Snippets', 1 ),
		array( 'snippet_update',     'Update an existing code snippet by ID.', 'Snippets', 1 ),
		array( 'snippet_delete',     'Delete a code snippet by ID.', 'Snippets', 1 ),
		array( 'snippet_activate',   'Activate a code snippet by ID.', 'Snippets', 1 ),
		array( 'snippet_deactivate', 'Deactivate a code snippet by ID.', 'Snippets', 1 ),
	);

	foreach ( $snippet_tools as $tool ) {
		$exists = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE tool_name = %s", $tool[0] ) );
		if ( ! $exists ) {
			$wpdb->insert( $table, array(
				'tool_name'        => $tool[0],
				'tool_description' => $tool[1],
				'category'         => $tool[2],
				'enabled'          => $tool[3],
				'created_at'       => $now,
				'updated_at'       => $now,
			), array( '%s', '%s', '%s', '%d', '%s', '%s' ) );
		}
	}

	// Add snippet tools to "WordPress Full Management" profile if missing.
	$profiles_table      = $wpdb->prefix . 'sflmcp_profiles';
	$profile_tools_table = $wpdb->prefix . 'sflmcp_profile_tools';
	$profile_id = $wpdb->get_var( $wpdb->prepare(
		"SELECT id FROM {$profiles_table} WHERE profile_name = %s AND is_system = 1 LIMIT 1",
		'WordPress Full Management'
	) );
	if ( $profile_id ) {
		foreach ( $snippet_tools as $tool ) {
			$in_profile = $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$profile_tools_table} WHERE profile_id = %d AND tool_name = %s",
				$profile_id, $tool[0]
			) );
			if ( ! $in_profile ) {
				$wpdb->insert( $profile_tools_table, array(
					'profile_id' => $profile_id,
					'tool_name'  => $tool[0],
				), array( '%d', '%s' ) );
			}
		}
	}

	update_option( $flag, '1' );
}

function stifli_flex_mcp_seed_system_profiles() {
	global $wpdb;
	$profiles_table = $wpdb->prefix . 'sflmcp_profiles';
	$profile_tools_table = $wpdb->prefix . 'sflmcp_profile_tools';
	
	// Check if system profiles already seeded
	$count = $wpdb->get_var("SELECT COUNT(*) FROM {$profiles_table} WHERE is_system = 1");
	if ($count > 0) {
		return; // Already seeded
	}
	
	$now = current_time('mysql', true);
	
	// Define system profiles with their tools
	$system_profiles = array(
		array(
			'name' => 'WordPress Read Only',
			'description' => 'WordPress read-only tools without write operations or sensitive data',
			'tools' => array(
				// Core
				'mcp_ping',
				// Posts
				'wp_get_posts', 'wp_get_post',
				// Pages
				'wp_get_pages',
				// Comments
				'wp_get_comments',
				// Users (sin user_meta por ser sensible)
				'wp_get_users',
				// Taxonomies
				'wp_get_taxonomies', 'wp_get_terms',
				'wp_get_categories', 'wp_get_tags',
				// Menus
				'wp_get_nav_menus', 'wp_get_menus', 'wp_get_menu',
				// Media
				'wp_get_media', 'wp_get_media_item',
				// Post Types & Revisions
				'wp_get_post_types',
				'wp_get_post_revisions',
				// Plugins & Themes
				'wp_list_plugins',
				'wp_get_themes',
			),
		),
		array(
			'name' => 'WordPress Full Management',
			'description' => 'All WordPress tools (CRUD posts, users, media, plugins, themes, options)',
			'tools' => array(
				// Core
				'mcp_ping',
				// Posts (6)
				'wp_get_posts', 'wp_get_post', 'wp_create_post', 'wp_update_post', 'wp_delete_post',
				// Pages (4)
				'wp_get_pages', 'wp_create_page', 'wp_update_page', 'wp_delete_page',
				// Comments (4)
				'wp_get_comments', 'wp_create_comment', 'wp_update_comment', 'wp_delete_comment',
				// Users (1) - Removed for WordPress.org compliance: wp_create_user, wp_update_user, wp_delete_user
				'wp_get_users',
				// User Meta (3)
				'wp_get_user_meta', 'wp_update_user_meta', 'wp_delete_user_meta',
				// Plugins (1) - Removed: wp_activate_plugin, wp_deactivate_plugin, wp_install_plugin
				'wp_list_plugins',
				// Themes (1) - Removed: wp_install_theme, wp_switch_theme
				'wp_get_themes',
				// Media (6)
				'wp_get_media', 'wp_get_media_item', 'wp_upload_image_from_url', 'wp_upload_image', 'wp_update_media_item', 'wp_delete_media_item',
				// Taxonomies (4)
				'wp_get_taxonomies', 'wp_get_terms', 'wp_create_term', 'wp_delete_term',
				// Categories (4)
				'wp_get_categories', 'wp_create_category', 'wp_update_category', 'wp_delete_category',
				// Tags (4)
				'wp_get_tags', 'wp_create_tag', 'wp_update_tag', 'wp_delete_tag',
				// Menus (4)
				'wp_get_nav_menus', 'wp_get_menus', 'wp_get_menu', 'wp_create_nav_menu',
				// Options (3)
				'wp_get_option', 'wp_update_option', 'wp_delete_option',
				// Post Meta (3)
				'wp_get_post_meta', 'wp_update_post_meta', 'wp_delete_post_meta',
				// Settings (2)
				'wp_get_settings', 'wp_update_settings',
				// Revisions (2)
				'wp_get_post_revisions', 'wp_restore_post_revision',
				// Post Types (1)
				'wp_get_post_types',
				// Site Health (1)
				'wp_get_site_health',
				// Utilities (1) — wp_generate_video excluded by default; enable in Multimedia Settings
				'wp_generate_image',
				// Snippets (7) — requires WPCode or Code Snippets plugin
				'snippet_list', 'snippet_get', 'snippet_create', 'snippet_update', 'snippet_delete', 'snippet_activate', 'snippet_deactivate',
			),
		),
		array(
			'name' => 'WooCommerce Read Only',
			'description' => 'Read-only WooCommerce tools for products, orders, customers, and coupons',
			'tools' => array(
				'mcp_ping',
				'wc_get_products', 'wc_get_product_variations', 'wc_get_product_categories', 'wc_get_product_tags', 'wc_get_product_reviews',
				'wc_get_orders', 'wc_get_order_notes',
				// Removed for WordPress.org compliance: wc_get_customers
				'wc_get_coupons',
				'wc_get_low_stock_products',
				'wc_get_refunds',
				'wc_get_sales_report', 'wc_get_top_sellers_report',
			),
		),
		array(
			'name' => 'WooCommerce Store Management',
			'description' => 'Product, stock, order, and coupon management (without advanced settings)',
			'tools' => array(
				'mcp_ping',
				// Products & Stock
				'wc_get_products', 'wc_create_product', 'wc_update_product', 'wc_delete_product', 'wc_batch_update_products',
				'wc_update_stock', 'wc_get_low_stock_products', 'wc_set_stock_status',
				'wc_get_product_variations', 'wc_create_product_variation', 'wc_update_product_variation', 'wc_delete_product_variation',
				'wc_get_product_categories', 'wc_create_product_category', 'wc_update_product_category', 'wc_delete_product_category',
				'wc_get_product_tags', 'wc_create_product_tag', 'wc_update_product_tag', 'wc_delete_product_tag',
				'wc_get_product_reviews', 'wc_create_product_review', 'wc_update_product_review', 'wc_delete_product_review',
				// Orders & Refunds
				'wc_get_orders', 'wc_create_order', 'wc_update_order', 'wc_delete_order', 'wc_batch_update_orders',
				'wc_get_order_notes', 'wc_create_order_note', 'wc_delete_order_note',
				'wc_create_refund', 'wc_get_refunds', 'wc_delete_refund',
				// Coupons (Customers removed for WordPress.org compliance)
				'wc_get_coupons', 'wc_create_coupon', 'wc_update_coupon', 'wc_delete_coupon',
				// Reports
				'wc_get_sales_report', 'wc_get_top_sellers_report',
			),
		),
		array(
			'name' => 'Complete E-commerce',
			'description' => 'All WooCommerce tools (products, orders, tax, shipping, webhooks)',
			'tools' => array(
				'mcp_ping',
				// All WooCommerce tools (65 total)
				'wc_get_products', 'wc_create_product', 'wc_update_product', 'wc_delete_product', 'wc_batch_update_products',
				'wc_get_product_variations', 'wc_create_product_variation', 'wc_update_product_variation', 'wc_delete_product_variation',
				'wc_get_product_categories', 'wc_create_product_category', 'wc_update_product_category', 'wc_delete_product_category',
				'wc_get_product_tags', 'wc_create_product_tag', 'wc_update_product_tag', 'wc_delete_product_tag',
				'wc_get_product_reviews', 'wc_create_product_review', 'wc_update_product_review', 'wc_delete_product_review',
				'wc_update_stock', 'wc_get_low_stock_products', 'wc_set_stock_status',
				'wc_get_orders', 'wc_create_order', 'wc_update_order', 'wc_delete_order', 'wc_batch_update_orders',
				'wc_get_order_notes', 'wc_create_order_note', 'wc_delete_order_note',
				'wc_create_refund', 'wc_get_refunds', 'wc_delete_refund',
				// Customers removed for WordPress.org compliance
				'wc_get_coupons', 'wc_create_coupon', 'wc_update_coupon', 'wc_delete_coupon',
				'wc_get_sales_report', 'wc_get_top_sellers_report',
				'wc_get_tax_classes', 'wc_get_tax_rates', 'wc_create_tax_rate', 'wc_update_tax_rate', 'wc_delete_tax_rate',
				'wc_get_shipping_zones', 'wc_get_shipping_zone_methods', 'wc_create_shipping_zone', 'wc_update_shipping_zone', 'wc_delete_shipping_zone',
				'wc_get_payment_gateways', 'wc_update_payment_gateway',
				'wc_get_system_status', 'wc_run_system_status_tool',
				'wc_get_settings', 'wc_update_setting_option',
				'wc_get_webhooks', 'wc_create_webhook', 'wc_update_webhook', 'wc_delete_webhook',
			),
		),
		array(
			'name' => 'Complete Site',
			'description' => 'All available tools (WordPress + WooCommerce = 117 tools)',
			'tools' => 'ALL', // Special marker to include all tools
		),
		array(
			'name' => 'Safe Mode',
			'description' => 'Non-sensitive read-only access (without options, settings, user_meta, system status)',
			'tools' => array(
				// Core
				'mcp_ping',
				// WordPress READ operations (sin sensitive)
				'wp_get_posts', 'wp_get_post',
				'wp_get_pages',
				'wp_get_comments',
				'wp_get_users',
				'wp_get_taxonomies', 'wp_get_terms',
				'wp_get_categories', 'wp_get_tags',
				'wp_get_nav_menus', 'wp_get_menus', 'wp_get_menu',
				'wp_get_media', 'wp_get_media_item',
				'wp_get_post_types',
				'wp_get_post_revisions',
				'wp_list_plugins',
				'wp_get_themes',
				// WooCommerce READ operations (sin sensitive)
				'wc_get_products', 'wc_get_product_variations',
				'wc_get_product_categories', 'wc_get_product_tags',
				'wc_get_product_reviews',
				'wc_get_orders', 'wc_get_order_notes',
				// Removed for WordPress.org compliance: wc_get_customers
				'wc_get_coupons',
				'wc_get_low_stock_products',
				'wc_get_refunds',
				'wc_get_sales_report', 'wc_get_top_sellers_report',
				'wc_get_tax_classes', 'wc_get_tax_rates',
				'wc_get_shipping_zones', 'wc_get_shipping_zone_methods',
				'wc_get_payment_gateways',
				'wc_get_webhooks',
			),
		),
		array(
			'name' => 'Development/Debug',
			'description' => 'Diagnostic and site configuration tools',
			'tools' => array(
				'mcp_ping',
				'wp_get_site_health',
				'wp_get_post_types',
				'wp_get_settings',
				'wp_get_option',
				'wp_list_plugins',
				'wp_get_themes',
				'wc_get_system_status',
				'wc_get_settings',
				'wc_get_tax_classes', 'wc_get_tax_rates',
				'wc_get_shipping_zones',
				'wc_get_payment_gateways',
				// Snippets (read)
				'snippet_list', 'snippet_get',
			),
		),
	);
	
	// Get all available tools for "Complete Site" profile
	$all_tools = $wpdb->get_col("SELECT tool_name FROM {$wpdb->prefix}sflmcp_tools");
	
	// Insert profiles
	foreach ($system_profiles as $profile) {
		// Set "WordPress Full Management" as the default active profile
		$is_active = ($profile['name'] === 'WordPress Full Management') ? 1 : 0;
		
		$wpdb->insert(
			$profiles_table,
			array(
				'profile_name' => $profile['name'],
				'profile_description' => $profile['description'],
				'is_system' => 1,
				'is_active' => $is_active,
				'created_at' => $now,
				'updated_at' => $now,
			),
			array('%s', '%s', '%d', '%d', '%s', '%s')
		);
		
		$profile_id = $wpdb->insert_id;
		
		// Get tools list
		$tools = ($profile['tools'] === 'ALL') ? $all_tools : $profile['tools'];
		
		// Insert profile tools
		foreach ($tools as $tool_name) {
			$wpdb->insert(
				$profile_tools_table,
				array(
					'profile_id' => $profile_id,
					'tool_name' => $tool_name,
					'created_at' => $now,
				),
				array('%d', '%s', '%s')
			);
		}
	}
	
	// Apply the default active profile (WordPress Full Management)
	// This disables tools not in the profile (like WooCommerce tools)
	stifli_flex_mcp_apply_active_profile();
}

/**
 * Apply the currently active profile to the tools table.
 * Disables all tools, then enables only those in the active profile.
 */
function stifli_flex_mcp_apply_active_profile() {
	global $wpdb;
	
	$profiles_table = $wpdb->prefix . 'sflmcp_profiles';
	$profile_tools_table = $wpdb->prefix . 'sflmcp_profile_tools';
	$tools_table = $wpdb->prefix . 'sflmcp_tools';
	
	// Get active profile ID
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- profile lookup for activation.
	$active_profile_id = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT id FROM {$profiles_table} WHERE is_active = %d LIMIT 1",
			1
		)
	);
	
	if (!$active_profile_id) {
		return; // No active profile, leave all tools enabled
	}
	
	// Get tools in the active profile
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- profile tools lookup.
	$profile_tools = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT tool_name FROM {$profile_tools_table} WHERE profile_id = %d",
			$active_profile_id
		)
	);
	
	if (empty($profile_tools)) {
		return; // No tools in profile, leave all enabled
	}
	
	// Disable all tools first
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- bulk update for profile application.
	$wpdb->query(
		$wpdb->prepare("UPDATE {$tools_table} SET enabled = %d", 0)
	);
	
	// Enable only tools in the active profile
	$placeholders = implode(',', array_fill(0, count($profile_tools), '%s'));
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name from helper is safe; placeholders are dynamically generated from array count.
	$wpdb->query(
		$wpdb->prepare(
			"UPDATE {$tools_table} SET enabled = 1 WHERE tool_name IN ({$placeholders})",
			...$profile_tools
		)
	);
}

if (!function_exists('stifli_flex_mcp_maybe_create_custom_tools_table')) {
	function stifli_flex_mcp_maybe_create_custom_tools_table() {
		global $wpdb;
		$table_name = StifliFlexMcpUtils::getPrefixedTable('sflmcp_custom_tools', false);
		$table_safe = StifliFlexMcpUtils::getPrefixedTable('sflmcp_custom_tools');
		$charset_collate = $wpdb->get_charset_collate();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- schema check for plugin-managed table.
		if ($wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table_name ) ) ) !== $table_name) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- CREATE TABLE for plugin-managed table.
			$sql = "CREATE TABLE $table_safe (
				id bigint(20) NOT NULL AUTO_INCREMENT,
				tool_name varchar(100) NOT NULL,
				tool_description text NOT NULL,
				method varchar(10) DEFAULT 'POST' NOT NULL,
				endpoint text NOT NULL,
				headers text,
				arguments text,
				enabled tinyint(1) DEFAULT 1 NOT NULL,
				created_at datetime DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				UNIQUE KEY tool_name (tool_name)
			) $charset_collate;";

			require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
			dbDelta($sql);
		}
	}
}

function stifli_flex_mcp_seed_custom_tools_examples() {
	global $wpdb;
	$table = $wpdb->prefix . 'sflmcp_custom_tools';
	
	// Check if already seeded (any row exists)
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-managed table, table name is safe.
	$count = $wpdb->get_var( "SELECT COUNT(*) FROM `$table`" );
	if ( $count > 0 ) {
		return;
	}
	
	$site_url = site_url();
	
	$examples = array(
		// ============================================================
		// TYPE 1: External REST APIs
		// ============================================================
		
		// External Example: Public API (Weather)
		array(
			'tool_name' => 'custom_get_weather',
			'tool_description' => 'Get current weather for a location using wttr.in public API. Returns temperature, conditions, etc.',
			'method' => 'GET',
			'endpoint' => 'https://wttr.in/{location}?format=j1',
			'headers' => '',
			'arguments' => json_encode(array(
				'type' => 'object',
				'properties' => array(
					'location' => array('type' => 'string', 'description' => 'City name (e.g. London, Madrid, New York)')
				),
				'required' => array('location')
			)),
			'enabled' => 0
		),
		
		// External Example: Public IP/Geolocation
		array(
			'tool_name' => 'custom_get_ip_info',
			'tool_description' => 'Get geolocation info for an IP address or the server IP if not specified.',
			'method' => 'GET',
			'endpoint' => 'https://ipapi.co/{ip}/json/',
			'headers' => '',
			'arguments' => json_encode(array(
				'type' => 'object',
				'properties' => array(
					'ip' => array('type' => 'string', 'description' => 'IP address to lookup (leave empty for server IP)')
				),
				'required' => array()
			)),
			'enabled' => 0
		),

		// ============================================================
		// TYPE 2: Webhooks / Automation Platforms
		// ============================================================
		
		// Webhook Example: Zapier/n8n/Make
		array(
			'tool_name' => 'custom_trigger_workflow',
			'tool_description' => 'Trigger an external automation workflow (Zapier, n8n, Make) via webhook POST.',
			'method' => 'POST',
			'endpoint' => 'https://hooks.zapier.com/hooks/catch/YOUR_ID/YOUR_HOOK/',
			'headers' => "Content-Type: application/json",
			'arguments' => json_encode(array(
				'type' => 'object',
				'properties' => array(
					'event_type' => array('type' => 'string', 'description' => 'Type of event (e.g. new_lead, order_placed)'),
					'data' => array('type' => 'string', 'description' => 'JSON string with event data')
				),
				'required' => array('event_type')
			)),
			'enabled' => 0
		),

		// ============================================================
		// TYPE 3: Internal WordPress REST API
		// ============================================================
		
		// Internal: Advanced Post Search with filters
		array(
			'tool_name' => 'custom_wp_search',
			'tool_description' => 'Search posts using WordPress REST API with advanced filters (status, category, author).',
			'method' => 'GET',
			'endpoint' => $site_url . '/wp-json/wp/v2/posts?search={term}&per_page={limit}&status={status}',
			'headers' => '',
			'arguments' => json_encode(array(
				'type' => 'object',
				'properties' => array(
					'term' => array('type' => 'string', 'description' => 'Search keyword'),
					'limit' => array('type' => 'integer', 'description' => 'Number of results (default 10)'),
					'status' => array('type' => 'string', 'description' => 'Post status: publish, draft, pending')
				),
				'required' => array('term')
			)),
			'enabled' => 0
		),
		
		// Internal: Get Page with SEO data (works with Yoast, RankMath, etc.)
		array(
			'tool_name' => 'custom_get_page_seo',
			'tool_description' => 'Get page details with SEO metadata. If Yoast/RankMath installed, includes yoast_head or rank_math fields.',
			'method' => 'GET',
			'endpoint' => $site_url . '/wp-json/wp/v2/pages/{id}',
			'headers' => '',
			'arguments' => json_encode(array(
				'type' => 'object',
				'properties' => array(
					'id' => array('type' => 'integer', 'description' => 'Page ID')
				),
				'required' => array('id')
			)),
			'enabled' => 0
		),
		
		// Internal: Get Site Info
		array(
			'tool_name' => 'custom_get_site_info',
			'tool_description' => 'Get WordPress site information (name, description, URL, timezone, etc.).',
			'method' => 'GET',
			'endpoint' => $site_url . '/wp-json/',
			'headers' => '',
			'arguments' => json_encode(array(
				'type' => 'object',
				'properties' => array(),
				'required' => array()
			)),
			'enabled' => 0
		),
		
		// ============================================================
		// TYPE 4: Plugin-Specific REST APIs
		// Note: These require plugin-specific authentication or permissions
		// ============================================================
		
		// Jetpack: Get Site Stats (if Jetpack connected)
		array(
			'tool_name' => 'custom_jetpack_stats',
			'tool_description' => 'Get Jetpack site statistics summary. Requires Jetpack plugin connected.',
			'method' => 'GET',
			'endpoint' => $site_url . '/wp-json/jetpack/v4/module/stats',
			'headers' => '',
			'arguments' => json_encode(array(
				'type' => 'object',
				'properties' => array(),
				'required' => array()
			)),
			'enabled' => 0
		),

		// ============================================================
		// TYPE 6: WordPress Actions (Internal PHP Hooks)
		// Use method = "ACTION" to execute do_action()
		// These call REAL WordPress/plugin actions - no prefix needed
		// ============================================================
		
		// WordPress Core: Flush Rewrite Rules
		array(
			'tool_name' => 'custom_flush_rewrites',
			'tool_description' => 'Flush WordPress rewrite rules (permalinks). Useful after changing permalink settings or adding custom post types.',
			'method' => 'ACTION',
			'endpoint' => 'flush_rewrite_rules',
			'headers' => '',
			'arguments' => json_encode(array(
				'type' => 'object',
				'properties' => array(),
				'required' => array()
			)),
			'enabled' => 0
		),
		
		// WordPress Core: Trigger Cron
		array(
			'tool_name' => 'custom_run_cron',
			'tool_description' => 'Manually trigger WordPress scheduled tasks (wp-cron). Runs all pending scheduled events.',
			'method' => 'ACTION',
			'endpoint' => 'wp_cron',
			'headers' => '',
			'arguments' => json_encode(array(
				'type' => 'object',
				'properties' => array(),
				'required' => array()
			)),
			'enabled' => 0
		),
		
		// WooCommerce: Cancel Unpaid Orders
		array(
			'tool_name' => 'custom_wc_cancel_unpaid',
			'tool_description' => 'Trigger WooCommerce to cancel unpaid orders that have exceeded the hold time.',
			'method' => 'ACTION',
			'endpoint' => 'woocommerce_cancel_unpaid_orders',
			'headers' => '',
			'arguments' => json_encode(array(
				'type' => 'object',
				'properties' => array(),
				'required' => array()
			)),
			'enabled' => 0
		),
		
		// WooCommerce: Cleanup Sessions
		array(
			'tool_name' => 'custom_wc_cleanup_sessions',
			'tool_description' => 'Trigger WooCommerce session cleanup. Removes expired customer sessions from database.',
			'method' => 'ACTION',
			'endpoint' => 'woocommerce_cleanup_sessions',
			'headers' => '',
			'arguments' => json_encode(array(
				'type' => 'object',
				'properties' => array(),
				'required' => array()
			)),
			'enabled' => 0
		),
		
		// Yoast SEO: Reindex
		array(
			'tool_name' => 'custom_yoast_reindex',
			'tool_description' => 'Trigger Yoast SEO indexable rebuild. Regenerates SEO data for all posts/pages.',
			'method' => 'ACTION',
			'endpoint' => 'wpseo_reindex',
			'headers' => '',
			'arguments' => json_encode(array(
				'type' => 'object',
				'properties' => array(),
				'required' => array()
			)),
			'enabled' => 0
		),
		
		// WP Super Cache: Clear Cache
		array(
			'tool_name' => 'custom_wpsc_clear',
			'tool_description' => 'Clear WP Super Cache. Only works if WP Super Cache plugin is active.',
			'method' => 'ACTION',
			'endpoint' => 'wp_cache_clear_cache',
			'headers' => '',
			'arguments' => json_encode(array(
				'type' => 'object',
				'properties' => array(),
				'required' => array()
			)),
			'enabled' => 0
		),
		
		// W3 Total Cache: Flush All
		array(
			'tool_name' => 'custom_w3tc_flush',
			'tool_description' => 'Flush all W3 Total Cache caches. Only works if W3 Total Cache plugin is active.',
			'method' => 'ACTION',
			'endpoint' => 'w3tc_flush_all',
			'headers' => '',
			'arguments' => json_encode(array(
				'type' => 'object',
				'properties' => array(),
				'required' => array()
			)),
			'enabled' => 0
		),
		
		// WordPress: Update Option (via custom wrapper)
		array(
			'tool_name' => 'custom_maintenance_mode',
			'tool_description' => 'Toggle WordPress maintenance mode on/off using the sflmcp_maintenance_mode action.',
			'method' => 'ACTION',
			'endpoint' => 'sflmcp_maintenance_mode',
			'headers' => '',
			'arguments' => json_encode(array(
				'type' => 'object',
				'properties' => array(
					'enable' => array('type' => 'boolean', 'description' => 'true to enable maintenance mode, false to disable')
				),
				'required' => array('enable')
			)),
			'enabled' => 0
		),
		
		// Custom: Send Admin Notification
		array(
			'tool_name' => 'custom_admin_notify',
			'tool_description' => 'Send an email notification to the site admin with a custom message.',
			'method' => 'ACTION',
			'endpoint' => 'sflmcp_admin_notify',
			'headers' => '',
			'arguments' => json_encode(array(
				'type' => 'object',
				'properties' => array(
					'subject' => array('type' => 'string', 'description' => 'Email subject'),
					'message' => array('type' => 'string', 'description' => 'Email message body')
				),
				'required' => array('subject', 'message')
			)),
			'enabled' => 0
		)
	);
	
	foreach ($examples as $tool) {
		$wpdb->insert(
			$table,
			$tool,
			array('%s', '%s', '%s', '%s', '%s', '%s', '%d')
		);
	}
}

function stifli_flex_mcp_ensure_clean_queue_event() {
	$event = wp_get_scheduled_event('sflmcp_clean_queue');
	if (!$event) {
		wp_schedule_event(time() + HOUR_IN_SECONDS, 'hourly', 'sflmcp_clean_queue');
		return;
	}
	$schedule = isset($event->schedule) ? $event->schedule : '';
	if ('hourly' !== $schedule) {
		$args = (isset($event->args) && is_array($event->args)) ? $event->args : array();
		wp_unschedule_event($event->timestamp, 'sflmcp_clean_queue', $args);
		wp_schedule_event(time() + HOUR_IN_SECONDS, 'hourly', 'sflmcp_clean_queue');
	}
}

function stifli_flex_mcp_ensure_clean_changelog_event() {
	$event = wp_get_scheduled_event('sflmcp_clean_changelog');
	if (!$event) {
		wp_schedule_event(time() + DAY_IN_SECONDS, 'daily', 'sflmcp_clean_changelog');
		return;
	}
	$schedule = isset($event->schedule) ? $event->schedule : '';
	if ('daily' !== $schedule) {
		$args = (isset($event->args) && is_array($event->args)) ? $event->args : array();
		wp_unschedule_event($event->timestamp, 'sflmcp_clean_changelog', $args);
		wp_schedule_event(time() + DAY_IN_SECONDS, 'daily', 'sflmcp_clean_changelog');
	}
}

/**
 * Seed initial event triggers
 */
function stifli_flex_mcp_seed_event_triggers() {
	global $wpdb;
	$table = $wpdb->prefix . 'sflmcp_event_triggers';
	
	// Check if already seeded
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$count = $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
	if ($count > 0) {
		return;
	}
	
	$triggers = array(
		// =====================
		// WordPress - Posts
		// =====================
		array(
			'trigger_id' => 'wp_post_published',
			'trigger_name' => 'Post Published',
			'trigger_description' => 'Fires when a post is published (new or from draft)',
			'hook_name' => 'publish_post',
			'hook_priority' => 10,
			'hook_accepted_args' => 2,
			'category' => 'WordPress - Posts',
			'plugin_required' => null,
			'payload_schema' => json_encode(array('post_id', 'post_title', 'post_content', 'post_excerpt', 'post_author', 'post_type', 'post_url')),
		),
		array(
			'trigger_id' => 'wp_post_updated',
			'trigger_name' => 'Post Updated',
			'trigger_description' => 'Fires when an existing post is updated',
			'hook_name' => 'post_updated',
			'hook_priority' => 10,
			'hook_accepted_args' => 3,
			'category' => 'WordPress - Posts',
			'plugin_required' => null,
			'payload_schema' => json_encode(array('post_id', 'post_title', 'post_before', 'post_after')),
		),
		array(
			'trigger_id' => 'wp_post_trashed',
			'trigger_name' => 'Post Trashed',
			'trigger_description' => 'Fires when a post is moved to trash',
			'hook_name' => 'wp_trash_post',
			'hook_priority' => 10,
			'hook_accepted_args' => 1,
			'category' => 'WordPress - Posts',
			'plugin_required' => null,
			'payload_schema' => json_encode(array('post_id', 'post_title', 'post_type')),
		),
		array(
			'trigger_id' => 'wp_post_deleted',
			'trigger_name' => 'Post Deleted',
			'trigger_description' => 'Fires before a post is permanently deleted',
			'hook_name' => 'before_delete_post',
			'hook_priority' => 10,
			'hook_accepted_args' => 1,
			'category' => 'WordPress - Posts',
			'plugin_required' => null,
			'payload_schema' => json_encode(array('post_id', 'post_title', 'post_type')),
		),
		array(
			'trigger_id' => 'wp_page_published',
			'trigger_name' => 'Page Published',
			'trigger_description' => 'Fires when a page is published',
			'hook_name' => 'publish_page',
			'hook_priority' => 10,
			'hook_accepted_args' => 2,
			'category' => 'WordPress - Posts',
			'plugin_required' => null,
			'payload_schema' => json_encode(array('post_id', 'post_title', 'post_content', 'post_url')),
		),
		array(
			'trigger_id' => 'wp_post_status_changed',
			'trigger_name' => 'Post Status Changed',
			'trigger_description' => 'Fires when post status transitions (draft to publish, etc.)',
			'hook_name' => 'transition_post_status',
			'hook_priority' => 10,
			'hook_accepted_args' => 3,
			'category' => 'WordPress - Posts',
			'plugin_required' => null,
			'payload_schema' => json_encode(array('new_status', 'old_status', 'post_id', 'post_title', 'post_type')),
		),
		
		// =====================
		// WordPress - Users
		// =====================
		array(
			'trigger_id' => 'wp_user_registered',
			'trigger_name' => 'User Registered',
			'trigger_description' => 'Fires when a new user registers',
			'hook_name' => 'user_register',
			'hook_priority' => 10,
			'hook_accepted_args' => 1,
			'category' => 'WordPress - Users',
			'plugin_required' => null,
			'payload_schema' => json_encode(array('user_id', 'user_email', 'user_login', 'user_name', 'user_role')),
		),
		array(
			'trigger_id' => 'wp_user_login',
			'trigger_name' => 'User Logged In',
			'trigger_description' => 'Fires when a user successfully logs in',
			'hook_name' => 'wp_login',
			'hook_priority' => 10,
			'hook_accepted_args' => 2,
			'category' => 'WordPress - Users',
			'plugin_required' => null,
			'payload_schema' => json_encode(array('user_login', 'user_id', 'user_email', 'user_name')),
		),
		array(
			'trigger_id' => 'wp_user_logout',
			'trigger_name' => 'User Logged Out',
			'trigger_description' => 'Fires when a user logs out',
			'hook_name' => 'wp_logout',
			'hook_priority' => 10,
			'hook_accepted_args' => 1,
			'category' => 'WordPress - Users',
			'plugin_required' => null,
			'payload_schema' => json_encode(array('user_id')),
		),
		array(
			'trigger_id' => 'wp_user_login_failed',
			'trigger_name' => 'Login Failed',
			'trigger_description' => 'Fires when a login attempt fails',
			'hook_name' => 'wp_login_failed',
			'hook_priority' => 10,
			'hook_accepted_args' => 1,
			'category' => 'WordPress - Users',
			'plugin_required' => null,
			'payload_schema' => json_encode(array('username')),
		),
		array(
			'trigger_id' => 'wp_user_profile_updated',
			'trigger_name' => 'Profile Updated',
			'trigger_description' => 'Fires when a user profile is updated',
			'hook_name' => 'profile_update',
			'hook_priority' => 10,
			'hook_accepted_args' => 2,
			'category' => 'WordPress - Users',
			'plugin_required' => null,
			'payload_schema' => json_encode(array('user_id', 'user_email', 'user_name', 'old_user_data')),
		),
		array(
			'trigger_id' => 'wp_user_role_changed',
			'trigger_name' => 'User Role Changed',
			'trigger_description' => 'Fires when a user role is changed',
			'hook_name' => 'set_user_role',
			'hook_priority' => 10,
			'hook_accepted_args' => 3,
			'category' => 'WordPress - Users',
			'plugin_required' => null,
			'payload_schema' => json_encode(array('user_id', 'new_role', 'old_roles')),
		),
		array(
			'trigger_id' => 'wp_user_deleted',
			'trigger_name' => 'User Deleted',
			'trigger_description' => 'Fires when a user is deleted',
			'hook_name' => 'delete_user',
			'hook_priority' => 10,
			'hook_accepted_args' => 1,
			'category' => 'WordPress - Users',
			'plugin_required' => null,
			'payload_schema' => json_encode(array('user_id')),
		),
		
		// =====================
		// WordPress - Comments
		// =====================
		array(
			'trigger_id' => 'wp_comment_posted',
			'trigger_name' => 'Comment Posted',
			'trigger_description' => 'Fires when a new comment is posted',
			'hook_name' => 'comment_post',
			'hook_priority' => 10,
			'hook_accepted_args' => 3,
			'category' => 'WordPress - Comments',
			'plugin_required' => null,
			'payload_schema' => json_encode(array('comment_id', 'comment_content', 'comment_author', 'comment_author_email', 'post_id')),
		),
		array(
			'trigger_id' => 'wp_comment_approved',
			'trigger_name' => 'Comment Approved',
			'trigger_description' => 'Fires when a comment is approved',
			'hook_name' => 'comment_approved_comment',
			'hook_priority' => 10,
			'hook_accepted_args' => 1,
			'category' => 'WordPress - Comments',
			'plugin_required' => null,
			'payload_schema' => json_encode(array('comment_id', 'comment_content', 'comment_author', 'post_id')),
		),
		array(
			'trigger_id' => 'wp_comment_spam',
			'trigger_name' => 'Comment Marked Spam',
			'trigger_description' => 'Fires when a comment is marked as spam',
			'hook_name' => 'spam_comment',
			'hook_priority' => 10,
			'hook_accepted_args' => 1,
			'category' => 'WordPress - Comments',
			'plugin_required' => null,
			'payload_schema' => json_encode(array('comment_id')),
		),
		array(
			'trigger_id' => 'wp_comment_status_changed',
			'trigger_name' => 'Comment Status Changed',
			'trigger_description' => 'Fires when a comment status changes',
			'hook_name' => 'transition_comment_status',
			'hook_priority' => 10,
			'hook_accepted_args' => 3,
			'category' => 'WordPress - Comments',
			'plugin_required' => null,
			'payload_schema' => json_encode(array('new_status', 'old_status', 'comment_id')),
		),
		
		// =====================
		// WordPress - Media
		// =====================
		array(
			'trigger_id' => 'wp_media_uploaded',
			'trigger_name' => 'Media Uploaded',
			'trigger_description' => 'Fires when a new media file is uploaded',
			'hook_name' => 'add_attachment',
			'hook_priority' => 10,
			'hook_accepted_args' => 1,
			'category' => 'WordPress - Media',
			'plugin_required' => null,
			'payload_schema' => json_encode(array('attachment_id', 'file_name', 'file_type', 'file_url')),
		),
		array(
			'trigger_id' => 'wp_media_deleted',
			'trigger_name' => 'Media Deleted',
			'trigger_description' => 'Fires when a media file is deleted',
			'hook_name' => 'delete_attachment',
			'hook_priority' => 10,
			'hook_accepted_args' => 1,
			'category' => 'WordPress - Media',
			'plugin_required' => null,
			'payload_schema' => json_encode(array('attachment_id')),
		),
		
		// =====================
		// WordPress - System
		// =====================
		array(
			'trigger_id' => 'wp_plugin_activated',
			'trigger_name' => 'Plugin Activated',
			'trigger_description' => 'Fires when a plugin is activated',
			'hook_name' => 'activated_plugin',
			'hook_priority' => 10,
			'hook_accepted_args' => 2,
			'category' => 'WordPress - System',
			'plugin_required' => null,
			'payload_schema' => json_encode(array('plugin_file', 'network_wide')),
		),
		array(
			'trigger_id' => 'wp_plugin_deactivated',
			'trigger_name' => 'Plugin Deactivated',
			'trigger_description' => 'Fires when a plugin is deactivated',
			'hook_name' => 'deactivated_plugin',
			'hook_priority' => 10,
			'hook_accepted_args' => 2,
			'category' => 'WordPress - System',
			'plugin_required' => null,
			'payload_schema' => json_encode(array('plugin_file', 'network_wide')),
		),
		array(
			'trigger_id' => 'wp_theme_switched',
			'trigger_name' => 'Theme Switched',
			'trigger_description' => 'Fires when the active theme is changed',
			'hook_name' => 'switch_theme',
			'hook_priority' => 10,
			'hook_accepted_args' => 3,
			'category' => 'WordPress - System',
			'plugin_required' => null,
			'payload_schema' => json_encode(array('new_theme', 'old_theme')),
		),
		
		// =====================
		// WooCommerce - Orders
		// =====================
		array(
			'trigger_id' => 'wc_order_created',
			'trigger_name' => 'New Order Created',
			'trigger_description' => 'Fires when a new order is placed',
			'hook_name' => 'woocommerce_new_order',
			'hook_priority' => 10,
			'hook_accepted_args' => 1,
			'category' => 'WooCommerce - Orders',
			'plugin_required' => 'woocommerce',
			'payload_schema' => json_encode(array('order_id', 'order_number', 'order_total', 'customer_email', 'customer_name', 'items_count')),
		),
		array(
			'trigger_id' => 'wc_order_status_changed',
			'trigger_name' => 'Order Status Changed',
			'trigger_description' => 'Fires when an order status changes',
			'hook_name' => 'woocommerce_order_status_changed',
			'hook_priority' => 10,
			'hook_accepted_args' => 4,
			'category' => 'WooCommerce - Orders',
			'plugin_required' => 'woocommerce',
			'payload_schema' => json_encode(array('order_id', 'old_status', 'new_status', 'order_total', 'customer_email')),
		),
		array(
			'trigger_id' => 'wc_order_completed',
			'trigger_name' => 'Order Completed',
			'trigger_description' => 'Fires when an order is marked complete',
			'hook_name' => 'woocommerce_order_status_completed',
			'hook_priority' => 10,
			'hook_accepted_args' => 1,
			'category' => 'WooCommerce - Orders',
			'plugin_required' => 'woocommerce',
			'payload_schema' => json_encode(array('order_id', 'order_number', 'order_total', 'customer_email', 'customer_name')),
		),
		array(
			'trigger_id' => 'wc_order_processing',
			'trigger_name' => 'Order Processing',
			'trigger_description' => 'Fires when an order status changes to processing',
			'hook_name' => 'woocommerce_order_status_processing',
			'hook_priority' => 10,
			'hook_accepted_args' => 1,
			'category' => 'WooCommerce - Orders',
			'plugin_required' => 'woocommerce',
			'payload_schema' => json_encode(array('order_id', 'order_number', 'order_total', 'customer_email')),
		),
		array(
			'trigger_id' => 'wc_order_cancelled',
			'trigger_name' => 'Order Cancelled',
			'trigger_description' => 'Fires when an order is cancelled',
			'hook_name' => 'woocommerce_order_status_cancelled',
			'hook_priority' => 10,
			'hook_accepted_args' => 1,
			'category' => 'WooCommerce - Orders',
			'plugin_required' => 'woocommerce',
			'payload_schema' => json_encode(array('order_id', 'order_number', 'customer_email')),
		),
		array(
			'trigger_id' => 'wc_order_refunded',
			'trigger_name' => 'Order Refunded',
			'trigger_description' => 'Fires when an order is refunded',
			'hook_name' => 'woocommerce_order_refunded',
			'hook_priority' => 10,
			'hook_accepted_args' => 2,
			'category' => 'WooCommerce - Orders',
			'plugin_required' => 'woocommerce',
			'payload_schema' => json_encode(array('order_id', 'refund_id')),
		),
		array(
			'trigger_id' => 'wc_payment_complete',
			'trigger_name' => 'Payment Complete',
			'trigger_description' => 'Fires when payment is received for an order',
			'hook_name' => 'woocommerce_payment_complete',
			'hook_priority' => 10,
			'hook_accepted_args' => 1,
			'category' => 'WooCommerce - Orders',
			'plugin_required' => 'woocommerce',
			'payload_schema' => json_encode(array('order_id', 'order_total', 'payment_method')),
		),
		
		// =====================
		// WooCommerce - Products
		// =====================
		array(
			'trigger_id' => 'wc_product_created',
			'trigger_name' => 'Product Created',
			'trigger_description' => 'Fires when a new product is created',
			'hook_name' => 'woocommerce_new_product',
			'hook_priority' => 10,
			'hook_accepted_args' => 1,
			'category' => 'WooCommerce - Products',
			'plugin_required' => 'woocommerce',
			'payload_schema' => json_encode(array('product_id', 'product_name', 'product_price', 'product_sku')),
		),
		array(
			'trigger_id' => 'wc_product_updated',
			'trigger_name' => 'Product Updated',
			'trigger_description' => 'Fires when a product is updated',
			'hook_name' => 'woocommerce_update_product',
			'hook_priority' => 10,
			'hook_accepted_args' => 1,
			'category' => 'WooCommerce - Products',
			'plugin_required' => 'woocommerce',
			'payload_schema' => json_encode(array('product_id', 'product_name', 'product_price')),
		),
		array(
			'trigger_id' => 'wc_product_stock_changed',
			'trigger_name' => 'Product Stock Changed',
			'trigger_description' => 'Fires when product stock quantity changes',
			'hook_name' => 'woocommerce_product_set_stock',
			'hook_priority' => 10,
			'hook_accepted_args' => 1,
			'category' => 'WooCommerce - Products',
			'plugin_required' => 'woocommerce',
			'payload_schema' => json_encode(array('product_id', 'product_name', 'stock_quantity')),
		),
		array(
			'trigger_id' => 'wc_product_low_stock',
			'trigger_name' => 'Product Low Stock',
			'trigger_description' => 'Fires when product reaches low stock threshold',
			'hook_name' => 'woocommerce_low_stock',
			'hook_priority' => 10,
			'hook_accepted_args' => 1,
			'category' => 'WooCommerce - Products',
			'plugin_required' => 'woocommerce',
			'payload_schema' => json_encode(array('product_id', 'product_name', 'stock_quantity')),
		),
		array(
			'trigger_id' => 'wc_product_out_of_stock',
			'trigger_name' => 'Product Out of Stock',
			'trigger_description' => 'Fires when a product runs out of stock',
			'hook_name' => 'woocommerce_no_stock',
			'hook_priority' => 10,
			'hook_accepted_args' => 1,
			'category' => 'WooCommerce - Products',
			'plugin_required' => 'woocommerce',
			'payload_schema' => json_encode(array('product_id', 'product_name')),
		),
		
		// =====================
		// WooCommerce - Customers
		// =====================
		array(
			'trigger_id' => 'wc_customer_created',
			'trigger_name' => 'Customer Created',
			'trigger_description' => 'Fires when a new WooCommerce customer is created',
			'hook_name' => 'woocommerce_created_customer',
			'hook_priority' => 10,
			'hook_accepted_args' => 3,
			'category' => 'WooCommerce - Customers',
			'plugin_required' => 'woocommerce',
			'payload_schema' => json_encode(array('customer_id', 'customer_email', 'customer_name')),
		),
		
		// =====================
		// WooCommerce - Cart
		// =====================
		array(
			'trigger_id' => 'wc_add_to_cart',
			'trigger_name' => 'Product Added to Cart',
			'trigger_description' => 'Fires when a product is added to cart',
			'hook_name' => 'woocommerce_add_to_cart',
			'hook_priority' => 10,
			'hook_accepted_args' => 6,
			'category' => 'WooCommerce - Cart',
			'plugin_required' => 'woocommerce',
			'payload_schema' => json_encode(array('cart_item_key', 'product_id', 'quantity')),
		),
		array(
			'trigger_id' => 'wc_checkout_complete',
			'trigger_name' => 'Checkout Complete',
			'trigger_description' => 'Fires when checkout is processed',
			'hook_name' => 'woocommerce_checkout_order_processed',
			'hook_priority' => 10,
			'hook_accepted_args' => 3,
			'category' => 'WooCommerce - Cart',
			'plugin_required' => 'woocommerce',
			'payload_schema' => json_encode(array('order_id', 'posted_data')),
		),
		array(
			'trigger_id' => 'wc_coupon_applied',
			'trigger_name' => 'Coupon Applied',
			'trigger_description' => 'Fires when a coupon is applied to cart',
			'hook_name' => 'woocommerce_applied_coupon',
			'hook_priority' => 10,
			'hook_accepted_args' => 1,
			'category' => 'WooCommerce - Cart',
			'plugin_required' => 'woocommerce',
			'payload_schema' => json_encode(array('coupon_code')),
		),
		
		// =====================
		// Forms - Contact Form 7
		// =====================
		array(
			'trigger_id' => 'cf7_form_submitted',
			'trigger_name' => 'Contact Form 7 Submitted',
			'trigger_description' => 'Fires when a CF7 form is submitted and email sent',
			'hook_name' => 'wpcf7_mail_sent',
			'hook_priority' => 10,
			'hook_accepted_args' => 1,
			'category' => 'Forms - Contact Form 7',
			'plugin_required' => 'contact-form-7',
			'payload_schema' => json_encode(array('form_id', 'form_title', 'posted_data')),
		),
		
		// =====================
		// Forms - Gravity Forms
		// =====================
		array(
			'trigger_id' => 'gf_form_submitted',
			'trigger_name' => 'Gravity Form Submitted',
			'trigger_description' => 'Fires after a Gravity Form is submitted',
			'hook_name' => 'gform_after_submission',
			'hook_priority' => 10,
			'hook_accepted_args' => 2,
			'category' => 'Forms - Gravity Forms',
			'plugin_required' => 'gravityforms',
			'payload_schema' => json_encode(array('entry', 'form')),
		),
		
		// =====================
		// Forms - WPForms
		// =====================
		array(
			'trigger_id' => 'wpforms_submitted',
			'trigger_name' => 'WPForms Submitted',
			'trigger_description' => 'Fires after a WPForms form is submitted',
			'hook_name' => 'wpforms_process_complete',
			'hook_priority' => 10,
			'hook_accepted_args' => 4,
			'category' => 'Forms - WPForms',
			'plugin_required' => 'wpforms',
			'payload_schema' => json_encode(array('fields', 'entry', 'form_data')),
		),
	);
	
	foreach ($triggers as $trigger) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert($table, $trigger);
	}
}

/**
 * Create abilities table for WordPress 6.9+ Abilities API integration.
 * This table stores abilities imported from other plugins.
 */
function stifli_flex_mcp_maybe_create_abilities_table() {
	global $wpdb;
	$table_name = $wpdb->prefix . 'sflmcp_abilities';
	$charset_collate = $wpdb->get_charset_collate();

	$like = $wpdb->esc_like($table_name);
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- schema check.
	if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $like)) === $table_name) {
		return;
	}

	$sql = "CREATE TABLE $table_name (
		id bigint(20) NOT NULL AUTO_INCREMENT,
		ability_name varchar(191) NOT NULL,
		ability_label varchar(255) NOT NULL,
		ability_description text,
		ability_category varchar(100) DEFAULT 'abilities',
		input_schema longtext,
		output_schema longtext,
		enabled tinyint(1) DEFAULT 1 NOT NULL,
		created_at datetime DEFAULT CURRENT_TIMESTAMP,
		updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
		PRIMARY KEY  (id),
		UNIQUE KEY ability_name (ability_name),
		KEY enabled (enabled),
		KEY ability_category (ability_category)
	) $charset_collate;";

	require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
	dbDelta($sql);
}

/**
 * Check if WordPress Abilities API is available (WordPress 6.9+)
 */
function stifli_flex_mcp_abilities_available() {
	return function_exists('wp_get_abilities');
}

// ============================================================
// Automation Tasks Tables
// ============================================================

/**
 * Create automation tasks table
 */
function stifli_flex_mcp_maybe_create_automation_tasks_table() {
	global $wpdb;
	$table_name = $wpdb->prefix . 'sflmcp_automation_tasks';
	$charset_collate = $wpdb->get_charset_collate();

	$like = $wpdb->esc_like($table_name);
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$table_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $like)) === $table_name;

	if ( $table_exists ) {
		// Migration: add columns if missing (for sites that already had the table).
		$columns = $wpdb->get_col( "SHOW COLUMNS FROM $table_name", 0 ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL
		if ( is_array( $columns ) && ! in_array( 'last_success', $columns, true ) ) {
			$wpdb->query( "ALTER TABLE $table_name ADD COLUMN last_success datetime DEFAULT NULL AFTER last_run" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL
		}
		if ( is_array( $columns ) && ! in_array( 'last_error', $columns, true ) ) {
			$wpdb->query( "ALTER TABLE $table_name ADD COLUMN last_error text DEFAULT NULL AFTER last_success" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL
		}
		if ( is_array( $columns ) && ! in_array( 'token_budget_monthly', $columns, true ) ) {
			$wpdb->query( "ALTER TABLE $table_name ADD COLUMN token_budget_monthly int(11) DEFAULT 0 AFTER max_retries" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL
		}
		return;
	}

	$sql = "CREATE TABLE $table_name (
		id bigint(20) NOT NULL AUTO_INCREMENT,
		task_name varchar(255) NOT NULL,
		task_description text,
		prompt longtext NOT NULL,
		system_prompt text,
		provider varchar(50) DEFAULT 'default',
		model varchar(100) DEFAULT '',
		allowed_tools text,
		schedule_preset varchar(50) DEFAULT 'daily_morning',
		schedule_time varchar(10) DEFAULT '08:00',
		schedule_timezone varchar(100) DEFAULT 'UTC',
		output_action varchar(50) DEFAULT 'log',
		output_config text,
		status varchar(20) DEFAULT 'draft',
		next_run datetime DEFAULT NULL,
		last_run datetime DEFAULT NULL,
		last_success datetime DEFAULT NULL,
		last_error text DEFAULT NULL,
		retry_count int(11) DEFAULT 0,
		max_retries int(11) DEFAULT 3,
		token_budget_monthly int(11) DEFAULT 0,
		created_by bigint(20) DEFAULT 0,
		created_at datetime DEFAULT CURRENT_TIMESTAMP,
		updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
		PRIMARY KEY  (id),
		KEY status (status),
		KEY next_run (next_run),
		KEY schedule_preset (schedule_preset)
	) $charset_collate;";

	require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
	dbDelta($sql);
}

/**
 * Create automation logs table
 */
function stifli_flex_mcp_maybe_create_automation_logs_table() {
	global $wpdb;
	$table_name = $wpdb->prefix . 'sflmcp_automation_logs';
	$charset_collate = $wpdb->get_charset_collate();

	$like = $wpdb->esc_like($table_name);
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$table_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $like)) === $table_name;

	// If table exists, check for missing columns and add them
	if ($table_exists) {
		// Check for prompt_used column
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$columns = $wpdb->get_results("SHOW COLUMNS FROM {$table_name}");
		$column_names = array_map(function($col) { return $col->Field; }, $columns);

		if (!in_array('prompt_used', $column_names, true)) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- schema migration for plugin-managed table.
			$wpdb->query("ALTER TABLE {$table_name} ADD COLUMN prompt_used longtext AFTER error_message");
		}
		if (!in_array('tools_results', $column_names, true)) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- schema migration for plugin-managed table.
			$wpdb->query("ALTER TABLE {$table_name} ADD COLUMN tools_results longtext AFTER tools_called");
		}
		return;
	}

	$sql = "CREATE TABLE $table_name (
		id bigint(20) NOT NULL AUTO_INCREMENT,
		task_id bigint(20) NOT NULL,
		started_at datetime DEFAULT CURRENT_TIMESTAMP,
		completed_at datetime DEFAULT NULL,
		status varchar(20) DEFAULT 'running',
		prompt_used longtext,
		ai_response longtext,
		tools_called text,
		tools_results longtext,
		tokens_input int(11) DEFAULT 0,
		tokens_output int(11) DEFAULT 0,
		execution_time_ms int(11) DEFAULT 0,
		error_message text,
		PRIMARY KEY  (id),
		KEY task_id (task_id),
		KEY status (status),
		KEY started_at (started_at)
	) $charset_collate;";

	require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
	dbDelta($sql);
}

/**
 * Create automation templates table
 */
function stifli_flex_mcp_maybe_create_automation_templates_table() {
	global $wpdb;
	$table_name = $wpdb->prefix . 'sflmcp_automation_templates';
	$charset_collate = $wpdb->get_charset_collate();

	$like = $wpdb->esc_like($table_name);
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $like)) === $table_name) {
		return;
	}

	$sql = "CREATE TABLE $table_name (
		id bigint(20) NOT NULL AUTO_INCREMENT,
		template_name varchar(255) NOT NULL,
		template_slug varchar(100) NOT NULL,
		template_description text,
		category varchar(100) DEFAULT 'general',
		icon varchar(100) DEFAULT 'dashicons-clock',
		default_prompt longtext,
		default_system_prompt text,
		suggested_tools text,
		suggested_schedule varchar(50) DEFAULT 'daily_morning',
		is_system tinyint(1) DEFAULT 0,
		created_at datetime DEFAULT CURRENT_TIMESTAMP,
		PRIMARY KEY  (id),
		UNIQUE KEY template_slug (template_slug),
		KEY category (category),
		KEY is_system (is_system)
	) $charset_collate;";

	require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
	dbDelta($sql);
}

/**
 * Create event triggers table (catalog of available triggers)
 */
function stifli_flex_mcp_maybe_create_event_triggers_table() {
	global $wpdb;
	$table_name = $wpdb->prefix . 'sflmcp_event_triggers';
	$charset_collate = $wpdb->get_charset_collate();

	$like = $wpdb->esc_like($table_name);
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $like)) === $table_name) {
		return;
	}

	$sql = "CREATE TABLE $table_name (
		id bigint(20) NOT NULL AUTO_INCREMENT,
		trigger_id varchar(100) NOT NULL,
		trigger_name varchar(200) NOT NULL,
		trigger_description text,
		hook_name varchar(200) NOT NULL,
		hook_priority int(11) DEFAULT 10,
		hook_accepted_args int(11) DEFAULT 1,
		category varchar(100) NOT NULL,
		plugin_required varchar(100) DEFAULT NULL,
		payload_schema longtext,
		is_active tinyint(1) DEFAULT 1,
		is_system tinyint(1) DEFAULT 1,
		created_at datetime DEFAULT CURRENT_TIMESTAMP,
		PRIMARY KEY  (id),
		UNIQUE KEY trigger_id (trigger_id),
		KEY category (category),
		KEY plugin_required (plugin_required),
		KEY is_active (is_active)
	) $charset_collate;";

	require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
	dbDelta($sql);
}

/**
 * Create event automations table (user configurations)
 */
function stifli_flex_mcp_maybe_create_event_automations_table() {
	global $wpdb;
	$table_name = $wpdb->prefix . 'sflmcp_event_automations';
	$charset_collate = $wpdb->get_charset_collate();

	$like = $wpdb->esc_like($table_name);
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$table_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $like)) === $table_name;

	// Always run migration for existing tables
	if ($table_exists) {
		stifli_flex_mcp_maybe_add_output_columns();
		return;
	}

	$sql = "CREATE TABLE $table_name (
		id bigint(20) NOT NULL AUTO_INCREMENT,
		automation_name varchar(200) NOT NULL,
		trigger_id varchar(100) NOT NULL,
		conditions longtext,
		prompt longtext NOT NULL,
		system_prompt text,
		tools_enabled longtext,
		provider varchar(50) DEFAULT NULL,
		model varchar(100) DEFAULT NULL,
		max_tokens int(11) DEFAULT 2000,
		output_email tinyint(1) DEFAULT 0,
		email_recipients text,
		email_subject varchar(255) DEFAULT NULL,
		output_webhook tinyint(1) DEFAULT 0,
		webhook_url varchar(500) DEFAULT NULL,
		webhook_preset varchar(50) DEFAULT 'custom',
		output_draft tinyint(1) DEFAULT 0,
		draft_post_type varchar(50) DEFAULT 'post',
		status enum('active','paused','error','draft') DEFAULT 'draft',
		run_count int(11) DEFAULT 0,
		last_run datetime DEFAULT NULL,
		last_error text DEFAULT NULL,
		created_by bigint(20) UNSIGNED DEFAULT NULL,
		created_at datetime DEFAULT CURRENT_TIMESTAMP,
		updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
		PRIMARY KEY  (id),
		KEY trigger_id (trigger_id),
		KEY status (status),
		KEY created_by (created_by)
	) $charset_collate;";

	require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
	dbDelta($sql);
}

/**
 * Add output action columns to existing event_automations table
 */
function stifli_flex_mcp_maybe_add_output_columns() {
	global $wpdb;
	$table_name = $wpdb->prefix . 'sflmcp_event_automations';

	// Check if output_email column exists
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$column_exists = $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'output_email'",
		DB_NAME,
		$table_name
	) );

	if ( ! $column_exists ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- schema migration for plugin-managed table.
		$wpdb->query( "ALTER TABLE {$table_name} 
			ADD COLUMN output_email tinyint(1) DEFAULT 0 AFTER max_tokens,
			ADD COLUMN email_recipients text AFTER output_email,
			ADD COLUMN email_subject varchar(255) DEFAULT NULL AFTER email_recipients,
			ADD COLUMN output_webhook tinyint(1) DEFAULT 0 AFTER email_subject,
			ADD COLUMN webhook_url varchar(500) DEFAULT NULL AFTER output_webhook,
			ADD COLUMN webhook_preset varchar(50) DEFAULT 'custom' AFTER webhook_url,
			ADD COLUMN output_draft tinyint(1) DEFAULT 0 AFTER webhook_preset,
			ADD COLUMN draft_post_type varchar(50) DEFAULT 'post' AFTER output_draft
		" );
	}
}

/**
 * Create event automation logs table
 */
function stifli_flex_mcp_maybe_create_event_logs_table() {
	global $wpdb;
	$table_name = $wpdb->prefix . 'sflmcp_event_logs';
	$charset_collate = $wpdb->get_charset_collate();

	$like = $wpdb->esc_like($table_name);
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $like)) === $table_name) {
		return;
	}

	$sql = "CREATE TABLE $table_name (
		id bigint(20) NOT NULL AUTO_INCREMENT,
		automation_id bigint(20) NOT NULL,
		trigger_id varchar(100) NOT NULL,
		trigger_payload longtext,
		prompt_sent longtext,
		response longtext,
		tools_executed longtext,
		tokens_used int(11) DEFAULT 0,
		execution_time float DEFAULT 0,
		status enum('success','error','skipped') DEFAULT 'success',
		error_message text DEFAULT NULL,
		created_at datetime DEFAULT CURRENT_TIMESTAMP,
		PRIMARY KEY  (id),
		KEY automation_id (automation_id),
		KEY trigger_id (trigger_id),
		KEY status (status),
		KEY created_at (created_at)
	) $charset_collate;";

	require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
	dbDelta($sql);
}

/**
 * Schedule automation cron event
 */
function stifli_flex_mcp_ensure_automation_cron() {
	// Register custom intervals for automation
	add_filter('cron_schedules', 'stifli_flex_mcp_automation_cron_schedules');
	
	if (!wp_next_scheduled('sflmcp_process_automation_tasks')) {
		wp_schedule_event(time(), 'every_minute', 'sflmcp_process_automation_tasks');
	}
}

/**
 * Add custom cron schedules for automation
 */
function stifli_flex_mcp_automation_cron_schedules($schedules) {
	if (!isset($schedules['every_minute'])) {
		$schedules['every_minute'] = array(
			'interval' => 60,
			'display'  => __('Every Minute', 'stifli-flex-mcp'),
		);
	}
	if (!isset($schedules['every_2_hours'])) {
		$schedules['every_2_hours'] = array(
			'interval' => 7200,
			'display'  => __('Every 2 Hours', 'stifli-flex-mcp'),
		);
	}
	if (!isset($schedules['every_6_hours'])) {
		$schedules['every_6_hours'] = array(
			'interval' => 21600,
			'display'  => __('Every 6 Hours', 'stifli-flex-mcp'),
		);
	}
	return $schedules;
}
add_filter('cron_schedules', 'stifli_flex_mcp_automation_cron_schedules');

/**
 * Process pending automation tasks (cron handler)
 */
add_action('sflmcp_process_automation_tasks', 'stifli_flex_mcp_run_automation_tasks');
function stifli_flex_mcp_run_automation_tasks() {
	// Check if engine class exists
	$engine_file = __DIR__ . '/client/class-automation-engine.php';
	if (!file_exists($engine_file)) {
		return;
	}
	
	require_once $engine_file;
	
	if (class_exists('StifliFlexMcp_Automation_Engine')) {
		$engine = StifliFlexMcp_Automation_Engine::get_instance();
		$engine->process_pending_tasks();
	}
}

register_activation_hook(__FILE__, 'stifli_flex_mcp_activate');
register_deactivation_hook(__FILE__, 'stifli_flex_mcp_deactivate');

function stifli_flex_mcp_activate() {
	stifli_flex_mcp_maybe_create_queue_table();
	stifli_flex_mcp_maybe_create_tools_table();
	stifli_flex_mcp_maybe_create_custom_tools_table();
	stifli_flex_mcp_maybe_create_abilities_table();
	stifli_flex_mcp_maybe_add_tools_token_column();
	stifli_flex_mcp_maybe_create_profiles_table();
	stifli_flex_mcp_maybe_create_profile_tools_table();
	
	// Automation tables
	stifli_flex_mcp_maybe_create_automation_tasks_table();
	stifli_flex_mcp_maybe_create_automation_logs_table();
	stifli_flex_mcp_maybe_create_automation_templates_table();
	
	// Event automations tables
	stifli_flex_mcp_maybe_create_event_triggers_table();
	stifli_flex_mcp_maybe_create_event_automations_table();
	stifli_flex_mcp_maybe_create_event_logs_table();
	stifli_flex_mcp_seed_event_triggers();
	
	stifli_flex_mcp_seed_initial_tools();
	stifli_flex_mcp_seed_custom_tools_examples();
	stifli_flex_mcp_seed_system_profiles();

	// Changelog table (Change Tracker)
	StifliFlexMcp_ChangeTracker::createTable();
	StifliFlexMcp_ChangeTracker::migrateAddSourceColumns();

	// OAuth 2.1 tables
	StifliFlexMcp_OAuth_Storage::create_tables();
	stifli_flex_mcp_sync_tool_token_estimates();
	stifli_flex_mcp_ensure_clean_queue_event();
	stifli_flex_mcp_ensure_clean_changelog_event();
	stifli_flex_mcp_ensure_automation_cron();
	
	// Authentication now uses WordPress Application Passwords (no custom token needed)
}

function stifli_flex_mcp_deactivate() {
	wp_clear_scheduled_hook('sflmcp_clean_queue');
	wp_clear_scheduled_hook('sflmcp_clean_changelog');
	wp_clear_scheduled_hook('sflmcp_process_automation_tasks');
}

add_action('sflmcp_clean_queue', 'stifli_flex_mcp_clean_queue');
add_action('sflmcp_clean_changelog', 'stifli_flex_mcp_clean_changelog');
function stifli_flex_mcp_clean_queue() {
	global $wpdb;
	$table = StifliFlexMcpUtils::getPrefixedTable('sflmcp_queue');
	$now = gmdate('Y-m-d H:i:s');
	$wpdb->query(
		$wpdb->prepare(
			'DELETE FROM ' . $table . ' WHERE expires_at < %s',
			$now
		)
	);

	// Also clean expired OAuth codes and tokens.
	if ( class_exists( 'StifliFlexMcp_OAuth_Storage' ) ) {
		StifliFlexMcp_OAuth_Storage::get_instance()->cleanup_expired();
	}
}

function stifli_flex_mcp_clean_changelog() {
	if ( class_exists( 'StifliFlexMcp_ChangeTracker' ) ) {
		$days = intval( get_option( 'sflmcp_changelog_retention_days', 90 ) );
		if ( $days < 1 ) { $days = 90; }
		StifliFlexMcp_ChangeTracker::getInstance()->purge( $days );
	}
}

/* phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter */

// ============================================================
// Custom Tool Actions - Handlers for sflmcp_* actions
// These provide results for our custom actions. Other actions
// (like woocommerce_cancel_unpaid_orders) are native WP/plugin hooks
// ============================================================

/**
 * Maintenance mode toggle
 * 
 * LIMITATION: Once maintenance mode is enabled, the MCP API becomes inaccessible (WordPress
 * returns 503 before plugins load). You must disable maintenance mode manually by deleting
 * the .maintenance file in WordPress root, or via wp-cli: wp maintenance-mode deactivate
 */
add_action('sflmcp_maintenance_mode', function($args) {
    $enable = isset($args['enable']) ? (bool) $args['enable'] : false;
    $maintenance_file = ABSPATH . '.maintenance';
    
    if ($enable) {
        $content = '<?php $upgrading = ' . time() . '; ?>';
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        file_put_contents($maintenance_file, $content);
    } else {
        if (file_exists($maintenance_file)) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
            unlink($maintenance_file);
        }
    }
});

add_filter('sflmcp_action_result', function($result, $action, $args) {
    if ($action === 'sflmcp_maintenance_mode') {
        $enable = isset($args['enable']) ? (bool) $args['enable'] : false;
        return $enable ? 'Maintenance mode ENABLED. Site now shows maintenance message to visitors. WARNING: MCP API will be inaccessible until maintenance mode is disabled manually.' : 'Maintenance mode DISABLED. Site is now accessible.';
    }
    return $result;
}, 10, 3);

/**
 * Send admin notification email
 */
add_action('sflmcp_admin_notify', function($args) {
    $subject = isset($args['subject']) ? sanitize_text_field($args['subject']) : 'MCP Notification';
    $message = isset($args['message']) ? sanitize_textarea_field($args['message']) : '';
    
    if (empty($message)) {
        return;
    }
    
    $admin_email = get_option('admin_email');
    $full_message = "Notification from StifLi Flex MCP:\n\n" . $message;
    $full_message .= "\n\n---\nSite: " . get_bloginfo('name') . "\nTime: " . current_time('mysql');
    
    wp_mail($admin_email, $subject, $full_message);
});

add_filter('sflmcp_action_result', function($result, $action, $args) {
    if ($action === 'sflmcp_admin_notify') {
        $message = isset($args['message']) ? $args['message'] : '';
        if (empty($message)) {
            return 'Error: Message cannot be empty.';
        }
        return 'Notification sent to admin: ' . get_option('admin_email');
    }
    return $result;
}, 10, 3);

/**
 * Result handlers for native WordPress actions
 */
add_filter('sflmcp_action_result', function($result, $action, $args) {
    switch ($action) {
        case 'flush_rewrite_rules':
            return 'Rewrite rules (permalinks) flushed successfully.';
        case 'wp_cron':
            return 'WordPress cron triggered. Pending scheduled tasks have been executed.';
        case 'woocommerce_cancel_unpaid_orders':
            return 'WooCommerce unpaid orders check completed.';
        case 'woocommerce_cleanup_sessions':
            return 'WooCommerce session cleanup completed.';
        case 'wpseo_reindex':
            return 'Yoast SEO reindex triggered.';
        case 'wp_cache_clear_cache':
            return 'WP Super Cache cleared.';
        case 'w3tc_flush_all':
            return 'W3 Total Cache flushed.';
    }
    return $result;
}, 5, 3);

// Global instance variable to prevent garbage collection
global $stifli_flex_mcp_instance;
global $stifliFlexMcp;

// Plugin initialization
add_action('plugins_loaded', function() {
	global $stifli_flex_mcp_instance;
	global $stifliFlexMcp;
	
	stifli_flex_mcp_maybe_create_queue_table();
	stifli_flex_mcp_maybe_create_tools_table();
	stifli_flex_mcp_maybe_create_custom_tools_table();
	stifli_flex_mcp_maybe_create_abilities_table();
	stifli_flex_mcp_maybe_add_tools_token_column();
	stifli_flex_mcp_maybe_create_profiles_table();
	stifli_flex_mcp_maybe_create_profile_tools_table();
	
	// Automation tables (with migration for existing tables)
	stifli_flex_mcp_maybe_create_automation_tasks_table();
	stifli_flex_mcp_maybe_create_automation_logs_table();
	
	// OAuth 2.1 tables (idempotent - CREATE TABLE IF NOT EXISTS)
	if ( class_exists( 'StifliFlexMcp_OAuth_Storage' ) ) {
		StifliFlexMcp_OAuth_Storage::create_tables();
	}

	// Changelog source columns migration
	if ( class_exists( 'StifliFlexMcp_ChangeTracker' ) ) {
		StifliFlexMcp_ChangeTracker::migrateAddSourceColumns();
	}
	
	stifli_flex_mcp_seed_custom_tools_examples();
	stifli_flex_mcp_seed_initial_tools();
	stifli_flex_mcp_seed_system_profiles();
	stifli_flex_mcp_upgrade_302();
	stifli_flex_mcp_sync_tool_token_estimates();
	stifli_flex_mcp_ensure_clean_queue_event();
	if (class_exists('StifliFlexMcp')) {
		$stifli_flex_mcp_instance = new StifliFlexMcp();
		$stifli_flex_mcp_instance->init();
		
		// Create global reference with model for client
		$stifliFlexMcp = new stdClass();
		$stifliFlexMcp->model = new StifliFlexMcpModel();
	}

	// Initialize OAuth 2.1 Server
	if ( class_exists( 'StifliFlexMcp_OAuth_Server' ) ) {
		StifliFlexMcp_OAuth_Server::get_instance()->init();
	}
	
	// Initialize AI Chat Agent admin
	if (class_exists('StifliFlexMcp_Client_Admin') && is_admin()) {
		new StifliFlexMcp_Client_Admin();
	}
});
