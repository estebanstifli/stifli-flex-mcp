<?php
/**
 * Code Snippets Integration Tools.
 * Supports WPCode, Code Snippets, and Woody Code Snippets plugins.
 * Provides MCP tools for listing, creating, updating, and managing code snippets.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- StifliFlexMcp is the plugin prefix.
class StifliFlexMcp_Snippets {

	/**
	 * Detect which snippet plugin is available.
	 *
	 * @return string 'wpcode'|'code-snippets'|'woody'|'none'
	 */
	private static function detectProvider() {
		// WPCode (Insert Headers and Footers) — uses custom post type 'wpcode'.
		if ( class_exists( 'WPCode_Snippet' ) || post_type_exists( 'wpcode' ) ) {
			return 'wpcode';
		}

		// Code Snippets plugin v3.x — uses namespaced functions.
		if ( function_exists( '\\Code_Snippets\\get_snippets' ) || function_exists( '\\Code_Snippets\\save_snippet' ) ) {
			return 'code-snippets';
		}

		// Code Snippets plugin v2.x — uses global functions.
		if ( function_exists( 'get_snippets' ) && function_exists( 'save_snippet' ) ) {
			return 'code-snippets';
		}

		// Code Snippets — detect by its plugin class (active plugin required).
		if ( class_exists( 'Code_Snippets_Plugin' ) || class_exists( '\\Code_Snippets\\Plugin' ) ) {
			return 'code-snippets';
		}

		// Woody Code Snippets — uses custom post type 'wbcr-snippets'.
		if ( defined( 'WINP_PLUGIN_ACTIVE' ) || class_exists( 'WINP_Plugin' ) || post_type_exists( 'wbcr-snippets' ) ) {
			return 'woody';
		}

		return 'none';
	}

	/**
	 * Check if any snippet plugin is available.
	 *
	 * @return bool
	 */
	public static function isAvailable() {
		return self::detectProvider() !== 'none';
	}

	/**
	 * Get tool definitions for snippets.
	 *
	 * @return array
	 */
	public static function getTools() {
		return array(
			'snippet_list' => array(
				'name'        => 'snippet_list',
				'description' => 'List code snippets. Supports limit and offset. Requires WPCode, Code Snippets, or Woody Code Snippets plugin. Returns id, title, active status, code_type, and location.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'limit'  => array( 'type' => 'integer', 'description' => 'Maximum number of snippets to return (default 50, max 100).' ),
						'offset' => array( 'type' => 'integer', 'description' => 'Offset for pagination (default 0).' ),
						'active' => array( 'type' => 'boolean', 'description' => 'Filter by active/inactive status. Omit to return all.' ),
					),
					'required' => array(),
				),
			),

			'snippet_get' => array(
				'name'        => 'snippet_get',
				'description' => 'Get a single code snippet by ID. Returns full details: id, title, code, code_type, location, active status, and tags.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'id' => array( 'type' => 'integer', 'description' => 'The snippet ID.' ),
					),
					'required' => array( 'id' ),
				),
			),

			'snippet_create' => array(
				'name'        => 'snippet_create',
				'description' => 'Create a new code snippet (inactive by default). Requires WPCode, Code Snippets, or Woody Code Snippets plugin. NEVER executes the code, only stores it.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'title'     => array( 'type' => 'string', 'description' => 'Snippet title (required).' ),
						'code'      => array( 'type' => 'string', 'description' => 'The snippet code content (required).' ),
						'code_type' => array(
							'type'        => 'string',
							'description' => 'Code language: php, js, html, css, text (default: php).',
							'enum'        => array( 'php', 'js', 'html', 'css', 'text' ),
						),
						'location'  => array( 'type' => 'string', 'description' => 'Where to run the snippet (default: everywhere). WPCode values: everywhere, site_wide_header, site_wide_footer, site_wide_body, before_post, after_post, etc.' ),
						'active'    => array( 'type' => 'boolean', 'description' => 'Whether to activate the snippet (default: false for safety).' ),
						'tags'      => array(
							'type'        => 'array',
							'description' => 'Array of tag names to assign to the snippet.',
							'items'       => array( 'type' => 'string' ),
						),
						'priority'  => array( 'type' => 'integer', 'description' => 'Execution priority (default: 10).' ),
					),
					'required' => array( 'title', 'code' ),
				),
			),

			'snippet_update' => array(
				'name'        => 'snippet_update',
				'description' => 'Update an existing code snippet by ID. Only provided fields are updated. NEVER executes the code.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'id'        => array( 'type' => 'integer', 'description' => 'The snippet ID (required).' ),
						'title'     => array( 'type' => 'string', 'description' => 'New snippet title.' ),
						'code'      => array( 'type' => 'string', 'description' => 'New code content.' ),
						'code_type' => array(
							'type'        => 'string',
							'description' => 'Code language: php, js, html, css, text.',
							'enum'        => array( 'php', 'js', 'html', 'css', 'text' ),
						),
						'location'  => array( 'type' => 'string', 'description' => 'Where to run the snippet.' ),
						'active'    => array( 'type' => 'boolean', 'description' => 'Whether the snippet is active.' ),
						'tags'      => array(
							'type'        => 'array',
							'description' => 'Array of tag names.',
							'items'       => array( 'type' => 'string' ),
						),
						'priority'  => array( 'type' => 'integer', 'description' => 'Execution priority.' ),
					),
					'required' => array( 'id' ),
				),
			),

			'snippet_delete' => array(
				'name'        => 'snippet_delete',
				'description' => 'Delete a code snippet by ID.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'id' => array( 'type' => 'integer', 'description' => 'The snippet ID to delete.' ),
					),
					'required' => array( 'id' ),
				),
			),

			'snippet_activate' => array(
				'name'        => 'snippet_activate',
				'description' => 'Activate a code snippet by ID.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'id' => array( 'type' => 'integer', 'description' => 'The snippet ID to activate.' ),
					),
					'required' => array( 'id' ),
				),
			),

			'snippet_deactivate' => array(
				'name'        => 'snippet_deactivate',
				'description' => 'Deactivate a code snippet by ID.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'id' => array( 'type' => 'integer', 'description' => 'The snippet ID to deactivate.' ),
					),
					'required' => array( 'id' ),
				),
			),
		);
	}

	/**
	 * Get capability requirements for snippet tools.
	 *
	 * @return array
	 */
	public static function getCapabilities() {
		return array(
			'snippet_list'       => 'manage_options',
			'snippet_get'        => 'manage_options',
			'snippet_create'     => 'manage_options',
			'snippet_update'     => 'manage_options',
			'snippet_delete'     => 'manage_options',
			'snippet_activate'   => 'manage_options',
			'snippet_deactivate' => 'manage_options',
		);
	}

	/**
	 * Dispatch snippet tool execution.
	 *
	 * @param string   $tool          Tool name.
	 * @param array    $args          Tool arguments.
	 * @param array    &$r            Result array (modified by reference).
	 * @param callable $addResultText Helper closure.
	 * @param string   $utils         Utils class name.
	 * @return true|null true if handled, null if not.
	 */
	public static function dispatch( $tool, $args, &$r, $addResultText, $utils ) {
		$provider = self::detectProvider();
		if ( $provider === 'none' ) {
			$r['error'] = array(
				'code'    => -50100,
				'message' => 'No snippet plugin detected. Install and activate WPCode, Code Snippets, or Woody Code Snippets plugin.',
			);
			return true;
		}

		switch ( $tool ) {
			case 'snippet_list':
				return self::handleList( $args, $r, $addResultText, $utils, $provider );

			case 'snippet_get':
				return self::handleGet( $args, $r, $addResultText, $utils, $provider );

			case 'snippet_create':
				return self::handleCreate( $args, $r, $addResultText, $utils, $provider );

			case 'snippet_update':
				return self::handleUpdate( $args, $r, $addResultText, $utils, $provider );

			case 'snippet_delete':
				return self::handleDelete( $args, $r, $addResultText, $utils, $provider );

			case 'snippet_activate':
				return self::handleActivate( $args, $r, $addResultText, $utils, $provider );

			case 'snippet_deactivate':
				return self::handleDeactivate( $args, $r, $addResultText, $utils, $provider );
		}

		return null; // Tool not handled by this module.
	}

	// =========================================================================
	// Code Snippets namespace helpers
	// =========================================================================

	/**
	 * Resolve a Code Snippets function name to the correct callable.
	 * Tries the namespaced version first (v3.x), then global (v2.x).
	 *
	 * @param string $name Function name (e.g. 'get_snippets', 'save_snippet').
	 * @return callable|null The callable or null if not available.
	 */
	private static function cs_func( $name ) {
		$ns = '\\Code_Snippets\\' . $name;
		if ( function_exists( $ns ) ) {
			return $ns;
		}
		if ( function_exists( $name ) ) {
			return $name;
		}
		return null;
	}

	/**
	 * Resolve the Code Snippets snippet class name.
	 * v3.x uses \Code_Snippets\Snippet, v2.x uses Code_Snippet.
	 *
	 * @return string|null Class name or null if not available.
	 */
	private static function cs_snippet_class() {
		if ( class_exists( '\\Code_Snippets\\Snippet' ) ) {
			return '\\Code_Snippets\\Snippet';
		}
		if ( class_exists( 'Code_Snippet' ) ) {
			return 'Code_Snippet';
		}
		return null;
	}

	// =========================================================================
	// WPCode helpers
	// =========================================================================

	/**
	 * Map a WPCode post to a normalised snippet array.
	 *
	 * @param WP_Post $post The WPCode custom post type post.
	 * @return array
	 */
	private static function wpcode_map_post( $post ) {
		// code_type and location are stored as taxonomies in WPCode, not post meta.
		$type_terms     = wp_get_post_terms( $post->ID, 'wpcode_type', array( 'fields' => 'slugs' ) );
		$location_terms = wp_get_post_terms( $post->ID, 'wpcode_location', array( 'fields' => 'slugs' ) );

		return array(
			'id'          => $post->ID,
			'title'       => $post->post_title,
			'active'      => ( $post->post_status === 'publish' ),
			'code_type'   => ( ! is_wp_error( $type_terms ) && ! empty( $type_terms ) ) ? $type_terms[0] : 'php',
			'location'    => ( ! is_wp_error( $location_terms ) && ! empty( $location_terms ) ) ? $location_terms[0] : '',
			'auto_insert' => (bool) get_post_meta( $post->ID, '_wpcode_auto_insert', true ),
			'priority'    => (int) get_post_meta( $post->ID, '_wpcode_priority', true ) ?: 10,
		);
	}

	/**
	 * Map a WPCode post to a full snippet array (including code).
	 *
	 * @param WP_Post $post The WPCode custom post type post.
	 * @return array
	 */
	private static function wpcode_map_post_full( $post ) {
		$data            = self::wpcode_map_post( $post );
		$data['code']    = $post->post_content;
		$tags_terms      = wp_get_post_terms( $post->ID, 'wpcode_tags', array( 'fields' => 'names' ) );
		$data['tags']    = is_wp_error( $tags_terms ) ? array() : $tags_terms;
		return $data;
	}

	// =========================================================================
	// Woody Code Snippets helpers
	// =========================================================================

	/**
	 * Read a Woody meta value using WINP_Helper if available, otherwise direct.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $key     Meta key without the wbcr_inp_ prefix.
	 * @param mixed  $default Default value.
	 * @return mixed
	 */
	private static function woody_meta( $post_id, $key, $default = '' ) {
		if ( class_exists( 'WINP_Helper' ) && method_exists( 'WINP_Helper', 'getMetaOption' ) ) {
			$val = WINP_Helper::getMetaOption( $post_id, $key, $default );
			return ( $val !== '' && $val !== null ) ? $val : $default;
		}
		$val = get_post_meta( $post_id, 'wbcr_inp_' . $key, true );
		return ( $val !== '' && $val !== null ) ? $val : $default;
	}

	/**
	 * Map a Woody post to a normalised snippet array (summary).
	 *
	 * @param WP_Post $post The Woody CPT post.
	 * @return array
	 */
	private static function woody_map_post( $post ) {
		$type   = self::woody_meta( $post->ID, 'snippet_type', 'php' );
		$active = (int) self::woody_meta( $post->ID, 'snippet_activate', 0 );

		return array(
			'id'        => $post->ID,
			'title'     => $post->post_title,
			'active'    => ( $active === 1 ),
			'code_type' => self::woodyTypeToCodeType( $type ),
			'location'  => self::woody_meta( $post->ID, 'snippet_scope', 'shortcode' ),
			'priority'  => (int) self::woody_meta( $post->ID, 'snippet_priority', 10 ),
		);
	}

	/**
	 * Map a Woody post to a full snippet array (including code).
	 *
	 * @param WP_Post $post The Woody CPT post.
	 * @return array
	 */
	private static function woody_map_post_full( $post ) {
		$data = self::woody_map_post( $post );
		// Code in Woody is stored in post_content (v2.2+), with legacy fallback.
		if ( class_exists( 'WINP_Helper' ) && method_exists( 'WINP_Helper', 'get_snippet_code' ) ) {
			$data['code'] = WINP_Helper::get_snippet_code( $post );
		} else {
			$data['code'] = ! empty( $post->post_content )
				? $post->post_content
				: get_post_meta( $post->ID, 'wbcr_inp_snippet_code', true );
		}
		$data['description'] = self::woody_meta( $post->ID, 'snippet_description', '' );
		$tag_terms           = wp_get_post_terms( $post->ID, 'wbcr-snippet-tags', array( 'fields' => 'names' ) );
		$data['tags']        = is_wp_error( $tag_terms ) ? array() : $tag_terms;
		return $data;
	}

	/**
	 * Map Woody snippet_type to our normalised code_type.
	 *
	 * @param string $type Woody type.
	 * @return string
	 */
	private static function woodyTypeToCodeType( $type ) {
		$map = array(
			'php'       => 'php',
			'universal' => 'php',
			'css'       => 'css',
			'js'        => 'js',
			'html'      => 'html',
			'text'      => 'text',
			'advert'    => 'html',
		);
		return isset( $map[ $type ] ) ? $map[ $type ] : 'php';
	}

	/**
	 * Map our code_type to Woody's snippet_type.
	 *
	 * @param string $code_type Validated code type.
	 * @return string Woody snippet_type.
	 */
	private static function codeTypeToWoody( $code_type ) {
		$map = array(
			'php'  => 'php',
			'css'  => 'css',
			'js'   => 'js',
			'html' => 'html',
			'text' => 'text',
		);
		return isset( $map[ $code_type ] ) ? $map[ $code_type ] : 'php';
	}

	/**
	 * Map our location to Woody's scope + location meta fields.
	 *
	 * @param string $location Normalised WPCode-style location.
	 * @param string $code_type Validated code type.
	 * @return array { scope: string, location: string }
	 */
	private static function mapToWoodyScope( $location, $code_type ) {
		$location = strtolower( trim( (string) $location ) );

		// CSS and JS in Woody always use 'auto' scope with header/footer location.
		if ( in_array( $code_type, array( 'css', 'js' ), true ) ) {
			if ( in_array( $location, array( 'site_wide_footer', 'footer', 'site-footer-js' ), true ) ) {
				return array( 'scope' => 'auto', 'location' => 'footer' );
			}
			return array( 'scope' => 'auto', 'location' => 'header' );
		}

		// Map WPCode-style locations to Woody scope + location.
		$scope_map = array(
			'everywhere'        => array( 'scope' => 'evrywhere', 'location' => '' ),
			'site_wide_header'  => array( 'scope' => 'auto',     'location' => 'header' ),
			'site_wide_footer'  => array( 'scope' => 'auto',     'location' => 'footer' ),
			'site_wide_body'    => array( 'scope' => 'evrywhere', 'location' => '' ),
			'before_post'       => array( 'scope' => 'auto',     'location' => 'before_post' ),
			'after_post'        => array( 'scope' => 'auto',     'location' => 'after_post' ),
			'before_paragraph'  => array( 'scope' => 'auto',     'location' => 'before_paragraph' ),
			'after_paragraph'   => array( 'scope' => 'auto',     'location' => 'after_paragraph' ),
			'shortcode'         => array( 'scope' => 'shortcode', 'location' => '' ),
		);

		if ( isset( $scope_map[ $location ] ) ) {
			return $scope_map[ $location ];
		}

		// Default: run everywhere for PHP, shortcode for others.
		if ( $code_type === 'php' ) {
			return array( 'scope' => 'evrywhere', 'location' => '' );
		}
		return array( 'scope' => 'shortcode', 'location' => '' );
	}

	/**
	 * Validate code_type is one of the allowed values.
	 *
	 * @param string $type The code type.
	 * @return string Sanitised code type.
	 */
	private static function validateCodeType( $type ) {
		$allowed = array( 'php', 'js', 'html', 'css', 'text' );
		$type    = strtolower( trim( (string) $type ) );
		// Common LLM aliases.
		$aliases = array(
			'javascript'  => 'js',
			'typescript'  => 'js',
			'jsx'         => 'js',
			'css3'        => 'css',
			'scss'        => 'css',
			'less'        => 'css',
			'html5'       => 'html',
			'htm'         => 'html',
			'txt'         => 'text',
			'plain'       => 'text',
			'plaintext'   => 'text',
			'plain_text'  => 'text',
			'php8'        => 'php',
			'php7'        => 'php',
		);
		if ( isset( $aliases[ $type ] ) ) {
			$type = $aliases[ $type ];
		}
		$type = sanitize_key( $type );
		return in_array( $type, $allowed, true ) ? $type : 'php';
	}

	/**
	 * Sanitise snippet code. Fixes common LLM output issues.
	 *
	 * @param string $code      Raw code from LLM.
	 * @param string $code_type The validated code type.
	 * @return string Cleaned code.
	 */
	private static function sanitizeCode( $code, $code_type ) {
		if ( ! is_string( $code ) ) {
			return '';
		}

		if ( $code_type === 'php' ) {
			// Remove opening <?php tag — WPCode adds it automatically.
			$code = preg_replace( '/^\s*<\?(php)?\s*/i', '', $code );
			// Remove closing PHP tag — causes "headers already sent" and whitespace issues.
			$code = preg_replace( '/\s*\?' . '>\s*$/', '', $code );
		}

		// Remove markdown code fences that LLMs sometimes wrap code in.
		$code = preg_replace( '/^```[a-z]*\s*\n?/i', '', $code );
		$code = preg_replace( '/\n?```\s*$/', '', $code );

		return $code;
	}

	/**
	 * Normalise the location parameter. Maps common LLM variants to WPCode slugs.
	 *
	 * @param string $location Raw location value.
	 * @return string Normalised location slug.
	 */
	private static function normalizeLocation( $location ) {
		$location = strtolower( trim( (string) $location ) );
		// Map common LLM variants to WPCode taxonomy slugs.
		$map = array(
			'run_everywhere'       => 'everywhere',
			'run-everywhere'       => 'everywhere',
			'global'               => 'everywhere',
			'all'                  => 'everywhere',
			'all_pages'            => 'everywhere',
			'header'               => 'site_wide_header',
			'site_header'          => 'site_wide_header',
			'wp_head'              => 'site_wide_header',
			'head'                 => 'site_wide_header',
			'footer'               => 'site_wide_footer',
			'site_footer'          => 'site_wide_footer',
			'wp_footer'            => 'site_wide_footer',
			'body'                 => 'site_wide_body',
			'wp_body_open'         => 'site_wide_body',
			'body_open'            => 'site_wide_body',
			'before_content'       => 'before_post',
			'before_the_content'   => 'before_post',
			'after_content'        => 'after_post',
			'after_the_content'    => 'after_post',
			'before_paragraph'     => 'before_paragraph',
			'after_paragraph'      => 'after_paragraph',
			'frontend'             => 'frontend_only',
			'front_end'            => 'frontend_only',
			'admin'                => 'admin_only',
			'backend'              => 'admin_only',
			'shortcode'            => '',
		);
		if ( isset( $map[ $location ] ) ) {
			return $map[ $location ];
		}
		// Already a valid slug — return as-is.
		return sanitize_key( $location );
	}

	/**
	 * Map code_type + location to a valid Code Snippets scope.
	 *
	 * Code Snippets v3.x derives the snippet type from its scope, so
	 * there is no separate "type" field. Valid scopes:
	 *   PHP:  global, admin, front-end, single-use
	 *   HTML: content, head-content, footer-content
	 *   CSS:  site-css, admin-css
	 *   JS:   site-head-js, site-footer-js
	 *
	 * @param string $code_type Validated code type (php, js, html, css, text).
	 * @param string $location  Normalised WPCode-style location.
	 * @return string Valid Code Snippets scope.
	 */
	private static function mapToCodeSnippetsScope( $code_type, $location ) {
		$location = strtolower( trim( (string) $location ) );

		switch ( $code_type ) {
			case 'php':
				$php_map = array(
					'admin_only'   => 'admin',
					'admin'        => 'admin',
					'frontend_only' => 'front-end',
					'front-end'    => 'front-end',
					'front_end'    => 'front-end',
					'single-use'   => 'single-use',
					'single_use'   => 'single-use',
				);
				return isset( $php_map[ $location ] ) ? $php_map[ $location ] : 'global';

			case 'css':
				if ( in_array( $location, array( 'admin_only', 'admin', 'admin-css' ), true ) ) {
					return 'admin-css';
				}
				return 'site-css';

			case 'js':
				if ( in_array( $location, array( 'site_wide_header', 'head', 'header', 'site-head-js' ), true ) ) {
					return 'site-head-js';
				}
				return 'site-footer-js';

			case 'html':
				if ( in_array( $location, array( 'site_wide_header', 'head', 'header', 'head-content' ), true ) ) {
					return 'head-content';
				}
				if ( in_array( $location, array( 'site_wide_footer', 'footer', 'footer-content' ), true ) ) {
					return 'footer-content';
				}
				return 'content';

			default:
				// text / unknown → treat as PHP global.
				return 'global';
		}
	}

	// =========================================================================
	// Tool handlers
	// =========================================================================

	/**
	 * List snippets.
	 */
	private static function handleList( $args, &$r, $addResultText, $utils, $provider ) {
		$limit  = min( max( 1, intval( $utils::getArrayValue( $args, 'limit', 50 ) ) ), 100 );
		$offset = max( 0, intval( $utils::getArrayValue( $args, 'offset', 0 ) ) );

		if ( $provider === 'wpcode' ) {
			$query_args = array(
				'post_type'      => 'wpcode',
				'posts_per_page' => $limit,
				'offset'         => $offset,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'post_status'    => 'any',
			);

			if ( isset( $args['active'] ) ) {
				$query_args['post_status'] = $args['active'] ? 'publish' : 'draft';
			}

			$posts   = get_posts( $query_args );
			$results = array();
			foreach ( $posts as $post ) {
				$results[] = self::wpcode_map_post( $post );
			}

			$addResultText( $r, 'Found ' . count( $results ) . ' snippets (WPCode): ' . wp_json_encode( $results, JSON_PRETTY_PRINT ) );
			return true;
		}

		// Code Snippets plugin.
		if ( $provider === 'code-snippets' ) {
			$fn = self::cs_func( 'get_snippets' );
			if ( ! $fn ) {
				$r['error'] = array( 'code' => -50101, 'message' => 'Code Snippets get_snippets() function not available.' );
				return true;
			}
			$all_snippets = $fn();
			$results      = array();

			// Apply active filter if present.
			foreach ( $all_snippets as $snippet ) {
				if ( isset( $args['active'] ) ) {
					if ( $args['active'] && ! $snippet->active ) {
						continue;
					}
					if ( ! $args['active'] && $snippet->active ) {
						continue;
					}
				}
				$results[] = array(
					'id'        => $snippet->id,
					'title'     => $snippet->name,
					'active'    => (bool) $snippet->active,
					'code_type' => $snippet->type ?: 'php',
					'location'  => $snippet->scope ?: '',
					'priority'  => $snippet->priority ?? 10,
				);
			}

			// Apply pagination.
			$results = array_slice( $results, $offset, $limit );

			$addResultText( $r, 'Found ' . count( $results ) . ' snippets (Code Snippets): ' . wp_json_encode( $results, JSON_PRETTY_PRINT ) );
			return true;
		}

		if ( $provider === 'woody' ) {
			$query_args = array(
				'post_type'      => 'wbcr-snippets',
				'posts_per_page' => $limit,
				'offset'         => $offset,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'post_status'    => 'any',
			);

			if ( isset( $args['active'] ) ) {
				$query_args['meta_query'] = array(
					array(
						'key'   => 'wbcr_inp_snippet_activate',
						'value' => $args['active'] ? '1' : '0',
					),
				);
			}

			$posts   = get_posts( $query_args );
			$results = array();
			foreach ( $posts as $post ) {
				$results[] = self::woody_map_post( $post );
			}

			$addResultText( $r, 'Found ' . count( $results ) . ' snippets (Woody): ' . wp_json_encode( $results, JSON_PRETTY_PRINT ) );
			return true;
		}

		return null;
	}

	/**
	 * Get a single snippet.
	 */
	private static function handleGet( $args, &$r, $addResultText, $utils, $provider ) {
		$id = intval( $utils::getArrayValue( $args, 'id', 0 ) );
		if ( $id <= 0 ) {
			$r['error'] = array( 'code' => -42602, 'message' => 'Valid snippet id is required.' );
			return true;
		}

		if ( $provider === 'wpcode' ) {
			$post = get_post( $id );
			if ( ! $post || $post->post_type !== 'wpcode' ) {
				$r['error'] = array( 'code' => -42604, 'message' => 'Snippet not found with ID ' . $id );
				return true;
			}
			$data = self::wpcode_map_post_full( $post );
			$addResultText( $r, wp_json_encode( $data, JSON_PRETTY_PRINT ) );
			return true;
		}

		if ( $provider === 'code-snippets' ) {
			$fn = self::cs_func( 'get_snippet' );
			if ( ! $fn ) {
				$r['error'] = array( 'code' => -50101, 'message' => 'Code Snippets get_snippet() function not available.' );
				return true;
			}
			$snippet = $fn( $id );
			if ( ! $snippet || empty( $snippet->id ) ) {
				$r['error'] = array( 'code' => -42604, 'message' => 'Snippet not found with ID ' . $id );
				return true;
			}
			$data = array(
				'id'        => $snippet->id,
				'title'     => $snippet->name,
				'code'      => $snippet->code,
				'code_type' => $snippet->type ?: 'php',
				'location'  => $snippet->scope ?: '',
				'active'    => (bool) $snippet->active,
				'tags'      => $snippet->tags ?? array(),
				'priority'  => $snippet->priority ?? 10,
			);
			$addResultText( $r, wp_json_encode( $data, JSON_PRETTY_PRINT ) );
			return true;
		}

		if ( $provider === 'woody' ) {
			$post = get_post( $id );
			if ( ! $post || $post->post_type !== 'wbcr-snippets' ) {
				$r['error'] = array( 'code' => -42604, 'message' => 'Snippet not found with ID ' . $id );
				return true;
			}
			$data = self::woody_map_post_full( $post );
			$addResultText( $r, wp_json_encode( $data, JSON_PRETTY_PRINT ) );
			return true;
		}

		return null;
	}

	/**
	 * Create a new snippet.
	 */
	private static function handleCreate( $args, &$r, $addResultText, $utils, $provider ) {
		$title = sanitize_text_field( $utils::getArrayValue( $args, 'title', '' ) );
		$code  = $utils::getArrayValue( $args, 'code', '' );

		if ( empty( $title ) ) {
			$r['error'] = array( 'code' => -42602, 'message' => 'title is required.' );
			return true;
		}
		if ( empty( $code ) ) {
			$r['error'] = array( 'code' => -42602, 'message' => 'code is required.' );
			return true;
		}

		$code_type = self::validateCodeType( $utils::getArrayValue( $args, 'code_type', 'php' ) );
		$code      = self::sanitizeCode( $code, $code_type );
		$location  = self::normalizeLocation( $utils::getArrayValue( $args, 'location', 'everywhere' ) );
		$active    = ! empty( $args['active'] ); // Default false for safety.
		$priority  = intval( $utils::getArrayValue( $args, 'priority', 10 ) );
		$tags      = array();
		if ( ! empty( $args['tags'] ) && is_array( $args['tags'] ) ) {
			$tags = array_map( 'sanitize_text_field', $args['tags'] );
		}

		if ( $provider === 'wpcode' ) {
			// Use WPCode_Snippet class if available for proper integration.
			if ( class_exists( 'WPCode_Snippet' ) ) {
				$snippet = new WPCode_Snippet(
					array(
						'code'        => $code,
						'code_type'   => $code_type,
						'title'       => $title,
						'location'    => $location,
						'auto_insert' => ! empty( $location ) ? 1 : 0,
						'active'      => $active,
						'priority'    => $priority,
					)
				);

				$snippet->save();
				$snippet_id = $snippet->get_id();

				if ( ! $snippet_id ) {
					$r['error'] = array( 'code' => -50102, 'message' => 'Failed to create snippet via WPCode.' );
					return true;
				}

				// Assign tags if WPCode supports the taxonomy.
				if ( ! empty( $tags ) && taxonomy_exists( 'wpcode_tags' ) ) {
					wp_set_object_terms( $snippet_id, $tags, 'wpcode_tags' );
				}

				$addResultText( $r, wp_json_encode( array(
					'success'    => true,
					'snippet_id' => $snippet_id,
					'active'     => $active,
					'provider'   => 'wpcode',
					'message'    => 'Snippet created successfully.' . ( ! $active ? ' Snippet is INACTIVE — activate it manually or via snippet_activate tool.' : '' ),
				), JSON_PRETTY_PRINT ) );
				return true;
			}

			// Fallback: use wp_insert_post directly for WPCode CPT.
			$post_data = array(
				'post_type'    => 'wpcode',
				'post_title'   => $title,
				'post_content' => $code,
				'post_status'  => $active ? 'publish' : 'draft',
			);

			$post_id = wp_insert_post( $post_data, true );
			if ( is_wp_error( $post_id ) ) {
				$r['error'] = array( 'code' => -50102, 'message' => 'Failed to create snippet: ' . $post_id->get_error_message() );
				return true;
			}

			update_post_meta( $post_id, '_wpcode_auto_insert', ! empty( $location ) ? 1 : 0 );
			update_post_meta( $post_id, '_wpcode_priority', $priority );

			// code_type and location are taxonomies in WPCode.
			wp_set_object_terms( $post_id, $code_type, 'wpcode_type' );
			if ( ! empty( $location ) ) {
				wp_set_object_terms( $post_id, $location, 'wpcode_location' );
			}

			if ( ! empty( $tags ) && taxonomy_exists( 'wpcode_tags' ) ) {
				wp_set_object_terms( $post_id, $tags, 'wpcode_tags' );
			}

			$addResultText( $r, wp_json_encode( array(
				'success'    => true,
				'snippet_id' => $post_id,
				'active'     => $active,
				'provider'   => 'wpcode',
				'message'    => 'Snippet created (via post fallback).' . ( ! $active ? ' Snippet is INACTIVE.' : '' ),
			), JSON_PRETTY_PRINT ) );
			return true;
		}

		if ( $provider === 'code-snippets' ) {
			$fn_save     = self::cs_func( 'save_snippet' );
			$snippet_cls = self::cs_snippet_class();
			if ( ! $fn_save || ! $snippet_cls ) {
				$r['error'] = array( 'code' => -50101, 'message' => 'Code Snippets save_snippet() or Snippet class not available.' );
				return true;
			}

			$snippet       = new $snippet_cls();
			$snippet->name     = $title;
			$snippet->code     = $code;
			$snippet->scope    = self::mapToCodeSnippetsScope( $code_type, $location );
			$snippet->active   = $active;
			$snippet->priority = $priority;
			$snippet->tags     = $tags;

			$result = $fn_save( $snippet );
			if ( ! $result || ( is_object( $result ) && empty( $result->id ) ) ) {
				$r['error'] = array( 'code' => -50102, 'message' => 'Failed to create snippet via Code Snippets.' );
				return true;
			}

			$snippet_id = is_object( $result ) ? $result->id : $result;

			$addResultText( $r, wp_json_encode( array(
				'success'    => true,
				'snippet_id' => $snippet_id,
				'active'     => $active,
				'provider'   => 'code-snippets',
				'message'    => 'Snippet created successfully.' . ( ! $active ? ' Snippet is INACTIVE.' : '' ),
			), JSON_PRETTY_PRINT ) );
			return true;
		}

		if ( $provider === 'woody' ) {
			$woody_scope = self::mapToWoodyScope( $location, $code_type );

			$post_data = array(
				'post_type'    => 'wbcr-snippets',
				'post_title'   => $title,
				'post_content' => $code,
				'post_status'  => 'publish',
			);

			$post_id = wp_insert_post( $post_data, true );
			if ( is_wp_error( $post_id ) ) {
				$r['error'] = array( 'code' => -50102, 'message' => 'Failed to create snippet: ' . $post_id->get_error_message() );
				return true;
			}

			update_post_meta( $post_id, 'wbcr_inp_snippet_type', self::codeTypeToWoody( $code_type ) );
			update_post_meta( $post_id, 'wbcr_inp_snippet_scope', $woody_scope['scope'] );
			update_post_meta( $post_id, 'wbcr_inp_snippet_location', $woody_scope['location'] );
			update_post_meta( $post_id, 'wbcr_inp_snippet_activate', $active ? 1 : 0 );
			update_post_meta( $post_id, 'wbcr_inp_snippet_priority', $priority );

			if ( ! empty( $tags ) && taxonomy_exists( 'wbcr-snippet-tags' ) ) {
				wp_set_object_terms( $post_id, $tags, 'wbcr-snippet-tags' );
			}

			$addResultText( $r, wp_json_encode( array(
				'success'    => true,
				'snippet_id' => $post_id,
				'active'     => $active,
				'provider'   => 'woody',
				'message'    => 'Snippet created successfully.' . ( ! $active ? ' Snippet is INACTIVE.' : '' ),
			), JSON_PRETTY_PRINT ) );
			return true;
		}

		return null;
	}

	/**
	 * Update an existing snippet.
	 */
	private static function handleUpdate( $args, &$r, $addResultText, $utils, $provider ) {
		$id = intval( $utils::getArrayValue( $args, 'id', 0 ) );
		if ( $id <= 0 ) {
			$r['error'] = array( 'code' => -42602, 'message' => 'Valid snippet id is required.' );
			return true;
		}

		if ( $provider === 'wpcode' ) {
			$post = get_post( $id );
			if ( ! $post || $post->post_type !== 'wpcode' ) {
				$r['error'] = array( 'code' => -42604, 'message' => 'Snippet not found with ID ' . $id );
				return true;
			}

			$update_args = array( 'ID' => $id );
			$changed     = false;

			if ( isset( $args['title'] ) ) {
				$update_args['post_title'] = sanitize_text_field( $args['title'] );
				$changed = true;
			}
			if ( isset( $args['code'] ) ) {
				// Determine code_type for sanitization (use new value if provided, else read current).
				$update_code_type = isset( $args['code_type'] )
					? self::validateCodeType( $args['code_type'] )
					: ( wp_get_post_terms( $id, 'wpcode_type', array( 'fields' => 'slugs' ) )[0] ?? 'php' );
				$update_args['post_content'] = self::sanitizeCode( $args['code'], $update_code_type );
				$changed = true;
			}
			if ( isset( $args['active'] ) ) {
				$update_args['post_status'] = $args['active'] ? 'publish' : 'draft';
				$changed = true;
			}

			if ( $changed ) {
				$result = wp_update_post( $update_args, true );
				if ( is_wp_error( $result ) ) {
					$r['error'] = array( 'code' => -50103, 'message' => 'Failed to update snippet: ' . $result->get_error_message() );
					return true;
				}
			}

			if ( isset( $args['code_type'] ) ) {
				// code_type is a taxonomy in WPCode.
				wp_set_object_terms( $id, self::validateCodeType( $args['code_type'] ), 'wpcode_type' );
			}
			if ( isset( $args['location'] ) ) {
				// location is a taxonomy in WPCode.
				$norm_loc = self::normalizeLocation( $args['location'] );
				wp_set_object_terms( $id, $norm_loc, 'wpcode_location' );
				update_post_meta( $id, '_wpcode_auto_insert', ! empty( $norm_loc ) ? 1 : 0 );
			}
			if ( isset( $args['priority'] ) ) {
				update_post_meta( $id, '_wpcode_priority', intval( $args['priority'] ) );
			}
			if ( isset( $args['tags'] ) && is_array( $args['tags'] ) && taxonomy_exists( 'wpcode_tags' ) ) {
				wp_set_object_terms( $id, array_map( 'sanitize_text_field', $args['tags'] ), 'wpcode_tags' );
			}

			$addResultText( $r, wp_json_encode( array(
				'success'    => true,
				'snippet_id' => $id,
				'provider'   => 'wpcode',
				'message'    => 'Snippet updated successfully.',
			), JSON_PRETTY_PRINT ) );
			return true;
		}

		if ( $provider === 'code-snippets' ) {
			$fn_get  = self::cs_func( 'get_snippet' );
			$fn_save = self::cs_func( 'save_snippet' );
			if ( ! $fn_get || ! $fn_save ) {
				$r['error'] = array( 'code' => -50101, 'message' => 'Code Snippets functions not available.' );
				return true;
			}

			$snippet = $fn_get( $id );
			if ( ! $snippet || empty( $snippet->id ) ) {
				$r['error'] = array( 'code' => -42604, 'message' => 'Snippet not found with ID ' . $id );
				return true;
			}

			if ( isset( $args['title'] ) ) {
				$snippet->name = sanitize_text_field( $args['title'] );
			}
			if ( isset( $args['code'] ) ) {
				$cs_code_type = isset( $args['code_type'] )
					? self::validateCodeType( $args['code_type'] )
					: ( $snippet->type ?: 'php' );
				$snippet->code = self::sanitizeCode( $args['code'], $cs_code_type );
			}
			if ( isset( $args['code_type'] ) || isset( $args['location'] ) ) {
				// Code Snippets derives type from scope, so both code_type and location affect scope.
				$new_code_type = isset( $args['code_type'] )
					? self::validateCodeType( $args['code_type'] )
					: ( $snippet->type ?: 'php' );
				$new_location  = isset( $args['location'] )
					? self::normalizeLocation( $args['location'] )
					: $snippet->scope;
				$snippet->scope = self::mapToCodeSnippetsScope( $new_code_type, $new_location );
			}
			if ( isset( $args['active'] ) ) {
				$snippet->active = (bool) $args['active'];
			}
			if ( isset( $args['priority'] ) ) {
				$snippet->priority = intval( $args['priority'] );
			}
			if ( isset( $args['tags'] ) && is_array( $args['tags'] ) ) {
				$snippet->tags = array_map( 'sanitize_text_field', $args['tags'] );
			}

			$result = $fn_save( $snippet );
			if ( ! $result ) {
				$r['error'] = array( 'code' => -50103, 'message' => 'Failed to update snippet via Code Snippets.' );
				return true;
			}

			$addResultText( $r, wp_json_encode( array(
				'success'    => true,
				'snippet_id' => $id,
				'provider'   => 'code-snippets',
				'message'    => 'Snippet updated successfully.',
			), JSON_PRETTY_PRINT ) );
			return true;
		}

		if ( $provider === 'woody' ) {
			$post = get_post( $id );
			if ( ! $post || $post->post_type !== 'wbcr-snippets' ) {
				$r['error'] = array( 'code' => -42604, 'message' => 'Snippet not found with ID ' . $id );
				return true;
			}

			$update_args = array( 'ID' => $id );
			$changed     = false;

			if ( isset( $args['title'] ) ) {
				$update_args['post_title'] = sanitize_text_field( $args['title'] );
				$changed = true;
			}
			if ( isset( $args['code'] ) ) {
				$up_code_type = isset( $args['code_type'] )
					? self::validateCodeType( $args['code_type'] )
					: self::woodyTypeToCodeType( self::woody_meta( $id, 'snippet_type', 'php' ) );
				$update_args['post_content'] = self::sanitizeCode( $args['code'], $up_code_type );
				$changed = true;
			}

			if ( $changed ) {
				$result = wp_update_post( $update_args, true );
				if ( is_wp_error( $result ) ) {
					$r['error'] = array( 'code' => -50103, 'message' => 'Failed to update snippet: ' . $result->get_error_message() );
					return true;
				}
			}

			if ( isset( $args['code_type'] ) ) {
				update_post_meta( $id, 'wbcr_inp_snippet_type', self::codeTypeToWoody( self::validateCodeType( $args['code_type'] ) ) );
			}
			if ( isset( $args['location'] ) || isset( $args['code_type'] ) ) {
				$ct  = isset( $args['code_type'] ) ? self::validateCodeType( $args['code_type'] ) : self::woodyTypeToCodeType( self::woody_meta( $id, 'snippet_type', 'php' ) );
				$loc = isset( $args['location'] ) ? self::normalizeLocation( $args['location'] ) : self::woody_meta( $id, 'snippet_scope', 'shortcode' );
				$ws  = self::mapToWoodyScope( $loc, $ct );
				update_post_meta( $id, 'wbcr_inp_snippet_scope', $ws['scope'] );
				update_post_meta( $id, 'wbcr_inp_snippet_location', $ws['location'] );
			}
			if ( isset( $args['active'] ) ) {
				update_post_meta( $id, 'wbcr_inp_snippet_activate', $args['active'] ? 1 : 0 );
			}
			if ( isset( $args['priority'] ) ) {
				update_post_meta( $id, 'wbcr_inp_snippet_priority', intval( $args['priority'] ) );
			}
			if ( isset( $args['tags'] ) && is_array( $args['tags'] ) && taxonomy_exists( 'wbcr-snippet-tags' ) ) {
				wp_set_object_terms( $id, array_map( 'sanitize_text_field', $args['tags'] ), 'wbcr-snippet-tags' );
			}

			$addResultText( $r, wp_json_encode( array(
				'success'    => true,
				'snippet_id' => $id,
				'provider'   => 'woody',
				'message'    => 'Snippet updated successfully.',
			), JSON_PRETTY_PRINT ) );
			return true;
		}

		return null;
	}

	/**
	 * Delete a snippet.
	 */
	private static function handleDelete( $args, &$r, $addResultText, $utils, $provider ) {
		$id = intval( $utils::getArrayValue( $args, 'id', 0 ) );
		if ( $id <= 0 ) {
			$r['error'] = array( 'code' => -42602, 'message' => 'Valid snippet id is required.' );
			return true;
		}

		if ( $provider === 'wpcode' ) {
			$post = get_post( $id );
			if ( ! $post || $post->post_type !== 'wpcode' ) {
				$r['error'] = array( 'code' => -42604, 'message' => 'Snippet not found with ID ' . $id );
				return true;
			}

			$deleted = wp_delete_post( $id, true );
			if ( ! $deleted ) {
				$r['error'] = array( 'code' => -50104, 'message' => 'Failed to delete snippet.' );
				return true;
			}

			$addResultText( $r, wp_json_encode( array(
				'success'    => true,
				'snippet_id' => $id,
				'provider'   => 'wpcode',
				'message'    => 'Snippet deleted.',
			), JSON_PRETTY_PRINT ) );
			return true;
		}

		if ( $provider === 'code-snippets' ) {
			$fn = self::cs_func( 'delete_snippet' );
			if ( ! $fn ) {
				$r['error'] = array( 'code' => -50101, 'message' => 'Code Snippets delete_snippet() function not available.' );
				return true;
			}

			$fn( $id );

			$addResultText( $r, wp_json_encode( array(
				'success'    => true,
				'snippet_id' => $id,
				'provider'   => 'code-snippets',
				'message'    => 'Snippet deleted.',
			), JSON_PRETTY_PRINT ) );
			return true;
		}

		if ( $provider === 'woody' ) {
			$post = get_post( $id );
			if ( ! $post || $post->post_type !== 'wbcr-snippets' ) {
				$r['error'] = array( 'code' => -42604, 'message' => 'Snippet not found with ID ' . $id );
				return true;
			}

			$deleted = wp_delete_post( $id, true );
			if ( ! $deleted ) {
				$r['error'] = array( 'code' => -50104, 'message' => 'Failed to delete snippet.' );
				return true;
			}

			$addResultText( $r, wp_json_encode( array(
				'success'    => true,
				'snippet_id' => $id,
				'provider'   => 'woody',
				'message'    => 'Snippet deleted.',
			), JSON_PRETTY_PRINT ) );
			return true;
		}

		return null;
	}

	/**
	 * Activate a snippet.
	 */
	private static function handleActivate( $args, &$r, $addResultText, $utils, $provider ) {
		$id = intval( $utils::getArrayValue( $args, 'id', 0 ) );
		if ( $id <= 0 ) {
			$r['error'] = array( 'code' => -42602, 'message' => 'Valid snippet id is required.' );
			return true;
		}

		if ( $provider === 'wpcode' ) {
			$post = get_post( $id );
			if ( ! $post || $post->post_type !== 'wpcode' ) {
				$r['error'] = array( 'code' => -42604, 'message' => 'Snippet not found with ID ' . $id );
				return true;
			}

			$result = wp_update_post( array( 'ID' => $id, 'post_status' => 'publish' ), true );
			if ( is_wp_error( $result ) ) {
				$r['error'] = array( 'code' => -50105, 'message' => 'Failed to activate snippet: ' . $result->get_error_message() );
				return true;
			}

			$addResultText( $r, wp_json_encode( array(
				'success'    => true,
				'snippet_id' => $id,
				'active'     => true,
				'provider'   => 'wpcode',
				'message'    => 'Snippet activated.',
			), JSON_PRETTY_PRINT ) );
			return true;
		}

		if ( $provider === 'code-snippets' ) {
			$fn = self::cs_func( 'activate_snippet' );
			if ( ! $fn ) {
				$r['error'] = array( 'code' => -50101, 'message' => 'Code Snippets activate_snippet() function not available.' );
				return true;
			}

			$result = $fn( $id );
			if ( is_wp_error( $result ) || $result === false ) {
				$r['error'] = array( 'code' => -50105, 'message' => 'Failed to activate snippet.' );
				return true;
			}

			$addResultText( $r, wp_json_encode( array(
				'success'    => true,
				'snippet_id' => $id,
				'active'     => true,
				'provider'   => 'code-snippets',
				'message'    => 'Snippet activated.',
			), JSON_PRETTY_PRINT ) );
			return true;
		}

		if ( $provider === 'woody' ) {
			$post = get_post( $id );
			if ( ! $post || $post->post_type !== 'wbcr-snippets' ) {
				$r['error'] = array( 'code' => -42604, 'message' => 'Snippet not found with ID ' . $id );
				return true;
			}

			update_post_meta( $id, 'wbcr_inp_snippet_activate', 1 );

			$addResultText( $r, wp_json_encode( array(
				'success'    => true,
				'snippet_id' => $id,
				'active'     => true,
				'provider'   => 'woody',
				'message'    => 'Snippet activated.',
			), JSON_PRETTY_PRINT ) );
			return true;
		}

		return null;
	}

	/**
	 * Deactivate a snippet.
	 */
	private static function handleDeactivate( $args, &$r, $addResultText, $utils, $provider ) {
		$id = intval( $utils::getArrayValue( $args, 'id', 0 ) );
		if ( $id <= 0 ) {
			$r['error'] = array( 'code' => -42602, 'message' => 'Valid snippet id is required.' );
			return true;
		}

		if ( $provider === 'wpcode' ) {
			$post = get_post( $id );
			if ( ! $post || $post->post_type !== 'wpcode' ) {
				$r['error'] = array( 'code' => -42604, 'message' => 'Snippet not found with ID ' . $id );
				return true;
			}

			$result = wp_update_post( array( 'ID' => $id, 'post_status' => 'draft' ), true );
			if ( is_wp_error( $result ) ) {
				$r['error'] = array( 'code' => -50106, 'message' => 'Failed to deactivate snippet: ' . $result->get_error_message() );
				return true;
			}

			$addResultText( $r, wp_json_encode( array(
				'success'    => true,
				'snippet_id' => $id,
				'active'     => false,
				'provider'   => 'wpcode',
				'message'    => 'Snippet deactivated.',
			), JSON_PRETTY_PRINT ) );
			return true;
		}

		if ( $provider === 'code-snippets' ) {
			$fn = self::cs_func( 'deactivate_snippet' );
			if ( ! $fn ) {
				$r['error'] = array( 'code' => -50101, 'message' => 'Code Snippets deactivate_snippet() function not available.' );
				return true;
			}

			$result = $fn( $id );
			if ( is_wp_error( $result ) || $result === false ) {
				$r['error'] = array( 'code' => -50106, 'message' => 'Failed to deactivate snippet.' );
				return true;
			}

			$addResultText( $r, wp_json_encode( array(
				'success'    => true,
				'snippet_id' => $id,
				'active'     => false,
				'provider'   => 'code-snippets',
				'message'    => 'Snippet deactivated.',
			), JSON_PRETTY_PRINT ) );
			return true;
		}

		if ( $provider === 'woody' ) {
			$post = get_post( $id );
			if ( ! $post || $post->post_type !== 'wbcr-snippets' ) {
				$r['error'] = array( 'code' => -42604, 'message' => 'Snippet not found with ID ' . $id );
				return true;
			}

			update_post_meta( $id, 'wbcr_inp_snippet_activate', 0 );

			$addResultText( $r, wp_json_encode( array(
				'success'    => true,
				'snippet_id' => $id,
				'active'     => false,
				'provider'   => 'woody',
				'message'    => 'Snippet deactivated.',
			), JSON_PRETTY_PRINT ) );
			return true;
		}

		return null;
	}
}
