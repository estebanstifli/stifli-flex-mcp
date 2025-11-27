<?php
/*
Plugin Name: StifLi Flex MCP
Plugin URI: https://github.com/estebanstifli/stifli-flex-mcp
Description: Transform your WordPress site into a Model Context Protocol (MCP) server. Expose 124 tools (58 WordPress, 65 WooCommerce, 1 Core) that AI agents like ChatGPT, Claude, and LibreChat can use to manage your WordPress and WooCommerce site via JSON-RPC 2.0.
Version: 1.0.2
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
        if (!defined('SFLMCP_DEBUG') || SFLMCP_DEBUG !== true) {
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
		error_log('[SFLMCP] ' . $message); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- gated by SFLMCP_DEBUG for opt-in debugging only
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
require_once __DIR__ . '/controller.php';
require_once __DIR__ . '/mod.php';

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
		$wpdb->query(
			sprintf(
				'ALTER TABLE %s ADD COLUMN token_estimate INT UNSIGNED NOT NULL DEFAULT 0 AFTER enabled',
				StifliFlexMcpUtils::getPrefixedTable('sflmcp_tools')
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.NotPrepared -- schema migration for plugin-managed table
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
		array('wp_create_user', 'Create a user. Requires user_login, user_email, user_pass.', 'WordPress - Users', 1),
		array('wp_update_user', 'Update a user by ID with fields object.', 'WordPress - Users', 1),
		array('wp_delete_user', 'Delete a user by ID.', 'WordPress - Users', 1),
		
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
	);
	
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
	
	// WooCommerce Customers
	$tools[] = array('wc_get_customers', 'List WooCommerce customers.', 'WooCommerce - Customers', 1);
	$tools[] = array('wc_create_customer', 'Create a new customer.', 'WooCommerce - Customers', 1);
	$tools[] = array('wc_update_customer', 'Update a customer by ID.', 'WooCommerce - Customers', 1);
	$tools[] = array('wc_delete_customer', 'Delete a customer by ID.', 'WooCommerce - Customers', 1);
	
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
				// Users (4)
				'wp_get_users', 'wp_create_user', 'wp_update_user', 'wp_delete_user',
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
			),
		),
		array(
			'name' => 'WooCommerce Read Only',
			'description' => 'Read-only WooCommerce tools for products, orders, customers, and coupons',
			'tools' => array(
				'mcp_ping',
				'wc_get_products', 'wc_get_product_variations', 'wc_get_product_categories', 'wc_get_product_tags', 'wc_get_product_reviews',
				'wc_get_orders', 'wc_get_order_notes',
				'wc_get_customers',
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
				// Customers & Coupons
				'wc_get_customers', 'wc_create_customer', 'wc_update_customer', 'wc_delete_customer',
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
				'wc_get_customers', 'wc_create_customer', 'wc_update_customer', 'wc_delete_customer',
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
			'description' => 'All available tools (WordPress + WooCommerce = 124 tools)',
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
				'wc_get_customers',
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
			),
		),
	);
	
	// Get all available tools for "Complete Site" profile
	$all_tools = $wpdb->get_col("SELECT tool_name FROM {$wpdb->prefix}sflmcp_tools");
	
	// Insert profiles
	foreach ($system_profiles as $profile) {
		$wpdb->insert(
			$profiles_table,
			array(
				'profile_name' => $profile['name'],
				'profile_description' => $profile['description'],
				'is_system' => 1,
				'is_active' => 0,
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

register_activation_hook(__FILE__, 'stifli_flex_mcp_activate');
register_deactivation_hook(__FILE__, 'stifli_flex_mcp_deactivate');

function stifli_flex_mcp_activate() {
	stifli_flex_mcp_maybe_create_queue_table();
	stifli_flex_mcp_maybe_create_tools_table();
	stifli_flex_mcp_maybe_add_tools_token_column();
	stifli_flex_mcp_maybe_create_profiles_table();
	stifli_flex_mcp_maybe_create_profile_tools_table();
	stifli_flex_mcp_seed_initial_tools();
	stifli_flex_mcp_seed_system_profiles();
	stifli_flex_mcp_sync_tool_token_estimates();
	stifli_flex_mcp_ensure_clean_queue_event();
}

function stifli_flex_mcp_deactivate() {
	wp_clear_scheduled_hook('sflmcp_clean_queue');
}

add_action('sflmcp_clean_queue', 'stifli_flex_mcp_clean_queue');
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
}

/* phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter */

// Plugin initialization
add_action('plugins_loaded', function() {
	stifli_flex_mcp_maybe_create_queue_table();
	stifli_flex_mcp_maybe_create_tools_table();
	stifli_flex_mcp_maybe_add_tools_token_column();
	stifli_flex_mcp_maybe_create_profiles_table();
	stifli_flex_mcp_maybe_create_profile_tools_table();
	stifli_flex_mcp_seed_initial_tools();
	stifli_flex_mcp_seed_system_profiles();
	stifli_flex_mcp_sync_tool_token_estimates();
	stifli_flex_mcp_ensure_clean_queue_event();
	if (class_exists('StifliFlexMcp')) {
		$mod = new StifliFlexMcp();
		$mod->init();
	}
});
