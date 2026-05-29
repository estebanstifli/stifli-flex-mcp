<?php
/**
 * Google Search Console external data integration.
 *
 * @package StifliFlexMcp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class StifliFlexMcp_GoogleSearchConsole {

	const OPTION_NAME       = 'sflmcp_seo_settings';
	const TOKEN_TRANSIENT   = 'sflmcp_gsc_token_';
	const CACHE_VERSION_OPT = 'sflmcp_gsc_cache_version';
	const SCOPE             = 'https://www.googleapis.com/auth/webmasters.readonly';
	const AUTH_URI          = 'https://accounts.google.com/o/oauth2/v2/auth';
	const TOKEN_URI         = 'https://oauth2.googleapis.com/token';
	const QUERY_DEFAULT_ROWS = 25;
	const QUERY_MAX_ROWS     = 100;
	const QUERY_INTERNAL_MAX_ROWS = 5000;

	private static $hooks_initialized = false;

	public static function init() {
		if ( self::$hooks_initialized ) {
			return;
		}

		add_filter( 'sflmcp_is_tool_enabled_for_integrations', array( __CLASS__, 'filter_tool_visibility' ), 10, 4 );
		self::$hooks_initialized = true;
	}

	public static function getTools() {
		$category = 'SEO - Google Search Console';
		$seo_category = 'SEO - Optimization';

		return array(
			'wp_gsc_list_sites' => array(
				'name' => 'wp_gsc_list_sites',
				'description' => 'List Google Search Console properties available to the connected Google account.',
				'category' => $category,
				'inputSchema' => array(
					'type' => 'object',
					'properties' => array(),
					'required' => array(),
				),
			),
			'wp_gsc_query_performance' => array(
				'name' => 'wp_gsc_query_performance',
				'description' => 'Query Google Search Console search performance data for a site property. Returns compact metrics plus a capped page of rows to avoid excessive MCP token usage.',
				'category' => $category,
				'inputSchema' => array(
					'type' => 'object',
					'properties' => array(
						'site_url' => array(
							'type' => 'string',
							'description' => 'GSC property URL, such as https://example.com/ or sc-domain:example.com. Defaults to the SEO settings page value.',
						),
						'start_date' => array( 'type' => 'string', 'description' => 'Optional start date in YYYY-MM-DD format. Defaults to the last 28 complete days when omitted or empty.' ),
						'end_date' => array( 'type' => 'string', 'description' => 'Optional end date in YYYY-MM-DD format. Defaults to two days ago when omitted or empty.' ),
						'dimensions' => array(
							'type' => 'array',
							'items' => array( 'type' => 'string' ),
							'description' => 'Optional dimensions: query, page, country, device, searchAppearance, date. Omit for query rows, or pass [] for aggregate totals.',
						),
						'search_type' => array( 'type' => 'string', 'description' => 'web, image, video, news, discover, or googleNews. Defaults to web.' ),
						'filters' => array(
							'type' => 'array',
							'items' => array( 'type' => 'object' ),
							'description' => 'Optional filters: each item supports dimension, operator, expression.',
						),
						'aggregation_type' => array( 'type' => 'string', 'description' => 'auto, byPage, or byProperty.' ),
						'data_state' => array( 'type' => 'string', 'description' => 'final or all.' ),
						'row_limit' => array( 'type' => 'integer', 'description' => 'Rows to return. Default 25, max 100. Use start_row for pagination.' ),
						'start_row' => array( 'type' => 'integer', 'description' => 'Pagination offset. Default 0.' ),
						'include_rows' => array( 'type' => 'boolean', 'description' => 'Whether to include row details. Default true. Set false for summary-only output.' ),
					),
					'required' => array(),
				),
			),
			'wp_gsc_inspect_url' => array(
				'name' => 'wp_gsc_inspect_url',
				'description' => 'Inspect a URL in Google Search Console URL Inspection API and return index coverage details.',
				'category' => $category,
				'inputSchema' => array(
					'type' => 'object',
					'properties' => array(
						'site_url' => array( 'type' => 'string', 'description' => 'GSC property URL. Defaults to the SEO settings page value.' ),
						'inspection_url' => array( 'type' => 'string', 'description' => 'Full URL to inspect.' ),
						'language_code' => array( 'type' => 'string', 'description' => 'Optional language code, such as en-US.' ),
					),
					'required' => array( 'inspection_url' ),
				),
			),
			'wp_gsc_list_sitemaps' => array(
				'name' => 'wp_gsc_list_sitemaps',
				'description' => 'List submitted sitemaps for a Google Search Console property, or get a single sitemap when sitemap_url is provided.',
				'category' => $category,
				'inputSchema' => array(
					'type' => 'object',
					'properties' => array(
						'site_url' => array( 'type' => 'string', 'description' => 'GSC property URL. Defaults to the SEO settings page value.' ),
						'sitemap_url' => array( 'type' => 'string', 'description' => 'Optional full sitemap URL to inspect.' ),
					),
					'required' => array(),
				),
			),
			'wp_seo_find_gsc_opportunities' => array(
				'name' => 'wp_seo_find_gsc_opportunities',
				'description' => 'Find SEO opportunities by combining GSC page/query performance with WordPress posts and SEO metadata.',
				'category' => $category,
				'inputSchema' => array(
					'type' => 'object',
					'properties' => array(
						'site_url' => array( 'type' => 'string', 'description' => 'GSC property URL. Defaults to the SEO settings page value.' ),
						'start_date' => array( 'type' => 'string', 'description' => 'Optional start date in YYYY-MM-DD format. Defaults to the last 28 complete days when omitted or empty.' ),
						'end_date' => array( 'type' => 'string', 'description' => 'Optional end date in YYYY-MM-DD format. Defaults to two days ago when omitted or empty.' ),
						'limit' => array( 'type' => 'integer', 'description' => 'Maximum opportunities to return. Default 25, max 100.' ),
						'min_impressions' => array( 'type' => 'integer', 'description' => 'Minimum page impressions. Default 100.' ),
						'max_ctr' => array( 'type' => 'number', 'description' => 'Low CTR threshold as a decimal. Default 0.03.' ),
						'min_position' => array( 'type' => 'number', 'description' => 'Minimum average position for striking-distance opportunities. Default 4.' ),
					),
					'required' => array(),
				),
			),
			'wp_seo_get_post_context' => array(
				'name' => 'wp_seo_get_post_context',
				'description' => 'Get compact SEO context for a post, including current Yoast/Rank Math metadata and capped Google Search Console query data for the post URL.',
				'category' => $seo_category,
				'inputSchema' => array(
					'type' => 'object',
					'properties' => array(
						'post_id' => array( 'type' => 'integer', 'description' => 'WordPress post ID. Provide post_id or url.' ),
						'url' => array( 'type' => 'string', 'description' => 'Post URL to resolve. Provide post_id or url.' ),
						'site_url' => array( 'type' => 'string', 'description' => 'GSC property URL. Defaults to the SEO settings page value.' ),
						'start_date' => array( 'type' => 'string', 'description' => 'Optional start date in YYYY-MM-DD format. Defaults to the last 28 complete days when omitted or empty.' ),
						'end_date' => array( 'type' => 'string', 'description' => 'Optional end date in YYYY-MM-DD format. Defaults to two days ago when omitted or empty.' ),
						'query_limit' => array( 'type' => 'integer', 'description' => 'Maximum top queries to include. Default 10, max 25.' ),
					),
					'required' => array(),
				),
			),
			'wp_seo_suggest_title_meta_from_gsc' => array(
				'name' => 'wp_seo_suggest_title_meta_from_gsc',
				'description' => 'Suggest title and meta description candidates from current post metadata and real GSC query performance. Does not write changes.',
				'category' => $seo_category,
				'inputSchema' => array(
					'type' => 'object',
					'properties' => array(
						'post_id' => array( 'type' => 'integer', 'description' => 'WordPress post ID. Provide post_id or url.' ),
						'url' => array( 'type' => 'string', 'description' => 'Post URL to resolve. Provide post_id or url.' ),
						'site_url' => array( 'type' => 'string', 'description' => 'GSC property URL. Defaults to the SEO settings page value.' ),
						'start_date' => array( 'type' => 'string', 'description' => 'Optional start date in YYYY-MM-DD format. Defaults to the last 28 complete days when omitted or empty.' ),
						'end_date' => array( 'type' => 'string', 'description' => 'Optional end date in YYYY-MM-DD format. Defaults to two days ago when omitted or empty.' ),
						'max_suggestions' => array( 'type' => 'integer', 'description' => 'Number of suggestions to return. Default 3, max 5.' ),
						'provider' => array( 'type' => 'string', 'description' => 'auto, yoast, or rank_math. Used to read current metadata.' ),
					),
					'required' => array(),
				),
			),
			'wp_seo_apply_title_meta_safe' => array(
				'name' => 'wp_seo_apply_title_meta_safe',
				'description' => 'Safely apply SEO title and meta description to Yoast or Rank Math with dry_run, conflict checks, and rollback support.',
				'category' => $seo_category,
				'inputSchema' => array(
					'type' => 'object',
					'properties' => array(
						'post_id' => array( 'type' => 'integer', 'description' => 'WordPress post ID to update.' ),
						'provider' => array( 'type' => 'string', 'description' => 'auto, yoast, or rank_math. Defaults to auto.' ),
						'title' => array( 'type' => 'string', 'description' => 'SEO title to apply. Optional if meta_description is provided.' ),
						'meta_description' => array( 'type' => 'string', 'description' => 'Meta description to apply. Optional if title is provided.' ),
						'focus_keyword' => array( 'type' => 'string', 'description' => 'Optional focus keyword for Yoast or Rank Math.' ),
						'expected_current_title' => array( 'type' => 'string', 'description' => 'Optional optimistic lock. Ignored when empty; if non-empty, current SEO title must match.' ),
						'expected_current_meta_description' => array( 'type' => 'string', 'description' => 'Optional optimistic lock. Ignored when empty; if non-empty, current meta description must match.' ),
						'dry_run' => array( 'type' => 'boolean', 'description' => 'Preview changes without writing. Defaults to true.' ),
					),
					'required' => array( 'post_id' ),
				),
			),
		);
	}

	public static function getCapabilities() {
		return array(
			'wp_gsc_list_sites' => 'manage_options',
			'wp_gsc_query_performance' => 'manage_options',
			'wp_gsc_inspect_url' => 'manage_options',
			'wp_gsc_list_sitemaps' => 'manage_options',
			'wp_seo_find_gsc_opportunities' => 'manage_options',
			'wp_seo_get_post_context' => 'manage_options',
			'wp_seo_suggest_title_meta_from_gsc' => 'manage_options',
			'wp_seo_apply_title_meta_safe' => 'manage_options',
		);
	}

	public static function filter_tool_visibility( $allowed, $tool_name, $context, $tool ) {
		unset( $context, $tool );

		if ( ! self::is_gsc_tool( $tool_name ) ) {
			return $allowed;
		}

		return $allowed && self::toolsAreEnabled() && self::is_tool_enabled_in_table( $tool_name );
	}

	public static function is_gsc_tool( $tool_name ) {
		return 0 === strpos( (string) $tool_name, 'wp_gsc_' )
			|| in_array(
				(string) $tool_name,
				array(
					'wp_seo_find_gsc_opportunities',
					'wp_seo_get_post_context',
					'wp_seo_suggest_title_meta_from_gsc',
					'wp_seo_apply_title_meta_safe',
				),
				true
			);
	}

	public static function toolsAreEnabled() {
		$settings = self::get_settings( false );
		return isset( $settings['gsc_enabled'] ) && '1' === (string) $settings['gsc_enabled'] && self::is_configured();
	}

	private static function is_tool_enabled_in_table( $tool_name ) {
		global $wpdb;

		$tools_table = class_exists( 'StifliFlexMcpUtils' ) ? StifliFlexMcpUtils::getPrefixedTable( 'sflmcp_tools', false ) : $wpdb->prefix . 'sflmcp_tools';
		$tools_table_sql = class_exists( 'StifliFlexMcpUtils' ) ? StifliFlexMcpUtils::getPrefixedTable( 'sflmcp_tools' ) : '`' . str_replace( '`', '', $tools_table ) . '`';
		if ( ! function_exists( 'stifli_flex_mcp_table_exists' ) ) {
			return true;
		}
		if ( ! stifli_flex_mcp_table_exists( $tools_table ) ) {
			return true;
		}

		$cache_key = 'gsc_tool_enabled_' . md5( (string) $tool_name );
		$value = wp_cache_get( $cache_key, 'sflmcp' );
		if ( false === $value ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- plugin table name is sanitized by getPrefixedTable().
			$value = $wpdb->get_var( $wpdb->prepare( "SELECT enabled FROM {$tools_table_sql} WHERE tool_name = %s LIMIT 1", $tool_name ) );
			wp_cache_set( $cache_key, null === $value ? '__missing__' : $value, 'sflmcp', 5 * MINUTE_IN_SECONDS );
		}
		if ( '__missing__' === $value ) {
			$value = null;
		}
		if ( null === $value ) {
			return true;
		}

		return 1 === (int) $value;
	}

	public static function is_configured() {
		$credentials = self::get_oauth_credentials( true );
		return ! is_wp_error( $credentials );
	}

	public static function get_settings( $include_secret = false ) {
		$defaults = array(
			'gsc_enabled' => '0',
			'gsc_site_url' => home_url( '/' ),
			'gsc_cache_ttl' => 900,
			'gsc_oauth_client_id' => '',
			'gsc_oauth_client_secret' => '',
			'gsc_oauth_refresh_token' => '',
			'gsc_oauth_connected_user_id' => 0,
			'gsc_oauth_connected_at' => '',
			'gsc_last_tested_at' => '',
			'gsc_last_test_status' => '',
		);

		$settings = get_option( self::OPTION_NAME, array() );
		$settings = is_array( $settings ) ? wp_parse_args( $settings, $defaults ) : $defaults;

		$has_client_secret = ! empty( $settings['gsc_oauth_client_secret'] );
		$has_refresh_token = ! empty( $settings['gsc_oauth_refresh_token'] );
		$connected_user = self::get_connected_user_label( $settings );

		if ( ! $include_secret ) {
			unset( $settings['gsc_oauth_client_secret'], $settings['gsc_oauth_refresh_token'] );
		}

		$settings['gsc_enabled'] = '1' === (string) $settings['gsc_enabled'] ? '1' : '0';
		$settings['gsc_cache_ttl'] = self::sanitize_cache_ttl( $settings['gsc_cache_ttl'] );
		$settings['gsc_site_url'] = self::sanitize_site_setting( $settings['gsc_site_url'] );
		$settings['gsc_oauth_client_id'] = sanitize_text_field( (string) $settings['gsc_oauth_client_id'] );
		$settings['gsc_oauth_client_secret_configured'] = $has_client_secret;
		$settings['gsc_oauth_refresh_token_configured'] = $has_refresh_token;
		$settings['gsc_oauth_connected_user'] = $connected_user;
		$settings['configured'] = ! empty( $settings['gsc_oauth_client_id'] ) && $has_client_secret && $has_refresh_token;
		$settings['client_configured'] = ! empty( $settings['gsc_oauth_client_id'] ) && $has_client_secret;
		$settings['redirect_uri'] = self::get_redirect_uri();

		return $settings;
	}

	public static function get_public_settings() {
		$settings = self::get_settings( false );

		return array(
			'gsc_enabled' => $settings['gsc_enabled'],
			'gsc_site_url' => $settings['gsc_site_url'],
			'gsc_cache_ttl' => $settings['gsc_cache_ttl'],
			'gsc_oauth_client_id' => $settings['gsc_oauth_client_id'],
			'gsc_oauth_client_secret_configured' => (bool) $settings['gsc_oauth_client_secret_configured'],
			'gsc_oauth_refresh_token_configured' => (bool) $settings['gsc_oauth_refresh_token_configured'],
			'gsc_oauth_connected_user' => $settings['gsc_oauth_connected_user'],
			'gsc_oauth_connected_at' => $settings['gsc_oauth_connected_at'],
			'redirect_uri' => $settings['redirect_uri'],
			'client_configured' => (bool) $settings['client_configured'],
			'gsc_last_tested_at' => $settings['gsc_last_tested_at'],
			'gsc_last_test_status' => $settings['gsc_last_test_status'],
			'configured' => self::is_configured(),
		);
	}

	public static function save_settings( $updates ) {
		$current = self::get_settings( true );
		$previous_client_id = isset( $current['gsc_oauth_client_id'] ) ? (string) $current['gsc_oauth_client_id'] : '';

		if ( array_key_exists( 'gsc_enabled', $updates ) ) {
			$current['gsc_enabled'] = self::is_truthy( $updates['gsc_enabled'] ) ? '1' : '0';
		}

		if ( array_key_exists( 'gsc_site_url', $updates ) ) {
			$site_url = self::sanitize_site_setting( $updates['gsc_site_url'] );
			if ( '' === $site_url ) {
				return new WP_Error( 'invalid_site_url', 'Enter a valid GSC property URL or sc-domain property.' );
			}
			$current['gsc_site_url'] = $site_url;
		}

		if ( array_key_exists( 'gsc_cache_ttl', $updates ) ) {
			$current['gsc_cache_ttl'] = self::sanitize_cache_ttl( $updates['gsc_cache_ttl'] );
		}

		if ( array_key_exists( 'gsc_oauth_client_id', $updates ) ) {
			$current['gsc_oauth_client_id'] = self::sanitize_oauth_client_id( $updates['gsc_oauth_client_id'] );
		}

		if ( isset( $updates['gsc_oauth_client_secret'] ) && '' !== trim( (string) $updates['gsc_oauth_client_secret'] ) ) {
			$encrypted = self::encrypt_value( trim( (string) $updates['gsc_oauth_client_secret'] ) );
			if ( is_wp_error( $encrypted ) ) {
				return $encrypted;
			}
			$current['gsc_oauth_client_secret'] = $encrypted;
		}

		if ( $previous_client_id && $previous_client_id !== $current['gsc_oauth_client_id'] ) {
			$current['gsc_oauth_refresh_token'] = '';
			$current['gsc_oauth_connected_user_id'] = 0;
			$current['gsc_oauth_connected_at'] = '';
			self::clear_access_token_cache();
		}

		unset( $current['gsc_credentials'], $current['gsc_client_email'], $current['gsc_project_id'] );

		update_option( self::OPTION_NAME, self::strip_runtime_settings( $current ), false );
		return self::get_public_settings();
	}

	public static function remove_credentials() {
		$settings = self::get_settings( true );
		self::clear_access_token_cache();
		$settings['gsc_oauth_client_id'] = '';
		$settings['gsc_oauth_client_secret'] = '';
		$settings['gsc_oauth_refresh_token'] = '';
		$settings['gsc_oauth_connected_user_id'] = 0;
		$settings['gsc_oauth_connected_at'] = '';
		$settings['gsc_last_tested_at'] = '';
		$settings['gsc_last_test_status'] = '';
		$settings['gsc_enabled'] = '0';
		unset( $settings['gsc_credentials'], $settings['gsc_client_email'], $settings['gsc_project_id'] );
		update_option( self::OPTION_NAME, self::strip_runtime_settings( $settings ), false );
		self::clear_cache();
		return self::get_public_settings();
	}

	public static function disconnect_account() {
		$settings = self::get_settings( true );
		self::clear_access_token_cache();
		$settings['gsc_oauth_refresh_token'] = '';
		$settings['gsc_oauth_connected_user_id'] = 0;
		$settings['gsc_oauth_connected_at'] = '';
		$settings['gsc_enabled'] = '0';
		update_option( self::OPTION_NAME, self::strip_runtime_settings( $settings ), false );
		self::clear_cache();
		return self::get_public_settings();
	}

	public static function mark_test_status( $status ) {
		$settings = self::get_settings( true );
		$settings['gsc_last_tested_at'] = current_time( 'mysql' );
		$settings['gsc_last_test_status'] = sanitize_text_field( (string) $status );
		update_option( self::OPTION_NAME, self::strip_runtime_settings( $settings ), false );
	}

	public static function clear_cache() {
		$version = (int) get_option( self::CACHE_VERSION_OPT, 1 );
		update_option( self::CACHE_VERSION_OPT, $version + 1, false );
		return true;
	}

	public static function getChangeTrackerSnapshot( $tool, $args ) {
		if ( 'wp_seo_apply_title_meta_safe' !== (string) $tool ) {
			return null;
		}

		$args = is_array( $args ) ? $args : array();
		$dry_run = ! array_key_exists( 'dry_run', $args ) || self::is_truthy( self::array_value( $args, 'dry_run', true ) );
		if ( $dry_run ) {
			return null;
		}

		$post_id = absint( self::array_value( $args, 'post_id', 0 ) );
		if ( ! $post_id ) {
			return null;
		}

		return self::build_change_post_snapshot( $post_id );
	}

	public static function dispatch( $tool, $args, &$r, $addResultText, $utils ) {
		unset( $utils );

		if ( ! self::is_gsc_tool( $tool ) ) {
			return null;
		}

		if ( ! self::toolsAreEnabled() ) {
			$r['error'] = array(
				'code' => -32603,
				'message' => 'Google Search Console is not enabled or configured in StifLi Flex MCP > SEO.',
			);
			return true;
		}

		$args = is_array( $args ) ? $args : array();

		try {
			switch ( $tool ) {
				case 'wp_gsc_list_sites':
					$payload = self::list_sites();
					break;
				case 'wp_gsc_query_performance':
					$payload = self::query_performance( $args );
					break;
				case 'wp_gsc_inspect_url':
					$payload = self::inspect_url( $args );
					break;
				case 'wp_gsc_list_sitemaps':
					$payload = self::list_sitemaps( $args );
					break;
				case 'wp_seo_find_gsc_opportunities':
					$payload = self::find_gsc_opportunities( $args );
					break;
				case 'wp_seo_get_post_context':
					$payload = self::get_post_context_tool( $args );
					break;
				case 'wp_seo_suggest_title_meta_from_gsc':
					$payload = self::suggest_title_meta_from_gsc( $args );
					break;
				case 'wp_seo_apply_title_meta_safe':
					$payload = self::apply_title_meta_safe( $args );
					break;
				default:
					return null;
			}
		} catch ( Exception $e ) {
			$r['error'] = array( 'code' => -32603, 'message' => $e->getMessage() );
			return true;
		}

		self::set_result_payload( $r, $payload, $addResultText );
		return true;
	}

	public static function test_connection( $site_url = '' ) {
		$result = self::list_sites( false );
		if ( is_wp_error( $result ) ) {
			self::mark_test_status( 'error: ' . $result->get_error_message() );
			return $result;
		}

		$settings = self::get_settings( false );
		$target = '' !== trim( (string) $site_url ) ? self::normalize_site_url( $site_url ) : $settings['gsc_site_url'];
		$found = false;

		if ( '' !== $target && ! is_wp_error( $target ) && ! empty( $result['site_entries'] ) ) {
			foreach ( $result['site_entries'] as $site ) {
				if ( isset( $site['site_url'] ) && $site['site_url'] === $target ) {
					$found = true;
					break;
				}
			}
		}

		$result['default_site_url'] = is_wp_error( $target ) ? '' : $target;
		$result['default_site_found'] = $found;
		$result['message'] = $found || empty( $target )
			? 'Google Search Console connected successfully.'
			: 'Google Search Console connected, but the default property was not returned for this Google account.';

		self::mark_test_status( $result['message'] );
		return $result;
	}

	private static function list_sites( $throw = true ) {
		$cache_key = self::cache_key( 'sites', array() );
		$cached = self::get_cached( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		$response = self::api_request( 'GET', 'https://www.googleapis.com/webmasters/v3/sites' );
		if ( is_wp_error( $response ) ) {
			if ( $throw ) {
				throw new Exception( esc_html( $response->get_error_message() ) );
			}
			return $response;
		}

		$entries = array();
		$raw_entries = isset( $response['siteEntry'] ) && is_array( $response['siteEntry'] ) ? $response['siteEntry'] : array();
		foreach ( $raw_entries as $entry ) {
			$entries[] = array(
				'site_url' => isset( $entry['siteUrl'] ) ? (string) $entry['siteUrl'] : '',
				'permission_level' => isset( $entry['permissionLevel'] ) ? (string) $entry['permissionLevel'] : '',
			);
		}

		$payload = array(
			'success' => true,
			'site_entries' => $entries,
			'total' => count( $entries ),
		);

		self::set_cached( $cache_key, $payload );
		return $payload;
	}

	private static function query_performance( $args ) {
		$defaults = self::default_date_range();
		$site_url = self::require_site_url( self::array_value( $args, 'site_url', '' ) );
		$start_date = self::sanitize_date( self::array_non_empty_value( $args, 'start_date', $defaults['start_date'] ), 'start_date' );
		$end_date = self::sanitize_date( self::array_non_empty_value( $args, 'end_date', $defaults['end_date'] ), 'end_date' );

		if ( strtotime( $end_date ) < strtotime( $start_date ) ) {
			throw new Exception( 'end_date must be on or after start_date.' );
		}

		$internal = self::is_truthy( self::array_value( $args, '_internal', false ) );
		$max_rows = $internal ? self::QUERY_INTERNAL_MAX_ROWS : self::QUERY_MAX_ROWS;
		$default_rows = $internal ? 1000 : self::QUERY_DEFAULT_ROWS;
		$requested_rows_raw = self::sanitize_int_range( self::array_value( $args, 'row_limit', $default_rows ), 1, 25000, $default_rows );
		$requested_rows = self::sanitize_int_range( $requested_rows_raw, 1, $max_rows, $default_rows );
		$include_rows = $internal ? true : self::is_truthy( self::array_value( $args, 'include_rows', true ) );
		$dimensions_provided = array_key_exists( 'dimensions', $args );

		$body = array(
			'startDate' => $start_date,
			'endDate' => $end_date,
			'dimensions' => self::sanitize_dimensions( self::array_value( $args, 'dimensions', array() ), $dimensions_provided ),
			'rowLimit' => $requested_rows,
			'startRow' => self::sanitize_int_range( self::array_value( $args, 'start_row', 0 ), 0, 1000000, 0 ),
		);

		$search_type = self::sanitize_enum(
			self::array_value( $args, 'search_type', 'web' ),
			array( 'web', 'image', 'video', 'news', 'discover', 'googleNews' ),
			'web'
		);
		$body['searchType'] = $search_type;

		$aggregation_type = self::sanitize_enum( self::array_value( $args, 'aggregation_type', '' ), array( 'auto', 'byPage', 'byProperty' ), '' );
		if ( '' !== $aggregation_type ) {
			$body['aggregationType'] = $aggregation_type;
		}

		$data_state = self::sanitize_enum( self::array_value( $args, 'data_state', '' ), array( 'final', 'all' ), '' );
		if ( '' !== $data_state ) {
			$body['dataState'] = $data_state;
		}

		$filters = self::sanitize_filters( self::array_value( $args, 'filters', array() ) );
		if ( ! empty( $filters ) ) {
			$body['dimensionFilterGroups'] = array(
				array(
					'groupType' => 'and',
					'filters' => $filters,
				),
			);
		}

		$cache_key = self::cache_key( 'query', array( $site_url, $body, $include_rows, $internal ) );
		$cached = self::get_cached( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		$url = 'https://www.googleapis.com/webmasters/v3/sites/' . rawurlencode( $site_url ) . '/searchAnalytics/query';
		$response = self::api_request( 'POST', $url, $body );
		if ( is_wp_error( $response ) ) {
			throw new Exception( esc_html( $response->get_error_message() ) );
		}

		$rows = array();
		$summary = array(
			'total_clicks' => 0.0,
			'total_impressions' => 0.0,
			'weighted_position_sum' => 0.0,
		);
		$raw_rows = isset( $response['rows'] ) && is_array( $response['rows'] ) ? $response['rows'] : array();
		foreach ( $raw_rows as $row ) {
			$clicks = isset( $row['clicks'] ) ? (float) $row['clicks'] : 0.0;
			$impressions = isset( $row['impressions'] ) ? (float) $row['impressions'] : 0.0;
			$position = isset( $row['position'] ) ? (float) $row['position'] : 0.0;
			$summary['total_clicks'] += $clicks;
			$summary['total_impressions'] += $impressions;
			$summary['weighted_position_sum'] += $position * max( 1, $impressions );

			$rows[] = array(
				'keys' => isset( $row['keys'] ) && is_array( $row['keys'] ) ? array_values( array_map( 'strval', $row['keys'] ) ) : array(),
				'clicks' => $clicks,
				'impressions' => $impressions,
				'ctr' => isset( $row['ctr'] ) ? round( (float) $row['ctr'], 5 ) : 0.0,
				'position' => round( $position, 2 ),
			);
		}

		$row_count = count( $rows );
		$rows_returned = $include_rows ? $row_count : 0;
		$next_start_row = $row_count >= $requested_rows ? $body['startRow'] + $row_count : null;
		$metrics_summary = array(
			'total_clicks' => round( $summary['total_clicks'], 2 ),
			'total_impressions' => round( $summary['total_impressions'], 2 ),
			'average_ctr' => $summary['total_impressions'] > 0 ? round( $summary['total_clicks'] / $summary['total_impressions'], 5 ) : 0,
			'average_position' => $summary['total_impressions'] > 0 ? round( $summary['weighted_position_sum'] / $summary['total_impressions'], 2 ) : 0,
		);
		$warning = '';
		if ( ! $include_rows ) {
			$warning = 'Rows omitted because include_rows=false. Metrics summary is still included.';
		} elseif ( $requested_rows_raw > $requested_rows ) {
			$warning = 'row_limit was capped to ' . $requested_rows . ' to avoid excessive MCP token usage. Use start_row pagination for additional pages.';
		}

		$payload = array(
			'success' => true,
			'site_url' => $site_url,
			'start_date' => $start_date,
			'end_date' => $end_date,
			'search_type' => $search_type,
			'dimensions' => $body['dimensions'],
			'metrics_summary' => $metrics_summary,
			'row_count' => $row_count,
			'rows_returned' => $rows_returned,
			'rows_truncated' => ! $include_rows || $requested_rows_raw > $requested_rows,
			'next_start_row' => $next_start_row,
			'warning' => $warning,
			'limits' => array(
				'requested_row_limit' => $requested_rows_raw,
				'applied_row_limit' => $requested_rows,
				'max_row_limit' => $max_rows,
				'pagination_hint' => null !== $next_start_row ? 'Call again with start_row=' . $next_start_row . ' to fetch the next page.' : '',
			),
			'rows' => $include_rows ? $rows : array(),
		);

		self::set_cached( $cache_key, $payload );
		return $payload;
	}

	private static function inspect_url( $args ) {
		$site_url = self::require_site_url( self::array_value( $args, 'site_url', '' ) );
		$inspection_url = esc_url_raw( trim( (string) self::array_value( $args, 'inspection_url', '' ) ) );
		if ( '' === $inspection_url || ! wp_http_validate_url( $inspection_url ) ) {
			throw new Exception( 'inspection_url must be a valid http or https URL.' );
		}

		$body = array(
			'inspectionUrl' => $inspection_url,
			'siteUrl' => $site_url,
		);

		$language_code = sanitize_text_field( (string) self::array_value( $args, 'language_code', '' ) );
		if ( '' !== $language_code ) {
			$body['languageCode'] = $language_code;
		}

		$cache_key = self::cache_key( 'inspect', $body );
		$cached = self::get_cached( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		$response = self::api_request( 'POST', 'https://searchconsole.googleapis.com/v1/urlInspection/index:inspect', $body );
		if ( is_wp_error( $response ) ) {
			throw new Exception( esc_html( $response->get_error_message() ) );
		}

		$payload = array(
			'success' => true,
			'site_url' => $site_url,
			'inspection_url' => $inspection_url,
			'inspection_result' => isset( $response['inspectionResult'] ) ? $response['inspectionResult'] : $response,
		);

		self::set_cached( $cache_key, $payload );
		return $payload;
	}

	private static function list_sitemaps( $args ) {
		$site_url = self::require_site_url( self::array_value( $args, 'site_url', '' ) );
		$sitemap_url = esc_url_raw( trim( (string) self::array_value( $args, 'sitemap_url', '' ) ) );

		$url = 'https://www.googleapis.com/webmasters/v3/sites/' . rawurlencode( $site_url ) . '/sitemaps';
		if ( '' !== $sitemap_url ) {
			if ( ! wp_http_validate_url( $sitemap_url ) ) {
				throw new Exception( 'sitemap_url must be a valid http or https URL.' );
			}
			$url .= '/' . rawurlencode( $sitemap_url );
		}

		$cache_key = self::cache_key( 'sitemaps', array( $site_url, $sitemap_url ) );
		$cached = self::get_cached( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		$response = self::api_request( 'GET', $url );
		if ( is_wp_error( $response ) ) {
			throw new Exception( esc_html( $response->get_error_message() ) );
		}

		$payload = array(
			'success' => true,
			'site_url' => $site_url,
			'sitemap_url' => $sitemap_url,
			'data' => $response,
		);

		self::set_cached( $cache_key, $payload );
		return $payload;
	}

	private static function find_gsc_opportunities( $args ) {
		$defaults = self::default_date_range();
		$start_date = self::sanitize_date( self::array_non_empty_value( $args, 'start_date', $defaults['start_date'] ), 'start_date' );
		$end_date = self::sanitize_date( self::array_non_empty_value( $args, 'end_date', $defaults['end_date'] ), 'end_date' );
		$limit = self::sanitize_int_range( self::array_value( $args, 'limit', 25 ), 1, 100, 25 );
		$min_impressions = self::sanitize_int_range( self::array_value( $args, 'min_impressions', 100 ), 1, 1000000, 100 );
		$max_ctr = self::sanitize_float_range( self::array_value( $args, 'max_ctr', 0.03 ), 0.001, 1, 0.03 );
		$min_position = self::sanitize_float_range( self::array_value( $args, 'min_position', 4 ), 1, 50, 4 );

		$query_args = array(
			'site_url' => self::array_value( $args, 'site_url', '' ),
			'start_date' => $start_date,
			'end_date' => $end_date,
			'dimensions' => array( 'page', 'query' ),
			'search_type' => 'web',
			'row_limit' => 25000,
			'_internal' => true,
		);

		$performance = self::query_performance( $query_args );
		$pages = array();

		foreach ( $performance['rows'] as $row ) {
			$keys = isset( $row['keys'] ) ? $row['keys'] : array();
			$page_url = isset( $keys[0] ) ? esc_url_raw( (string) $keys[0] ) : '';
			$query = isset( $keys[1] ) ? sanitize_text_field( (string) $keys[1] ) : '';
			if ( '' === $page_url ) {
				continue;
			}

			if ( ! isset( $pages[ $page_url ] ) ) {
				$pages[ $page_url ] = array(
					'page_url' => $page_url,
					'clicks' => 0.0,
					'impressions' => 0.0,
					'weighted_position' => 0.0,
					'top_queries' => array(),
				);
			}

			$clicks = (float) $row['clicks'];
			$impressions = (float) $row['impressions'];
			$position = (float) $row['position'];
			$pages[ $page_url ]['clicks'] += $clicks;
			$pages[ $page_url ]['impressions'] += $impressions;
			$pages[ $page_url ]['weighted_position'] += $position * max( 1, $impressions );

			if ( '' !== $query ) {
				$pages[ $page_url ]['top_queries'][] = array(
					'query' => $query,
					'clicks' => $clicks,
					'impressions' => $impressions,
					'ctr' => $impressions > 0 ? $clicks / $impressions : 0,
					'position' => $position,
				);
			}
		}

		$opportunities = array();
		foreach ( $pages as $page ) {
			if ( $page['impressions'] < $min_impressions ) {
				continue;
			}

			$ctr = $page['impressions'] > 0 ? $page['clicks'] / $page['impressions'] : 0;
			$position = $page['impressions'] > 0 ? $page['weighted_position'] / $page['impressions'] : 0;
			$expected_ctr = self::expected_ctr_for_position( $position );
			$reason = '';

			if ( $ctr <= $max_ctr && $expected_ctr > $ctr ) {
				$reason = 'high_impressions_low_ctr';
			} elseif ( $position >= $min_position && $position <= 20 ) {
				$reason = 'striking_distance';
			} else {
				continue;
			}

			usort( $page['top_queries'], array( __CLASS__, 'sort_queries_by_impressions' ) );
			$page['top_queries'] = array_slice( $page['top_queries'], 0, 5 );

			$post_context = self::get_post_context_for_url( $page['page_url'] );
			$score = max( 0, $expected_ctr - $ctr ) * $page['impressions'];
			if ( 'striking_distance' === $reason ) {
				$score += $page['impressions'] / max( 1, $position );
			}

			$opportunities[] = array(
				'page_url' => $page['page_url'],
				'post' => $post_context,
				'reason' => $reason,
				'recommendation' => self::build_recommendation( $reason, $post_context ),
				'score' => round( $score, 3 ),
				'metrics' => array(
					'clicks' => round( $page['clicks'], 2 ),
					'impressions' => round( $page['impressions'], 2 ),
					'ctr' => round( $ctr, 5 ),
					'position' => round( $position, 2 ),
					'expected_ctr' => round( $expected_ctr, 5 ),
				),
				'top_queries' => $page['top_queries'],
			);
		}

		usort( $opportunities, array( __CLASS__, 'sort_opportunities' ) );
		$opportunities = array_slice( $opportunities, 0, $limit );

		return array(
			'success' => true,
			'site_url' => $performance['site_url'],
			'start_date' => $start_date,
			'end_date' => $end_date,
			'criteria' => array(
				'limit' => $limit,
				'min_impressions' => $min_impressions,
				'max_ctr' => $max_ctr,
				'min_position' => $min_position,
			),
			'opportunity_count' => count( $opportunities ),
			'opportunities' => $opportunities,
		);
	}

	private static function get_post_context_tool( $args ) {
		$post = self::resolve_post_from_args( $args );
		$defaults = self::default_date_range();
		$start_date = self::sanitize_date( self::array_non_empty_value( $args, 'start_date', $defaults['start_date'] ), 'start_date' );
		$end_date = self::sanitize_date( self::array_non_empty_value( $args, 'end_date', $defaults['end_date'] ), 'end_date' );
		$query_limit = self::sanitize_int_range( self::array_value( $args, 'query_limit', 10 ), 1, 25, 10 );
		$permalink = get_permalink( $post->ID );

		if ( false === $permalink || '' === $permalink ) {
			throw new Exception( 'Could not resolve post permalink.' );
		}

		$gsc = self::get_post_gsc_context( $permalink, $args, $start_date, $end_date, $query_limit );

		return array(
			'success' => true,
			'post' => self::build_post_context( $post->ID, self::array_value( $args, 'provider', 'auto' ) ),
			'gsc' => $gsc,
			'analysis' => self::summarize_post_gsc_context( $gsc ),
		);
	}

	private static function suggest_title_meta_from_gsc( $args ) {
		$context = self::get_post_context_tool( array_merge( $args, array( 'query_limit' => 10 ) ) );
		$post = isset( $context['post'] ) && is_array( $context['post'] ) ? $context['post'] : array();
		$gsc = isset( $context['gsc'] ) && is_array( $context['gsc'] ) ? $context['gsc'] : array();
		$queries = isset( $gsc['top_queries'] ) && is_array( $gsc['top_queries'] ) ? $gsc['top_queries'] : array();
		$max_suggestions = self::sanitize_int_range( self::array_value( $args, 'max_suggestions', 3 ), 1, 5, 3 );
		$current = isset( $post['seo']['current'] ) && is_array( $post['seo']['current'] ) ? $post['seo']['current'] : array();
		$current_title = isset( $current['title'] ) ? (string) $current['title'] : '';
		$current_description = isset( $current['meta_description'] ) ? (string) $current['meta_description'] : '';
		$post_title = isset( $post['title'] ) && '' !== $post['title'] ? (string) $post['title'] : $current_title;
		$post_summary = isset( $post['content_summary'] ) ? (string) $post['content_summary'] : '';
		$site_name = wp_strip_all_tags( get_bloginfo( 'name' ) );
		$query_terms = array();

		foreach ( $queries as $row ) {
			if ( empty( $row['query'] ) ) {
				continue;
			}
			$query_terms[] = (string) $row['query'];
		}

		$query_terms = array_values( array_unique( array_slice( $query_terms, 0, 5 ) ) );
		$primary_query = isset( $query_terms[0] ) ? $query_terms[0] : '';
		$secondary_query = isset( $query_terms[1] ) ? $query_terms[1] : '';
		$suggestions = array();

		$title_candidates = array(
			self::compose_title_candidate( $post_title, $primary_query, $site_name, 1 ),
			self::compose_title_candidate( $post_title, $primary_query, $site_name, 2 ),
			self::compose_title_candidate( $post_title, $secondary_query, $site_name, 3 ),
			self::limit_text( '' !== $current_title ? $current_title : $post_title, 60 ),
		);

		$description_candidates = array(
			self::compose_description_candidate( $post_summary, $primary_query, 1 ),
			self::compose_description_candidate( $post_summary, $secondary_query, 2 ),
			self::limit_text( '' !== $current_description ? $current_description : $post_summary, 155 ),
			self::compose_description_candidate( $post_summary, implode( ', ', array_slice( $query_terms, 0, 3 ) ), 3 ),
		);
		$seen = array();

		foreach ( $title_candidates as $index => $title ) {
			$title = self::normalize_spaces( $title );
			$description = isset( $description_candidates[ $index ] ) ? self::normalize_spaces( $description_candidates[ $index ] ) : '';
			if ( '' === $title && '' === $description ) {
				continue;
			}

			$signature = md5( $title . '|' . $description );
			if ( isset( $seen[ $signature ] ) ) {
				continue;
			}
			$seen[ $signature ] = true;

			$suggestions[] = array(
				'title' => $title,
				'meta_description' => $description,
				'title_length' => strlen( $title ),
				'meta_description_length' => strlen( $description ),
				'focus_keyword' => '' !== $primary_query ? $primary_query : '',
				'rationale' => self::build_suggestion_rationale( $query_terms, $context['analysis'] ),
				'source_queries' => array_slice( $query_terms, 0, 3 ),
				'warnings' => self::get_title_meta_warnings( $title, $description ),
			);

			if ( count( $suggestions ) >= $max_suggestions ) {
				break;
			}
		}

		return array(
			'success' => true,
			'post' => $post,
			'gsc' => array(
				'site_url' => isset( $gsc['site_url'] ) ? $gsc['site_url'] : '',
				'start_date' => isset( $gsc['start_date'] ) ? $gsc['start_date'] : '',
				'end_date' => isset( $gsc['end_date'] ) ? $gsc['end_date'] : '',
				'metrics_summary' => isset( $gsc['metrics_summary'] ) ? $gsc['metrics_summary'] : array(),
				'top_queries' => array_slice( $queries, 0, 5 ),
			),
			'suggestions' => $suggestions,
			'note' => 'These are deterministic suggestions from site content and GSC query data. Review wording before applying.',
		);
	}

	private static function apply_title_meta_safe( $args ) {
		$post_id = absint( self::array_value( $args, 'post_id', 0 ) );
		if ( ! $post_id || ! get_post( $post_id ) ) {
			throw new Exception( 'Invalid or missing post_id.' );
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			throw new Exception( 'You do not have permission to edit this post.' );
		}

		$provider = self::resolve_seo_provider( $post_id, self::array_value( $args, 'provider', 'auto' ), true );
		if ( 'none' === $provider ) {
			throw new Exception( 'Yoast SEO or Rank Math must be active before applying SEO metadata.' );
		}

		$dry_run = ! array_key_exists( 'dry_run', $args ) || self::is_truthy( self::array_value( $args, 'dry_run', true ) );
		$title_provided = self::has_non_empty_arg( $args, 'title' );
		$description_provided = self::has_non_empty_arg( $args, 'meta_description' );
		$keyword_provided = self::has_non_empty_arg( $args, 'focus_keyword' );

		if ( ! $title_provided && ! $description_provided && ! $keyword_provided ) {
			throw new Exception( 'Provide title, meta_description, or focus_keyword.' );
		}

		$title = $title_provided ? self::sanitize_title_meta_value( self::array_value( $args, 'title', '' ), 'title', 120 ) : null;
		$description = $description_provided ? self::sanitize_title_meta_value( self::array_value( $args, 'meta_description', '' ), 'meta_description', 320 ) : null;
		$focus_keyword = $keyword_provided ? self::sanitize_title_meta_value( self::array_value( $args, 'focus_keyword', '' ), 'focus_keyword', 120 ) : null;
		$current = self::get_provider_current_meta( $post_id, $provider );

		if ( self::has_non_empty_arg( $args, 'expected_current_title' ) && (string) self::array_value( $args, 'expected_current_title', '' ) !== (string) $current['title'] ) {
			throw new Exception( 'Conflict: current SEO title no longer matches expected_current_title.' );
		}
		if ( self::has_non_empty_arg( $args, 'expected_current_meta_description' ) && (string) self::array_value( $args, 'expected_current_meta_description', '' ) !== (string) $current['meta_description'] ) {
			throw new Exception( 'Conflict: current meta description no longer matches expected_current_meta_description.' );
		}

		$changes = array();
		if ( null !== $title && $title !== (string) $current['title'] ) {
			$changes['title'] = array( 'from' => (string) $current['title'], 'to' => $title );
		}
		if ( null !== $description && $description !== (string) $current['meta_description'] ) {
			$changes['meta_description'] = array( 'from' => (string) $current['meta_description'], 'to' => $description );
		}
		if ( null !== $focus_keyword && $focus_keyword !== (string) $current['focus_keyword'] ) {
			$changes['focus_keyword'] = array( 'from' => (string) $current['focus_keyword'], 'to' => $focus_keyword );
		}

		if ( ! $dry_run && ! empty( $changes ) ) {
			self::update_provider_meta( $post_id, $provider, $title, $description, $focus_keyword );
			self::clear_provider_cache( $post_id, $provider );
		}

		$final = self::get_provider_current_meta( $post_id, $provider );
		$payload = array(
			'success' => true,
			'dry_run' => $dry_run,
			'post_id' => $post_id,
			'provider' => $provider,
			'changed' => ! empty( $changes ) && ! $dry_run,
			'would_change' => ! empty( $changes ),
			'changes' => $changes,
			'warnings' => self::get_title_meta_warnings( null !== $title ? $title : $current['title'], null !== $description ? $description : $current['meta_description'] ),
			'before' => $current,
			'after' => $dry_run ? $current : $final,
			'undo' => array(
				'available' => ! $dry_run && ! empty( $changes ),
				'tool' => 'mcp_rollback_change',
			),
		);

		if ( ! $dry_run && empty( $changes ) ) {
			$payload['_skip_change_tracking'] = true;
		}

		return $payload;
	}

	private static function api_request( $method, $url, $body = null ) {
		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( ! in_array( $host, array( 'www.googleapis.com', 'searchconsole.googleapis.com' ), true ) ) {
			return new WP_Error( 'invalid_google_host', 'Blocked Google API host.' );
		}

		$token = self::get_access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$args = array(
			'method' => strtoupper( $method ),
			'timeout' => 25,
			'redirection' => 2,
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Accept' => 'application/json',
			),
		);

		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body'] = wp_json_encode( $body );
		}

		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw = (string) wp_remote_retrieve_body( $response );
		$data = '' !== $raw ? json_decode( $raw, true ) : array();
		if ( ! is_array( $data ) ) {
			$data = array( 'raw' => $raw );
		}

		if ( $code < 200 || $code >= 300 ) {
			$message = self::extract_google_error_message( $data );
			return new WP_Error( 'google_api_error', $message, array( 'status' => $code ) );
		}

		return $data;
	}

	private static function get_access_token() {
		$credentials = self::get_oauth_credentials( true );
		if ( is_wp_error( $credentials ) ) {
			return $credentials;
		}

		$transient = self::TOKEN_TRANSIENT . md5( $credentials['client_id'] . '|' . $credentials['refresh_token'] );
		$cached = get_transient( $transient );
		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		$response = wp_remote_post(
			self::TOKEN_URI,
			array(
				'timeout' => 20,
				'redirection' => 0,
				'headers' => array(
					'Content-Type' => 'application/x-www-form-urlencoded',
					'Accept' => 'application/json',
				),
				'body' => http_build_query(
					array(
						'client_id' => $credentials['client_id'],
						'client_secret' => $credentials['client_secret'],
						'refresh_token' => $credentials['refresh_token'],
						'grant_type' => 'refresh_token',
					),
					'',
					'&'
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw = (string) wp_remote_retrieve_body( $response );
		$data = '' !== $raw ? json_decode( $raw, true ) : array();
		if ( ! is_array( $data ) ) {
			$data = array();
		}

		if ( $code < 200 || $code >= 300 || empty( $data['access_token'] ) ) {
			return new WP_Error( 'google_token_error', self::extract_google_error_message( $data ), array( 'status' => $code ) );
		}

		$ttl = isset( $data['expires_in'] ) ? max( 60, (int) $data['expires_in'] - 90 ) : 3300;
		set_transient( $transient, (string) $data['access_token'], $ttl );
		return (string) $data['access_token'];
	}

	public static function get_oauth_credentials( $require_refresh_token = true ) {
		$settings = self::get_settings( true );
		$client_id = isset( $settings['gsc_oauth_client_id'] ) ? self::sanitize_oauth_client_id( $settings['gsc_oauth_client_id'] ) : '';
		$encrypted_secret = isset( $settings['gsc_oauth_client_secret'] ) ? (string) $settings['gsc_oauth_client_secret'] : '';
		$encrypted_refresh = isset( $settings['gsc_oauth_refresh_token'] ) ? (string) $settings['gsc_oauth_refresh_token'] : '';

		if ( '' === $client_id || '' === $encrypted_secret ) {
			return new WP_Error( 'gsc_oauth_client_missing', 'Google OAuth Client ID and Client Secret are required.' );
		}

		$client_secret = self::decrypt_value( $encrypted_secret );
		if ( is_wp_error( $client_secret ) ) {
			return $client_secret;
		}

		$refresh_token = '';
		if ( '' !== $encrypted_refresh ) {
			$refresh_token = self::decrypt_value( $encrypted_refresh );
			if ( is_wp_error( $refresh_token ) ) {
				return $refresh_token;
			}
		}

		if ( $require_refresh_token && '' === $refresh_token ) {
			return new WP_Error( 'gsc_oauth_not_connected', 'Connect a Google account before using Search Console tools.' );
		}

		return array(
			'client_id' => $client_id,
			'client_secret' => (string) $client_secret,
			'refresh_token' => (string) $refresh_token,
		);
	}

	public static function get_redirect_uri() {
		return admin_url( 'admin-post.php?action=sflmcp_gsc_oauth_callback' );
	}

	public static function build_authorization_url( $state ) {
		$credentials = self::get_oauth_credentials( false );
		if ( is_wp_error( $credentials ) ) {
			return $credentials;
		}

		return add_query_arg(
			array(
				'client_id' => $credentials['client_id'],
				'redirect_uri' => self::get_redirect_uri(),
				'response_type' => 'code',
				'scope' => self::SCOPE,
				'access_type' => 'offline',
				'prompt' => 'consent',
				'state' => (string) $state,
			),
			self::AUTH_URI
		);
	}

	public static function exchange_authorization_code( $code, $user_id ) {
		$credentials = self::get_oauth_credentials( false );
		if ( is_wp_error( $credentials ) ) {
			return $credentials;
		}

		$response = wp_remote_post(
			self::TOKEN_URI,
			array(
				'timeout' => 20,
				'redirection' => 0,
				'headers' => array(
					'Content-Type' => 'application/x-www-form-urlencoded',
					'Accept' => 'application/json',
				),
				'body' => http_build_query(
					array(
						'code' => (string) $code,
						'client_id' => $credentials['client_id'],
						'client_secret' => $credentials['client_secret'],
						'redirect_uri' => self::get_redirect_uri(),
						'grant_type' => 'authorization_code',
					),
					'',
					'&'
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code_status = (int) wp_remote_retrieve_response_code( $response );
		$raw = (string) wp_remote_retrieve_body( $response );
		$data = '' !== $raw ? json_decode( $raw, true ) : array();
		if ( ! is_array( $data ) ) {
			$data = array();
		}

		if ( $code_status < 200 || $code_status >= 300 ) {
			return new WP_Error( 'google_oauth_error', self::extract_google_error_message( $data ), array( 'status' => $code_status ) );
		}

		if ( empty( $data['refresh_token'] ) ) {
			return new WP_Error( 'google_refresh_token_missing', 'Google did not return a refresh token. Revoke this app in your Google Account permissions and connect again.' );
		}

		$encrypted_refresh = self::encrypt_value( (string) $data['refresh_token'] );
		if ( is_wp_error( $encrypted_refresh ) ) {
			return $encrypted_refresh;
		}

		$settings = self::get_settings( true );
		$settings['gsc_oauth_refresh_token'] = $encrypted_refresh;
		$settings['gsc_oauth_connected_user_id'] = absint( $user_id );
		$settings['gsc_oauth_connected_at'] = current_time( 'mysql' );
		$settings['gsc_enabled'] = '1';
		$settings['gsc_last_test_status'] = 'Google account connected.';
		$settings['gsc_last_tested_at'] = current_time( 'mysql' );
		unset( $settings['gsc_credentials'], $settings['gsc_client_email'], $settings['gsc_project_id'] );

		update_option( self::OPTION_NAME, self::strip_runtime_settings( $settings ), false );
		self::clear_access_token_cache();
		self::clear_cache();

		return self::get_public_settings();
	}

	private static function encrypt_value( $plain_text ) {
		if ( '' === (string) $plain_text ) {
			return '';
		}

		if ( ! function_exists( 'openssl_encrypt' ) || ! in_array( 'aes-256-gcm', openssl_get_cipher_methods(), true ) ) {
			return new WP_Error( 'openssl_missing', 'OpenSSL AES-256-GCM support is required to store Google OAuth tokens securely.' );
		}

		$key = self::encryption_key();
		try {
			$iv = random_bytes( 12 );
		} catch ( Exception $e ) {
			return new WP_Error( 'encryption_failed', 'Could not generate secure random bytes for Google OAuth encryption.' );
		}
		$tag = '';
		$ciphertext = openssl_encrypt( (string) $plain_text, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );

		if ( false === $ciphertext || '' === $tag ) {
			return new WP_Error( 'encryption_failed', 'Could not encrypt Google OAuth data.' );
		}

		return 'gcm:v1:' . base64_encode( $iv ) . ':' . base64_encode( $tag ) . ':' . base64_encode( $ciphertext );
	}

	private static function decrypt_value( $encrypted_text ) {
		if ( '' === (string) $encrypted_text ) {
			return '';
		}

		if ( 0 !== strpos( (string) $encrypted_text, 'gcm:v1:' ) ) {
			return new WP_Error( 'unsupported_encryption', 'Stored Google OAuth data uses an unsupported encryption format.' );
		}

		$parts = explode( ':', (string) $encrypted_text, 5 );
		if ( 5 !== count( $parts ) ) {
			return new WP_Error( 'invalid_encrypted_value', 'Stored Google OAuth data is malformed.' );
		}

		$iv = base64_decode( $parts[2], true );
		$tag = base64_decode( $parts[3], true );
		$ciphertext = base64_decode( $parts[4], true );
		if ( false === $iv || false === $tag || false === $ciphertext ) {
			return new WP_Error( 'invalid_encrypted_value', 'Stored Google OAuth data is malformed.' );
		}

		$plain = openssl_decrypt( $ciphertext, 'aes-256-gcm', self::encryption_key(), OPENSSL_RAW_DATA, $iv, $tag );
		if ( false === $plain ) {
			return new WP_Error( 'decryption_failed', 'Stored Google OAuth data could not be decrypted. Check that WordPress salts have not changed.' );
		}

		return $plain;
	}

	private static function encryption_key() {
		$material = '';
		if ( defined( 'SECURE_AUTH_KEY' ) ) {
			$material .= SECURE_AUTH_KEY;
		}
		if ( defined( 'SECURE_AUTH_SALT' ) ) {
			$material .= SECURE_AUTH_SALT;
		}
		$material .= wp_salt( 'auth' );
		return hash( 'sha256', $material, true );
	}

	private static function require_site_url( $site_url ) {
		$settings = self::get_settings( false );
		$value = '' !== trim( (string) $site_url ) ? $site_url : $settings['gsc_site_url'];
		$normalized = self::normalize_site_url( $value );
		if ( is_wp_error( $normalized ) ) {
			throw new Exception( esc_html( $normalized->get_error_message() ) );
		}
		return $normalized;
	}

	private static function sanitize_site_setting( $site_url ) {
		$site_url = trim( (string) $site_url );
		if ( '' === $site_url ) {
			return '';
		}

		$normalized = self::normalize_site_url( $site_url );
		return is_wp_error( $normalized ) ? '' : $normalized;
	}

	private static function normalize_site_url( $site_url ) {
		$site_url = trim( (string) $site_url );
		if ( '' === $site_url ) {
			return new WP_Error( 'missing_site_url', 'A GSC site_url property is required.' );
		}

		if ( 0 === strpos( $site_url, 'sc-domain:' ) ) {
			$domain = substr( $site_url, 10 );
			$domain = strtolower( trim( $domain ) );
			if ( ! preg_match( '/^[a-z0-9.-]+\.[a-z]{2,}$/i', $domain ) ) {
				return new WP_Error( 'invalid_domain_property', 'sc-domain properties must look like sc-domain:example.com.' );
			}
			return 'sc-domain:' . $domain;
		}

		$site_url = esc_url_raw( $site_url );
		if ( '' === $site_url || ! wp_http_validate_url( $site_url ) ) {
			return new WP_Error( 'invalid_site_url', 'site_url must be a valid http or https URL, or sc-domain:example.com.' );
		}

		$scheme = wp_parse_url( $site_url, PHP_URL_SCHEME );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return new WP_Error( 'invalid_site_url', 'site_url must use http or https.' );
		}

		return trailingslashit( $site_url );
	}

	private static function sanitize_date( $value, $field ) {
		$value = trim( (string) $value );
		$dt = DateTime::createFromFormat( 'Y-m-d', $value );
		if ( ! $dt || $dt->format( 'Y-m-d' ) !== $value ) {
			throw new Exception( esc_html( $field ) . ' must be a valid YYYY-MM-DD date.' );
		}
		return $value;
	}

	private static function default_date_range() {
		$end_ts = strtotime( '-2 days', current_time( 'timestamp', true ) );
		$start_ts = strtotime( '-27 days', $end_ts );
		return array(
			'start_date' => gmdate( 'Y-m-d', $start_ts ),
			'end_date' => gmdate( 'Y-m-d', $end_ts ),
		);
	}

	private static function sanitize_dimensions( $dimensions, $empty_means_total = false ) {
		$allowed = array( 'query', 'page', 'country', 'device', 'searchAppearance', 'date' );
		if ( ! is_array( $dimensions ) ) {
			return array( 'query' );
		}

		if ( empty( $dimensions ) ) {
			if ( $empty_means_total ) {
				return array();
			}
			return array( 'query' );
		}

		$out = array();
		foreach ( $dimensions as $dimension ) {
			$dimension = sanitize_text_field( (string) $dimension );
			if ( in_array( $dimension, $allowed, true ) && ! in_array( $dimension, $out, true ) ) {
				$out[] = $dimension;
			}
		}

		return empty( $out ) ? array( 'query' ) : array_slice( $out, 0, 5 );
	}

	private static function sanitize_filters( $filters ) {
		if ( ! is_array( $filters ) ) {
			return array();
		}

		$allowed_dimensions = array( 'query', 'page', 'country', 'device', 'searchAppearance' );
		$allowed_operators = array( 'contains', 'equals', 'notContains', 'notEquals', 'includingRegex', 'excludingRegex' );
		$out = array();

		foreach ( $filters as $filter ) {
			if ( ! is_array( $filter ) ) {
				continue;
			}
			$dimension = self::sanitize_enum( self::array_value( $filter, 'dimension', '' ), $allowed_dimensions, '' );
			$operator = self::sanitize_enum( self::array_value( $filter, 'operator', 'contains' ), $allowed_operators, 'contains' );
			$expression = sanitize_text_field( (string) self::array_value( $filter, 'expression', '' ) );
			if ( '' === $dimension || '' === $expression ) {
				continue;
			}
			$out[] = array(
				'dimension' => $dimension,
				'operator' => $operator,
				'expression' => $expression,
			);
		}

		return array_slice( $out, 0, 10 );
	}

	private static function get_post_context_for_url( $url ) {
		$post_id = url_to_postid( $url );
		$home = trailingslashit( home_url( '/' ) );
		if ( ! $post_id && trailingslashit( $url ) === $home ) {
			$post_id = (int) get_option( 'page_on_front' );
		}

		if ( ! $post_id ) {
			return null;
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return null;
		}

		return array(
			'id' => (int) $post_id,
			'post_type' => $post->post_type,
			'post_status' => $post->post_status,
			'title' => get_the_title( $post_id ),
			'edit_url' => get_edit_post_link( $post_id, 'raw' ),
			'permalink' => get_permalink( $post_id ),
			'seo' => self::get_post_seo_meta( $post_id ),
		);
	}

	private static function get_post_seo_meta( $post_id ) {
		$yoast_title = get_post_meta( $post_id, '_yoast_wpseo_title', true );
		$yoast_description = get_post_meta( $post_id, '_yoast_wpseo_metadesc', true );
		$rankmath_title = get_post_meta( $post_id, 'rank_math_title', true );
		$rankmath_description = get_post_meta( $post_id, 'rank_math_description', true );

		return array(
			'yoast' => array(
				'title' => is_string( $yoast_title ) ? $yoast_title : '',
				'description' => is_string( $yoast_description ) ? $yoast_description : '',
				'focus_keyword' => (string) get_post_meta( $post_id, '_yoast_wpseo_focuskw', true ),
			),
			'rank_math' => array(
				'title' => is_string( $rankmath_title ) ? $rankmath_title : '',
				'description' => is_string( $rankmath_description ) ? $rankmath_description : '',
				'focus_keyword' => (string) get_post_meta( $post_id, 'rank_math_focus_keyword', true ),
			),
		);
	}

	private static function resolve_post_from_args( $args ) {
		$post_id = absint( self::array_value( $args, 'post_id', 0 ) );
		$url = trim( (string) self::array_value( $args, 'url', '' ) );

		if ( ! $post_id && '' !== $url ) {
			$post_id = url_to_postid( esc_url_raw( $url ) );
			$home = trailingslashit( home_url( '/' ) );
			if ( ! $post_id && trailingslashit( $url ) === $home ) {
				$post_id = (int) get_option( 'page_on_front' );
			}
		}

		if ( ! $post_id ) {
			throw new Exception( 'Provide a valid post_id or URL.' );
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			throw new Exception( 'Post not found.' );
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			throw new Exception( 'You do not have permission to read this post context.' );
		}

		return $post;
	}

	private static function build_post_context( $post_id, $provider = 'auto' ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return array();
		}

		$resolved_provider = self::resolve_seo_provider( $post_id, $provider, false );
		$content = self::normalize_spaces( wp_strip_all_tags( strip_shortcodes( (string) $post->post_content ) ) );
		$excerpt = self::normalize_spaces( wp_strip_all_tags( (string) $post->post_excerpt ) );
		if ( '' === $excerpt ) {
			$excerpt = wp_trim_words( $content, 35, '' );
		}

		$seo = self::get_post_seo_meta( $post_id );
		$seo['active_provider'] = $resolved_provider;
		$seo['current'] = 'none' !== $resolved_provider ? self::get_provider_current_meta( $post_id, $resolved_provider ) : array(
			'title' => '',
			'meta_description' => '',
			'focus_keyword' => '',
		);
		$seo['plugins'] = array(
			'yoast_active' => self::is_yoast_active(),
			'rank_math_active' => self::is_rank_math_active(),
		);

		return array(
			'id' => (int) $post_id,
			'post_type' => $post->post_type,
			'post_status' => $post->post_status,
			'title' => get_the_title( $post_id ),
			'slug' => $post->post_name,
			'excerpt' => $excerpt,
			'content_summary' => wp_trim_words( $content, 90, '' ),
			'modified_gmt' => $post->post_modified_gmt,
			'permalink' => get_permalink( $post_id ),
			'edit_url' => get_edit_post_link( $post_id, 'raw' ),
			'seo' => $seo,
		);
	}

	private static function get_post_gsc_context( $permalink, $args, $start_date, $end_date, $query_limit ) {
		$query_args = array(
			'site_url' => self::array_value( $args, 'site_url', '' ),
			'start_date' => $start_date,
			'end_date' => $end_date,
			'dimensions' => array( 'query' ),
			'search_type' => 'web',
			'filters' => array(
				array(
					'dimension' => 'page',
					'operator' => 'equals',
					'expression' => $permalink,
				),
			),
			'row_limit' => $query_limit,
		);

		$performance = self::query_performance( $query_args );
		$rows = isset( $performance['rows'] ) && is_array( $performance['rows'] ) ? $performance['rows'] : array();
		$top_queries = array();

		foreach ( $rows as $row ) {
			$keys = isset( $row['keys'] ) && is_array( $row['keys'] ) ? $row['keys'] : array();
			$top_queries[] = array(
				'query' => isset( $keys[0] ) ? sanitize_text_field( (string) $keys[0] ) : '',
				'clicks' => isset( $row['clicks'] ) ? (float) $row['clicks'] : 0.0,
				'impressions' => isset( $row['impressions'] ) ? (float) $row['impressions'] : 0.0,
				'ctr' => isset( $row['ctr'] ) ? (float) $row['ctr'] : 0.0,
				'position' => isset( $row['position'] ) ? (float) $row['position'] : 0.0,
			);
		}

		return array(
			'site_url' => isset( $performance['site_url'] ) ? $performance['site_url'] : '',
			'page_url' => $permalink,
			'start_date' => $start_date,
			'end_date' => $end_date,
			'metrics_summary' => isset( $performance['metrics_summary'] ) ? $performance['metrics_summary'] : array(),
			'row_count' => isset( $performance['row_count'] ) ? (int) $performance['row_count'] : count( $top_queries ),
			'query_limit' => $query_limit,
			'top_queries' => $top_queries,
		);
	}

	private static function summarize_post_gsc_context( $gsc ) {
		$metrics = isset( $gsc['metrics_summary'] ) && is_array( $gsc['metrics_summary'] ) ? $gsc['metrics_summary'] : array();
		$impressions = isset( $metrics['total_impressions'] ) ? (float) $metrics['total_impressions'] : 0.0;
		$ctr = isset( $metrics['average_ctr'] ) ? (float) $metrics['average_ctr'] : 0.0;
		$position = isset( $metrics['average_position'] ) ? (float) $metrics['average_position'] : 0.0;
		$signals = array();

		if ( $impressions <= 0 ) {
			$signals[] = 'no_gsc_rows_for_page';
		}
		if ( $impressions >= 100 && $ctr > 0 && $ctr < 0.03 ) {
			$signals[] = 'high_impressions_low_ctr';
		}
		if ( $position >= 4 && $position <= 20 ) {
			$signals[] = 'striking_distance';
		}

		return array(
			'signals' => $signals,
			'recommendation' => in_array( 'high_impressions_low_ctr', $signals, true )
				? 'Review title and meta description for stronger query-intent alignment.'
				: 'Use top queries as context before changing metadata.',
		);
	}

	private static function resolve_seo_provider( $post_id, $requested = 'auto', $require_active = false ) {
		$requested = self::sanitize_enum( $requested, array( 'auto', 'yoast', 'rank_math' ), 'auto' );
		$yoast_active = self::is_yoast_active();
		$rank_math_active = self::is_rank_math_active();

		if ( 'yoast' === $requested ) {
			if ( $require_active && ! $yoast_active ) {
				throw new Exception( 'Yoast SEO plugin is not active.' );
			}
			return $yoast_active || ! $require_active ? 'yoast' : 'none';
		}

		if ( 'rank_math' === $requested ) {
			if ( $require_active && ! $rank_math_active ) {
				throw new Exception( 'Rank Math SEO plugin is not active.' );
			}
			return $rank_math_active || ! $require_active ? 'rank_math' : 'none';
		}

		$rank_math = self::get_provider_current_meta( $post_id, 'rank_math' );
		$yoast = self::get_provider_current_meta( $post_id, 'yoast' );

		if ( $rank_math_active && self::provider_meta_has_values( $rank_math ) ) {
			return 'rank_math';
		}
		if ( $yoast_active && self::provider_meta_has_values( $yoast ) ) {
			return 'yoast';
		}
		if ( $rank_math_active ) {
			return 'rank_math';
		}
		if ( $yoast_active ) {
			return 'yoast';
		}
		if ( ! $require_active && self::provider_meta_has_values( $rank_math ) ) {
			return 'rank_math';
		}
		if ( ! $require_active && self::provider_meta_has_values( $yoast ) ) {
			return 'yoast';
		}

		return 'none';
	}

	private static function provider_meta_has_values( $meta ) {
		return is_array( $meta ) && ( '' !== (string) $meta['title'] || '' !== (string) $meta['meta_description'] || '' !== (string) $meta['focus_keyword'] );
	}

	private static function get_provider_current_meta( $post_id, $provider ) {
		if ( 'rank_math' === $provider ) {
			return array(
				'title' => (string) get_post_meta( $post_id, 'rank_math_title', true ),
				'meta_description' => (string) get_post_meta( $post_id, 'rank_math_description', true ),
				'focus_keyword' => (string) get_post_meta( $post_id, 'rank_math_focus_keyword', true ),
			);
		}

		return array(
			'title' => (string) get_post_meta( $post_id, '_yoast_wpseo_title', true ),
			'meta_description' => (string) get_post_meta( $post_id, '_yoast_wpseo_metadesc', true ),
			'focus_keyword' => (string) get_post_meta( $post_id, '_yoast_wpseo_focuskw', true ),
		);
	}

	private static function update_provider_meta( $post_id, $provider, $title, $description, $focus_keyword ) {
		$map = 'rank_math' === $provider
			? array(
				'title' => 'rank_math_title',
				'meta_description' => 'rank_math_description',
				'focus_keyword' => 'rank_math_focus_keyword',
			)
			: array(
				'title' => '_yoast_wpseo_title',
				'meta_description' => '_yoast_wpseo_metadesc',
				'focus_keyword' => '_yoast_wpseo_focuskw',
			);

		if ( null !== $title ) {
			update_post_meta( $post_id, $map['title'], $title );
		}
		if ( null !== $description ) {
			update_post_meta( $post_id, $map['meta_description'], $description );
		}
		if ( null !== $focus_keyword ) {
			update_post_meta( $post_id, $map['focus_keyword'], $focus_keyword );
		}
	}

	private static function clear_provider_cache( $post_id, $provider ) {
		if ( 'yoast' === $provider ) {
			delete_post_meta( $post_id, '_yoast_wpseo_content_score' );
			if ( function_exists( 'YoastSEO' ) && class_exists( 'Yoast\\WP\\SEO\\Repositories\\Indexable_Repository' ) ) {
				try {
					$repo = \YoastSEO()->classes->get( 'Yoast\\WP\\SEO\\Repositories\\Indexable_Repository' );
					$indexable = $repo ? $repo->find_by_id_and_type( $post_id, 'post', false ) : null;
					if ( $indexable ) {
						$indexable->delete();
					}
				} catch ( Exception $e ) {
					stifli_flex_mcp_log( 'Yoast SEO cache clear failed: ' . $e->getMessage() );
				}
			}
		}

		clean_post_cache( $post_id );
	}

	private static function compose_title_candidate( $post_title, $query, $site_name, $variant ) {
		$post_title = self::normalize_spaces( $post_title );
		$query = self::normalize_spaces( $query );
		$site_name = self::normalize_spaces( $site_name );

		if ( '' === $query ) {
			return self::limit_text( '' !== $site_name ? $post_title . ' | ' . $site_name : $post_title, 60 );
		}

		if ( false !== stripos( $post_title, $query ) ) {
			return self::limit_text( 3 === (int) $variant && '' !== $site_name ? $post_title . ' | ' . $site_name : $post_title, 60 );
		}

		if ( 1 === (int) $variant ) {
			return self::limit_text( ucfirst( $query ) . ' | ' . $post_title, 60 );
		}
		if ( 2 === (int) $variant ) {
			return self::limit_text( $post_title . ': ' . $query, 60 );
		}

		return self::limit_text( '' !== $site_name ? $post_title . ' | ' . $site_name : $post_title, 60 );
	}

	private static function compose_description_candidate( $summary, $query, $variant ) {
		$summary = self::normalize_spaces( $summary );
		$query = self::normalize_spaces( $query );
		if ( '' === $summary ) {
			return self::limit_text( $query, 155 );
		}
		if ( '' === $query || false !== stripos( $summary, $query ) ) {
			return self::limit_text( $summary, 155 );
		}
		if ( 2 === (int) $variant ) {
			return self::limit_text( $summary . ' ' . ucfirst( $query ) . '.', 155 );
		}
		return self::limit_text( ucfirst( $query ) . ': ' . $summary, 155 );
	}

	private static function build_suggestion_rationale( $query_terms, $analysis ) {
		$signals = isset( $analysis['signals'] ) && is_array( $analysis['signals'] ) ? $analysis['signals'] : array();
		$primary = isset( $query_terms[0] ) ? $query_terms[0] : '';
		if ( '' !== $primary ) {
			return 'Uses top GSC query "' . $primary . '" and current post content. Signals: ' . implode( ', ', $signals );
		}
		return 'Uses current post content because GSC returned no query rows for this page.';
	}

	private static function get_title_meta_warnings( $title, $description ) {
		$warnings = array();
		$title = (string) $title;
		$description = (string) $description;
		$title_len = strlen( $title );
		$description_len = strlen( $description );

		if ( $title_len > 65 ) {
			$warnings[] = 'title_may_truncate';
		}
		if ( $title_len > 0 && $title_len < 20 ) {
			$warnings[] = 'title_short';
		}
		if ( $description_len > 160 ) {
			$warnings[] = 'meta_description_may_truncate';
		}
		if ( $description_len > 0 && $description_len < 70 ) {
			$warnings[] = 'meta_description_short';
		}

		return $warnings;
	}

	private static function sanitize_title_meta_value( $value, $field, $max_length ) {
		$value = self::normalize_spaces( sanitize_text_field( (string) $value ) );
		if ( '' === $value && 'focus_keyword' !== $field ) {
			throw new Exception( esc_html( $field ) . ' cannot be empty when provided.' );
		}
		if ( strlen( $value ) > (int) $max_length ) {
			throw new Exception( esc_html( $field ) . ' is too long. Max ' . (int) $max_length . ' characters.' );
		}
		return $value;
	}

	private static function is_yoast_active() {
		return defined( 'WPSEO_VERSION' ) || function_exists( 'YoastSEO' );
	}

	private static function is_rank_math_active() {
		return defined( 'RANK_MATH_VERSION' ) || function_exists( 'rank_math' );
	}

	private static function normalize_spaces( $value ) {
		return trim( preg_replace( '/\s+/', ' ', (string) $value ) );
	}

	private static function limit_text( $text, $max_chars ) {
		$text = self::normalize_spaces( $text );
		$max_chars = max( 1, (int) $max_chars );
		if ( strlen( $text ) <= $max_chars ) {
			return $text;
		}

		$cut = substr( $text, 0, $max_chars );
		$space = strrpos( $cut, ' ' );
		if ( false !== $space && $space > 20 ) {
			$cut = substr( $cut, 0, $space );
		}
		return rtrim( $cut, " \t\n\r\0\x0B.,;:-" );
	}

	private static function build_change_post_snapshot( $post_id ) {
		$post = get_post( $post_id, ARRAY_A );
		if ( ! $post ) {
			return null;
		}

		$post['meta'] = get_post_meta( $post_id );
		return array(
			'operation_type' => 'update',
			'object_type' => 'post',
			'object_id' => (int) $post_id,
			'object_subtype' => isset( $post['post_type'] ) ? $post['post_type'] : null,
			'before_state' => $post,
		);
	}

	private static function build_recommendation( $reason, $post_context ) {
		if ( 'high_impressions_low_ctr' === $reason ) {
			return $post_context
				? 'Review title and meta description using the top queries. Prioritize CTR intent alignment before content changes.'
				: 'Review SERP title and description for this URL. Map it to a WordPress post before applying metadata changes.';
		}

		return $post_context
			? 'Improve the section that targets the top queries and strengthen internal links to this page.'
			: 'This URL is close to page-one growth. Map it to content and review topical coverage for the top queries.';
	}

	private static function expected_ctr_for_position( $position ) {
		if ( $position <= 1.5 ) {
			return 0.22;
		}
		if ( $position <= 3 ) {
			return 0.12;
		}
		if ( $position <= 5 ) {
			return 0.08;
		}
		if ( $position <= 10 ) {
			return 0.04;
		}
		if ( $position <= 20 ) {
			return 0.02;
		}
		return 0.01;
	}

	private static function cache_key( $kind, $data ) {
		$version = (int) get_option( self::CACHE_VERSION_OPT, 1 );
		return 'sflmcp_gsc_' . sanitize_key( $kind ) . '_' . $version . '_' . md5( wp_json_encode( $data ) );
	}

	private static function get_cached( $key ) {
		$ttl = self::get_cache_ttl();
		if ( $ttl <= 0 ) {
			return false;
		}
		return get_transient( $key );
	}

	private static function set_cached( $key, $value ) {
		$ttl = self::get_cache_ttl();
		if ( $ttl <= 0 ) {
			return;
		}
		set_transient( $key, $value, $ttl );
	}

	private static function get_cache_ttl() {
		$settings = self::get_settings( false );
		return self::sanitize_cache_ttl( $settings['gsc_cache_ttl'] );
	}

	private static function sanitize_cache_ttl( $value ) {
		return self::sanitize_int_range( $value, 0, 86400, 900 );
	}

	private static function sanitize_int_range( $value, $min, $max, $default ) {
		if ( is_numeric( $value ) ) {
			$value = (int) $value;
		} else {
			$value = (int) $default;
		}
		return max( (int) $min, min( (int) $max, $value ) );
	}

	private static function sanitize_float_range( $value, $min, $max, $default ) {
		if ( is_numeric( $value ) ) {
			$value = (float) $value;
		} else {
			$value = (float) $default;
		}
		return max( (float) $min, min( (float) $max, $value ) );
	}

	private static function sanitize_enum( $value, $allowed, $default ) {
		$value = sanitize_text_field( (string) $value );
		return in_array( $value, $allowed, true ) ? $value : $default;
	}

	private static function sanitize_oauth_client_id( $value ) {
		$value = trim( (string) $value );
		$value = sanitize_text_field( $value );
		return preg_replace( '/[^A-Za-z0-9._:-]/', '', $value );
	}

	private static function array_value( $array, $key, $default = null ) {
		return is_array( $array ) && array_key_exists( $key, $array ) ? $array[ $key ] : $default;
	}

	private static function array_non_empty_value( $array, $key, $default = null ) {
		if ( ! is_array( $array ) || ! array_key_exists( $key, $array ) ) {
			return $default;
		}

		$value = $array[ $key ];
		if ( null === $value ) {
			return $default;
		}
		if ( is_string( $value ) && '' === trim( $value ) ) {
			return $default;
		}

		return $value;
	}

	private static function has_non_empty_arg( $array, $key ) {
		if ( ! is_array( $array ) || ! array_key_exists( $key, $array ) ) {
			return false;
		}

		$value = $array[ $key ];
		if ( null === $value ) {
			return false;
		}
		if ( is_string( $value ) && '' === trim( $value ) ) {
			return false;
		}

		return true;
	}

	private static function get_connected_user_label( $settings ) {
		$user_id = isset( $settings['gsc_oauth_connected_user_id'] ) ? absint( $settings['gsc_oauth_connected_user_id'] ) : 0;
		if ( ! $user_id ) {
			return '';
		}

		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return '';
		}

		return $user->display_name ? $user->display_name : $user->user_login;
	}

	private static function strip_runtime_settings( $settings ) {
		if ( ! is_array( $settings ) ) {
			return array();
		}

		unset(
			$settings['configured'],
			$settings['client_configured'],
			$settings['redirect_uri'],
			$settings['gsc_oauth_client_secret_configured'],
			$settings['gsc_oauth_refresh_token_configured'],
			$settings['gsc_oauth_connected_user']
		);

		return $settings;
	}

	private static function is_truthy( $value ) {
		if ( is_bool( $value ) ) {
			return $value;
		}
		if ( is_int( $value ) ) {
			return 1 === $value;
		}
		if ( is_string( $value ) ) {
			return in_array( strtolower( trim( $value ) ), array( '1', 'true', 'yes', 'on' ), true );
		}
		return ! empty( $value );
	}

	private static function extract_google_error_message( $data ) {
		if ( isset( $data['error']['message'] ) ) {
			return sanitize_text_field( (string) $data['error']['message'] );
		}
		if ( isset( $data['error_description'] ) ) {
			return sanitize_text_field( (string) $data['error_description'] );
		}
		if ( isset( $data['error'] ) && is_string( $data['error'] ) ) {
			return sanitize_text_field( $data['error'] );
		}
		return 'Google API request failed.';
	}

	private static function clear_access_token_cache() {
		$credentials = self::get_oauth_credentials( true );
		if ( is_wp_error( $credentials ) ) {
			return;
		}
		delete_transient( self::TOKEN_TRANSIENT . md5( $credentials['client_id'] . '|' . $credentials['refresh_token'] ) );
	}

	private static function set_result_payload( &$r, $payload, $addResultText ) {
		if ( ! isset( $r['result'] ) || ! is_array( $r['result'] ) ) {
			$r['result'] = array();
		}
		$r['result']['structuredContent'] = $payload;
		$encoded = wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		$addResultText( $r, false !== $encoded ? $encoded : (string) maybe_serialize( $payload ) );
	}

	public static function sort_queries_by_impressions( $a, $b ) {
		return (float) $b['impressions'] <=> (float) $a['impressions'];
	}

	public static function sort_opportunities( $a, $b ) {
		return (float) $b['score'] <=> (float) $a['score'];
	}
}
