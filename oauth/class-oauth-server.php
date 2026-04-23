<?php
/**
 * OAuth 2.1 Authorization Server for MCP
 *
 * Implements RFC 9728, RFC 8414, RFC 7591, RFC 8707, and PKCE (S256).
 * Compatible with Claude Desktop, ChatGPT Custom GPTs, and other MCP clients.
 *
 * @package StifliFlexMcp
 * @since 3.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class StifliFlexMcp_OAuth_Server
 */
class StifliFlexMcp_OAuth_Server {

	/**
	 * REST namespace matching the main plugin.
	 *
	 * @var string
	 */
	private $namespace = 'stifli-flex-mcp/v1';

	/**
	 * Singleton instance.
	 *
	 * @var StifliFlexMcp_OAuth_Server|null
	 */
	private static $instance = null;

	/**
	 * Get singleton instance.
	 *
	 * @return StifliFlexMcp_OAuth_Server
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Initialize hooks.
	 */
	public function init() {
		// Well-known endpoints and authorize page handler (priority 0 = very early).
		add_action( 'init', array( $this, 'handle_early_requests' ), 0 );

		// REST API routes for token, register, revoke.
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );

		// Add WWW-Authenticate header to 401 responses on our namespace.
		add_filter( 'rest_post_dispatch', array( $this, 'add_www_authenticate_header' ), 10, 3 );
	}

	// =========================================================================
	// Well-known Endpoints + Authorize Page
	// =========================================================================

	/**
	 * Handle requests for .well-known endpoints and the authorize page.
	 * Fires at init priority 0 to intercept before WordPress routing.
	 */
	public function handle_early_requests() {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		$path        = wp_parse_url( $request_uri, PHP_URL_PATH );
		$method      = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : 'UNKNOWN';

		// Log all incoming requests that hit .well-known or sflmcp_oauth.
		if ( function_exists( 'stifli_flex_mcp_log' ) && ( strpos( $request_uri, '.well-known/oauth' ) !== false || strpos( $request_uri, 'sflmcp_oauth' ) !== false || strpos( $request_uri, '/oauth/' ) !== false ) ) {
			stifli_flex_mcp_log( sprintf( 'OAuth request: %s %s', $method, $request_uri ) );
		}

		// Strip the home path for subdirectory installs.
		$home_path = wp_parse_url( home_url(), PHP_URL_PATH );
		if ( $home_path && '/' !== $home_path ) {
			$path = substr( $path, strlen( $home_path ) );
		}

		if ( '/.well-known/oauth-protected-resource' === $path ) {
			if ( function_exists( 'stifli_flex_mcp_log' ) ) {
				stifli_flex_mcp_log( 'OAuth: Serving protected resource metadata' );
			}
			$this->serve_protected_resource_metadata();
		}

		if ( '/.well-known/oauth-authorization-server' === $path ) {
			if ( function_exists( 'stifli_flex_mcp_log' ) ) {
				stifli_flex_mcp_log( 'OAuth: Serving authorization server metadata (oauth-authorization-server)' );
			}
			$this->serve_authorization_server_metadata();
		}

		// Also serve at openid-configuration path — MCP SDK fallback #3 for path-based issuers.
		// SDK tries: 1) /.well-known/oauth-authorization-server{path} (root, RFC 8414)
		//            2) /.well-known/openid-configuration{path} (root, OIDC path)
		//            3) {path}/.well-known/openid-configuration (this one reaches us!)
		if ( '/.well-known/openid-configuration' === $path ) {
			if ( function_exists( 'stifli_flex_mcp_log' ) ) {
				stifli_flex_mcp_log( 'OAuth: Serving authorization server metadata (openid-configuration fallback)' );
			}
			$this->serve_authorization_server_metadata();
		}

		// Authorization page: /?sflmcp_oauth=authorize
		$oauth_route = isset( $_GET['sflmcp_oauth'] ) ? sanitize_key( wp_unslash( $_GET['sflmcp_oauth'] ) ) : '';
		if ( 'authorize' === $oauth_route ) {
			if ( function_exists( 'stifli_flex_mcp_log' ) ) {
				stifli_flex_mcp_log( sprintf( 'OAuth: Authorize page %s, params: %s', $method, wp_json_encode( array_keys( $_GET ) ) ) );
			}
			if ( 'POST' === $method ) {
				$this->process_authorize_consent();
			} else {
				$this->show_authorize_page();
			}
		}
	}

	/**
	 * RFC 9728 - Protected Resource Metadata.
	 */
	private function serve_protected_resource_metadata() {
		$metadata = array(
			'resource'                => rest_url( $this->namespace ),
			'authorization_servers'   => array( home_url() ),
			'bearer_methods_supported' => array( 'header' ),
			'scopes_supported'        => array( 'mcp' ),
		);

		$this->send_json_and_exit( $metadata );
	}

	/**
	 * RFC 8414 - Authorization Server Metadata.
	 */
	private function serve_authorization_server_metadata() {
		$metadata = array(
			'issuer'                                => home_url(),
			'authorization_endpoint'                => home_url( '?sflmcp_oauth=authorize' ),
			'token_endpoint'                        => rest_url( $this->namespace . '/oauth/token' ),
			'registration_endpoint'                 => rest_url( $this->namespace . '/oauth/register' ),
			'revocation_endpoint'                   => rest_url( $this->namespace . '/oauth/revoke' ),
			'scopes_supported'                      => array( 'mcp' ),
			'response_types_supported'              => array( 'code' ),
			'grant_types_supported'                 => array( 'authorization_code', 'refresh_token' ),
			'token_endpoint_auth_methods_supported' => array( 'none', 'client_secret_post' ),
			'code_challenge_methods_supported'      => array( 'S256' ),
			'service_documentation'                 => 'https://modelcontextprotocol.io/',
		);

		$this->send_json_and_exit( $metadata );
	}

	/**
	 * Send JSON response and terminate.
	 *
	 * @param array $data Response data.
	 * @param int   $code HTTP status code.
	 */
	private function send_json_and_exit( $data, $code = 200 ) {
		status_header( $code );
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Cache-Control: no-store' );
		header( 'Access-Control-Allow-Origin: *' );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON output, not HTML context.
		echo wp_json_encode( $data );
		exit;
	}

	// =========================================================================
	// Authorization Page (Browser Flow)
	// =========================================================================

	/**
	 * Show the OAuth consent page (GET).
	 */
	private function show_authorize_page() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$response_type = isset( $_GET['response_type'] ) ? sanitize_text_field( wp_unslash( $_GET['response_type'] ) ) : '';
		$client_id     = isset( $_GET['client_id'] ) ? sanitize_text_field( wp_unslash( $_GET['client_id'] ) ) : '';
		$redirect_uri  = isset( $_GET['redirect_uri'] ) ? esc_url_raw( wp_unslash( $_GET['redirect_uri'] ) ) : '';
		$state         = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
		$scope         = isset( $_GET['scope'] ) ? sanitize_text_field( wp_unslash( $_GET['scope'] ) ) : 'mcp';
		$code_challenge = isset( $_GET['code_challenge'] ) ? sanitize_text_field( wp_unslash( $_GET['code_challenge'] ) ) : '';
		$code_challenge_method = isset( $_GET['code_challenge_method'] ) ? sanitize_text_field( wp_unslash( $_GET['code_challenge_method'] ) ) : '';
		$resource      = isset( $_GET['resource'] ) ? esc_url_raw( wp_unslash( $_GET['resource'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		// Validate required parameters.
		if ( 'code' !== $response_type ) {
			$this->show_authorize_error( 'Unsupported response_type. Only "code" is supported.' );
			return;
		}
		if ( empty( $client_id ) || empty( $redirect_uri ) || empty( $code_challenge ) ) {
			$this->show_authorize_error( 'Missing required parameters: client_id, redirect_uri, code_challenge.' );
			return;
		}
		if ( 'S256' !== $code_challenge_method ) {
			$this->show_authorize_error( 'Only S256 code_challenge_method is supported (OAuth 2.1).' );
			return;
		}

		// Validate the client.
		$storage = StifliFlexMcp_OAuth_Storage::get_instance();
		$client  = $storage->get_client( $client_id );
		if ( ! $client ) {
			$this->show_authorize_error( 'Unknown client_id.' );
			return;
		}

		// Validate redirect_uri against registered URIs.
		if ( ! $storage->validate_redirect_uri( $client, $redirect_uri ) ) {
			$this->show_authorize_error( 'redirect_uri does not match any registered URI for this client.' );
			return;
		}

		// If user is not logged in, redirect to wp-login.php.
		if ( ! is_user_logged_in() ) {
			// Build the full authorize URL to redirect back to after login.
			$authorize_url = add_query_arg(
				array(
					'sflmcp_oauth'          => 'authorize',
					'response_type'         => $response_type,
					'client_id'             => $client_id,
					'redirect_uri'          => rawurlencode( $redirect_uri ),
					'state'                 => $state,
					'scope'                 => $scope,
					'code_challenge'        => $code_challenge,
					'code_challenge_method' => $code_challenge_method,
					'resource'              => $resource,
				),
				home_url( '/' )
			);
			wp_safe_redirect( wp_login_url( $authorize_url ) );
			exit;
		}

		// Check user capabilities.
		if ( ! current_user_can( 'edit_posts' ) ) {
			$this->show_authorize_error( 'Your account does not have sufficient permissions to authorize MCP access.' );
			return;
		}

		// Auto-grant if this user already authorized this client before (has active tokens).
		if ( $storage->user_has_active_grant( $client_id, get_current_user_id() ) ) {
			if ( function_exists( 'stifli_flex_mcp_log' ) ) {
				stifli_flex_mcp_log( sprintf( 'OAuth: Auto-granting for previously authorized client %s, user %d', $client_id, get_current_user_id() ) );
			}
			$code = $storage->create_code(
				$client_id,
				get_current_user_id(),
				$redirect_uri,
				$scope,
				$code_challenge,
				$code_challenge_method,
				$resource
			);
			$redirect = add_query_arg( array( 'code' => $code, 'state' => $state ), $redirect_uri );
			wp_redirect( $redirect ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
			exit;
		}

		// Show consent page.
		$this->render_consent_page( $client, $redirect_uri, $state, $scope, $code_challenge, $code_challenge_method, $resource );
		exit;
	}

	/**
	 * Process the authorization consent form (POST).
	 */
	private function process_authorize_consent() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified below via wp_verify_nonce.
		$nonce = isset( $_POST['sflmcp_oauth_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['sflmcp_oauth_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'sflmcp_oauth_authorize' ) ) {
			$this->show_authorize_error( 'Invalid or expired form. Please try again.' );
			return;
		}

		$decision     = isset( $_POST['authorize'] ) ? sanitize_text_field( wp_unslash( $_POST['authorize'] ) ) : '';
		$client_id    = isset( $_POST['client_id'] ) ? sanitize_text_field( wp_unslash( $_POST['client_id'] ) ) : '';
		$redirect_uri = isset( $_POST['redirect_uri'] ) ? esc_url_raw( wp_unslash( $_POST['redirect_uri'] ) ) : '';
		$state        = isset( $_POST['state'] ) ? sanitize_text_field( wp_unslash( $_POST['state'] ) ) : '';
		$scope        = isset( $_POST['scope'] ) ? sanitize_text_field( wp_unslash( $_POST['scope'] ) ) : 'mcp';
		$code_challenge        = isset( $_POST['code_challenge'] ) ? sanitize_text_field( wp_unslash( $_POST['code_challenge'] ) ) : '';
		$code_challenge_method = isset( $_POST['code_challenge_method'] ) ? sanitize_text_field( wp_unslash( $_POST['code_challenge_method'] ) ) : 'S256';
		$resource     = isset( $_POST['resource'] ) ? esc_url_raw( wp_unslash( $_POST['resource'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		// Re-validate client.
		$storage = StifliFlexMcp_OAuth_Storage::get_instance();
		$client  = $storage->get_client( $client_id );
		if ( ! $client || ! $storage->validate_redirect_uri( $client, $redirect_uri ) ) {
			$this->show_authorize_error( 'Invalid client or redirect URI.' );
			return;
		}

		// User denied access.
		if ( 'approve' !== $decision ) {
			$deny_url = add_query_arg(
				array(
					'error' => 'access_denied',
					'state' => $state,
				),
				$redirect_uri
			);
			wp_redirect( $deny_url ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Redirect to registered client URI.
			exit;
		}

		// User approved: generate authorization code.
		$user_id = get_current_user_id();
		$code    = $storage->create_code(
			$client_id,
			$user_id,
			$redirect_uri,
			$scope,
			$code_challenge,
			$code_challenge_method,
			$resource ?: null
		);

		$approve_url = add_query_arg(
			array(
				'code'  => $code,
				'state' => $state,
			),
			$redirect_uri
		);

		wp_redirect( $approve_url ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Redirect to registered client URI.
		exit;
	}

	/**
	 * Render the consent page HTML.
	 *
	 * @param object $client               Client record.
	 * @param string $redirect_uri         Redirect URI.
	 * @param string $state                State parameter.
	 * @param string $scope                Scope.
	 * @param string $code_challenge       PKCE challenge.
	 * @param string $code_challenge_method PKCE method.
	 * @param string $resource             Resource indicator.
	 */
	private function render_consent_page( $client, $redirect_uri, $state, $scope, $code_challenge, $code_challenge_method, $resource ) {
		$site_name   = get_bloginfo( 'name' );
		$client_name = esc_html( $client->client_name );
		$user        = wp_get_current_user();
		$user_display = esc_html( $user->display_name );
		$nonce       = wp_create_nonce( 'sflmcp_oauth_authorize' );

		?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?php echo esc_html( sprintf( 'Authorize — %s', $site_name ) ); ?></title>
<link rel="stylesheet" href="<?php echo esc_url( plugins_url( 'assets/oauth-authorize.css', __FILE__ ) ); ?>">
</head>
<body>
<div class="oauth-card">
	<div class="oauth-header">
		<h1><?php echo esc_html( $site_name ); ?></h1>
		<span class="site-name"><?php echo esc_url( home_url() ); ?></span>
	</div>

	<div class="oauth-client">
		<h2><?php echo $client_name; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Already escaped above. ?> wants access</h2>
		<?php if ( ! empty( $client->client_uri ) ) : ?>
			<div class="client-detail"><?php echo esc_url( $client->client_uri ); ?></div>
		<?php endif; ?>
		<div class="client-detail">Client ID: <?php echo esc_html( substr( $client->client_id, 0, 16 ) ); ?>…</div>
	</div>

	<div class="oauth-perms">
		<h3>This will allow the application to:</h3>
		<ul>
			<li>Read posts, pages, media, and site information</li>
			<li>Create, update, and delete content on your behalf</li>
			<li>Access MCP tools based on your WordPress role</li>
		</ul>
	</div>

	<div class="oauth-user">
		Authorizing as <strong><?php echo $user_display; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Already escaped. ?></strong>
	</div>

	<form method="POST" action="<?php echo esc_url( home_url( '?sflmcp_oauth=authorize' ) ); ?>">
		<input type="hidden" name="sflmcp_oauth_nonce" value="<?php echo esc_attr( $nonce ); ?>">
		<input type="hidden" name="client_id" value="<?php echo esc_attr( $client->client_id ); ?>">
		<input type="hidden" name="redirect_uri" value="<?php echo esc_attr( $redirect_uri ); ?>">
		<input type="hidden" name="state" value="<?php echo esc_attr( $state ); ?>">
		<input type="hidden" name="scope" value="<?php echo esc_attr( $scope ); ?>">
		<input type="hidden" name="code_challenge" value="<?php echo esc_attr( $code_challenge ); ?>">
		<input type="hidden" name="code_challenge_method" value="<?php echo esc_attr( $code_challenge_method ); ?>">
		<input type="hidden" name="resource" value="<?php echo esc_attr( $resource ); ?>">
		<div class="oauth-actions">
			<button type="submit" name="authorize" value="deny" class="btn-deny">Deny</button>
			<button type="submit" name="authorize" value="approve" class="btn-approve">Authorize</button>
		</div>
	</form>
</div>
</body>
</html>
		<?php
	}

	/**
	 * Show an error on the authorize page (no redirect — never redirect to unvalidated URIs).
	 *
	 * @param string $message Error message.
	 */
	private function show_authorize_error( $message ) {
		$site_name = get_bloginfo( 'name' );
		status_header( 400 );
		?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?php echo esc_html( sprintf( 'Authorization Error — %s', $site_name ) ); ?></title>
<link rel="stylesheet" href="<?php echo esc_url( plugins_url( 'assets/oauth-authorize.css', __FILE__ ) ); ?>">
</head>
<body class="oauth-error">
<div class="oauth-card">
	<h1>Authorization Error</h1>
	<p><?php echo esc_html( $message ); ?></p>
</div>
</body>
</html>
		<?php
		exit;
	}

	// =========================================================================
	// REST API Routes
	// =========================================================================

	/**
	 * Register OAuth REST routes.
	 */
	public function register_routes() {
		// Dynamic Client Registration (RFC 7591).
		// Accept both GET and POST — some clients probe with GET before POSTing.
		register_rest_route( $this->namespace, '/oauth/register', array(
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_register' ),
				'permission_callback' => '__return_true',
			),
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_register_info' ),
				'permission_callback' => '__return_true',
			),
		) );

		// Token endpoint (authorization_code + refresh_token grants).
		// Accept both GET and POST — some clients probe with GET.
		register_rest_route( $this->namespace, '/oauth/token', array(
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_token' ),
				'permission_callback' => '__return_true',
			),
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_endpoint_info' ),
				'permission_callback' => '__return_true',
			),
		) );

		// Token revocation (RFC 7009).
		register_rest_route( $this->namespace, '/oauth/revoke', array(
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_revoke' ),
				'permission_callback' => '__return_true',
			),
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_endpoint_info' ),
				'permission_callback' => '__return_true',
			),
		) );
	}

	// =========================================================================
	// Dynamic Client Registration (RFC 7591)
	// =========================================================================

	/**
	 * Handle POST /oauth/register.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public function handle_register( $request ) {
		$this->log_rest_request( 'POST /oauth/register', $request );
		$auto_approve = get_option( 'sflmcp_oauth_auto_approve', '1' );
		if ( '1' !== $auto_approve ) {
			return new WP_REST_Response(
				array(
					'error'             => 'registration_not_supported',
					'error_description' => 'Dynamic client registration is disabled. An administrator must register clients manually.',
				),
				403
			);
		}

		$body = $request->get_json_params();
		if ( empty( $body ) ) {
			$body = $request->get_body_params();
		}

		$storage = StifliFlexMcp_OAuth_Storage::get_instance();
		$result  = $storage->register_client( $body );

		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response(
				array( 'error' => $result->get_error_code(), 'error_description' => $result->get_error_message() ),
				400
			);
		}

		$response = new WP_REST_Response( $result, 201 );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	// =========================================================================
	// Token Endpoint
	// =========================================================================

	/**
	 * Handle POST /oauth/token.
	 *
	 * Supports grant_type: authorization_code, refresh_token.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public function handle_token( $request ) {
		$this->log_rest_request( 'POST /oauth/token', $request );
		// Accept both JSON and form-urlencoded.
		$grant_type = $request->get_param( 'grant_type' );

		if ( 'authorization_code' === $grant_type ) {
			return $this->handle_authorization_code_grant( $request );
		}

		if ( 'refresh_token' === $grant_type ) {
			return $this->handle_refresh_token_grant( $request );
		}

		return $this->oauth_error( 'unsupported_grant_type', 'Supported: authorization_code, refresh_token.', 400 );
	}

	/**
	 * Handle authorization_code grant.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	private function handle_authorization_code_grant( $request ) {
		$code          = $request->get_param( 'code' );
		$redirect_uri  = $request->get_param( 'redirect_uri' );
		$client_id     = $request->get_param( 'client_id' );
		$code_verifier = $request->get_param( 'code_verifier' );
		$client_secret = $request->get_param( 'client_secret' );

		if ( empty( $code ) || empty( $redirect_uri ) || empty( $client_id ) || empty( $code_verifier ) ) {
			return $this->oauth_error( 'invalid_request', 'Missing required parameters: code, redirect_uri, client_id, code_verifier.', 400 );
		}

		$storage = StifliFlexMcp_OAuth_Storage::get_instance();

		// Validate client.
		$client = $storage->get_client( $client_id );
		if ( ! $client ) {
			return $this->oauth_error( 'invalid_client', 'Unknown client_id.', 401 );
		}

		// Authenticate confidential clients.
		if ( 'client_secret_post' === $client->token_endpoint_auth_method ) {
			if ( empty( $client_secret ) || ! $storage->validate_client_secret( $client, $client_secret ) ) {
				return $this->oauth_error( 'invalid_client', 'Invalid client credentials.', 401 );
			}
		}

		// Consume the authorization code (marks as used atomically).
		$code_record = $storage->consume_code( $code );
		if ( ! $code_record ) {
			return $this->oauth_error( 'invalid_grant', 'Authorization code is invalid, expired, or already used.', 400 );
		}

		// Verify the code belongs to this client.
		if ( $code_record->client_id !== $client_id ) {
			return $this->oauth_error( 'invalid_grant', 'Code was not issued to this client.', 400 );
		}

		// Verify redirect_uri matches.
		if ( $code_record->redirect_uri !== $redirect_uri ) {
			return $this->oauth_error( 'invalid_grant', 'redirect_uri mismatch.', 400 );
		}

		// Verify PKCE.
		if ( ! $storage->verify_pkce( $code_verifier, $code_record->code_challenge, $code_record->code_challenge_method ) ) {
			return $this->oauth_error( 'invalid_grant', 'PKCE verification failed.', 400 );
		}

		// Issue tokens.
		$tokens = $storage->create_token_pair(
			$client_id,
			$code_record->user_id,
			$code_record->scope,
			$code_record->resource
		);

		$response = new WP_REST_Response( $tokens, 200 );
		$response->header( 'Cache-Control', 'no-store' );
		$response->header( 'Pragma', 'no-cache' );
		return $response;
	}

	/**
	 * Handle refresh_token grant.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	private function handle_refresh_token_grant( $request ) {
		$refresh_token = $request->get_param( 'refresh_token' );
		$client_id     = $request->get_param( 'client_id' );
		$client_secret = $request->get_param( 'client_secret' );

		if ( empty( $refresh_token ) || empty( $client_id ) ) {
			return $this->oauth_error( 'invalid_request', 'Missing required parameters: refresh_token, client_id.', 400 );
		}

		$storage = StifliFlexMcp_OAuth_Storage::get_instance();

		// Validate client.
		$client = $storage->get_client( $client_id );
		if ( ! $client ) {
			return $this->oauth_error( 'invalid_client', 'Unknown client_id.', 401 );
		}

		// Authenticate confidential clients.
		if ( 'client_secret_post' === $client->token_endpoint_auth_method ) {
			if ( empty( $client_secret ) || ! $storage->validate_client_secret( $client, $client_secret ) ) {
				return $this->oauth_error( 'invalid_client', 'Invalid client credentials.', 401 );
			}
		}

		// Rotate: revoke old, issue new.
		$tokens = $storage->refresh_token( $refresh_token, $client_id );
		if ( ! $tokens ) {
			return $this->oauth_error( 'invalid_grant', 'Refresh token is invalid, expired, or revoked.', 400 );
		}

		$response = new WP_REST_Response( $tokens, 200 );
		$response->header( 'Cache-Control', 'no-store' );
		$response->header( 'Pragma', 'no-cache' );
		return $response;
	}

	// =========================================================================
	// Token Revocation (RFC 7009)
	// =========================================================================

	/**
	 * Handle POST /oauth/revoke.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response Always 200 per spec.
	 */
	public function handle_revoke( $request ) {
		$this->log_rest_request( 'POST /oauth/revoke', $request );
		$token      = $request->get_param( 'token' );
		$token_hint = $request->get_param( 'token_type_hint' ) ?: '';

		if ( ! empty( $token ) ) {
			$storage = StifliFlexMcp_OAuth_Storage::get_instance();
			$storage->revoke_token( $token, $token_hint );
		}

		// RFC 7009: always 200.
		return new WP_REST_Response( null, 200 );
	}

	// =========================================================================
	// Token Validation (Public API for canAccessMCP)
	// =========================================================================

	/**
	 * Validate an OAuth Bearer token.
	 *
	 * @param string $token Plaintext Bearer token.
	 * @return int|false WordPress user ID if valid, false otherwise.
	 */
	public function validate_token( $token ) {
		if ( empty( $token ) ) {
			return false;
		}

		$storage = StifliFlexMcp_OAuth_Storage::get_instance();
		$record  = $storage->validate_access_token( $token );

		if ( ! $record ) {
			return false;
		}

		return (int) $record->user_id;
	}

	// =========================================================================
	// WWW-Authenticate Header (MCP spec requirement)
	// =========================================================================

	/**
	 * Add WWW-Authenticate header to 401 responses on our endpoints.
	 *
	 * @param WP_REST_Response $response Response.
	 * @param WP_REST_Server   $server   REST server.
	 * @param WP_REST_Request  $request  Request.
	 * @return WP_REST_Response
	 */
	public function add_www_authenticate_header( $response, $server, $request ) {
		if ( 401 === $response->get_status() ) {
			$route = $request->get_route();
			if ( strpos( $route, '/' . $this->namespace ) === 0 ) {
				$resource_metadata = home_url( '/.well-known/oauth-protected-resource' );
				$www_auth = 'Bearer resource_metadata="' . $resource_metadata . '"';
				$response->header( 'WWW-Authenticate', $www_auth );
				if ( function_exists( 'stifli_flex_mcp_log' ) ) {
					stifli_flex_mcp_log( sprintf( 'OAuth: WWW-Authenticate header: %s (route: %s)', $www_auth, $route ) );
				}
			}
		}
		return $response;
	}

	/**
	 * Get a 401 WP_Error with proper status for use in permission callbacks.
	 *
	 * @return WP_Error
	 */
	public function get_unauthorized_error() {
		return new WP_Error(
			'rest_unauthorized',
			'Authentication required. Use Authorization: Bearer <token>.',
			array( 'status' => 401 )
		);
	}

	// =========================================================================
	// Helpers
	// =========================================================================

	/**
	 * Build a standard OAuth error response.
	 *
	 * @param string $error             Error code.
	 * @param string $error_description Description.
	 * @param int    $status            HTTP status.
	 * @return WP_REST_Response
	 */
	private function oauth_error( $error, $error_description, $status = 400 ) {
		$response = new WP_REST_Response(
			array(
				'error'             => $error,
				'error_description' => $error_description,
			),
			$status
		);
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	/**
	 * Handle GET /oauth/register — informational response for discovery probes.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public function handle_register_info( $request ) {
		$this->log_rest_request( 'GET /oauth/register (discovery probe)', $request );
		return new WP_REST_Response(
			array(
				'endpoint'    => 'Dynamic Client Registration (RFC 7591)',
				'method'      => 'POST',
				'description' => 'Submit a POST request with JSON body to register a new OAuth client.',
			),
			200
		);
	}

	/**
	 * Handle GET on token/revoke — informational response for discovery probes.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public function handle_endpoint_info( $request ) {
		$route = $request->get_route();
		$this->log_rest_request( 'GET ' . $route . ' (discovery probe)', $request );
		return new WP_REST_Response(
			array(
				'endpoint' => $route,
				'method'   => 'POST',
				'message'  => 'This endpoint only accepts POST requests.',
			),
			200
		);
	}

	/**
	 * Log a REST API request for debugging.
	 *
	 * @param string          $label   Short label for the log entry.
	 * @param WP_REST_Request $request REST request.
	 */
	private function log_rest_request( $label, $request ) {
		if ( ! function_exists( 'stifli_flex_mcp_log' ) ) {
			return;
		}
		$headers = $request->get_headers();
		// Remove sensitive headers.
		unset( $headers['authorization'], $headers['cookie'] );
		stifli_flex_mcp_log( sprintf(
			"OAuth REST: %s | Method: %s | Route: %s | Headers: %s | Body: %s",
			$label,
			$request->get_method(),
			$request->get_route(),
			wp_json_encode( $headers ),
			wp_json_encode( $request->get_json_params() )
		) );
	}
}
