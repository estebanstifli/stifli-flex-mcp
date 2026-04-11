<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
class StifliFlexMcp {
	private $logging = false;
	private $addedFilter = false;
	private $namespace = 'stifli-flex-mcp/v1';
	private $sessionID = null;
	private $lastAction = 0;
	private $protocolVersion = '2025-06-18';
	private $serverVersion = '0.0.1';
	private $queueTable = '';
	private $queueTtl = 300; // seconds

	public function __construct() {
		global $wpdb;
		if (isset($wpdb->prefix)) {
			$this->queueTable = StifliFlexMcpUtils::getPrefixedTable('sflmcp_queue', false);
		}
	}

	public function init() {
		add_action('rest_api_init', array($this, 'restApiInit'));
		// Register admin menu and settings when in WP admin
		if (is_admin()) {
			add_action('admin_menu', array($this, 'registerAdmin'));
			add_action('admin_menu', array($this, 'registerMcpServerSubmenu'), 15);
			add_action('admin_menu', array($this, 'registerMultimediaSubmenu'), 25);
			add_action('admin_init', array($this, 'registerSettings'));
			add_action('admin_enqueue_scripts', array($this, 'enqueueAdminScripts'));
			// AJAX handlers for profiles management
			add_action('wp_ajax_sflmcp_create_profile', array($this, 'ajax_create_profile'));
			add_action('wp_ajax_sflmcp_update_profile', array($this, 'ajax_update_profile'));
			add_action('wp_ajax_sflmcp_delete_profile', array($this, 'ajax_delete_profile'));
			add_action('wp_ajax_sflmcp_duplicate_profile', array($this, 'ajax_duplicate_profile'));
			add_action('wp_ajax_sflmcp_apply_profile', array($this, 'ajax_apply_profile'));
			add_action('wp_ajax_sflmcp_export_profile', array($this, 'ajax_export_profile'));
			add_action('wp_ajax_sflmcp_import_profile', array($this, 'ajax_import_profile'));
			add_action('wp_ajax_sflmcp_restore_system_profiles', array($this, 'ajax_restore_system_profiles'));
			// AJAX handlers for custom tools
			add_action('wp_ajax_sflmcp_get_custom_tools', array($this, 'ajax_get_custom_tools'));
			add_action('wp_ajax_sflmcp_save_custom_tool', array($this, 'ajax_save_custom_tool'));
			add_action('wp_ajax_sflmcp_delete_custom_tool', array($this, 'ajax_delete_custom_tool'));
			add_action('wp_ajax_sflmcp_test_custom_tool', array($this, 'ajax_test_custom_tool'));
			add_action('wp_ajax_sflmcp_toggle_custom_tool', array($this, 'ajax_toggle_custom_tool'));
			// AJAX handlers for WordPress/WooCommerce tools
			add_action('wp_ajax_sflmcp_toggle_tool', array($this, 'ajax_toggle_tool'));
			add_action('wp_ajax_sflmcp_bulk_toggle_tools', array($this, 'ajax_bulk_toggle_tools'));
			// AJAX handlers for WordPress Abilities API (WordPress 6.9+)
			add_action('wp_ajax_sflmcp_discover_abilities', array($this, 'ajax_discover_abilities'));
			add_action('wp_ajax_sflmcp_import_ability', array($this, 'ajax_import_ability'));
			add_action('wp_ajax_sflmcp_toggle_ability', array($this, 'ajax_toggle_ability'));
			add_action('wp_ajax_sflmcp_delete_ability', array($this, 'ajax_delete_ability'));
			add_action('wp_ajax_sflmcp_get_imported_abilities', array($this, 'ajax_get_imported_abilities'));
			// AJAX handlers for Multimedia settings
			add_action('wp_ajax_sflmcp_save_multimedia_settings', array($this, 'ajax_save_multimedia_settings'));
			add_action('wp_ajax_sflmcp_load_multimedia_settings', array($this, 'ajax_load_multimedia_settings'));
			add_action('wp_ajax_sflmcp_mm_toggle_tool', array($this, 'ajax_mm_toggle_tool'));
			add_action('wp_ajax_sflmcp_mm_reveal_key', array($this, 'ajax_mm_reveal_key'));
			// AJAX handlers for OAuth Clients
			add_action('wp_ajax_sflmcp_oauth_delete_client', array($this, 'ajax_oauth_delete_client'));
			add_action('wp_ajax_sflmcp_oauth_revoke_token', array($this, 'ajax_oauth_revoke_token'));
			add_action('wp_ajax_sflmcp_oauth_save_settings', array($this, 'ajax_oauth_save_settings'));
		}
	}

	public function restApiInit() {
		register_rest_route($this->namespace, '/sse', array(
			'methods' => 'GET',
			'callback' => array($this, 'handleSSE'),
			'permission_callback' => function( $request ) {
				return $this->canAccessMCP($request);
			},
		));
		register_rest_route($this->namespace, '/sse', array(
			'methods' => 'POST',
			'callback' => array($this, 'handleSSE'),
			'permission_callback' => function( $request ) {
				return $this->canAccessMCP($request);
			},
		));
		register_rest_route($this->namespace, '/messages', array(
			'methods' => 'POST',
			'callback' => array($this, 'handleMessage'),
			'permission_callback' => function( $request ) {
				return $this->canAccessMCP($request);
			},
		));
		StifliFlexMcpDispatcher::addFilter('sflmcp_callback', array($this, 'handleCallback'), 10, 4);
	}

	/**
	 * Check if the request can access the MCP endpoint.
	 * WordPress 5.6+ handles Application Password authentication natively.
	 * This method only checks that the user has sufficient capabilities.
	 * 
	 * @see https://developer.wordpress.org/rest-api/using-the-rest-api/authentication/
	 * @see https://make.wordpress.org/core/2020/11/05/application-passwords-integration-guide/
	 */
	public function canAccessMCP( $request ) {
		// --- Verbose debug logging ---
		$req_method = $request->get_method();
		$req_route  = $request->get_route();
		$auth_hdr   = $request->get_header( 'Authorization' );
		stifli_flex_mcp_log( sprintf(
			'canAccessMCP: %s %s | Auth: %s | User-Agent: %s',
			$req_method,
			$req_route,
			$auth_hdr ? substr( $auth_hdr, 0, 20 ) . '...' : '(none)',
			$request->get_header( 'User-Agent' ) ?: '(none)'
		) );

		// --- Rate limiting: 30 requests/minute per IP ---
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '0.0.0.0';
		$rate_key = 'sflmcp_rate_' . md5( $ip );
		$rate_data = get_transient( $rate_key );
		if ( false === $rate_data ) {
			$rate_data = array( 'count' => 0, 'start' => time() );
		}
		$rate_data['count']++;
		$window = 60; // seconds
		$limit  = 30; // max requests per window
		if ( ( time() - $rate_data['start'] ) > $window ) {
			// Window expired, reset.
			$rate_data = array( 'count' => 1, 'start' => time() );
		} elseif ( $rate_data['count'] > $limit ) {
			stifli_flex_mcp_log( sprintf( 'canAccessMCP: Rate limit exceeded for IP %s (%d requests in %ds)', $ip, $rate_data['count'], time() - $rate_data['start'] ) );
			return new WP_Error( 'rate_limit_exceeded', 'Rate limit exceeded. Max ' . $limit . ' requests per minute.', array( 'status' => 429 ) );
		}
		set_transient( $rate_key, $rate_data, $window );

		// --- OAuth 2.1 Bearer token validation ---
		$auth_header = $request->get_header( 'Authorization' );
		if ( $auth_header && stripos( $auth_header, 'Bearer ' ) === 0 ) {
			$bearer_token = substr( $auth_header, 7 );
			if ( class_exists( 'StifliFlexMcp_OAuth_Server' ) ) {
				$oauth_user_id = StifliFlexMcp_OAuth_Server::get_instance()->validate_token( $bearer_token );
				if ( $oauth_user_id ) {
					wp_set_current_user( $oauth_user_id );
					stifli_flex_mcp_log( sprintf( 'canAccessMCP: OAuth token validated for user %d', $oauth_user_id ) );

					// Resolve OAuth client name for source tracking
					if ( class_exists( 'StifliFlexMcp_ChangeTracker' ) ) {
						$client_label = $this->resolveOAuthClientLabel( $bearer_token );
						StifliFlexMcp_ChangeTracker::setSourceContext( 'mcp', $client_label );
					}

					return true;
				}
			}
			// Bearer token present but invalid → 401.
			stifli_flex_mcp_log( 'canAccessMCP: Invalid OAuth Bearer token' );
			return new WP_Error( 'invalid_token', 'Invalid or expired Bearer token.', array( 'status' => 401 ) );
		}

		$current_user_id = get_current_user_id();
		
		if ($current_user_id > 0 && current_user_can('edit_posts')) {
			stifli_flex_mcp_log(sprintf('canAccessMCP: user %d has sufficient capabilities', $current_user_id));
			return true;
		}
		
		stifli_flex_mcp_log('canAccessMCP: Access denied - no authenticated user with edit_posts capability');
		if ( class_exists( 'StifliFlexMcp_OAuth_Server' ) ) {
			return StifliFlexMcp_OAuth_Server::get_instance()->get_unauthorized_error();
		}
		return false;
	}

	/**
	 * Resolve OAuth client_name from a bearer token for source tracking.
	 *
	 * @param string $bearer_token Raw bearer token.
	 * @return string Client name or empty string.
	 */
	private function resolveOAuthClientLabel( $bearer_token ) {
		if ( ! class_exists( 'StifliFlexMcp_OAuth_Storage' ) ) {
			return '';
		}
		$storage = StifliFlexMcp_OAuth_Storage::get_instance();
		$record  = $storage->validate_access_token( $bearer_token );
		if ( $record && ! empty( $record->client_id ) ) {
			$client = $storage->get_client( $record->client_id );
			if ( $client && ! empty( $client->client_name ) ) {
				return $client->client_name;
			}
		}
		return '';
	}

	public function handleCallback( $result, string $tool, array $args, $id ) {
		if (!empty($result)) {
			return $result;
		}
		$tools = $this->getModel()->getTools();
		if (!isset($tools[$tool])) {
			StifliFlexMcpFrame::_()->saveDebugLogging('Tool not found ' . $tool, false, 'SFLMCP');
			return $result;
		}
		return $this->getModel()->dispatchTool($tool, $args, $id);
	}

	private function getSSEid($req) {
		$last = $req ? $req->get_header('last-event-id') : '';
		return empty($last) ? str_replace('-', '', wp_generate_uuid4()) : $last;
	}

	public function handleSSE( $request ) {
		$body = $request->get_body();
		$remote = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'n/a';
		$uaHeader = $request->get_header('User-Agent');
		$ua = $uaHeader ? sanitize_text_field( $uaHeader ) : 'n/a';
		$hdrAuth = $request->get_header('Authorization') ? 'present' : 'none';
		$qp = $request->get_param('token') ? 'present' : 'none';
		stifli_flex_mcp_log(sprintf('handleSSE start: remote=%s, method=%s, auth_header=%s, query_token=%s, body_len=%d, ua=%s', $remote, $request->get_method(), $hdrAuth, $qp, strlen($body), $ua));
		if ($request->get_method() === 'POST' && !empty($body)) {
			$data = json_decode($body, true);
			if ($data && isset($data['method'])) {
				return $this->handleDirectJsonRPC($request, $data);
			}
		}
		if ( function_exists( 'ini_set' ) ) {
			ini_set('zlib.output_compression', '0'); // phpcs:ignore WordPress.PHP.DiscouragedFunctions.runtime_ini_set,Squiz.PHP.DiscouragedFunctions.Discouraged
			ini_set('output_buffering', '0'); // phpcs:ignore WordPress.PHP.DiscouragedFunctions.runtime_ini_set,Squiz.PHP.DiscouragedFunctions.Discouraged
			ini_set('implicit_flush', '1'); // phpcs:ignore WordPress.PHP.DiscouragedFunctions.runtime_ini_set,Squiz.PHP.DiscouragedFunctions.Discouraged
		}
		if (function_exists('ob_implicit_flush')) {
			ob_implicit_flush( true );
		}
		header('Content-Type: text/event-stream');
		header('Cache-Control: no-cache');
		header('X-Accel-Buffering: no');
		header('Connection: keep-alive');
		header('Access-Control-Allow-Origin: *');
		header('Access-Control-Allow-Headers: Authorization, Content-Type');
		while (ob_get_level()) {
			ob_end_flush();
		}
		$this->sessionID = $this->getSSEid($request);
		$this->lastAction = time();
		$msgUri = sprintf('%s/messages?session_id=%s', rest_url($this->namespace), $this->sessionID);
		// Note: Client must include HTTP Basic auth header when posting to msgUri
		stifli_flex_mcp_log('handleSSE: sessionID=' . $this->sessionID . ' msgUri=' . $msgUri);
		$this->reply('endpoint', $msgUri, 'text');
		while (true) {
			$maxTime = $this->logging ? 60 : 60 * 5;
			$idle = ( time() - $this->lastAction ) >= $maxTime;
			if (connection_aborted() || $idle) {
				stifli_flex_mcp_log('handleSSE: connection aborted or idle, aborting session ' . $this->sessionID);
				$this->reply('bye');
				break;
			}
			foreach ($this->fetchMessages($this->sessionID) as $p) {
				if (isset($p['method']) && 'SFLMCP/kill' === $p['method']) {
					$this->reply('bye');
					exit;
				}
				stifli_flex_mcp_log('handleSSE: sending message to session ' . $this->sessionID . ' method=' . (isset($p['method']) ? $p['method'] : 'n/a'));
				$this->reply('message', $p);
			}
			usleep(200000);
			if (time() - $this->lastAction > 10) $this->reply('heartbeat', ['status' => 'alive']);
		}
		exit;
	}

	private function reply( string $event, $data = null, string $enc = 'json' ) {
		if ('bye' === $event) {
			echo "event: bye\ndata: \n\n";
			if (ob_get_level()) {
				ob_end_flush();
			}
			flush();
			$this->lastAction = time();
			return;
		}
		if ('json' === $enc && null === $data) {
			return;
		}
		echo 'event: ' . esc_attr( $event ) . "\n";
		if ('json' === $enc) {
			// SSE data is consumed by MCP clients (not HTML context).
			// wp_json_encode handles safe encoding; esc_html would break JSON
			// by converting " to &quot;.  phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			$data = null === $data ? '{}' : str_replace('[]', '{}', wp_json_encode($data, JSON_UNESCAPED_UNICODE));
		}
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SSE text/event-stream data, not HTML context
		echo 'data: ' . $data . "\n\n";
		if (ob_get_level()) {
			ob_end_flush();
		}
		flush();
		$this->lastAction = time();
	}

	public function handleDirectJsonRPC( $request, $data ) {
		$id = isset($data['id']) ? $data['id'] : null;
		$method = isset($data['method']) ? $data['method'] : null;
		$qp = $request->get_param('token') ? 'present' : 'none';
		$hdr = $request->get_header('Authorization') ? 'present' : 'none';
		stifli_flex_mcp_log(sprintf('handleDirectJsonRPC: id=%s method=%s header=%s query=%s', $id, $method, $hdr, $qp));

		// Set session_id for ChangeTracker — use query param or generate per request.
		if ( class_exists( 'StifliFlexMcp_ChangeTracker' ) ) {
			$sess = sanitize_text_field( $request->get_param( 'session_id' ) );
			if ( ! $sess ) {
				$sess = 'mcp-' . wp_generate_uuid4();
			}
			StifliFlexMcp_ChangeTracker::getInstance()->setSessionId( $sess );
		}
		if (json_last_error() !== JSON_ERROR_NONE) {
			return new WP_REST_Response(array(
				'jsonrpc' => '2.0',
				'id' => null,
				'error' => array('code' => -32700, 'message' => 'Parse error: invalid JSON'),
			), 200);
		}
		if (!is_array($data) || !$method) {
			return new WP_REST_Response(array(
				'jsonrpc' => '2.0',
				'id' => $id,
				'error' => array('code' => -32600, 'message' => 'Invalid Request'),
			), 200);
		}
		try {
			$reply = null;
			switch ($method) {
				case 'initialize':
					$params = StifliFlexMcpUtils::getArrayValue($data, 'params', array(), 2);
					$reqVersion = StifliFlexMcpUtils::getArrayValue($params, 'protocolVersion', null);
					$clientInfo = StifliFlexMcpUtils::getArrayValue($params, 'clientInfo', false);

					// Store MCP client name for source tracking
					if ( $clientInfo && class_exists( 'StifliFlexMcp_ChangeTracker' ) ) {
						$client_name = is_array( $clientInfo ) && ! empty( $clientInfo['name'] ) ? $clientInfo['name'] : '';
						if ( $client_name ) {
							StifliFlexMcp_ChangeTracker::setSourceContext( 'mcp', $client_name );
						}
					}

					$reply = array(
						'jsonrpc' => '2.0',
						'id' => $id,
						'result' => array(
							'protocolVersion' => $this->protocolVersion,
							'serverInfo' => (object) array(
								'name' => get_bloginfo('name') . ' StifliFlexMcp',
								'version' => $this->serverVersion,
							),
							'capabilities' => array(
								'tools' => array('listChanged' => true),
								'prompts' => array('subscribe' => false, 'listChanged' => false),
								'resources' => array('subscribe' => false, 'listChanged' => false),
							),
						),
					);
					break;
				case 'tools/list':
					$tools = $this->getToolsList();
					$reply = array(
						'jsonrpc' => '2.0',
						'id' => $id,
						'result' => array('tools' => $tools),
					);
					break;
				   case 'tools/call':
					   $params = StifliFlexMcpUtils::getArrayValue($data, 'params', array(), 2);
					   // Compatibilidad: acepta tanto 'name'/'arguments' como 'tool'/'args'
					   $tool = null;
					   $arguments = array();
					   if (isset($params['name'])) {
						   $tool = $params['name'];
						   $arguments = isset($params['arguments']) ? $params['arguments'] : array();
					   } elseif (isset($params['tool'])) {
						   $tool = $params['tool'];
						   $arguments = isset($params['args']) ? $params['args'] : array();
					   }
					   $reply = $this->executeTool($tool, $arguments, $id);
					   break;
				case 'notifications/initialized':
					$reply = array(
						'jsonrpc' => '2.0',
						'id' => $id,
						'method' => 'tools/listChanged',
					);
					break;
				case 'resources/list':
					$reply = array(
						'jsonrpc' => '2.0',
						'id' => $id,
						'result' => array('resources' => array()),
					);
					break;
				case 'prompts/list':
					$reply = array(
						'jsonrpc' => '2.0',
						'id' => $id,
						'result' => array('prompts' => array()),
					);
					break;
				default:
					if (is_null($id) && strpos($method, 'notifications/') === 0) {
						return new WP_REST_Response(null, 204);
					}
					$reply = array(
						'jsonrpc' => '2.0',
						'id' => $id,
						'error' => array('code' => -44001, 'message' => "Method not found: {$method}"),
					);
			}
			$response = new WP_REST_Response($reply, 200);
			$response->set_headers(array('Content-Type' => 'application/json'));
			return $response;
		}
		catch ( Exception $e ) {
			$response = new WP_REST_Response(array(
				'jsonrpc' => '2.0',
				'id' => $id,
				'error' => array('code' => -44000, 'message' => 'Internal error', 'data' => $e->getMessage())
			), 200);
			$response->set_headers(array('Content-Type' => 'application/json'));
			return $response;
		}
	}

	public function handleMessage( $request ) {
		   $sess = sanitize_text_field($request->get_param('session_id'));
		   $body = $request->get_body();
		   $hdr = $request->get_header('Authorization') ? 'present' : 'none';
		   $qp = $request->get_param('token') ? 'present' : 'none';
		   $remote = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'n/a';
		   stifli_flex_mcp_log(sprintf('handleMessage: session=%s remote=%s header=%s query=%s body_len=%d', $sess, $remote, $hdr, $qp, strlen($body)));
		   stifli_flex_mcp_log('handleMessage: RAW BODY: ' . $body);
		   $data = json_decode($body, true);
		   $decodedForLog = wp_json_encode($data);
		   if (false === $decodedForLog) {
			   $decodedForLog = '[unserializable]';
		   }
		   stifli_flex_mcp_log('handleMessage: JSON decoded: ' . $decodedForLog);
		   $id = isset($data['id']) ? $data['id'] : null;
		   $method = StifliFlexMcpUtils::getArrayValue($data, 'method', null);
		if ('initialized' === $method) {
			return new WP_REST_Response(null, 204);
		}
		if ('SFLMCP/kill' === $method) {
			$this->storeMessage($sess, array('jsonrpc' => '2.0', 'method' => 'SFLMCP/kill'));
			usleep( 100000 );
			return new WP_REST_Response(null, 204);
		}
		if (is_null($id) && !is_null($method)) {
			return new WP_REST_Response(null, 204);
		}
		if (!$method) {
			$this->queueError($sess, $id, -32900, 'Invalid Request: method missing');
			return new WP_REST_Response(null, 204);
		}

		// Pass session_id to ChangeTracker for grouping
		if ( class_exists( 'StifliFlexMcp_ChangeTracker' ) ) {
			if ( ! $sess ) {
				$sess = 'sse-' . wp_generate_uuid4();
			}
			StifliFlexMcp_ChangeTracker::getInstance()->setSessionId( $sess );
		}

		try {
			$reply = null;
			switch ($method) {
				case 'initialize':
					$params = StifliFlexMcpUtils::getArrayValue($data, 'params', array(), 2);
					$requestedVersion = StifliFlexMcpUtils::getArrayValue($params, 'protocolVersion', null);
					$clientInfo = StifliFlexMcpUtils::getArrayValue($params, 'clientInfo', null);

					// Store MCP client name for source tracking (e.g. "Claude", "ChatGPT")
					if ( $clientInfo && class_exists( 'StifliFlexMcp_ChangeTracker' ) ) {
						$client_name = is_array( $clientInfo ) && ! empty( $clientInfo['name'] ) ? $clientInfo['name'] : '';
						if ( $client_name ) {
							StifliFlexMcp_ChangeTracker::setSourceContext( 'mcp', $client_name );
						}
					}

					$reply = array(
						'jsonrpc' => '2.0',
						'id' => $id,
						'result' => array(
							'protocolVersion' => $this->protocolVersion,
							'serverInfo' => (object) array(
								'name' => get_bloginfo( 'name' ) . ' StifliFlexMcp',
								'version' => $this->serverVersion,
							),
							'capabilities' => array(
								'tools' => array('listChanged' => true),
								'prompts' => array('subscribe' => false, 'listChanged' => false),
								'resources' => array('subscribe' => false, 'listChanged' => false),
							),
						),
					);
					break;
				case 'tools/list':
					$tools = $this->getToolsList();
					$reply = array(
						'jsonrpc' => '2.0',
						'id' => $id,
						'result' => array('tools' => $tools),
					);
					break;
				case 'resources/list':
					$reply = array(
						'jsonrpc' => '2.0',
						'id' => $id,
						'result' => array('resources' => $this->getResourcesList()),
					);
					break;
				case 'prompts/list':
					$reply = array(
						'jsonrpc' => '2.0',
						'id' => $id,
						'result' => array('prompts' => $this->getPromptsList()),
					);
					break;
				case 'tools/call':
					   $params = StifliFlexMcpUtils::getArrayValue($data, 'params', array(), 2);
					   // Compatibilidad: acepta tanto 'name'/'arguments' como 'tool'/'args'
					   $tool = null;
					   $arguments = array();
					   if (isset($params['name'])) {
						   $tool = $params['name'];
						   $arguments = isset($params['arguments']) ? $params['arguments'] : array();
					   } elseif (isset($params['tool'])) {
						   $tool = $params['tool'];
						   $arguments = isset($params['args']) ? $params['args'] : array();
						}
						   $toolLog = wp_json_encode($tool);
						   if (false === $toolLog) {
							   $toolLog = is_scalar($tool) ? (string) $tool : '[unserializable]';
						   }
						   $argsLog = wp_json_encode($arguments);
						   if (false === $argsLog) {
							   $argsLog = '[unserializable]';
						   }
						   stifli_flex_mcp_log(sprintf('tools/call: tool=%s arguments=%s', $toolLog, $argsLog));
					   $reply = $this->executeTool($tool, $arguments, $id);
					   break;
				default:
					$reply = $this->rpcError($id, -45601, "Method not found: {$method}");
			}
			if ($reply) {
				// Devolver la respuesta JSON-RPC directamente
				return new WP_REST_Response($reply, 200);
			}
		}
		catch ( Exception $e ) {
			$error = $this->rpcError($id, -45603, 'Internal error', $e->getMessage() );
			return new WP_REST_Response($error, 200);
		}
		return new WP_REST_Response(null, 204);
	}

	public function getToolsList() {
		$model = new StifliFlexMcpModel();
		return $model->getToolsList();
	}

	private function getResourcesList() {
		return array();
	}
	private function getPromptsList() {
		return array();
	}

	private function executeTool( $tool, $args, $id ) {
		   try {
			   $toolLog = wp_json_encode($tool);
			   if (false === $toolLog) {
				   $toolLog = is_scalar($tool) ? (string) $tool : '[unserializable]';
			   }
			   $argsLog = wp_json_encode($args);
			   if (false === $argsLog) {
				   $argsLog = '[unserializable]';
			   }
			   $idLog = wp_json_encode($id);
			   if (false === $idLog) {
				   $idLog = is_scalar($id) ? (string) $id : '[unserializable]';
			   }
			   stifli_flex_mcp_log(sprintf('executeTool: tool=%s args=%s id=%s', $toolLog, $argsLog, $idLog));
			   $filtered = StifliFlexMcpDispatcher::applyFilters('sflmcp_callback', null, $tool, $args, $id, $this);
			   if (!is_null($filtered)) {
				   if (is_array($filtered) && isset($filtered['jsonrpc']) && isset($filtered['id'])) {
					   return $filtered;
				   }
				   return array(
					   'jsonrpc' => '2.0',
					   'id' => $id,
					   'result' => $filtered,
				   );
			   }
			   throw new Exception("Unknown tool: {$tool}");
		   }
		   catch ( Exception $e ) {
			   stifli_flex_mcp_log('executeTool: Exception: ' . $e->getMessage());
			   return $this->rpcError( $id, -44003, $e->getMessage() );
		   }
	}

	private function rpcError( $id, int $code, string $msg, $extra = null ): array {
		$err = array('code' => $code, 'message' => $msg);
		if (!is_null($extra)) {
			$err['data'] = $extra;
		}
		return array('jsonrpc' => '2.0', 'id' => $id, 'error' => $err);
	}

	private function queueError( $sess, $id, int $code, string $msg, $extra = null ): void {
		$this->storeMessage($sess, $this->rpcError($id, $code, $msg, $extra));
	}

	/*
	 * Custom queue and profile storage relies on plugin-managed tables.
	 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,PluginCheck.Security.DirectDB.UnescapedDBParameter
	 */
	private function storeMessage( $sess, $payload ) {
		if (empty($sess) || empty($this->queueTable)) {
			return;
		}
		global $wpdb;
		$sessionKey = $this->normalizeSessionId($sess);
		if ('' === $sessionKey) {
			return;
		}
		$messageId = null;
		if (is_array($payload) && array_key_exists('id', $payload)) {
			if (is_null($payload['id'])) {
				$messageId = null;
			} elseif (is_scalar($payload['id'])) {
				$messageId = (string) $payload['id'];
			} else {
				$messageId = wp_json_encode($payload['id']);
			}
		}
		if (!is_null($messageId)) {
			$messageId = substr($messageId, 0, 191);
		}
		$nowTs = current_time('timestamp', true);
		$now = gmdate('Y-m-d H:i:s', $nowTs);
		$expires = gmdate('Y-m-d H:i:s', $nowTs + $this->queueTtl);
		$wpdb->insert(
			$this->queueTable,
			array(
				'session_id' => $sessionKey,
				'message_id' => $messageId,
				'payload' => maybe_serialize($payload),
				'created_at' => $now,
				'expires_at' => $expires,
			),
			array('%s', '%s', '%s', '%s', '%s')
		);
	}

	private function fetchMessages( $sess ) {
		if (empty($sess) || empty($this->queueTable)) {
			return array();
		}
		global $wpdb;
		$sessionKey = $this->normalizeSessionId($sess);
		if ('' === $sessionKey) {
			return array();
		}
		$now = gmdate('Y-m-d H:i:s');
		$queue_tbl = StifliFlexMcpUtils::getPrefixedTable('sflmcp_queue');
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name from sanitized helper.
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT id, payload FROM {$queue_tbl} WHERE session_id = %s AND expires_at >= %s ORDER BY id ASC", $sessionKey, $now ),
			ARRAY_A
		);
		if (empty($rows)) {
			return array();
		}
		$ids = array();
		$msgs = array();
		foreach ($rows as $row) {
			$ids[] = (int) $row['id'];
			$decoded = maybe_unserialize($row['payload']);
			if ($decoded === false && 'b:0;' !== $row['payload']) {
				$decoded = $row['payload'];
			}
			$msgs[] = $decoded;
		}
		if (!empty($ids)) {
			foreach ($ids as $deleteId) {
				$wpdb->delete($this->queueTable, array('id' => $deleteId), array('%d'));
			}
		}
		return $msgs;
	}

	private function normalizeSessionId( $sess ) {
		if (empty($sess)) {
			return '';
		}
		$sess = preg_replace('/[^A-Za-z0-9_\-]/', '', (string) $sess);
		return substr($sess, 0, 191);
	}
	private function getModel() {
		return new StifliFlexMcpModel();
	}
    
	// ============ PROFILE MANAGEMENT AJAX HANDLERS ============
	
	public function ajax_apply_profile() {
		if (!current_user_can('manage_options')) {
			wp_send_json_error(array('message' => 'No permission'), 403);
		}
		check_ajax_referer('sflmcp_profiles');
		
		global $wpdb;
		$profile_id = isset($_POST['profile_id']) ? absint( wp_unslash( $_POST['profile_id'] ) ) : 0;
		
		if ($profile_id <= 0) {
			wp_send_json_error(array('message' => 'Invalid profile ID'));
		}
		
		$profiles_table = StifliFlexMcpUtils::getPrefixedTable('sflmcp_profiles', false);
		$profile_tools_table = StifliFlexMcpUtils::getPrefixedTable('sflmcp_profile_tools', false);
		$tools_table = StifliFlexMcpUtils::getPrefixedTable('sflmcp_tools', false);
		$profiles_table_sql = StifliFlexMcpUtils::wrapTableNameForQuery($profiles_table);
		$profile_tools_table_sql = StifliFlexMcpUtils::wrapTableNameForQuery($profile_tools_table);
		$tools_table_sql = StifliFlexMcpUtils::wrapTableNameForQuery($tools_table);
		$profiles_table_sql = StifliFlexMcpUtils::wrapTableNameForQuery($profiles_table);
		$profile_tools_table_sql = StifliFlexMcpUtils::wrapTableNameForQuery($profile_tools_table);
		$tools_table_sql = StifliFlexMcpUtils::wrapTableNameForQuery($tools_table);
		$profiles_table_sql = StifliFlexMcpUtils::wrapTableNameForQuery($profiles_table);
		$profile_tools_table_sql = StifliFlexMcpUtils::wrapTableNameForQuery($profile_tools_table);
		$tools_table_sql = StifliFlexMcpUtils::wrapTableNameForQuery($tools_table);
		$profiles_table_sql = StifliFlexMcpUtils::getPrefixedTable('sflmcp_profiles');
		$profile_tools_table_sql = StifliFlexMcpUtils::getPrefixedTable('sflmcp_profile_tools');
		$tools_table_sql = StifliFlexMcpUtils::getPrefixedTable('sflmcp_tools');
		
		// Get profile tools
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name from sanitized helper.
		$profile_tools = $wpdb->get_col(
			$wpdb->prepare( "SELECT tool_name FROM {$profile_tools_table_sql} WHERE profile_id = %d", $profile_id )
		);
		
		if ($profile_tools === null) {
			wp_send_json_error(array('message' => 'Profile not found'));
		}
		
		// Disable all tools first
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name from sanitized helper.
		$wpdb->query($wpdb->prepare( "UPDATE {$tools_table_sql} SET enabled = %d", 0 ));
		
		// Enable profile tools
		if (!empty($profile_tools)) {
			$placeholders = implode(',', array_fill(0, count($profile_tools), '%s'));
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name from sanitized helper, placeholders are dynamic.
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$tools_table_sql} SET enabled = 1 WHERE tool_name IN ({$placeholders})",
					...$profile_tools
				)
			);
		}
		
		// Mark profile as active
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name from sanitized helper.
		$wpdb->query($wpdb->prepare( "UPDATE {$profiles_table_sql} SET is_active = %d", 0 ));
		$wpdb->update($profiles_table, array('is_active' => 1), array('id' => $profile_id), array('%d'), array('%d'));
		
		wp_send_json_success(array('message' => count($profile_tools) . ' herramientas habilitadas'));
	}
	
	public function ajax_delete_profile() {
		if (!current_user_can('manage_options')) {
			wp_send_json_error(array('message' => 'No permission'), 403);
		}
		check_ajax_referer('sflmcp_profiles');
		
		global $wpdb;
		$profile_id = isset($_POST['profile_id']) ? absint( wp_unslash( $_POST['profile_id'] ) ) : 0;
		
		$profiles_table = StifliFlexMcpUtils::getPrefixedTable('sflmcp_profiles', false);
		$profiles_table_sql = StifliFlexMcpUtils::wrapTableNameForQuery($profiles_table);
		
		// Check if system profile
		$system_query = sprintf('SELECT is_system FROM %s WHERE id = %%d', $profiles_table_sql);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- query uses sprintf with safe table wrapper.
		$is_system = $wpdb->get_var($wpdb->prepare($system_query, $profile_id));
		
		if ($is_system === null) {
			wp_send_json_error(array('message' => 'Profile not found'));
		}
		
		if (intval($is_system) === 1) {
			wp_send_json_error(array('message' => 'Cannot delete system profiles'));
		}
		
		$wpdb->delete($profiles_table, array('id' => $profile_id), array('%d'));
		
		wp_send_json_success();
	}
	
	public function ajax_duplicate_profile() {
		if (!current_user_can('manage_options')) {
			wp_send_json_error(array('message' => 'No permission'), 403);
		}
		check_ajax_referer('sflmcp_profiles');
		
		global $wpdb;
		$profile_id = isset($_POST['profile_id']) ? absint( wp_unslash( $_POST['profile_id'] ) ) : 0;
		
		$profiles_table = StifliFlexMcpUtils::getPrefixedTable('sflmcp_profiles', false);
		$profile_tools_table = StifliFlexMcpUtils::getPrefixedTable('sflmcp_profile_tools', false);
		$profiles_table_sql = StifliFlexMcpUtils::wrapTableNameForQuery($profiles_table);
		$profile_tools_table_sql = StifliFlexMcpUtils::wrapTableNameForQuery($profile_tools_table);
		
		// Get original profile
		$profile_query = sprintf('SELECT * FROM %s WHERE id = %%d', $profiles_table_sql);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- query uses sprintf with safe table wrapper.
		$original = $wpdb->get_row($wpdb->prepare($profile_query, $profile_id), ARRAY_A);
		
		if (!$original) {
			wp_send_json_error(array('message' => 'Profile not found'));
		}
		
		// Create new profile name
		$new_name = 'Copia de ' . $original['profile_name'];
		$counter = 1;
		$profile_name_check = sprintf('SELECT id FROM %s WHERE profile_name = %%s', $profiles_table_sql);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- query uses sprintf with safe table wrapper.
		while ($wpdb->get_var($wpdb->prepare($profile_name_check, $new_name))) {
			$counter++;
			$new_name = 'Copia de ' . $original['profile_name'] . ' (' . $counter . ')';
		}
		
		$now = current_time('mysql', true);
		
		// Insert new profile
		$wpdb->insert(
			$profiles_table,
			array(
				'profile_name' => $new_name,
				'profile_description' => $original['profile_description'],
				'is_system' => 0, // Duplicates are always custom
				'is_active' => 0,
				'created_at' => $now,
				'updated_at' => $now,
			),
			array('%s', '%s', '%d', '%d', '%s', '%s')
		);
		
		$new_profile_id = $wpdb->insert_id;
		
		// Copy tools
		$tools_query = sprintf('SELECT tool_name FROM %s WHERE profile_id = %%d', $profile_tools_table_sql);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- query uses sprintf with safe table wrapper.
		$tools = $wpdb->get_col($wpdb->prepare($tools_query, $profile_id));
		
		foreach ($tools as $tool_name) {
			$wpdb->insert(
				$profile_tools_table,
				array(
					'profile_id' => $new_profile_id,
					'tool_name' => $tool_name,
					'created_at' => $now,
				),
				array('%d', '%s', '%s')
			);
		}
		
		wp_send_json_success();
	}
	
	public function ajax_export_profile() {
		if (!current_user_can('manage_options')) {
			wp_die('No permission', 403);
		}
		check_ajax_referer('sflmcp_profiles');
		
		global $wpdb;
		$profile_id = intval($_GET['profile_id'] ?? 0);
		
		$profiles_table = StifliFlexMcpUtils::getPrefixedTable('sflmcp_profiles', false);
		$profile_tools_table = StifliFlexMcpUtils::getPrefixedTable('sflmcp_profile_tools', false);
		$tools_table = StifliFlexMcpUtils::getPrefixedTable('sflmcp_tools', false);
		$profiles_table_sql = StifliFlexMcpUtils::wrapTableNameForQuery($profiles_table);
		$profile_tools_table_sql = StifliFlexMcpUtils::wrapTableNameForQuery($profile_tools_table);
		$tools_table_sql = StifliFlexMcpUtils::wrapTableNameForQuery($tools_table);
		
		$profile_query = sprintf('SELECT * FROM %s WHERE id = %%d', $profiles_table_sql);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- query uses sprintf with safe table wrapper.
		$profile = $wpdb->get_row($wpdb->prepare($profile_query, $profile_id), ARRAY_A);
		
		if (!$profile) {
			wp_die('Profile not found', 404);
		}
		
		$tools_query = sprintf('SELECT tool_name FROM %s WHERE profile_id = %%d ORDER BY tool_name', $profile_tools_table_sql);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- query uses sprintf with safe table wrapper.
		$tools = $wpdb->get_col($wpdb->prepare($tools_query, $profile_id));
		
		// Get categories
		$categories = array();
		if (!empty($tools)) {
			$placeholders = implode(',', array_fill(0, count($tools), '%s'));
			$categories_query = 'SELECT DISTINCT category FROM ' . $tools_table_sql . ' WHERE tool_name IN (' . $placeholders . ') ORDER BY category';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- query uses dynamic placeholders with prepare.
			$categories = $wpdb->get_col($wpdb->prepare($categories_query, ...$tools));
		}
		
		$export = array(
			'format_version' => '1.0',
			'export_date' => gmdate('Y-m-d\TH:i:s\Z'),
			'plugin_version' => '0.1.0',
			'profile' => array(
				'name' => $profile['profile_name'],
				'description' => $profile['profile_description'],
				'tools' => $tools,
				'tools_count' => count($tools),
				'categories_included' => $categories,
			),
		);
		
		$filename = sanitize_file_name($profile['profile_name']) . '-profile.json';
		
		header('Content-Type: application/json');
		header('Content-Disposition: attachment; filename="' . $filename . '"');
		echo json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
		exit;
	}
	
	public function ajax_import_profile() {
		if (!current_user_can('manage_options')) {
			wp_send_json_error(array('message' => 'No permission'), 403);
		}
		check_ajax_referer('sflmcp_profiles');
		
		global $wpdb;
		$profiles_table = StifliFlexMcpUtils::getPrefixedTable('sflmcp_profiles', false);
		$profile_tools_table = StifliFlexMcpUtils::getPrefixedTable('sflmcp_profile_tools', false);
		$tools_table = StifliFlexMcpUtils::getPrefixedTable('sflmcp_tools', false);
		$profiles_table_sql = StifliFlexMcpUtils::wrapTableNameForQuery($profiles_table);
		$profile_tools_table_sql = StifliFlexMcpUtils::wrapTableNameForQuery($profile_tools_table);
		$tools_table_sql = StifliFlexMcpUtils::wrapTableNameForQuery($tools_table);

		$json_data = isset($_POST['profile_json']) ? StifliFlexMcpUtils::sanitizeJsonString( sanitize_text_field( wp_unslash( $_POST['profile_json'] ) ) ) : '';
		if (empty($json_data)) {
			wp_send_json_error(array('message' => 'No JSON data provided'));
		}
		
		$data = json_decode($json_data, true);
		if (!$data || !isset($data['profile'])) {
			wp_send_json_error(array('message' => 'Invalid JSON format'));
		}
		
		$profile = $data['profile'];
		$name = sanitize_text_field($profile['name'] ?? 'Importado');
		$description = sanitize_textarea_field($profile['description'] ?? '');
		$tools_list = isset($profile['tools']) && is_array($profile['tools']) ? $profile['tools'] : array();
		$tools = array();
		foreach ($tools_list as $tool_name) {
			if (!is_string($tool_name)) {
				continue;
			}
			$clean_tool = sanitize_key($tool_name);
			if ('' !== $clean_tool) {
				$tools[] = $clean_tool;
			}
		}
		
		// Check if name exists
		$counter = 1;
		$original_name = $name;
		$profile_name_check = sprintf('SELECT id FROM %s WHERE profile_name = %%s', $profiles_table_sql);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- query uses sprintf with safe table wrapper.
		while ($wpdb->get_var($wpdb->prepare($profile_name_check, $name))) {
			$counter++;
			$name = $original_name . ' (' . $counter . ')';
		}
		
		// Validate tools exist
		$existing_tools_query = sprintf('SELECT tool_name FROM %s WHERE 1 = %%d', $tools_table_sql);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- query uses sprintf with safe table wrapper.
		$existing_tools = $wpdb->get_col($wpdb->prepare($existing_tools_query, 1));
		$valid_tools = array_intersect($tools, $existing_tools);
		
		if (empty($valid_tools)) {
			wp_send_json_error(array('message' => 'No valid tools found in profile'));
		}
		
		$now = current_time('mysql', true);
		
		// Insert profile
		$wpdb->insert(
			$profiles_table,
			array(
				'profile_name' => $name,
				'profile_description' => $description,
				'is_system' => 0,
				'is_active' => 0,
				'created_at' => $now,
				'updated_at' => $now,
			),
			array('%s', '%s', '%d', '%d', '%s', '%s')
		);
		
		$profile_id = $wpdb->insert_id;
		
		// Insert tools
		foreach ($valid_tools as $tool_name) {
			$wpdb->insert(
				$profile_tools_table,
				array(
					'profile_id' => $profile_id,
					'tool_name' => $tool_name,
					'created_at' => $now,
				),
				array('%d', '%s', '%s')
			);
		}
		
		$ignored_count = count($tools) - count($valid_tools);
		$message = 'Perfil importado: ' . count($valid_tools) . ' herramientas';
		if ($ignored_count > 0) {
			$message .= ' (' . $ignored_count . ' herramientas no encontradas fueron ignoradas)';
		}
		
		wp_send_json_success(array('message' => $message));
	}
	
	public function ajax_restore_system_profiles() {
		if (!current_user_can('manage_options')) {
			wp_send_json_error(array('message' => 'No permission'), 403);
		}
		check_ajax_referer('sflmcp_profiles');
		
		global $wpdb;
		$profiles_table = StifliFlexMcpUtils::getPrefixedTable('sflmcp_profiles', false);
		$profile_tools_table = StifliFlexMcpUtils::getPrefixedTable('sflmcp_profile_tools', false);
		$profiles_table_sql = StifliFlexMcpUtils::wrapTableNameForQuery($profiles_table);
		$profile_tools_table_sql = StifliFlexMcpUtils::wrapTableNameForQuery($profile_tools_table);
		
		// Delete existing system profiles
		$system_ids_query = sprintf('SELECT id FROM %s WHERE is_system = %%d', $profiles_table_sql);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- query uses sprintf with safe table wrapper.
		$system_ids = $wpdb->get_col($wpdb->prepare($system_ids_query, 1));
		if (!empty($system_ids)) {
			$placeholders = implode(',', array_fill(0, count($system_ids), '%d'));
			$delete_relations_query = 'DELETE FROM ' . $profile_tools_table_sql . ' WHERE profile_id IN (' . $placeholders . ')';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- query uses dynamic placeholders with prepare.
			$wpdb->query($wpdb->prepare($delete_relations_query, ...$system_ids));
			$delete_profiles_query = sprintf('DELETE FROM %s WHERE is_system = %%d', $profiles_table_sql);
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- query uses sprintf with safe table wrapper.
			$wpdb->query($wpdb->prepare($delete_profiles_query, 1));
		}
		
		// Re-seed system profiles
		stifli_flex_mcp_seed_system_profiles();
		
		wp_send_json_success();
	}
	
	public function ajax_create_profile() {
		if (!current_user_can('manage_options')) {
			wp_send_json_error(array('message' => 'No permission'), 403);
		}
		check_ajax_referer('sflmcp_profiles');
		
		// TODO: Implement create/edit modal in next phase
		wp_send_json_error(array('message' => 'Not implemented yet'));
	}
	
	public function ajax_update_profile() {
		if (!current_user_can('manage_options')) {
			wp_send_json_error(array('message' => 'No permission'), 403);
		}
		check_ajax_referer('sflmcp_profiles');
		
		// TODO: Implement create/edit modal in next phase
		wp_send_json_error(array('message' => 'Not implemented yet'));
	}

	/**
	 * Register top-level admin menu.
	 * Only creates the parent — submenus are added by priority:
	 *   Priority 10: AI Chat Agent (client class, same slug as parent → first item)
	 *   Priority 20: MCP Server (this class → second item)
	 */
	public function registerAdmin() {
		add_menu_page(
			__('StifLi Flex MCP', 'stifli-flex-mcp'),
			__('StifLi Flex MCP', 'stifli-flex-mcp'),
			'manage_options',
			'stifli-flex-mcp',
			'__return_null',
			'dashicons-rest-api',
			30
		);
	}

	/**
	 * Register MCP Server submenu at priority 20 (after AI Chat Agent at priority 10).
	 */
	public function registerMcpServerSubmenu() {
		add_submenu_page(
			'stifli-flex-mcp',
			__('MCP Server', 'stifli-flex-mcp'),
			__('MCP Server', 'stifli-flex-mcp'),
			'manage_options',
			'sflmcp-server',
			array($this, 'adminPage')
		);
	}

	/**
	 * Register Multimedia submenu at priority 25 (after MCP Server at 15).
	 */
	public function registerMultimediaSubmenu() {
		add_submenu_page(
			'stifli-flex-mcp',
			__('Multimedia', 'stifli-flex-mcp'),
			__('Multimedia', 'stifli-flex-mcp'),
			'manage_options',
			'sflmcp-multimedia',
			array($this, 'multimediaPage')
		);
	}

	/**
	 * Register settings used by the plugin
	 */
	public function registerSettings() {
		// No custom settings needed - uses WordPress Application Passwords
	}

	/**
	 * Enqueue admin scripts and styles
	 */
	public function enqueueAdminScripts($hook) {
		// Load Multimedia page assets on its own page
		if ($hook === 'stifli-flex-mcp_page_sflmcp-multimedia') {
			$this->enqueueMultimediaAssets();
			return;
		}

		// Only load on our MCP Server page
		if ($hook !== 'stifli-flex-mcp_page_sflmcp-server') {
			return;
		}

		// Get active tab early for conditional loading
		$active_tab = isset($_GET['tab']) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : 'settings'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		// Enqueue Settings tab JavaScript
		wp_enqueue_script(
			'sflmcp-admin-settings',
			plugin_dir_url(__FILE__) . 'assets/admin-settings.js',
			array(),
			'1.0.3',
			true
		);

		// Localize script with data
		wp_localize_script('sflmcp-admin-settings', 'sflmcpSettings', array(
			'ajaxUrl' => admin_url('admin-ajax.php'),
			'nonce' => wp_create_nonce('SFLMCP-admin'),
			'i18n' => array(
				'urlCopied' => __('URL copied', 'stifli-flex-mcp'),
				'headerCopied' => __('Header copied', 'stifli-flex-mcp'),
			),
		));

		// Enqueue Profiles tab JavaScript
		wp_enqueue_script(
			'sflmcp-admin-profiles',
			plugin_dir_url(__FILE__) . 'assets/admin-profiles.js',
			array(),
			'1.0.1',
			true
		);

		// Localize script with data
		wp_localize_script('sflmcp-admin-profiles', 'sflmcpProfiles', array(
			'nonce' => wp_create_nonce('sflmcp_profiles'),
			'i18n' => array(
				'includedTools' => __('Included tools:', 'stifli-flex-mcp'),
			),
		));

		// Enqueue main admin styles (tools, help page)
		wp_enqueue_style(
			'sflmcp-admin-styles',
			plugin_dir_url(__FILE__) . 'assets/admin-styles.css',
			array(),
			'1.0.5'
		);

		// Enqueue Custom Tools Assets
		if ($active_tab === 'custom') {
			wp_enqueue_style(
				'sflmcp-admin-custom-tools',
				plugin_dir_url(__FILE__) . 'assets/admin-custom-tools.css',
				array(),
				'1.0.5'
			);
			wp_enqueue_script(
				'sflmcp-admin-custom-tools',
				plugin_dir_url(__FILE__) . 'assets/admin-custom-tools.js',
				array('jquery'),
				'1.0.5',
				true
			);
			wp_localize_script('sflmcp-admin-custom-tools', 'sflmcpCustom', array(
				'ajaxUrl' => admin_url('admin-ajax.php'),
				'nonce' => wp_create_nonce('sflmcp_custom_tools'),
				'i18n' => array(
					'confirmDelete' => __('Are you sure you want to delete this tool?', 'stifli-flex-mcp'),
					'errorSaving' => __('Error saving tool', 'stifli-flex-mcp'),
					'saved' => __('Tool saved successfully', 'stifli-flex-mcp'),
					'testing' => __('Testing...', 'stifli-flex-mcp'),
					'success' => __('Success', 'stifli-flex-mcp'),
					'failed' => __('Failed', 'stifli-flex-mcp'),
				),
			));
		}

		// Enqueue Abilities tab assets (WordPress 6.9+)
		if ($active_tab === 'abilities' && stifli_flex_mcp_abilities_available()) {
			wp_enqueue_style(
				'sflmcp-admin-abilities',
				plugin_dir_url(__FILE__) . 'assets/admin-abilities.css',
				array(),
				'1.0.0'
			);
			wp_enqueue_script(
				'sflmcp-admin-abilities',
				plugin_dir_url(__FILE__) . 'assets/admin-abilities.js',
				array('jquery'),
				'1.0.0',
				true
			);
			wp_localize_script('sflmcp-admin-abilities', 'sflmcpAbilities', array(
				'ajaxUrl' => admin_url('admin-ajax.php'),
				'nonce' => wp_create_nonce('sflmcp_abilities'),
				'i18n' => array(
					'discovering' => __('Discovering abilities...', 'stifli-flex-mcp'),
					'noAbilities' => __('No abilities found. Install plugins that register WordPress Abilities.', 'stifli-flex-mcp'),
					'confirmDelete' => __('Are you sure you want to remove this ability?', 'stifli-flex-mcp'),
					'imported' => __('Ability imported successfully', 'stifli-flex-mcp'),
					'deleted' => __('Ability removed', 'stifli-flex-mcp'),
					'error' => __('An error occurred', 'stifli-flex-mcp'),
					'alreadyImported' => __('Already imported', 'stifli-flex-mcp'),
					'import' => __('Import', 'stifli-flex-mcp'),
				),
			));
		}

		// Enqueue OAuth assets on Settings tab
		if ($active_tab === 'settings') {
			wp_enqueue_style(
				'sflmcp-admin-oauth',
				plugin_dir_url(__FILE__) . 'assets/admin-oauth.css',
				array(),
				'1.0.0'
			);
			wp_enqueue_script(
				'sflmcp-admin-oauth',
				plugin_dir_url(__FILE__) . 'assets/admin-oauth.js',
				array('jquery'),
				'1.0.0',
				true
			);
			wp_localize_script('sflmcp-admin-oauth', 'sflmcpOAuth', array(
				'ajaxUrl' => admin_url('admin-ajax.php'),
				'nonce' => wp_create_nonce('sflmcp_oauth'),
				'i18n' => array(
					'confirmDeleteClient' => __('Are you sure you want to delete this OAuth client and revoke all its tokens?', 'stifli-flex-mcp'),
					'confirmRevokeToken' => __('Revoke this token? The client will need to re-authorize.', 'stifli-flex-mcp'),
					'clientDeleted' => __('OAuth client deleted', 'stifli-flex-mcp'),
					'tokenRevoked' => __('Token revoked', 'stifli-flex-mcp'),
					'settingsSaved' => __('Settings saved', 'stifli-flex-mcp'),
					'error' => __('An error occurred', 'stifli-flex-mcp'),
				),
			));
		}

		// Enqueue Tools tab JavaScript (WordPress and WooCommerce tools)
		if ($active_tab === 'tools' || $active_tab === 'wc_tools') {
			wp_enqueue_script(
				'sflmcp-admin-tools',
				plugin_dir_url(__FILE__) . 'assets/admin-tools.js',
				array('jquery'),
				'1.0.5',
				true
			);
			wp_localize_script('sflmcp-admin-tools', 'sflmcpTools', array(
				'ajaxUrl' => admin_url('admin-ajax.php'),
				'nonce' => wp_create_nonce('sflmcp_tools'),
				'i18n' => array(
					'enabled' => __('Enabled', 'stifli-flex-mcp'),
					'disabled' => __('Disabled', 'stifli-flex-mcp'),
					'error' => __('Error updating tool', 'stifli-flex-mcp'),
				),
			));
		}
	}

	/**
	 * Render the admin settings page
	 */
	public function adminPage() {
		if (!current_user_can('manage_options')) {
			wp_die( esc_html__('You do not have permission to view this page.','stifli-flex-mcp') );
		}
		
		// Get active tab
		$active_tab = isset($_GET['tab']) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : 'settings'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- tab selection is a read-only navigation parameter
		
		?>
		<div class="wrap">
			<h1><?php echo esc_html__('MCP Server', 'stifli-flex-mcp'); ?></h1>
			
			<h2 class="nav-tab-wrapper">
				<a href="?page=sflmcp-server&tab=settings" class="nav-tab <?php echo $active_tab === 'settings' ? 'nav-tab-active' : ''; ?>">
					<?php echo esc_html__('Settings', 'stifli-flex-mcp'); ?>
				</a>
				<a href="?page=sflmcp-server&tab=profiles" class="nav-tab <?php echo $active_tab === 'profiles' ? 'nav-tab-active' : ''; ?>">
					<?php echo esc_html__('Profiles', 'stifli-flex-mcp'); ?>
				</a>
				<a href="?page=sflmcp-server&tab=tools" class="nav-tab <?php echo $active_tab === 'tools' ? 'nav-tab-active' : ''; ?>">
					<?php echo esc_html__('WordPress Tools', 'stifli-flex-mcp'); ?>
				</a>
				<a href="?page=sflmcp-server&tab=wc_tools" class="nav-tab <?php echo $active_tab === 'wc_tools' ? 'nav-tab-active' : ''; ?>">
					<?php echo esc_html__('WooCommerce Tools', 'stifli-flex-mcp'); ?>
				</a>
				<?php if ( stifli_flex_mcp_abilities_available() ) : ?>
				<a href="?page=sflmcp-server&tab=abilities" class="nav-tab <?php echo $active_tab === 'abilities' ? 'nav-tab-active' : ''; ?>">
					<?php echo esc_html__('Abilities', 'stifli-flex-mcp'); ?>
				</a>
				<?php endif; ?>
				<a href="?page=sflmcp-server&tab=custom" class="nav-tab <?php echo $active_tab === 'custom' ? 'nav-tab-active' : ''; ?>">
					<?php echo esc_html__('Custom Tools', 'stifli-flex-mcp'); ?>
				</a>
				<a href="?page=sflmcp-server&tab=help" class="nav-tab <?php echo $active_tab === 'help' ? 'nav-tab-active' : ''; ?>">
					<?php echo esc_html__('📚 Help', 'stifli-flex-mcp'); ?>
				</a>
			</h2>
			
			<?php
			if ($active_tab === 'settings') {
				$this->renderSettingsTab();
			} elseif ($active_tab === 'profiles') {
				$this->renderProfilesTab();
			} elseif ($active_tab === 'tools') {
				$this->renderToolsTab();
			} elseif ($active_tab === 'wc_tools') {
				$this->renderWCToolsTab();
			} elseif ($active_tab === 'abilities' && stifli_flex_mcp_abilities_available()) {
				$this->renderAbilitiesTab();
			} elseif ($active_tab === 'custom') {
				$this->renderCustomToolsTab();
			} elseif ($active_tab === 'help') {
				$this->renderHelpTab();
			}
			?>
		</div>
		<?php
	}
	
	private function renderSettingsTab() {
		$sse_url = rest_url( $this->namespace . '/sse' );

		if ( ! class_exists( 'StifliFlexMcp_OAuth_Storage' ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'OAuth module is not loaded.', 'stifli-flex-mcp' ) . '</p></div>';
			return;
		}

		$storage       = StifliFlexMcp_OAuth_Storage::get_instance();
		$clients       = $storage->get_all_clients();

		// Preload token data.
		global $wpdb;
		$tokens_table = $wpdb->prefix . 'sflmcp_oauth_tokens';
		$now          = gmdate( 'Y-m-d H:i:s' );

		$token_counts = array();
		$token_data   = array();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$counts_raw = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT client_id, COUNT(*) as cnt FROM {$tokens_table} WHERE revoked = 0 AND access_expires_at > %s GROUP BY client_id",
				$now
			)
		);
		foreach ( $counts_raw as $row ) {
			$token_counts[ $row->client_id ] = (int) $row->cnt;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$tokens_raw = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT t.id, t.client_id, t.user_id, t.scope, t.access_expires_at, t.created_at, u.display_name as user_name
				FROM {$tokens_table} t
				LEFT JOIN {$wpdb->users} u ON t.user_id = u.ID
				WHERE t.revoked = 0 AND t.access_expires_at > %s
				ORDER BY t.created_at DESC",
				$now
			)
		);
		foreach ( $tokens_raw as $tok ) {
			$token_data[ $tok->client_id ][] = $tok;
		}
		?>

		<div class="sflmcp-settings-connect">
			<h2><?php esc_html_e( 'Connect your AI assistant', 'stifli-flex-mcp' ); ?></h2>
			<p><?php esc_html_e( 'Copy this URL and paste it in your AI client (Claude, ChatGPT, or any MCP-compatible app):', 'stifli-flex-mcp' ); ?></p>

			<div class="sflmcp-settings-url-box">
				<code id="sflmcp_sse_url" class="sflmcp-settings-endpoint-code"><?php echo esc_html( $sse_url ); ?></code>
				<button type="button" class="button button-primary sflmcp-copy-btn" data-copy-target="#sflmcp_sse_url" data-copy-notice="<?php echo esc_attr__( 'URL copied!', 'stifli-flex-mcp' ); ?>">
					<?php esc_html_e( 'Copy', 'stifli-flex-mcp' ); ?>
				</button>
			</div>

			<div class="sflmcp-settings-steps">
				<div class="sflmcp-settings-step">
					<span class="sflmcp-step-number">1</span>
					<div>
						<strong><?php esc_html_e( 'Copy the URL above', 'stifli-flex-mcp' ); ?></strong>
					</div>
				</div>
				<div class="sflmcp-settings-step">
					<span class="sflmcp-step-number">2</span>
					<div>
						<strong><?php esc_html_e( 'Paste it in your AI client', 'stifli-flex-mcp' ); ?></strong>
						<p class="description" style="margin-bottom:4px;"><strong>Claude Desktop:</strong> <?php esc_html_e( 'Customize → Connectors → Add custom connector.', 'stifli-flex-mcp' ); ?></p>
						<p class="description"><strong>ChatGPT:</strong> <?php esc_html_e( 'Settings → Apps & Connectors → Advanced settings → Enable Developer mode → Create app → Paste the URL → Choose OAuth.', 'stifli-flex-mcp' ); ?></p>
					</div>
				</div>
				<div class="sflmcp-settings-step">
					<span class="sflmcp-step-number">3</span>
					<div>
						<strong><?php esc_html_e( 'Authorize when prompted', 'stifli-flex-mcp' ); ?></strong>
						<p class="description"><?php esc_html_e( 'A browser window will open. Log in to WordPress and click "Authorize". You only need to do this once.', 'stifli-flex-mcp' ); ?></p>
					</div>
				</div>
			</div>

			<?php if ( ! empty( $clients ) ) : ?>
			<div class="sflmcp-settings-status">
				<span class="dashicons dashicons-yes-alt" style="color:#00a32a;"></span>
				<?php
				$client_count = count( $clients );
				$total_tokens = array_sum( $token_counts );
				printf(
					/* translators: %1$d: number of connected clients, %2$d: number of active sessions */
					esc_html( _n(
						'%1$d client connected, %2$d active session.',
						'%1$d clients connected, %2$d active sessions.',
						$client_count,
						'stifli-flex-mcp'
					) ),
					intval( $client_count ),
					intval( $total_tokens )
				);
				?>
			</div>
			<?php endif; ?>
		</div>

		<!-- View More Details -->
		<div class="sflmcp-settings-details-toggle">
			<button type="button" class="button" id="sflmcp-toggle-details">
				<span class="dashicons dashicons-arrow-down-alt2" style="vertical-align:middle;"></span>
				<?php esc_html_e( 'View More Details', 'stifli-flex-mcp' ); ?>
			</button>
		</div>

		<div id="sflmcp-settings-details" style="display:none;">

			<!-- Connected Clients -->
			<div class="sflmcp-settings-section">
				<h3><?php esc_html_e( 'Connected Clients', 'stifli-flex-mcp' ); ?></h3>
				<?php if ( empty( $clients ) ) : ?>
					<p class="description"><?php esc_html_e( 'No clients connected yet. Follow the steps above to connect your first AI assistant.', 'stifli-flex-mcp' ); ?></p>
				<?php else : ?>
					<table class="wp-list-table widefat fixed striped sflmcp-oauth-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Client Name', 'stifli-flex-mcp' ); ?></th>
								<th><?php esc_html_e( 'Client ID', 'stifli-flex-mcp' ); ?></th>
								<th><?php esc_html_e( 'Active Tokens', 'stifli-flex-mcp' ); ?></th>
								<th><?php esc_html_e( 'Registered', 'stifli-flex-mcp' ); ?></th>
								<th><?php esc_html_e( 'Actions', 'stifli-flex-mcp' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $clients as $client ) :
								$cid         = esc_attr( $client->client_id );
								$count       = isset( $token_counts[ $client->client_id ] ) ? $token_counts[ $client->client_id ] : 0;
								$tokens_list = isset( $token_data[ $client->client_id ] ) ? $token_data[ $client->client_id ] : array();
							?>
								<tr>
									<td><strong><?php echo esc_html( $client->client_name ); ?></strong></td>
									<td><code style="font-size:11px;"><?php echo esc_html( $client->client_id ); ?></code></td>
									<td>
										<?php if ( $count > 0 ) : ?>
											<a href="#" class="sflmcp-oauth-toggle-tokens" data-client-id="<?php echo esc_attr( $cid ); ?>">
												<span class="sflmcp-oauth-token-count has-tokens" data-client-count="<?php echo esc_attr( $cid ); ?>"><?php echo intval( $count ); ?></span>
											</a>
										<?php else : ?>
											<span data-client-count="<?php echo esc_attr( $cid ); ?>">0</span>
										<?php endif; ?>
									</td>
									<td>
										<?php
										$registered = strtotime( $client->created_at );
										echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $registered + ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ) ) );
										?>
									</td>
									<td>
										<button type="button" class="button button-small button-link-delete sflmcp-oauth-delete-client" data-client-id="<?php echo esc_attr( $cid ); ?>">
											<?php esc_html_e( 'Delete', 'stifli-flex-mcp' ); ?>
										</button>
									</td>
								</tr>
								<?php if ( ! empty( $tokens_list ) ) : ?>
								<tr class="sflmcp-oauth-tokens-row">
									<td colspan="5">
										<div id="sflmcp-tokens-<?php echo esc_attr( $cid ); ?>" class="sflmcp-oauth-tokens-detail">
											<table class="sflmcp-oauth-tokens-list">
												<thead>
													<tr>
														<th><?php esc_html_e( 'User', 'stifli-flex-mcp' ); ?></th>
														<th><?php esc_html_e( 'Scope', 'stifli-flex-mcp' ); ?></th>
														<th><?php esc_html_e( 'Issued', 'stifli-flex-mcp' ); ?></th>
														<th><?php esc_html_e( 'Expires', 'stifli-flex-mcp' ); ?></th>
														<th></th>
													</tr>
												</thead>
												<tbody>
													<?php foreach ( $tokens_list as $tok ) :
														$issued  = strtotime( $tok->created_at );
														$expires = strtotime( $tok->access_expires_at );
														$offset  = get_option( 'gmt_offset' ) * HOUR_IN_SECONDS;
													?>
													<tr>
														<td><?php echo esc_html( $tok->user_name ?: '#' . $tok->user_id ); ?></td>
														<td><?php echo esc_html( $tok->scope ); ?></td>
														<td><?php echo esc_html( date_i18n( 'M j, H:i', $issued + $offset ) ); ?></td>
														<td><?php echo esc_html( date_i18n( 'M j, H:i', $expires + $offset ) ); ?></td>
														<td>
															<button type="button" class="button button-small button-link-delete sflmcp-oauth-revoke-token"
																data-token-id="<?php echo intval( $tok->id ); ?>"
																data-client-id="<?php echo esc_attr( $cid ); ?>">
																<?php esc_html_e( 'Revoke', 'stifli-flex-mcp' ); ?>
															</button>
														</td>
													</tr>
													<?php endforeach; ?>
												</tbody>
											</table>
										</div>
									</td>
								</tr>
								<?php endif; ?>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>

			<!-- Troubleshooting -->
			<div class="sflmcp-settings-section">
				<h3><?php esc_html_e( 'Troubleshooting', 'stifli-flex-mcp' ); ?></h3>
				<table class="form-table">
					<tr>
						<th><?php esc_html_e( 'Connection fails', 'stifli-flex-mcp' ); ?></th>
						<td><?php esc_html_e( 'Make sure your site uses HTTPS. Check that no security plugin is blocking the REST API.', 'stifli-flex-mcp' ); ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Authorization keeps asking', 'stifli-flex-mcp' ); ?></th>
						<td><?php esc_html_e( 'You need to be logged in to WordPress as an administrator when the authorization page opens. Tokens last 24 hours and refresh automatically for up to 90 days.', 'stifli-flex-mcp' ); ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Client not appearing', 'stifli-flex-mcp' ); ?></th>
						<td><?php esc_html_e( 'The client registers automatically when it first connects. If nothing shows up, try disconnecting and reconnecting from your AI assistant.', 'stifli-flex-mcp' ); ?></td>
					</tr>
				</table>
			</div>

			<!-- Alternative: Application Passwords -->
			<div class="sflmcp-settings-section">
				<h3><?php esc_html_e( 'Alternative: Application Passwords', 'stifli-flex-mcp' ); ?></h3>
				<p class="description">
					<?php echo wp_kses(
						sprintf(
							/* translators: %s: link to profile page */
							__( 'For advanced setups or clients that don\'t support OAuth, you can still use WordPress Application Passwords. Go to %s to create one, then use HTTP Basic Auth with your username and the generated password.', 'stifli-flex-mcp' ),
							'<a href="' . esc_url( get_edit_profile_url( get_current_user_id() ) . '#application-passwords-section' ) . '" target="_blank">'
							. esc_html__( 'your profile', 'stifli-flex-mcp' ) . '</a>'
						),
						array( 'a' => array( 'href' => array(), 'target' => array() ) )
					); ?>
				</p>
			</div>

		</div>

		<?php
	}
	
	private function renderToolsTab() {
		global $wpdb;
		$table = StifliFlexMcpUtils::getPrefixedTable('sflmcp_tools', false);
		$profiles_table = StifliFlexMcpUtils::getPrefixedTable('sflmcp_profiles', false);
		$table_sql = StifliFlexMcpUtils::wrapTableNameForQuery($table);
		$profiles_table_sql = StifliFlexMcpUtils::wrapTableNameForQuery($profiles_table);
		
		// Check if there's an active profile
		$active_profile_query = sprintf('SELECT * FROM %s WHERE is_active = %%d', $profiles_table_sql);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- query uses sprintf with safe table wrapper.
		$active_profile = $wpdb->get_row($wpdb->prepare($active_profile_query, 1), ARRAY_A);
		
		// Handle re-seeding
		$reseed_nonce = isset($_POST['sflmcp_reseed_nonce']) ? sanitize_text_field( wp_unslash( $_POST['sflmcp_reseed_nonce'] ) ) : '';
		if (!empty($reseed_nonce) && wp_verify_nonce($reseed_nonce, 'sflmcp_reseed_tools')) {
			$truncate_query = sprintf('TRUNCATE TABLE %s', $table_sql);
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared -- admin action intentionally resets plugin-managed table.
			$wpdb->query($truncate_query);
			stifli_flex_mcp_seed_initial_tools();
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Tools reset and reseeded successfully.', 'stifli-flex-mcp') . '</p></div>';
		}
		
		// Get all tools grouped by category (ONLY WordPress, excluding WooCommerce)
		$tools_query = sprintf('SELECT * FROM %s WHERE category NOT LIKE %%s ORDER BY category, tool_name', $table_sql);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- query uses sprintf with safe table wrapper.
		$tools = $wpdb->get_results($wpdb->prepare($tools_query, 'WooCommerce%'), ARRAY_A);
		$token_sum_query = sprintf('SELECT COALESCE(SUM(token_estimate),0) FROM %s WHERE category NOT LIKE %%s AND enabled = %%d', $table_sql);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- query uses sprintf with safe table wrapper.
		$enabled_token_total = (int) $wpdb->get_var($wpdb->prepare($token_sum_query, 'WooCommerce%', 1));
		
		$grouped_tools = array();
		foreach ($tools as $tool) {
			$category = $tool['category'];
			if (!isset($grouped_tools[$category])) {
				$grouped_tools[$category] = array();
			}
			$grouped_tools[$category][] = $tool;
		}
		
		?>
		
		<p><?php echo esc_html__('Here you can manage which tools are available on the MCP server. Click on the status button to toggle. Changes are saved automatically.', 'stifli-flex-mcp'); ?></p>
		<p><strong><?php echo esc_html__('Total estimated tokens for enabled WordPress tools:', 'stifli-flex-mcp'); ?></strong> <span class="sflmcp-total-tokens"><?php echo esc_html(number_format_i18n($enabled_token_total)); ?></span></p>
		<p class="description"><?php echo esc_html__('Token estimates are approximate (computed from tool name, description, and schema). Use them to compare profiles rather than as an exact billing value.', 'stifli-flex-mcp'); ?></p>
		
		<?php if ($active_profile): ?>
			<div class="notice notice-info">
				<p>
					<strong>⚠️ <?php echo esc_html__('Active profile:', 'stifli-flex-mcp'); ?></strong>
					<?php echo esc_html($active_profile['profile_name']); ?>
					<br>
					<?php echo esc_html__('Changes to tools will be automatically saved to this profile.', 'stifli-flex-mcp'); ?>
					<a href="?page=sflmcp-server&tab=profiles" class="button button-small sflmcp-profile-link">
						<?php echo esc_html__('View Profiles', 'stifli-flex-mcp'); ?>
					</a>
				</p>
			</div>
		<?php endif; ?>
		
		<?php if (empty($grouped_tools)): ?>
			<div class="notice notice-warning">
				<p><?php echo esc_html__('No tools found in the database. Use the button below to seed them.', 'stifli-flex-mcp'); ?></p>
			</div>
			<form method="post" action="">
				<?php wp_nonce_field('sflmcp_reseed_tools', 'sflmcp_reseed_nonce'); ?>
				<p>
					<button type="submit" class="button button-primary"><?php echo esc_html__('Seed Initial Tools', 'stifli-flex-mcp'); ?></button>
				</p>
			</form>
		<?php else: ?>
			<form method="post" action="" class="sflmcp-reseed-form">
				<?php wp_nonce_field('sflmcp_reseed_tools', 'sflmcp_reseed_nonce'); ?>
				<p>
					<button type="submit" class="button button-secondary sflmcp-reseed-btn" data-confirm="<?php echo esc_attr__('This will delete all tools and reseed them. Are you sure?', 'stifli-flex-mcp'); ?>"><?php echo esc_html__('Reset and Reseed Tools', 'stifli-flex-mcp'); ?></button>
					<span class="description"><?php echo esc_html__('Useful if you\'ve updated the plugin and new tools are available.', 'stifli-flex-mcp'); ?></span>
				</p>
			</form>
			
			<?php foreach ($grouped_tools as $category => $category_tools): ?>
				<?php $category_token_total = 0; foreach ($category_tools as $tool_meta) { $category_token_total += intval($tool_meta['token_estimate']); } ?>
				<h2><?php echo esc_html($category); ?> <small class="sflmcp-category-count">(<?php echo esc_html__('estimated tokens:', 'stifli-flex-mcp'); ?> <?php echo esc_html(number_format_i18n($category_token_total)); ?>)</small></h2>
				<div class="sflmcp-bulk-actions">
					<button type="button" class="button button-small sflmcp-bulk-toggle" data-action="enable" data-category="<?php echo esc_attr($category); ?>"><?php echo esc_html__('Enable All', 'stifli-flex-mcp'); ?></button>
					<button type="button" class="button button-small sflmcp-bulk-toggle" data-action="disable" data-category="<?php echo esc_attr($category); ?>"><?php echo esc_html__('Disable All', 'stifli-flex-mcp'); ?></button>
				</div>
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th class="sflmcp-tools-col-tool"><?php echo esc_html__('Tool', 'stifli-flex-mcp'); ?></th>
							<th class="sflmcp-tools-col-desc"><?php echo esc_html__('Description', 'stifli-flex-mcp'); ?></th>
							<th class="sflmcp-tools-col-tokens"><?php echo esc_html__('Tokens (~)', 'stifli-flex-mcp'); ?></th>
							<th class="sflmcp-tools-col-status"><?php echo esc_html__('Status', 'stifli-flex-mcp'); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($category_tools as $tool): 
							$is_enabled = intval($tool['enabled']) === 1;
							$status_class = $is_enabled ? 'status-enabled' : 'status-disabled';
							$status_icon = $is_enabled ? 'dashicons-yes' : 'dashicons-no';
							$status_text = $is_enabled ? __('Enabled', 'stifli-flex-mcp') : __('Disabled', 'stifli-flex-mcp');
						?>
							<tr>
								<td><code><?php echo esc_html($tool['tool_name']); ?></code></td>
								<td><?php echo esc_html($tool['tool_description']); ?></td>
								<td><?php echo esc_html(number_format_i18n(intval($tool['token_estimate']))); ?></td>
								<td>
									<button type="button" class="button button-small sflmcp-tool-toggle <?php echo esc_attr($status_class); ?>" 
										data-id="<?php echo intval($tool['id']); ?>" 
										data-enabled="<?php echo $is_enabled ? '1' : '0'; ?>">
										<span class="dashicons <?php echo esc_attr($status_icon); ?>"></span>
										<span class="status-text"><?php echo esc_html($status_text); ?></span>
									</button>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<br/>
			<?php endforeach; ?>
		<?php endif; ?>
		<?php
	}
	
	private function renderWCToolsTab() {
		global $wpdb;
		$table = StifliFlexMcpUtils::getPrefixedTable('sflmcp_tools', false);
		$profiles_table = StifliFlexMcpUtils::getPrefixedTable('sflmcp_profiles', false);
		$table_sql = StifliFlexMcpUtils::wrapTableNameForQuery($table);
		$profiles_table_sql = StifliFlexMcpUtils::wrapTableNameForQuery($profiles_table);
		
		// Check if there's an active profile
		$active_profile_query = sprintf('SELECT * FROM %s WHERE is_active = %%d', $profiles_table_sql);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- query uses sprintf with safe table wrapper.
		$active_profile = $wpdb->get_row($wpdb->prepare($active_profile_query, 1), ARRAY_A);
		
		// Get all WooCommerce tools grouped by category
		$wc_tools_query = sprintf("SELECT * FROM %s WHERE category LIKE %%s ORDER BY category, tool_name", $table_sql);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- query uses sprintf with safe table wrapper.
		$tools = $wpdb->get_results($wpdb->prepare($wc_tools_query, 'WooCommerce%'), ARRAY_A);
		$wc_token_sum_query = sprintf("SELECT COALESCE(SUM(token_estimate),0) FROM %s WHERE category LIKE %%s AND enabled = %%d", $table_sql);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- query uses sprintf with safe table wrapper.
		$enabled_token_total = (int) $wpdb->get_var($wpdb->prepare($wc_token_sum_query, 'WooCommerce%', 1));
		
		$grouped_tools = array();
		foreach ($tools as $tool) {
			$category = $tool['category'];
			if (!isset($grouped_tools[$category])) {
				$grouped_tools[$category] = array();
			}
			$grouped_tools[$category][] = $tool;
		}
		
		?>
		
		<p><?php echo esc_html__('Here you can manage which WooCommerce tools are available on the MCP server. Click on the status button to toggle. Changes are saved automatically.', 'stifli-flex-mcp'); ?></p>
		<p><strong><?php echo esc_html__('Total estimated tokens for enabled WooCommerce tools:', 'stifli-flex-mcp'); ?></strong> <span class="sflmcp-total-tokens"><?php echo esc_html(number_format_i18n($enabled_token_total)); ?></span></p>
		<p class="description"><?php echo esc_html__('Token estimates are approximate (computed from tool name, description, and schema). Use them to compare profiles rather than as an exact billing value.', 'stifli-flex-mcp'); ?></p>
		
		<?php if ($active_profile): ?>
			<div class="notice notice-info">
				<p>
					<strong>⚠️ <?php echo esc_html__('Active profile:', 'stifli-flex-mcp'); ?></strong>
					<?php echo esc_html($active_profile['profile_name']); ?>
					<br>
					<?php echo esc_html__('Changes to tools will be automatically saved to this profile.', 'stifli-flex-mcp'); ?>
					<a href="?page=sflmcp-server&tab=profiles" class="button button-small sflmcp-profile-link">
						<?php echo esc_html__('View Profiles', 'stifli-flex-mcp'); ?>
					</a>
				</p>
			</div>
		<?php endif; ?>
		
		<?php 
		// Check if WooCommerce is installed and active
		$wc_installed = class_exists('WooCommerce');
		?>
		
		<?php if (!$wc_installed): ?>
			<div class="notice notice-warning">
				<p>
					<strong>⚠️ <?php echo esc_html__('WooCommerce is not installed or activated', 'stifli-flex-mcp'); ?></strong><br>
					<?php echo esc_html__('WooCommerce tools are available to configure, but will not work until you install and activate the WooCommerce plugin.', 'stifli-flex-mcp'); ?>
					<?php echo esc_html__('You can enable/disable them now and they will be ready when WooCommerce is active.', 'stifli-flex-mcp'); ?>
				</p>
			</div>
		<?php endif; ?>
		
		<?php if (empty($grouped_tools)): ?>
			<div class="notice notice-info">
				<p><?php echo esc_html__('No WooCommerce tools found in the database. Use the "Reset and Reseed" button in the WordPress tab to load them.', 'stifli-flex-mcp'); ?></p>
			</div>
		<?php else: ?>
			<?php foreach ($grouped_tools as $category => $category_tools): ?>
				<?php $category_token_total = 0; foreach ($category_tools as $tool_meta) { $category_token_total += intval($tool_meta['token_estimate']); } ?>
				<h2><?php echo esc_html($category); ?> <small class="sflmcp-category-count">(<?php echo esc_html__('estimated tokens:', 'stifli-flex-mcp'); ?> <?php echo esc_html(number_format_i18n($category_token_total)); ?>)</small></h2>
				<div class="sflmcp-bulk-actions">
					<button type="button" class="button button-small sflmcp-bulk-toggle" data-action="enable" data-category="<?php echo esc_attr($category); ?>"><?php echo esc_html__('Enable All', 'stifli-flex-mcp'); ?></button>
					<button type="button" class="button button-small sflmcp-bulk-toggle" data-action="disable" data-category="<?php echo esc_attr($category); ?>"><?php echo esc_html__('Disable All', 'stifli-flex-mcp'); ?></button>
				</div>
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th class="sflmcp-tools-col-tool"><?php echo esc_html__('Tool', 'stifli-flex-mcp'); ?></th>
							<th class="sflmcp-tools-col-desc"><?php echo esc_html__('Description', 'stifli-flex-mcp'); ?></th>
							<th class="sflmcp-tools-col-tokens"><?php echo esc_html__('Tokens (~)', 'stifli-flex-mcp'); ?></th>
							<th class="sflmcp-tools-col-status"><?php echo esc_html__('Status', 'stifli-flex-mcp'); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($category_tools as $tool): 
							$is_enabled = intval($tool['enabled']) === 1;
							$status_class = $is_enabled ? 'status-enabled' : 'status-disabled';
							$status_icon = $is_enabled ? 'dashicons-yes' : 'dashicons-no';
							$status_text = $is_enabled ? __('Enabled', 'stifli-flex-mcp') : __('Disabled', 'stifli-flex-mcp');
						?>
							<tr>
								<td><code><?php echo esc_html($tool['tool_name']); ?></code></td>
								<td><?php echo esc_html($tool['tool_description']); ?></td>
								<td><?php echo esc_html(number_format_i18n(intval($tool['token_estimate']))); ?></td>
								<td>
									<button type="button" class="button button-small sflmcp-tool-toggle <?php echo esc_attr($status_class); ?>" 
										data-id="<?php echo intval($tool['id']); ?>" 
										data-enabled="<?php echo $is_enabled ? '1' : '0'; ?>">
										<span class="dashicons <?php echo esc_attr($status_icon); ?>"></span>
										<span class="status-text"><?php echo esc_html($status_text); ?></span>
									</button>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<br/>
			<?php endforeach; ?>
		<?php endif; ?>
		<?php
	}
	
	private function renderProfilesTab() {
		global $wpdb;
		$profiles_table = StifliFlexMcpUtils::getPrefixedTable('sflmcp_profiles', false);
		$profile_tools_table = StifliFlexMcpUtils::getPrefixedTable('sflmcp_profile_tools', false);
		$tools_table = StifliFlexMcpUtils::getPrefixedTable('sflmcp_tools', false);
		$profiles_table_sql = StifliFlexMcpUtils::wrapTableNameForQuery($profiles_table);
		$profile_tools_table_sql = StifliFlexMcpUtils::wrapTableNameForQuery($profile_tools_table);
		$tools_table_sql = StifliFlexMcpUtils::wrapTableNameForQuery($tools_table);
		
		// Get all profiles with tool count and estimated tokens
		$profiles_query = sprintf(
			"SELECT p.*, COUNT(pt.id) AS tools_count, COALESCE(SUM(t.token_estimate),0) AS tokens_sum\n"
			."FROM %s p\n"
			."LEFT JOIN %s pt ON p.id = pt.profile_id\n"
			."LEFT JOIN %s t ON pt.tool_name = t.tool_name\n"
			."WHERE 1 = %%d\n"
			."GROUP BY p.id\n"
			."ORDER BY p.is_system DESC, p.profile_name ASC",
			$profiles_table_sql,
			$profile_tools_table_sql,
			$tools_table_sql
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- query uses sprintf with safe table wrapper.
		$profiles = $wpdb->get_results($wpdb->prepare($profiles_query, 1), ARRAY_A);

		$total_tools_query = sprintf('SELECT COUNT(*) FROM %s WHERE 1 = %%d', $tools_table_sql);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- query uses sprintf with safe table wrapper.
		$total_tools = $wpdb->get_var($wpdb->prepare($total_tools_query, 1));
		
		?>
		<p><?php echo esc_html__('Profiles allow you to quickly switch which tools are available for different use cases.', 'stifli-flex-mcp'); ?></p>
		<p class="description"><?php echo esc_html__('Token totals shown below are approximations based on the enabled tools. They help you gauge relative cost when switching profiles.', 'stifli-flex-mcp'); ?></p>
		
		<div class="sflmcp-profiles-actions">
			<button type="button" class="button" id="sflmcp_import_profile">
				<?php echo esc_html__('⬆ Import JSON', 'stifli-flex-mcp'); ?>
			</button>
			<button type="button" class="button" id="sflmcp_restore_system_profiles">
				<?php echo esc_html__('🔄 Restore System Profiles', 'stifli-flex-mcp'); ?>
			</button>
		</div>
		<?php
		$active_profile_info = null;
		foreach ($profiles as $profile_row) {
			if (intval($profile_row['is_active']) === 1) {
				$active_profile_info = $profile_row;
				break;
			}
		}
		if ($active_profile_info) :
		?>
		<div class="notice notice-info">
			<p>
				<strong><?php echo esc_html__('Currently active profile:', 'stifli-flex-mcp'); ?></strong>
				<?php echo esc_html($active_profile_info['profile_name']); ?>
				<span class="sflmcp-profiles-token-info"><?php echo esc_html__('Estimated token footprint (sum of enabled tools within the profile):', 'stifli-flex-mcp'); ?> <?php echo esc_html(number_format_i18n(intval($active_profile_info['tokens_sum']))); ?></span>
			</p>
		</div>
		<?php endif; ?>
		
		<?php if (empty($profiles)): ?>
			<div class="notice notice-warning">
				<p><?php echo esc_html__('No profiles found. Use the button above to restore system profiles.', 'stifli-flex-mcp'); ?></p>
			</div>
		<?php else: ?>
			<!-- System Profiles -->
			<?php
			$system_profiles = array_filter($profiles, function($p) { return intval($p['is_system']) === 1; });
			if (!empty($system_profiles)):
			?>
			<h3><?php echo esc_html__('System Profiles (non-deletable)', 'stifli-flex-mcp'); ?></h3>
			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th class="sflmcp-profiles-col-radio"></th>
						<th class="sflmcp-profiles-col-name"><?php echo esc_html__('Name', 'stifli-flex-mcp'); ?></th>
						<th class="sflmcp-profiles-col-desc"><?php echo esc_html__('Description', 'stifli-flex-mcp'); ?></th>
						<th class="sflmcp-profiles-col-tools"><?php echo esc_html__('Tools', 'stifli-flex-mcp'); ?></th>
						<th class="sflmcp-profiles-col-tokens"><?php echo esc_html__('Tokens (~)', 'stifli-flex-mcp'); ?></th>
						<th class="sflmcp-profiles-col-actions"><?php echo esc_html__('Actions', 'stifli-flex-mcp'); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ($system_profiles as $profile): ?>
						<?php
						// Get tools for this profile
						$system_tools_query = sprintf(
							'SELECT t.tool_name FROM %s pt LEFT JOIN %s t ON pt.tool_name = t.tool_name WHERE pt.profile_id = %%d ORDER BY t.tool_name',
							$profile_tools_table_sql,
							$tools_table_sql
						);
						// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- query uses sprintf with safe table wrapper.
						$profile_tools_rows = $wpdb->get_results($wpdb->prepare($system_tools_query, $profile['id']), ARRAY_A);
						$profile_tools_list = array();
						if (!empty($profile_tools_rows)) {
							foreach ($profile_tools_rows as $tool_row) {
								$profile_tools_list[] = $tool_row['tool_name'];
							}
						}
						$tools_list_html = !empty($profile_tools_list) ? implode(', ', $profile_tools_list) : esc_html__('None', 'stifli-flex-mcp');
						?>
						<tr>
							<td>
								<?php if (intval($profile['is_active']) === 1): ?>
									<span class="sflmcp-active-dot">●</span>
								<?php endif; ?>
							</td>
							<td><strong><?php echo esc_html($profile['profile_name']); ?></strong></td>
							<td>
								<?php echo esc_html($profile['profile_description']); ?>
								<br>
								<a href="#" class="SFLMCP-view-tools" data-tools="<?php echo esc_attr($tools_list_html); ?>" class="sflmcp-view-tools-link">
									📋 <?php echo esc_html__('View tools', 'stifli-flex-mcp'); ?>
								</a>
							</td>
							<td><?php echo esc_html(intval($profile['tools_count']) . '/' . intval($total_tools)); ?></td>
							<td><?php echo esc_html(number_format_i18n(intval($profile['tokens_sum']))); ?></td>
							<td>
								<button type="button" class="button SFLMCP-apply-profile" data-profile-id="<?php echo intval($profile['id']); ?>" data-profile-name="<?php echo esc_attr($profile['profile_name']); ?>">
									<?php echo esc_html__('Apply', 'stifli-flex-mcp'); ?>
								</button>
								<button type="button" class="button SFLMCP-duplicate-profile" data-profile-id="<?php echo intval($profile['id']); ?>">
									<?php echo esc_html__('Duplicate', 'stifli-flex-mcp'); ?>
								</button>
								<button type="button" class="button SFLMCP-export-profile" data-profile-id="<?php echo intval($profile['id']); ?>" data-profile-name="<?php echo esc_attr($profile['profile_name']); ?>">
									<?php echo esc_html__('Export', 'stifli-flex-mcp'); ?>
								</button>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<?php endif; ?>
			
			<!-- Custom Profiles -->
			<?php
			$custom_profiles = array_filter($profiles, function($p) { return intval($p['is_system']) === 0; });
			if (!empty($custom_profiles)):
			?>
			<h3 class="sflmcp-profiles-custom-heading"><?php echo esc_html__('Custom Profiles', 'stifli-flex-mcp'); ?></h3>
			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th class="sflmcp-profiles-col-radio"></th>
						<th class="sflmcp-profiles-col-name"><?php echo esc_html__('Name', 'stifli-flex-mcp'); ?></th>
						<th class="sflmcp-profiles-col-desc"><?php echo esc_html__('Description', 'stifli-flex-mcp'); ?></th>
						<th class="sflmcp-profiles-col-tools"><?php echo esc_html__('Tools', 'stifli-flex-mcp'); ?></th>
						<th class="sflmcp-profiles-col-tokens"><?php echo esc_html__('Tokens (~)', 'stifli-flex-mcp'); ?></th>
						<th class="sflmcp-profiles-col-actions"><?php echo esc_html__('Actions', 'stifli-flex-mcp'); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ($custom_profiles as $profile): ?>
						<?php
						// Get tools for this profile
						$custom_tools_query = sprintf(
							'SELECT t.tool_name, COALESCE(t.token_estimate,0) as token_estimate FROM %s pt LEFT JOIN %s t ON pt.tool_name = t.tool_name WHERE pt.profile_id = %%d ORDER BY t.tool_name',
							$profile_tools_table_sql,
							$tools_table_sql
						);
						// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- query uses sprintf with safe table wrapper.
						$profile_tools_rows = $wpdb->get_results($wpdb->prepare($custom_tools_query, $profile['id']), ARRAY_A);
						$profile_tools_list = array();
						if (!empty($profile_tools_rows)) {
							foreach ($profile_tools_rows as $tool_row) {
								$token_str = number_format_i18n(intval($tool_row['token_estimate']));
								$profile_tools_list[] = sprintf('%s (≈%s)', $tool_row['tool_name'], $token_str);
							}
						}
						$tools_list_html = !empty($profile_tools_list) ? implode(', ', $profile_tools_list) : esc_html__('None', 'stifli-flex-mcp');
						?>
						<tr>
							<td>
								<?php if (intval($profile['is_active']) === 1): ?>
									<span class="sflmcp-active-dot">●</span>
								<?php endif; ?>
							</td>
							<td><strong><?php echo esc_html($profile['profile_name']); ?></strong></td>
							<td>
								<?php echo esc_html($profile['profile_description']); ?>
								<br>
								<a href="#" class="SFLMCP-view-tools" data-tools="<?php echo esc_attr($tools_list_html); ?>" class="sflmcp-view-tools-link">
									📋 <?php echo esc_html__('View tools', 'stifli-flex-mcp'); ?>
								</a>
							</td>
							<td><?php echo esc_html(intval($profile['tools_count']) . '/' . intval($total_tools)); ?></td>
							<td><?php echo esc_html(number_format_i18n(intval($profile['tokens_sum']))); ?></td>
							<td>
								<button type="button" class="button SFLMCP-apply-profile" data-profile-id="<?php echo intval($profile['id']); ?>" data-profile-name="<?php echo esc_attr($profile['profile_name']); ?>">
									<?php echo esc_html__('Apply', 'stifli-flex-mcp'); ?>
								</button>
								<button type="button" class="button SFLMCP-edit-profile" data-profile-id="<?php echo intval($profile['id']); ?>">
									<?php echo esc_html__('Edit', 'stifli-flex-mcp'); ?>
								</button>
								<button type="button" class="button SFLMCP-duplicate-profile" data-profile-id="<?php echo intval($profile['id']); ?>">
									<?php echo esc_html__('Duplicate', 'stifli-flex-mcp'); ?>
								</button>
								<button type="button" class="button SFLMCP-export-profile" data-profile-id="<?php echo intval($profile['id']); ?>" data-profile-name="<?php echo esc_attr($profile['profile_name']); ?>">
									<?php echo esc_html__('Export', 'stifli-flex-mcp'); ?>
								</button>
								<button type="button" class="button button-link-delete SFLMCP-delete-profile" data-profile-id="<?php echo intval($profile['id']); ?>" data-profile-name="<?php echo esc_attr($profile['profile_name']); ?>">
									<?php echo esc_html__('Delete', 'stifli-flex-mcp'); ?>
								</button>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<?php endif; ?>
		<?php endif; ?>
		
		<!-- Hidden file input for import -->
		<input type="file" id="sflmcp_import_file" accept=".json" class="sflmcp-hidden-file-input" />
		
		
		<?php
	}

	/**
	 * Render Custom Tools Tab
	 */
	private function renderCustomToolsTab() {
		?>
		<h2><?php echo esc_html__('🔌 Custom Tools (Webhooks & Actions)', 'stifli-flex-mcp'); ?></h2>
		<p>
			<?php echo esc_html__('Create custom tools that connect to external services, call any WordPress/plugin action hook, or integrate with APIs. The AI will invoke these tools just like native functions.', 'stifli-flex-mcp'); ?>
			<a href="?page=sflmcp-server&tab=help" class="button button-link">
				📚 <?php echo esc_html__('View Complete Guide', 'stifli-flex-mcp'); ?>
			</a>
		</p>
		
		<div class="sflmcp-custom-tools-container">
			<!-- Tools List -->
			<div class="sflmcp-tools-list-panel">
				<div class="sflmcp-header-actions">
					<h3><?php echo esc_html__('Your Custom Tools', 'stifli-flex-mcp'); ?></h3>
					<button type="button" class="button button-primary" id="sflmcp_add_custom_tool">
						<?php echo esc_html__('➕ Add New Tool', 'stifli-flex-mcp'); ?>
					</button>
				</div>
				
				<table class="wp-list-table widefat fixed striped" id="sflmcp_custom_tools_table">
					<thead>
						<tr>
							<th width="20%"><?php echo esc_html__('Name', 'stifli-flex-mcp'); ?></th>
							<th width="35%"><?php echo esc_html__('Description', 'stifli-flex-mcp'); ?></th>
							<th width="10%"><?php echo esc_html__('Method', 'stifli-flex-mcp'); ?></th>
							<th width="10%"><?php echo esc_html__('Status', 'stifli-flex-mcp'); ?></th>
							<th width="25%"><?php echo esc_html__('Actions', 'stifli-flex-mcp'); ?></th>
						</tr>
					</thead>
					<tbody>
						<!-- Rows loaded via AJAX -->
						<tr class="sflmcp-loading-row">
							<td colspan="5"><?php echo esc_html__('Loading tools...', 'stifli-flex-mcp'); ?></td>
						</tr>
					</tbody>
				</table>
			</div>
			
			<!-- Editor Modal (Hidden) -->
			<div id="sflmcp_tool_editor_modal" class="sflmcp-modal">
				<div class="sflmcp-modal-content">
					<div class="sflmcp-modal-header">
						<h2 id="sflmcp_editor_title"><?php echo esc_html__('Edit Tool', 'stifli-flex-mcp'); ?></h2>
						<span class="sflmcp-modal-close">&times;</span>
					</div>
					<div class="sflmcp-modal-body">
						<form id="sflmcp_tool_form">
							<input type="hidden" id="tool_id" name="id" value="">
							
							<div class="sflmcp-form-row">
								<label><?php echo esc_html__('Internal Name (Unique)', 'stifli-flex-mcp'); ?></label>
								<input type="text" id="tool_name" name="tool_name" required placeholder="custom_create_ticket">
								<p class="description"><?php echo esc_html__('Must start with "custom_". Only lowercase letters, numbers, and underscores.', 'stifli-flex-mcp'); ?></p>
							</div>
							
							<div class="sflmcp-form-row">
								<label><?php echo esc_html__('Description (Instruction for AI)', 'stifli-flex-mcp'); ?></label>
								<textarea id="tool_description" name="tool_description" rows="2" required placeholder="Creates a support ticket in Jira with title and priority."></textarea>
							</div>
							
							<div class="sflmcp-form-group-inline">
								<div class="sflmcp-form-row">
									<label><?php echo esc_html__('Type', 'stifli-flex-mcp'); ?></label>
									<select id="tool_method" name="method">
										<option value="GET">GET (HTTP)</option>
										<option value="POST">POST (HTTP)</option>
										<option value="PUT">PUT (HTTP)</option>
										<option value="DELETE">DELETE (HTTP)</option>
										<option value="ACTION">ACTION (WordPress do_action)</option>
									</select>
									<p class="description"><?php echo esc_html__('HTTP calls external APIs. ACTION executes internal WordPress hooks.', 'stifli-flex-mcp'); ?></p>
								</div>
								<div class="sflmcp-form-row sflmcp-form-row-grow">
									<label id="endpoint_label"><?php echo esc_html__('Webhook URL / Endpoint', 'stifli-flex-mcp'); ?></label>
									<input type="text" id="tool_endpoint" name="endpoint" required placeholder="https://hook.eu1.make.com/...">
									<p class="description" id="endpoint_help"><?php echo esc_html__('For HTTP: full URL. For ACTION: any WordPress action hook name (e.g. flush_rewrite_rules, woocommerce_cancel_unpaid_orders)', 'stifli-flex-mcp'); ?></p>
								</div>
							</div>
							
							<!-- Interactive Schema Builder -->
							<div class="sflmcp-form-row">
								<label><?php echo esc_html__('Parameters (What does the AI ask for?)', 'stifli-flex-mcp'); ?></label>
								<div id="sflmcp_args_builder">
									<table class="widefat" id="sflmcp_args_table">
										<thead>
											<tr>
												<th><?php echo esc_html__('Param Name', 'stifli-flex-mcp'); ?></th>
												<th><?php echo esc_html__('Type', 'stifli-flex-mcp'); ?></th>
												<th><?php echo esc_html__('Description', 'stifli-flex-mcp'); ?></th>
												<th><?php echo esc_html__('Required', 'stifli-flex-mcp'); ?></th>
												<th width="50"></th>
											</tr>
										</thead>
										<tbody><!-- JS populates this --></tbody>
										<tfoot>
											<tr>
												<td colspan="5">
													<button type="button" class="button button-small" id="sflmcp_add_arg_row"><?php echo esc_html__('+ Add Parameter', 'stifli-flex-mcp'); ?></button>
												</td>
											</tr>
										</tfoot>
									</table>
								</div>
							</div>
							
							<div class="sflmcp-advanced-toggle">
								<a href="#" id="sflmcp_toggle_advanced"><?php echo esc_html__('Advanced Settings (Headers)', 'stifli-flex-mcp'); ?></a>
							</div>
							
							<div id="sflmcp_advanced_settings">
								<div class="sflmcp-form-row">
									<label><?php echo esc_html__('HTTP Headers (JSON)', 'stifli-flex-mcp'); ?></label>
									<textarea id="tool_headers" name="headers" rows="3" placeholder='{"Authorization": "Bearer token"}'></textarea>
								</div>
							</div>

							<div class="sflmcp-form-row">
								<label>
									<input type="checkbox" id="tool_enabled" name="enabled" value="1" checked>
									<?php echo esc_html__('Enable this tool', 'stifli-flex-mcp'); ?>
								</label>
							</div>
						</form>
					</div>
					<div class="sflmcp-modal-footer">
						<button type="button" class="button button-secondary" id="sflmcp_test_tool"><?php echo esc_html__('Test Connection', 'stifli-flex-mcp'); ?></button>
						<div class="sflmcp-spacer"></div>
						<button type="button" class="button button-primary" id="sflmcp_save_tool"><?php echo esc_html__('Save Tool', 'stifli-flex-mcp'); ?></button>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	// AJAX Handlers for Custom Tools

	public function ajax_get_custom_tools() {
		check_ajax_referer('sflmcp_custom_tools', 'nonce');
		if (!current_user_can('manage_options')) wp_send_json_error();
		
		global $wpdb;
		$table = StifliFlexMcpUtils::getPrefixedTable('sflmcp_custom_tools');
		// phpcs:ignore
		$tools = $wpdb->get_results("SELECT * FROM $table ORDER BY created_at DESC");
		
		wp_send_json_success($tools);
	}

	public function ajax_save_custom_tool() {
		check_ajax_referer('sflmcp_custom_tools', 'nonce');
		if (!current_user_can('manage_options')) wp_send_json_error();
		
		global $wpdb;
		$table = StifliFlexMcpUtils::getPrefixedTable('sflmcp_custom_tools');
		
		$id = isset($_POST['id']) ? intval($_POST['id']) : 0;
		$name = isset($_POST['tool_name']) ? sanitize_text_field( wp_unslash( $_POST['tool_name'] ) ) : '';
		$desc = isset($_POST['tool_description']) ? sanitize_text_field( wp_unslash( $_POST['tool_description'] ) ) : '';
		$method = isset($_POST['method']) ? sanitize_text_field( wp_unslash( $_POST['method'] ) ) : 'GET';
		$endpoint = isset($_POST['endpoint']) ? esc_url_raw( wp_unslash( $_POST['endpoint'] ) ) : '';
		$enabled = isset($_POST['enabled']) ? 1 : 0;
		
		// Handle headers JSON
		$headers = isset($_POST['headers']) ? sanitize_textarea_field( wp_unslash( $_POST['headers'] ) ) : '';
		if (!empty($headers) && null === json_decode($headers)) {
			wp_send_json_error(array('message' => 'Invalid JSON in headers'));
		}

		// Handle Arguments Builder -> JSON Schema
		$args_json = isset($_POST['arguments']) ? sanitize_textarea_field( wp_unslash( $_POST['arguments'] ) ) : '{}';
		// Validate that it's valid JSON
		if (null === json_decode($args_json)) {
			wp_send_json_error(array('message' => 'Invalid JSON in arguments'));
		}
		
		$data = array(
			'tool_name' => $name,
			'tool_description' => $desc,
			'method' => $method,
			'endpoint' => $endpoint,
			'headers' => $headers,
			'arguments' => $args_json,
			'enabled' => $enabled
		);
		
		if ($id > 0) {
			$wpdb->update($table, $data, array('id' => $id));
		} else {
			// Ensure unique name
			if ($wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE tool_name = %s", $name))) {
				wp_send_json_error(array('message' => 'Tool name already exists'));
			}
			$wpdb->insert($table, $data);
		}
		
		wp_send_json_success();
	}

	public function ajax_delete_custom_tool() {
		check_ajax_referer('sflmcp_custom_tools', 'nonce');
		if (!current_user_can('manage_options')) wp_send_json_error();
		
		global $wpdb;
		$table = StifliFlexMcpUtils::getPrefixedTable('sflmcp_custom_tools');
		$id = isset($_POST['id']) ? intval($_POST['id']) : 0;
		
		if (!$id) {
			wp_send_json_error(array('message' => __('Invalid tool ID', 'stifli-flex-mcp')));
			return;
		}
		
		$wpdb->delete($table, array('id' => $id));
		wp_send_json_success();
	}

	public function ajax_toggle_custom_tool() {
		check_ajax_referer('sflmcp_custom_tools', 'nonce');
		if (!current_user_can('manage_options')) {
			wp_send_json_error(array('message' => __('Permission denied', 'stifli-flex-mcp')));
			return;
		}
		
		global $wpdb;
		$table = $wpdb->prefix . 'sflmcp_custom_tools';
		$id = isset($_POST['id']) ? intval($_POST['id']) : 0;
		
		if (!$id) {
			wp_send_json_error(array('message' => __('Invalid tool ID', 'stifli-flex-mcp')));
			return;
		}
		
		// Get current status
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-managed table with parameterized query.
		$current = $wpdb->get_var($wpdb->prepare("SELECT enabled FROM $table WHERE id = %d", $id));
		if ($current === null) {
			wp_send_json_error(array('message' => __('Tool not found', 'stifli-flex-mcp')));
			return;
		}
		
		$new_status = ($current == 1) ? 0 : 1;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->update($table, array('enabled' => $new_status), array('id' => $id));
		
		wp_send_json_success(array('enabled' => $new_status));
	}

	public function ajax_test_custom_tool() {
		check_ajax_referer('sflmcp_custom_tools', 'nonce');
		if (!current_user_can('manage_options')) wp_send_json_error();
		
		$endpoint = isset($_POST['endpoint']) ? esc_url_raw( wp_unslash( $_POST['endpoint'] ) ) : '';
		$method = isset($_POST['method']) ? sanitize_text_field( wp_unslash( $_POST['method'] ) ) : 'GET';
		$headers_raw = isset($_POST['headers']) ? sanitize_textarea_field( wp_unslash( $_POST['headers'] ) ) : '';
		$test_args = array(
			'test' => true,
			'timestamp' => time(),
			'source' => 'StifLi Flex MCP Test'
		);
		
		$args = array(
			'method' => $method,
			'timeout' => 15,
			'user-agent' => 'StifLi-Flex-MCP/Tester'
		);
		
		if (!empty($headers_raw)) {
			$h = json_decode($headers_raw, true);
			if (is_array($h)) {
				$args['headers'] = $h;
			}
		}
		
		if ($method === 'GET') {
			$endpoint = add_query_arg($test_args, $endpoint);
		} else {
			$args['body'] = wp_json_encode($test_args);
			if (!isset($args['headers']['Content-Type'])) {
				$args['headers']['Content-Type'] = 'application/json';
			}
		}
		
		$response = wp_remote_request($endpoint, $args);
		
		if (is_wp_error($response)) {
			wp_send_json_error(array('message' => $response->get_error_message()));
		} else {
			$code = wp_remote_retrieve_response_code($response);
			$body = wp_remote_retrieve_body($response);
			wp_send_json_success(array('code' => $code, 'body' => substr($body, 0, 500))); // Truncate for preview
		}
	}

	/**
	 * AJAX handler to toggle a single WordPress/WooCommerce tool
	 */
	public function ajax_toggle_tool() {
		check_ajax_referer('sflmcp_tools', 'nonce');
		
		if (!current_user_can('manage_options')) {
			wp_send_json_error(array('message' => __('Permission denied', 'stifli-flex-mcp')));
			return;
		}
		
		global $wpdb;
		$table = $wpdb->prefix . 'sflmcp_tools';
		$tool_id = isset($_POST['tool_id']) ? intval($_POST['tool_id']) : 0;
		
		if (!$tool_id) {
			wp_send_json_error(array('message' => __('Invalid tool ID', 'stifli-flex-mcp')));
			return;
		}
		
		// Get current status
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$tool = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $tool_id), ARRAY_A);
		
		if (!$tool) {
			wp_send_json_error(array('message' => __('Tool not found', 'stifli-flex-mcp')));
			return;
		}
		
		$new_status = ($tool['enabled'] == 1) ? 0 : 1;
		
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->update(
			$table,
			array('enabled' => $new_status, 'updated_at' => current_time('mysql', true)),
			array('id' => $tool_id),
			array('%d', '%s'),
			array('%d')
		);
		
		// Sync to active profile if exists
		$this->syncToolToActiveProfile($tool['tool_name'], $new_status);
		
		// Calculate new token totals
		$is_wc = strpos($tool['category'], 'WooCommerce') === 0;
		$like_pattern = $is_wc ? 'WooCommerce%' : '';
		
		if ($is_wc) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$total_tokens = $wpdb->get_var($wpdb->prepare(
				"SELECT COALESCE(SUM(token_estimate),0) FROM $table WHERE category LIKE %s AND enabled = %d",
				'WooCommerce%', 1
			));
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$total_tokens = $wpdb->get_var($wpdb->prepare(
				"SELECT COALESCE(SUM(token_estimate),0) FROM $table WHERE category NOT LIKE %s AND enabled = %d",
				'WooCommerce%', 1
			));
		}
		
		wp_send_json_success(array(
			'enabled' => $new_status,
			'total_tokens' => number_format_i18n(intval($total_tokens))
		));
	}

	/**
	 * AJAX handler to bulk toggle tools in a category
	 */
	public function ajax_bulk_toggle_tools() {
		check_ajax_referer('sflmcp_tools', 'nonce');
		
		if (!current_user_can('manage_options')) {
			wp_send_json_error(array('message' => __('Permission denied', 'stifli-flex-mcp')));
			return;
		}
		
		global $wpdb;
		$table = $wpdb->prefix . 'sflmcp_tools';
		$bulk_action = isset($_POST['bulk_action']) ? sanitize_text_field( wp_unslash( $_POST['bulk_action'] ) ) : '';
		$category = isset($_POST['category']) ? sanitize_text_field( wp_unslash( $_POST['category'] ) ) : '';
		
		if (!in_array($bulk_action, array('enable', 'disable'))) {
			wp_send_json_error(array('message' => __('Invalid action', 'stifli-flex-mcp')));
			return;
		}
		
		$new_status = ($bulk_action === 'enable') ? 1 : 0;
		
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query($wpdb->prepare(
			"UPDATE $table SET enabled = %d, updated_at = %s WHERE category = %s",
			$new_status, current_time('mysql', true), $category
		));
		
		// Get affected tools and sync to active profile
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$affected_tools = $wpdb->get_col($wpdb->prepare(
			"SELECT tool_name FROM $table WHERE category = %s",
			$category
		));
		
		foreach ($affected_tools as $tool_name) {
			$this->syncToolToActiveProfile($tool_name, $new_status);
		}
		
		wp_send_json_success();
	}

	/**
	 * Sync a tool's enabled status to the active profile
	 */
	private function syncToolToActiveProfile($tool_name, $enabled) {
		global $wpdb;
		$profiles_table = $wpdb->prefix . 'sflmcp_profiles';
		$profile_tools_table = $wpdb->prefix . 'sflmcp_profile_tools';
		
		// Get active profile
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$active_profile = $wpdb->get_row($wpdb->prepare(
			"SELECT id FROM $profiles_table WHERE is_active = %d",
			1
		), ARRAY_A);
		
		if (!$active_profile) {
			return;
		}
		
		$profile_id = $active_profile['id'];
		
		if ($enabled) {
			// Check if already exists
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$exists = $wpdb->get_var($wpdb->prepare(
				"SELECT id FROM $profile_tools_table WHERE profile_id = %d AND tool_name = %s",
				$profile_id, $tool_name
			));
			
			if (!$exists) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				$wpdb->insert(
					$profile_tools_table,
					array('profile_id' => $profile_id, 'tool_name' => $tool_name),
					array('%d', '%s')
				);
			}
		} else {
			// Remove from profile
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->delete(
				$profile_tools_table,
				array('profile_id' => $profile_id, 'tool_name' => $tool_name),
				array('%d', '%s')
			);
		}
	}

	/**
	 * Render Abilities Tab - WordPress 6.9+ Abilities API Integration
	 */
	private function renderAbilitiesTab() {
		?>
		<h2><?php echo esc_html__('🔮 WordPress Abilities (6.9+)', 'stifli-flex-mcp'); ?></h2>
		<p class="description">
			<?php echo esc_html__('Discover and import abilities from other WordPress plugins. Imported abilities are exposed as MCP tools for AI agents.', 'stifli-flex-mcp'); ?>
		</p>

		<div class="sflmcp-abilities-container">
			<!-- Left Panel: Discover Abilities -->
			<div class="sflmcp-abilities-discover">
				<h3>
					<?php echo esc_html__('🔍 Discover Available Abilities', 'stifli-flex-mcp'); ?>
				</h3>
				<p class="description">
					<?php echo esc_html__('Scan your WordPress installation for registered abilities from other plugins.', 'stifli-flex-mcp'); ?>
				</p>
				<button type="button" id="sflmcp-discover-abilities" class="button button-primary">
					<?php echo esc_html__('Discover Abilities', 'stifli-flex-mcp'); ?>
				</button>
				
				<div id="sflmcp-discovered-abilities">
					<!-- Discovered abilities will be loaded here via AJAX -->
				</div>
			</div>

			<!-- Right Panel: Imported Abilities -->
			<div class="sflmcp-abilities-imported">
				<h3>
					<?php echo esc_html__('✅ Imported Abilities', 'stifli-flex-mcp'); ?>
				</h3>
				<p class="description">
					<?php echo esc_html__('These abilities are exposed as MCP tools and available to AI agents.', 'stifli-flex-mcp'); ?>
				</p>
				
				<div id="sflmcp-imported-abilities">
					<?php $this->renderImportedAbilitiesList(); ?>
				</div>
			</div>
		</div>

		<div class="sflmcp-abilities-info">
			<strong><?php echo esc_html__('How it works:', 'stifli-flex-mcp'); ?></strong>
			<ol>
				<li><?php echo esc_html__('Install plugins that register WordPress Abilities (e.g., All Sources Images)', 'stifli-flex-mcp'); ?></li>
				<li><?php echo esc_html__('Click "Discover Abilities" to find available abilities', 'stifli-flex-mcp'); ?></li>
				<li><?php echo esc_html__('Import the abilities you want to expose to AI agents', 'stifli-flex-mcp'); ?></li>
				<li><?php echo esc_html__('AI agents can now use these abilities via MCP', 'stifli-flex-mcp'); ?></li>
			</ol>
		</div>
		<?php
	}

	/**
	 * Render the list of imported abilities
	 */
	private function renderImportedAbilitiesList() {
		global $wpdb;
		$table = $wpdb->prefix . 'sflmcp_abilities';
		
		// Check if table exists
		$like = $wpdb->esc_like($table);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- schema check.
		if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $like)) !== $table) {
			echo '<p>' . esc_html__('Abilities table not initialized. Please deactivate and reactivate the plugin.', 'stifli-flex-mcp') . '</p>';
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- fresh data needed.
		$abilities = $wpdb->get_results("SELECT * FROM {$table} ORDER BY ability_category, ability_label");
		
		if (empty($abilities)) {
			echo '<p class="sflmcp-empty-msg">' . esc_html__('No abilities imported yet. Use the Discover button to find and import abilities.', 'stifli-flex-mcp') . '</p>';
			return;
		}

		echo '<table class="widefat striped">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__('Ability', 'stifli-flex-mcp') . '</th>';
		echo '<th>' . esc_html__('Category', 'stifli-flex-mcp') . '</th>';
		echo '<th class="sflmcp-logs-col-enabled">' . esc_html__('Enabled', 'stifli-flex-mcp') . '</th>';
		echo '<th class="sflmcp-logs-col-enabled">' . esc_html__('Actions', 'stifli-flex-mcp') . '</th>';
		echo '</tr></thead><tbody>';

		foreach ($abilities as $ability) {
			$enabled_class = $ability->enabled ? 'dashicons-yes-alt' : 'dashicons-marker';
			$enabled_color = $ability->enabled ? '#46b450' : '#dc3232';
			$tool_name = 'ability_' . str_replace(array('/', '-'), '_', $ability->ability_name);
			
			echo '<tr data-ability-id="' . esc_attr($ability->id) . '">';
			echo '<td>';
			echo '<strong>' . esc_html($ability->ability_label) . '</strong>';
			echo '<br><code class="sflmcp-ability-tool-name">' . esc_html($tool_name) . '</code>';
			if (!empty($ability->ability_description)) {
				echo '<br><small class="sflmcp-ability-desc">' . esc_html(wp_trim_words($ability->ability_description, 15)) . '</small>';
			}
			echo '</td>';
			echo '<td>' . esc_html($ability->ability_category) . '</td>';
			echo '<td class="sflmcp-td-center">';
			echo '<button type="button" class="button-link sflmcp-toggle-ability" data-id="' . esc_attr($ability->id) . '" data-enabled="' . esc_attr($ability->enabled) . '" title="' . esc_attr__('Toggle enabled', 'stifli-flex-mcp') . '">';
			echo '<span class="dashicons ' . esc_attr($enabled_class) . '" class="sflmcp-ability-status-icon"></span>';
			echo '</button>';
			echo '</td>';
			echo '<td class="sflmcp-td-center">';
			echo '<button type="button" class="button-link sflmcp-delete-ability" data-id="' . esc_attr($ability->id) . '" title="' . esc_attr__('Remove ability', 'stifli-flex-mcp') . '">';
			echo '<span class="dashicons dashicons-trash" class="sflmcp-ability-delete-icon"></span>';
			echo '</button>';
			echo '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * AJAX handler: Discover available WordPress Abilities
	 */
	public function ajax_discover_abilities() {
		check_ajax_referer('sflmcp_abilities', 'nonce');

		if (!current_user_can('manage_options')) {
			wp_send_json_error(array('message' => __('Permission denied', 'stifli-flex-mcp')));
		}

		if (!stifli_flex_mcp_abilities_available()) {
			wp_send_json_error(array('message' => __('WordPress Abilities API not available. Requires WordPress 6.9+', 'stifli-flex-mcp')));
		}

		// Get all registered abilities using wp_get_abilities()
		$all_abilities = wp_get_abilities();
		if (empty($all_abilities)) {
			wp_send_json_success(array(
				'abilities' => array(),
				'message' => __('No abilities found. Install plugins that register WordPress Abilities.', 'stifli-flex-mcp'),
			));
			return;
		}

		// Get already imported abilities
		global $wpdb;
		$table = $wpdb->prefix . 'sflmcp_abilities';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$imported = $wpdb->get_col("SELECT ability_name FROM {$table}");
		$imported_map = array_flip($imported);

		$abilities_list = array();
		foreach ($all_abilities as $ability) {
			$name = $ability->get_name();
			
			// Skip our own abilities if we ever register any
			if (strpos($name, 'sflmcp/') === 0) {
				continue;
			}

			// Get category - may be a string or null
			$category = method_exists($ability, 'get_category') ? $ability->get_category() : '';
			if (is_object($category) && method_exists($category, 'get_label')) {
				$category = $category->get_label();
			}

			$abilities_list[] = array(
				'name' => $name,
				'label' => $ability->get_label(),
				'description' => $ability->get_description(),
				'category' => $category ?: 'Uncategorized',
				'input_schema' => $ability->get_input_schema(),
				'output_schema' => method_exists($ability, 'get_output_schema') ? $ability->get_output_schema() : null,
				'imported' => isset($imported_map[$name]),
			);
		}

		wp_send_json_success(array(
			'abilities' => $abilities_list,
			'count' => count($abilities_list),
		));
	}

	/**
	 * AJAX handler: Import an ability
	 */
	public function ajax_import_ability() {
		check_ajax_referer('sflmcp_abilities', 'nonce');

		if (!current_user_can('manage_options')) {
			wp_send_json_error(array('message' => __('Permission denied', 'stifli-flex-mcp')));
		}

		$ability_name = isset($_POST['ability_name']) ? sanitize_text_field(wp_unslash($_POST['ability_name'])) : '';
		if (empty($ability_name)) {
			wp_send_json_error(array('message' => __('Ability name is required', 'stifli-flex-mcp')));
		}

		if (!stifli_flex_mcp_abilities_available()) {
			wp_send_json_error(array('message' => __('WordPress Abilities API not available', 'stifli-flex-mcp')));
		}

		// Use wp_get_ability() to get a specific ability
		$ability = wp_get_ability($ability_name);
		
		if (!$ability) {
			wp_send_json_error(array('message' => __('Ability not found', 'stifli-flex-mcp')));
		}

		global $wpdb;
		$table = $wpdb->prefix . 'sflmcp_abilities';

		// Check if already imported
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE ability_name = %s", $ability_name));
		if ($exists) {
			wp_send_json_error(array('message' => __('Ability already imported', 'stifli-flex-mcp')));
		}

		$input_schema = $ability->get_input_schema();
		$output_schema = $ability->get_output_schema();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $wpdb->insert(
			$table,
			array(
				'ability_name' => $ability_name,
				'ability_label' => $ability->get_label(),
				'ability_description' => $ability->get_description(),
				'ability_category' => $ability->get_category(),
				'input_schema' => is_array($input_schema) ? wp_json_encode($input_schema) : null,
				'output_schema' => is_array($output_schema) ? wp_json_encode($output_schema) : null,
				'enabled' => 1,
				'created_at' => current_time('mysql', true),
				'updated_at' => current_time('mysql', true),
			),
			array('%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s')
		);

		if ($result === false) {
			wp_send_json_error(array('message' => __('Failed to import ability', 'stifli-flex-mcp')));
		}

		wp_send_json_success(array(
			'message' => __('Ability imported successfully', 'stifli-flex-mcp'),
			'id' => $wpdb->insert_id,
		));
	}

	/**
	 * AJAX handler: Toggle ability enabled state
	 */
	public function ajax_toggle_ability() {
		check_ajax_referer('sflmcp_abilities', 'nonce');

		if (!current_user_can('manage_options')) {
			wp_send_json_error(array('message' => __('Permission denied', 'stifli-flex-mcp')));
		}

		$ability_id = isset($_POST['ability_id']) ? intval($_POST['ability_id']) : 0;
		if ($ability_id <= 0) {
			wp_send_json_error(array('message' => __('Invalid ability ID', 'stifli-flex-mcp')));
		}

		global $wpdb;
		$table = $wpdb->prefix . 'sflmcp_abilities';

		// Get current state
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$current = $wpdb->get_var($wpdb->prepare("SELECT enabled FROM {$table} WHERE id = %d", $ability_id));
		if ($current === null) {
			wp_send_json_error(array('message' => __('Ability not found', 'stifli-flex-mcp')));
		}

		$new_state = $current ? 0 : 1;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->update(
			$table,
			array('enabled' => $new_state, 'updated_at' => current_time('mysql', true)),
			array('id' => $ability_id),
			array('%d', '%s'),
			array('%d')
		);

		wp_send_json_success(array(
			'enabled' => $new_state,
			'message' => $new_state ? __('Ability enabled', 'stifli-flex-mcp') : __('Ability disabled', 'stifli-flex-mcp'),
		));
	}

	/**
	 * AJAX handler: Delete an imported ability
	 */
	public function ajax_delete_ability() {
		check_ajax_referer('sflmcp_abilities', 'nonce');

		if (!current_user_can('manage_options')) {
			wp_send_json_error(array('message' => __('Permission denied', 'stifli-flex-mcp')));
		}

		$ability_id = isset($_POST['ability_id']) ? intval($_POST['ability_id']) : 0;
		if ($ability_id <= 0) {
			wp_send_json_error(array('message' => __('Invalid ability ID', 'stifli-flex-mcp')));
		}

		global $wpdb;
		$table = $wpdb->prefix . 'sflmcp_abilities';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->delete($table, array('id' => $ability_id), array('%d'));

		if ($result === false) {
			wp_send_json_error(array('message' => __('Failed to delete ability', 'stifli-flex-mcp')));
		}

		wp_send_json_success(array('message' => __('Ability removed', 'stifli-flex-mcp')));
	}

	/**
	 * AJAX handler: Get imported abilities list (for refresh)
	 */
	public function ajax_get_imported_abilities() {
		check_ajax_referer('sflmcp_abilities', 'nonce');

		if (!current_user_can('manage_options')) {
			wp_send_json_error(array('message' => __('Permission denied', 'stifli-flex-mcp')));
		}

		ob_start();
		$this->renderImportedAbilitiesList();
		$html = ob_get_clean();

		wp_send_json_success(array('html' => $html));
	}

	/**
	 * Enqueue assets for the Multimedia submenu page.
	 */
	private function enqueueMultimediaAssets() {
		wp_enqueue_style(
			'sflmcp-admin-multimedia',
			plugin_dir_url(__FILE__) . 'assets/admin-multimedia.css',
			array(),
			'1.3.0'
		);
		wp_enqueue_script(
			'sflmcp-admin-multimedia',
			plugin_dir_url(__FILE__) . 'assets/admin-multimedia.js',
			array('jquery'),
			'1.6.0',
			true
		);
		wp_localize_script('sflmcp-admin-multimedia', 'sflmcpMultimedia', array(
			'ajaxUrl' => admin_url('admin-ajax.php'),
			'nonce'   => wp_create_nonce('sflmcp_multimedia'),
			'i18n'    => array(
				'saving'   => __('Saving...', 'stifli-flex-mcp'),
				'saved'    => __('Saved', 'stifli-flex-mcp'),
				'error'    => __('Error saving settings', 'stifli-flex-mcp'),
				'loaded'   => __('Settings loaded', 'stifli-flex-mcp'),
				'enabled'  => __('Enabled', 'stifli-flex-mcp'),
				'disabled' => __('Disabled', 'stifli-flex-mcp'),
			),
		));
	}

	/**
	 * Render the Multimedia admin page (standalone submenu).
	 */
	public function multimediaPage() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'stifli-flex-mcp' ) );
		}
		$active_tab = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : 'images'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Multimedia Settings', 'stifli-flex-mcp' ); ?></h1>
			<h2 class="nav-tab-wrapper">
				<a href="?page=sflmcp-multimedia&tab=images" class="nav-tab <?php echo $active_tab === 'images' ? 'nav-tab-active' : ''; ?>">
					🖼️ <?php esc_html_e( 'Images', 'stifli-flex-mcp' ); ?>
				</a>
				<a href="?page=sflmcp-multimedia&tab=videos" class="nav-tab <?php echo $active_tab === 'videos' ? 'nav-tab-active' : ''; ?>">
					🎬 <?php esc_html_e( 'Videos', 'stifli-flex-mcp' ); ?>
				</a>
			</h2>
			<?php
			if ( $active_tab === 'videos' ) {
				$this->renderVideoSettingsTab();
			} else {
				$this->renderMultimediaTab();
			}
			?>
		</div>
		<?php
	}

	/**
	 * Get multimedia settings with defaults.
	 *
	 * @return array Settings array merged with defaults.
	 */
	private function getMultimediaSettings() {
		$defaults = array(
			'image_provider'       => 'openai',
			// OpenAI image settings
			'openai_api_key'       => '',
			'openai_model'         => 'gpt-image-1',
			'openai_quality'       => 'medium',
			'openai_size'          => 'square',
			'openai_style'         => 'natural',
			'openai_background'    => 'auto',
			'openai_output_format' => 'png',
			// Gemini image settings
			'gemini_api_key'       => '',
			'gemini_model'         => 'gemini-2.5-flash-image',
			'gemini_aspect_ratio'  => '1:1',
			// Post-processing
			'pp_enabled'           => '1',
			'pp_max_width'         => 1024,
			'pp_max_height'        => 1024,
			'pp_quality'           => 80,
			'pp_format'            => 'original',
			// Video settings
			'video_provider'       => 'gemini',
			'video_gemini_model'   => 'veo-3.0-generate-preview',
			'video_openai_model'   => 'sora-2',
			'video_duration'       => '5',
			'video_aspect_ratio'   => '16:9',
			'video_resolution'     => '720p',
			'video_poll_interval'  => 10,
			'video_max_wait'       => 300,
		);
		$saved = get_option( 'sflmcp_multimedia_settings', array() );
		return wp_parse_args( $saved, $defaults );
	}

	/**
	 * Create a partial display mask for an encrypted API key.
	 *
	 * Decrypts the stored key, then returns a masked version showing the first
	 * few characters and last 4, with bullets in between matching the real length.
	 * Example: "sk-proj-••••••••••••••••abcd"
	 *
	 * @param string $encrypted_value The encrypted (or empty) key from settings.
	 * @return string Partial mask for display, or empty string if no key stored.
	 */
	private function maskApiKeyForDisplay( $encrypted_value ) {
		if ( empty( $encrypted_value ) ) {
			return '';
		}

		// Decrypt to get real key
		$plain = '';
		if ( class_exists( 'StifliFlexMcp_Client_Admin' ) ) {
			$plain = StifliFlexMcp_Client_Admin::decrypt_value( $encrypted_value );
		} else {
			$plain = $encrypted_value;
		}

		if ( empty( $plain ) ) {
			return '';
		}

		$len = strlen( $plain );

		// Very short keys: just show bullets
		if ( $len <= 8 ) {
			return str_repeat( '•', $len );
		}

		// Show first 4 chars + bullets + last 4 chars
		$prefix    = substr( $plain, 0, 4 );
		$suffix    = substr( $plain, -4 );
		$mid_count = max( 4, $len - 8 );
		return $prefix . str_repeat( '•', $mid_count ) . $suffix;
	}

	/**
	 * Render Multimedia Settings Tab
	 */
	private function renderMultimediaTab() {
		$s       = $this->getMultimediaSettings();
		$has_gd  = extension_loaded( 'gd' );
		$gd_info = $has_gd ? gd_info() : array();

		// Build partial display strings for API keys (e.g. "sk-••••••••xxxx")
		$openai_display = $this->maskApiKeyForDisplay( $s['openai_api_key'] );
		$gemini_display = $this->maskApiKeyForDisplay( $s['gemini_api_key'] );

		?>
		<div class="sflmcp-multimedia-wrap">

			<!-- ─── Tool Enable/Disable ─────────────────────── -->
			<div class="sflmcp-tool-toggle-banner" data-tool="wp_generate_image">
				<label class="sflmcp-toggle-switch">
					<input type="checkbox" id="sflmcp_mm_tool_image_toggle" class="sflmcp-mm-tool-toggle" data-tool="wp_generate_image">
					<span class="sflmcp-toggle-slider"></span>
				</label>
				<span class="sflmcp-toggle-label">
					<strong>wp_generate_image</strong> — <?php esc_html_e( 'Enable this tool for MCP clients and AI agents', 'stifli-flex-mcp' ); ?>
				</span>
				<span class="sflmcp-toggle-status"></span>
			</div>
			<p class="description sflmcp-pricing-notice">
				<span class="dashicons dashicons-info" class="sflmcp-pricing-icon"></span>
				<?php esc_html_e( 'Approximate cost per image: OpenAI GPT Image 1: $0.01–$0.25 (varies by quality: low/medium/high and size). DALL·E 3: $0.04–$0.12. DALL·E 2: ~$0.02. Google Imagen 4: $0.02 (fast), $0.04 (standard), $0.06 (ultra). Prices may change — please consult each provider\'s pricing page for up-to-date rates.', 'stifli-flex-mcp' ); ?>
			</p>

			<h2><?php esc_html_e( 'Image Generation Settings', 'stifli-flex-mcp' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Configure defaults for AI image generation (wp_generate_image tool). These settings are used when generating images via MCP tools or the Chat Agent. Tool arguments can override size and quality per-request.', 'stifli-flex-mcp' ); ?>
			</p>

			<div id="sflmcp-mm-notice" class="sflmcp-mm-notice"></div>

			<form id="sflmcp-multimedia-form">

				<!-- ─── Image Provider ───────────────────────────── -->
				<div class="card">
					<h3>🖼️ <?php esc_html_e( 'Image Generation Provider', 'stifli-flex-mcp' ); ?></h3>
					<p class="description"><?php esc_html_e( 'Select which AI provider to use for image generation. Each provider has its own API key and configuration.', 'stifli-flex-mcp' ); ?></p>

					<input type="hidden" id="sflmcp_mm_image_provider" name="image_provider" value="<?php echo esc_attr( $s['image_provider'] ); ?>">

					<div class="sflmcp-provider-tabs">
						<div class="sflmcp-provider-tab <?php echo $s['image_provider'] === 'openai' ? 'active' : ''; ?>" data-provider="openai">
							<span class="dashicons dashicons-format-image"></span> OpenAI
						</div>
						<div class="sflmcp-provider-tab <?php echo $s['image_provider'] === 'gemini' ? 'active' : ''; ?>" data-provider="gemini">
							<span class="dashicons dashicons-admin-customizer"></span> Gemini
						</div>
					</div>
				</div>

				<!-- ─── OpenAI Settings ──────────────────────────── -->
				<div id="sflmcp-panel-openai" class="sflmcp-provider-panel card<?php echo $s['image_provider'] !== 'openai' ? ' sflmcp-hidden' : ''; ?>">
					<h3><span class="dashicons dashicons-format-image"></span> <?php esc_html_e( 'OpenAI Image Settings', 'stifli-flex-mcp' ); ?></h3>

					<table class="form-table">
						<tr>
							<th><?php esc_html_e( 'API Key', 'stifli-flex-mcp' ); ?></th>
							<td>
								<div class="sflmcp-api-key-field">
									<input type="password" id="sflmcp_mm_openai_key" class="sflmcp-shared-apikey" data-key="openai_api_key" value="<?php echo esc_attr( $openai_display ); ?>" placeholder="sk-..." autocomplete="off">
									<button type="button" class="button sflmcp-api-key-toggle" title="<?php esc_attr_e( 'Toggle visibility', 'stifli-flex-mcp' ); ?>"><span class="dashicons dashicons-visibility"></span></button>
								</div>
								<p class="sflmcp-field-desc">
									<?php esc_html_e( 'Shared OpenAI key for image and video generation. Independent from Chat Agent settings.', 'stifli-flex-mcp' ); ?>
								</p>
							</td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Model', 'stifli-flex-mcp' ); ?></th>
							<td>
								<select id="sflmcp_mm_openai_model">
									<option value="gpt-image-1" <?php selected( $s['openai_model'], 'gpt-image-1' ); ?>>gpt-image-1 (<?php esc_html_e( 'Latest, best quality', 'stifli-flex-mcp' ); ?>)</option>
									<option value="dall-e-3" <?php selected( $s['openai_model'], 'dall-e-3' ); ?>>DALL·E 3 (<?php esc_html_e( 'Stable, no verification needed', 'stifli-flex-mcp' ); ?>)</option>
									<option value="dall-e-2" <?php selected( $s['openai_model'], 'dall-e-2' ); ?>>DALL·E 2 (<?php esc_html_e( 'Legacy, cheapest', 'stifli-flex-mcp' ); ?>)</option>
								</select>
							</td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Default Quality', 'stifli-flex-mcp' ); ?></th>
							<td>
								<select id="sflmcp_mm_openai_quality">
									<option value="low" <?php selected( $s['openai_quality'], 'low' ); ?>><?php esc_html_e( 'Low (fastest, cheapest)', 'stifli-flex-mcp' ); ?></option>
									<option value="medium" <?php selected( $s['openai_quality'], 'medium' ); ?>><?php esc_html_e( 'Medium (balanced)', 'stifli-flex-mcp' ); ?></option>
									<option value="high" <?php selected( $s['openai_quality'], 'high' ); ?>><?php esc_html_e( 'High (best quality, slowest)', 'stifli-flex-mcp' ); ?></option>
								</select>
							</td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Default Size', 'stifli-flex-mcp' ); ?></th>
							<td>
								<select id="sflmcp_mm_openai_size">
									<option value="square" <?php selected( $s['openai_size'], 'square' ); ?>><?php esc_html_e( 'Square (1024×1024)', 'stifli-flex-mcp' ); ?></option>
									<option value="landscape" <?php selected( $s['openai_size'], 'landscape' ); ?>><?php esc_html_e( 'Landscape (1536×1024)', 'stifli-flex-mcp' ); ?></option>
									<option value="portrait" <?php selected( $s['openai_size'], 'portrait' ); ?>><?php esc_html_e( 'Portrait (1024×1536)', 'stifli-flex-mcp' ); ?></option>
								</select>
								<p class="sflmcp-field-desc"><?php esc_html_e( 'The tool can override this per-request via the "size" argument.', 'stifli-flex-mcp' ); ?></p>
							</td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Style', 'stifli-flex-mcp' ); ?></th>
							<td>
								<select id="sflmcp_mm_openai_style">
									<option value="natural" <?php selected( $s['openai_style'], 'natural' ); ?>><?php esc_html_e( 'Natural (photorealistic)', 'stifli-flex-mcp' ); ?></option>
									<option value="vivid" <?php selected( $s['openai_style'], 'vivid' ); ?>><?php esc_html_e( 'Vivid (hyper-real, dramatic)', 'stifli-flex-mcp' ); ?></option>
								</select>
								<p class="sflmcp-field-desc"><?php esc_html_e( 'Only applies to DALL·E 3.', 'stifli-flex-mcp' ); ?></p>
							</td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Background', 'stifli-flex-mcp' ); ?></th>
							<td>
								<select id="sflmcp_mm_openai_background">
									<option value="auto" <?php selected( $s['openai_background'], 'auto' ); ?>><?php esc_html_e( 'Auto', 'stifli-flex-mcp' ); ?></option>
									<option value="transparent" <?php selected( $s['openai_background'], 'transparent' ); ?>><?php esc_html_e( 'Transparent', 'stifli-flex-mcp' ); ?></option>
									<option value="opaque" <?php selected( $s['openai_background'], 'opaque' ); ?>><?php esc_html_e( 'Opaque', 'stifli-flex-mcp' ); ?></option>
								</select>
								<p class="sflmcp-field-desc"><?php esc_html_e( 'Only for gpt-image-1. Use Transparent for logos/icons with PNG output.', 'stifli-flex-mcp' ); ?></p>
							</td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Output Format', 'stifli-flex-mcp' ); ?></th>
							<td>
								<select id="sflmcp_mm_openai_format">
									<option value="png" <?php selected( $s['openai_output_format'], 'png' ); ?>>PNG (<?php esc_html_e( 'lossless, supports transparency', 'stifli-flex-mcp' ); ?>)</option>
									<option value="jpeg" <?php selected( $s['openai_output_format'], 'jpeg' ); ?>>JPEG (<?php esc_html_e( 'smaller file, no transparency', 'stifli-flex-mcp' ); ?>)</option>
									<option value="webp" <?php selected( $s['openai_output_format'], 'webp' ); ?>>WebP (<?php esc_html_e( 'modern, best compression', 'stifli-flex-mcp' ); ?>)</option>
								</select>
								<p class="sflmcp-field-desc"><?php esc_html_e( 'Only for gpt-image-1. DALL·E models always return PNG.', 'stifli-flex-mcp' ); ?></p>
							</td>
						</tr>
					</table>
				</div>

				<!-- ─── Gemini Settings ──────────────────────────── -->
				<div id="sflmcp-panel-gemini" class="sflmcp-provider-panel card<?php echo $s['image_provider'] !== 'gemini' ? ' sflmcp-hidden' : ''; ?>">
					<h3><span class="dashicons dashicons-admin-customizer"></span> <?php esc_html_e( 'Gemini Image Settings', 'stifli-flex-mcp' ); ?></h3>

					<table class="form-table">
						<tr>
							<th><?php esc_html_e( 'API Key', 'stifli-flex-mcp' ); ?></th>
							<td>
								<div class="sflmcp-api-key-field">
									<input type="password" id="sflmcp_mm_gemini_key" class="sflmcp-shared-apikey" data-key="gemini_api_key" value="<?php echo esc_attr( $gemini_display ); ?>" placeholder="AIza..." autocomplete="off">
									<button type="button" class="button sflmcp-api-key-toggle" title="<?php esc_attr_e( 'Toggle visibility', 'stifli-flex-mcp' ); ?>"><span class="dashicons dashicons-visibility"></span></button>
								</div>
								<p class="sflmcp-field-desc">
									<?php esc_html_e( 'Shared Gemini key for image and video generation. Independent from Chat Agent settings.', 'stifli-flex-mcp' ); ?>
								</p>
							</td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Model', 'stifli-flex-mcp' ); ?></th>
							<td>
								<select id="sflmcp_mm_gemini_model">
									<option value="gemini-2.5-flash-image" <?php selected( $s['gemini_model'], 'gemini-2.5-flash-image' ); ?>>gemini-2.5-flash-image (<?php esc_html_e( 'Fast, native generation', 'stifli-flex-mcp' ); ?>)</option>
									<option value="imagen-4.0-generate-001" <?php selected( $s['gemini_model'], 'imagen-4.0-generate-001' ); ?>>Imagen 4 Standard (<?php esc_html_e( 'High fidelity', 'stifli-flex-mcp' ); ?>)</option>
									<option value="imagen-4.0-fast-generate-001" <?php selected( $s['gemini_model'], 'imagen-4.0-fast-generate-001' ); ?>>Imagen 4 Fast (<?php esc_html_e( 'Fastest', 'stifli-flex-mcp' ); ?>)</option>
									<option value="imagen-4.0-ultra-generate-001" <?php selected( $s['gemini_model'], 'imagen-4.0-ultra-generate-001' ); ?>>Imagen 4 Ultra (<?php esc_html_e( 'Best quality', 'stifli-flex-mcp' ); ?>)</option>
								</select>
							</td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Default Aspect Ratio', 'stifli-flex-mcp' ); ?></th>
							<td>
								<select id="sflmcp_mm_gemini_aspect">
									<option value="1:1" <?php selected( $s['gemini_aspect_ratio'], '1:1' ); ?>>1:1 (<?php esc_html_e( 'Square', 'stifli-flex-mcp' ); ?>)</option>
									<option value="16:9" <?php selected( $s['gemini_aspect_ratio'], '16:9' ); ?>>16:9 (<?php esc_html_e( 'Landscape', 'stifli-flex-mcp' ); ?>)</option>
									<option value="9:16" <?php selected( $s['gemini_aspect_ratio'], '9:16' ); ?>>9:16 (<?php esc_html_e( 'Portrait', 'stifli-flex-mcp' ); ?>)</option>
									<option value="4:3" <?php selected( $s['gemini_aspect_ratio'], '4:3' ); ?>>4:3 (<?php esc_html_e( 'Classic', 'stifli-flex-mcp' ); ?>)</option>
									<option value="3:4" <?php selected( $s['gemini_aspect_ratio'], '3:4' ); ?>>3:4 (<?php esc_html_e( 'Portrait Classic', 'stifli-flex-mcp' ); ?>)</option>
									<option value="3:2" <?php selected( $s['gemini_aspect_ratio'], '3:2' ); ?>>3:2</option>
									<option value="2:3" <?php selected( $s['gemini_aspect_ratio'], '2:3' ); ?>>2:3</option>
								</select>
								<p class="sflmcp-field-desc"><?php esc_html_e( 'The tool can override this per-request via the "size" argument.', 'stifli-flex-mcp' ); ?></p>
							</td>
						</tr>
					</table>
				</div>

				<!-- ─── Post-Processing ──────────────────────────── -->
				<div class="card sflmcp-postprocess-section <?php echo $s['pp_enabled'] !== '1' ? 'disabled' : ''; ?>">
					<h3>⚙️ <?php esc_html_e( 'Image Post-Processing', 'stifli-flex-mcp' ); ?></h3>
					<p class="description"><?php esc_html_e( 'Automatically compress/resize AI-generated images before saving to the Media Library. Uses GD or the WordPress image editor.', 'stifli-flex-mcp' ); ?></p>

					<?php if ( $has_gd ) : ?>
						<div class="sflmcp-gd-ok">
							✅ <?php
							/* translators: %s: GD library version string */
							echo esc_html( sprintf( __( 'GD Library available (version: %s). JPEG, PNG, and WebP processing supported.', 'stifli-flex-mcp' ), isset( $gd_info['GD Version'] ) ? $gd_info['GD Version'] : 'unknown' ) ); ?>
						</div>
					<?php else : ?>
						<div class="sflmcp-gd-warning">
							⚠️ <?php esc_html_e( 'GD Library not detected. Post-processing will use the WordPress image editor (Imagick or fallback).', 'stifli-flex-mcp' ); ?>
						</div>
					<?php endif; ?>

					<table class="form-table">
						<tr>
							<th><?php esc_html_e( 'Enable Post-Processing', 'stifli-flex-mcp' ); ?></th>
							<td>
								<label>
									<input type="checkbox" id="sflmcp_mm_pp_enabled" <?php checked( $s['pp_enabled'], '1' ); ?>>
									<?php esc_html_e( 'Compress and/or resize images after generation', 'stifli-flex-mcp' ); ?>
								</label>
							</td>
						</tr>
					</table>

					<div class="sflmcp-postprocess-fields <?php echo $s['pp_enabled'] !== '1' ? 'hidden' : ''; ?>">
						<table class="form-table">
							<tr>
								<th><?php esc_html_e( 'Max Width (px)', 'stifli-flex-mcp' ); ?></th>
								<td>
									<input type="number" id="sflmcp_mm_pp_max_width" value="<?php echo esc_attr( $s['pp_max_width'] ); ?>" min="256" max="4096" step="1">
									<p class="sflmcp-field-desc"><?php esc_html_e( 'Images wider than this will be proportionally resized. Set 0 for no limit.', 'stifli-flex-mcp' ); ?></p>
								</td>
							</tr>
							<tr>
								<th><?php esc_html_e( 'Max Height (px)', 'stifli-flex-mcp' ); ?></th>
								<td>
									<input type="number" id="sflmcp_mm_pp_max_height" value="<?php echo esc_attr( $s['pp_max_height'] ); ?>" min="256" max="4096" step="1">
									<p class="sflmcp-field-desc"><?php esc_html_e( 'Images taller than this will be proportionally resized. Set 0 for no limit.', 'stifli-flex-mcp' ); ?></p>
								</td>
							</tr>
							<tr>
								<th><?php esc_html_e( 'Compression Quality', 'stifli-flex-mcp' ); ?></th>
								<td>
									<div class="sflmcp-range-field">
										<input type="range" id="sflmcp_mm_pp_quality" min="30" max="100" value="<?php echo esc_attr( $s['pp_quality'] ); ?>">
										<span id="sflmcp_mm_pp_quality_val" class="sflmcp-range-value"><?php echo esc_html( $s['pp_quality'] ); ?>%</span>
									</div>
									<p class="sflmcp-field-desc"><?php esc_html_e( 'JPEG/WebP quality. Lower = smaller file, less detail. 75-85 is recommended.', 'stifli-flex-mcp' ); ?></p>
								</td>
							</tr>
							<tr>
								<th><?php esc_html_e( 'Convert Format', 'stifli-flex-mcp' ); ?></th>
								<td>
									<select id="sflmcp_mm_pp_format">
										<option value="original" <?php selected( $s['pp_format'], 'original' ); ?>><?php esc_html_e( 'Keep Original Format', 'stifli-flex-mcp' ); ?></option>
										<option value="jpeg" <?php selected( $s['pp_format'], 'jpeg' ); ?>><?php esc_html_e( 'Convert to JPEG (smallest, no transparency)', 'stifli-flex-mcp' ); ?></option>
										<option value="webp" <?php selected( $s['pp_format'], 'webp' ); ?>><?php esc_html_e( 'Convert to WebP (modern, good compression)', 'stifli-flex-mcp' ); ?></option>
										<option value="png" <?php selected( $s['pp_format'], 'png' ); ?>><?php esc_html_e( 'Convert to PNG (lossless, preserves transparency)', 'stifli-flex-mcp' ); ?></option>
									</select>
								</td>
							</tr>
						</table>
					</div>
				</div>

				<div id="sflmcp-mm-autosave-status" class="sflmcp-autosave-status"></div>
			</form>
		</div>
		<?php
	}

	/**
	 * Render Video Settings Tab
	 */
	private function renderVideoSettingsTab() {
		$s = $this->getMultimediaSettings();

		// Build partial display strings for API keys (same shared keys as image tab)
		$openai_display = $this->maskApiKeyForDisplay( $s['openai_api_key'] );
		$gemini_display = $this->maskApiKeyForDisplay( $s['gemini_api_key'] );
		?>
		<div class="sflmcp-multimedia-wrap">

			<!-- ─── Tool Enable/Disable ─────────────────────── -->
			<div class="sflmcp-tool-toggle-banner" data-tool="wp_generate_video">
				<label class="sflmcp-toggle-switch">
					<input type="checkbox" id="sflmcp_mm_tool_video_toggle" class="sflmcp-mm-tool-toggle" data-tool="wp_generate_video">
					<span class="sflmcp-toggle-slider"></span>
				</label>
				<span class="sflmcp-toggle-label">
					<strong>wp_generate_video</strong> — <?php esc_html_e( 'Enable this tool for MCP clients and AI agents', 'stifli-flex-mcp' ); ?>
				</span>
				<span class="sflmcp-toggle-status"></span>
			</div>
			<p class="description sflmcp-pricing-notice">
				<span class="dashicons dashicons-info" class="sflmcp-pricing-icon"></span>
				<?php esc_html_e( 'Approximate cost per video: OpenAI Sora is billed per second — sora-2: $0.10/s, sora-2-pro: $0.30–$0.50/s (e.g. a 5s video costs $0.50–$2.50). Google Veo 2: $0.35/video, Veo 3: $0.15–$0.40/video, Veo 3.1: $0.15–$0.60/video (varies by resolution). Video generation is significantly more expensive than images. Prices may change — please consult each provider\'s pricing page for up-to-date rates.', 'stifli-flex-mcp' ); ?>
			</p>

			<h2><?php esc_html_e( 'Video Generation Settings', 'stifli-flex-mcp' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Configure defaults for AI video generation (wp_generate_video tool). Video generation is asynchronous — the tool submits the request, polls for completion, then saves the result as a WordPress media attachment.', 'stifli-flex-mcp' ); ?>
			</p>

			<div id="sflmcp-mm-notice" class="sflmcp-mm-notice"></div>

			<form id="sflmcp-multimedia-form-video" class="sflmcp-multimedia-form">

				<!-- ─── Video Provider ────────────────────────────── -->
				<div class="card">
					<h3>🎬 <?php esc_html_e( 'Video Generation Provider', 'stifli-flex-mcp' ); ?></h3>
					<p class="description"><?php esc_html_e( 'Select which AI provider to use for video generation. Each has its own API key, models, and pricing.', 'stifli-flex-mcp' ); ?></p>

					<input type="hidden" id="sflmcp_mm_video_provider" name="video_provider" value="<?php echo esc_attr( $s['video_provider'] ); ?>">

					<div class="sflmcp-provider-tabs">
						<div class="sflmcp-provider-tab <?php echo $s['video_provider'] === 'gemini' ? 'active' : ''; ?>" data-provider="gemini">
							<span class="dashicons dashicons-admin-customizer"></span> Google Veo
						</div>
						<div class="sflmcp-provider-tab <?php echo $s['video_provider'] === 'openai' ? 'active' : ''; ?>" data-provider="openai">
							<span class="dashicons dashicons-video-alt3"></span> OpenAI Sora
						</div>
					</div>
				</div>

				<!-- ─── Gemini / Veo Settings ──────────────────────── -->
				<div id="sflmcp-panel-gemini" class="sflmcp-provider-panel card<?php echo $s['video_provider'] !== 'gemini' ? ' sflmcp-hidden' : ''; ?>">
					<h3><span class="dashicons dashicons-admin-customizer"></span> <?php esc_html_e( 'Google Veo Settings', 'stifli-flex-mcp' ); ?></h3>

					<table class="form-table">
						<tr>
							<th><?php esc_html_e( 'API Key', 'stifli-flex-mcp' ); ?></th>
							<td>
								<div class="sflmcp-api-key-field">
									<input type="password" id="sflmcp_mm_video_gemini_key" class="sflmcp-shared-apikey" data-key="gemini_api_key" value="<?php echo esc_attr( $gemini_display ); ?>" placeholder="AIza..." autocomplete="off">
									<button type="button" class="button sflmcp-api-key-toggle" title="<?php esc_attr_e( 'Toggle visibility', 'stifli-flex-mcp' ); ?>"><span class="dashicons dashicons-visibility"></span></button>
								</div>
								<p class="sflmcp-field-desc">
									<?php esc_html_e( 'Same Gemini key used for image generation. Changes here update the shared key.', 'stifli-flex-mcp' ); ?>
								</p>
							</td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Model', 'stifli-flex-mcp' ); ?></th>
							<td>
								<select id="sflmcp_mm_video_gemini_model">
									<option value="veo-3.0-generate-preview" <?php selected( $s['video_gemini_model'], 'veo-3.0-generate-preview' ); ?>>Veo 3 Preview (<?php esc_html_e( 'Latest, best quality with audio', 'stifli-flex-mcp' ); ?>)</option>
									<option value="veo-2.0-generate-001" <?php selected( $s['video_gemini_model'], 'veo-2.0-generate-001' ); ?>>Veo 2 (<?php esc_html_e( 'Stable, 480p-720p', 'stifli-flex-mcp' ); ?>)</option>
								</select>
								<p class="sflmcp-field-desc"><?php esc_html_e( 'Veo 3 generates videos up to 8s with audio. Veo 2 generates silent video.', 'stifli-flex-mcp' ); ?></p>
							</td>
						</tr>
					</table>
				</div>

				<!-- ─── OpenAI / Sora Settings ──────────────────── -->
				<div id="sflmcp-panel-openai" class="sflmcp-provider-panel card<?php echo $s['video_provider'] !== 'openai' ? ' sflmcp-hidden' : ''; ?>">
					<h3><span class="dashicons dashicons-video-alt3"></span> <?php esc_html_e( 'OpenAI Sora Settings', 'stifli-flex-mcp' ); ?></h3>

					<table class="form-table">
						<tr>
							<th><?php esc_html_e( 'API Key', 'stifli-flex-mcp' ); ?></th>
							<td>
								<div class="sflmcp-api-key-field">
									<input type="password" id="sflmcp_mm_video_openai_key" class="sflmcp-shared-apikey" data-key="openai_api_key" value="<?php echo esc_attr( $openai_display ); ?>" placeholder="sk-..." autocomplete="off">
									<button type="button" class="button sflmcp-api-key-toggle" title="<?php esc_attr_e( 'Toggle visibility', 'stifli-flex-mcp' ); ?>"><span class="dashicons dashicons-visibility"></span></button>
								</div>
								<p class="sflmcp-field-desc">
									<?php esc_html_e( 'Same OpenAI key used for image generation. Changes here update the shared key.', 'stifli-flex-mcp' ); ?>
								</p>
							</td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Model', 'stifli-flex-mcp' ); ?></th>
							<td>
								<select id="sflmcp_mm_video_openai_model">
									<option value="sora-2" <?php selected( $s['video_openai_model'], 'sora-2' ); ?>>Sora 2 (<?php esc_html_e( 'Fast, flexible, good quality', 'stifli-flex-mcp' ); ?>)</option>
									<option value="sora-2-pro" <?php selected( $s['video_openai_model'], 'sora-2-pro' ); ?>>Sora 2 Pro (<?php esc_html_e( 'Higher quality, slower', 'stifli-flex-mcp' ); ?>)</option>
								</select>
							</td>
						</tr>
					</table>
				</div>

				<!-- ─── Common Video Settings ────────────────────── -->
				<div class="card">
					<h3>⚙️ <?php esc_html_e( 'Default Video Parameters', 'stifli-flex-mcp' ); ?></h3>
					<p class="description"><?php esc_html_e( 'These defaults are used when the tool is called without explicit arguments. The tool can override per-request.', 'stifli-flex-mcp' ); ?></p>

					<table class="form-table">
						<tr>
							<th><?php esc_html_e( 'Duration', 'stifli-flex-mcp' ); ?></th>
							<td>
								<select id="sflmcp_mm_video_duration">
									<option value="4" <?php selected( $s['video_duration'], '4' ); ?>>4s (<?php esc_html_e( 'Sora only', 'stifli-flex-mcp' ); ?>)</option>
									<option value="5" <?php selected( $s['video_duration'], '5' ); ?>>5s (<?php esc_html_e( 'Veo only', 'stifli-flex-mcp' ); ?>)</option>
									<option value="6" <?php selected( $s['video_duration'], '6' ); ?>>6s (<?php esc_html_e( 'Veo only', 'stifli-flex-mcp' ); ?>)</option>
									<option value="8" <?php selected( $s['video_duration'], '8' ); ?>>8s</option>
									<option value="12" <?php selected( $s['video_duration'], '12' ); ?>>12s (<?php esc_html_e( 'Sora only', 'stifli-flex-mcp' ); ?>)</option>
								</select>
								<p class="sflmcp-field-desc"><?php esc_html_e( 'Default video length. Veo supports 5-8s, Sora supports 4, 8, or 12s. Nearest valid value is used per provider.', 'stifli-flex-mcp' ); ?></p>
							</td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Aspect Ratio', 'stifli-flex-mcp' ); ?></th>
							<td>
								<select id="sflmcp_mm_video_aspect">
									<option value="16:9" <?php selected( $s['video_aspect_ratio'], '16:9' ); ?>>16:9 (<?php esc_html_e( 'Landscape / YouTube', 'stifli-flex-mcp' ); ?>)</option>
									<option value="9:16" <?php selected( $s['video_aspect_ratio'], '9:16' ); ?>>9:16 (<?php esc_html_e( 'Portrait / Reels', 'stifli-flex-mcp' ); ?>)</option>
									<option value="1:1" <?php selected( $s['video_aspect_ratio'], '1:1' ); ?>>1:1 (<?php esc_html_e( 'Square', 'stifli-flex-mcp' ); ?>)</option>
								</select>
							</td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Resolution', 'stifli-flex-mcp' ); ?></th>
							<td>
								<select id="sflmcp_mm_video_resolution">
									<option value="480p" <?php selected( $s['video_resolution'], '480p' ); ?>>480p (<?php esc_html_e( 'Faster, smaller files', 'stifli-flex-mcp' ); ?>)</option>
									<option value="720p" <?php selected( $s['video_resolution'], '720p' ); ?>>720p (<?php esc_html_e( 'Balanced', 'stifli-flex-mcp' ); ?>)</option>
									<option value="1080p" <?php selected( $s['video_resolution'], '1080p' ); ?>>1080p (<?php esc_html_e( 'Full HD', 'stifli-flex-mcp' ); ?>)</option>
								</select>
								<p class="sflmcp-field-desc"><?php esc_html_e( 'Not all providers support all resolutions. Veo supports up to 720p. Sora uses 1280x720 / 720x1280.', 'stifli-flex-mcp' ); ?></p>
							</td>
						</tr>
					</table>
				</div>

				<!-- ─── Async Parameters ─────────────────────────── -->
				<div class="card">
					<h3>⏱ <?php esc_html_e( 'Generation Timeout', 'stifli-flex-mcp' ); ?></h3>
					<p class="description"><?php esc_html_e( 'Video generation is asynchronous. The tool polls the provider API until completion or timeout.', 'stifli-flex-mcp' ); ?></p>

					<table class="form-table">
						<tr>
							<th><?php esc_html_e( 'Poll Interval (seconds)', 'stifli-flex-mcp' ); ?></th>
							<td>
								<input type="number" id="sflmcp_mm_video_poll" value="<?php echo esc_attr( $s['video_poll_interval'] ); ?>" min="5" max="60" step="1">
								<p class="sflmcp-field-desc"><?php esc_html_e( 'How often to check if the video is ready. Lower = faster detection but more API calls.', 'stifli-flex-mcp' ); ?></p>
							</td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Max Wait (seconds)', 'stifli-flex-mcp' ); ?></th>
							<td>
								<input type="number" id="sflmcp_mm_video_max_wait" value="<?php echo esc_attr( $s['video_max_wait'] ); ?>" min="60" max="600" step="10">
								<p class="sflmcp-field-desc"><?php esc_html_e( 'Maximum time to wait for video generation. Typical: 1-5 minutes. If exceeded, the tool returns an error.', 'stifli-flex-mcp' ); ?></p>
							</td>
						</tr>
					</table>
				</div>

				<div id="sflmcp-mm-autosave-status" class="sflmcp-autosave-status"></div>
			</form>
		</div>
		<?php
	}

	/**
	 * AJAX handler: Save multimedia settings.
	 */
	public function ajax_save_multimedia_settings() {
		check_ajax_referer( 'sflmcp_multimedia', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied', 'stifli-flex-mcp' ) ) );
		}

		// Allowed values for enum fields
		$allowed_enums = array(
			'image_provider'       => array( 'openai', 'gemini' ),
			'openai_model'         => array( 'gpt-image-1', 'dall-e-3', 'dall-e-2' ),
			'openai_quality'       => array( 'low', 'medium', 'high' ),
			'openai_size'          => array( 'square', 'landscape', 'portrait' ),
			'openai_style'         => array( 'natural', 'vivid' ),
			'openai_background'    => array( 'auto', 'transparent', 'opaque' ),
			'openai_output_format' => array( 'png', 'jpeg', 'webp' ),
			'gemini_model'         => array( 'gemini-2.5-flash-image', 'imagen-4.0-generate-001', 'imagen-4.0-fast-generate-001', 'imagen-4.0-ultra-generate-001' ),
			'gemini_aspect_ratio'  => array( '1:1', '16:9', '9:16', '4:3', '3:4', '3:2', '2:3' ),
			'pp_format'            => array( 'original', 'jpeg', 'webp', 'png' ),
			'video_provider'       => array( 'gemini', 'openai' ),
			'video_gemini_model'   => array( 'veo-3.0-generate-preview', 'veo-2.0-generate-001' ),
			'video_openai_model'   => array( 'sora-2', 'sora-2-pro' ),
			'video_duration'       => array( '4', '5', '6', '8', '12' ),
			'video_aspect_ratio'   => array( '16:9', '9:16', '1:1' ),
			'video_resolution'     => array( '480p', '720p', '1080p' ),
		);

		// Numeric fields: key => array( min, max )
		$numeric_fields = array(
			'pp_max_width'         => array( 0, 4096 ),
			'pp_max_height'        => array( 0, 4096 ),
			'pp_quality'           => array( 30, 100 ),
			'video_poll_interval'  => array( 5, 60 ),
			'video_max_wait'       => array( 60, 600 ),
		);

		// Start from existing settings (partial merge — only update fields present in POST)
		$settings = get_option( 'sflmcp_multimedia_settings', array() );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce verified above via check_ajax_referer

		// Update enum fields only if present in POST
		foreach ( $allowed_enums as $key => $values ) {
			if ( isset( $_POST[ $key ] ) ) {
				$val = sanitize_text_field( wp_unslash( $_POST[ $key ] ) );
				if ( in_array( $val, $values, true ) ) {
					$settings[ $key ] = $val;
				}
			}
		}

		// Update checkbox field
		if ( isset( $_POST['pp_enabled'] ) ) {
			$settings['pp_enabled'] = sanitize_text_field( wp_unslash( $_POST['pp_enabled'] ) ) === '1' ? '1' : '0';
		}

		// Update numeric fields only if present in POST
		foreach ( $numeric_fields as $key => $range ) {
			if ( isset( $_POST[ $key ] ) ) {
				$settings[ $key ] = max( $range[0], min( $range[1], intval( $_POST[ $key ] ) ) );
			}
		}

		// Handle API keys — only update if user entered a real value (not the masked placeholder)
		$api_key_fields = array( 'openai_api_key', 'gemini_api_key' );
		foreach ( $api_key_fields as $key ) {
			if ( isset( $_POST[ $key ] ) ) {
				$raw = sanitize_text_field( wp_unslash( $_POST[ $key ] ) );
				// Skip if empty or contains bullet chars (masked value, not a real new key)
				if ( ! empty( $raw ) && strpos( $raw, '•' ) === false ) {
					if ( class_exists( 'StifliFlexMcp_Client_Admin' ) ) {
						$ref = new ReflectionMethod( 'StifliFlexMcp_Client_Admin', 'encrypt_value' );
						$ref->setAccessible( true );
						$settings[ $key ] = $ref->invoke( null, $raw );
					} else {
						$settings[ $key ] = $raw;
					}
				}
				// If empty or masked, keep existing value — do not touch $settings[$key]
			}
		}

		// phpcs:enable WordPress.Security.NonceVerification.Missing

		update_option( 'sflmcp_multimedia_settings', $settings );

		wp_send_json_success( array( 'message' => __( 'Settings saved', 'stifli-flex-mcp' ) ) );
	}

	/**
	 * AJAX handler: Load multimedia settings.
	 */
	public function ajax_load_multimedia_settings() {
		check_ajax_referer( 'sflmcp_multimedia', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied', 'stifli-flex-mcp' ) ) );
		}

		$s = $this->getMultimediaSettings();

		// Mask API keys — partial reveal (first 4 + bullets + last 4 chars)
		$api_keys = array( 'openai_api_key', 'gemini_api_key' );
		foreach ( $api_keys as $key ) {
			$s[ $key ] = $this->maskApiKeyForDisplay( $s[ $key ] );
		}

		// Include tool enabled/disabled status from wp_sflmcp_tools.
		global $wpdb;
		$tools_table = $wpdb->prefix . 'sflmcp_tools';
		$tool_names  = array( 'wp_generate_image', 'wp_generate_video' );
		foreach ( $tool_names as $tname ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$enabled = $wpdb->get_var( $wpdb->prepare(
				"SELECT enabled FROM {$tools_table} WHERE tool_name = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$tname
			) );
			$s[ 'tool_enabled_' . $tname ] = ( '1' === $enabled || 1 === (int) $enabled ) ? '1' : '0';
		}

		wp_send_json_success( $s );
	}

	/**
	 * AJAX handler: Reveal a full decrypted API key (admin only).
	 */
	public function ajax_mm_reveal_key() {
		check_ajax_referer( 'sflmcp_multimedia', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied', 'stifli-flex-mcp' ) ) );
		}

		$key_name = sanitize_text_field( wp_unslash( $_POST['key_name'] ?? '' ) );
		$allowed  = array( 'openai_api_key', 'gemini_api_key' );
		if ( ! in_array( $key_name, $allowed, true ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid key name', 'stifli-flex-mcp' ) ) );
		}

		$settings  = $this->getMultimediaSettings();
		$encrypted = isset( $settings[ $key_name ] ) ? $settings[ $key_name ] : '';

		if ( empty( $encrypted ) ) {
			wp_send_json_success( array( 'key' => '' ) );
		}

		$plain = '';
		if ( class_exists( 'StifliFlexMcp_Client_Admin' ) ) {
			$plain = StifliFlexMcp_Client_Admin::decrypt_value( $encrypted );
		} else {
			$plain = $encrypted;
		}

		wp_send_json_success( array( 'key' => $plain ) );
	}

	/**
	 * AJAX handler: Toggle a multimedia tool on/off by tool_name.
	 */
	public function ajax_mm_toggle_tool() {
		check_ajax_referer( 'sflmcp_multimedia', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied', 'stifli-flex-mcp' ) ) );
		}

		$tool_name = sanitize_text_field( wp_unslash( $_POST['tool_name'] ?? '' ) );
		$enabled   = isset( $_POST['enabled'] ) ? intval( $_POST['enabled'] ) : -1;

		$allowed = array( 'wp_generate_image', 'wp_generate_video' );
		if ( ! in_array( $tool_name, $allowed, true ) || ! in_array( $enabled, array( 0, 1 ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid parameters', 'stifli-flex-mcp' ) ) );
		}

		global $wpdb;
		$tools_table = $wpdb->prefix . 'sflmcp_tools';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->update(
			$tools_table,
			array( 'enabled' => $enabled, 'updated_at' => current_time( 'mysql', true ) ),
			array( 'tool_name' => $tool_name ),
			array( '%d', '%s' ),
			array( '%s' )
		);

		// Sync to active profile.
		$this->syncToolToActiveProfile( $tool_name, $enabled );

		wp_send_json_success( array( 'tool_name' => $tool_name, 'enabled' => $enabled ) );
	}

	// ================================================================
	// OAuth Clients Tab
	// ================================================================

	/**
	 * Render the OAuth Clients admin tab.
	 */
	private function renderOAuthClientsTab() {
		if ( ! class_exists( 'StifliFlexMcp_OAuth_Storage' ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'OAuth module is not loaded.', 'stifli-flex-mcp' ) . '</p></div>';
			return;
		}

		$storage       = StifliFlexMcp_OAuth_Storage::get_instance();
		$clients       = $storage->get_all_clients();
		$auto_approve  = get_option( 'sflmcp_oauth_auto_approve', '1' );

		// Preload token counts per client.
		global $wpdb;
		$tokens_table = $wpdb->prefix . 'sflmcp_oauth_tokens';
		$now          = gmdate( 'Y-m-d H:i:s' );

		$token_counts = array();
		$token_data   = array();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$counts_raw = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT client_id, COUNT(*) as cnt FROM {$tokens_table} WHERE revoked = 0 AND access_expires_at > %s GROUP BY client_id",
				$now
			)
		);
		foreach ( $counts_raw as $row ) {
			$token_counts[ $row->client_id ] = (int) $row->cnt;
		}

		// Get active tokens grouped by client for expandable detail.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$tokens_raw = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT t.id, t.client_id, t.user_id, t.scope, t.access_expires_at, t.created_at, u.display_name as user_name
				FROM {$tokens_table} t
				LEFT JOIN {$wpdb->users} u ON t.user_id = u.ID
				WHERE t.revoked = 0 AND t.access_expires_at > %s
				ORDER BY t.created_at DESC",
				$now
			)
		);
		foreach ( $tokens_raw as $tok ) {
			$token_data[ $tok->client_id ][] = $tok;
		}
		?>
		<div class="sflmcp-oauth-wrap">
			<div class="sflmcp-oauth-header">
				<h2><?php esc_html_e( 'OAuth 2.1 Clients', 'stifli-flex-mcp' ); ?></h2>
			</div>

			<!-- Auto-approve setting -->
			<div class="sflmcp-oauth-settings">
				<label>
					<input type="checkbox" id="sflmcp_oauth_auto_approve" value="1" <?php checked( $auto_approve, '1' ); ?>>
					<strong><?php esc_html_e( 'Auto-approve new client registrations', 'stifli-flex-mcp' ); ?></strong>
				</label>
				<p class="description">
					<?php esc_html_e( 'When enabled, any MCP client (Claude Desktop, ChatGPT, etc.) can register automatically via Dynamic Client Registration. When disabled, clients must be manually registered by an administrator.', 'stifli-flex-mcp' ); ?>
				</p>
			</div>

			<!-- OAuth Endpoints Reference -->
			<div class="sflmcp-oauth-endpoints">
				<h3><?php esc_html_e( 'OAuth Endpoints', 'stifli-flex-mcp' ); ?></h3>
				<table>
					<tr>
						<td><?php esc_html_e( 'Resource Metadata', 'stifli-flex-mcp' ); ?></td>
						<td><code><?php echo esc_html( home_url( '/.well-known/oauth-protected-resource' ) ); ?></code></td>
					</tr>
					<tr>
						<td><?php esc_html_e( 'Server Metadata', 'stifli-flex-mcp' ); ?></td>
						<td><code><?php echo esc_html( home_url( '/.well-known/oauth-authorization-server' ) ); ?></code></td>
					</tr>
					<tr>
						<td><?php esc_html_e( 'Client Registration', 'stifli-flex-mcp' ); ?></td>
						<td><code><?php echo esc_html( rest_url( 'stifli-flex-mcp/v1/oauth/register' ) ); ?></code></td>
					</tr>
					<tr>
						<td><?php esc_html_e( 'Authorization', 'stifli-flex-mcp' ); ?></td>
						<td><code><?php echo esc_html( home_url( '?sflmcp_oauth=authorize' ) ); ?></code></td>
					</tr>
					<tr>
						<td><?php esc_html_e( 'Token', 'stifli-flex-mcp' ); ?></td>
						<td><code><?php echo esc_html( rest_url( 'stifli-flex-mcp/v1/oauth/token' ) ); ?></code></td>
					</tr>
					<tr>
						<td><?php esc_html_e( 'Revocation', 'stifli-flex-mcp' ); ?></td>
						<td><code><?php echo esc_html( rest_url( 'stifli-flex-mcp/v1/oauth/revoke' ) ); ?></code></td>
					</tr>
				</table>
			</div>

			<!-- Clients Table -->
			<?php if ( empty( $clients ) ) : ?>
				<div class="sflmcp-oauth-empty">
					<div class="dashicons dashicons-shield"></div>
					<p><strong><?php esc_html_e( 'No OAuth clients registered yet', 'stifli-flex-mcp' ); ?></strong></p>
					<p><?php esc_html_e( 'Clients will appear here when an MCP application (Claude Desktop, ChatGPT, etc.) registers via Dynamic Client Registration.', 'stifli-flex-mcp' ); ?></p>
				</div>
			<?php else : ?>
				<table class="wp-list-table widefat fixed striped sflmcp-oauth-table">
					<thead>
						<tr>
							<th class="column-name"><?php esc_html_e( 'Client Name', 'stifli-flex-mcp' ); ?></th>
							<th class="column-client-id"><?php esc_html_e( 'Client ID', 'stifli-flex-mcp' ); ?></th>
							<th class="column-auth-method"><?php esc_html_e( 'Type', 'stifli-flex-mcp' ); ?></th>
							<th class="column-tokens"><?php esc_html_e( 'Tokens', 'stifli-flex-mcp' ); ?></th>
							<th class="column-date"><?php esc_html_e( 'Registered', 'stifli-flex-mcp' ); ?></th>
							<th class="column-actions"><?php esc_html_e( 'Actions', 'stifli-flex-mcp' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $clients as $client ) :
							$cid         = esc_attr( $client->client_id );
							$count       = isset( $token_counts[ $client->client_id ] ) ? $token_counts[ $client->client_id ] : 0;
							$is_public   = ( 'none' === $client->token_endpoint_auth_method );
							$tokens_list = isset( $token_data[ $client->client_id ] ) ? $token_data[ $client->client_id ] : array();
						?>
							<tr>
								<td>
									<strong><?php echo esc_html( $client->client_name ); ?></strong>
									<?php if ( $client->client_uri ) : ?>
										<br><small><a href="<?php echo esc_url( $client->client_uri ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $client->client_uri ); ?></a></small>
									<?php endif; ?>
								</td>
								<td>
									<span class="sflmcp-oauth-client-id"><?php echo esc_html( $client->client_id ); ?></span>
								</td>
								<td>
									<?php if ( $is_public ) : ?>
										<span class="sflmcp-oauth-badge sflmcp-oauth-badge--public"><?php esc_html_e( 'Public', 'stifli-flex-mcp' ); ?></span>
									<?php else : ?>
										<span class="sflmcp-oauth-badge sflmcp-oauth-badge--confidential"><?php esc_html_e( 'Confidential', 'stifli-flex-mcp' ); ?></span>
									<?php endif; ?>
								</td>
								<td>
									<?php if ( $count > 0 ) : ?>
										<a href="#" class="sflmcp-oauth-toggle-tokens" data-client-id="<?php echo esc_attr( $cid ); ?>">
											<span class="sflmcp-oauth-token-count has-tokens" data-client-count="<?php echo esc_attr( $cid ); ?>"><?php echo intval( $count ); ?></span>
										</a>
									<?php else : ?>
										<span class="sflmcp-oauth-token-count" data-client-count="<?php echo esc_attr( $cid ); ?>">0</span>
									<?php endif; ?>
								</td>
								<td>
									<?php
									$registered = strtotime( $client->created_at );
									echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $registered + ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ) ) );
									?>
								</td>
								<td>
									<button type="button" class="button button-link-delete sflmcp-oauth-delete-client" data-client-id="<?php echo esc_attr( $cid ); ?>">
										<?php esc_html_e( 'Delete', 'stifli-flex-mcp' ); ?>
									</button>
								</td>
							</tr>
							<!-- Expandable tokens row -->
							<?php if ( ! empty( $tokens_list ) ) : ?>
							<tr class="sflmcp-oauth-tokens-row">
								<td colspan="6">
									<div id="sflmcp-tokens-<?php echo esc_attr( $cid ); ?>" class="sflmcp-oauth-tokens-detail">
										<h4><?php esc_html_e( 'Active Tokens', 'stifli-flex-mcp' ); ?></h4>
										<table class="sflmcp-oauth-tokens-list">
											<thead>
												<tr>
													<th><?php esc_html_e( 'User', 'stifli-flex-mcp' ); ?></th>
													<th><?php esc_html_e( 'Scope', 'stifli-flex-mcp' ); ?></th>
													<th><?php esc_html_e( 'Issued', 'stifli-flex-mcp' ); ?></th>
													<th><?php esc_html_e( 'Expires', 'stifli-flex-mcp' ); ?></th>
													<th></th>
												</tr>
											</thead>
											<tbody>
												<?php foreach ( $tokens_list as $tok ) :
													$issued  = strtotime( $tok->created_at );
													$expires = strtotime( $tok->access_expires_at );
													$offset  = get_option( 'gmt_offset' ) * HOUR_IN_SECONDS;
												?>
												<tr>
													<td><?php echo esc_html( $tok->user_name ?: '#' . $tok->user_id ); ?></td>
													<td><?php echo esc_html( $tok->scope ); ?></td>
													<td><?php echo esc_html( date_i18n( 'M j, H:i', $issued + $offset ) ); ?></td>
													<td><?php echo esc_html( date_i18n( 'M j, H:i', $expires + $offset ) ); ?></td>
													<td>
														<button type="button" class="button button-small button-link-delete sflmcp-oauth-revoke-token"
															data-token-id="<?php echo intval( $tok->id ); ?>"
															data-client-id="<?php echo esc_attr( $cid ); ?>">
															<?php esc_html_e( 'Revoke', 'stifli-flex-mcp' ); ?>
														</button>
													</td>
												</tr>
												<?php endforeach; ?>
											</tbody>
										</table>
									</div>
								</td>
							</tr>
							<?php endif; ?>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<!-- Setup Guides -->
			<div class="sflmcp-oauth-guides" style="margin-top:24px;">
				<h2><?php esc_html_e( 'Quick Setup Guides', 'stifli-flex-mcp' ); ?></h2>

				<!-- Claude Desktop -->
				<div class="sflmcp-oauth-guide-card" style="background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:20px;margin-bottom:16px;">
					<h3 style="margin-top:0;">🟣 <?php esc_html_e( 'Claude Desktop', 'stifli-flex-mcp' ); ?></h3>
					<p style="color:#646970;font-size:13px;margin-bottom:12px;">
						<?php esc_html_e( 'Claude Desktop supports MCP OAuth 2.1 natively. It discovers all endpoints automatically from a single URL.', 'stifli-flex-mcp' ); ?>
					</p>
					<ol style="font-size:13px;line-height:1.8;padding-left:20px;">
						<li><?php esc_html_e( 'Enable "Auto-approve new client registrations" above', 'stifli-flex-mcp' ); ?></li>
						<li><?php echo wp_kses(
							sprintf(
								/* translators: %s: file path */
								__( 'Edit your Claude Desktop config file: %s', 'stifli-flex-mcp' ),
								'<code>' . ( PHP_OS_FAMILY === 'Darwin'
									? '~/Library/Application Support/Claude/claude_desktop_config.json'
									: '%APPDATA%\\Claude\\claude_desktop_config.json' ) . '</code>'
							),
							array( 'code' => array() )
						); ?></li>
						<li><?php esc_html_e( 'Add this MCP server configuration:', 'stifli-flex-mcp' ); ?>
							<pre style="background:#f6f7f7;border:1px solid #dcdcde;border-radius:4px;padding:12px;font-size:12px;overflow-x:auto;margin-top:6px;">{
  "mcpServers": {
    "<?php echo esc_html( sanitize_title( get_bloginfo( 'name' ) ) ); ?>": {
      "type": "sse",
      "url": "<?php echo esc_html( rest_url( $this->namespace . '/sse' ) ); ?>"
    }
  }
}</pre>
						</li>
						<li><?php esc_html_e( 'Restart Claude Desktop — it will open your browser to authorize', 'stifli-flex-mcp' ); ?></li>
						<li><?php esc_html_e( 'Log in to WordPress and click "Authorize"', 'stifli-flex-mcp' ); ?></li>
						<li><?php esc_html_e( 'Done! Claude will auto-discover all OAuth endpoints via .well-known metadata', 'stifli-flex-mcp' ); ?></li>
					</ol>
					<p style="font-size:12px;color:#646970;margin-bottom:0;">
						<?php echo wp_kses(
							sprintf(
								/* translators: %s: the SSE URL */
								__( 'Claude only needs the SSE URL: %s — it discovers authorization, token, and registration endpoints automatically.', 'stifli-flex-mcp' ),
								'<code>' . esc_html( rest_url( $this->namespace . '/sse' ) ) . '</code>'
							),
							array( 'code' => array() )
						); ?>
					</p>
				</div>

				<!-- ChatGPT Custom GPT -->
				<div class="sflmcp-oauth-guide-card" style="background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:20px;margin-bottom:16px;">
					<h3 style="margin-top:0;">🟢 <?php esc_html_e( 'ChatGPT (Custom GPT Actions)', 'stifli-flex-mcp' ); ?></h3>
					<p style="color:#646970;font-size:13px;margin-bottom:12px;">
						<?php esc_html_e( 'For ChatGPT GPT Actions, register a confidential client and configure OAuth in the GPT editor.', 'stifli-flex-mcp' ); ?>
					</p>
					<ol style="font-size:13px;line-height:1.8;padding-left:20px;">
						<li><?php esc_html_e( 'Register a confidential client (via API or ask an admin) with:', 'stifli-flex-mcp' ); ?>
							<ul style="list-style:disc;padding-left:16px;margin-top:4px;">
								<li><code>token_endpoint_auth_method: "client_secret_post"</code></li>
								<li><code>redirect_uris: ["https://chatgpt.com/aip/<em>YOUR_PLUGIN_ID</em>/oauth/callback"]</code></li>
							</ul>
						</li>
						<li><?php esc_html_e( 'In the GPT editor → Actions → Authentication, select "OAuth" and fill:', 'stifli-flex-mcp' ); ?>
							<table style="font-size:12px;margin-top:6px;border-collapse:collapse;">
								<tr><td style="padding:3px 8px;font-weight:600;"><?php esc_html_e( 'Client ID', 'stifli-flex-mcp' ); ?></td><td style="padding:3px 8px;"><em><?php esc_html_e( '(from step 1)', 'stifli-flex-mcp' ); ?></em></td></tr>
								<tr><td style="padding:3px 8px;font-weight:600;"><?php esc_html_e( 'Client Secret', 'stifli-flex-mcp' ); ?></td><td style="padding:3px 8px;"><em><?php esc_html_e( '(from step 1)', 'stifli-flex-mcp' ); ?></em></td></tr>
								<tr><td style="padding:3px 8px;font-weight:600;"><?php esc_html_e( 'Authorization URL', 'stifli-flex-mcp' ); ?></td><td style="padding:3px 8px;"><code><?php echo esc_html( home_url( '?sflmcp_oauth=authorize' ) ); ?></code></td></tr>
								<tr><td style="padding:3px 8px;font-weight:600;"><?php esc_html_e( 'Token URL', 'stifli-flex-mcp' ); ?></td><td style="padding:3px 8px;"><code><?php echo esc_html( rest_url( $this->namespace . '/oauth/token' ) ); ?></code></td></tr>
								<tr><td style="padding:3px 8px;font-weight:600;"><?php esc_html_e( 'Scope', 'stifli-flex-mcp' ); ?></td><td style="padding:3px 8px;"><code>mcp</code></td></tr>
							</table>
						</li>
						<li><?php esc_html_e( 'Save and test the GPT — users will be prompted to authorize via your WordPress site', 'stifli-flex-mcp' ); ?></li>
					</ol>
				</div>

				<!-- Application Passwords -->
				<div class="sflmcp-oauth-guide-card" style="background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:20px;margin-bottom:0;">
					<h3 style="margin-top:0;">🔑 <?php esc_html_e( 'Application Passwords (alternative)', 'stifli-flex-mcp' ); ?></h3>
					<p style="color:#646970;font-size:13px;margin-bottom:8px;">
						<?php echo wp_kses(
							sprintf(
								/* translators: %s: link to profile page */
								__( 'For simple setups, WordPress Application Passwords still work. Go to %s to generate one.', 'stifli-flex-mcp' ),
								'<a href="' . esc_url( get_edit_profile_url( get_current_user_id() ) . '#application-passwords-section' ) . '">'
								. esc_html__( 'your profile', 'stifli-flex-mcp' ) . '</a>'
							),
							array( 'a' => array( 'href' => array() ) )
						); ?>
					</p>
					<p style="font-size:12px;color:#646970;margin-bottom:0;">
						<?php esc_html_e( 'Use HTTP Basic Auth with your WordPress username and the generated application password. This method does not require OAuth setup.', 'stifli-flex-mcp' ); ?>
					</p>
				</div>
			</div>

		</div>
		<?php
	}

	// ================================================================
	// OAuth AJAX Handlers
	// ================================================================

	/**
	 * AJAX: Delete an OAuth client and all its tokens.
	 */
	public function ajax_oauth_delete_client() {
		check_ajax_referer( 'sflmcp_oauth', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied', 'stifli-flex-mcp' ) ) );
			return;
		}

		$client_id = isset( $_POST['client_id'] ) ? sanitize_text_field( wp_unslash( $_POST['client_id'] ) ) : '';

		if ( empty( $client_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Missing client_id', 'stifli-flex-mcp' ) ) );
			return;
		}

		$storage = StifliFlexMcp_OAuth_Storage::get_instance();
		$deleted = $storage->delete_client( $client_id );

		if ( $deleted ) {
			wp_send_json_success();
		} else {
			wp_send_json_error( array( 'message' => __( 'Client not found', 'stifli-flex-mcp' ) ) );
		}
	}

	/**
	 * AJAX: Revoke a specific OAuth token by row ID.
	 */
	public function ajax_oauth_revoke_token() {
		check_ajax_referer( 'sflmcp_oauth', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied', 'stifli-flex-mcp' ) ) );
			return;
		}

		$token_id = isset( $_POST['token_id'] ) ? intval( $_POST['token_id'] ) : 0;

		if ( ! $token_id ) {
			wp_send_json_error( array( 'message' => __( 'Missing token_id', 'stifli-flex-mcp' ) ) );
			return;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'sflmcp_oauth_tokens';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$updated = $wpdb->update(
			$table,
			array( 'revoked' => 1 ),
			array( 'id' => $token_id ),
			array( '%d' ),
			array( '%d' )
		);

		if ( false !== $updated ) {
			wp_send_json_success();
		} else {
			wp_send_json_error( array( 'message' => __( 'Token not found', 'stifli-flex-mcp' ) ) );
		}
	}

	/**
	 * AJAX: Save OAuth settings (auto-approve toggle).
	 */
	public function ajax_oauth_save_settings() {
		check_ajax_referer( 'sflmcp_oauth', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied', 'stifli-flex-mcp' ) ) );
			return;
		}

		$auto_approve = isset( $_POST['auto_approve'] ) ? sanitize_text_field( wp_unslash( $_POST['auto_approve'] ) ) : '0';
		update_option( 'sflmcp_oauth_auto_approve', $auto_approve === '1' ? '1' : '0' );

		wp_send_json_success();
	}

	/**
	 * Render Help Tab - Complete documentation
	 */
	private function renderHelpTab() {
		$site_url = site_url();
		$endpoint = rest_url($this->namespace . '/messages');
		?>
		
		<div class="sflmcp-help">
			<h1>📚 <?php esc_html_e('StifLi Flex MCP - Complete Guide', 'stifli-flex-mcp'); ?></h1>
			
			<div class="card toc">
				<h3>📑 <?php esc_html_e('Table of Contents', 'stifli-flex-mcp'); ?></h3>
				<ul>
					<li><a href="#overview">🎯 <?php esc_html_e('What is MCP?', 'stifli-flex-mcp'); ?></a></li>
					<li><a href="#builtin-tools">🔧 <?php esc_html_e('Built-in Tools (117+)', 'stifli-flex-mcp'); ?></a></li>
					<li><a href="#custom-tools">🔌 <?php esc_html_e('Custom Tools Overview', 'stifli-flex-mcp'); ?></a></li>
					<li><a href="#action-hooks">⚡ <?php esc_html_e('WordPress Action Hooks', 'stifli-flex-mcp'); ?></a></li>
					<li><a href="#find-actions">🔍 <?php esc_html_e('Finding Plugin Actions', 'stifli-flex-mcp'); ?></a></li>
					<li><a href="#webhooks">🌐 <?php esc_html_e('Webhooks & External APIs', 'stifli-flex-mcp'); ?></a></li>
					<li><a href="#internal-api">🏠 <?php esc_html_e('Internal WordPress REST API', 'stifli-flex-mcp'); ?></a></li>
					<li><a href="#use-cases">💡 <?php esc_html_e('Use Cases & Examples', 'stifli-flex-mcp'); ?></a></li>
					<li><a href="#security">🔐 <?php esc_html_e('Security Considerations', 'stifli-flex-mcp'); ?></a></li>
					<li><a href="#troubleshooting">🔧 <?php esc_html_e('Troubleshooting', 'stifli-flex-mcp'); ?></a></li>
				</ul>
			</div>

			<!-- SECTION: Overview -->
			<h2 id="overview">🎯 <?php esc_html_e('What is MCP (Model Context Protocol)?', 'stifli-flex-mcp'); ?></h2>
			<p><?php esc_html_e('MCP is an open standard that allows AI assistants (like ChatGPT, Claude, LibreChat) to interact with external tools and services. StifLi Flex MCP transforms your WordPress site into an MCP server, giving AI the ability to:', 'stifli-flex-mcp'); ?></p>
			<ul>
				<li>✅ <?php esc_html_e('Create, edit, and delete posts, pages, and custom content', 'stifli-flex-mcp'); ?></li>
				<li>✅ <?php esc_html_e('Manage WooCommerce products, orders, and customers', 'stifli-flex-mcp'); ?></li>
				<li>✅ <?php esc_html_e('Upload images (including AI-generated images)', 'stifli-flex-mcp'); ?></li>
				<li>✅ <?php esc_html_e('Execute any WordPress action hook', 'stifli-flex-mcp'); ?></li>
				<li>✅ <?php esc_html_e('Connect to external APIs and webhooks', 'stifli-flex-mcp'); ?></li>
			</ul>

			<!-- SECTION: Built-in Tools -->
			<h2 id="builtin-tools">🔧 <?php esc_html_e('Built-in Tools', 'stifli-flex-mcp'); ?></h2>
			<p><?php esc_html_e('This plugin comes with 117+ ready-to-use tools:', 'stifli-flex-mcp'); ?></p>
			<table>
				<tr><th><?php esc_html_e('Category', 'stifli-flex-mcp'); ?></th><th><?php esc_html_e('Examples', 'stifli-flex-mcp'); ?></th></tr>
				<tr><td><strong>Posts & Pages</strong></td><td>wp_get_posts, wp_create_post, wp_update_post, wp_delete_post</td></tr>
				<tr><td><strong>Media</strong></td><td>wp_upload_image, wp_upload_image_from_url, wp_get_media</td></tr>
				<tr><td><strong>Taxonomies</strong></td><td>wp_get_categories, wp_create_tag, wp_get_terms</td></tr>
				<tr><td><strong>Users</strong></td><td>wp_get_users, wp_get_user_meta, wp_update_user_meta</td></tr>
				<tr><td><strong>WooCommerce</strong></td><td>wc_get_products, wc_create_order, wc_update_stock</td></tr>
				<tr><td><strong>System</strong></td><td>wp_get_site_health, wp_get_settings, mcp_ping</td></tr>
			</table>
			<p><?php esc_html_e('Manage these tools in the "WordPress Tools" and "WooCommerce Tools" tabs.', 'stifli-flex-mcp'); ?></p>

			<!-- SECTION: Custom Tools Overview -->
			<h2 id="custom-tools">🔌 <?php esc_html_e('Custom Tools Overview', 'stifli-flex-mcp'); ?></h2>
			<p><?php esc_html_e('Custom Tools extend the AI\'s capabilities beyond the built-in functions. There are two types:', 'stifli-flex-mcp'); ?></p>
			
			<table>
				<tr>
					<th><?php esc_html_e('Type', 'stifli-flex-mcp'); ?></th>
					<th><?php esc_html_e('Badge', 'stifli-flex-mcp'); ?></th>
					<th><?php esc_html_e('Use Case', 'stifli-flex-mcp'); ?></th>
				</tr>
				<tr>
					<td><strong>HTTP</strong> (GET/POST/PUT/DELETE)</td>
					<td><span class="badge badge-http">HTTP</span></td>
					<td><?php esc_html_e('Call external APIs, webhooks (Zapier, Make, n8n), or your site\'s own REST API', 'stifli-flex-mcp'); ?></td>
				</tr>
				<tr>
					<td><strong>ACTION</strong></td>
					<td><span class="badge badge-action">ACTION</span></td>
					<td><?php esc_html_e('Execute any WordPress do_action() hook - from core, themes, or any plugin', 'stifli-flex-mcp'); ?></td>
				</tr>
			</table>

			<!-- SECTION: WordPress Action Hooks -->
			<h2 id="action-hooks">⚡ <?php esc_html_e('WordPress Action Hooks (Make Tools from ANY Plugin)', 'stifli-flex-mcp'); ?></h2>
			
			<div class="card success">
				<strong>💡 <?php esc_html_e('Key Concept', 'stifli-flex-mcp'); ?>:</strong>
				<?php esc_html_e('WordPress plugins communicate through "action hooks" (do_action). With Custom Tools, you can expose ANY of these hooks to the AI!', 'stifli-flex-mcp'); ?>
			</div>

			<h3><?php esc_html_e('How It Works', 'stifli-flex-mcp'); ?></h3>
			<ol>
				<li><?php esc_html_e('You create a Custom Tool with Type = ACTION', 'stifli-flex-mcp'); ?></li>
				<li><?php esc_html_e('In "Action Hook Name", you put the hook name (e.g., flush_rewrite_rules)', 'stifli-flex-mcp'); ?></li>
				<li><?php esc_html_e('When the AI calls your tool, the plugin executes: do_action(\'hook_name\', $args)', 'stifli-flex-mcp'); ?></li>
			</ol>

			<h3><?php esc_html_e('Example: WordPress Core Actions', 'stifli-flex-mcp'); ?></h3>
			<table>
				<tr><th><?php esc_html_e('Action Hook', 'stifli-flex-mcp'); ?></th><th><?php esc_html_e('What It Does', 'stifli-flex-mcp'); ?></th></tr>
				<tr><td><code>flush_rewrite_rules</code></td><td><?php esc_html_e('Regenerate permalink structure', 'stifli-flex-mcp'); ?></td></tr>
				<tr><td><code>wp_cron</code></td><td><?php esc_html_e('Manually run scheduled tasks', 'stifli-flex-mcp'); ?></td></tr>
				<tr><td><code>wp_cache_flush</code></td><td><?php esc_html_e('Clear object cache', 'stifli-flex-mcp'); ?></td></tr>
			</table>

			<h3><?php esc_html_e('Example: WooCommerce Actions', 'stifli-flex-mcp'); ?></h3>
			<table>
				<tr><th><?php esc_html_e('Action Hook', 'stifli-flex-mcp'); ?></th><th><?php esc_html_e('What It Does', 'stifli-flex-mcp'); ?></th></tr>
				<tr><td><code>woocommerce_cancel_unpaid_orders</code></td><td><?php esc_html_e('Cancel orders not paid within hold time', 'stifli-flex-mcp'); ?></td></tr>
				<tr><td><code>woocommerce_cleanup_sessions</code></td><td><?php esc_html_e('Remove expired customer sessions', 'stifli-flex-mcp'); ?></td></tr>
				<tr><td><code>woocommerce_scheduled_sales</code></td><td><?php esc_html_e('Process scheduled sale prices', 'stifli-flex-mcp'); ?></td></tr>
			</table>

			<h3><?php esc_html_e('Example: Popular Plugin Actions', 'stifli-flex-mcp'); ?></h3>
			<table>
				<tr><th><?php esc_html_e('Plugin', 'stifli-flex-mcp'); ?></th><th><?php esc_html_e('Action Hook', 'stifli-flex-mcp'); ?></th><th><?php esc_html_e('Effect', 'stifli-flex-mcp'); ?></th></tr>
				<tr><td>Yoast SEO</td><td><code>wpseo_reindex</code></td><td><?php esc_html_e('Rebuild SEO index', 'stifli-flex-mcp'); ?></td></tr>
				<tr><td>WP Super Cache</td><td><code>wp_cache_clear_cache</code></td><td><?php esc_html_e('Clear all cache', 'stifli-flex-mcp'); ?></td></tr>
				<tr><td>W3 Total Cache</td><td><code>w3tc_flush_all</code></td><td><?php esc_html_e('Flush all caches', 'stifli-flex-mcp'); ?></td></tr>
				<tr><td>WP Rocket</td><td><code>rocket_clean_domain</code></td><td><?php esc_html_e('Purge site cache', 'stifli-flex-mcp'); ?></td></tr>
				<tr><td>Elementor</td><td><code>elementor/core/files/clear_cache</code></td><td><?php esc_html_e('Clear CSS cache', 'stifli-flex-mcp'); ?></td></tr>
			</table>

			<!-- SECTION: Finding Plugin Actions -->
			<h2 id="find-actions">🔍 <?php esc_html_e('How to Find Plugin Action Hooks', 'stifli-flex-mcp'); ?></h2>
			
			<div class="card">
				<h4><?php esc_html_e('Method 1: Plugin Documentation', 'stifli-flex-mcp'); ?></h4>
				<p><?php esc_html_e('Most quality plugins document their hooks. Search for:', 'stifli-flex-mcp'); ?></p>
				<ul>
					<li><code>"[plugin name] action hooks"</code></li>
					<li><code>"[plugin name] do_action"</code></li>
					<li><code>"[plugin name] developer documentation"</code></li>
				</ul>
			</div>

			<div class="card">
				<h4><?php esc_html_e('Method 2: Search Plugin Code', 'stifli-flex-mcp'); ?></h4>
				<p><?php esc_html_e('Search for do_action in the plugin files:', 'stifli-flex-mcp'); ?></p>
				<pre><code># In your plugin folder, search for hooks:
grep -r "do_action(" wp-content/plugins/your-plugin/

# Common patterns:
do_action('plugin_prefix_event_name');
do_action('plugin_prefix_before_save', $data);
do_action('plugin_prefix_after_delete', $id);</code></pre>
			</div>

			<div class="card">
				<h4><?php esc_html_e('Method 3: Use a Hook Discovery Plugin', 'stifli-flex-mcp'); ?></h4>
				<p><?php esc_html_e('Install "Query Monitor" or "Debug Bar Actions and Filters" to see all hooks executed on any page.', 'stifli-flex-mcp'); ?></p>
			</div>

			<div class="card example">
				<h4>📋 <?php esc_html_e('Step-by-Step Example: Creating an Action Tool', 'stifli-flex-mcp'); ?></h4>
				<p><?php esc_html_e('Let\'s create a tool that clears WP Rocket cache:', 'stifli-flex-mcp'); ?></p>
				<ol>
					<li><?php esc_html_e('Go to Custom Tools → Add New Tool', 'stifli-flex-mcp'); ?></li>
					<li><strong><?php esc_html_e('Name', 'stifli-flex-mcp'); ?>:</strong> <code>custom_clear_rocket_cache</code></li>
					<li><strong><?php esc_html_e('Description', 'stifli-flex-mcp'); ?>:</strong> "Clear WP Rocket cache. Use when site shows outdated content."</li>
					<li><strong><?php esc_html_e('Type', 'stifli-flex-mcp'); ?>:</strong> ACTION (WordPress do_action)</li>
					<li><strong><?php esc_html_e('Action Hook Name', 'stifli-flex-mcp'); ?>:</strong> <code>rocket_clean_domain</code></li>
					<li><strong><?php esc_html_e('Parameters', 'stifli-flex-mcp'); ?>:</strong> (none needed)</li>
					<li><?php esc_html_e('Enable and Save!', 'stifli-flex-mcp'); ?></li>
				</ol>
				<p><?php esc_html_e('Now the AI can say: "Clear the cache" and it will work!', 'stifli-flex-mcp'); ?></p>
			</div>

			<!-- SECTION: Webhooks -->
			<h2 id="webhooks">🌐 <?php esc_html_e('Webhooks & External APIs', 'stifli-flex-mcp'); ?></h2>
			
			<p><?php esc_html_e('Connect your AI to ANY external service using HTTP methods:', 'stifli-flex-mcp'); ?></p>

			<h3><?php esc_html_e('Automation Platforms', 'stifli-flex-mcp'); ?></h3>
			<table>
				<tr><th><?php esc_html_e('Platform', 'stifli-flex-mcp'); ?></th><th><?php esc_html_e('Webhook URL Pattern', 'stifli-flex-mcp'); ?></th></tr>
				<tr><td>Zapier</td><td><code>https://hooks.zapier.com/hooks/catch/...</code></td></tr>
				<tr><td>Make (Integromat)</td><td><code>https://hook.eu1.make.com/...</code></td></tr>
				<tr><td>n8n</td><td><code>https://your-n8n.com/webhook/...</code></td></tr>
				<tr><td>IFTTT</td><td><code>https://maker.ifttt.com/trigger/...</code></td></tr>
			</table>

			<div class="card example">
				<h4>📋 <?php esc_html_e('Example: Create a Jira Ticket via Zapier', 'stifli-flex-mcp'); ?></h4>
				<ol>
					<li><?php esc_html_e('In Zapier: Create a Zap with "Webhooks by Zapier" trigger → "Jira" action', 'stifli-flex-mcp'); ?></li>
					<li><?php esc_html_e('Copy the Zapier webhook URL', 'stifli-flex-mcp'); ?></li>
					<li><?php esc_html_e('In Custom Tools:', 'stifli-flex-mcp'); ?>
						<ul>
							<li><strong>Name:</strong> <code>custom_create_jira_ticket</code></li>
							<li><strong>Type:</strong> POST</li>
							<li><strong>Endpoint:</strong> (your Zapier URL)</li>
							<li><strong>Parameters:</strong> title (string, required), description (string), priority (string)</li>
						</ul>
					</li>
				</ol>
				<p><?php esc_html_e('Now the AI can say: "Create a Jira ticket about the login bug"!', 'stifli-flex-mcp'); ?></p>
			</div>

			<h3><?php esc_html_e('Public APIs', 'stifli-flex-mcp'); ?></h3>
			<table>
				<tr><th><?php esc_html_e('API', 'stifli-flex-mcp'); ?></th><th><?php esc_html_e('Example Endpoint', 'stifli-flex-mcp'); ?></th><th><?php esc_html_e('Use Case', 'stifli-flex-mcp'); ?></th></tr>
				<tr><td>wttr.in</td><td><code>https://wttr.in/{city}?format=j1</code></td><td><?php esc_html_e('Weather data', 'stifli-flex-mcp'); ?></td></tr>
				<tr><td>ipapi.co</td><td><code>https://ipapi.co/{ip}/json/</code></td><td><?php esc_html_e('IP geolocation', 'stifli-flex-mcp'); ?></td></tr>
				<tr><td>OpenAI</td><td><code>https://api.openai.com/v1/...</code></td><td><?php esc_html_e('AI completions', 'stifli-flex-mcp'); ?></td></tr>
			</table>

			<!-- SECTION: Internal API -->
			<h2 id="internal-api">🏠 <?php esc_html_e('Internal WordPress REST API', 'stifli-flex-mcp'); ?></h2>
			
			<p><?php esc_html_e('Your WordPress site already has a powerful REST API. Use Custom Tools to expose specific endpoints:', 'stifli-flex-mcp'); ?></p>

			<table>
				<tr><th><?php esc_html_e('Endpoint', 'stifli-flex-mcp'); ?></th><th><?php esc_html_e('What It Returns', 'stifli-flex-mcp'); ?></th></tr>
				<tr><td><code><?php echo esc_html($site_url); ?>/wp-json/wp/v2/posts</code></td><td><?php esc_html_e('List of posts', 'stifli-flex-mcp'); ?></td></tr>
				<tr><td><code><?php echo esc_html($site_url); ?>/wp-json/wp/v2/pages/{id}</code></td><td><?php esc_html_e('Single page (with Yoast/RankMath SEO data if installed)', 'stifli-flex-mcp'); ?></td></tr>
				<tr><td><code><?php echo esc_html($site_url); ?>/wp-json/wc/v3/products</code></td><td><?php esc_html_e('WooCommerce products', 'stifli-flex-mcp'); ?></td></tr>
				<tr><td><code><?php echo esc_html($site_url); ?>/wp-json/contact-form-7/v1/contact-forms</code></td><td><?php esc_html_e('Contact Form 7 forms', 'stifli-flex-mcp'); ?></td></tr>
			</table>

			<div class="card warning">
				<strong>⚠️ <?php esc_html_e('Note on Plugin APIs:', 'stifli-flex-mcp'); ?></strong>
				<?php esc_html_e('Many plugins add their own REST endpoints. Check their documentation for available endpoints. You can also visit', 'stifli-flex-mcp'); ?> 
				<code><?php echo esc_html($site_url); ?>/wp-json/</code>
				<?php esc_html_e('to see all registered namespaces.', 'stifli-flex-mcp'); ?>
			</div>

			<!-- SECTION: Use Cases -->
			<h2 id="use-cases">💡 <?php esc_html_e('Real-World Use Cases', 'stifli-flex-mcp'); ?></h2>

			<div class="card">
				<h4>🛒 <?php esc_html_e('E-commerce Operations', 'stifli-flex-mcp'); ?></h4>
				<ul>
					<li><?php esc_html_e('"Cancel all unpaid orders older than 24 hours"', 'stifli-flex-mcp'); ?> → <code>woocommerce_cancel_unpaid_orders</code></li>
					<li><?php esc_html_e('"Update stock for product SKU-123 to 50 units"', 'stifli-flex-mcp'); ?> → built-in <code>wc_update_stock</code></li>
					<li><?php esc_html_e('"Create a 20% discount coupon"', 'stifli-flex-mcp'); ?> → built-in <code>wc_create_coupon</code></li>
				</ul>
			</div>

			<div class="card">
				<h4>🔧 <?php esc_html_e('Site Maintenance', 'stifli-flex-mcp'); ?></h4>
				<ul>
					<li><?php esc_html_e('"Clear all caches"', 'stifli-flex-mcp'); ?> → <code>w3tc_flush_all</code> / <code>rocket_clean_domain</code></li>
					<li><?php esc_html_e('"Enable maintenance mode"', 'stifli-flex-mcp'); ?> → <code>sflmcp_maintenance_mode</code></li>
					<li><?php esc_html_e('"Rebuild permalinks"', 'stifli-flex-mcp'); ?> → <code>flush_rewrite_rules</code></li>
				</ul>
			</div>

			<div class="card">
				<h4>📊 <?php esc_html_e('Business Integrations', 'stifli-flex-mcp'); ?></h4>
				<ul>
					<li><?php esc_html_e('"Log this conversation to Notion"', 'stifli-flex-mcp'); ?> → Notion API webhook</li>
					<li><?php esc_html_e('"Create a support ticket in Jira"', 'stifli-flex-mcp'); ?> → Zapier/Make webhook</li>
					<li><?php esc_html_e('"Send SMS notification"', 'stifli-flex-mcp'); ?> → Twilio API</li>
				</ul>
			</div>

			<!-- SECTION: Security -->
			<h2 id="security">🔐 <?php esc_html_e('Security Considerations', 'stifli-flex-mcp'); ?></h2>

			<div class="card warning">
				<h4><?php esc_html_e('Important Security Notes', 'stifli-flex-mcp'); ?></h4>
				<ul>
					<li><?php esc_html_e('All MCP requests require authentication (WordPress Application Passwords)', 'stifli-flex-mcp'); ?></li>
					<li><?php esc_html_e('The AI operates with the permissions of the authenticated user', 'stifli-flex-mcp'); ?></li>
					<li><?php esc_html_e('Custom Tools marked as "write" operations require confirmation', 'stifli-flex-mcp'); ?></li>
					<li><?php esc_html_e('Disable tools you don\'t need in the Tools tabs', 'stifli-flex-mcp'); ?></li>
					<li><?php esc_html_e('Use Profiles to limit available tools for specific use cases', 'stifli-flex-mcp'); ?></li>
				</ul>
			</div>

			<h3><?php esc_html_e('Headers for Authenticated APIs', 'stifli-flex-mcp'); ?></h3>
			<p><?php esc_html_e('For external APIs requiring authentication, add headers in the Advanced Settings:', 'stifli-flex-mcp'); ?></p>
			<pre><code>Authorization: Bearer your-api-token
Content-Type: application/json
X-Custom-Header: value</code></pre>

			<!-- SECTION: Troubleshooting -->
			<h2 id="troubleshooting">🔧 <?php esc_html_e('Troubleshooting', 'stifli-flex-mcp'); ?></h2>

			<table>
				<tr><th><?php esc_html_e('Problem', 'stifli-flex-mcp'); ?></th><th><?php esc_html_e('Solution', 'stifli-flex-mcp'); ?></th></tr>
				<tr>
					<td><?php esc_html_e('Tool not appearing for AI', 'stifli-flex-mcp'); ?></td>
					<td><?php esc_html_e('Check that the tool is enabled (green checkmark in table)', 'stifli-flex-mcp'); ?></td>
				</tr>
				<tr>
					<td><?php esc_html_e('ACTION returns "no handlers registered"', 'stifli-flex-mcp'); ?></td>
					<td><?php esc_html_e('The hook name might be wrong, or the plugin that registers it is not active', 'stifli-flex-mcp'); ?></td>
				</tr>
				<tr>
					<td><?php esc_html_e('HTTP webhook returns error', 'stifli-flex-mcp'); ?></td>
					<td><?php esc_html_e('Use the "Test Tool" button to debug. Check URL, headers, and method', 'stifli-flex-mcp'); ?></td>
				</tr>
				<tr>
					<td><?php esc_html_e('Parameters not passed correctly', 'stifli-flex-mcp'); ?></td>
					<td><?php esc_html_e('For GET: use {param} in URL. For POST: they\'re sent as JSON body', 'stifli-flex-mcp'); ?></td>
				</tr>
			</table>

			<div class="card">
				<h4>📝 <?php esc_html_e('Enable Logging', 'stifli-flex-mcp'); ?></h4>
				<p><?php esc_html_e('Go to the Logs tab and enable logging to see all MCP requests and responses. This helps debug issues with tools.', 'stifli-flex-mcp'); ?></p>
			</div>

			<hr class="sflmcp-help-separator">
			<p class="sflmcp-help-footer">
				<strong>StifLi Flex MCP</strong> - 
				<a href="https://github.com/estebanstifli/stifli-flex-mcp" target="_blank">GitHub</a> | 
				<a href="https://wordpress.org/plugins/stifli-flex-mcp/" target="_blank">WordPress.org</a>
			</p>
		</div>
		<?php
	}
	/* phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,PluginCheck.Security.DirectDB.UnescapedDBParameter */
}





