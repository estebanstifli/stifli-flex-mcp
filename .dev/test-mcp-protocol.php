<?php
define( 'ABSPATH', __DIR__ . '/' );
define( 'ARRAY_A', 'ARRAY_A' );
require_once dirname( __DIR__ ) . '/models/utils.php';
require_once dirname( __DIR__ ) . '/mod.php';

function get_bloginfo( $key ) { return 'Test site'; }
function get_current_user_id() { return isset( $GLOBALS['test_user'] ) ? $GLOBALS['test_user'] : 1; }
function sanitize_text_field( $value ) { return (string) $value; }
function wp_generate_uuid4() { return 'test-uuid'; }
function stifli_flex_mcp_log( ...$args ) { $GLOBALS['test_logs'][] = $args; }
function get_option( $key, $default = false ) { return isset( $GLOBALS['options'][$key] ) ? $GLOBALS['options'][$key] : $default; }
function add_option( $key, $value, ...$args ) { $GLOBALS['options'][$key] = $value; return true; }
function update_option( $key, $value, ...$args ) { $GLOBALS['options'][$key] = $value; return true; }
function delete_option( $key ) { unset( $GLOBALS['options'][$key] ); return true; }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
function stifli_flex_mcp_is_gsc_enabled_for_runtime() { return false; }
function get_transient( $key ) { return isset( $GLOBALS['transients'][$key] ) ? $GLOBALS['transients'][$key] : false; }
function set_transient( $key, $value, $ttl ) { $GLOBALS['transients'][$key] = $value; return true; }
function delete_transient( $key ) { unset( $GLOBALS['transients'][$key] ); return true; }
function wp_next_scheduled( ...$args ) { return false; }
function wp_schedule_single_event( ...$args ) { return true; }
function apply_filters( $name, $value, ...$args ) { return $value; }
function get_post_types( ...$args ) { return array(); }
function wp_get_theme() { return new ProtocolTestTheme(); }
class ProtocolTestTheme {
	public function get_template() { return 'test-theme'; }
	public function get_stylesheet() { return 'test-theme'; }
}
class WP_Error {
	public $code;
	public $message;
	public $data;
	public function __construct( $code, $message, $data = null ) { $this->code = $code; $this->message = $message; $this->data = $data; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
	public function get_error_data() { return $this->data; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }

class WP_REST_Response {
	public $data;
	public $status;
	public $headers = array();
	public function __construct( $data = null, $status = 200 ) { $this->data = $data; $this->status = $status; }
	public function set_headers( $headers ) { $this->headers = array_merge( $this->headers, $headers ); }
	public function header( $name, $value ) { $this->headers[$name] = $value; }
	public function get_status() { return $this->status; }
	public function get_data() { return $this->data; }
}
class StifliFlexMcp_ChangeTracker {
	public static function getInstance() { return new self(); }
	public function setSessionId( $id ) {}
	public static function setSourceContext( ...$args ) {}
}
class ProtocolRequest {
	public $data;
	public $headers;
	public $http_method = 'POST';
	public $query = array();
	public function __construct( $method, $version = '2026-07-28' ) {
		$this->data = array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => array( '_meta' => array( 'io.modelcontextprotocol/protocolVersion' => $version, 'io.modelcontextprotocol/clientCapabilities' => (object) array() ) ) );
		$this->headers = array( 'MCP-Protocol-Version' => $version, 'Mcp-Method' => $method );
	}
	public function get_header( $name ) { return isset( $this->headers[$name] ) ? $this->headers[$name] : ''; }
	public function get_param( $name ) { return isset( $this->query[$name] ) ? $this->query[$name] : null; }
	public function get_body() { return json_encode( $this->data ); }
	public function get_route() { return '/stifli-flex-mcp/v1/messages'; }
	public function get_method() { return $this->http_method; }
}

function check_same( $expected, $actual, $label ) {
	if ( $expected !== $actual ) {
		throw new RuntimeException( $label . ': ' . var_export( $actual, true ) );
	}
	if ( ! in_array( '--schema', $GLOBALS['argv'], true ) ) {
		echo 'PASS ' . $label . PHP_EOL;
	}
}

foreach ( array( '', null, 'example-secret', 0, '0', false, array(), array( 'nested' => 'secret' ) ) as $value ) {
	$expected = null === $value || '' === $value ? $value : '[REDACTED]';
	check_same( $expected, sflmcp_redact_sensitive_value( $value, 'api_key' ), 'sensitive scalar or container: ' . json_encode( $value ) );
	$nested = array( 'settings' => array( 'api_key' => $value ), 'label' => 'Public label' );
	$redacted = sflmcp_redact_sensitive_value( $nested );
	check_same( array( 'settings' => array( 'api_key' => $expected ), 'label' => 'Public label' ), $redacted, 'nested redaction preserves public fields' );
	check_same( $expected, StifliFlexMcpUtils::redactSecrets( $redacted )['settings']['api_key'], 'plugin settings second pass preserves empty values' );
	$redacted_object = sflmcp_redact_sensitive_value( (object) array( 'settings' => (object) array( 'client_secret' => $value ) ) );
	check_same( true, is_object( $redacted_object->settings ), 'redaction preserves object structure' );
	check_same( $expected, $redacted_object->settings->client_secret, 'object secret follows empty-value rule' );
}

$reflection = new ReflectionClass( 'StifliFlexMcp' );
$server = $reflection->newInstanceWithoutConstructor();
$supported = $reflection->getMethod( 'getSupportedProtocolVersions' );
$supported->setAccessible( true );
check_same( array( '2026-07-28', '2025-11-25', '2025-06-18', '2025-03-26' ), $supported->invoke( $server ), 'supported versions' );
$negotiate = $reflection->getMethod( 'negotiateProtocolVersion' );
$negotiate->setAccessible( true );
foreach ( array( '2025-11-25', '2025-06-18', '2025-03-26' ) as $version ) {
	check_same( $version, $negotiate->invoke( $server, $version ), 'legacy initialize ' . $version );
	$request = new ProtocolRequest( 'initialize', $version );
	$request->data['params'] = array( 'protocolVersion' => $version, 'capabilities' => (object) array(), 'clientInfo' => array( 'name' => 'Test', 'version' => '1.0' ) );
	check_same( $version, $server->handleMessage( $request )->data['result']['protocolVersion'], 'legacy initialize messages transport ' . $version );
	check_same( $version, $server->handleSSE( $request )->data['result']['protocolVersion'], 'legacy initialize SSE POST ' . $version );
	$legacy_identity = $server->handleMessage( $request )->data['result']['serverInfo'];
	check_same( 'Test site Flex MCP', $legacy_identity->name, 'legacy server name unchanged' );
	check_same( '2025-11-25' === $version, isset( $legacy_identity->websiteUrl ), 'site metadata only on supported identity versions' );
}
check_same( '2025-11-25', $negotiate->invoke( $server, '2026-07-28' ), 'initialize never negotiates modern semantics' );
check_same( '2025-11-25', $negotiate->invoke( $server, 'unknown' ), 'legacy fallback' );

$request = new ProtocolRequest( 'server/discover' );
$response = $server->handleMessage( $request );
check_same( 200, $response->status, 'modern POST without session returns a result' );
check_same( 'complete', $response->data['result']['resultType'], 'discovery discriminator' );
check_same( 'private', $response->data['result']['cacheScope'], 'private discovery cache' );
check_same( 0, $response->data['result']['ttlMs'], 'conservative cache TTL' );
check_same( 'Test site Flex MCP', $response->data['result']['_meta']['io.modelcontextprotocol/serverInfo']->name, 'modern identity' );
foreach ( array( 'https://test.example' => 'test.example', 'https://test.example/36' => 'test.example/36', 'https://test.example/37' => 'test.example/37', 'https://test.example:8443/36' => 'test.example:8443/36' ) as $site_url => $site_label ) {
	$GLOBALS['test_home_url'] = $site_url;
	$site_response = $server->handleMessage( new ProtocolRequest( 'server/discover' ) );
	$identity = $site_response->data['result']['_meta']['io.modelcontextprotocol/serverInfo'];
	check_same( 'Test site Flex MCP', $identity->name, 'site identification preserves server name' );
	check_same( 'Test site Flex MCP (' . $site_label . ')', $identity->title, 'identity includes domain, port and subdirectory' );
	check_same( $site_url . '/', $identity->websiteUrl, 'identity website URL uses configured home' );
	check_same( 'WordPress site: ' . $site_url . '/', $identity->description, 'identity description identifies site' );
}
unset( $GLOBALS['test_home_url'] );
$request->query['session_id'] = 'old-session';
check_same( 200, $server->handleMessage( $request )->status, 'modern ignores legacy session parameter' );
$request->headers['Mcp-Method'] = 'tools/call';
check_same( -32020, $server->handleMessage( $request )->data['error']['code'], 'reject mismatched header' );
$request = new ProtocolRequest( 'server/discover', '2099-01-01' );
check_same( -32022, $server->handleMessage( $request )->data['error']['code'], 'unsupported modern version' );
$request = new ProtocolRequest( 'prompts/list' );
$response = $server->handleMessage( $request );
check_same( array(), $response->data['result']['prompts'], 'empty prompts remain an array' );
$request = new ProtocolRequest( 'unknown/method' );
check_same( 404, $server->handleMessage( $request )->status, 'modern unknown method HTTP status' );
$request = new ProtocolRequest( 'ping', '2025-11-25' );
check_same( 200, $server->handleMessage( $request )->status, 'legacy POST returns result with tracker enabled' );
$request = new ProtocolRequest( 'tools/call' );
$request->data['params']['name'] = 'mcp_ping';
check_same( -32020, $server->handleMessage( $request )->data['error']['code'], 'tool call requires name header' );

$request->data['params']['name'] = 'wp_generate_image';
$request->headers['Mcp-Name'] = 'wp_generate_image';
$request->data['params']['_meta']['io.modelcontextprotocol/clientCapabilities'] = array( 'extensions' => array( 'io.modelcontextprotocol/tasks' => (object) array() ) );
$response = $server->handleMessage( $request );
check_same( 'task', $response->data['result']['resultType'], 'task extension creation' );
check_same( 'test-uuid', $response->data['result']['taskId'], 'task handle is flat' );
check_same( 300000, $response->data['result']['ttlMs'], 'modern task TTL' );
$request->data['method'] = 'tasks/get';
$request->headers['Mcp-Method'] = 'tasks/get';
$request->data['params']['taskId'] = 'test-uuid';
check_same( 'working', $server->handleMessage( $request )->data['result']['status'], 'poll task' );
$GLOBALS['test_user'] = 2;
check_same( -32602, $server->handleMessage( $request )->data['error']['code'], 'task owner isolation' );
$GLOBALS['test_user'] = 1;
$key = 'sflmcp_task_' . md5( 'test-uuid' );
$GLOBALS['transients'][$key]['status'] = 'completed';
$GLOBALS['transients'][$key]['payload'] = array( 'result' => array( 'content' => array( array( 'type' => 'text', 'text' => 'Generated' ) ) ) );
check_same( 'complete', $server->handleMessage( $request )->data['result']['result']['resultType'], 'poll includes completed result' );
$request->data['params']['_meta']['io.modelcontextprotocol/clientCapabilities'] = (object) array();
check_same( -32021, $server->handleMessage( $request )->data['error']['code'], 'tasks require client capability' );
$missing_capability = $server->handleMessage( $request );
check_same( 400, $missing_capability->status, 'missing capability HTTP status' );
check_same( true, isset( $missing_capability->data['error']['data']['requiredCapabilities']['extensions']['io.modelcontextprotocol/tasks'] ), 'missing capability identifies required extension' );
$request = new ProtocolRequest( 'server/discover' );
$request->data['id'] = null;
check_same( -32600, $server->handleMessage( $request )->data['error']['code'], 'modern null ID is invalid' );
unset( $request->data['id'] );
check_same( 202, $server->handleMessage( $request )->status, 'notification accepted without JSON-RPC result' );
check_same( 405, $server->handleMessagesGet( $request )->status, 'standalone GET is unavailable' );
$request->http_method = 'GET';
check_same( 405, $server->handleSSE( $request )->status, 'modern GET never opens legacy SSE' );
$request = new ProtocolRequest( 'tools/call' );
$request->data['params']['name'] = 'wp_generate_image';
$request->headers['Mcp-Name'] = '=?base64?' . base64_encode( 'wp_generate_image' ) . '?=';
$request->data['params']['_meta']['io.modelcontextprotocol/clientCapabilities'] = array( 'extensions' => array( 'io.modelcontextprotocol/tasks' => null ) );
check_same( -32602, $server->handleMessage( $request )->data['error']['code'], 'null extension is not capability opt-in' );
$request->data['params']['_meta']['io.modelcontextprotocol/clientCapabilities']['extensions']['io.modelcontextprotocol/tasks'] = (object) array();
$request->data['params']['arguments'] = array();
check_same( -32602, $server->handleMessage( $request )->data['error']['code'], 'modern arguments cannot be a JSON array' );
$request->data['params']['arguments'] = (object) array();
check_same( 'task', $server->handleMessage( $request )->data['result']['resultType'], 'base64 name header and object arguments accepted' );
$request = new ProtocolRequest( 'tasks/cancel' );
$request->data['params']['taskId'] = 'test-uuid';
$request->data['params']['_meta']['io.modelcontextprotocol/clientCapabilities'] = array( 'extensions' => array( 'io.modelcontextprotocol/tasks' => (object) array() ) );
check_same( 'complete', $server->handleMessage( $request )->data['result']['resultType'], 'task cancellation acknowledgement' );
check_same( 'cancelled', $GLOBALS['transients'][$key]['status'], 'task cancellation persists' );
$request->data['method'] = $request->headers['Mcp-Method'] = 'tasks/get';
$GLOBALS['transients'][$key]['status'] = 'failed';
$GLOBALS['transients'][$key]['payload'] = array( 'error' => array( 'code' => -32603, 'message' => 'Worker failed' ) );
$failed_task = $server->handleMessage( $request )->data['result'];
check_same( -32603, $failed_task['error']['code'], 'failed task exposes JSON-RPC error' );
$GLOBALS['transients'][$key]['payload'] = array( 'result' => array( 'content' => array(), 'isError' => true ) );
check_same( 'completed', $server->handleMessage( $request )->data['result']['status'], 'tool-level error is a completed task result' );

check_same( true, StifliFlexMcpUtils::checkRateLimit( 'test-limit', 1 ), 'rate first request' );
$limited = StifliFlexMcpUtils::checkRateLimit( 'test-limit', 1 );
check_same( 429, $limited->get_error_data()['status'], 'rate exceeded' );
check_same( true, $limited->get_error_data()['retry_after'] > 0, 'retry time available' );
check_same( true, StifliFlexMcpUtils::checkRateLimit( 'other-user', 1 ), 'rate identity isolation' );
$_SERVER['REMOTE_ADDR'] = '203.0.113.2';
$_SERVER['HTTP_CF_CONNECTING_IP'] = '198.51.100.4';
check_same( '203.0.113.2', StifliFlexMcpUtils::getClientIp(), 'untrusted proxy header ignored' );
require_once dirname( __DIR__ ) . '/oauth/class-oauth-server.php';
function home_url( $path = '' ) { return ( isset( $GLOBALS['test_home_url'] ) ? $GLOBALS['test_home_url'] : 'https://test.example' ) . $path; }
function rest_url( $path = '' ) { return home_url( '/wp-json/' . $path ); }
$oauth_reflection = new ReflectionClass( 'StifliFlexMcp_OAuth_Server' );
$metadata = $oauth_reflection->getMethod( 'authorization_server_metadata' );
$metadata->setAccessible( true );
check_same( true, $metadata->invoke( $oauth_reflection->newInstanceWithoutConstructor() )['authorization_response_iss_parameter_supported'], 'OAuth issuer response advertised' );
require_once dirname( __DIR__ ) . '/oauth/class-oauth-storage.php';
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
$client_url = 'https://client.example/oauth/client.json';
$document = array( 'client_id' => $client_url, 'client_name' => 'Test client', 'redirect_uris' => array( 'http://127.0.0.1:1234/callback', 'https://client.example/callback' ) );
check_same( true, StifliFlexMcp_OAuth_Storage::validate_cimd_document( $client_url, $document ), 'valid CIMD public client' );
check_same( false, StifliFlexMcp_OAuth_Storage::validate_cimd_document( 'https://other.example/client.json', $document ), 'CIMD exact client identity' );
$document['redirect_uris'] = array( 'http://untrusted.example/callback' );
check_same( false, StifliFlexMcp_OAuth_Storage::validate_cimd_document( $client_url, $document ), 'CIMD rejects non-loopback plaintext redirect' );
check_same( true, strlen( StifliFlexMcp_OAuth_Storage::client_storage_id( $client_url . str_repeat( 'x', 500 ) ) ) < 128, 'CIMD long IDs fit storage without truncation' );
$GLOBALS['options']['sflmcp_oauth_require_approval'] = '1';
$storage = StifliFlexMcp_OAuth_Storage::get_instance();
$storage->mark_new_client_for_review( $client_url );
check_same( true, $storage->client_is_pending( $client_url ), 'new CIMD client awaits approval' );
check_same( false, $storage->client_is_pending( 'existing-client' ), 'existing clients retain authorization' );
$response = new WP_REST_Response( array( 'data' => array( 'retry_after' => 17 ) ), 429 );
$response = $server->addNoCacheHeadersForNamespace( $response, null, new ProtocolRequest( 'tools/list' ) );
check_same( '17', $response->headers['Retry-After'], '429 exposes Retry-After header' );
$GLOBALS['options']['blogname'] = 'Before';
$before_options = $GLOBALS['options'];
$plan = StifliFlexMcpUtils::buildOptionWritePlan( array( 'blogname' => 'After', 'siteurl' => 'https://other.example' ) );
check_same( $before_options, $GLOBALS['options'], 'option preview never writes' );
check_same( 'Before', $plan['changes']->blogname['before'], 'option preview before' );
check_same( 'After', $plan['changes']->blogname['after'], 'option preview after' );
check_same( true, isset( $plan['blocked']->siteurl ), 'preview preserves option denylist' );
$GLOBALS['options']['blogname'] = 'Changed concurrently';
check_same( false, $plan['state_hash'] === StifliFlexMcpUtils::buildOptionWritePlan( array( 'blogname' => 'After', 'siteurl' => 'https://other.example' ) )['state_hash'], 'preview detects concurrent changes' );
function wp_safe_remote_get( $url, $args ) { $GLOBALS['last_http_args'] = $args; return $GLOBALS['http_response']; }
function wp_remote_retrieve_body( $response ) { return $response['body']; }
function wp_remote_retrieve_response_code( $response ) { return $response['status']; }
function wp_remote_retrieve_header( $response, $name ) { return isset( $response['headers'][$name] ) ? $response['headers'][$name] : ''; }
$GLOBALS['http_response'] = array( 'status' => 200, 'headers' => array( 'content-type' => 'text/html' ), 'body' => '<html><head><title>Updated</title><link rel="canonical" href="https://test.example/page"><meta name="description" content="New description"></head><body>Visible text<script>Hidden script</script></body></html>' );
$verification = StifliFlexMcpUtils::verifyRenderedPage( array( 'url' => 'https://test.example/page', 'expected_title' => 'Updated', 'expected_text' => array( 'Visible text' ) ) );
check_same( true, $verification['verified'], 'HTML expectations match' );
check_same( false, $verification['javascript_executed'], 'HTML verification does not claim browser rendering' );
check_same( 0, $GLOBALS['last_http_args']['redirection'], 'verification cannot redirect to another origin' );
$verification = StifliFlexMcpUtils::verifyRenderedPage( array( 'url' => 'https://test.example/page', 'expected_text' => array( 'Hidden script' ) ) );
check_same( false, $verification['verified'], 'script text is not visible page content' );
check_same( true, is_wp_error( StifliFlexMcpUtils::verifyRenderedPage( array( 'url' => 'https://other.example/page' ) ) ), 'verification rejects foreign origins' );
check_same( true, is_wp_error( StifliFlexMcpUtils::verifyRenderedPage( array( 'url' => 'https://test.example/wp-admin/' ) ) ), 'verification rejects admin pages' );
$request = new ProtocolRequest( 'prompts/list' );
$request->data['params']['arguments'] = array( 'password' => 'never-store-this' );
StifliFlexMcpUtils::recordProtocolUsage( $request, new WP_REST_Response( array( 'result' => array() ) ) );
check_same( false, get_option( 'sflmcp_protocol_usage', false ), 'statistics disabled by default' );
$GLOBALS['options']['sflmcp_protocol_usage_enabled'] = true;
StifliFlexMcpUtils::recordProtocolUsage( $request, new WP_REST_Response( array( 'result' => array() ) ) );
$usage = get_option( 'sflmcp_protocol_usage' );
check_same( 1, $usage[gmdate('Y-m-d')]['versions']['2026-07-28']['requests'], 'protocol volume recorded' );
check_same( false, strpos( json_encode( $usage ), 'never-store-this' ) !== false, 'statistics exclude tool arguments' );
$GLOBALS['options']['sflmcp_protocol_usage']['2000-01-01'] = array();
check_same( false, isset( StifliFlexMcpUtils::pruneProtocolUsage()['2000-01-01'] ), 'old statistics pruned' );
$request->data['params']['_meta']['io.modelcontextprotocol/clientCapabilities'] = array();
check_same( -32602, $server->handleMessage( $request )->data['error']['code'], 'capabilities must be a JSON object' );

require_once dirname( __DIR__ ) . '/models/model.php';
function current_user_can( ...$args ) { return ! isset( $GLOBALS['permission'] ) || $GLOBALS['permission']; }
function do_action( ...$args ) {}
function is_multisite() { return false; }
function post_type_exists( $type ) { return false; }
class ProtocolTestDatabase {
	public $prefix = 'wp_';
	public $deleted = array();
	public function get_row( $sql ) { return (object) array( 'client_uri' => $GLOBALS['client_url'] ); }
	public function query( $sql ) { return 1; }
	public function delete( $table, $where, $format ) { $this->deleted[$table] = $where; return 1; }
	public function esc_like( $value ) { return $value; }
	public function prepare( $sql, ...$args ) { return $sql . ' ' . implode( ' ', $args ); }
	public function get_var( $sql ) {
		if ( isset( $GLOBALS['ability_rows'] ) && false !== strpos( $sql, 'wp_sflmcp_abilities' ) ) { return 'wp_sflmcp_abilities'; }
		if ( isset( $GLOBALS['enabled_tool_rows'] ) && false !== strpos( $sql, 'wp_sflmcp_tools' ) ) { return 'wp_sflmcp_tools'; }
		return null;
	}
	public function get_results( $sql, ...$args ) {
		if ( isset( $GLOBALS['ability_rows'] ) && false !== strpos( $sql, 'sflmcp_abilities' ) ) {
			return array_filter( $GLOBALS['ability_rows'], function( $row ) { return ! empty( $row->enabled ); } );
		}
		if ( isset( $GLOBALS['enabled_tool_rows'] ) && false !== strpos( $sql, 'sflmcp_tools' ) ) { return $GLOBALS['enabled_tool_rows']; }
		return array();
	}
}
$GLOBALS['wpdb'] = new ProtocolTestDatabase();
$cache_key = 'sflmcp_cimd_' . hash( 'sha256', $client_url );
set_transient( $cache_key, $document, 300 );
$storage->delete_client( $client_url );
check_same( false, $storage->client_is_pending( $client_url ), 'deleted client review state removed' );
check_same( false, get_transient( $cache_key ), 'deleted CIMD metadata cache removed' );
check_same( StifliFlexMcp_OAuth_Storage::client_storage_id( $client_url ), $GLOBALS['wpdb']->deleted['wp_sflmcp_oauth_codes']['client_id'], 'deleted CIMD codes use internal identity' );
$storage->mark_new_client_for_review( $client_url );
check_same( true, $storage->client_is_pending( $client_url ), 'CIMD re-registration requires new approval' );
class OptionTestModel extends StifliFlexMcpModel {
	public function getTools() { return array(); }
}
$GLOBALS['options']['sflmcp_changelog_enabled'] = false;
$model = new OptionTestModel();
$before_options = $GLOBALS['options'];
$result = $model->dispatchTool( 'wp_update_option', array( 'option' => 'blogname', 'value' => 'Planned', 'dry_run' => true ), 1 );
check_same( $before_options, $GLOBALS['options'], 'dispatcher dry-run does not write' );
check_same( true, $result['result']['structuredContent']['dry_run'], 'dispatcher returns write plan' );
$hash = $result['result']['structuredContent']['state_hash'];
$GLOBALS['options']['blogname'] = 'Concurrent';
$result = $model->dispatchTool( 'wp_update_option', array( 'option' => 'blogname', 'value' => 'Overwrite', 'expected_hash' => $hash ), 1 );
check_same( -32602, $result['error']['code'], 'dispatcher rejects stale write' );
check_same( 'Concurrent', $GLOBALS['options']['blogname'], 'stale write leaves current value untouched' );
$GLOBALS['permission'] = false;
$result = $model->dispatchTool( 'wp_update_settings', array( 'settings' => array( 'blogname' => 'No access' ), 'dry_run' => true ), 1 );
check_same( -32603, $result['error']['code'], 'dry-run still requires capability' );
$GLOBALS['permission'] = true;

foreach ( array( '2026-07-28', '2025-11-25', '2025-06-18', '2025-03-26' ) as $upload_version ) {
	$catalog_response = $server->handleMessage( new ProtocolRequest( 'tools/list', $upload_version ) );
	$catalog = array_column( $catalog_response->data['result']['tools'], null, 'name' );
	check_same( array( 'file' ), $catalog['wp_upload_media']['_meta']['openai/fileParams'], 'file metadata survives discovery ' . $upload_version );
	check_same( false, $catalog['wp_upload_media']['annotations']['readOnlyHint'], 'upload is advertised as mutating ' . $upload_version );
	check_same( 'object', $catalog['wp_upload_media']['inputSchema']['properties']['file']['type'], 'file object schema survives discovery ' . $upload_version );
	check_same( array( 'url' ), $catalog['wp_upload_image_from_url']['inputSchema']['required'], 'legacy URL upload schema preserved ' . $upload_version );
	check_same( array(), $catalog['wp_upload_image']['inputSchema']['required'], 'image uploads do not require base64 for files ' . $upload_version );
	check_same( array( 'file' ), $catalog['wp_upload_image']['_meta']['openai/fileParams'], 'image file metadata survives discovery ' . $upload_version );
	check_same( $catalog['wp_upload_media']['inputSchema']['properties']['file'], $catalog['wp_upload_image']['inputSchema']['properties']['file'], 'upload tools share the OpenAI file schema ' . $upload_version );
	check_same( 'string', $catalog['wp_upload_image']['inputSchema']['properties']['image_data']['type'], 'legacy image_data remains supported ' . $upload_version );
	foreach ( $catalog as $tool ) {
		foreach ( array( 'readOnlyHint', 'destructiveHint', 'idempotentHint', 'openWorldHint' ) as $hint ) {
			check_same( true, isset( $tool['annotations'][$hint] ) && is_bool( $tool['annotations'][$hint] ), $tool['name'] . ' publishes boolean ' . $hint . ' ' . $upload_version );
		}
	}
	check_same( false, $catalog['fetch']['annotations']['readOnlyHint'], 'fetch cannot claim GET-only semantics ' . $upload_version );
	check_same( true, $catalog['wp_get_posts']['annotations']['readOnlyHint'], 'post queries remain read-only ' . $upload_version );
}

$catalog_model = new StifliFlexMcpModel();
$all_definitions = $catalog_model->getTools();
foreach ( array(
	'integrations/class-elementor.php' => 'StifliFlexMcp_Elementor',
	'integrations/class-divi.php' => 'StifliFlexMcp_Divi',
	'integrations/class-the-events-calendar.php' => 'StifliFlexMcp_TheEventsCalendar',
	'integrations/class-contact-form-7.php' => 'StifliFlexMcp_ContactForm7',
	'external-data/class-google-search-console.php' => 'StifliFlexMcp_GoogleSearchConsole',
	'media/class-search-image.php' => 'StifliFlexMcp_Search_Image',
	'snippets/snippets.php' => 'StifliFlexMcp_Snippets',
	'woocommerce/wc-products.php' => 'StifliFlexMcp_WC_Products',
	'woocommerce/wc-orders.php' => 'StifliFlexMcp_WC_Orders',
	'woocommerce/wc-customers-coupons.php' => 'StifliFlexMcp_WC_Coupons',
	'woocommerce/wc-system.php' => 'StifliFlexMcp_WC_System',
) as $file => $class ) {
	require_once dirname( __DIR__ ) . '/models/' . $file;
	$all_definitions = array_merge( $all_definitions, $class::getTools() );
}
class AnnotationCatalogModel extends StifliFlexMcpModel {
	public $definitions;
	public function getTools() { return $this->definitions; }
}
$annotation_model = new AnnotationCatalogModel();
$annotation_model->definitions = $all_definitions;
$annotation_model->definitions['wp_get_posts']['annotations'] = array( 'title' => 'Browse posts', 'readOnlyHint' => false );
$GLOBALS['test_logs'] = array();
$full_catalog = array_column( $annotation_model->getToolsList(), null, 'name' );
check_same( count( $all_definitions ), count( $full_catalog ), 'all optional module definitions receive metadata' );
check_same( true, count( $full_catalog ) >= 224, 'catalog coverage includes dormant integrations and WooCommerce: ' . count( $full_catalog ) . ' tools' );
check_same( array(), $GLOBALS['test_logs'], 'every built-in and integration tool has an explicit reviewed classification' );
check_same( 'Browse posts', $full_catalog['wp_get_posts']['annotations']['title'], 'existing annotation titles survive' );
foreach ( $full_catalog as $name => $tool ) {
	$metadata = $catalog_model->getToolMetadata( $name );
	foreach ( $metadata['annotations'] as $hint => $value ) {
		check_same( $value, $tool['annotations'][$hint], $name . ' consistent ' . $hint );
	}
	check_same( $metadata['intent'], $tool['intent'], $name . ' consistent intent' );
	check_same( 'read' !== $tool['intent'], $tool['requires_confirmation'], $name . ' consistent confirmation' );
}
foreach ( array( 'wp_get_posts', 'wp_get_site_health', 'divi_validate_layout', 'elementor_export_template', 'cf7_get_form', 'wc_get_order', 'wp_gsc_query_performance', 'wp_search_image' ) as $name ) {
	check_same( true, $full_catalog[$name]['annotations']['readOnlyHint'], $name . ' is a reviewed logical read' );
	check_same( false, $full_catalog[$name]['annotations']['destructiveHint'], $name . ' is non-destructive' );
}
foreach ( array( 'fetch', 'yoast_reindex', 'elementor_update_widget', 'elementor_add_widget', 'wc_bulk_assign_product_categories', 'wc_bulk_delete_products', 'wc_create_refund', 'wp_tec_save_event', 'wp_tec_save_entity', 'wc_batch_update_products', 'wp_create_nav_menu', 'wp_create_menu', 'wp_set_featured_image', 'wp_update_option' ) as $name ) {
	check_same( 'write', $full_catalog[$name]['intent'], $name . ' must not default to read' );
	check_same( true, $full_catalog[$name]['annotations']['destructiveHint'], $name . ' may replace or remove existing state' );
}
foreach ( array( 'wp_upload_image', 'wp_upload_media', 'wp_create_preview_link', 'wp_create_post', 'wp_add_post_meta', 'wc_create_product', 'elementor_import_template' ) as $name ) {
	check_same( false, $full_catalog[$name]['annotations']['destructiveHint'], $name . ' is additive' );
	check_same( false, $full_catalog[$name]['annotations']['readOnlyHint'], $name . ' still writes' );
}
foreach ( array( 'wc_update_stock', 'wp_css_set_global', 'wp_css_set_scoped', 'wp_update_post', 'snippet_activate', 'mcp_redo_change' ) as $name ) {
	check_same( false, $full_catalog[$name]['annotations']['idempotentHint'], $name . ' does not promise safe automatic retries' );
}
foreach ( array( 'fetch', 'mcp_ping', 'wp_upload_image', 'wp_gsc_query_performance', 'wp_gsc_inspect_url', 'wp_search_image', 'wc_update_order', 'wc_create_order_note', 'wc_create_webhook', 'snippet_activate', 'mcp_redo_change' ) as $name ) {
	check_same( true, $full_catalog[$name]['annotations']['openWorldHint'], $name . ' can interact with external entities' );
}
foreach ( array( 'wp_get_posts', 'wp_get_option', 'wc_get_products', 'divi_validate_layout', 'wp_update_term' ) as $name ) {
	check_same( false, $full_catalog[$name]['annotations']['openWorldHint'], $name . ' operates on local site data' );
}
$conservative = array( 'readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => false, 'openWorldHint' => true );
foreach ( array( 'wp_get_unreviewed_tool', 'extension_search', 'extension_delete' ) as $name ) {
	$GLOBALS['test_logs'] = array();
	$metadata = $catalog_model->getToolMetadata( $name );
	check_same( $conservative, $metadata['annotations'], 'unknown tool names do not imply safety: ' . $name );
	check_same( 'write', $metadata['intent'], 'unknown tools require write confirmation: ' . $name );
	check_same( 1, count( $GLOBALS['test_logs'] ), 'unknown policy is logged: ' . $name );
}
$openai_functions = array_column( $annotation_model->getOpenAIFunctions(), null, 'name' );
check_same( 'write', $openai_functions['fetch']['metadata']['intent'], 'function adapter gets corrected intent' );
check_same( true, $openai_functions['elementor_add_widget']['metadata']['requires_confirmation'], 'function adapter gets corrected confirmation' );

class AnnotationTestAbility {
	public $meta;
	public function __construct( $meta ) { $this->meta = $meta; }
	public function get_meta() { return $this->meta; }
	public function execute() { throw new RuntimeException( 'Discovery must not execute Abilities.' ); }
	public function check_permissions() { throw new RuntimeException( 'Discovery must not invoke Ability permissions.' ); }
}
function stifli_flex_mcp_get_ability_safe( $name ) {
	$GLOBALS['last_ability_slug'] = $name;
	return isset( $GLOBALS['registered_abilities'][$name] ) ? $GLOBALS['registered_abilities'][$name] : null;
}
$ability_slug = 'test-plugin/read-private_data';
$ability_name = 'ability_test_plugin_read_private_data';
$ability_definition = array( '_ability_name' => $ability_slug );
$GLOBALS['registered_abilities'][$ability_slug] = new AnnotationTestAbility( array( 'annotations' => array( 'readonly' => true, 'destructive' => null, 'idempotent' => null ) ) );
$metadata = $catalog_model->getToolMetadata( $ability_name, $ability_definition );
check_same( $ability_slug, $GLOBALS['last_ability_slug'], 'Ability metadata lookup uses original slug, not a lossy name reversal' );
check_same( array( 'readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => true ), $metadata['annotations'], 'WordPress readonly metadata maps to MCP' );
check_same( 'sensitive_read', $metadata['intent'], 'read-only Abilities retain sensitive intent' );
check_same( true, $metadata['requires_confirmation'], 'read-only Abilities retain confirmation' );
foreach ( array( array(), array( 'readonly' => null, 'destructive' => null, 'idempotent' => null ), array( 'readonly' => 'false', 'destructive' => 'false', 'idempotent' => 1, 'openWorldHint' => 0 ), 'invalid' ) as $declared ) {
	$GLOBALS['registered_abilities'][$ability_slug]->meta = array( 'annotations' => $declared );
	check_same( $conservative, $catalog_model->getToolMetadata( $ability_name, $ability_definition )['annotations'], 'missing, null or malformed Ability hints remain conservative: ' . json_encode( $declared ) );
}
$GLOBALS['registered_abilities'][$ability_slug]->meta = array( 'annotations' => array( 'readonly' => false, 'destructive' => false, 'idempotent' => true, 'openWorldHint' => false ) );
check_same( array( 'readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false ), $catalog_model->getToolMetadata( $ability_name, $ability_definition )['annotations'], 'explicit additive idempotent Ability declarations are respected' );
$GLOBALS['registered_abilities'][$ability_slug]->meta = array( 'annotations' => array( 'readonly' => true ) );
$GLOBALS['ability_rows'] = array(
	(object) array( 'ability_name' => $ability_slug, 'ability_description' => 'Read private test data', 'ability_label' => 'Private data', 'ability_category' => 'test', 'input_schema' => '{"type":"object","properties":{}}', 'enabled' => 1 ),
	(object) array( 'ability_name' => 'test/disabled', 'ability_description' => '', 'ability_label' => 'Disabled', 'ability_category' => 'test', 'input_schema' => '{}', 'enabled' => 0 ),
);
$ability_model = new StifliFlexMcpModel();
$imported = array_column( $ability_model->getToolsList(), null, 'name' );
check_same( true, $imported[$ability_name]['annotations']['readOnlyHint'], 'imported Ability annotations survive catalog enrichment' );
check_same( true, $imported[$ability_name]['requires_confirmation'], 'catalog does not overwrite Ability confirmation' );
check_same( false, isset( $imported['ability_test_disabled'] ), 'disabled Abilities remain undiscoverable' );
check_same( array( 'intent' => 'sensitive_read', 'requires_confirmation' => true ), $ability_model->getIntentForTool( $ability_name ), 'admin intent lookup resolves the original Ability descriptor' );
foreach ( array( '2026-07-28', '2025-11-25', '2025-06-18', '2025-03-26' ) as $version ) {
	$wire = $server->handleMessage( new ProtocolRequest( 'tools/list', $version ) );
	$wire_catalog = array_column( $wire->data['result']['tools'], null, 'name' );
	check_same( true, $wire_catalog[$ability_name]['annotations']['readOnlyHint'], 'Ability hints survive wire discovery ' . $version );
	check_same( true, $wire_catalog[$ability_name]['requires_confirmation'], 'Ability confirmation survives wire discovery ' . $version );
}
$GLOBALS['registered_abilities'][$ability_slug]->meta = array( 'annotations' => array( 'readonly' => false ) );
check_same( array( 'intent' => 'write', 'requires_confirmation' => true ), $ability_model->getIntentForTool( $ability_name ), 'runtime Ability metadata changes are not cached as read-only' );
unset( $GLOBALS['registered_abilities'][$ability_slug] );
check_same( $conservative, $ability_model->getToolMetadata( $ability_name, $ability_definition )['annotations'], 'unregistered Abilities do not inherit stale hints' );
check_same( $conservative, $ability_model->getToolMetadata( 'ability_unknown' )['annotations'], 'unknown Ability names remain conservative' );
unset( $GLOBALS['ability_rows'] );
$GLOBALS['enabled_tool_rows'] = array( array( 'tool_name' => 'wp_get_posts', 'token_estimate' => 1 ) );
check_same( array( 'wp_get_posts' ), array_column( $annotation_model->getToolsList(), 'name' ), 'annotations do not enable disabled tools' );
unset( $GLOBALS['enabled_tool_rows'] );

if ( in_array( '--schema', $argv, true ) ) {
	$fixtures = array();
	foreach ( array( 'server/discover' => 'DiscoverResult', 'prompts/list' => 'ListPromptsResult', 'resources/list' => 'ListResourcesResult', 'resources/templates/list' => 'ListResourceTemplatesResult' ) as $method => $schema ) {
		$response = $server->handleMessage( new ProtocolRequest( $method ) );
		$fixtures[] = array( 'source' => 'core', 'schema' => $schema, 'value' => $response->data['result'] );
	}
	$response = $server->handleMessage( new ProtocolRequest( 'tools/list' ) );
	check_same( true, ! empty( $response->data['result']['tools'] ), 'real tool catalog is nonempty' );
	$fixtures[] = array( 'source' => 'core', 'schema' => 'ListToolsResult', 'value' => $response->data['result'] );
	$full_catalog_result = $response->data['result'];
	$full_catalog_result['tools'] = array_values( $full_catalog );
	$fixtures[] = array( 'source' => 'core', 'schema' => 'ListToolsResult', 'value' => $full_catalog_result );
	$request = new ProtocolRequest( 'resources/read' );
	$request->data['params']['uri'] = $request->headers['Mcp-Name'] = 'wp://site/post-types';
	$fixtures[] = array( 'source' => 'core', 'schema' => 'ReadResourceResult', 'value' => $server->handleMessage( $request )->data['result'] );
	$enrich = $reflection->getMethod( 'modernResponse' );
	$enrich->setAccessible( true );
	$preview = $model->dispatchTool( 'wp_update_settings', array( 'settings' => array( 'blogname' => 'Preview' ), 'dry_run' => true ), 1 );
	$fixtures[] = array( 'source' => 'core', 'schema' => 'CallToolResult', 'value' => $enrich->invoke( $server, 1, 'tools/call', $preview )->data['result'] );
	$request = new ProtocolRequest( 'tasks/get' );
	$request->data['params']['taskId'] = 'test-uuid';
	$request->data['params']['_meta']['io.modelcontextprotocol/clientCapabilities'] = array( 'extensions' => array( 'io.modelcontextprotocol/tasks' => (object) array() ) );
	$fixtures[] = array( 'source' => 'tasks', 'schema' => 'GetTaskResult', 'value' => $server->handleMessage( $request )->data['result'] );
	$fixtures[] = array( 'source' => 'tasks', 'schema' => 'GetTaskResult', 'value' => $failed_task );
	$request->data['method'] = $request->headers['Mcp-Method'] = 'tasks/cancel';
	$fixtures[] = array( 'source' => 'tasks', 'schema' => 'CancelTaskResult', 'value' => $server->handleMessage( $request )->data['result'] );
	$request = new ProtocolRequest( 'tools/call' );
	$request->data['params']['name'] = 'wp_generate_image';
	$request->headers['Mcp-Name'] = 'wp_generate_image';
	$request->data['params']['_meta']['io.modelcontextprotocol/clientCapabilities'] = array( 'extensions' => array( 'io.modelcontextprotocol/tasks' => (object) array() ) );
	$fixtures[] = array( 'source' => 'tasks', 'schema' => 'CreateTaskResult', 'value' => $server->handleMessage( $request )->data['result'] );
	$request = new ProtocolRequest( 'server/discover', '2099-01-01' );
	$fixtures[] = array( 'source' => 'core', 'schema' => 'UnsupportedProtocolVersionError', 'value' => $server->handleMessage( $request )->data );
	$request = new ProtocolRequest( 'tasks/get' );
	$fixtures[] = array( 'source' => 'core', 'schema' => 'MissingRequiredClientCapabilityError', 'value' => $server->handleMessage( $request )->data );
	$request = new ProtocolRequest( 'server/discover' );
	unset( $request->headers['Mcp-Method'] );
	$fixtures[] = array( 'source' => 'core', 'schema' => 'HeaderMismatchError', 'value' => $server->handleMessage( $request )->data );
	$fixtures[] = array( 'source' => 'core', 'schema' => 'JSONRPCErrorResponse', 'value' => $server->handleMessage( new ProtocolRequest( 'unknown/method' ) )->data );
	echo json_encode( $fixtures, JSON_THROW_ON_ERROR );
}