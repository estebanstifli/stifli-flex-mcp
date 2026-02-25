<?php
/**
 * Logs Admin
 *
 * Handles the debug logs admin page.
 *
 * @package StifliFlexMcp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Logs Admin class
 */
class StifliFlexMcp_Logs_Admin {

	/**
	 * Constructor
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu' ), 35 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		
		// AJAX handlers for logs management
		add_action( 'wp_ajax_sflmcp_toggle_logging', array( $this, 'ajax_toggle_logging' ) );
		add_action( 'wp_ajax_sflmcp_clear_logs', array( $this, 'ajax_clear_logs' ) );
		add_action( 'wp_ajax_sflmcp_refresh_logs', array( $this, 'ajax_refresh_logs' ) );
	}

	/**
	 * Add menu item - Logs as separate menu item
	 */
	public function add_menu() {
		add_submenu_page(
			'stifli-flex-mcp',
			__( 'Logs', 'stifli-flex-mcp' ),
			__( 'Logs', 'stifli-flex-mcp' ),
			'manage_options',
			'sflmcp-logs',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Enqueue assets
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets( $hook ) {
		if ( 'stifli-flex-mcp_page_sflmcp-logs' !== $hook ) {
			return;
		}

		// Enqueue Logs CSS
		wp_enqueue_style(
			'sflmcp-admin-logs',
			plugin_dir_url( dirname( __FILE__ ) ) . 'assets/admin-logs.css',
			array(),
			'1.0.4'
		);

		// Enqueue Logs JavaScript
		wp_enqueue_script(
			'sflmcp-admin-logs',
			plugin_dir_url( dirname( __FILE__ ) ) . 'assets/admin-logs.js',
			array( 'jquery' ),
			'1.0.4',
			true
		);

		// Localize script with data
		wp_localize_script( 'sflmcp-admin-logs', 'sflmcpLogs', array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'sflmcp_logs' ),
			'i18n'    => array(
				'loggingEnabled'  => __( 'Logging enabled', 'stifli-flex-mcp' ),
				'loggingDisabled' => __( 'Logging disabled', 'stifli-flex-mcp' ),
				'errorSaving'     => __( 'Error saving setting', 'stifli-flex-mcp' ),
				'loading'         => __( 'Loading...', 'stifli-flex-mcp' ),
				'confirmClear'    => __( 'Are you sure you want to clear all logs?', 'stifli-flex-mcp' ),
				'logsCleared'     => __( 'Logs cleared successfully', 'stifli-flex-mcp' ),
			),
		) );
	}

	/**
	 * Render the logs page
	 */
	public function render_page() {
		$logging_enabled = get_option( 'sflmcp_logging_enabled', false );
		$log_contents    = stifli_flex_mcp_get_log_contents( 500 );
		$log_size        = stifli_flex_mcp_get_log_size();
		$log_file_path   = stifli_flex_mcp_get_log_file_path();

		// Format file size
		if ( $log_size >= 1048576 ) {
			$log_size_formatted = number_format( $log_size / 1048576, 2 ) . ' MB';
		} elseif ( $log_size >= 1024 ) {
			$log_size_formatted = number_format( $log_size / 1024, 2 ) . ' KB';
		} else {
			$log_size_formatted = $log_size . ' bytes';
		}
		?>
		<div class="wrap sflmcp-logs-page">
			<h1><?php echo esc_html__( 'StifLi Flex MCP - Logs', 'stifli-flex-mcp' ); ?></h1>

			<h2><?php echo esc_html__( '📋 Debug Logging', 'stifli-flex-mcp' ); ?></h2>

			<div class="sflmcp-logs-info-box">
				<h3><?php echo esc_html__( 'About Logging', 'stifli-flex-mcp' ); ?></h3>
				<p><?php echo esc_html__( 'When enabled, the plugin will log debug information to help troubleshoot issues. Logs include API requests, authentication events, and tool executions.', 'stifli-flex-mcp' ); ?></p>
				<p><strong><?php echo esc_html__( 'Note:', 'stifli-flex-mcp' ); ?></strong> <?php echo esc_html__( 'Logging can also be enabled by defining SFLMCP_DEBUG as true in wp-config.php.', 'stifli-flex-mcp' ); ?></p>
			</div>

			<table class="form-table">
				<tr valign="top">
					<th scope="row"><?php echo esc_html__( 'Enable Logging', 'stifli-flex-mcp' ); ?></th>
					<td>
						<label>
							<input type="checkbox" id="sflmcp_logging_enabled" <?php checked( $logging_enabled, true ); ?> />
							<?php echo esc_html__( 'Enable debug logging', 'stifli-flex-mcp' ); ?>
						</label>
						<p class="description"><?php echo esc_html__( 'When enabled, debug information will be written to the log file.', 'stifli-flex-mcp' ); ?></p>
						<?php if ( defined( 'SFLMCP_DEBUG' ) && SFLMCP_DEBUG === true ) : ?>
							<p class="sflmcp-warning-text"><strong><?php echo esc_html__( '⚠️ SFLMCP_DEBUG is defined as true in wp-config.php. Logging is always enabled.', 'stifli-flex-mcp' ); ?></strong></p>
						<?php endif; ?>
					</td>
				</tr>
				<tr valign="top">
					<th scope="row"><?php echo esc_html__( 'Log File', 'stifli-flex-mcp' ); ?></th>
					<td>
						<code class="sflmcp-log-path"><?php echo esc_html( $log_file_path ); ?></code>
						<p class="description"><?php echo sprintf(
							/* translators: %s: file size */
							esc_html__( 'Current file size: %s', 'stifli-flex-mcp' ),
							esc_html( $log_size_formatted )
						); ?></p>
					</td>
				</tr>
			</table>

			<p>
				<button type="button" class="button button-secondary" id="sflmcp_refresh_logs">
					<?php echo esc_html__( '🔄 Refresh Logs', 'stifli-flex-mcp' ); ?>
				</button>
				<button type="button" class="button button-secondary sflmcp-btn-danger" id="sflmcp_clear_logs">
					<?php echo esc_html__( '🗑️ Clear Logs', 'stifli-flex-mcp' ); ?>
				</button>
			</p>

			<h3><?php echo esc_html__( 'Log Contents', 'stifli-flex-mcp' ); ?> <small class="sflmcp-small-text">(<?php echo esc_html__( 'last 500 lines', 'stifli-flex-mcp' ); ?>)</small></h3>

			<textarea id="sflmcp_log_viewer" class="sflmcp-log-viewer" readonly wrap="off"><?php echo esc_textarea( $log_contents ); ?></textarea>
		</div>
		<?php
	}

	/**
	 * AJAX handler: Toggle logging
	 */
	public function ajax_toggle_logging() {
		check_ajax_referer( 'sflmcp_logs', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied', 'stifli-flex-mcp' ) ) );
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- boolean check only
		$enabled = ! empty( $_POST['enabled'] ) && in_array( $_POST['enabled'], array( 'true', '1', 1, true ), true );
		update_option( 'sflmcp_logging_enabled', $enabled ? '1' : '' );

		wp_send_json_success( array(
			'enabled' => $enabled,
			'message' => $enabled
				? __( 'Logging enabled', 'stifli-flex-mcp' )
				: __( 'Logging disabled', 'stifli-flex-mcp' ),
		) );
	}

	/**
	 * AJAX handler: Clear logs
	 */
	public function ajax_clear_logs() {
		check_ajax_referer( 'sflmcp_logs', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied', 'stifli-flex-mcp' ) ) );
		}

		$result = stifli_flex_mcp_clear_log();

		if ( $result ) {
			wp_send_json_success( array( 'message' => __( 'Logs cleared successfully', 'stifli-flex-mcp' ) ) );
		} else {
			wp_send_json_error( array( 'message' => __( 'Error clearing logs', 'stifli-flex-mcp' ) ) );
		}
	}

	/**
	 * AJAX handler: Refresh logs
	 */
	public function ajax_refresh_logs() {
		check_ajax_referer( 'sflmcp_logs', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied', 'stifli-flex-mcp' ) ) );
		}

		$log_contents  = stifli_flex_mcp_get_log_contents( 500 );
		$log_size      = stifli_flex_mcp_get_log_size();
		$log_file_path = stifli_flex_mcp_get_log_file_path();

		// Format file size
		if ( $log_size >= 1048576 ) {
			$log_size_formatted = number_format( $log_size / 1048576, 2 ) . ' MB';
		} elseif ( $log_size >= 1024 ) {
			$log_size_formatted = number_format( $log_size / 1024, 2 ) . ' KB';
		} else {
			$log_size_formatted = $log_size . ' bytes';
		}

		wp_send_json_success( array(
			'contents'      => $log_contents,
			'size'          => $log_size,
			'size_formatted' => $log_size_formatted,
			'path'          => $log_file_path,
		) );
	}
}
