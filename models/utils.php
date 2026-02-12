<?php
// Utilidades mínimas para StifliFlexMcp (stub)
class StifliFlexMcpUtils {
	/**
	 * Rough token estimation from a string.
	 *
	 * NOTE: This is an approximation (about 4 chars/token for English-ish text).
	 * Use provider-reported usage when available.
	 *
	 * @param string $text Input text.
	 * @return int Estimated tokens.
	 */
	public static function estimateTokensFromString( $text ) {
		if ( ! is_string( $text ) ) {
			return 0;
		}
		$len = strlen( $text );
		if ( $len <= 0 ) {
			return 0;
		}
		return (int) ceil( $len / 4 );
	}

	/**
	 * Rough token estimation from any JSON-serializable value.
	 *
	 * @param mixed $value Any value.
	 * @return int Estimated tokens.
	 */
	public static function estimateTokensFromJson( $value ) {
		$json = wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( ! is_string( $json ) ) {
			return 0;
		}
		return self::estimateTokensFromString( $json );
	}

	public static function getUserAgent() {
		return isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
	}
	public static function getIP() {
		return isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	}
	public static function setAdminUser() {
		// Implementar si es necesario
	}
	public static function sanitizeTableSuffix($suffix) {
		return preg_replace('/[^A-Za-z0-9_]/', '', (string) $suffix);
	}
	public static function getPrefixedTable($suffix, $withBackticks = true) {
		global $wpdb;
		$cleanSuffix = self::sanitizeTableSuffix($suffix);
		$tableName = $wpdb->prefix . $cleanSuffix;
		if (!$withBackticks) {
			return $tableName;
		}
		return '`' . str_replace('`', '', $tableName) . '`';
	}
	public static function wrapTableNameForQuery($tableName) {
		if (!is_string($tableName) || '' === $tableName) {
			return '';
		}
		$clean = preg_replace('/[^A-Za-z0-9_]/', '', $tableName);
		if ('' === $clean) {
			return '';
		}
		return '`' . $clean . '`';
	}
	public static function formatSqlWithTables($template, $suffixes) {
		if (!is_array($suffixes)) {
			$suffixes = array($suffixes);
		}
		$tables = array();
		foreach ($suffixes as $suffix) {
			$tables[] = self::getPrefixedTable($suffix);
		}
		return vsprintf($template, $tables);
	}
	public static function sanitizeJsonString($value) {
		if (!is_string($value)) {
			return '';
		}
		$utf8 = wp_check_invalid_utf8($value);
		return is_string($utf8) ? $utf8 : '';
	}
	public static function sanitizeCheckboxMap($values) {
		if (!is_array($values)) {
			return array();
		}
		$clean = array();
		foreach ($values as $key => $value) {
			$cleanKey = absint($key);
			$clean[$cleanKey] = intval($value) > 0 ? 1 : 0;
		}
		return $clean;
	}
	public static function getArrayValue($arr, $key, $default = null, $depth = 1) {
		if (!is_array($arr)) return $default;
		if (!array_key_exists($key, $arr)) return $default;
		return $arr[$key];
	}
	public static function estimateToolTokenUsage(array $toolDef): int {
		$name = isset($toolDef['name']) ? (string) $toolDef['name'] : '';
		$description = isset($toolDef['description']) ? (string) $toolDef['description'] : '';
		$inputSchema = isset($toolDef['inputSchema']) ? $toolDef['inputSchema'] : null;
		$additional = array();
		foreach (array('confirmPrompt', 'outputSchema', 'examples') as $extraKey) {
			if (isset($toolDef[$extraKey])) {
				$additional[] = wp_json_encode($toolDef[$extraKey], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
			}
		}
		$parts = array($name, $description);
		if (null !== $inputSchema) {
			$parts[] = wp_json_encode($inputSchema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		}
		if (!empty($additional)) {
			$parts = array_merge($parts, $additional);
		}
		$payload = trim(implode("\n", array_filter($parts, 'strlen')));
		if ('' === $payload) {
			return 0;
		}
		$charCount = strlen($payload);
		if ($charCount <= 0) {
			return 0;
		}
		return (int) ceil($charCount / 4);
	}
}
