<?php
// Utilidades mínimas para StifliFlexMcp (stub)
class StifliFlexMcpUtils {
	/**
	 * Check whether an array is sequential (list-like) and not an associative map.
	 *
	 * @param mixed $value Value to inspect.
	 * @return bool
	 */
	public static function isSequentialArray( $value ) {
		if ( ! is_array( $value ) ) {
			return false;
		}
		return array_values( $value ) === $value;
	}

	/**
	 * Normalize a tool input schema so providers receive valid JSON Schema objects.
	 *
	 * In particular, ensures object properties are encoded as a JSON object (map)
	 * instead of a JSON array (list), which Gemini rejects.
	 *
	 * @param mixed $schema Raw schema.
	 * @return array Normalized schema array.
	 */
	public static function normalizeToolInputSchema( $schema ) {
		if ( ! is_array( $schema ) ) {
			$schema = array();
		}

		if ( empty( $schema['type'] ) || ! is_string( $schema['type'] ) ) {
			$schema['type'] = 'object';
		}

		if ( ! isset( $schema['required'] ) || ! is_array( $schema['required'] ) ) {
			$schema['required'] = array();
		}

		if ( 'object' === $schema['type'] || ! isset( $schema['type'] ) ) {
			$properties = isset( $schema['properties'] ) ? $schema['properties'] : array();
			if ( is_object( $properties ) ) {
				$properties = (array) $properties;
			}

			if ( ! is_array( $properties ) ) {
				$properties = array();
			}

			if ( self::isSequentialArray( $properties ) ) {
				$mapped = array();
				foreach ( $properties as $item ) {
					if ( ! is_array( $item ) ) {
						continue;
					}
					$prop_name = isset( $item['name'] ) ? sanitize_key( (string) $item['name'] ) : '';
					if ( '' === $prop_name ) {
						continue;
					}

					if ( isset( $item['schema'] ) && is_array( $item['schema'] ) ) {
						$prop_schema = $item['schema'];
					} else {
						$prop_schema = $item;
						unset( $prop_schema['name'] );
					}

					$mapped[ $prop_name ] = self::normalizeToolInputSchema( $prop_schema );
				}
				$properties = $mapped;
			} else {
				foreach ( $properties as $prop_key => $prop_schema ) {
					if ( is_array( $prop_schema ) ) {
						$properties[ $prop_key ] = self::normalizeToolInputSchema( $prop_schema );
					}
				}
			}

			$schema['properties'] = empty( $properties ) ? new stdClass() : $properties;
		}

		if ( isset( $schema['items'] ) && is_array( $schema['items'] ) ) {
			$schema['items'] = self::normalizeToolInputSchema( $schema['items'] );
		}

		foreach ( array( 'oneOf', 'anyOf', 'allOf' ) as $compound_key ) {
			if ( isset( $schema[ $compound_key ] ) && is_array( $schema[ $compound_key ] ) ) {
				foreach ( $schema[ $compound_key ] as $idx => $sub_schema ) {
					if ( is_array( $sub_schema ) ) {
						$schema[ $compound_key ][ $idx ] = self::normalizeToolInputSchema( $sub_schema );
					}
				}
			}
		}

		return $schema;
	}

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
		$inputSchema = isset($toolDef['inputSchema']) ? self::normalizeToolInputSchema( $toolDef['inputSchema'] ) : null;
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

	/**
	 * Default regex patterns matched against keys/option-names that
	 * potentially contain secrets. Filterable via 'sflmcp_secret_key_patterns'.
	 *
	 * @return string[]
	 */
	public static function getSecretKeyPatterns() {
		$patterns = array(
			'/(^|[_\-.])(api[_\-]?key|apikey)([_\-.]|$)/i',
			'/(^|[_\-.])(secret|client[_\-]?secret)([_\-.]|$)/i',
			'/(^|[_\-.])(token|access[_\-]?token|refresh[_\-]?token|bearer)([_\-.]|$)/i',
			'/(^|[_\-.])(password|passwd|pwd|pass)([_\-.]|$)/i',
			'/(^|[_\-.])(salt|nonce|auth[_\-]?key|secure[_\-]?auth)([_\-.]|$)/i',
			'/(^|[_\-.])(private[_\-]?key|priv[_\-]?key)([_\-.]|$)/i',
			'/(stripe|paypal|sendgrid|mailgun|twilio|aws|s3|recaptcha)[_\-]?(key|secret|token)/i',
			'/^(sk_live|pk_live|sk_test|pk_test|rk_live|rk_test)/i',
			'/_yoast_indexable_/i', // not really secret but noisy; keep separate filter if needed
		);
		if ( function_exists( 'apply_filters' ) ) {
			$patterns = apply_filters( 'sflmcp_secret_key_patterns', $patterns );
			if ( ! is_array( $patterns ) ) {
				$patterns = array();
			}
		}
		return $patterns;
	}

	/**
	 * Regex patterns matched against string VALUES (when the key gives no hint)
	 * to detect tokens, JWTs, AWS keys, Bearer auth headers, etc.
	 *
	 * @return string[]
	 */
	public static function getSecretValuePatterns() {
		$patterns = array(
			'/^Bearer\s+[A-Za-z0-9._\-]+/i',
			'/eyJ[A-Za-z0-9_\-]{10,}\.[A-Za-z0-9_\-]{10,}\.[A-Za-z0-9_\-]{5,}/', // JWT
			'/^(sk_live|pk_live|sk_test|pk_test|rk_live|rk_test)_[A-Za-z0-9]{16,}/',
			'/AKIA[0-9A-Z]{16}/', // AWS access key id
			'/AIza[0-9A-Za-z\-_]{35}/', // Google API key
			'/ghp_[A-Za-z0-9]{30,}/', // GitHub PAT
			'/xox[abprs]-[A-Za-z0-9\-]{10,}/', // Slack token
		);
		if ( function_exists( 'apply_filters' ) ) {
			$patterns = apply_filters( 'sflmcp_secret_value_patterns', $patterns );
			if ( ! is_array( $patterns ) ) {
				$patterns = array();
			}
		}
		return $patterns;
	}

	/**
	 * Decide whether a given key looks sensitive.
	 *
	 * @param string $key Key/option name.
	 * @return bool
	 */
	public static function keyLooksSensitive( $key ) {
		if ( ! is_string( $key ) || '' === $key ) {
			return false;
		}
		foreach ( self::getSecretKeyPatterns() as $pattern ) {
			if ( @preg_match( $pattern, $key ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Decide whether a given string value looks like a secret/token.
	 *
	 * @param string $value
	 * @return bool
	 */
	public static function valueLooksSensitive( $value ) {
		if ( ! is_string( $value ) || '' === $value ) {
			return false;
		}
		foreach ( self::getSecretValuePatterns() as $pattern ) {
			if ( @preg_match( $pattern, $value ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Recursively redact secrets from any value (string, array, object).
	 *
	 * Rules:
	 * - If a key matches a secret pattern, its value is replaced by [REDACTED].
	 * - If a string value matches a secret VALUE pattern, it is redacted.
	 * - Arrays/objects are walked recursively (depth-limited to avoid loops).
	 * - If $context_key is provided, the top-level scalar is treated as belonging
	 *   to that key (so wp_get_option('foo_api_key') returns [REDACTED]).
	 *
	 * @param mixed       $value       Value to redact.
	 * @param string|null $context_key Optional parent key name.
	 * @param int         $depth       Internal recursion guard.
	 * @return mixed Redacted copy.
	 */
	public static function redactSecrets( $value, $context_key = null, $depth = 0 ) {
		if ( $depth > 12 ) {
			return $value;
		}

		// Top-level / leaf scalar tied to a sensitive key.
		if ( ! is_array( $value ) && ! is_object( $value ) ) {
			if ( null !== $context_key && self::keyLooksSensitive( (string) $context_key ) ) {
				if ( is_scalar( $value ) && '' !== (string) $value ) {
					return '[REDACTED]';
				}
				return $value;
			}
			if ( is_string( $value ) && self::valueLooksSensitive( $value ) ) {
				return '[REDACTED]';
			}
			return $value;
		}

		if ( is_object( $value ) ) {
			$value = (array) $value;
		}

		$out = array();
		foreach ( $value as $k => $v ) {
			$key_str = (string) $k;
			if ( self::keyLooksSensitive( $key_str ) ) {
				if ( is_array( $v ) || is_object( $v ) ) {
					// Even nested structures under a sensitive key are wiped.
					$out[ $k ] = '[REDACTED]';
				} elseif ( is_scalar( $v ) && '' !== (string) $v ) {
					$out[ $k ] = '[REDACTED]';
				} else {
					$out[ $k ] = $v;
				}
				continue;
			}
			$out[ $k ] = self::redactSecrets( $v, $key_str, $depth + 1 );
		}
		return $out;
	}

	/**
	 * Hard denylist of WordPress options that must never be writable via MCP,
	 * regardless of capability. Filterable via 'sflmcp_option_write_denylist'.
	 *
	 * @return string[]
	 */
	public static function getOptionWriteDenylist() {
		$deny = array(
			'siteurl', 'home', 'WPLANG',
			'admin_email', 'new_admin_email',
			'users_can_register', 'default_role',
			'db_version', 'initial_db_version', 'secret',
			'auth_key', 'auth_salt', 'logged_in_key', 'logged_in_salt',
			'nonce_key', 'nonce_salt', 'secure_auth_key', 'secure_auth_salt',
			'active_plugins', 'template', 'stylesheet', 'current_theme',
			'recently_activated', 'uploads_use_yearmonth_folders',
			'fileupload_url', 'upload_path',
			'permalink_structure', 'rewrite_rules',
			'cron', 'rss_use_excerpt',
			// Plugin-specific high-risk
			'sflmcp_oauth_auto_approve', 'sflmcp_token', 'sflmcp_settings',
		);
		if ( function_exists( 'apply_filters' ) ) {
			$deny = apply_filters( 'sflmcp_option_write_denylist', $deny );
			if ( ! is_array( $deny ) ) {
				$deny = array();
			}
		}
		return array_map( 'strtolower', array_filter( $deny, 'is_string' ) );
	}

	/**
	 * Decide whether an option name is safe to write via MCP.
	 * Combines a hard denylist + the secret key patterns.
	 *
	 * @param string $option Option name.
	 * @return true|string True if writable, otherwise a reason string.
	 */
	public static function checkOptionWritable( $option ) {
		if ( ! is_string( $option ) || '' === $option ) {
			return 'invalid_option';
		}
		$lower = strtolower( $option );
		$deny  = self::getOptionWriteDenylist();
		if ( in_array( $lower, $deny, true ) ) {
			return 'option_denied_hard';
		}
		if ( self::keyLooksSensitive( $option ) ) {
			return 'option_denied_sensitive_pattern';
		}
		// Optional strict-mode allowlist (opt-in via filter).
		$allow = function_exists( 'apply_filters' )
			? apply_filters( 'sflmcp_writable_options', null )
			: null;
		if ( is_array( $allow ) ) {
			$allow_lower = array_map( 'strtolower', array_filter( $allow, 'is_string' ) );
			if ( ! in_array( $lower, $allow_lower, true ) ) {
				return 'option_not_in_allowlist';
			}
		}
		return true;
	}

	/**
	 * Mask an email address for display in untrusted outputs.
	 *
	 * @param string $email
	 * @return string
	 */
	public static function maskEmail( $email ) {
		if ( ! is_string( $email ) || '' === $email ) {
			return '';
		}
		$at = strrpos( $email, '@' );
		if ( false === $at || $at < 1 ) {
			return '[redacted]';
		}
		$local  = substr( $email, 0, $at );
		$domain = substr( $email, $at + 1 );
		$local_masked = strlen( $local ) <= 2
			? str_repeat( '*', strlen( $local ) )
			: substr( $local, 0, 1 ) . str_repeat( '*', max( 1, strlen( $local ) - 2 ) ) . substr( $local, -1 );
		return $local_masked . '@' . $domain;
	}

	/**
	 * Mask an IP address (IPv4 or IPv6) for display in untrusted outputs.
	 *
	 * @param string $ip
	 * @return string
	 */
	public static function maskIp( $ip ) {
		if ( ! is_string( $ip ) || '' === $ip ) {
			return '';
		}
		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			$parts = explode( '.', $ip );
			if ( 4 === count( $parts ) ) {
				return $parts[0] . '.' . $parts[1] . '.x.x';
			}
		}
		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			$parts = explode( ':', $ip );
			$keep  = array_slice( $parts, 0, 2 );
			return implode( ':', $keep ) . ':****';
		}
		return '[redacted]';
	}

	/**
	 * Validate a URL for safe outbound fetch (used by media/URL upload tools).
	 *
	 * Blocks:
	 * - non-http(s) schemes
	 * - http (when $require_https is true)
	 * - URLs whose host resolves to a private/reserved IP (SSRF protection)
	 * - obvious internal hostnames (localhost, *.local, *.internal)
	 *
	 * @param string $url           URL to validate.
	 * @param bool   $require_https Require HTTPS.
	 * @return true|WP_Error
	 */
	public static function validateOutboundUrl( $url, $require_https = true ) {
		if ( ! is_string( $url ) || '' === $url ) {
			return new WP_Error( 'invalid_url', 'URL is empty' );
		}
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return new WP_Error( 'invalid_url', 'URL must include scheme and host' );
		}
		$scheme = strtolower( $parts['scheme'] );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return new WP_Error( 'invalid_url_scheme', 'Only http(s) URLs are allowed' );
		}
		if ( $require_https && 'https' !== $scheme ) {
			// Allow http for explicit local hosts in dev (loopback), but still
			// block them at the IP check unless caller disables require_https.
			return new WP_Error( 'https_required', 'Only HTTPS URLs are allowed' );
		}
		$host = strtolower( $parts['host'] );
		// Block obvious internal names.
		$blocked_hosts = array( 'localhost', 'localhost.localdomain', 'ip6-localhost' );
		if ( in_array( $host, $blocked_hosts, true ) ) {
			return new WP_Error( 'host_blocked', 'Internal hostname blocked' );
		}
		if ( preg_match( '/\.(local|internal|lan|intranet|test|localhost)$/i', $host ) ) {
			return new WP_Error( 'host_blocked', 'Internal/reserved hostname blocked' );
		}

		// Resolve host to IP(s) and validate each one.
		$ips = array();
		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			$ips[] = $host;
		} else {
			$records = @dns_get_record( $host, DNS_A | DNS_AAAA );
			if ( is_array( $records ) ) {
				foreach ( $records as $rec ) {
					if ( ! empty( $rec['ip'] ) ) {
						$ips[] = $rec['ip'];
					} elseif ( ! empty( $rec['ipv6'] ) ) {
						$ips[] = $rec['ipv6'];
					}
				}
			}
			if ( empty( $ips ) ) {
				$resolved = @gethostbynamel( $host );
				if ( is_array( $resolved ) ) {
					$ips = array_merge( $ips, $resolved );
				}
			}
		}
		if ( empty( $ips ) ) {
			return new WP_Error( 'host_unresolvable', 'Could not resolve host' );
		}
		foreach ( $ips as $ip ) {
			if ( ! filter_var(
				$ip,
				FILTER_VALIDATE_IP,
				FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
			) ) {
				return new WP_Error( 'private_ip_blocked', 'URL resolves to a private/reserved IP' );
			}
		}
		return true;
	}
}

if ( ! function_exists( 'sflmcp_redact_sensitive_value' ) ) {
	/**
	 * Recursively redact sensitive fields by key name.
	 *
	 * @param mixed  $value Value to inspect.
	 * @param string $key   Optional context key.
	 * @return mixed
	 */
	function sflmcp_redact_sensitive_value( $value, $key = '' ) {
		$keywords = array(
			'api_key', 'apikey', 'key', 'token', 'secret', 'password', 'passwd', 'pwd', 'salt',
			'license', 'license_key', 'private_key', 'client_secret', 'consumer_secret',
			'access_token', 'refresh_token', 'auth', 'bearer',
		);

		$key_is_sensitive = function ( $name ) use ( $keywords ) {
			if ( ! is_string( $name ) || '' === $name ) {
				return false;
			}
			$lower = strtolower( $name );
			foreach ( $keywords as $needle ) {
				if ( false !== strpos( $lower, $needle ) ) {
					return true;
				}
			}
			return false;
		};

		if ( $key_is_sensitive( (string) $key ) ) {
			return '[REDACTED]';
		}

		if ( is_array( $value ) ) {
			$out = array();
			foreach ( $value as $child_key => $child_value ) {
				$child_key_str = (string) $child_key;
				if ( $key_is_sensitive( $child_key_str ) ) {
					$out[ $child_key ] = '[REDACTED]';
					continue;
				}
				$out[ $child_key ] = sflmcp_redact_sensitive_value( $child_value, $child_key_str );
			}
			return $out;
		}

		if ( is_object( $value ) ) {
			$out = new stdClass();
			foreach ( get_object_vars( $value ) as $child_key => $child_value ) {
				$child_key_str = (string) $child_key;
				if ( $key_is_sensitive( $child_key_str ) ) {
					$out->{$child_key} = '[REDACTED]';
					continue;
				}
				$out->{$child_key} = sflmcp_redact_sensitive_value( $child_value, $child_key_str );
			}
			return $out;
		}

		return $value;
	}
}
