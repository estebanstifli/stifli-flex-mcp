<?php
/**
 * OAuth 2.1 Storage Layer
 *
 * Database operations for OAuth clients, authorization codes, and tokens.
 *
 * @package StifliFlexMcp
 * @since 3.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class StifliFlexMcp_OAuth_Storage
 *
 * Handles all database operations for the OAuth 2.1 authorization server.
 */
class StifliFlexMcp_OAuth_Storage {

	/**
	 * Access token lifetime in seconds (24 hours).
	 */
	const ACCESS_TOKEN_TTL = 86400;

	/**
	 * Refresh token lifetime in seconds (90 days).
	 */
	const REFRESH_TOKEN_TTL = 7776000;

	/**
	 * Authorization code lifetime in seconds (10 minutes).
	 */
	const CODE_TTL = 600;

	/**
	 * Singleton instance.
	 *
	 * @var StifliFlexMcp_OAuth_Storage|null
	 */
	private static $instance = null;

	/**
	 * Get singleton instance.
	 *
	 * @return StifliFlexMcp_OAuth_Storage
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	// -------------------------------------------------------------------------
	// Table Creation
	// -------------------------------------------------------------------------

	/**
	 * Create all OAuth tables.
	 */
	public static function create_tables() {
		global $wpdb;

		$charset = $wpdb->get_charset_collate();

		// OAuth Clients (Dynamic Client Registration - RFC 7591)
		$clients_table = $wpdb->prefix . 'sflmcp_oauth_clients';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- CREATE TABLE for plugin-managed OAuth table.
		$wpdb->query(
			"CREATE TABLE IF NOT EXISTS {$clients_table} (
				id BIGINT UNSIGNED AUTO_INCREMENT,
				client_id VARCHAR(128) NOT NULL,
				client_name VARCHAR(255) NOT NULL DEFAULT '',
				client_uri VARCHAR(2048) DEFAULT NULL,
				redirect_uris TEXT NOT NULL,
				grant_types VARCHAR(255) NOT NULL DEFAULT 'authorization_code',
				token_endpoint_auth_method VARCHAR(50) NOT NULL DEFAULT 'none',
				client_secret_hash VARCHAR(255) DEFAULT NULL,
				scope VARCHAR(255) NOT NULL DEFAULT 'mcp',
				created_at DATETIME NOT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY client_id (client_id)
			) {$charset}"
		);

		// Authorization Codes (temporary, single-use)
		$codes_table = $wpdb->prefix . 'sflmcp_oauth_codes';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- CREATE TABLE for plugin-managed OAuth table.
		$wpdb->query(
			"CREATE TABLE IF NOT EXISTS {$codes_table} (
				id BIGINT UNSIGNED AUTO_INCREMENT,
				code_hash VARCHAR(64) NOT NULL,
				client_id VARCHAR(128) NOT NULL,
				user_id BIGINT UNSIGNED NOT NULL,
				redirect_uri VARCHAR(2048) NOT NULL,
				scope VARCHAR(255) NOT NULL DEFAULT 'mcp',
				code_challenge VARCHAR(128) NOT NULL,
				code_challenge_method VARCHAR(10) NOT NULL DEFAULT 'S256',
				resource VARCHAR(2048) DEFAULT NULL,
				expires_at DATETIME NOT NULL,
				used TINYINT(1) NOT NULL DEFAULT 0,
				created_at DATETIME NOT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY code_hash (code_hash)
			) {$charset}"
		);

		// Access Tokens + Refresh Tokens
		$tokens_table = $wpdb->prefix . 'sflmcp_oauth_tokens';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- CREATE TABLE for plugin-managed OAuth table.
		$wpdb->query(
			"CREATE TABLE IF NOT EXISTS {$tokens_table} (
				id BIGINT UNSIGNED AUTO_INCREMENT,
				access_token_hash VARCHAR(64) NOT NULL,
				refresh_token_hash VARCHAR(64) DEFAULT NULL,
				client_id VARCHAR(128) NOT NULL,
				user_id BIGINT UNSIGNED NOT NULL,
				scope VARCHAR(255) NOT NULL DEFAULT 'mcp',
				resource VARCHAR(2048) DEFAULT NULL,
				access_expires_at DATETIME NOT NULL,
				refresh_expires_at DATETIME DEFAULT NULL,
				revoked TINYINT(1) NOT NULL DEFAULT 0,
				created_at DATETIME NOT NULL,
				PRIMARY KEY (id),
				KEY access_token_hash (access_token_hash),
				KEY refresh_token_hash (refresh_token_hash),
				KEY client_user (client_id, user_id)
			) {$charset}"
		);
	}

	// -------------------------------------------------------------------------
	// Client Operations
	// -------------------------------------------------------------------------

	/**
	 * Register a new OAuth client (RFC 7591 Dynamic Client Registration).
	 *
	 * @param array $data Client registration data.
	 * @return array|WP_Error Client record or error.
	 */
	public function register_client( $data ) {
		global $wpdb;

		$client_id    = 'sflmcp_' . bin2hex( random_bytes( 16 ) );
		$client_name  = sanitize_text_field( $data['client_name'] ?? 'MCP Client' );
		$client_uri   = isset( $data['client_uri'] ) ? esc_url_raw( $data['client_uri'] ) : null;
		$redirect_uris = $data['redirect_uris'] ?? array();
		$grant_types  = $data['grant_types'] ?? array( 'authorization_code' );
		$auth_method  = $data['token_endpoint_auth_method'] ?? 'none';
		$scope        = sanitize_text_field( $data['scope'] ?? 'mcp' );

		// Validate redirect_uris.
		if ( empty( $redirect_uris ) || ! is_array( $redirect_uris ) ) {
			return new WP_Error( 'invalid_client_metadata', 'redirect_uris is required and must be an array.' );
		}

		// Validate auth method.
		$allowed_methods = array( 'none', 'client_secret_post', 'client_secret_basic' );
		if ( ! in_array( $auth_method, $allowed_methods, true ) ) {
			return new WP_Error( 'invalid_client_metadata', 'Unsupported token_endpoint_auth_method.' );
		}

		// Generate client_secret for confidential clients.
		$client_secret      = null;
		$client_secret_hash = null;
		if ( 'none' !== $auth_method ) {
			$client_secret      = bin2hex( random_bytes( 32 ) );
			$client_secret_hash = wp_hash_password( $client_secret );
		}

		$now = gmdate( 'Y-m-d H:i:s' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$inserted = $wpdb->insert(
			$wpdb->prefix . 'sflmcp_oauth_clients',
			array(
				'client_id'                  => $client_id,
				'client_name'                => $client_name,
				'client_uri'                 => $client_uri,
				'redirect_uris'              => wp_json_encode( $redirect_uris ),
				'grant_types'                => implode( ' ', $grant_types ),
				'token_endpoint_auth_method' => $auth_method,
				'client_secret_hash'         => $client_secret_hash,
				'scope'                      => $scope,
				'created_at'                 => $now,
			)
		);

		if ( false === $inserted ) {
			$db_error = isset( $wpdb->last_error ) ? (string) $wpdb->last_error : '';
			if ( '' !== $db_error && function_exists( 'stifli_flex_mcp_log' ) ) {
				stifli_flex_mcp_log( 'OAuth register_client DB error: ' . $db_error );
			}
			// Return a generic error code; do not leak SQL details to the public.
			return new WP_Error( 'server_error_db', 'Failed to register client (database error).' );
		}

		$result = array(
			'client_id'                  => $client_id,
			'client_name'                => $client_name,
			'redirect_uris'              => $redirect_uris,
			'grant_types'                => $grant_types,
			'token_endpoint_auth_method' => $auth_method,
			'scope'                      => $scope,
		);

		if ( $client_uri ) {
			$result['client_uri'] = $client_uri;
		}
		if ( $client_secret ) {
			$result['client_secret'] = $client_secret;
		}

		return $result;
	}

	/**
	 * Get a client by client_id.
	 *
	 * @param string $client_id Client ID.
	 * @return object|null Client row or null.
	 */
	public function get_client( $client_id ) {
		global $wpdb;
		if ( is_string( $client_id ) && 0 === strpos( $client_id, 'https://' ) ) {
			return $this->get_cimd_client( $client_id );
		}

		$table = $wpdb->prefix . 'sflmcp_oauth_clients';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name from $wpdb->prefix is safe.
		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE client_id = %s", $client_id )
		);
	}

	public static function client_storage_id( $client_id ) {
		return is_string( $client_id ) && 0 === strpos( $client_id, 'https://' ) ? 'cimd_' . hash( 'sha256', $client_id ) : $client_id;
	}

	public function client_is_pending( $client_id ) {
		return 'pending' === get_option( 'sflmcp_oauth_review_' . hash( 'sha256', self::client_storage_id( $client_id ) ), '' );
	}

	public function mark_new_client_for_review( $client_id ) {
		if ( '1' === get_option( 'sflmcp_oauth_require_approval', '0' ) ) {
			add_option( 'sflmcp_oauth_review_' . hash( 'sha256', self::client_storage_id( $client_id ) ), 'pending', '', false );
		}
	}

	public static function validate_cimd_document( $client_id, $document ) {
		if ( ! is_array( $document ) || self::value( $document, 'client_id' ) !== $client_id
			|| ! is_string( self::value( $document, 'client_name' ) ) || '' === trim( $document['client_name'] )
			|| ! is_array( self::value( $document, 'redirect_uris' ) ) || empty( $document['redirect_uris'] )
			|| count( $document['redirect_uris'] ) > 20 || 'none' !== self::value( $document, 'token_endpoint_auth_method', 'none' ) ) {
			return false;
		}
		foreach ( $document['redirect_uris'] as $redirect ) {
			if ( ! is_string( $redirect ) || strlen( $redirect ) > 2048 || preg_match( '/[\x00-\x20\x7f]/', $redirect ) ) {
				return false;
			}
			$parts = wp_parse_url( $redirect );
			$scheme = strtolower( self::value( $parts, 'scheme', '' ) );
			if ( '' === $scheme || in_array( $scheme, array( 'javascript', 'data', 'file' ), true ) || isset( $parts['fragment'] ) || isset( $parts['user'] )
				|| ( 'https' === $scheme && empty( $parts['host'] ) )
				|| ( 'http' === $scheme && ! in_array( self::value( $parts, 'host' ), array( 'localhost', '127.0.0.1', '[::1]' ), true ) ) ) {
				return false;
			}
		}
		return true;
	}

	private static function value( $values, $key, $default = null ) {
		return StifliFlexMcpUtils::getArrayValue( $values, $key, $default );
	}

	private function get_cimd_client( $client_id ) {
		global $wpdb;
		$parts = wp_parse_url( $client_id );
		if ( strlen( $client_id ) > 2048 || ! is_array( $parts ) || 'https' !== self::value( $parts, 'scheme' )
			|| empty( $parts['host'] ) || empty( $parts['path'] ) || '/' === $parts['path'] || isset( $parts['fragment'] ) || isset( $parts['user'] )
			|| ( isset( $parts['port'] ) && 443 !== $parts['port'] ) || ! wp_http_validate_url( $client_id ) ) {
			return null;
		}
		$storage_id = self::client_storage_id( $client_id );
		$cache_key = 'sflmcp_cimd_' . hash( 'sha256', $client_id );
		$document = get_transient( $cache_key );
		if ( ! is_array( $document ) ) {
			if ( is_wp_error( StifliFlexMcpUtils::checkRateLimit( 'cimd:' . StifliFlexMcpUtils::getClientIp(), 10 ) ) ) {
				return null;
			}
			$response = wp_safe_remote_get( $client_id, array( 'timeout' => 5, 'redirection' => 0, 'limit_response_size' => 65536, 'headers' => array( 'Accept' => 'application/json' ), 'cookies' => array() ) );
			if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
				return null;
			}
			$document = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( ! self::validate_cimd_document( $client_id, $document ) ) {
				return null;
			}
			$cache_control = (string) wp_remote_retrieve_header( $response, 'cache-control' );
			$ttl = preg_match( '/max-age\s*=\s*(\d+)/i', $cache_control, $matches ) ? min( 3600, (int) $matches[1] ) : 300;
			if ( $ttl > 0 && ! preg_match( '/no-store|no-cache/i', $cache_control ) ) {
				set_transient( $cache_key, $document, $ttl );
			}
		}
		if ( ! self::validate_cimd_document( $client_id, $document ) ) {
			return null;
		}
		$existing = $this->get_client( $storage_id );
		$values = array( 'client_name' => sanitize_text_field( $document['client_name'] ), 'client_uri' => $client_id, 'redirect_uris' => wp_json_encode( $document['redirect_uris'] ), 'token_endpoint_auth_method' => 'none', 'scope' => 'mcp' );
		if ( $existing ) {
			$saved = $wpdb->update( $wpdb->prefix . 'sflmcp_oauth_clients', $values, array( 'client_id' => $storage_id ) );
		} else {
			$values['client_id'] = $storage_id;
			$values['created_at'] = gmdate( 'Y-m-d H:i:s' );
			$saved = $wpdb->insert( $wpdb->prefix . 'sflmcp_oauth_clients', $values );
			if ( false !== $saved ) {
				$this->mark_new_client_for_review( $storage_id );
			}
		}
		if ( false === $saved ) {
			return null;
		}
		$values['client_id'] = $client_id;
		$values['client_secret_hash'] = null;
		return (object) $values;
	}

	/**
	 * Validate a redirect_uri against a client's registered URIs.
	 *
	 * @param object $client      Client row.
	 * @param string $redirect_uri URI to validate.
	 * @return bool True if valid.
	 */
	public function validate_redirect_uri( $client, $redirect_uri ) {
		$registered = json_decode( $client->redirect_uris, true );
		if ( ! is_array( $registered ) ) {
			return false;
		}
		return in_array( $redirect_uri, $registered, true );
	}

	/**
	 * Validate client_secret for confidential clients.
	 *
	 * @param object $client        Client row.
	 * @param string $client_secret Presented secret.
	 * @return bool True if valid.
	 */
	public function validate_client_secret( $client, $client_secret ) {
		if ( empty( $client->client_secret_hash ) ) {
			return false;
		}
		return wp_check_password( $client_secret, $client->client_secret_hash );
	}

	// -------------------------------------------------------------------------
	// Authorization Code Operations
	// -------------------------------------------------------------------------

	/**
	 * Create an authorization code.
	 *
	 * @param string $client_id             Client ID.
	 * @param int    $user_id               WordPress user ID.
	 * @param string $redirect_uri          Redirect URI.
	 * @param string $scope                 Authorized scope.
	 * @param string $code_challenge        PKCE code challenge.
	 * @param string $code_challenge_method PKCE method (S256).
	 * @param string $resource              Resource indicator (RFC 8707).
	 * @return string The authorization code (plaintext, return once).
	 */
	public function create_code( $client_id, $user_id, $redirect_uri, $scope, $code_challenge, $code_challenge_method, $resource = null ) {
		global $wpdb;
		$client_id = self::client_storage_id( $client_id );

		$code      = bin2hex( random_bytes( 16 ) );
		$code_hash = hash( 'sha256', $code );
		$now       = gmdate( 'Y-m-d H:i:s' );
		$expires   = gmdate( 'Y-m-d H:i:s', time() + self::CODE_TTL );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			$wpdb->prefix . 'sflmcp_oauth_codes',
			array(
				'code_hash'             => $code_hash,
				'client_id'             => $client_id,
				'user_id'               => $user_id,
				'redirect_uri'          => $redirect_uri,
				'scope'                 => $scope,
				'code_challenge'        => $code_challenge,
				'code_challenge_method' => $code_challenge_method,
				'resource'              => $resource,
				'expires_at'            => $expires,
				'created_at'            => $now,
			)
		);

		return $code;
	}

	/**
	 * Consume an authorization code (single-use).
	 *
	 * Returns the code record and marks it as used atomically.
	 *
	 * @param string $code Plaintext authorization code.
	 * @return object|null Code row or null if invalid/expired/used.
	 */
	public function consume_code( $code ) {
		global $wpdb;

		$code_hash = hash( 'sha256', $code );
		$table     = $wpdb->prefix . 'sflmcp_oauth_codes';
		$now       = gmdate( 'Y-m-d H:i:s' );

		// Atomically mark as used and return.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name from $wpdb->prefix is safe.
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET used = 1 WHERE code_hash = %s AND used = 0 AND expires_at > %s",
				$code_hash,
				$now
			)
		);

		if ( 0 === $wpdb->rows_affected ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name from $wpdb->prefix is safe.
		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE code_hash = %s", $code_hash )
		);
	}

	/**
	 * Verify PKCE code_verifier against stored code_challenge.
	 *
	 * @param string $code_verifier  The verifier from the token request.
	 * @param string $code_challenge The challenge stored with the code.
	 * @param string $method         The challenge method (S256).
	 * @return bool True if valid.
	 */
	public function verify_pkce( $code_verifier, $code_challenge, $method = 'S256' ) {
		if ( 'S256' !== $method ) {
			return false;
		}
		$expected = rtrim( strtr( base64_encode( hash( 'sha256', $code_verifier, true ) ), '+/', '-_' ), '=' );
		return hash_equals( $code_challenge, $expected );
	}

	// -------------------------------------------------------------------------
	// Token Operations
	// -------------------------------------------------------------------------

	/**
	 * Create an access token + refresh token pair.
	 *
	 * @param string      $client_id Client ID.
	 * @param int         $user_id   WordPress user ID.
	 * @param string      $scope     Scope.
	 * @param string|null $resource  Resource indicator.
	 * @return array|WP_Error Token response: access_token, refresh_token, expires_in, token_type.
	 */
	public function create_token_pair( $client_id, $user_id, $scope, $resource = null ) {
		global $wpdb;
		$client_id = self::client_storage_id( $client_id );

		$access_token  = bin2hex( random_bytes( 32 ) );
		$refresh_token = bin2hex( random_bytes( 32 ) );
		$now           = gmdate( 'Y-m-d H:i:s' );
		$access_exp    = gmdate( 'Y-m-d H:i:s', time() + self::ACCESS_TOKEN_TTL );
		$refresh_exp   = gmdate( 'Y-m-d H:i:s', time() + self::REFRESH_TOKEN_TTL );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$inserted = $wpdb->insert(
			$wpdb->prefix . 'sflmcp_oauth_tokens',
			array(
				'access_token_hash'  => hash( 'sha256', $access_token ),
				'refresh_token_hash' => hash( 'sha256', $refresh_token ),
				'client_id'          => $client_id,
				'user_id'            => $user_id,
				'scope'              => $scope,
				'resource'           => $resource,
				'access_expires_at'  => $access_exp,
				'refresh_expires_at' => $refresh_exp,
				'created_at'         => $now,
			)
		);

		if ( false === $inserted ) {
			$db_error = isset( $wpdb->last_error ) ? (string) $wpdb->last_error : '';
			if ( '' !== $db_error && function_exists( 'stifli_flex_mcp_log' ) ) {
				stifli_flex_mcp_log( 'OAuth create_token_pair DB error: ' . $db_error );
			}
			return new WP_Error( 'server_error_db', 'Failed to store OAuth token.' );
		}

		return array(
			'access_token'  => $access_token,
			'token_type'    => 'Bearer',
			'expires_in'    => self::ACCESS_TOKEN_TTL,
			'refresh_token' => $refresh_token,
			'scope'         => $scope,
		);
	}

	/**
	 * Validate an access token.
	 *
	 * @param string $access_token Plaintext access token.
	 * @return object|null Token row (with user_id, scope, etc.) or null.
	 */
	public function validate_access_token( $access_token ) {
		global $wpdb;

		$hash  = hash( 'sha256', $access_token );
		$table = $wpdb->prefix . 'sflmcp_oauth_tokens';
		$now   = gmdate( 'Y-m-d H:i:s' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name from $wpdb->prefix is safe.
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE access_token_hash = %s AND access_expires_at > %s AND revoked = 0",
				$hash,
				$now
			)
		);
	}

	/**
	 * Check if a user has previously authorized a client (has any non-revoked token).
	 *
	 * @param string $client_id Client ID.
	 * @param int    $user_id   WordPress user ID.
	 * @return bool True if user has an active or refreshable grant for this client.
	 */
	public function user_has_active_grant( $client_id, $user_id ) {
		global $wpdb;
		$client_id = self::client_storage_id( $client_id );

		$table = $wpdb->prefix . 'sflmcp_oauth_tokens';
		$now   = gmdate( 'Y-m-d H:i:s' );

		// A grant is still usable if either the access token or the refresh token hasn't expired.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name from $wpdb->prefix is safe.
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE client_id = %s AND user_id = %d AND revoked = 0 AND (access_expires_at > %s OR refresh_expires_at > %s)",
				$client_id,
				$user_id,
				$now,
				$now
			)
		);

		return intval( $count ) > 0;
	}

	/**
	 * Refresh a token pair (rotate refresh token).
	 *
	 * @param string $refresh_token Plaintext refresh token.
	 * @param string $client_id     Expected client ID.
	 * @return array|WP_Error|null New token response, error on storage failure, or null if invalid.
	 */
	public function refresh_token( $refresh_token, $client_id ) {
		global $wpdb;
		$client_id = self::client_storage_id( $client_id );

		$hash  = hash( 'sha256', $refresh_token );
		$table = $wpdb->prefix . 'sflmcp_oauth_tokens';
		$now   = gmdate( 'Y-m-d H:i:s' );

		// Find and revoke old token pair atomically.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name from $wpdb->prefix is safe.
		$old = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE refresh_token_hash = %s AND client_id = %s AND refresh_expires_at > %s AND revoked = 0",
				$hash,
				$client_id,
				$now
			)
		);

		if ( ! $old ) {
			return null;
		}

		// Revoke old token pair.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin-managed OAuth table.
		$wpdb->update(
			$table,
			array( 'revoked' => 1 ),
			array( 'id' => $old->id ),
			array( '%d' ),
			array( '%d' )
		);

		// Issue new pair.
		return $this->create_token_pair( $old->client_id, $old->user_id, $old->scope, $old->resource );
	}

	/**
	 * Revoke a token (access or refresh).
	 *
	 * @param string $token          Plaintext token.
	 * @param string $token_type_hint Optional: 'access_token' or 'refresh_token'.
	 * @return bool True if a token was revoked.
	 */
	public function revoke_token( $token, $token_type_hint = '' ) {
		global $wpdb;

		$hash  = hash( 'sha256', $token );
		$table = $wpdb->prefix . 'sflmcp_oauth_tokens';

		// Try access token first (or as hinted).
		if ( 'refresh_token' !== $token_type_hint ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name from $wpdb->prefix is safe.
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table} SET revoked = 1 WHERE access_token_hash = %s AND revoked = 0",
					$hash
				)
			);
			if ( $wpdb->rows_affected > 0 ) {
				return true;
			}
		}

		// Try refresh token.
		if ( 'access_token' !== $token_type_hint ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name from $wpdb->prefix is safe.
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table} SET revoked = 1 WHERE refresh_token_hash = %s AND revoked = 0",
					$hash
				)
			);
			if ( $wpdb->rows_affected > 0 ) {
				return true;
			}
		}

		return false;
	}

	// -------------------------------------------------------------------------
	// Cleanup
	// -------------------------------------------------------------------------

	/**
	 * Delete expired codes and revoked/expired tokens.
	 */
	public function cleanup_expired() {
		global $wpdb;

		$now = gmdate( 'Y-m-d H:i:s' );

		// Expired authorization codes.
		$codes_table = $wpdb->prefix . 'sflmcp_oauth_codes';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name from $wpdb->prefix is safe.
		$wpdb->query(
			$wpdb->prepare( "DELETE FROM {$codes_table} WHERE expires_at < %s", $now )
		);

		// Expired or revoked tokens (keep revoked ones for 24h for audit, then delete).
		$tokens_table = $wpdb->prefix . 'sflmcp_oauth_tokens';
		$cutoff       = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name from $wpdb->prefix is safe.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$tokens_table} WHERE (refresh_expires_at < %s) OR (revoked = 1 AND created_at < %s)",
				$now,
				$cutoff
			)
		);
	}

	/**
	 * Get all active tokens for a user (for admin display).
	 *
	 * @param int $user_id WordPress user ID.
	 * @return array Token rows.
	 */
	public function get_user_tokens( $user_id ) {
		global $wpdb;

		$table = $wpdb->prefix . 'sflmcp_oauth_tokens';
		$now   = gmdate( 'Y-m-d H:i:s' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name from $wpdb->prefix is safe.
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT t.*, c.client_name FROM {$table} t
				LEFT JOIN {$wpdb->prefix}sflmcp_oauth_clients c ON t.client_id = c.client_id
				WHERE t.user_id = %d AND t.revoked = 0 AND t.access_expires_at > %s
				ORDER BY t.created_at DESC",
				$user_id,
				$now
			)
		);
	}

	/**
	 * Get all registered clients (for admin display).
	 *
	 * @return array Client rows.
	 */
	public function get_all_clients() {
		global $wpdb;

		$table = $wpdb->prefix . 'sflmcp_oauth_clients';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name from $wpdb->prefix is safe.
		return $wpdb->get_results( "SELECT * FROM {$table} ORDER BY created_at DESC" );
	}

	/**
	 * Reset OAuth sessions and pending auth codes while preserving client registrations.
	 *
	 * @return array|WP_Error Affected row counts on success.
	 */
	public function reset_all_state() {
		global $wpdb;

		$clients_table = $wpdb->prefix . 'sflmcp_oauth_clients';
		$codes_table   = $wpdb->prefix . 'sflmcp_oauth_codes';
		$tokens_table  = $wpdb->prefix . 'sflmcp_oauth_tokens';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- plugin-managed OAuth tables.
		$deleted_codes = $wpdb->query( "DELETE FROM {$codes_table}" );
		if ( false === $deleted_codes ) {
			return new WP_Error( 'oauth_reset_failed', __( 'Failed to delete pending authorization codes.', 'stifli-flex-mcp' ) );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- plugin-managed OAuth tables.
		$deleted_tokens = $wpdb->query( "DELETE FROM {$tokens_table}" );
		if ( false === $deleted_tokens ) {
			return new WP_Error( 'oauth_reset_failed', __( 'Failed to delete OAuth tokens.', 'stifli-flex-mcp' ) );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- plugin-managed OAuth table.
		$clients_preserved = $wpdb->get_var( "SELECT COUNT(*) FROM {$clients_table}" );

		return array(
			'codes_deleted'     => (int) $deleted_codes,
			'tokens_deleted'    => (int) $deleted_tokens,
			'clients_preserved' => (int) $clients_preserved,
		);
	}

	/**
	 * Delete a client and all its tokens.
	 *
	 * @param string $client_id Client ID.
	 * @return bool True if deleted.
	 */
	public function delete_client( $client_id ) {
		global $wpdb;
		$client_id = self::client_storage_id( $client_id );
		$client = $this->get_client( $client_id );

		// Revoke all tokens for this client.
		$tokens_table = $wpdb->prefix . 'sflmcp_oauth_tokens';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name from $wpdb->prefix is safe.
		$wpdb->query(
			$wpdb->prepare( "UPDATE {$tokens_table} SET revoked = 1 WHERE client_id = %s", $client_id )
		);

		// Delete the client.
		$clients_table = $wpdb->prefix . 'sflmcp_oauth_clients';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin-managed OAuth table.
		$deleted = $wpdb->delete( $clients_table, array( 'client_id' => $client_id ), array( '%s' ) );

		if ( false !== $deleted ) {
			$wpdb->delete( $wpdb->prefix . 'sflmcp_oauth_codes', array( 'client_id' => $client_id ), array( '%s' ) );
			delete_option( 'sflmcp_oauth_review_' . hash( 'sha256', $client_id ) );
			if ( $client && 0 === strpos( $client_id, 'cimd_' ) && ! empty( $client->client_uri ) ) {
				delete_transient( 'sflmcp_cimd_' . hash( 'sha256', $client->client_uri ) );
			}
		}

		return false !== $deleted;
	}
}
