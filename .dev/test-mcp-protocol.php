<?php
define( 'ABSPATH', __DIR__ . '/' );
require_once dirname( __DIR__ ) . '/models/utils.php';
require_once dirname( __DIR__ ) . '/mod.php';

function get_bloginfo( $key ) { return 'Test site'; }
function get_current_user_id() { return isset( $GLOBALS['test_user'] ) ? $GLOBALS['test_user'] : 1; }
function sanitize_text_field( $value ) { return (string) $value; }
function wp_generate_uuid4() { return 'test-uuid'; }
function stifli_flex_mcp_log( ...$args ) {}
function get_option( $key, $default = false ) { return isset( $GLOBALS['options'][$key] ) ? $GLOBALS['options'][$key] : $default; }
function add_option( $key, $value, ...$args ) { $GLOBALS['options'][$key] = $value; return true; }
function update_option( $key, $value, ...$args ) { $GLOBALS['options'][$key] = $value; return true; }
function delete_option( $key ) { unset( $GLOBALS['options'][$key] ); return true; }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
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
	public function prepare( $sql, ...$args ) { return $sql; }
	public function get_var( $sql ) { return null; }
	public function get_results( $sql, ...$args ) { return array(); }
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

if ( in_array( '--schema', $argv, true ) ) {
	$fixtures = array();
	foreach ( array( 'server/discover' => 'DiscoverResult', 'prompts/list' => 'ListPromptsResult', 'resources/list' => 'ListResourcesResult', 'resources/templates/list' => 'ListResourceTemplatesResult' ) as $method => $schema ) {
		$response = $server->handleMessage( new ProtocolRequest( $method ) );
		$fixtures[] = array( 'source' => 'core', 'schema' => $schema, 'value' => $response->data['result'] );
	}
	$response = $server->handleMessage( new ProtocolRequest( 'tools/list' ) );
	check_same( true, ! empty( $response->data['result']['tools'] ), 'real tool catalog is nonempty' );
	$fixtures[] = array( 'source' => 'core', 'schema' => 'ListToolsResult', 'value' => $response->data['result'] );
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