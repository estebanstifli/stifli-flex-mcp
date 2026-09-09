<?php
/**
 * Divi MCP integration.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class StifliFlexMcp_Divi {

	const LIBRARY_POST_TYPE = 'et_pb_layout';

	public static function isAvailable() {
		$theme = wp_get_theme();
		$template = strtolower( (string) $theme->get_template() );
		$stylesheet = strtolower( (string) $theme->get_stylesheet() );

		return defined( 'ET_BUILDER_VERSION' )
			|| class_exists( 'ET_Builder_Plugin' )
			|| in_array( $template, array( 'divi', 'extra' ), true )
			|| in_array( $stylesheet, array( 'divi', 'extra' ), true )
			|| self::is_plugin_active( 'divi-builder/divi-builder.php' );
	}

	public static function getTools() {
		return array(
			'divi_get_page_format' => array(
				'name' => 'divi_get_page_format',
				'description' => 'Inspect whether a post uses Divi Builder and return its builder mode, page layout, shortcode counts, and content size.',
				'inputSchema' => self::post_id_schema(),
			),
			'divi_get_page_outline' => array(
				'name' => 'divi_get_page_outline',
				'description' => 'Return a compact ordered outline of Divi section, row, column, and module shortcodes without exposing the full layout.',
				'inputSchema' => self::post_id_schema(),
			),
			'divi_validate_layout' => array(
				'name' => 'divi_validate_layout',
				'description' => 'Validate the nesting and balance of Divi shortcodes in a post or supplied layout content without executing shortcodes.',
				'inputSchema' => array(
					'type' => 'object',
					'properties' => array(
						'post_id' => array( 'type' => 'integer', 'description' => 'Post ID containing the Divi layout.' ),
						'content' => array( 'type' => 'string', 'description' => 'Optional Divi shortcode content to validate instead of loading a post.' ),
					),
					'required' => array(),
				),
			),
			'divi_list_local_templates' => array(
				'name' => 'divi_list_local_templates',
				'description' => 'List layouts from the local Divi Library with type, status, modification date, and content size.',
				'inputSchema' => array(
					'type' => 'object',
					'properties' => array(
						'layout_type' => array( 'type' => 'string', 'description' => 'Optional Divi layout type slug.' ),
						'limit' => array( 'type' => 'integer', 'description' => 'Maximum layouts to return. Default 50, max 200.' ),
					),
					'required' => array(),
				),
			),
			'divi_library_get' => array(
				'name' => 'divi_library_get',
				'description' => 'Get one local Divi Library layout with its shortcode content, format metadata, and compact outline.',
				'inputSchema' => array(
					'type' => 'object',
					'properties' => array(
						'layout_id' => array( 'type' => 'integer' ),
					),
					'required' => array( 'layout_id' ),
				),
			),
			'divi_replace_text' => array(
				'name' => 'divi_replace_text',
				'description' => 'Replace visible text between Divi shortcodes while preserving shortcode tags and attributes. Supports Unicode matching and dry_run.',
				'inputSchema' => array(
					'type' => 'object',
					'properties' => array(
						'post_id' => array( 'type' => 'integer' ),
						'find' => array( 'type' => 'string' ),
						'replace' => array( 'type' => 'string' ),
						'case_insensitive' => array( 'type' => 'boolean', 'description' => 'Default false.' ),
						'dry_run' => array( 'type' => 'boolean', 'description' => 'Preview replacements without saving.' ),
					),
					'required' => array( 'post_id', 'find', 'replace' ),
				),
			),
		);
	}

	public static function getCapabilities() {
		return array(
			'divi_get_page_format' => 'edit_posts',
			'divi_get_page_outline' => 'edit_posts',
			'divi_validate_layout' => 'edit_posts',
			'divi_list_local_templates' => 'edit_posts',
			'divi_library_get' => 'edit_posts',
			'divi_replace_text' => 'edit_posts',
		);
	}

	public static function getChangeTrackerSnapshot( $tool, $args ) {
		if ( 'divi_replace_text' !== $tool || self::is_truthy( self::array_value( $args, 'dry_run', false ) ) ) {
			return null;
		}

		$post_id = absint( self::array_value( $args, 'post_id', 0 ) );
		$post = $post_id > 0 ? get_post( $post_id, ARRAY_A ) : null;
		if ( ! $post ) {
			return null;
		}

		$post['meta'] = get_post_meta( $post_id );
		return array(
			'operation_type' => 'update',
			'object_type' => 'post',
			'object_id' => $post_id,
			'object_subtype' => isset( $post['post_type'] ) ? $post['post_type'] : 'post',
			'before_state' => $post,
		);
	}

	public static function dispatch( $tool, $args, &$r, $addResultText, $utils ) {
		if ( 0 !== strpos( (string) $tool, 'divi_' ) ) {
			return null;
		}
		if ( ! self::isAvailable() ) {
			$r['error'] = array( 'code' => -32603, 'message' => 'Divi Builder or a Divi theme is not active.' );
			return true;
		}

		$args = is_array( $args ) ? $args : array();
		try {
			switch ( $tool ) {
				case 'divi_get_page_format':
					$payload = self::get_page_format( $args );
					break;
				case 'divi_get_page_outline':
					$post = self::require_editable_post( $args, 'post_id' );
					$payload = array( 'post_id' => (int) $post->ID, 'outline' => self::build_outline( $post->post_content ) );
					break;
				case 'divi_validate_layout':
					$payload = self::validate_layout( $args );
					break;
				case 'divi_list_local_templates':
					$payload = self::list_local_templates( $args );
					break;
				case 'divi_library_get':
					$payload = self::library_get( $args );
					break;
				case 'divi_replace_text':
					$payload = self::replace_text( $args );
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

	private static function get_page_format( $args ) {
		$post = self::require_editable_post( $args, 'post_id' );
		$validation = self::validate_content( (string) $post->post_content );
		return array(
			'post_id' => (int) $post->ID,
			'post_type' => $post->post_type,
			'post_status' => $post->post_status,
			'uses_divi_builder' => 'on' === get_post_meta( $post->ID, '_et_pb_use_builder', true ) || $validation['shortcode_count'] > 0,
			'builder_mode' => (string) get_post_meta( $post->ID, '_et_pb_use_builder', true ),
			'page_layout' => (string) get_post_meta( $post->ID, '_et_pb_page_layout', true ),
			'content_length' => self::text_length( $post->post_content ),
			'shortcode_count' => $validation['shortcode_count'],
			'layout_valid' => $validation['valid'],
		);
	}

	private static function validate_layout( $args ) {
		$content = self::array_value( $args, 'content', null );
		$post_id = absint( self::array_value( $args, 'post_id', 0 ) );
		if ( null === $content ) {
			$post = self::require_editable_post( array( 'post_id' => $post_id ), 'post_id' );
			$content = $post->post_content;
		} elseif ( ! is_string( $content ) ) {
			throw new Exception( 'content must be a string.' );
		}

		$result = self::validate_content( $content );
		$result['post_id'] = $post_id > 0 ? $post_id : null;
		return $result;
	}

	private static function list_local_templates( $args ) {
		$limit = max( 1, min( 200, absint( self::array_value( $args, 'limit', 50 ) ) ) );
		$query_args = array(
			'post_type' => self::LIBRARY_POST_TYPE,
			'post_status' => array( 'publish', 'draft', 'private' ),
			'posts_per_page' => $limit,
			'orderby' => 'modified',
			'order' => 'DESC',
			'no_found_rows' => true,
		);
		$layout_type = sanitize_key( (string) self::array_value( $args, 'layout_type', '' ) );
		if ( '' !== $layout_type && taxonomy_exists( 'layout_type' ) ) {
			$query_args['tax_query'] = array(
				array( 'taxonomy' => 'layout_type', 'field' => 'slug', 'terms' => $layout_type ),
			);
		}

		$posts = get_posts( $query_args );
		$items = array();
		foreach ( $posts as $post ) {
			$terms = taxonomy_exists( 'layout_type' ) ? wp_get_post_terms( $post->ID, 'layout_type', array( 'fields' => 'slugs' ) ) : array();
			$items[] = array(
				'id' => (int) $post->ID,
				'title' => $post->post_title,
				'status' => $post->post_status,
				'modified' => $post->post_modified,
				'layout_types' => is_wp_error( $terms ) ? array() : $terms,
				'content_length' => self::text_length( $post->post_content ),
			);
		}
		return array( 'count' => count( $items ), 'templates' => $items );
	}

	private static function library_get( $args ) {
		$layout_id = absint( self::array_value( $args, 'layout_id', 0 ) );
		$post = self::require_editable_post( array( 'layout_id' => $layout_id ), 'layout_id' );
		if ( self::LIBRARY_POST_TYPE !== $post->post_type ) {
			throw new Exception( 'Divi Library layout not found.' );
		}

		$terms = taxonomy_exists( 'layout_type' ) ? wp_get_post_terms( $post->ID, 'layout_type', array( 'fields' => 'slugs' ) ) : array();
		return array(
			'id' => (int) $post->ID,
			'title' => $post->post_title,
			'status' => $post->post_status,
			'layout_types' => is_wp_error( $terms ) ? array() : $terms,
			'content' => $post->post_content,
			'outline' => self::build_outline( $post->post_content ),
			'validation' => self::validate_content( $post->post_content ),
		);
	}

	private static function replace_text( $args ) {
		$post = self::require_editable_post( $args, 'post_id' );
		$find = (string) self::array_value( $args, 'find', '' );
		$replace = (string) self::array_value( $args, 'replace', '' );
		$case_insensitive = self::is_truthy( self::array_value( $args, 'case_insensitive', false ) );
		$dry_run = self::is_truthy( self::array_value( $args, 'dry_run', false ) );
		if ( '' === $find ) {
			throw new Exception( 'find is required.' );
		}

		$parts = preg_split( '/(\[\/?et_pb_[^\]]+\])/i', (string) $post->post_content, -1, PREG_SPLIT_DELIM_CAPTURE );
		if ( false === $parts ) {
			throw new Exception( 'Unable to parse Divi shortcode content.' );
		}

		$count = 0;
		foreach ( $parts as $index => $part ) {
			if ( preg_match( '/^\[\/?et_pb_/i', $part ) ) {
				continue;
			}
			$parts[ $index ] = self::replace_value( $part, $find, $replace, $case_insensitive, $count );
		}
		$updated = implode( '', $parts );
		if ( ! $dry_run && $count > 0 ) {
			$result = wp_update_post( array( 'ID' => $post->ID, 'post_content' => wp_slash( $updated ) ), true );
			if ( is_wp_error( $result ) ) {
				throw new Exception( $result->get_error_message() );
			}
			clean_post_cache( $post->ID );
		}

		$payload = array(
			'success' => true,
			'post_id' => (int) $post->ID,
			'replacements' => $count,
			'dry_run' => $dry_run,
			'find' => $find,
			'replace' => $replace,
		);
		if ( self::has_literal_escape_sequence( $replace ) ) {
			$payload['warnings'] = array( 'Replacement text contains literal escape sequences such as \\n, \\t, or escaped quotes. Verify that literal backslashes are intended.' );
		}
		return $payload;
	}

	private static function build_outline( $content ) {
		preg_match_all( '/\[(\/?)((?:et_pb_)[a-z0-9_]+)([^\]]*)\]/i', (string) $content, $matches, PREG_SET_ORDER );
		$depth = 0;
		$outline = array();
		foreach ( $matches as $match ) {
			$is_closing = '/' === $match[1];
			if ( $is_closing ) {
				$depth = max( 0, $depth - 1 );
				continue;
			}
			$raw_attributes = trim( (string) $match[3] );
			$self_closing = '/' === substr( $raw_attributes, -1 );
			$attributes = shortcode_parse_atts( rtrim( $raw_attributes, '/' ) );
			$summary = array();
			if ( is_array( $attributes ) ) {
				foreach ( array( 'admin_label', 'module_id', 'global_module', 'specialty' ) as $key ) {
					if ( isset( $attributes[ $key ] ) ) {
						$summary[ $key ] = sanitize_text_field( (string) $attributes[ $key ] );
					}
				}
			}
			$outline[] = array( 'depth' => $depth, 'tag' => strtolower( $match[2] ), 'attributes' => $summary );
			if ( ! $self_closing ) {
				$depth++;
			}
		}
		return $outline;
	}

	private static function validate_content( $content ) {
		preg_match_all( '/\[(\/?)((?:et_pb_)[a-z0-9_]+)([^\]]*)\]/i', (string) $content, $matches, PREG_SET_ORDER );
		$stack = array();
		$errors = array();
		foreach ( $matches as $index => $match ) {
			$tag = strtolower( $match[2] );
			if ( '/' === $match[1] ) {
				$expected = array_pop( $stack );
				if ( null === $expected ) {
					$errors[] = 'Unexpected closing shortcode [' . $tag . '] at token ' . $index . '.';
				} elseif ( $expected !== $tag ) {
					$errors[] = 'Mismatched closing shortcode [' . $tag . '] at token ' . $index . '; expected [/' . $expected . '].';
				}
			} elseif ( '/' !== substr( trim( (string) $match[3] ), -1 ) ) {
				$stack[] = $tag;
			}
		}
		while ( ! empty( $stack ) ) {
			$errors[] = 'Missing closing shortcode [/' . array_pop( $stack ) . '].';
		}

		return array(
			'valid' => empty( $errors ),
			'shortcode_count' => count( $matches ),
			'errors' => $errors,
		);
	}

	private static function require_editable_post( $args, $key ) {
		$post_id = absint( self::array_value( $args, $key, 0 ) );
		if ( $post_id <= 0 ) {
			throw new Exception( $key . ' is required.' );
		}
		$post = get_post( $post_id );
		if ( ! $post ) {
			throw new Exception( 'Post not found.' );
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			throw new Exception( 'Insufficient permissions to access this post.' );
		}
		return $post;
	}

	private static function replace_value( $value, $find, $replace, $case_insensitive, &$count ) {
		if ( ! $case_insensitive ) {
			return str_replace( $find, $replace, $value, $count );
		}
		$pattern = '/' . preg_quote( $find, '/' ) . '/iu';
		$updated = preg_replace_callback( $pattern, function() use ( $replace ) {
			return $replace;
		}, $value, -1, $count );
		return null === $updated ? str_ireplace( $find, $replace, $value, $count ) : $updated;
	}

	private static function has_literal_escape_sequence( $value ) {
		return 1 === preg_match( '/\\\\(?:n|r|t|["\'\\\\])/', (string) $value );
	}

	private static function post_id_schema() {
		return array(
			'type' => 'object',
			'properties' => array( 'post_id' => array( 'type' => 'integer' ) ),
			'required' => array( 'post_id' ),
		);
	}

	private static function set_result_payload( &$r, $payload, $addResultText ) {
		$r['result'] = array( 'content' => array(), 'structuredContent' => $payload );
		$json = wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( is_callable( $addResultText ) ) {
			$addResultText( $r, $json );
		} else {
			$r['result']['content'][] = array( 'type' => 'text', 'text' => $json );
		}
	}

	private static function text_length( $value ) {
		return function_exists( 'mb_strlen' ) ? mb_strlen( (string) $value, 'UTF-8' ) : strlen( (string) $value );
	}

	private static function is_plugin_active( $plugin_file ) {
		$active = (array) get_option( 'active_plugins', array() );
		if ( in_array( $plugin_file, $active, true ) ) {
			return true;
		}
		return is_multisite() && isset( ((array) get_site_option( 'active_sitewide_plugins', array() ))[ $plugin_file ] );
	}

	private static function array_value( $array, $key, $default = null ) {
		return is_array( $array ) && array_key_exists( $key, $array ) ? $array[ $key ] : $default;
	}

	private static function is_truthy( $value ) {
		return true === $value || 1 === $value || in_array( strtolower( (string) $value ), array( '1', 'true', 'yes', 'on' ), true );
	}
}