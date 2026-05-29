<?php
/**
 * SEO external data admin page.
 *
 * @package StifliFlexMcp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class StifliFlexMcp_SEO_Admin {

	const NONCE_ACTION = 'sflmcp_seo';

	public function __construct( $register_menu = false ) {
		if ( $register_menu ) {
			add_action( 'admin_menu', array( $this, 'add_submenu_page' ), 26 );
		}

		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_sflmcp_seo_save_settings', array( $this, 'ajax_save_settings' ) );
		add_action( 'wp_ajax_sflmcp_seo_load_settings', array( $this, 'ajax_load_settings' ) );
		add_action( 'wp_ajax_sflmcp_seo_test_gsc', array( $this, 'ajax_test_gsc' ) );
		add_action( 'wp_ajax_sflmcp_seo_disconnect_gsc', array( $this, 'ajax_disconnect_gsc' ) );
		add_action( 'wp_ajax_sflmcp_seo_remove_gsc_credentials', array( $this, 'ajax_remove_gsc_credentials' ) );
		add_action( 'wp_ajax_sflmcp_seo_clear_gsc_cache', array( $this, 'ajax_clear_gsc_cache' ) );
		add_action( 'wp_ajax_sflmcp_seo_toggle_tool', array( $this, 'ajax_toggle_tool' ) );
		add_action( 'admin_post_sflmcp_gsc_oauth_start', array( $this, 'handle_oauth_start' ) );
		add_action( 'admin_post_sflmcp_gsc_oauth_callback', array( $this, 'handle_oauth_callback' ) );
	}

	public function add_submenu_page() {
		add_submenu_page(
			'stifli-flex-mcp',
			__( 'SEO', 'stifli-flex-mcp' ),
			__( 'SEO', 'stifli-flex-mcp' ),
			'manage_options',
			'sflmcp-seo',
			array( $this, 'render_page' )
		);
	}

	public function enqueue_assets( $hook ) {
		if ( 'stifli-flex-mcp_page_sflmcp-seo' !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'sflmcp-admin-seo',
			plugin_dir_url( dirname( __DIR__, 2 ) . '/stifli-flex-mcp.php' ) . 'assets/admin-seo.css',
			array(),
			'1.0.1'
		);

		wp_enqueue_script(
			'sflmcp-admin-seo',
			plugin_dir_url( dirname( __DIR__, 2 ) . '/stifli-flex-mcp.php' ) . 'assets/admin-seo.js',
			array( 'jquery' ),
			'1.0.1',
			true
		);

		wp_localize_script(
			'sflmcp-admin-seo',
			'sflmcpSeo',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce' => wp_create_nonce( self::NONCE_ACTION ),
				'i18n' => array(
					'saving' => __( 'Saving...', 'stifli-flex-mcp' ),
					'saved' => __( 'Saved', 'stifli-flex-mcp' ),
					'error' => __( 'Error saving settings', 'stifli-flex-mcp' ),
					'testing' => __( 'Testing connection...', 'stifli-flex-mcp' ),
					'cacheCleared' => __( 'Cache cleared', 'stifli-flex-mcp' ),
					'credentialsRemoved' => __( 'Credentials removed', 'stifli-flex-mcp' ),
					'accountDisconnected' => __( 'Google account disconnected', 'stifli-flex-mcp' ),
					'copySuccess' => __( 'Redirect URI copied', 'stifli-flex-mcp' ),
					'clientRequired' => __( 'Save the OAuth Client ID and Client Secret before connecting Google.', 'stifli-flex-mcp' ),
					'enabled' => __( 'Enabled', 'stifli-flex-mcp' ),
					'disabled' => __( 'Disabled', 'stifli-flex-mcp' ),
				),
			)
		);
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'stifli-flex-mcp' ) );
		}

		$active_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'gsc'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! in_array( $active_tab, array( 'gsc', 'tools' ), true ) ) {
			$active_tab = 'gsc';
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'SEO Settings', 'stifli-flex-mcp' ); ?></h1>
			<h2 class="nav-tab-wrapper">
				<a href="?page=sflmcp-seo&tab=gsc" class="nav-tab <?php echo 'gsc' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<span class="dashicons dashicons-search"></span> <?php esc_html_e( 'Search Console', 'stifli-flex-mcp' ); ?>
				</a>
				<a href="?page=sflmcp-seo&tab=tools" class="nav-tab <?php echo 'tools' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<span class="dashicons dashicons-admin-tools"></span> <?php esc_html_e( 'Tools', 'stifli-flex-mcp' ); ?>
				</a>
			</h2>
			<?php
			if ( 'tools' === $active_tab ) {
				$this->render_tools_tab();
			} else {
				$this->render_gsc_tab();
			}
			?>
		</div>
		<?php
	}

	private function render_gsc_tab() {
		$settings = class_exists( 'StifliFlexMcp_GoogleSearchConsole' )
			? StifliFlexMcp_GoogleSearchConsole::get_public_settings()
			: array();
		$configured = ! empty( $settings['configured'] );
		$enabled = isset( $settings['gsc_enabled'] ) && '1' === (string) $settings['gsc_enabled'];
		$client_secret_label = ! empty( $settings['gsc_oauth_client_secret_configured'] ) ? __( 'Configured', 'stifli-flex-mcp' ) : __( 'Not configured', 'stifli-flex-mcp' );
		$connect_url = wp_nonce_url( admin_url( 'admin-post.php?action=sflmcp_gsc_oauth_start' ), 'sflmcp_gsc_oauth_start' );
		$redirect_uri = isset( $settings['redirect_uri'] ) ? $settings['redirect_uri'] : StifliFlexMcp_GoogleSearchConsole::get_redirect_uri();
		?>
		<div class="sflmcp-seo-wrap">
			<?php $this->render_oauth_notice(); ?>
			<div id="sflmcp-seo-notice" class="sflmcp-seo-notice"></div>

			<div class="sflmcp-seo-status-row">
				<div class="card sflmcp-seo-status-card">
					<h3><span class="dashicons dashicons-search"></span> <?php esc_html_e( 'Google Search Console', 'stifli-flex-mcp' ); ?></h3>
					<p class="description"><?php esc_html_e( 'Connect the Google account that owns or can read your Search Console properties. MCP tools use read-only Search Console access.', 'stifli-flex-mcp' ); ?></p>
					<div class="sflmcp-seo-status-pill <?php echo $configured ? 'is-ok' : 'is-warning'; ?>">
						<?php echo $configured ? esc_html__( 'Connected', 'stifli-flex-mcp' ) : esc_html__( 'Not connected', 'stifli-flex-mcp' ); ?>
					</div>
					<dl class="sflmcp-seo-meta">
						<dt><?php esc_html_e( 'OAuth Client ID', 'stifli-flex-mcp' ); ?></dt>
						<dd id="sflmcp_seo_gsc_client_id_status"><?php echo esc_html( $settings['gsc_oauth_client_id'] ?? '' ); ?></dd>
						<dt><?php esc_html_e( 'Client Secret', 'stifli-flex-mcp' ); ?></dt>
						<dd id="sflmcp_seo_gsc_secret_status"><?php echo esc_html( $client_secret_label ); ?></dd>
						<dt><?php esc_html_e( 'Connected by', 'stifli-flex-mcp' ); ?></dt>
						<dd id="sflmcp_seo_gsc_connected_user"><?php echo esc_html( $settings['gsc_oauth_connected_user'] ?? '' ); ?></dd>
						<dt><?php esc_html_e( 'Connected at', 'stifli-flex-mcp' ); ?></dt>
						<dd id="sflmcp_seo_gsc_connected_at"><?php echo esc_html( $settings['gsc_oauth_connected_at'] ?? '' ); ?></dd>
						<dt><?php esc_html_e( 'Last test', 'stifli-flex-mcp' ); ?></dt>
						<dd id="sflmcp_seo_gsc_last_test"><?php echo esc_html( $settings['gsc_last_test_status'] ?? '' ); ?></dd>
					</dl>
				</div>

				<div class="card sflmcp-seo-help-card">
					<h3><span class="dashicons dashicons-info-outline"></span> <?php esc_html_e( 'OAuth setup steps', 'stifli-flex-mcp' ); ?></h3>
					<ol class="sflmcp-seo-steps">
						<li><?php esc_html_e( 'Open Google Cloud Console and enable the Google Search Console API for your project.', 'stifli-flex-mcp' ); ?></li>
						<li><?php esc_html_e( 'Configure the OAuth consent screen. If the app is in Testing mode, add your Google account as a test user.', 'stifli-flex-mcp' ); ?></li>
						<li><?php esc_html_e( 'Create Credentials > OAuth client ID > Web application.', 'stifli-flex-mcp' ); ?></li>
						<li><?php esc_html_e( 'Add this Authorized redirect URI:', 'stifli-flex-mcp' ); ?></li>
					</ol>
					<div class="sflmcp-redirect-field">
						<input type="text" id="sflmcp_seo_redirect_uri" readonly value="<?php echo esc_attr( $redirect_uri ); ?>">
						<button type="button" class="button" id="sflmcp_seo_copy_redirect"><?php esc_html_e( 'Copy', 'stifli-flex-mcp' ); ?></button>
					</div>
					<p><?php esc_html_e( 'Paste the Client ID and Client Secret below, then click Connect Google Account.', 'stifli-flex-mcp' ); ?></p>
				</div>
			</div>

			<form id="sflmcp-seo-gsc-form">
				<div class="sflmcp-tool-toggle-banner <?php echo $enabled ? '' : 'disabled'; ?>">
					<label class="sflmcp-toggle-switch">
						<input type="checkbox" id="sflmcp_seo_gsc_enabled" <?php checked( $enabled ); ?>>
						<span class="sflmcp-toggle-slider"></span>
					</label>
					<span class="sflmcp-toggle-label">
						<strong><?php esc_html_e( 'Enable Search Console tools', 'stifli-flex-mcp' ); ?></strong>
					</span>
					<span class="sflmcp-toggle-status"><?php echo $enabled ? esc_html__( 'Enabled', 'stifli-flex-mcp' ) : esc_html__( 'Disabled', 'stifli-flex-mcp' ); ?></span>
				</div>

				<div class="card">
					<h3><span class="dashicons dashicons-admin-network"></span> <?php esc_html_e( 'OAuth Client and Property', 'stifli-flex-mcp' ); ?></h3>
					<table class="form-table">
						<tr>
							<th><label for="sflmcp_seo_gsc_site_url"><?php esc_html_e( 'Default property', 'stifli-flex-mcp' ); ?></label></th>
							<td>
								<input type="text" id="sflmcp_seo_gsc_site_url" value="<?php echo esc_attr( $settings['gsc_site_url'] ?? home_url( '/' ) ); ?>" class="regular-text" placeholder="https://example.com/">
								<p class="description"><?php esc_html_e( 'Examples: https://example.com/ or sc-domain:example.com.', 'stifli-flex-mcp' ); ?></p>
							</td>
						</tr>
						<tr>
							<th><label for="sflmcp_seo_gsc_cache_ttl"><?php esc_html_e( 'Cache TTL', 'stifli-flex-mcp' ); ?></label></th>
							<td>
								<input type="number" id="sflmcp_seo_gsc_cache_ttl" value="<?php echo esc_attr( $settings['gsc_cache_ttl'] ?? 900 ); ?>" min="0" max="86400" step="60">
								<p class="description"><?php esc_html_e( 'Seconds to cache GSC API responses. Use 0 to disable cache.', 'stifli-flex-mcp' ); ?></p>
							</td>
						</tr>
						<tr>
							<th><label for="sflmcp_seo_gsc_client_id"><?php esc_html_e( 'OAuth Client ID', 'stifli-flex-mcp' ); ?></label></th>
							<td>
								<input type="text" id="sflmcp_seo_gsc_client_id" value="<?php echo esc_attr( $settings['gsc_oauth_client_id'] ?? '' ); ?>" class="regular-text" autocomplete="off">
								<p class="description"><?php esc_html_e( 'From Google Cloud > APIs & Services > Credentials.', 'stifli-flex-mcp' ); ?></p>
							</td>
						</tr>
						<tr>
							<th><label for="sflmcp_seo_gsc_client_secret"><?php esc_html_e( 'OAuth Client Secret', 'stifli-flex-mcp' ); ?></label></th>
							<td>
								<input type="password" id="sflmcp_seo_gsc_client_secret" value="" class="regular-text" placeholder="<?php echo esc_attr( ! empty( $settings['gsc_oauth_client_secret_configured'] ) ? __( 'Stored - leave blank to keep', 'stifli-flex-mcp' ) : '' ); ?>" autocomplete="new-password">
								<p class="description"><?php esc_html_e( 'Stored encrypted. Leave blank to keep the existing secret.', 'stifli-flex-mcp' ); ?></p>
							</td>
						</tr>
					</table>
				</div>

				<div class="sflmcp-seo-actions">
					<button type="button" class="button button-primary" id="sflmcp_seo_connect_google" data-url="<?php echo esc_url( $connect_url ); ?>">
						<span class="dashicons dashicons-admin-users"></span> <?php esc_html_e( 'Connect Google Account', 'stifli-flex-mcp' ); ?>
					</button>
					<button type="button" class="button button-primary" id="sflmcp_seo_test_gsc">
						<span class="dashicons dashicons-yes-alt"></span> <?php esc_html_e( 'Test Connection', 'stifli-flex-mcp' ); ?>
					</button>
					<button type="button" class="button" id="sflmcp_seo_clear_gsc_cache">
						<span class="dashicons dashicons-update"></span> <?php esc_html_e( 'Clear Cache', 'stifli-flex-mcp' ); ?>
					</button>
					<button type="button" class="button" id="sflmcp_seo_disconnect_gsc">
						<?php esc_html_e( 'Disconnect Google Account', 'stifli-flex-mcp' ); ?>
					</button>
					<button type="button" class="button button-link-delete" id="sflmcp_seo_remove_gsc_credentials">
						<?php esc_html_e( 'Remove OAuth Client', 'stifli-flex-mcp' ); ?>
					</button>
					<span id="sflmcp-seo-autosave-status" class="sflmcp-autosave-status"></span>
				</div>
			</form>
		</div>
		<?php
	}

	private function render_tools_tab() {
		$settings = class_exists( 'StifliFlexMcp_GoogleSearchConsole' )
			? StifliFlexMcp_GoogleSearchConsole::get_public_settings()
			: array();
		$tools = $this->get_gsc_tool_states();
		?>
		<div class="sflmcp-seo-wrap">
			<div id="sflmcp-seo-notice" class="sflmcp-seo-notice"></div>

			<div class="card">
				<h3><span class="dashicons dashicons-admin-tools"></span> <?php esc_html_e( 'Search Console MCP Tools', 'stifli-flex-mcp' ); ?></h3>
				<p class="description">
					<?php esc_html_e( 'These tools are exposed only when Google Search Console is enabled, credentials are configured, and each tool is enabled here.', 'stifli-flex-mcp' ); ?>
				</p>
				<?php if ( empty( $settings['configured'] ) ) : ?>
					<div class="notice inline notice-warning">
						<p><?php esc_html_e( 'Configure Search Console credentials before using these tools.', 'stifli-flex-mcp' ); ?></p>
					</div>
				<?php endif; ?>
			</div>

			<?php foreach ( $tools as $tool ) : ?>
				<div class="sflmcp-tool-toggle-banner <?php echo ! empty( $tool['enabled'] ) ? '' : 'disabled'; ?>" data-tool="<?php echo esc_attr( $tool['name'] ); ?>">
					<label class="sflmcp-toggle-switch">
						<input type="checkbox" class="sflmcp-seo-tool-toggle" data-tool="<?php echo esc_attr( $tool['name'] ); ?>" <?php checked( ! empty( $tool['enabled'] ) ); ?>>
						<span class="sflmcp-toggle-slider"></span>
					</label>
					<span class="sflmcp-toggle-label">
						<strong><?php echo esc_html( $tool['name'] ); ?></strong> - <?php echo esc_html( $tool['description'] ); ?>
					</span>
					<span class="sflmcp-toggle-status"><?php echo ! empty( $tool['enabled'] ) ? esc_html__( 'Enabled', 'stifli-flex-mcp' ) : esc_html__( 'Disabled', 'stifli-flex-mcp' ); ?></span>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	public function ajax_save_settings() {
		$this->verify_ajax();

		$updates = array();
		$updates['gsc_enabled'] = isset( $_POST['gsc_enabled'] ) ? sanitize_text_field( wp_unslash( $_POST['gsc_enabled'] ) ) : '0';

		if ( isset( $_POST['gsc_site_url'] ) ) {
			$updates['gsc_site_url'] = sanitize_text_field( wp_unslash( $_POST['gsc_site_url'] ) );
		}
		if ( isset( $_POST['gsc_cache_ttl'] ) ) {
			$updates['gsc_cache_ttl'] = absint( wp_unslash( $_POST['gsc_cache_ttl'] ) );
		}
		if ( isset( $_POST['gsc_oauth_client_id'] ) ) {
			$updates['gsc_oauth_client_id'] = sanitize_text_field( wp_unslash( $_POST['gsc_oauth_client_id'] ) );
		}
		if ( isset( $_POST['gsc_oauth_client_secret'] ) && '' !== trim( (string) wp_unslash( $_POST['gsc_oauth_client_secret'] ) ) ) {
			$updates['gsc_oauth_client_secret'] = (string) wp_unslash( $_POST['gsc_oauth_client_secret'] );
		}

		$result = StifliFlexMcp_GoogleSearchConsole::save_settings( $updates );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		wp_send_json_success( $result );
	}

	public function ajax_load_settings() {
		$this->verify_ajax();
		wp_send_json_success( StifliFlexMcp_GoogleSearchConsole::get_public_settings() );
	}

	public function ajax_test_gsc() {
		$this->verify_ajax();

		$site_url = isset( $_POST['gsc_site_url'] ) ? sanitize_text_field( wp_unslash( $_POST['gsc_site_url'] ) ) : '';
		$result = StifliFlexMcp_GoogleSearchConsole::test_connection( $site_url );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		wp_send_json_success( $result );
	}

	public function ajax_remove_gsc_credentials() {
		$this->verify_ajax();
		wp_send_json_success( StifliFlexMcp_GoogleSearchConsole::remove_credentials() );
	}

	public function ajax_disconnect_gsc() {
		$this->verify_ajax();
		wp_send_json_success( StifliFlexMcp_GoogleSearchConsole::disconnect_account() );
	}

	public function ajax_clear_gsc_cache() {
		$this->verify_ajax();
		StifliFlexMcp_GoogleSearchConsole::clear_cache();
		wp_send_json_success( array( 'message' => __( 'Cache cleared', 'stifli-flex-mcp' ) ) );
	}

	public function ajax_toggle_tool() {
		$this->verify_ajax();

		$tool_name = isset( $_POST['tool_name'] ) ? sanitize_key( wp_unslash( $_POST['tool_name'] ) ) : '';
		$enabled = isset( $_POST['enabled'] ) && '1' === (string) sanitize_text_field( wp_unslash( $_POST['enabled'] ) ) ? 1 : 0;

		if ( ! StifliFlexMcp_GoogleSearchConsole::is_gsc_tool( $tool_name ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid SEO tool.', 'stifli-flex-mcp' ) ), 400 );
		}

		global $wpdb;
		$tools_table = StifliFlexMcpUtils::getPrefixedTable( 'sflmcp_tools', false );
		$tools_table_sql = StifliFlexMcpUtils::getPrefixedTable( 'sflmcp_tools' );
		if ( function_exists( 'stifli_flex_mcp_table_exists' ) && ! stifli_flex_mcp_table_exists( $tools_table ) ) {
			wp_send_json_error( array( 'message' => __( 'Tools table is not available.', 'stifli-flex-mcp' ) ), 500 );
		}

		$tool_defs = StifliFlexMcp_GoogleSearchConsole::getTools();
		$description = isset( $tool_defs[ $tool_name ]['description'] ) ? $tool_defs[ $tool_name ]['description'] : $tool_name;
		$category = isset( $tool_defs[ $tool_name ]['category'] ) ? $tool_defs[ $tool_name ]['category'] : 'SEO - Google Search Console';
		$now = current_time( 'mysql', true );
		$row_cache_key = 'seo_tool_row_' . md5( (string) $tool_name );
		$existing_tool = wp_cache_get( $row_cache_key, 'sflmcp' );
		if ( false === $existing_tool ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- plugin table name is sanitized by getPrefixedTable().
			$existing_tool = $wpdb->get_var( $wpdb->prepare( "SELECT tool_name FROM {$tools_table_sql} WHERE tool_name = %s LIMIT 1", $tool_name ) );
			wp_cache_set( $row_cache_key, null === $existing_tool ? '__missing__' : $existing_tool, 'sflmcp', 5 * MINUTE_IN_SECONDS );
		}
		if ( '__missing__' === $existing_tool ) {
			$existing_tool = null;
		}

		if ( null !== $existing_tool ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- plugin-managed table write.
			$wpdb->update(
				$tools_table,
				array(
					'tool_description' => $description,
					'category' => $category,
					'enabled' => $enabled,
					'updated_at' => $now,
				),
				array( 'tool_name' => $tool_name ),
				array( '%s', '%s', '%d', '%s' ),
				array( '%s' )
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- plugin-managed table write.
			$wpdb->insert(
				$tools_table,
				array(
					'tool_name' => $tool_name,
					'tool_description' => $description,
					'category' => $category,
					'enabled' => $enabled,
					'created_at' => $now,
					'updated_at' => $now,
				),
				array( '%s', '%s', '%s', '%d', '%s', '%s' )
			);
		}
		wp_cache_delete( 'gsc_tool_states_' . md5( implode( '|', array_keys( $tool_defs ) ) ), 'sflmcp' );
		wp_cache_delete( 'gsc_tool_enabled_' . md5( (string) $tool_name ), 'sflmcp' );
		wp_cache_delete( $row_cache_key, 'sflmcp' );
		wp_cache_delete( 'seo_summary_tools', 'sflmcp' );

		wp_send_json_success(
			array(
				'tool_name' => $tool_name,
				'enabled' => (bool) $enabled,
			)
		);
	}

	public function handle_oauth_start() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied', 'stifli-flex-mcp' ) );
		}

		check_admin_referer( 'sflmcp_gsc_oauth_start' );

		$state = wp_generate_password( 32, false, false );
		set_transient(
			'sflmcp_gsc_oauth_state_' . md5( $state ),
			array(
				'user_id' => get_current_user_id(),
				'created_at' => time(),
			),
			10 * MINUTE_IN_SECONDS
		);

		$url = StifliFlexMcp_GoogleSearchConsole::build_authorization_url( $state );
		if ( is_wp_error( $url ) ) {
			$this->redirect_with_oauth_notice( 'error', $url->get_error_message() );
		}

		$google_redirect_host = (string) wp_parse_url( $url, PHP_URL_HOST );
		$allow_google_redirect = static function( $hosts ) use ( $google_redirect_host ) {
			if ( 'accounts.google.com' === $google_redirect_host && ! in_array( $google_redirect_host, $hosts, true ) ) {
				$hosts[] = $google_redirect_host;
			}
			return $hosts;
		};
		add_filter( 'allowed_redirect_hosts', $allow_google_redirect );
		wp_safe_redirect( esc_url_raw( $url ) );
		remove_filter( 'allowed_redirect_hosts', $allow_google_redirect );
		exit;
	}

	public function handle_oauth_callback() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied', 'stifli-flex-mcp' ) );
		}

		$error = isset( $_GET['error'] ) ? sanitize_text_field( wp_unslash( $_GET['error'] ) ) : '';
		if ( '' !== $error ) {
			$this->redirect_with_oauth_notice( 'error', $error );
		}

		$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
		$code = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
		if ( '' === $state || '' === $code ) {
			$this->redirect_with_oauth_notice( 'error', __( 'Google OAuth callback is missing state or code.', 'stifli-flex-mcp' ) );
		}

		$state_key = 'sflmcp_gsc_oauth_state_' . md5( $state );
		$stored = get_transient( $state_key );
		delete_transient( $state_key );

		if ( ! is_array( $stored ) || empty( $stored['user_id'] ) || (int) $stored['user_id'] !== get_current_user_id() ) {
			$this->redirect_with_oauth_notice( 'error', __( 'Google OAuth state is invalid or expired. Please try connecting again.', 'stifli-flex-mcp' ) );
		}

		$result = StifliFlexMcp_GoogleSearchConsole::exchange_authorization_code( $code, get_current_user_id() );
		if ( is_wp_error( $result ) ) {
			$this->redirect_with_oauth_notice( 'error', $result->get_error_message() );
		}

		$this->redirect_with_oauth_notice( 'connected', __( 'Google account connected successfully.', 'stifli-flex-mcp' ) );
	}

	private function redirect_with_oauth_notice( $status, $message ) {
		$url = add_query_arg(
			array(
				'page' => 'sflmcp-seo',
				'tab' => 'gsc',
				'sflmcp_gsc_oauth' => sanitize_key( $status ),
				'sflmcp_gsc_message' => wp_strip_all_tags( (string) $message ),
			),
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $url );
		exit;
	}

	private function render_oauth_notice() {
		if ( empty( $_GET['sflmcp_gsc_oauth'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$status = sanitize_key( wp_unslash( $_GET['sflmcp_gsc_oauth'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$message = isset( $_GET['sflmcp_gsc_message'] ) ? sanitize_text_field( rawurldecode( wp_unslash( $_GET['sflmcp_gsc_message'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$class = 'connected' === $status ? 'notice-success' : 'notice-error';
		if ( '' === $message ) {
			$message = 'connected' === $status
				? __( 'Google account connected successfully.', 'stifli-flex-mcp' )
				: __( 'Google OAuth connection failed.', 'stifli-flex-mcp' );
		}
		?>
		<div class="notice <?php echo esc_attr( $class ); ?> is-dismissible">
			<p><?php echo esc_html( $message ); ?></p>
		</div>
		<?php
	}

	private function verify_ajax() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied', 'stifli-flex-mcp' ) ), 403 );
		}
		if ( ! class_exists( 'StifliFlexMcp_GoogleSearchConsole' ) ) {
			wp_send_json_error( array( 'message' => __( 'Google Search Console module is not available.', 'stifli-flex-mcp' ) ), 500 );
		}
	}

	private function get_gsc_tool_states() {
		global $wpdb;
		$defs = class_exists( 'StifliFlexMcp_GoogleSearchConsole' ) ? StifliFlexMcp_GoogleSearchConsole::getTools() : array();
		$states = array();
		$tools_table = StifliFlexMcpUtils::getPrefixedTable( 'sflmcp_tools', false );
		$tools_table_sql = StifliFlexMcpUtils::getPrefixedTable( 'sflmcp_tools' );
		$db_enabled = array();

		if ( function_exists( 'stifli_flex_mcp_table_exists' ) && stifli_flex_mcp_table_exists( $tools_table ) ) {
			$names = array_keys( $defs );
			if ( ! empty( $names ) ) {
				$placeholders = implode( ',', array_fill( 0, count( $names ), '%s' ) );
				$cache_key = 'gsc_tool_states_' . md5( implode( '|', $names ) );
				$rows = wp_cache_get( $cache_key, 'sflmcp' );
				if ( false === $rows ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- plugin table name is sanitized by getPrefixedTable().
					$rows = $wpdb->get_results(
						$wpdb->prepare(
							"SELECT tool_name, enabled FROM {$tools_table_sql} WHERE tool_name IN ({$placeholders})",
							$names
						),
						ARRAY_A
					);
					wp_cache_set( $cache_key, $rows, 'sflmcp', 5 * MINUTE_IN_SECONDS );
				}
				foreach ( (array) $rows as $row ) {
					$db_enabled[ $row['tool_name'] ] = (int) $row['enabled'];
				}
			}
		}

		foreach ( $defs as $name => $def ) {
			$states[] = array(
				'name' => $name,
				'description' => isset( $def['description'] ) ? $def['description'] : '',
				'enabled' => array_key_exists( $name, $db_enabled ) ? (bool) $db_enabled[ $name ] : true,
			);
		}

		return $states;
	}
}
