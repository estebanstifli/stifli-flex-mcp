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
		$profile_tools = $wpdb->get_col(
			$wpdb->prepare($profile_tools_query, $profile_id)
		);
		
		if ($profile_tools === null) {
			wp_send_json_error(array('message' => 'Profile not found'));
		}
		
		// Disable all tools first
		$disable_tools_query = sprintf('UPDATE %s SET enabled = %%d', $tools_table_sql);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- bulk toggle requires direct UPDATE.
		$wpdb->query($wpdb->prepare($disable_tools_query, 0));
		
		// Enable profile tools
		if (!empty($profile_tools)) {
			$placeholders = implode(',', array_fill(0, count($profile_tools), '%s'));
			$enable_tools_query = 'UPDATE ' . $tools_table_sql . ' SET enabled = 1 WHERE tool_name IN (' . $placeholders . ')';
			$wpdb->query(
				$wpdb->prepare(
					$enable_tools_query,
					...$profile_tools
				)
			);
		}
		
		// Mark profile as active
		$deactivate_profiles_query = sprintf('UPDATE %s SET is_active = %%d', $profiles_table_sql);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- bulk toggle requires direct UPDATE.
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
		$original = $wpdb->get_row($wpdb->prepare($profile_query, $profile_id), ARRAY_A);
		
		if (!$original) {
			wp_send_json_error(array('message' => 'Profile not found'));
		}
		
		// Create new profile name
		$new_name = 'Copia de ' . $original['profile_name'];
		$counter = 1;
		$profile_name_check = sprintf('SELECT id FROM %s WHERE profile_name = %%s', $profiles_table_sql);
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
		$profile = $wpdb->get_row($wpdb->prepare($profile_query, $profile_id), ARRAY_A);
		
		if (!$profile) {
			wp_die('Profile not found', 404);
		}
		
		$tools_query = sprintf('SELECT tool_name FROM %s WHERE profile_id = %%d ORDER BY tool_name', $profile_tools_table_sql);
		$tools = $wpdb->get_col($wpdb->prepare($tools_query, $profile_id));
		
		// Get categories
		$categories = array();
		if (!empty($tools)) {
			$placeholders = implode(',', array_fill(0, count($tools), '%s'));
			$categories_query = 'SELECT DISTINCT category FROM ' . $tools_table_sql . ' WHERE tool_name IN (' . $placeholders . ') ORDER BY category';
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
		while ($wpdb->get_var($wpdb->prepare($profile_name_check, $name))) {
			$counter++;
			$name = $original_name . ' (' . $counter . ')';
		}
		
		// Validate tools exist
		$existing_tools_query = sprintf('SELECT tool_name FROM %s WHERE 1 = %%d', $tools_table_sql);
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
		$system_ids = $wpdb->get_col($wpdb->prepare($system_ids_query, 1));
		if (!empty($system_ids)) {
			$placeholders = implode(',', array_fill(0, count($system_ids), '%d'));
			$delete_relations_query = 'DELETE FROM ' . $profile_tools_table_sql . ' WHERE profile_id IN (' . $placeholders . ')';
			$wpdb->query($wpdb->prepare($delete_relations_query, ...$system_ids));
			$delete_profiles_query = sprintf('DELETE FROM %s WHERE is_system = %%d', $profiles_table_sql);
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
				<a href="?page=stifli-flex-mcp&tab=logs" class="nav-tab <?php echo $active_tab === 'logs' ? 'nav-tab-active' : ''; ?>">
					<?php echo esc_html__('Logs', 'stifli-flex-mcp'); ?>
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
			} elseif ($active_tab === 'logs') {
				$this->renderLogsTab();
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
		$profile_tools_table = StifliFlexMcpUtils::getPrefixedTable('sflmcp_profile_tools', false);
		$table_sql = StifliFlexMcpUtils::wrapTableNameForQuery($table);
		$profiles_table_sql = StifliFlexMcpUtils::wrapTableNameForQuery($profiles_table);
		$profile_tools_table_sql = StifliFlexMcpUtils::wrapTableNameForQuery($profile_tools_table);
		
		// Check if there's an active profile
		$active_profile_query = sprintf('SELECT * FROM %s WHERE is_active = %%d', $profiles_table_sql);
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
		
		// Handle tool enable/disable
		$tools_nonce = isset($_POST['sflmcp_tools_nonce']) ? sanitize_text_field( wp_unslash( $_POST['sflmcp_tools_nonce'] ) ) : '';
		if (!empty($tools_nonce) && wp_verify_nonce($tools_nonce, 'sflmcp_update_tools')) {
			$tool_enabled = StifliFlexMcpUtils::sanitizeCheckboxMap(
				isset($_POST['tool_enabled']) && is_array($_POST['tool_enabled'])
					? map_deep( wp_unslash( $_POST['tool_enabled'] ), 'sanitize_text_field' )
					: array()
			);
			if (!empty($tool_enabled)) {
				foreach ($tool_enabled as $tool_id => $enabled) {
					$wpdb->update(
						$table,
						array('enabled' => $enabled, 'updated_at' => current_time('mysql', true)),
						array('id' => $tool_id),
						array('%d', '%s'),
						array('%d')
					);
				}
				// Save current tools state to active profile
				if ($active_profile) {
					// Delete existing profile tools
					$wpdb->delete($profile_tools_table, array('profile_id' => $active_profile['id']), array('%d'));
					
					// Get all currently enabled tools (WordPress + WooCommerce)
					$enabled_tools_query = sprintf('SELECT tool_name FROM %s WHERE enabled = %%d', $table_sql);
					$enabled_tools = $wpdb->get_col($wpdb->prepare($enabled_tools_query, 1));
					
					// Insert enabled tools into profile
					if (!empty($enabled_tools)) {
						foreach ($enabled_tools as $tool_name) {
							$wpdb->insert(
								$profile_tools_table,
								array('profile_id' => $active_profile['id'], 'tool_name' => $tool_name),
								array('%d', '%s')
							);
						}
					}
					echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Tools updated and saved to active profile.', 'stifli-flex-mcp') . '</p></div>';
				} else {
					echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Tools updated successfully.', 'stifli-flex-mcp') . '</p></div>';
				}
			}
		}
		
		// Get all tools grouped by category (ONLY WordPress, excluding WooCommerce)
		$tools_query = sprintf('SELECT * FROM %s WHERE category NOT LIKE %%s ORDER BY category, tool_name', $table_sql);
		$tools = $wpdb->get_results($wpdb->prepare($tools_query, 'WooCommerce%'), ARRAY_A);
		$token_sum_query = sprintf('SELECT COALESCE(SUM(token_estimate),0) FROM %s WHERE category NOT LIKE %%s AND enabled = %%d', $table_sql);
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
		<p><?php echo esc_html__('Here you can manage which tools are available on the MCP server. Disabled tools will not appear in tools/list.', 'stifli-flex-mcp'); ?></p>
		<p><strong><?php echo esc_html__('Total estimated tokens for enabled WordPress tools:', 'stifli-flex-mcp'); ?></strong> <?php echo esc_html(number_format_i18n($enabled_token_total)); ?></p>
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
		
		<form method="post" action="">
			<?php wp_nonce_field('sflmcp_update_tools', 'sflmcp_tools_nonce'); ?>
			
			<?php foreach ($grouped_tools as $category => $category_tools): ?>
				<?php $category_token_total = 0; foreach ($category_tools as $tool_meta) { $category_token_total += intval($tool_meta['token_estimate']); } ?>
				<h2><?php echo esc_html($category); ?> <small style="font-weight: normal;">(<?php echo esc_html__('estimated tokens:', 'stifli-flex-mcp'); ?> <?php echo esc_html(number_format_i18n($category_token_total)); ?>)</small></h2>
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
						<?php foreach ($category_tools as $tool): ?>
							<tr>
								<td><code><?php echo esc_html($tool['tool_name']); ?></code></td>
								<td><?php echo esc_html($tool['tool_description']); ?></td>
								<td><?php echo esc_html(number_format_i18n(intval($tool['token_estimate']))); ?></td>
								<td>
									<label>
										<input type="hidden" name="tool_enabled[<?php echo intval($tool['id']); ?>]" value="0" />
										<input type="checkbox" name="tool_enabled[<?php echo intval($tool['id']); ?>]" value="1" <?php checked(intval($tool['enabled']), 1); ?> />
										<?php echo esc_html__('Enabled', 'stifli-flex-mcp'); ?>
									</label>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<br/>
			<?php endforeach; ?>
			
			<?php submit_button(__('Save Changes', 'stifli-flex-mcp')); ?>
		</form>
		<?php endif; ?>
		<?php
	}
	
	private function renderWCToolsTab() {
		global $wpdb;
		$table = StifliFlexMcpUtils::getPrefixedTable('sflmcp_tools', false);
		$profiles_table = StifliFlexMcpUtils::getPrefixedTable('sflmcp_profiles', false);
		$profile_tools_table = StifliFlexMcpUtils::getPrefixedTable('sflmcp_profile_tools', false);
		$table_sql = StifliFlexMcpUtils::wrapTableNameForQuery($table);
		$profiles_table_sql = StifliFlexMcpUtils::wrapTableNameForQuery($profiles_table);
		$profile_tools_table_sql = StifliFlexMcpUtils::wrapTableNameForQuery($profile_tools_table);
		
		// Check if there's an active profile
		$active_profile_query = sprintf('SELECT * FROM %s WHERE is_active = %%d', $profiles_table_sql);
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
		
		// Handle tool enable/disable
		$tools_nonce = isset($_POST['sflmcp_tools_nonce']) ? sanitize_text_field( wp_unslash( $_POST['sflmcp_tools_nonce'] ) ) : '';
		if (!empty($tools_nonce) && wp_verify_nonce($tools_nonce, 'sflmcp_update_tools')) {
			$tool_enabled = StifliFlexMcpUtils::sanitizeCheckboxMap(
				isset($_POST['tool_enabled']) && is_array($_POST['tool_enabled'])
					? map_deep( wp_unslash( $_POST['tool_enabled'] ), 'sanitize_text_field' )
					: array()
			);
			if (!empty($tool_enabled)) {
				foreach ($tool_enabled as $tool_id => $enabled) {
					$wpdb->update(
						$table,
						array('enabled' => $enabled, 'updated_at' => current_time('mysql', true)),
						array('id' => $tool_id),
						array('%d', '%s'),
						array('%d')
					);
				}
				// Save current tools state to active profile
				if ($active_profile) {
					// Delete existing profile tools
					$wpdb->delete($profile_tools_table, array('profile_id' => $active_profile['id']), array('%d'));
					
					// Get all currently enabled tools (WordPress + WooCommerce)
					$enabled_tools_query = sprintf('SELECT tool_name FROM %s WHERE enabled = %%d', $table_sql);
					$enabled_tools = $wpdb->get_col($wpdb->prepare($enabled_tools_query, 1));
					
					// Insert enabled tools into profile
					if (!empty($enabled_tools)) {
						foreach ($enabled_tools as $tool_name) {
							$wpdb->insert(
								$profile_tools_table,
								array('profile_id' => $active_profile['id'], 'tool_name' => $tool_name),
								array('%d', '%s')
							);
						}
					}
					echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Tools updated and saved to active profile.', 'stifli-flex-mcp') . '</p></div>';
				} else {
					echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Tools updated successfully.', 'stifli-flex-mcp') . '</p></div>';
				}
			}
		}
		
		// Get all WooCommerce tools grouped by category
		$wc_tools_query = sprintf("SELECT * FROM %s WHERE category LIKE %%s ORDER BY category, tool_name", $table_sql);
		$tools = $wpdb->get_results($wpdb->prepare($wc_tools_query, 'WooCommerce%'), ARRAY_A);
		$wc_token_sum_query = sprintf("SELECT COALESCE(SUM(token_estimate),0) FROM %s WHERE category LIKE %%s AND enabled = %%d", $table_sql);
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
		<p><?php echo esc_html__('Here you can manage which WooCommerce tools are available on the MCP server. Disabled tools will not appear in tools/list.', 'stifli-flex-mcp'); ?></p>
		<p><strong><?php echo esc_html__('Total estimated tokens for enabled WooCommerce tools:', 'stifli-flex-mcp'); ?></strong> <?php echo esc_html(number_format_i18n($enabled_token_total)); ?></p>
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
		<form method="post" action="">
			<?php wp_nonce_field('sflmcp_update_tools', 'sflmcp_tools_nonce'); ?>
			
			<?php foreach ($grouped_tools as $category => $category_tools): ?>
				<?php $category_token_total = 0; foreach ($category_tools as $tool_meta) { $category_token_total += intval($tool_meta['token_estimate']); } ?>
				<h2><?php echo esc_html($category); ?> <small style="font-weight: normal;">(<?php echo esc_html__('estimated tokens:', 'stifli-flex-mcp'); ?> <?php echo esc_html(number_format_i18n($category_token_total)); ?>)</small></h2>
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
						<?php foreach ($category_tools as $tool): ?>
							<tr>
								<td><code><?php echo esc_html($tool['tool_name']); ?></code></td>
								<td><?php echo esc_html($tool['tool_description']); ?></td>
								<td><?php echo esc_html(number_format_i18n(intval($tool['token_estimate']))); ?></td>
								<td>
									<label>
										<input type="hidden" name="tool_enabled[<?php echo intval($tool['id']); ?>]" value="0" />
										<input type="checkbox" name="tool_enabled[<?php echo intval($tool['id']); ?>]" value="1" <?php checked(intval($tool['enabled']), 1); ?> />
										<?php echo esc_html__('Enabled', 'stifli-flex-mcp'); ?>
									</label>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<br/>
			<?php endforeach; ?>
			
			<?php submit_button(__('Save Changes', 'stifli-flex-mcp')); ?>
		</form>
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
		$profiles = $wpdb->get_results($wpdb->prepare($profiles_query, 1), ARRAY_A);

		$total_tools_query = sprintf('SELECT COUNT(*) FROM %s WHERE 1 = %%d', $tools_table_sql);
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
	/* phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,PluginCheck.Security.DirectDB.UnescapedDBParameter */
}





