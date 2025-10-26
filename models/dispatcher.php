<?php
// Dispatcher mínimo para EasyVisualMcp (stub)
class EasyVisualMcpDispatcher {
	public static function addFilter($tag, $function_to_add, $priority = 10, $accepted_args = 1) {
		add_filter($tag, $function_to_add, $priority, $accepted_args);
	}
	public static function applyFilters($tag, $value, ...$args) {
		return apply_filters($tag, $value, ...$args);
	}
}
