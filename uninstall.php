<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * @package StifLi_Flex_MCP
 */

// If uninstall not called from WordPress, exit.
if (!defined('WP_UNINSTALL_PLUGIN')) {
	exit;
}

global $wpdb;

$sflmcp_tables = array(
	'sflmcp_queue',
	'sflmcp_tools',
	'sflmcp_profile_tools',
	'sflmcp_profiles',
	'sflmcp_custom_tools',
	'sflmcp_changelog',
	'sflmcp_abilities',
	'sflmcp_automation_tasks',
	'sflmcp_automation_logs',
	'sflmcp_automation_templates',
	'sflmcp_event_triggers',
	'sflmcp_event_automations',
	'sflmcp_event_logs',
	'sflmcp_oauth_clients',
	'sflmcp_oauth_codes',
	'sflmcp_oauth_tokens',
);

$sflmcp_options = array(
	'stifli_flex_mcp_token',
	'stifli_flex_mcp_token_user',
	'sflmcp_db_version',
	'sflmcp_oauth_auto_approve',
	'sflmcp_gsc_cache_version',
	'sflmcp_scoped_css_rules',
	'sflmcp_seo_settings',
	'sflmcp_settings',
);

$sflmcp_wrap_table = static function ( $table_name ) {
	$clean = preg_replace( '/[^A-Za-z0-9_]/', '', (string) $table_name );
	return '`' . $clean . '`';
};

$sflmcp_drop_tables = static function ( array $tables, $wpdb, $wrap_table ) {
	foreach ( $tables as $table_suffix ) {
		$table_name = $wpdb->prefix . $table_suffix;
		$table_sql  = $wrap_table( $table_name );
		$drop_sql   = sprintf( 'DROP TABLE IF EXISTS %s', $table_sql );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- uninstall must remove plugin-managed tables explicitly.
		$wpdb->query( $drop_sql );
	}
};

$sflmcp_cleanup_options = static function ( array $options, $wpdb ) {
	foreach ( $options as $option_name ) {
		delete_option( $option_name );
	}

	$prefixes = array(
		'sflmcp_',
		'stifli_flex_mcp_',
		'_transient_sflmcp_',
		'_transient_timeout_sflmcp_',
	);

	foreach ( $prefixes as $prefix ) {
		$like = $wpdb->esc_like( $prefix ) . '%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- uninstall cleanup must remove plugin-owned dynamic option names.
		$option_names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) );
		foreach ( (array) $option_names as $option_name ) {
			delete_option( $option_name );
		}
	}
};

// For multisite installations
if (is_multisite()) {
	$stifli_flex_mcp_sites = get_sites(['number' => 0]);
	foreach ($stifli_flex_mcp_sites as $stifli_flex_mcp_site) {
		switch_to_blog($stifli_flex_mcp_site->blog_id);
		$sflmcp_drop_tables( $sflmcp_tables, $wpdb, $sflmcp_wrap_table );
		$sflmcp_cleanup_options( $sflmcp_options, $wpdb );
		restore_current_blog();
	}
} else {
	$sflmcp_drop_tables( $sflmcp_tables, $wpdb, $sflmcp_wrap_table );
	$sflmcp_cleanup_options( $sflmcp_options, $wpdb );
}

// Clear any cached data
wp_cache_flush();
