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
			// AJAX handlers for logs management
			add_action('wp_ajax_sflmcp_toggle_logging', array($this, 'ajax_toggle_logging'));
			add_action('wp_ajax_sflmcp_clear_logs', array($this, 'ajax_clear_logs'));
			add_action('wp_ajax_sflmcp_refresh_logs', array($this, 'ajax_refresh_logs'));
			// AJAX handlers for custom tools
			add_action('wp_ajax_sflmcp_get_custom_tools', array($this, 'ajax_get_custom_tools'));
			add_action('wp_ajax_sflmcp_save_custom_tool', array($this, 'ajax_save_custom_tool'));
			add_action('wp_ajax_sflmcp_delete_custom_tool', array($this, 'ajax_delete_custom_tool'));
			add_action('wp_ajax_sflmcp_test_custom_tool', array($this, 'ajax_test_custom_tool'));
			add_action('wp_ajax_sflmcp_toggle_custom_tool', array($this, 'ajax_toggle_custom_tool'));
			// AJAX handlers for WordPress/WooCommerce tools
			add_action('wp_ajax_sflmcp_toggle_tool', array($this, 'ajax_toggle_tool'));
			add_action('wp_ajax_sflmcp_bulk_toggle_tools', array($this, 'ajax_bulk_toggle_tools'));
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
		$current_user_id = get_current_user_id();
		
		if ($current_user_id > 0 && current_user_can('edit_posts')) {
			stifli_flex_mcp_log(sprintf('canAccessMCP: user %d has sufficient capabilities', $current_user_id));
			return true;
		}
		
		stifli_flex_mcp_log('canAccessMCP: Access denied - no authenticated user with edit_posts capability');
		return false;
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
			$data = null === $data ? '{}' : str_replace('[]', '{}', wp_json_encode($data, JSON_UNESCAPED_UNICODE));
		}
		echo 'data: ' . esc_html( $data ) . "\n\n";
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
		try {
			$reply = null;
			switch ($method) {
				case 'initialize':
					$params = StifliFlexMcpUtils::getArrayValue($data, 'params', array(), 2);
					$requestedVersion = StifliFlexMcpUtils::getArrayValue($params, 'protocolVersion', null);
					$clientInfo = StifliFlexMcpUtils::getArrayValue($params, 'clientInfo', null);
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
		$queue_select = StifliFlexMcpUtils::formatSqlWithTables(
			'SELECT id, payload FROM %s WHERE session_id = %%s AND expires_at >= %%s ORDER BY id ASC',
			'sflmcp_queue'
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- query is prepared via formatSqlWithTables helper.
		$rows = $wpdb->get_results(
			$wpdb->prepare($queue_select, $sessionKey, $now),
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
		$profile_tools_query = sprintf(
			'SELECT tool_name FROM %s WHERE profile_id = %%d',
			$profile_tools_table_sql
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- query uses sprintf with safe table wrapper.
		$profile_tools = $wpdb->get_col(
			$wpdb->prepare($profile_tools_query, $profile_id)
		);
		
		if ($profile_tools === null) {
			wp_send_json_error(array('message' => 'Profile not found'));
		}
		
		// Disable all tools first
		$disable_tools_query = sprintf('UPDATE %s SET enabled = %%d', $tools_table_sql);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- query uses sprintf with safe table wrapper.
		$wpdb->query($wpdb->prepare($disable_tools_query, 0));
		
		// Enable profile tools
		if (!empty($profile_tools)) {
			$placeholders = implode(',', array_fill(0, count($profile_tools), '%s'));
			$enable_tools_query = 'UPDATE ' . $tools_table_sql . ' SET enabled = 1 WHERE tool_name IN (' . $placeholders . ')';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- query uses dynamic placeholders with prepare.
			$wpdb->query(
				$wpdb->prepare(
					$enable_tools_query,
					...$profile_tools
				)
			);
		}
		
		// Mark profile as active
		$deactivate_profiles_query = sprintf('UPDATE %s SET is_active = %%d', $profiles_table_sql);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- query uses sprintf with safe table wrapper.
		$wpdb->query($wpdb->prepare($deactivate_profiles_query, 0));
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
	 * Register admin menu entry for plugin settings
	 */
	public function registerAdmin() {
		add_menu_page(
			__('StifLi Flex MCP', 'stifli-flex-mcp'),
			__('StifLi Flex MCP', 'stifli-flex-mcp'),
			'manage_options',
			'stifli-flex-mcp',
			array($this, 'adminPage'),
			'dashicons-rest-api',
			30
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
		// Only load on our plugin page
		if ($hook !== 'toplevel_page_stifli-flex-mcp') {
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

		// Enqueue Logs tab CSS
		wp_enqueue_style(
			'sflmcp-admin-logs',
			plugin_dir_url(__FILE__) . 'assets/admin-logs.css',
			array(),
			'1.0.4'
		);

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

		// Enqueue Logs tab JavaScript
		wp_enqueue_script(
			'sflmcp-admin-logs',
			plugin_dir_url(__FILE__) . 'assets/admin-logs.js',
			array('jquery'),
			'1.0.4',
			true
		);

		// Localize script with data
		wp_localize_script('sflmcp-admin-logs', 'sflmcpLogs', array(
			'ajaxUrl' => admin_url('admin-ajax.php'),
			'nonce' => wp_create_nonce('sflmcp_logs'),
			'i18n' => array(
				'loggingEnabled' => __('Logging enabled', 'stifli-flex-mcp'),
				'loggingDisabled' => __('Logging disabled', 'stifli-flex-mcp'),
				'errorSaving' => __('Error saving setting', 'stifli-flex-mcp'),
				'loading' => __('Loading...', 'stifli-flex-mcp'),
				'confirmClear' => __('Are you sure you want to clear all logs?', 'stifli-flex-mcp'),
				'logsCleared' => __('Logs cleared successfully', 'stifli-flex-mcp'),
				'errorClearing' => __('Error clearing logs', 'stifli-flex-mcp'),
			),
		));
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
			<h1><?php echo esc_html__('StifLi Flex MCP', 'stifli-flex-mcp'); ?></h1>
			
			<h2 class="nav-tab-wrapper">
				<a href="?page=stifli-flex-mcp&tab=settings" class="nav-tab <?php echo $active_tab === 'settings' ? 'nav-tab-active' : ''; ?>">
					<?php echo esc_html__('Settings', 'stifli-flex-mcp'); ?>
				</a>
				<a href="?page=stifli-flex-mcp&tab=profiles" class="nav-tab <?php echo $active_tab === 'profiles' ? 'nav-tab-active' : ''; ?>">
					<?php echo esc_html__('Profiles', 'stifli-flex-mcp'); ?>
				</a>
				<a href="?page=stifli-flex-mcp&tab=tools" class="nav-tab <?php echo $active_tab === 'tools' ? 'nav-tab-active' : ''; ?>">
					<?php echo esc_html__('WordPress Tools', 'stifli-flex-mcp'); ?>
				</a>
				<a href="?page=stifli-flex-mcp&tab=wc_tools" class="nav-tab <?php echo $active_tab === 'wc_tools' ? 'nav-tab-active' : ''; ?>">
					<?php echo esc_html__('WooCommerce Tools', 'stifli-flex-mcp'); ?>
				</a>
				<a href="?page=stifli-flex-mcp&tab=custom" class="nav-tab <?php echo $active_tab === 'custom' ? 'nav-tab-active' : ''; ?>">
					<?php echo esc_html__('Custom Tools', 'stifli-flex-mcp'); ?>
				</a>
				<a href="?page=stifli-flex-mcp&tab=logs" class="nav-tab <?php echo $active_tab === 'logs' ? 'nav-tab-active' : ''; ?>">
					<?php echo esc_html__('Logs', 'stifli-flex-mcp'); ?>
				</a>
				<a href="?page=stifli-flex-mcp&tab=help" class="nav-tab <?php echo $active_tab === 'help' ? 'nav-tab-active' : ''; ?>">
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
			} elseif ($active_tab === 'custom') {
				$this->renderCustomToolsTab();
			} elseif ($active_tab === 'logs') {
				$this->renderLogsTab();
			} elseif ($active_tab === 'help') {
				$this->renderHelpTab();
			}
			?>
		</div>
		<?php
	}
	
	private function renderSettingsTab() {
		$endpoint = rest_url($this->namespace . '/messages');
		$current_user = wp_get_current_user();
		$profile_url = get_edit_profile_url($current_user->ID) . '#application-passwords-section';
		
		// Build endpoint URL with auth placeholder
		$parsed = wp_parse_url($endpoint);
		$endpoint_with_auth = $parsed['scheme'] . '://' . $current_user->user_login . ':YOUR_APP_PASSWORD@' . $parsed['host'] . (isset($parsed['port']) ? ':' . $parsed['port'] : '') . $parsed['path'];
		?>
		
		<h2><?php echo esc_html__('🔐 Authentication with Application Passwords', 'stifli-flex-mcp'); ?></h2>
		
		<div style="background:#f0f9ff;border:1px solid #0073aa;padding:20px;margin:20px 0;border-radius:4px;">
			<h3 style="margin-top:0;color:#0073aa;"><?php echo esc_html__('How it works', 'stifli-flex-mcp'); ?></h3>
			<p><?php echo esc_html__('This plugin uses WordPress Application Passwords for secure API authentication. This is the recommended authentication method by WordPress.org.', 'stifli-flex-mcp'); ?></p>
			<ol>
				<li><?php echo esc_html__('Create an Application Password in your WordPress profile', 'stifli-flex-mcp'); ?></li>
				<li><?php echo esc_html__('Use HTTP Basic Authentication with your username and application password', 'stifli-flex-mcp'); ?></li>
				<li><?php echo esc_html__('API calls will execute with your user permissions', 'stifli-flex-mcp'); ?></li>
			</ol>
			<p>
				<a href="<?php echo esc_url($profile_url); ?>" class="button button-primary" target="_blank">
					<?php echo esc_html__('🔑 Create Application Password', 'stifli-flex-mcp'); ?>
				</a>
			</p>
		</div>
		
		<h2><?php echo esc_html__('📡 MCP Endpoint', 'stifli-flex-mcp'); ?></h2>
		
		<table class="form-table">
			<tr valign="top">
				<th scope="row"><?php echo esc_html__('JSON-RPC 2.0 Endpoint', 'stifli-flex-mcp'); ?></th>
				<td>
					<code id="sflmcp_endpoint" style="display:block;background:#f0f0f0;padding:8px;margin:5px 0;font-size:13px;"><?php echo esc_html($endpoint); ?></code>
					<button type="button" class="button" onclick="navigator.clipboard.writeText('<?php echo esc_js($endpoint); ?>');alert('<?php echo esc_js(__('Endpoint copied!', 'stifli-flex-mcp')); ?>');"><?php echo esc_html__('📋 Copy', 'stifli-flex-mcp'); ?></button>
					<p class="description"><?php echo esc_html__('Main endpoint for JSON-RPC 2.0 calls (methods: tools/list, tools/call).', 'stifli-flex-mcp'); ?></p>
				</td>
			</tr>
			<tr valign="top">
				<th scope="row"><?php echo esc_html__('Endpoint for Claude/ChatGPT', 'stifli-flex-mcp'); ?></th>
				<td>
					<code id="sflmcp_endpoint_auth" style="display:block;background:#f0f0f0;padding:8px;margin:5px 0;font-size:13px;word-break:break-all;"><?php echo esc_html($endpoint_with_auth); ?></code>
					<button type="button" class="button" onclick="navigator.clipboard.writeText('<?php echo esc_js($endpoint_with_auth); ?>');alert('<?php echo esc_js(__('Endpoint copied! Remember to replace YOUR_APP_PASSWORD with your actual Application Password (without spaces).', 'stifli-flex-mcp')); ?>');"><?php echo esc_html__('📋 Copy', 'stifli-flex-mcp'); ?></button>
					<p class="description"><?php echo esc_html__('Use this URL format for Claude, ChatGPT, and other MCP clients. Replace YOUR_APP_PASSWORD with your Application Password (without spaces).', 'stifli-flex-mcp'); ?></p>
				</td>
			</tr>
		</table>
		
		<h2><?php echo esc_html__('🚀 Quick Start Guide', 'stifli-flex-mcp'); ?></h2>
		
		<h3><?php echo esc_html__('Step 1: Create an Application Password', 'stifli-flex-mcp'); ?></h3>
		<ol>
			<li><?php echo sprintf(
				/* translators: %s: link to user profile */
				esc_html__('Go to %s', 'stifli-flex-mcp'),
				'<a href="' . esc_url($profile_url) . '" target="_blank">' . esc_html__('your profile page', 'stifli-flex-mcp') . '</a>'
			); ?></li>
			<li><?php echo esc_html__('Scroll to "Application Passwords" section', 'stifli-flex-mcp'); ?></li>
			<li><?php echo esc_html__('Enter a name like "MCP Client" and click "Add New Application Password"', 'stifli-flex-mcp'); ?></li>
			<li><?php echo esc_html__('Copy the generated password (it will only be shown once!)', 'stifli-flex-mcp'); ?></li>
		</ol>
		
		<div style="background:#e8f5e9;border:1px solid #4caf50;padding:12px 15px;margin:10px 0 20px;border-radius:4px;">
			<strong>💡 <?php echo esc_html__('Tip:', 'stifli-flex-mcp'); ?></strong>
			<?php echo esc_html__('WordPress displays the password with spaces for readability (e.g., "SbfX irNe J5t3 OUNK"). You can use it with or without spaces - both work! For cleaner code, remove the spaces when configuring your client.', 'stifli-flex-mcp'); ?>
		</div>
		
		<h3><?php echo esc_html__('Step 2: Test Your Endpoint', 'stifli-flex-mcp'); ?></h3>
		<p><?php echo esc_html__('Use HTTP Basic Authentication with your WordPress username and the application password:', 'stifli-flex-mcp'); ?></p>
		
		<pre style="background:#2c3e50;color:#ecf0f1;border:none;padding:15px;overflow:auto;border-radius:4px;">curl -X POST '<?php echo esc_url($endpoint); ?>' \
  -H 'Content-Type: application/json' \
  -u '<?php echo esc_html($current_user->user_login); ?>:YOUR_APPLICATION_PASSWORD' \
  -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}'</pre>
		
		<p><?php echo esc_html__('Or with the Authorization header:', 'stifli-flex-mcp'); ?></p>
		<pre style="background:#2c3e50;color:#ecf0f1;border:none;padding:15px;overflow:auto;border-radius:4px;">curl -X POST '<?php echo esc_url($endpoint); ?>' \
  -H 'Content-Type: application/json' \
  -H 'Authorization: Basic BASE64_ENCODED_CREDENTIALS' \
  -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}'</pre>
		
		<p class="description"><?php echo esc_html__('Note: BASE64_ENCODED_CREDENTIALS = base64(username:application_password)', 'stifli-flex-mcp'); ?></p>
		
		<h3><?php echo esc_html__('Step 3: Configure Your MCP Client', 'stifli-flex-mcp'); ?></h3>
		<p><?php echo esc_html__('Example configuration:', 'stifli-flex-mcp'); ?></p>
		<pre style="background:#f7f7f7;border:1px solid #ddd;padding:15px;overflow:auto;border-radius:4px;">{
  "url": "<?php echo esc_url($endpoint); ?>",
  "auth": {
    "type": "basic",
    "username": "<?php echo esc_html($current_user->user_login); ?>",
    "password": "YOUR_APPLICATION_PASSWORD"
  },
  "protocol": "JSON-RPC 2.0",
  "methods": ["tools/list", "tools/call"]
}</pre>
		
		<div style="background:#fff8e1;border:1px solid #ff9800;padding:15px;margin:20px 0;border-radius:4px;">
			<h4 style="margin-top:0;color:#e65100;">⚠️ <?php echo esc_html__('Security Notes', 'stifli-flex-mcp'); ?></h4>
			<ul style="margin-bottom:0;">
				<li><?php echo esc_html__('Application Passwords are tied to your WordPress user - API calls execute with your permissions', 'stifli-flex-mcp'); ?></li>
				<li><?php echo esc_html__('You can revoke an Application Password at any time from your profile', 'stifli-flex-mcp'); ?></li>
				<li><?php echo esc_html__('Create separate Application Passwords for different clients/integrations', 'stifli-flex-mcp'); ?></li>
				<li><?php echo esc_html__('Always use HTTPS in production to protect credentials', 'stifli-flex-mcp'); ?></li>
			</ul>
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
					<a href="?page=stifli-flex-mcp&tab=profiles" class="button button-small" style="margin-left: 10px;">
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
			<form method="post" action="" style="margin-bottom: 20px;">
				<?php wp_nonce_field('sflmcp_reseed_tools', 'sflmcp_reseed_nonce'); ?>
				<p>
					<button type="submit" class="button button-secondary" onclick="return confirm('<?php echo esc_js(__('This will delete all tools and reseed them. Are you sure?', 'stifli-flex-mcp')); ?>');"><?php echo esc_html__('Reset and Reseed Tools', 'stifli-flex-mcp'); ?></button>
					<span class="description"><?php echo esc_html__('Useful if you\'ve updated the plugin and new tools are available.', 'stifli-flex-mcp'); ?></span>
				</p>
			</form>
			
			<?php foreach ($grouped_tools as $category => $category_tools): ?>
				<?php $category_token_total = 0; foreach ($category_tools as $tool_meta) { $category_token_total += intval($tool_meta['token_estimate']); } ?>
				<h2><?php echo esc_html($category); ?> <small style="font-weight: normal;">(<?php echo esc_html__('estimated tokens:', 'stifli-flex-mcp'); ?> <?php echo esc_html(number_format_i18n($category_token_total)); ?>)</small></h2>
				<div class="sflmcp-bulk-actions">
					<button type="button" class="button button-small sflmcp-bulk-toggle" data-action="enable" data-category="<?php echo esc_attr($category); ?>"><?php echo esc_html__('Enable All', 'stifli-flex-mcp'); ?></button>
					<button type="button" class="button button-small sflmcp-bulk-toggle" data-action="disable" data-category="<?php echo esc_attr($category); ?>"><?php echo esc_html__('Disable All', 'stifli-flex-mcp'); ?></button>
				</div>
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th style="width:25%"><?php echo esc_html__('Tool', 'stifli-flex-mcp'); ?></th>
							<th style="width:45%"><?php echo esc_html__('Description', 'stifli-flex-mcp'); ?></th>
							<th style="width:15%"><?php echo esc_html__('Tokens (~)', 'stifli-flex-mcp'); ?></th>
							<th style="width:15%"><?php echo esc_html__('Status', 'stifli-flex-mcp'); ?></th>
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
					<a href="?page=stifli-flex-mcp&tab=profiles" class="button button-small" style="margin-left: 10px;">
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
				<h2><?php echo esc_html($category); ?> <small style="font-weight: normal;">(<?php echo esc_html__('estimated tokens:', 'stifli-flex-mcp'); ?> <?php echo esc_html(number_format_i18n($category_token_total)); ?>)</small></h2>
				<div class="sflmcp-bulk-actions">
					<button type="button" class="button button-small sflmcp-bulk-toggle" data-action="enable" data-category="<?php echo esc_attr($category); ?>"><?php echo esc_html__('Enable All', 'stifli-flex-mcp'); ?></button>
					<button type="button" class="button button-small sflmcp-bulk-toggle" data-action="disable" data-category="<?php echo esc_attr($category); ?>"><?php echo esc_html__('Disable All', 'stifli-flex-mcp'); ?></button>
				</div>
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th style="width:25%"><?php echo esc_html__('Tool', 'stifli-flex-mcp'); ?></th>
							<th style="width:45%"><?php echo esc_html__('Description', 'stifli-flex-mcp'); ?></th>
							<th style="width:15%"><?php echo esc_html__('Tokens (~)', 'stifli-flex-mcp'); ?></th>
							<th style="width:15%"><?php echo esc_html__('Status', 'stifli-flex-mcp'); ?></th>
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
		
		<div style="margin: 20px 0;">
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
				<span style="display:block;font-size:12px;"><?php echo esc_html__('Estimated token footprint (sum of enabled tools within the profile):', 'stifli-flex-mcp'); ?> <?php echo esc_html(number_format_i18n(intval($active_profile_info['tokens_sum']))); ?></span>
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
						<th style="width: 5%;"></th>
						<th style="width: 18%;"><?php echo esc_html__('Name', 'stifli-flex-mcp'); ?></th>
						<th style="width: 35%;"><?php echo esc_html__('Description', 'stifli-flex-mcp'); ?></th>
						<th style="width: 12%;"><?php echo esc_html__('Tools', 'stifli-flex-mcp'); ?></th>
						<th style="width: 12%;"><?php echo esc_html__('Tokens (~)', 'stifli-flex-mcp'); ?></th>
						<th style="width: 18%;"><?php echo esc_html__('Actions', 'stifli-flex-mcp'); ?></th>
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
									<span style="color: #2271b1; font-size: 20px;">●</span>
								<?php endif; ?>
							</td>
							<td><strong><?php echo esc_html($profile['profile_name']); ?></strong></td>
							<td>
								<?php echo esc_html($profile['profile_description']); ?>
								<br>
								<a href="#" class="SFLMCP-view-tools" data-tools="<?php echo esc_attr($tools_list_html); ?>" style="font-size: 12px; text-decoration: none;">
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
			<h3 style="margin-top: 30px;"><?php echo esc_html__('Custom Profiles', 'stifli-flex-mcp'); ?></h3>
			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th style="width: 5%;"></th>
						<th style="width: 18%;"><?php echo esc_html__('Name', 'stifli-flex-mcp'); ?></th>
						<th style="width: 35%;"><?php echo esc_html__('Description', 'stifli-flex-mcp'); ?></th>
						<th style="width: 12%;"><?php echo esc_html__('Tools', 'stifli-flex-mcp'); ?></th>
						<th style="width: 12%;"><?php echo esc_html__('Tokens (~)', 'stifli-flex-mcp'); ?></th>
						<th style="width: 18%;"><?php echo esc_html__('Actions', 'stifli-flex-mcp'); ?></th>
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
									<span style="color: #2271b1; font-size: 20px;">●</span>
								<?php endif; ?>
							</td>
							<td><strong><?php echo esc_html($profile['profile_name']); ?></strong></td>
							<td>
								<?php echo esc_html($profile['profile_description']); ?>
								<br>
								<a href="#" class="SFLMCP-view-tools" data-tools="<?php echo esc_attr($tools_list_html); ?>" style="font-size: 12px; text-decoration: none;">
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
		<input type="file" id="sflmcp_import_file" accept=".json" style="display: none;" />
		
		
		<?php
	}

	/**
	 * Render the Logs tab
	 */
	private function renderLogsTab() {
		$logging_enabled = get_option('sflmcp_logging_enabled', false);
		$log_contents = stifli_flex_mcp_get_log_contents(500);
		$log_size = stifli_flex_mcp_get_log_size();
		$log_file_path = stifli_flex_mcp_get_log_file_path();
		
		// Format file size
		if ($log_size >= 1048576) {
			$log_size_formatted = number_format($log_size / 1048576, 2) . ' MB';
		} elseif ($log_size >= 1024) {
			$log_size_formatted = number_format($log_size / 1024, 2) . ' KB';
		} else {
			$log_size_formatted = $log_size . ' bytes';
		}
		
		?>
		<h2><?php echo esc_html__('📋 Debug Logging', 'stifli-flex-mcp'); ?></h2>
		
		<div class="sflmcp-logs-info-box">
			<h3><?php echo esc_html__('About Logging', 'stifli-flex-mcp'); ?></h3>
			<p><?php echo esc_html__('When enabled, the plugin will log debug information to help troubleshoot issues. Logs include API requests, authentication events, and tool executions.', 'stifli-flex-mcp'); ?></p>
			<p><strong><?php echo esc_html__('Note:', 'stifli-flex-mcp'); ?></strong> <?php echo esc_html__('Logging can also be enabled by defining SFLMCP_DEBUG as true in wp-config.php.', 'stifli-flex-mcp'); ?></p>
		</div>
		
		<table class="form-table">
			<tr valign="top">
				<th scope="row"><?php echo esc_html__('Enable Logging', 'stifli-flex-mcp'); ?></th>
				<td>
					<label>
						<input type="checkbox" id="sflmcp_logging_enabled" <?php checked($logging_enabled, true); ?> />
						<?php echo esc_html__('Enable debug logging', 'stifli-flex-mcp'); ?>
					</label>
					<p class="description"><?php echo esc_html__('When enabled, debug information will be written to the log file.', 'stifli-flex-mcp'); ?></p>
					<?php if (defined('SFLMCP_DEBUG') && SFLMCP_DEBUG === true): ?>
						<p class="sflmcp-warning-text"><strong><?php echo esc_html__('⚠️ SFLMCP_DEBUG is defined as true in wp-config.php. Logging is always enabled.', 'stifli-flex-mcp'); ?></strong></p>
					<?php endif; ?>
				</td>
			</tr>
			<tr valign="top">
				<th scope="row"><?php echo esc_html__('Log File', 'stifli-flex-mcp'); ?></th>
				<td>
					<code class="sflmcp-log-path"><?php echo esc_html($log_file_path); ?></code>
					<p class="description"><?php echo sprintf(
						/* translators: %s: file size */
						esc_html__('Current file size: %s', 'stifli-flex-mcp'),
						esc_html($log_size_formatted)
					); ?></p>
				</td>
			</tr>
		</table>
		
		<p>
			<button type="button" class="button button-secondary" id="sflmcp_refresh_logs">
				<?php echo esc_html__('🔄 Refresh Logs', 'stifli-flex-mcp'); ?>
			</button>
			<button type="button" class="button button-secondary sflmcp-btn-danger" id="sflmcp_clear_logs">
				<?php echo esc_html__('🗑️ Clear Logs', 'stifli-flex-mcp'); ?>
			</button>
		</p>
		
		<h3><?php echo esc_html__('Log Contents', 'stifli-flex-mcp'); ?> <small class="sflmcp-small-text">(<?php echo esc_html__('last 500 lines', 'stifli-flex-mcp'); ?>)</small></h3>
		
		<textarea id="sflmcp_log_viewer" class="sflmcp-log-viewer" readonly><?php echo esc_textarea($log_contents); ?></textarea>
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
			<a href="?page=stifli-flex-mcp&tab=help" class="button button-link">
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
			<div id="sflmcp_tool_editor_modal" class="sflmcp-modal" style="display:none;">
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
								<div class="sflmcp-form-row" style="flex-grow:2;">
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
							
							<div id="sflmcp_advanced_settings" style="display:none;">
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
						<div style="flex-grow:1;"></div>
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
	 * AJAX handler to toggle logging
	 */
	public function ajax_toggle_logging() {
		check_ajax_referer('sflmcp_logs', 'nonce');
		
		if (!current_user_can('manage_options')) {
			wp_send_json_error(array('message' => __('Permission denied', 'stifli-flex-mcp')));
			return;
		}
		
		$enabled_raw = isset($_POST['enabled']) ? sanitize_text_field( wp_unslash( $_POST['enabled'] ) ) : '0';
		$enabled = (bool) intval($enabled_raw);
		update_option('sflmcp_logging_enabled', $enabled);
		
		wp_send_json_success(array('enabled' => $enabled));
	}

	/**
	 * AJAX handler to clear logs
	 */
	public function ajax_clear_logs() {
		check_ajax_referer('sflmcp_logs', 'nonce');
		
		if (!current_user_can('manage_options')) {
			wp_send_json_error(array('message' => __('Permission denied', 'stifli-flex-mcp')));
			return;
		}
		
		$result = stifli_flex_mcp_clear_log();
		
		if ($result) {
			wp_send_json_success();
		} else {
			wp_send_json_error(array('message' => __('Could not clear log file', 'stifli-flex-mcp')));
		}
	}

	/**
	 * AJAX handler to refresh logs
	 */
	public function ajax_refresh_logs() {
		check_ajax_referer('sflmcp_logs', 'nonce');
		
		if (!current_user_can('manage_options')) {
			wp_send_json_error(array('message' => __('Permission denied', 'stifli-flex-mcp')));
			return;
		}
		
		$contents = stifli_flex_mcp_get_log_contents(500);
		
		wp_send_json_success(array('contents' => $contents));
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
				<h3 style="margin-top:0;">📑 <?php esc_html_e('Table of Contents', 'stifli-flex-mcp'); ?></h3>
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

			<hr style="margin: 40px 0;">
			<p style="text-align: center; color: #666;">
				<strong>StifLi Flex MCP</strong> - 
				<a href="https://github.com/estebanstifli/stifli-flex-mcp" target="_blank">GitHub</a> | 
				<a href="https://wordpress.org/plugins/stifli-flex-mcp/" target="_blank">WordPress.org</a>
			</p>
		</div>
		<?php
	}
	/* phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,PluginCheck.Security.DirectDB.UnescapedDBParameter */
}





