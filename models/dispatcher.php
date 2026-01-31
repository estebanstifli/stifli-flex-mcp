<?php
/**
 * Dispatcher mínimo para StifliFlexMcp (stub)
 *
 * @package StifLi_Flex_MCP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Dispatcher mínimo para StifliFlexMcp (stub)
class StifliFlexMcpDispatcher {
	public static function addFilter($tag, $function_to_add, $priority = 10, $accepted_args = 1) {
		add_filter($tag, $function_to_add, $priority, $accepted_args);
	}
	public static function applyFilters($tag, $value, ...$args) {
		return apply_filters($tag, $value, ...$args); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- proxying to core filter dispatcher
	}
}
