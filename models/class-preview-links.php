<?php
/**
 * Temporary shareable preview links for non-public posts.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class StifliFlexMcp_Preview_Links {

	const QUERY_VAR = 'sflmcp_preview';

	private static $active_post_id = 0;

	public static function init() {
		add_action( 'pre_get_posts', array( __CLASS__, 'authorize_preview_query' ), 1 );
		add_filter( 'redirect_canonical', array( __CLASS__, 'disable_canonical_redirect' ), 10, 2 );
		add_action( 'template_redirect', array( __CLASS__, 'send_private_preview_headers' ), 0 );
	}

	public static function create( $post_id, $ttl_minutes = 60 ) {
		$post_id = absint( $post_id );
		$post = $post_id > 0 ? get_post( $post_id ) : null;
		if ( ! $post ) {
			return new WP_Error( 'post_not_found', 'Post not found.' );
		}
		if ( 'publish' === $post->post_status ) {
			return new WP_Error( 'already_public', 'Published posts already have a public permalink.' );
		}
		if ( ! in_array( $post->post_status, array( 'draft', 'pending', 'future' ), true ) ) {
			return new WP_Error( 'unsupported_status', 'Preview links are limited to draft, pending, or scheduled posts.' );
		}

		$ttl_minutes = max( 5, min( 10080, absint( $ttl_minutes ) ) );
		$token = wp_generate_password( 48, false, false );
		$key = self::transient_key( $token );
		$expires_at = time() + ( $ttl_minutes * MINUTE_IN_SECONDS );
		set_transient(
			$key,
			array( 'post_id' => $post_id, 'expires_at' => $expires_at ),
			$ttl_minutes * MINUTE_IN_SECONDS
		);

		$url = add_query_arg( self::QUERY_VAR, rawurlencode( $token ), get_permalink( $post_id ) );
		return array(
			'post_id' => $post_id,
			'preview_url' => $url,
			'expires_at' => gmdate( 'c', $expires_at ),
			'ttl_minutes' => $ttl_minutes,
		);
	}

	public static function authorize_preview_query( $query ) {
		if ( is_admin() || ! $query->is_main_query() ) {
			return;
		}
		$data = self::get_request_data();
		if ( ! $data ) {
			return;
		}

		self::$active_post_id = (int) $data['post_id'];
		$post = get_post( self::$active_post_id );
		if ( ! $post || 'publish' === $post->post_status ) {
			self::$active_post_id = 0;
			return;
		}

		$query->set( 'p', self::$active_post_id );
		$query->set( 'page_id', 0 );
		$query->set( 'post_type', $post->post_type );
		$query->set( 'post_status', array( 'draft', 'pending', 'future' ) );
		$query->set( 'name', '' );
		$query->set( 'pagename', '' );
		$query->is_preview = true;
		$query->is_singular = true;
	}

	public static function disable_canonical_redirect( $redirect_url, $requested_url ) {
		return self::$active_post_id > 0 ? false : $redirect_url;
	}

	public static function send_private_preview_headers() {
		if ( self::$active_post_id <= 0 ) {
			return;
		}
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow, noarchive', true );
	}

	private static function get_request_data() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- random bearer preview token is the authorization credential.
		$token = isset( $_GET[ self::QUERY_VAR ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::QUERY_VAR ] ) ) : '';
		if ( '' === $token || strlen( $token ) < 32 ) {
			return null;
		}
		$data = get_transient( self::transient_key( $token ) );
		if ( ! is_array( $data ) || empty( $data['post_id'] ) || empty( $data['expires_at'] ) || time() > (int) $data['expires_at'] ) {
			return null;
		}
		return $data;
	}

	private static function transient_key( $token ) {
		return 'sflmcp_preview_' . hash( 'sha256', (string) $token );
	}
}