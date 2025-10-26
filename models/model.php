<?php
// Model stub para tools
class EasyVisualMcpModel {
	private $tools = false;

	public function getToolsList() {
		$tools = $this->getTools();
		if (!is_array($tools)) {
			return [];
		}
		foreach ($tools as &$tool) {
			if (in_array($tool['name'], array('search', 'fetch'))) {
				$tool['category'] = 'Core: OpenAI';
			} else {
				$tool['category'] = 'Core';
			}
		}
		return array_values($tools);
	}

	public function getTools() {
		if (empty($this->tools)) {
			$tools = array(
				'mcp_ping' => array(
					'name' => 'mcp_ping',
					'description' => 'Simple connectivity check. Returns the current GMT time and the WordPress site name.',
					'inputSchema' => array(
						'type' => 'object',
						'properties' => (object) array(),
						'required' => array(),
					),
				),
				'wp_list_plugins' => array(
					'name' => 'wp_list_plugins',
					'description' => 'List installed plugins (returns array of {Name, Version}).',
					'inputSchema' => array(
						'type' => 'object',
						'properties' => array('search' => array('type' => 'string')),
						'required' => array(),
					),
				),
				'wp_get_users' => array(
					'name' => 'wp_get_users',
					'description' => 'Retrieve users (fields: ID, user_login, display_name, roles). If no limit supplied, returns 10. `paged` ignored if `offset` is used.',
					'inputSchema' => array(
						'type' => 'object',
						'properties' => array(
							'search' => array('type' => 'string'),
							'role' => array('type' => 'string'),
							'limit' => array('type' => 'integer'),
							'offset' => array('type' => 'integer'),
							'paged' => array('type' => 'integer'),
						),
						'required' => array(),
					),
				),
				'wp_create_user' => array(
					'name' => 'wp_create_user',
					'description' => 'Create a user. Requires user_login and user_email. Optional: user_pass (random if omitted), display_name, role.',
					'inputSchema' => array(
						'type' => 'object',
						'properties' => array(
							'user_login' => array('type' => 'string'),
							'user_email' => array('type' => 'string'),
							'user_pass' => array('type' => 'string'),
							'display_name' => array('type' => 'string'),
							'role' => array('type' => 'string'),
						),
						'required' => array('user_login', 'user_email'),
					),
				),
				'wp_update_user' => array(
					'name' => 'wp_update_user',
					'description' => 'Update a user – pass ID plus a “fields” object (user_email, display_name, user_pass, role).',
					'inputSchema' => array(
						'type' => 'object',
						'properties' => array(
							'ID' => array('type' => 'integer'),
							'fields' => array(
								'type' => 'object',
								'properties' => array(
									'user_email' => array('type' => 'string'),
									'display_name' => array('type' => 'string'),
									'user_pass' => array('type' => 'string'),
									'role' => array('type' => 'string'),
								),
								'additionalProperties' => true,
							),
						),
						'required' => array('ID'),
					),
				),
				
				   'wp_create_post' => array(
					   'name' => 'wp_create_post',
					   'description' => 'Crea un post. Requiere post_title. Opcionales: post_content, post_status, post_type, post_excerpt, post_author, meta_input.',
					   'inputSchema' => array(
						   'type' => 'object',
						   'properties' => array(
							   'post_title' => array('type' => 'string'),
							   'post_content' => array('type' => 'string'),
							   'post_status' => array('type' => 'string'),
							   'post_type' => array('type' => 'string'),
							   'post_excerpt' => array('type' => 'string'),
							   'post_author' => array('type' => 'integer'),
							   'meta_input' => array('type' => 'object'),
						   ),
						   'required' => array('post_title'),
					   ),
				   ),
				   'wp_get_comments' => array(
					'name' => 'wp_get_comments',
					'description' => 'List comments. Supports post_id, status, search, limit, offset, paged.',
					'inputSchema' => array('type' => 'object', 'properties' => array('post_id' => array('type' => 'integer'), 'status' => array('type' => 'string'), 'search' => array('type' => 'string'), 'limit' => array('type' => 'integer'), 'offset' => array('type' => 'integer'), 'paged' => array('type' => 'integer')), 'required' => array()),
				),
				'wp_create_comment' => array(
					'name' => 'wp_create_comment',
					'description' => 'Create a comment. Requires post_id and comment_content.',
					'inputSchema' => array('type' => 'object', 'properties' => array('post_id' => array('type' => 'integer'), 'comment_content' => array('type' => 'string'), 'comment_author' => array('type' => 'string'), 'comment_author_email' => array('type' => 'string'), 'comment_author_url' => array('type' => 'string'), 'comment_approved' => array('type' => 'integer')), 'required' => array('post_id', 'comment_content')),
				),
				   'wp_update_comment' => array(
					   'name' => 'wp_update_comment',
					   'description' => 'Update a comment by comment_ID with fields object.',
					   'inputSchema' => array(
						   'type' => 'object',
						   'properties' => array(
							   'comment_ID' => array('type' => 'integer'),
							   'fields' => array('type' => 'object'),
						   ),
						   'required' => array('comment_ID')
					   ),
				   ),
				   'wp_delete_comment' => array(
					   'name' => 'wp_delete_comment',
					   'description' => 'Delete a comment by comment_ID. Optional force flag.',
					   'inputSchema' => array(
						   'type' => 'object',
						   'properties' => array(
							   'comment_ID' => array('type' => 'integer'),
							   'force' => array('type' => 'boolean'),
						   ),
						   'required' => array('comment_ID')
					   ),
				   ),
				'wp_get_users' => array(
					'name' => 'wp_get_users',
					'description' => 'Retrieve users (fields: ID, user_login, display_name, roles).',
					'inputSchema' => array('type' => 'object', 'properties' => array('search' => array('type' => 'string'), 'role' => array('type' => 'string'), 'limit' => array('type' => 'integer'), 'offset' => array('type' => 'integer'), 'paged' => array('type' => 'integer')), 'required' => array()),
				),
				'wp_create_user' => array(
					'name' => 'wp_create_user',
					'description' => 'Create a user. Requires user_login and user_email.',
					'inputSchema' => array('type' => 'object', 'properties' => array('user_login' => array('type' => 'string'), 'user_email' => array('type' => 'string'), 'user_pass' => array('type' => 'string'), 'display_name' => array('type' => 'string'), 'role' => array('type' => 'string')), 'required' => array('user_login', 'user_email')),
				),
				'wp_update_user' => array(
					'name' => 'wp_update_user',
					'description' => 'Update a user – pass ID plus a fields object (user_email, display_name, user_pass, role).',
					'inputSchema' => array('type' => 'object', 'properties' => array('ID' => array('type' => 'integer'), 'fields' => array('type' => 'object')), 'required' => array('ID')),
				),
				'wp_get_post_meta' => array(
					'name' => 'wp_get_post_meta',
					'description' => 'Get post meta (post_id, meta_key, single).',
					'inputSchema' => array('type' => 'object', 'properties' => array('post_id' => array('type' => 'integer'), 'meta_key' => array('type' => 'string'), 'single' => array('type' => 'boolean')), 'required' => array('post_id', 'meta_key')),
				),
				   'wp_update_post_meta' => array(
					   'name' => 'wp_update_post_meta',
					   'description' => 'Update post meta (post_id, meta_key, meta_value).',
					   'inputSchema' => array('type' => 'object', 'properties' => array('post_id' => array('type' => 'integer'), 'meta_key' => array('type' => 'string'), 'meta_value' => array('type' => 'string')), 'required' => array('post_id', 'meta_key', 'meta_value')),
				   ),
				   'wp_delete_post_meta' => array(
					   'name' => 'wp_delete_post_meta',
					   'description' => 'Delete post meta (post_id, meta_key, meta_value optional).',
					   'inputSchema' => array('type' => 'object', 'properties' => array('post_id' => array('type' => 'integer'), 'meta_key' => array('type' => 'string'), 'meta_value' => array('type' => 'string')), 'required' => array('post_id', 'meta_key')),
				   ),
				'wp_get_option' => array(
					'name' => 'wp_get_option',
					'description' => 'Get a WordPress option value by name.',
					'inputSchema' => array('type' => 'object', 'properties' => array('option' => array('type' => 'string')), 'required' => array('option')),
				),
				   'wp_update_option' => array(
					   'name' => 'wp_update_option',
					   'description' => 'Update a WordPress option.',
					   'inputSchema' => array('type' => 'object', 'properties' => array('option' => array('type' => 'string'), 'value' => array('type' => 'string')), 'required' => array('option', 'value')),
				   ),
				'wp_delete_option' => array(
					'name' => 'wp_delete_option',
					'description' => 'Delete a WordPress option.',
					'inputSchema' => array('type' => 'object', 'properties' => array('option' => array('type' => 'string')), 'required' => array('option')),
				),
				'wp_activate_plugin' => array(
					'name' => 'wp_activate_plugin',
					'description' => 'Activate a plugin by file path (requires appropriate permissions).',
					'inputSchema' => array('type' => 'object', 'properties' => array('file' => array('type' => 'string')), 'required' => array('file')),
				),
				'wp_deactivate_plugin' => array(
					'name' => 'wp_deactivate_plugin',
					'description' => 'Deactivate a plugin by file path (requires appropriate permissions).',
					'inputSchema' => array('type' => 'object', 'properties' => array('file' => array('type' => 'string')), 'required' => array('file')),
				),
				'wp_get_themes' => array(
					'name' => 'wp_get_themes',
					'description' => 'List installed themes.',
					'inputSchema' => array('type' => 'object', 'properties' => (object) array(), 'required' => array()),
				),
				'wp_get_media' => array(
					'name' => 'wp_get_media',
					'description' => 'List media attachments (limit, offset).',
					'inputSchema' => array('type' => 'object', 'properties' => array('limit' => array('type' => 'integer'), 'offset' => array('type' => 'integer')), 'required' => array()),
				),
				'wp_get_media_item' => array(
					'name' => 'wp_get_media_item',
					'description' => 'Get media item details by ID.',
					'inputSchema' => array('type' => 'object', 'properties' => array('ID' => array('type' => 'integer')), 'required' => array('ID')),
				),
				'wp_upload_image_from_url' => array(
					'name' => 'wp_upload_image_from_url',
					'description' => 'Download an image from a public URL and create a media attachment. Returns attachment ID and URL.',
					'inputSchema' => array('type' => 'object', 'properties' => array('url' => array('type' => 'string')), 'required' => array('url')),
				),
				'wp_get_taxonomies' => array(
					'name' => 'wp_get_taxonomies',
					'description' => 'List registered taxonomies.',
					'inputSchema' => array('type' => 'object', 'properties' => (object) array(), 'required' => array()),
				),
				'wp_get_terms' => array(
					'name' => 'wp_get_terms',
					'description' => 'List terms for a taxonomy (taxonomy required).',
					'inputSchema' => array('type' => 'object', 'properties' => array('taxonomy' => array('type' => 'string')), 'required' => array('taxonomy')),
				),
				'wp_create_term' => array(
					'name' => 'wp_create_term',
					'description' => 'Create a term in a taxonomy (taxonomy and name required).',
					'inputSchema' => array('type' => 'object', 'properties' => array('taxonomy' => array('type' => 'string'), 'name' => array('type' => 'string')), 'required' => array('taxonomy', 'name')),
				),
				'wp_delete_term' => array(
					'name' => 'wp_delete_term',
					'description' => 'Delete a term by term_id and taxonomy.',
					'inputSchema' => array('type' => 'object', 'properties' => array('term_id' => array('type' => 'integer'), 'taxonomy' => array('type' => 'string')), 'required' => array('term_id', 'taxonomy')),
				),
				'search' => array(
					'name' => 'search',
					'description' => 'Simple search across posts (q or query param).',
					'inputSchema' => array('type' => 'object', 'properties' => array('q' => array('type' => 'string'), 'limit' => array('type' => 'integer')), 'required' => array()),
				),
				   'fetch' => array(
					   'name' => 'fetch',
					   'description' => 'Fetch a URL using WordPress HTTP API (url required, method optional).',
					   'inputSchema' => array('type' => 'object', 'properties' => array('url' => array('type' => 'string'), 'method' => array('type' => 'string'), 'headers' => array('type' => 'object'), 'body' => array('type' => 'string')), 'required' => array('url')),
				   ),
			);
			$this->tools = $tools;
		}
		return $this->tools;
	}

	/**
	 * Return tools formatted as OpenAI/ChatGPT functions (name, description, parameters)
	 * This does a light mapping from our inputSchema -> parameters property expected by OpenAI.
	 */
	public function getOpenAIFunctions() {
		$tools = $this->getToolsList();
		$funcs = array();
		foreach ($tools as $t) {
			$f = array(
				'name' => $t['name'],
				'description' => isset($t['description']) ? $t['description'] : '',
				'parameters' => array(
					'type' => 'object',
					'properties' => (isset($t['inputSchema']) ? $t['inputSchema']['properties'] : new stdClass()),
					'required' => (isset($t['inputSchema']) && isset($t['inputSchema']['required']) ? $t['inputSchema']['required'] : array()),
				),
			);
			$funcs[] = $f;
		}
		return $funcs;
	}

	/**
	 * Basic validation of arguments against a very small subset of JSON Schema.
	 * Returns true if valid, false otherwise and fills $err with a message.
	 */
	public function validateArgumentsSchema($schema, $args, & $err = '') {
		$err = '';
		if (!is_array($schema) || empty($schema['type']) || $schema['type'] !== 'object') {
			return true; // nothing to validate against
		}
		$props = isset($schema['properties']) ? $schema['properties'] : array();
		// required
		if (!empty($schema['required']) && is_array($schema['required'])) {
			foreach ($schema['required'] as $rk) {
				if (!isset($args[$rk])) {
					$err = 'Missing required parameter: ' . $rk;
					return false;
				}
			}
		}
		// types (basic)
		foreach ($props as $k => $p) {
			if (!isset($args[$k])) {
				continue;
			}
			$val = $args[$k];
			if (!isset($p['type'])) {
				continue;
			}
			$type = $p['type'];
			switch ($type) {
				case 'string':
					if (!is_string($val)) { $err = "Parameter $k must be a string"; return false; }
					break;
				case 'integer':
					if (!is_int($val) && !(is_string($val) && ctype_digit($val))) { $err = "Parameter $k must be an integer"; return false; }
					break;
				case 'boolean':
					if (!is_bool($val) && !in_array($val, array(true,false,0,1,'0','1'), true)) { $err = "Parameter $k must be boolean"; return false; }
					break;
				case 'object':
					if (!is_array($val) && !is_object($val)) { $err = "Parameter $k must be an object"; return false; }
					break;
				case 'array':
					if (!is_array($val)) { $err = "Parameter $k must be an array"; return false; }
					break;
				default:
					// unknown type -> skip
					break;
			}
		}
		return true;
	}

	/**
	 * Return the required WP capability for a given tool, or null if none (read-only).
	 * This is a coarse mapping; some tools may still perform finer-grained checks.
	 */
	public function getToolCapability($tool) {
		$map = array(
			// posts
			'wp_create_post' => 'edit_posts',
			'wp_update_post' => 'edit_posts',
			'wp_delete_post' => 'delete_posts',
			// comments
			'wp_create_comment' => 'moderate_comments',
			'wp_update_comment' => 'moderate_comments',
			'wp_delete_comment' => 'moderate_comments',
			// users
			'wp_create_user' => 'create_users',
			'wp_update_user' => 'promote_users',
			// media
			'wp_upload_image_from_url' => 'upload_files',
			// plugins/themes
			'wp_activate_plugin' => 'activate_plugins',
			'wp_deactivate_plugin' => 'activate_plugins',
			// options/meta
			'wp_update_option' => 'manage_options',
			'wp_delete_option' => 'manage_options',
			'wp_update_post_meta' => 'manage_options',
			'wp_delete_post_meta' => 'manage_options',
			// terms
			'wp_create_term' => 'manage_categories',
			'wp_delete_term' => 'manage_categories',
			// users list modifications
			'wp_update_option' => 'manage_options',
		);
		return isset($map[$tool]) ? $map[$tool] : null;
	}

	public function dispatchTool($tool, $args, $id = null) {
		$r = array('jsonrpc' => '2.0', 'id' => $id);
		$utils = 'EasyVisualMcpUtils';
		$frame = class_exists('EasyVisualMcpFrame') ? EasyVisualMcpFrame::_() : null;
		$addResultText = function(array &$r, string $text) {
			if (!isset($r['result']['content'])) {
				$r['result']['content'] = [];
			}
			$r['result']['content'][] = array('type' => 'text', 'text' => $text);
		};
		$cleanHtml = function($v) { return wp_kses_post( wp_unslash( $v ) ); };
		$postExcerpt = function($p) {
			return wp_trim_words( wp_strip_all_tags( isset($p->post_excerpt) && !empty($p->post_excerpt) ? $p->post_excerpt : $p->post_content ), 55 );
		};

		// Validate args against tool schema (basic) before dispatching
		$tools_map = $this->getTools();
		if (isset($tools_map[$tool]) && !empty($tools_map[$tool]['inputSchema'])) {
			$schema = $tools_map[$tool]['inputSchema'];
			$errMsg = '';
			if (!$this->validateArgumentsSchema($schema, is_array($args) ? $args : array(), $errMsg)) {
				$r['error'] = array('code' => -42602, 'message' => 'Invalid arguments: ' . $errMsg);
				return $r;
			}
		}
		// --- INICIO LÓGICA DE DISPATCH ADAPTADA ---
		// Enforce capability mapping for mutating tools (centralized)
		$required_cap = $this->getToolCapability($tool);
		if (!empty($required_cap) && !current_user_can($required_cap)) {
			return array('jsonrpc' => '2.0', 'id' => $id, 'error' => array('code' => 'permission_denied', 'message' => 'Insufficient permissions to execute ' . $tool . '. Required capability: ' . $required_cap));
		}
		switch ($tool) {
			case 'mcp_ping':
				$pingData = array(
					'time' => gmdate('Y-m-d H:i:s'),
					'name' => get_bloginfo('name'),
				);
				$addResultText($r, 'Ping successful: ' . wp_json_encode($pingData, JSON_PRETTY_PRINT));
				break;
			case 'wp_get_posts':
				$q = array(
					'post_type' => sanitize_key($utils::getArrayValue($args, 'post_type', 'post')),
					'post_status' => sanitize_key($utils::getArrayValue($args, 'post_status', 'publish')),
					's' => sanitize_text_field($utils::getArrayValue($args, 'search')),
					'posts_per_page' => max(1, intval($utils::getArrayValue($args, 'limit', 10, 1))),
				);
				if (isset($args['offset'])) {
					$q['offset'] = max(0, intval($args['offset']));
				}
				if (isset($args['paged'])) {
					$q['paged'] = max(1, intval($args['paged']));
				}
				$date = array();
				if (!empty($args['after'])) {
					$date['after'] = $args['after'];
				}
				if (!empty($args['before'])) {
					$date['before'] = $args['before'];
				}
				if ($date) {
					$q['date_query'] = array($date);
				}
				$rows = array();
				foreach (get_posts($q) as $p) {
					$rows[] = array(
						'ID' => $p->ID,
						'post_title' => $p->post_title,
						'post_status' => $p->post_status,
						'post_excerpt' => $postExcerpt($p),
						'permalink' => get_permalink($p),
					);
				}
				$addResultText($r, wp_json_encode($rows, JSON_PRETTY_PRINT));
				break;
			case 'wp_get_post':
				if (empty($args['ID'])) {
					$r['error'] = array('code' => -42602, 'message' => 'ID required');
					break;
				}
				$p = get_post(intval($args['ID']));
				if (!$p) {
					$r['error'] = array('code' => -42600, 'message' => 'Post not found');
					break;
				}
				$out = array(
					'ID' => $p->ID,
					'post_title' => $p->post_title,
					'post_status' => $p->post_status,
					'post_content' => $cleanHtml($p->post_content),
					'post_excerpt' => $postExcerpt($p),
					'permalink' => get_permalink($p),
					'post_date' => $p->post_date,
					'post_modified' => $p->post_modified,
				);
				$addResultText($r, wp_json_encode($out, JSON_PRETTY_PRINT));
				break;
			case 'wp_create_post':
				if (empty($args['post_title'])) {
					$r['error'] = array('code' => -42602, 'message' => 'post_title required');
					break;
				}
				$ins = array(
					'post_title' => sanitize_text_field($args['post_title']),
					'post_status' => sanitize_key($utils::getArrayValue($args, 'post_status', 'draft')),
					'post_type' => sanitize_key($utils::getArrayValue($args, 'post_type', 'post')),
				);
				if (!empty($args['post_content'])) {
					$ins['post_content'] = $args['post_content']; // Markdown a HTML si lo necesitas
				}
				if (!empty($args['post_excerpt'])) {
					$ins['post_excerpt'] = $cleanHtml($args['post_excerpt']);
				}
				if (!empty($args['post_name'])) {
					$ins['post_name'] = sanitize_title($args['post_name']);
				}
				if (!empty($args['meta_input']) && is_array($args['meta_input'])) {
					$ins['meta_input'] = $args['meta_input'];
				}
				$new = wp_insert_post($ins, true);
				if (is_wp_error($new)) {
					$r['error'] = array('code' => $new->get_error_code(), 'message' => $new->get_error_message());
				} else {
					if (empty($ins['meta_input']) && !empty($args['meta_input']) && is_array($args['meta_input'])) {
						foreach ($args['meta_input'] as $k => $v) {
							update_post_meta($new, sanitize_key($k), maybe_serialize($v));
						}
					}
					$addResultText($r, 'Post created ID ' . $new);
				}
				break;
			case 'wp_update_post':
				if (empty($args['ID'])) {
					$r['error'] = array('code' => -42602, 'message' => 'ID required');
					break;
				}
				$c = array('ID' => intval($args['ID']));
				if (!empty($args['fields']) && is_array($args['fields'])) {
					foreach ($args['fields'] as $k => $v) {
						$c[$k] = in_array($k, array('post_content', 'post_excerpt'), true) ? $cleanHtml($v) : sanitize_text_field($v);
					}
				}
				$u = ( count($c) > 1 ) ? wp_update_post($c, true) : $c['ID'];
				if (is_wp_error($u)) {
					$r['error'] = array('code' => $u->get_error_code(), 'message' => $u->get_error_message());
					break;
				}
				if (!empty($args['meta_input']) && is_array($args['meta_input'])) {
					foreach ($args['meta_input'] as $k => $v) {
						update_post_meta($u, sanitize_key($k), maybe_serialize($v));
					}
				}
				$addResultText($r, 'Post #' . $u . ' updated');
				break;
			case 'wp_delete_post':
				if (empty($args['ID'])) {
					$r['error'] = array('code' => -42602, 'message' => 'ID required');
					break;
				}
				$del = wp_delete_post(intval($args['ID']), !empty($args['force']));
				if ($del) {
					$addResultText($r, 'Post #' . $args['ID'] . ' deleted');
				} else {
					$r['error'] = array('code' => -42603, 'message' => 'Deletion failed');
				}
				break;
			case 'wp_get_comments':
				$cargs = array(
					'post_id' => $utils::getArrayValue($args, 'post_id', 0, 1),
					'status' => $utils::getArrayValue($args, 'status', 'approve'),
					'search' => $utils::getArrayValue($args, 'search'),
					'number' => max(1, $utils::getArrayValue($args, 'limit', 10, 1)),
				);
				if (isset($args['offset'])) {
					$cargs['offset'] = max(0, intval($args['offset']));
				}
				if (isset($args['paged'])) {
					$cargs['paged'] = max(1, intval($args['paged']));
				}
				$list = array();
				foreach (get_comments($cargs) as $c) {
					$list[] = array(
						'comment_ID' => $c->comment_ID,
						'comment_post_ID' => $c->comment_post_ID,
						'comment_author' => $c->comment_author,
						'comment_content' => wp_trim_words(wp_strip_all_tags($c->comment_content), 40),
						'comment_date' => $c->comment_date,
						'comment_approved' => $c->comment_approved,
					);
				}
				$addResultText($r, wp_json_encode($list, JSON_PRETTY_PRINT));
				break;
			case 'wp_create_comment':
				if (empty($args['post_id']) || empty($args['comment_content'])) {
					$r['error'] = array('code' => -42602, 'message' => 'post_id & comment_content required');
					break;
				}
				$ins = array(
					'comment_post_ID' => intval($args['post_id']),
					'comment_content' => $cleanHtml($args['comment_content']),
					'comment_author' => sanitize_text_field($utils::getArrayValue($args, 'comment_author')),
					'comment_author_email' => sanitize_email($utils::getArrayValue($args, 'comment_author_email')),
					'comment_author_url' => esc_url_raw($utils::getArrayValue($args, 'comment_author_url')),
					'comment_approved' => $utils::getArrayValue($args, 'comment_approved', 1),
				);
				$cid = wp_insert_comment($ins);

				if (is_wp_error($cid)) {
					$r['error'] = array(
						'code' => $cid instanceof WP_Error ? $cid->get_error_code() : -1,
						'message' => $cid instanceof WP_Error ? $cid->get_error_message() : 'Unknown error occurred.'
					);
				} elseif ($cid === false) {
					$r['error'] = array(
						'code' => -1,
						'message' => 'Unknown error occurred while creating the comment.'
					);
				} elseif (is_int($cid)) {
					$addResultText($r, 'Comment created successfully with ID ' . $cid);
				} else {
					$r['error'] = array(
						'code' => -1,
						'message' => 'Unexpected return type from wp_insert_comment.'
					);
				}
				break;
			case 'wp_update_comment':
				if (empty($args['comment_ID'])) {
					$r['error'] = array('code' => -42602, 'message' => 'comment_ID required');
					break;
				}
				$c = array('comment_ID' => intval($args['comment_ID']));
				if (!empty($args['fields']) && is_array($args['fields'])) {
					foreach ($args['fields'] as $k => $v) {
						$c[$k] = ( 'comment_content' === $k ) ? $cleanHtml($v) : sanitize_text_field($v);
					}
				}
				$cid = wp_update_comment($c, true);
				if (is_wp_error($cid)) {
					$r['error'] = array('code' => $cid->get_error_code(), 'message' => $cid->get_error_message());
				} else {
					$addResultText($r, 'Comment #' . $cid . ' updated');
				}
				break;
			case 'wp_delete_comment':
				if (empty($args['comment_ID'])) {
					$r['error'] = array('code' => -42602, 'message' => 'comment_ID required');
					break;
				}
				$done = wp_delete_comment(intval($args['comment_ID']), !empty($args['force']));
				if ($done) {
					$addResultText($r, 'Comment #' . $args['comment_ID'] . ' deleted');
				} else {
					$r['error'] = array('code' => -42603, 'message' => 'Deletion failed');
				}
				break;
			case 'wp_get_users':
				$q = array(
					'search' => '*' . esc_attr($utils::getArrayValue($args, 'search')) . '*',
					'role' => $utils::getArrayValue($args, 'role'),
					'number' => max(1, intval($utils::getArrayValue($args, 'limit', 10, 1))),
				);
				if (isset($args['offset'])) {
					$q['offset'] = max(0, intval($args['offset']));
				}
				if (isset($args['paged'])) {
					$q['paged'] = max(1, intval($args['paged']));
				}
				$rows = array();
				foreach (get_users($q) as $u) {
					$rows[] = array(
						'ID' => $u->ID,
						'user_login' => $u->user_login,
						'display_name' => $u->display_name,
						'roles' => $u->roles,
					);
				}
				$addResultText($r, wp_json_encode($rows, JSON_PRETTY_PRINT));
				break;
			case 'wp_create_user':
				$data = array(
					'user_login' => sanitize_user($args['user_login']),
					'user_email' => sanitize_email($args['user_email']),
					'user_pass' => $utils::getArrayValue($args, 'user_pass', wp_generate_password(12, true)),
					'display_name' => sanitize_text_field($utils::getArrayValue($args, 'display_name')),
					'role' => sanitize_key($utils::getArrayValue($args, 'role', get_option('default_role', 'subscriber'))),
				);
				$uid = wp_insert_user($data);
				if (is_wp_error($uid)) {
					$r['error'] = array('code' => $uid->get_error_code(), 'message' => $uid->get_error_message());
				} else {
					$addResultText($r, 'User created ID ' . $uid);
				}
				break;
			case 'wp_update_user':
				if (empty($args['ID'])) {
					$r['error'] = array('code' => -42602, 'message' => 'ID required');
					break;
				}
				$upd = array('ID' => intval($args['ID']));
				if (!empty($args['fields']) && is_array($args['fields'])) {
					foreach ($args['fields'] as $k => $v) {
						$upd[ $k ] = ( 'role' === $k ) ? sanitize_key($v) : sanitize_text_field($v);
					}
				}
				$u = wp_update_user($upd);
				if (is_wp_error($u)) {
					$r['error'] = array('code' => $u->get_error_code(), 'message' => $u->get_error_message());
				} else {
					$addResultText($r, 'User #' . $u . ' updated');
				}
				break;
				case 'wp_list_plugins':
					if (!function_exists('get_plugins')) {
						require_once ABSPATH . 'wp-admin/includes/plugin.php';
					}
					$all = get_plugins();
					$rows = array();
					foreach ($all as $file => $meta) {
						$rows[] = array('file' => $file, 'Name' => $meta['Name'] ?? '', 'Version' => $meta['Version'] ?? '', 'active' => is_plugin_active($file));
					}
					$addResultText($r, wp_json_encode($rows, JSON_PRETTY_PRINT));
					break;

				case 'wp_activate_plugin':
					if (empty($args['file'])) {
						$r['error'] = array('code' => -42602, 'message' => 'file parameter required');
						break;
					}
					if (!current_user_can('activate_plugins')) {
						$r['error'] = array('code' => 'permission_denied', 'message' => 'Insufficient permissions');
						break;
					}
					require_once ABSPATH . 'wp-admin/includes/plugin.php';
					$resp = activate_plugin(sanitize_text_field($args['file']));
					if (is_wp_error($resp)) {
						$r['error'] = array('code' => $resp->get_error_code(), 'message' => $resp->get_error_message());
					} else {
						$addResultText($r, 'Plugin activated: ' . $args['file']);
					}
					break;

				case 'wp_deactivate_plugin':
					if (empty($args['file'])) {
						$r['error'] = array('code' => -42602, 'message' => 'file parameter required');
						break;
					}
					if (!current_user_can('activate_plugins')) {
						$r['error'] = array('code' => 'permission_denied', 'message' => 'Insufficient permissions');
						break;
					}
					deactivate_plugins(sanitize_text_field($args['file']));
					$addResultText($r, 'Plugin deactivated: ' . $args['file']);
					break;

				case 'wp_get_themes':
					$themes = wp_get_themes();
					$out = array();
					foreach ($themes as $slug => $theme) {
						$out[] = array('slug' => $slug, 'Name' => $theme->get('Name'), 'Version' => $theme->get('Version'));
					}
					$addResultText($r, wp_json_encode($out, JSON_PRETTY_PRINT));
					break;

				case 'wp_get_media':
					$q = array('post_type' => 'attachment', 'posts_per_page' => max(1, intval($utils::getArrayValue($args, 'limit', 20, 1))));
					if (isset($args['offset'])) { $q['offset'] = max(0, intval($args['offset'])); }
					$rows = array();
					foreach (get_posts($q) as $a) {
						$rows[] = array('ID' => $a->ID, 'post_title' => $a->post_title, 'mime_type' => get_post_mime_type($a), 'url' => wp_get_attachment_url($a->ID));
					}
					$addResultText($r, wp_json_encode($rows, JSON_PRETTY_PRINT));
					break;

				case 'wp_get_media_item':
					if (empty($args['ID'])) { $r['error'] = array('code' => -42602, 'message' => 'ID required'); break; }
					$att = get_post(intval($args['ID']));
					if (!$att || 'attachment' !== $att->post_type) { $r['error'] = array('code' => -42600, 'message' => 'Media not found'); break; }
					$meta = wp_get_attachment_metadata($att->ID);
					$out = array('ID' => $att->ID, 'post_title' => $att->post_title, 'mime_type' => get_post_mime_type($att), 'url' => wp_get_attachment_url($att->ID), 'meta' => $meta);
					$addResultText($r, wp_json_encode($out, JSON_PRETTY_PRINT));
					break;
				case 'wp_upload_image_from_url':
					$url = esc_url_raw($utils::getArrayValue($args, 'url'));
					if (!$url) { $r['error'] = array('code' => -42602, 'message' => 'url required'); break; }
					if (!current_user_can('upload_files')) { $r['error'] = array('code' => 'permission_denied', 'message' => 'Insufficient permissions to upload files'); break; }
					require_once ABSPATH . 'wp-admin/includes/file.php';
					require_once ABSPATH . 'wp-admin/includes/media.php';
					require_once ABSPATH . 'wp-admin/includes/image.php';
					$tmp = download_url($url);
					if (is_wp_error($tmp)) { $r['error'] = array('code' => 'download_error', 'message' => $tmp->get_error_message()); break; }
					$file = array();
					$file['name'] = wp_basename($url);
					$file['tmp_name'] = $tmp;
					$att_id = media_handle_sideload($file, 0);
					if (is_wp_error($att_id)) { @unlink($file['tmp_name']); $r['error'] = array('code' => 'sideload_error', 'message' => $att_id->get_error_message()); break; }
					   $att_url = wp_get_attachment_url($att_id);
					   $addResultText($r, 'Imagen subida correctamente. ID: ' . $att_id . ', URL: ' . $att_url);
					   return $r;

				case 'wp_get_taxonomies':
					$tax = get_taxonomies(array(), 'objects');
					$out = array();
					foreach ($tax as $k => $o) { $out[] = array('name' => $k, 'label' => $o->label); }
					$addResultText($r, wp_json_encode($out, JSON_PRETTY_PRINT));
					break;

				case 'wp_get_terms':
					$taxonomy = sanitize_text_field($utils::getArrayValue($args, 'taxonomy'));
					if (!$taxonomy) { $r['error'] = array('code' => -42602, 'message' => 'taxonomy required'); break; }
					$terms = get_terms(array('taxonomy' => $taxonomy, 'hide_empty' => false));
					$out = array();
					foreach ($terms as $t) { $out[] = array('term_id' => $t->term_id, 'name' => $t->name, 'slug' => $t->slug, 'count' => $t->count); }
					$addResultText($r, wp_json_encode($out, JSON_PRETTY_PRINT));
					break;

				case 'wp_create_term':
					$taxonomy = sanitize_text_field($utils::getArrayValue($args, 'taxonomy'));
					$name = sanitize_text_field($utils::getArrayValue($args, 'name'));
					if (!$taxonomy || !$name) { $r['error'] = array('code' => -42602, 'message' => 'taxonomy & name required'); break; }
					$res = wp_insert_term($name, $taxonomy);
					if (is_wp_error($res)) { $r['error'] = array('code' => $res->get_error_code(), 'message' => $res->get_error_message()); } else { $addResultText($r, 'Term created: ' . json_encode($res)); }
					break;

				case 'wp_delete_term':
					$term_id = intval($utils::getArrayValue($args, 'term_id'));
					$taxonomy = sanitize_text_field($utils::getArrayValue($args, 'taxonomy'));
					if (!$term_id || !$taxonomy) { $r['error'] = array('code' => -42602, 'message' => 'term_id & taxonomy required'); break; }
					$done = wp_delete_term($term_id, $taxonomy);
					if (is_wp_error($done)) { $r['error'] = array('code' => $done->get_error_code(), 'message' => $done->get_error_message()); } else { $addResultText($r, 'Term deleted'); }
					break;

				case 'search':
					// simple search wrapper (posts)
					$s = sanitize_text_field($utils::getArrayValue($args, 'q', $utils::getArrayValue($args, 'query', '')));
					$limit = max(1, intval($utils::getArrayValue($args, 'limit', 10, 1)));
					$q = new WP_Query(array('s' => $s, 'posts_per_page' => $limit));
					$out = array();
					foreach ($q->posts as $p) { $out[] = array('ID' => $p->ID, 'post_title' => $p->post_title, 'excerpt' => $postExcerpt($p), 'permalink' => get_permalink($p)); }
					$addResultText($r, wp_json_encode($out, JSON_PRETTY_PRINT));
					break;

				case 'fetch':
					$url = esc_url_raw($utils::getArrayValue($args, 'url'));
					if (!$url) { $r['error'] = array('code' => -42602, 'message' => 'url required'); break; }
					$method = strtoupper($utils::getArrayValue($args, 'method', 'GET'));
					$opts = array();
					if (!empty($args['headers']) && is_array($args['headers'])) { $opts['headers'] = $args['headers']; }
					if (!empty($args['body'])) { $opts['body'] = $args['body']; }
					if ('GET' === $method) { $resp = wp_remote_get($url, $opts); } else { $resp = wp_remote_request($url, array_merge($opts, array('method' => $method))); }
					if (is_wp_error($resp)) { $r['error'] = array('code' => 'fetch_error', 'message' => $resp->get_error_message()); break; }
					   $code = wp_remote_retrieve_response_code($resp);
					   $body = wp_remote_retrieve_body($resp);
					   $maxlen = 2000;
					   $body_short = (strlen($body) > $maxlen) ? substr($body, 0, $maxlen) . "... [truncated]" : $body;
					   $addResultText($r, "Fetch status: $code\n" . $body_short);
					   return $r;

			   case 'wp_get_post_meta':
				   if (!current_user_can('manage_options')) {
					   $r['error'] = array('code' => 'permission_denied', 'message' => 'No tienes permisos para manipular meta.');
					   return $r;
				   }
				   $post_id = isset($args['post_id']) ? intval($args['post_id']) : 0;
				   $meta_key = isset($args['meta_key']) ? sanitize_text_field($args['meta_key']) : '';
				   if (!$post_id || !$meta_key) {
					   $r['error'] = array('code' => 'invalid_params', 'message' => 'Faltan parámetros.');
					   return $r;
				   }
				   $single = isset($args['single']) ? (bool)$args['single'] : true;
				   $value = get_post_meta($post_id, $meta_key, $single);
				   $addResultText($r, 'Valor de meta (' . $meta_key . ') para post ' . $post_id . ': ' . var_export($value, true));
				   return $r;
			   case 'wp_update_post_meta':
				   if (!current_user_can('manage_options')) {
					   $r['error'] = array('code' => 'permission_denied', 'message' => 'No tienes permisos para manipular meta.');
					   return $r;
				   }
				   $post_id = isset($args['post_id']) ? intval($args['post_id']) : 0;
				   $meta_key = isset($args['meta_key']) ? sanitize_text_field($args['meta_key']) : '';
				   $meta_value = isset($args['meta_value']) ? $args['meta_value'] : null;
				   if (!$post_id || !$meta_key) {
					   $r['error'] = array('code' => 'invalid_params', 'message' => 'Faltan parámetros.');
					   return $r;
				   }
				   $updated = update_post_meta($post_id, $meta_key, $meta_value);
				   if ($updated) {
					   $addResultText($r, 'Meta creado/actualizado para post ' . $post_id . ' (' . $meta_key . ')');
				   } else {
					   $addResultText($r, 'No se pudo crear/actualizar el metadato para post ' . $post_id . ' (' . $meta_key . ')');
				   }
				   return $r;
			   case 'wp_delete_post_meta':
				   if (!current_user_can('manage_options')) {
					   $r['error'] = array('code' => 'permission_denied', 'message' => 'No tienes permisos para manipular meta.');
					   return $r;
				   }
				   $post_id = isset($args['post_id']) ? intval($args['post_id']) : 0;
				   $meta_key = isset($args['meta_key']) ? sanitize_text_field($args['meta_key']) : '';
				   $meta_value = isset($args['meta_value']) ? $args['meta_value'] : null;
				   if (!$post_id || !$meta_key) {
					   $r['error'] = array('code' => 'invalid_params', 'message' => 'Faltan parámetros.');
					   return $r;
				   }
				   $deleted = delete_post_meta($post_id, $meta_key, $meta_value);
				   if ($deleted) {
					   $addResultText($r, 'Metadato (' . $meta_key . ') eliminado para post ' . $post_id);
				   } else {
					   $addResultText($r, 'No se eliminó el metadato (' . $meta_key . ') para post ' . $post_id);
				   }
				   return $r;
			   case 'wp_get_option':
				   if (!current_user_can('manage_options')) {
					   $r['error'] = array('code' => 'permission_denied', 'message' => 'No tienes permisos para manipular opciones.');
					   return $r;
				   }
				   $option = isset($args['option']) ? sanitize_text_field($args['option']) : '';
				   if (!$option) {
					   $r['error'] = array('code' => 'invalid_params', 'message' => 'Falta el parámetro option.');
					   return $r;
				   }
				   $val = get_option($option);
				   $addResultText($r, 'Valor de opción (' . $option . '): ' . var_export($val, true));
				   return $r;
			   case 'wp_update_option':
				   if (!current_user_can('manage_options')) {
					   $r['error'] = array('code' => 'permission_denied', 'message' => 'No tienes permisos para manipular opciones.');
					   return $r;
				   }
				   $option = isset($args['option']) ? sanitize_text_field($args['option']) : '';
				   $value = isset($args['value']) ? $args['value'] : null;
				   if (!$option) {
					   $r['error'] = array('code' => 'invalid_params', 'message' => 'Falta el parámetro option.');
					   return $r;
				   }
				   $old_val = get_option($option, null);
				   $updated = update_option($option, $value);
				   if ($updated) {
					   $addResultText($r, 'Opción (' . $option . ') actualizada correctamente.');
				   } else if ($old_val === $value) {
					   $addResultText($r, 'La opción (' . $option . ') ya tenía ese valor, no se modificó.');
				   } else {
					   $addResultText($r, 'No se pudo actualizar la opción (' . $option . ').');
				   }
				   return $r;
			   case 'wp_delete_option':
				   if (!current_user_can('manage_options')) {
					   $r['error'] = array('code' => 'permission_denied', 'message' => 'No tienes permisos para manipular opciones.');
					   return $r;
				   }
				   $option = isset($args['option']) ? sanitize_text_field($args['option']) : '';
				   if (!$option) {
					   $r['error'] = array('code' => 'invalid_params', 'message' => 'Falta el parámetro option.');
					   return $r;
				   }
				   $deleted = delete_option($option);
				   if ($deleted) {
					   $addResultText($r, 'Opción (' . $option . ') eliminada');
				   } else {
					   $addResultText($r, 'No se eliminó la opción (' . $option . ')');
				   }
				   return $r;
			   default:
				   $r['error'] = array('code' => -42609, 'message' => 'Unknown tool');
	   }
		return $r;
	}
}
