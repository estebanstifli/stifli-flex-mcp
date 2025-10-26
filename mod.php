<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
class EasyVisualMcp {
	private $logging = false;
	private $mcpToken = null;
	private $addedFilter = false;
	private $namespace = 'easy-visual-mcp/v1';
	private $sessionID = null;
	private $lastAction = 0;
	private $protocolVersion = '2025-06-18';
	private $serverVersion = '0.0.1';
	private $queueKey = 'evmcp_msg';

	public function init() {
		add_action('rest_api_init', array($this, 'restApiInit'));
		// Register admin menu and settings when in WP admin
		if (is_admin()) {
			add_action('admin_menu', array($this, 'registerAdmin'));
			add_action('admin_init', array($this, 'registerSettings'));
			// AJAX handlers for token generation/revocation
			add_action('wp_ajax_evmcp_generate_token', array($this, 'ajax_generate_token'));
			add_action('wp_ajax_evmcp_revoke_token', array($this, 'ajax_revoke_token'));
		}
	}

	public function restApiInit() {
		if ($this->mcpToken === null) {
			// Try loading a configured token from options (admin can set this option)
			$this->mcpToken = get_option('easy_visual_mcp_token', '');
			if (empty($this->mcpToken)) {
				$this->mcpToken = null;
			}
		}
		if (!empty($this->mcpToken) && !$this->addedFilter) {
			EasyVisualMcpDispatcher::addFilter('allow_evmcp', array($this, 'authViaBeaberToken'), 10, 2);
			$this->addedFilter = true;
		}
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
		EasyVisualMcpDispatcher::addFilter('evmcp_callback', array($this, 'handleCallback'), 10, 4);
	}

	public function canAccessMCP( $request ) {
		// Si no hay token configurado, permitir acceso público
		if (empty($this->mcpToken)) {
			if (defined('WP_DEBUG') && WP_DEBUG) {
				error_log('[EVMCP] canAccessMCP: no token configured, allowing public access');
			}
			return true;
		}
		$isAdmin = current_user_can('manage_options');
		if (defined('WP_DEBUG') && WP_DEBUG) {
			$hdr = $request->get_header('Authorization');
			$qp = $request->get_param('token');
			$masked = $this->maskToken($this->mcpToken);
			error_log(sprintf('[EVMCP] canAccessMCP: isAdmin=%s, header=%s, query=%s, stored=%s', $isAdmin ? '1':'0', $hdr ? 'present' : 'none', $qp ? 'present' : 'none', $masked));
		}
		// Fallback: check Authorization header or token query param directly (normalize before compare)
		try {
			$hdrValue = $request->get_header('Authorization');
			$incoming = null;
			if ($hdrValue && preg_match('/Bearer\s+(.+)/i', $hdrValue, $m)) {
				$incoming = $m[1];
			}
			if (empty($incoming)) {
				$qpVal = $request->get_param('token');
				if (!empty($qpVal)) {
					$incoming = $qpVal;
				}
			}
			if (!empty($incoming)) {
				// normalize: urldecode, trim, sanitize
				$norm = sanitize_text_field(rawurldecode(trim((string)$incoming)));
				$stored = sanitize_text_field((string)$this->mcpToken);
				if (!empty($stored) && hash_equals($stored, $norm)) {
					// matched token -> set mapped user or fallback admin
					$user_id = intval(get_option('easy_visual_mcp_token_user', 0));
					if ($user_id && get_userdata($user_id)) {
						wp_set_current_user($user_id);
					} else {
						if (class_exists('EasyVisualMcpUtils') && method_exists('EasyVisualMcpUtils', 'setAdminUser')) {
							EasyVisualMcpUtils::setAdminUser();
						}
					}
					if (defined('WP_DEBUG') && WP_DEBUG) {
						error_log('[EVMCP] canAccessMCP: token match -> access granted');
					}
					return true;
				} else {
					if (defined('WP_DEBUG') && WP_DEBUG) {
						error_log('[EVMCP] canAccessMCP: token provided but did not match stored token');
					}
				}
			}
		} catch (Exception $e) {
			if (defined('WP_DEBUG') && WP_DEBUG) {
				error_log('[EVMCP] canAccessMCP: exception during token fallback: ' . $e->getMessage());
			}
		}

		return EasyVisualMcpDispatcher::applyFilters('allow_evmcp', $isAdmin, $request);
	}

	/**
	 * Mask a token for safe logging (keep first/last 4 chars)
	 */
	private function maskToken($t) {
		if (empty($t) || !is_string($t)) return '(empty)';
		$len = strlen($t);
		if ($len <= 8) return str_repeat('*', $len);
		return substr($t,0,4) . str_repeat('*', max(0,$len-8)) . substr($t,-4);
	}

	public function handleCallback( $result, string $tool, array $args, int $id ) {
		if (!empty($result)) {
			return $result;
		}
		$tools = $this->getModel()->getTools();
		if (!isset($tools[$tool])) {
			EasyVisualMcpFrame::_()->saveDebugLogging('Tool not found ' . $tool, false, 'EVMCP');
			return $result;
		}
		return $this->getModel()->dispatchTool($tool, $args, $id);
	}

	public function authViaBeaberToken($allow, $request) {
		$hdr = $request->get_header('Authorization');
		if (!$hdr && !empty($this->mcpToken)) {
			$token = sanitize_text_field($request->get_param('token'));
			if ($token && hash_equals($this->mcpToken, $token)) {
				// If a specific user ID is configured for the token, switch to that user.
				$user_id = intval(get_option('easy_visual_mcp_token_user', 0));
				if ($user_id && get_userdata($user_id)) {
					wp_set_current_user($user_id);
					return true;
				}
				// fallback: previous behavior
				if (class_exists('EasyVisualMcpUtils') && method_exists('EasyVisualMcpUtils', 'setAdminUser')) {
					EasyVisualMcpUtils::setAdminUser();
				}
				return true;
			}
			return false;
		}
		if ($hdr && preg_match('/Bearer\s+(.+)/i', $hdr, $m)) {
			$token = trim($m[1]);
			if (!empty($this->mcpToken) && hash_equals($this->mcpToken, $token)) {
				$user_id = intval(get_option('easy_visual_mcp_token_user', 0));
				if ($user_id && get_userdata($user_id)) {
					wp_set_current_user($user_id);
					return true;
				}
				if (class_exists('EasyVisualMcpUtils') && method_exists('EasyVisualMcpUtils', 'setAdminUser')) {
					EasyVisualMcpUtils::setAdminUser();
				}
				return true;
			}
			return false;
		}
		if (!empty($this->mcpToken)) {
			return false;
		}
		return $allow;
	}

	private function getSSEid($req) {
		$last = $req ? $req->get_header('last-event-id') : '';
		return empty($last) ? str_replace('-', '', wp_generate_uuid4()) : $last;
	}

	public function handleSSE( $request ) {
		$body = $request->get_body();
		if (defined('WP_DEBUG') && WP_DEBUG) {
			$remote = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'n/a';
			$ua = $request->get_header('User-Agent');
			$hdrAuth = $request->get_header('Authorization') ? 'present' : 'none';
			$qp = $request->get_param('token') ? 'present' : 'none';
			error_log(sprintf('[EVMCP] handleSSE start: remote=%s, method=%s, auth_header=%s, query_token=%s, body_len=%d, ua=%s', $remote, $request->get_method(), $hdrAuth, $qp, strlen($body), $ua));
		}
		if ($request->get_method() === 'POST' && !empty($body)) {
			$data = json_decode($body, true);
			if ($data && isset($data['method'])) {
				return $this->handleDirectJsonRPC($request, $data);
			}
		}
		@ini_set('zlib.output_compression', '0');
		@ini_set('output_buffering', '0');
		@ini_set('implicit_flush', '1');
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
		if (!empty($this->mcpToken)) {
			$msgUri .= '&token=' . $this->mcpToken;
		}
		if (defined('WP_DEBUG') && WP_DEBUG) {
			error_log('[EVMCP] handleSSE: sessionID=' . $this->sessionID . ' msgUri=' . $msgUri);
		}
		$this->reply('endpoint', $msgUri, 'text');
		while (true) {
			$maxTime = $this->logging ? 60 : 60 * 5;
			$idle = ( time() - $this->lastAction ) >= $maxTime;
			if (connection_aborted() || $idle) {
				if (defined('WP_DEBUG') && WP_DEBUG) {
					error_log('[EVMCP] handleSSE: connection aborted or idle, aborting session ' . $this->sessionID);
				}
				$this->reply('bye');
				break;
			}
			foreach ($this->fetchMessages($this->sessionID) as $p) {
				if (isset($p['method']) && 'evmcp/kill' === $p['method']) {
					$this->reply('bye');
					exit;
				}
				if (defined('WP_DEBUG') && WP_DEBUG) {
					error_log('[EVMCP] handleSSE: sending message to session ' . $this->sessionID . ' method=' . (isset($p['method']) ? $p['method'] : 'n/a'));
				}
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
		echo 'event: ' . $event . "\n";
		if ('json' === $enc) {
			$data = null === $data ? '{}' : str_replace('[]', '{}', wp_json_encode($data, JSON_UNESCAPED_UNICODE));
		}
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
		if (defined('WP_DEBUG') && WP_DEBUG) {
			$qp = $request->get_param('token') ? 'present' : 'none';
			$hdr = $request->get_header('Authorization') ? 'present' : 'none';
			error_log(sprintf('[EVMCP] handleDirectJsonRPC: id=%s method=%s header=%s query=%s', $id, $method, $hdr, $qp));
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
					$params = EasyVisualMcpUtils::getArrayValue($data, 'params', array(), 2);
					$reqVersion = EasyVisualMcpUtils::getArrayValue($params, 'protocolVersion', null);
					$clientInfo = EasyVisualMcpUtils::getArrayValue($params, 'clientInfo', false);
					$reply = array(
						'jsonrpc' => '2.0',
						'id' => $id,
						'result' => array(
							'protocolVersion' => $this->protocolVersion,
							'serverInfo' => (object) array(
								'name' => get_bloginfo('name') . ' EasyVisualMCP',
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
					   $params = EasyVisualMcpUtils::getArrayValue($data, 'params', array(), 2);
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
		   if (defined('WP_DEBUG') && WP_DEBUG) {
			   $hdr = $request->get_header('Authorization') ? 'present' : 'none';
			   $qp = $request->get_param('token') ? 'present' : 'none';
			   $remote = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'n/a';
			   error_log(sprintf('[EVMCP] handleMessage: session=%s remote=%s header=%s query=%s body_len=%d', $sess, $remote, $hdr, $qp, strlen($body)));
			   error_log('[EVMCP] handleMessage: RAW BODY: ' . $body);
		   }
		   $data = json_decode($body, true);
		   if (defined('WP_DEBUG') && WP_DEBUG) {
			   error_log('[EVMCP] handleMessage: JSON decoded: ' . print_r($data, true));
		   }
		   $id = isset($data['id']) ? $data['id'] : null;
		   $method = EasyVisualMcpUtils::getArrayValue($data, 'method', null);
		if ('initialized' === $method) {
			return new WP_REST_Response(null, 204);
		}
		if ('evmcp/kill' === $method) {
			$this->storeMessage($sess, array('jsonrpc' => '2.0', 'method' => 'evmcp/kill'));
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
		try {
			$reply = null;
			switch ($method) {
				case 'initialize':
					$params = EasyVisualMcpUtils::getArrayValue($data, 'params', array(), 2);
					$requestedVersion = EasyVisualMcpUtils::getArrayValue($params, 'protocolVersion', null);
					$clientInfo = EasyVisualMcpUtils::getArrayValue($params, 'clientInfo', null);
					$reply = array(
						'jsonrpc' => '2.0',
						'id' => $id,
						'result' => array(
							'protocolVersion' => $this->protocolVersion,
							'serverInfo' => (object) array(
								'name' => get_bloginfo( 'name' ) . ' EasyVisualMCP',
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
					   $params = EasyVisualMcpUtils::getArrayValue($data, 'params', array(), 2);
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
					   if (defined('WP_DEBUG') && WP_DEBUG) {
						   error_log('[EVMCP] tools/call: tool=' . print_r($tool, true) . ' arguments=' . print_r($arguments, true));
					   }
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
		$model = new EasyVisualMcpModel();
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
			   if (defined('WP_DEBUG') && WP_DEBUG) {
				   error_log('[EVMCP] executeTool: tool=' . print_r($tool, true) . ' args=' . print_r($args, true) . ' id=' . print_r($id, true));
			   }
			   $filtered = EasyVisualMcpDispatcher::applyFilters('evmcp_callback', null, $tool, $args, $id, $this);
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
			   if (defined('WP_DEBUG') && WP_DEBUG) {
				   error_log('[EVMCP] executeTool: Exception: ' . $e->getMessage());
			   }
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

	private function transientKey( $sess, $id ) {
		return "{$this->queueKey}_{$sess}_{$id}";
	}

	private function storeMessage( $sess, $payload ) {
		if (!$sess) {
			return;
		}
		$idKey = array_key_exists('id', $payload) ? ( isset($payload['id']) ? $payload['id'] : 'NULL' ) : 'N/A';
		set_transient($this->transientKey($sess, $idKey), $payload, 30);
	}

	private function fetchMessages( $sess ) {
		global $wpdb;
		$like = $wpdb->esc_like( '_transient_' . "{$this->queueKey}_{$sess}_" ) . '%';
		$rows = $wpdb->get_results(
			$wpdb->prepare("SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s",  $like),
			ARRAY_A
		);
		$msgs = array();
		foreach ($rows as $r) {
			$msgs[] = maybe_unserialize($r['option_value']);
			delete_option( $r['option_name'] );
		}
		usort($msgs, function( $a, $b ) {
			$aId = isset($a['id']) ? $a['id'] : 0;
			$bId = isset($b['id']) ? $b['id'] : 0;
			if ($aId == $bId) {
				return 0;
			}
			return ($aId < $bId) ? -1 : 1;
		});
		return $msgs;
	}
	private function getModel() {
		return new EasyVisualMcpModel();
	}
    
	/**
	 * AJAX: generate a new token and return it (does not persist to options until admin saves)
	 */
	public function ajax_generate_token() {
		if (!current_user_can('manage_options')) {
			wp_send_json_error(array('message' => 'No permission'), 403);
		}
		check_ajax_referer('evmcp-admin');
		try {
			if (function_exists('random_bytes')) {
				$token = bin2hex(random_bytes(16));
			} else {
				$token = wp_generate_password(32, false, false);
			}
			// Return token to UI; admin must click Save to persist to options
			wp_send_json_success(array('token' => $token));
		} catch (Exception $e) {
			wp_send_json_error(array('message' => $e->getMessage()), 500);
		}
	}
    
	/**
	 * AJAX: revoke current token (clears option). Requires manage_options
	 */
	public function ajax_revoke_token() {
		if (!current_user_can('manage_options')) {
			wp_send_json_error(array('message' => 'No permission'), 403);
		}
		check_ajax_referer('evmcp-admin');
		// Clear the option so saving will persist empty token
		update_option('easy_visual_mcp_token', '');
		wp_send_json_success();
	}

	/**
	 * Register admin menu entry for plugin settings
	 */
	public function registerAdmin() {
		add_options_page(
			__('Easy Visual MCP','easy-visual-mcp'),
			__('Easy Visual MCP','easy-visual-mcp'),
			'manage_options',
			'easy-visual-mcp',
			array($this, 'adminPage')
		);
	}

	/**
	 * Register settings used by the plugin
	 */
	public function registerSettings() {
		register_setting('easy_visual_mcp', 'easy_visual_mcp_token', array('type' => 'string', 'sanitize_callback' => 'sanitize_text_field'));
		register_setting('easy_visual_mcp', 'easy_visual_mcp_token_user', array('type' => 'integer', 'sanitize_callback' => 'intval'));
	}

	/**
	 * Render the admin settings page
	 */
	public function adminPage() {
		if (!current_user_can('manage_options')) {
			wp_die(__('No tienes permiso para ver esta página.','easy-visual-mcp'));
		}
		// Save notices handled by settings API
		$token = get_option('easy_visual_mcp_token', '');
		$token_user = intval(get_option('easy_visual_mcp_token_user', 0));
	$endpoint = rest_url($this->namespace . '/messages');
	$sse_endpoint = rest_url($this->namespace . '/sse');
		$users = get_users(array('orderby' => 'display_name', 'fields' => array('ID','display_name','user_login')));
	?>
		<div class="wrap">
			<h1><?php echo esc_html__('Easy Visual MCP - Ajustes', 'easy-visual-mcp'); ?></h1>
			<form method="post" action="options.php">
				<?php settings_fields('easy_visual_mcp'); ?>
				<?php do_settings_sections('easy_visual_mcp'); ?>
				<table class="form-table">
					<tr valign="top">
						<th scope="row"><?php echo esc_html__('Token (Bearer)', 'easy-visual-mcp'); ?></th>
						<td>
							<input id="evmcp_token_field" type="text" name="easy_visual_mcp_token" value="<?php echo esc_attr($token); ?>" class="regular-text" />
							<p class="description"><?php echo esc_html__('Token que permitirá llamadas autenticadas al endpoint. Dejar vacío para permitir acceso público (no recomendado).', 'easy-visual-mcp'); ?></p>
							<p>
								<button id="evmcp_generate" class="button button-secondary" type="button"><?php echo esc_html__('Generar token', 'easy-visual-mcp'); ?></button>
								<button id="evmcp_revoke" class="button button-secondary" type="button"><?php echo esc_html__('Revocar token', 'easy-visual-mcp'); ?></button>
								<span id="evmcp_spinner" style="display:none;margin-left:10px;"><?php echo esc_html__('Procesando...', 'easy-visual-mcp'); ?></span>
							</p>
						</td>
					</tr>
					<tr valign="top">
						<th scope="row"><?php echo esc_html__('Asignar token a usuario', 'easy-visual-mcp'); ?></th>
						<td>
							<select name="easy_visual_mcp_token_user">
								<option value="0"><?php echo esc_html__('-- Ninguno (fallback a admin) --', 'easy-visual-mcp'); ?></option>
								<?php foreach ($users as $u): $sel = ($token_user === intval($u->ID)) ? 'selected' : ''; ?>
									<option value="<?php echo intval($u->ID); ?>" <?php echo $sel; ?>><?php echo esc_html($u->display_name . ' (' . $u->user_login . ')'); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php echo esc_html__('Si seleccionas un usuario, las llamadas autenticadas con el token se ejecutarán con los permisos de ese usuario.', 'easy-visual-mcp'); ?></p>
						</td>
					</tr>
					<tr valign="top">
						<th scope="row"><?php echo esc_html__('Endpoint', 'easy-visual-mcp'); ?></th>
						<td>
							<p><strong><?php echo esc_html__('HTTP JSON-RPC endpoint (requests/responses):', 'easy-visual-mcp'); ?></strong></p>
							<code id="evmcp_endpoint"><?php echo esc_html($endpoint); ?></code>
							<p class="description"><?php echo esc_html__('Este endpoint acepta llamadas JSON-RPC 2.0 (métodos: tools/list, tools/call) y es útil para llamadas puntuales o descubrimiento.', 'easy-visual-mcp'); ?></p>
							<p><strong><?php echo esc_html__('SSE streamable endpoint (recomendado para conectores y streaming):', 'easy-visual-mcp'); ?></strong></p>
							<code id="evmcp_sse_endpoint"><?php echo esc_html($sse_endpoint); ?></code>
							<p class="description"><?php echo esc_html__('Para integraciones tipo ChatGPT Connector se recomienda usar la URL SSE (Server-Sent Events) ya que el conector mantiene una conexión streamable al servidor MCP. Muchas implementaciones de cliente intentan abrir un SSE al registrar el conector.', 'easy-visual-mcp'); ?></p>
							<p class="description"><strong><?php echo esc_html__('Importante:'); ?></strong> <?php echo esc_html__('Si al crear el conector en ChatGPT configuras la URL sin /sse, el conector podrá listar herramientas pero fallará en la fase de streaming/ejecución. Usa la URL SSE en la pantalla del conector.', 'easy-visual-mcp'); ?></p>
							<p class="description"><?php echo esc_html__('Si tu proveedor de hosting aplica WAF o buffering (nginx/fastcgi buffers), la conexión SSE puede ser bloqueada o bufferizada. Revisa la sección de pruebas más abajo.', 'easy-visual-mcp'); ?></p>
						</td>
					</tr>
				</table>
				<h2><?php echo esc_html__('Guía rápida', 'easy-visual-mcp'); ?></h2>
				<p><?php echo esc_html__('Ejemplo para registrar las funciones en OpenAI/ChatGPT (usa el resultado de getOpenAIFunctions() o tools/list):', 'easy-visual-mcp'); ?></p>
				<pre style="background:#f7f7f7;border:1px solid #ddd;padding:10px;overflow:auto;">{
  "url": "<?php echo esc_html($endpoint); ?>",
  "auth": "Bearer &lt;TOKEN&gt;",
  "format": "JSON-RPC 2.0",
  "discovery": "tools/list"
}</pre>
				<h3><?php echo esc_html__('Pruebas y diagnóstico', 'easy-visual-mcp'); ?></h3>
				<p><?php echo esc_html__('Si el conector lista herramientas pero falla al ejecutar o al mantener la conexión, prueba los siguientes comandos desde tu máquina para diagnosticar problemas de SSE/CORS/WAF.', 'easy-visual-mcp'); ?></p>
				<p><strong><?php echo esc_html__('Probar discovery (tools/list) con header Authorization:', 'easy-visual-mcp'); ?></strong></p>
				<pre style="background:#f7f7f7;border:1px solid #ddd;padding:8px;">curl -X POST '<?php echo esc_html($endpoint); ?>' \
	  -H 'Content-Type: application/json' \
	  -H 'Authorization: Bearer &lt;TOKEN&gt;' \
	  -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}'</pre>
				<p><strong><?php echo esc_html__('Probar discovery con token en query string:', 'easy-visual-mcp'); ?></strong></p>
				<pre style="background:#f7f7f7;border:1px solid #ddd;padding:8px;">curl -X POST '<?php echo esc_html($endpoint); ?>?token=&lt;TOKEN&gt;' \
	  -H 'Content-Type: application/json' \
	  -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}'</pre>
				<p><strong><?php echo esc_html__('Probar conexión SSE (muestra eventos):', 'easy-visual-mcp'); ?></strong></p>
				<pre style="background:#f7f7f7;border:1px solid #ddd;padding:8px;"># Usando curl (mantiene la conexión abierta, -N para no bufferizar)
	curl -N '<?php echo esc_html($sse_endpoint); ?>?token=&lt;TOKEN&gt;'

	# PowerShell (Invoke-RestMethod no mantiene SSE; usar curl.exe si está instalado):
	curl.exe -N "<?php echo esc_html($sse_endpoint); ?>?token=&lt;TOKEN&gt;"</pre>
				<p><?php echo esc_html__('Si el comando SSE no muestra eventos o devuelve un error HTTP (400/403/502), es muy probable que el proveedor de hosting o un WAF esté bloqueando conexiones de larga duración o eliminando cabeceras necesarias (p. ej. X-Accel-Buffering). En ese caso revisa la configuración de nginx/apache o pide al host que desactive buffering para esta ruta.', 'easy-visual-mcp'); ?></p>
				<?php submit_button(); ?>
			</form>

			<h2><?php echo esc_html__('Copiar ejemplo listo', 'easy-visual-mcp'); ?></h2>
			<p><?php echo esc_html__('Puedes copiar la URL con token (si lo has generado) o la cabecera Authorization para pegarla en el conector de ChatGPT.', 'easy-visual-mcp'); ?></p>
			<p>
				<label><?php echo esc_html__('URL con token:', 'easy-visual-mcp'); ?></label>
				<input type="text" id="evmcp_url_with_token" class="regular-text" readonly />
				<button id="evmcp_copy_url" class="button"><?php echo esc_html__('Copiar', 'easy-visual-mcp'); ?></button>
			</p>
			<p>
				<label><?php echo esc_html__('Header Authorization:', 'easy-visual-mcp'); ?></label>
				<input type="text" id="evmcp_auth_header" class="regular-text" readonly />
				<button id="evmcp_copy_header" class="button"><?php echo esc_html__('Copiar', 'easy-visual-mcp'); ?></button>
			</p>
		</div>

		<script type="text/javascript">
		(function(){
			var ajaxUrl = '<?php echo admin_url('admin-ajax.php'); ?>';
			var nonce = '<?php echo wp_create_nonce('evmcp-admin'); ?>';
			function setFields(token) {
				var endpoint = document.getElementById('evmcp_endpoint').textContent || '';
				document.getElementById('evmcp_token_field').value = token || '';
				document.getElementById('evmcp_url_with_token').value = endpoint + (endpoint.indexOf('?')===-1 ? '?token=' : '&token=') + (token || '');
				document.getElementById('evmcp_auth_header').value = 'Authorization: Bearer ' + (token || '');
			}
			// init with existing token
			setFields('<?php echo esc_js($token); ?>');

			document.getElementById('evmcp_generate').addEventListener('click', function(){
				document.getElementById('evmcp_spinner').style.display = '';
				fetch(ajaxUrl, {
					method: 'POST',
					credentials: 'same-origin',
					headers: {'Content-Type':'application/x-www-form-urlencoded'},
					body: 'action=evmcp_generate_token&_wpnonce=' + encodeURIComponent(nonce)
				}).then(function(r){return r.json();}).then(function(j){
					document.getElementById('evmcp_spinner').style.display = 'none';
					if (j.success && j.data && j.data.token) {
						setFields(j.data.token);
						alert('<?php echo esc_js(__('Token generado. Guarda los cambios para persistirlo en la opción.', 'easy-visual-mcp')); ?>');
					} else {
						alert('<?php echo esc_js(__('Error generando token', 'easy-visual-mcp')); ?>: ' + (j.data && j.data.message ? j.data.message : ''));
					}
				}).catch(function(e){ document.getElementById('evmcp_spinner').style.display = 'none'; alert('Error: '+e); });
			});

			document.getElementById('evmcp_revoke').addEventListener('click', function(){
				if (!confirm('<?php echo esc_js(__('¿Revocar el token actual? Esto dejará inválidas las integraciones que lo usen.', 'easy-visual-mcp')); ?>')) return;
				document.getElementById('evmcp_spinner').style.display = '';
				fetch(ajaxUrl, {
					method: 'POST',
					credentials: 'same-origin',
					headers: {'Content-Type':'application/x-www-form-urlencoded'},
					body: 'action=evmcp_revoke_token&_wpnonce=' + encodeURIComponent(nonce)
				}).then(function(r){return r.json();}).then(function(j){
					document.getElementById('evmcp_spinner').style.display = 'none';
					if (j.success) {
						setFields('');
						alert('<?php echo esc_js(__('Token revocado. Guarda los cambios.', 'easy-visual-mcp')); ?>');
					} else {
						alert('<?php echo esc_js(__('Error revocando token', 'easy-visual-mcp')); ?>: ' + (j.data && j.data.message ? j.data.message : ''));
					}
				}).catch(function(e){ document.getElementById('evmcp_spinner').style.display = 'none'; alert('Error: '+e); });
			});

			document.getElementById('evmcp_copy_url').addEventListener('click', function(){
				navigator.clipboard.writeText(document.getElementById('evmcp_url_with_token').value).then(function(){ alert('URL copiada'); });
			});
			document.getElementById('evmcp_copy_header').addEventListener('click', function(){
				navigator.clipboard.writeText(document.getElementById('evmcp_auth_header').value).then(function(){ alert('Header copiado'); });
			});
		})();
		</script>
		<?php
	}
}
