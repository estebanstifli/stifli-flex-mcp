<?php
/**
 * Logs Admin
 *
 * Handles the debug logs and changelog admin pages.
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
		
		// AJAX handlers for debug logs
		add_action( 'wp_ajax_sflmcp_toggle_logging', array( $this, 'ajax_toggle_logging' ) );
		add_action( 'wp_ajax_sflmcp_clear_logs', array( $this, 'ajax_clear_logs' ) );
		add_action( 'wp_ajax_sflmcp_refresh_logs', array( $this, 'ajax_refresh_logs' ) );

		// AJAX handlers for changelog
		add_action( 'wp_ajax_sflmcp_get_changelog', array( $this, 'ajax_get_changelog' ) );
		add_action( 'wp_ajax_sflmcp_get_changelog_detail', array( $this, 'ajax_get_changelog_detail' ) );
		add_action( 'wp_ajax_sflmcp_rollback_change', array( $this, 'ajax_rollback_change' ) );
		add_action( 'wp_ajax_sflmcp_redo_change', array( $this, 'ajax_redo_change' ) );
		add_action( 'wp_ajax_sflmcp_rollback_session', array( $this, 'ajax_rollback_session' ) );
		add_action( 'wp_ajax_sflmcp_purge_changelog', array( $this, 'ajax_purge_changelog' ) );
		add_action( 'wp_ajax_sflmcp_export_changelog', array( $this, 'ajax_export_changelog' ) );
		add_action( 'wp_ajax_sflmcp_toggle_changelog', array( $this, 'ajax_toggle_changelog' ) );
	}

	/**
	 * Add menu item - Logs as separate menu item
	 */
	public function add_menu() {
		add_submenu_page(
			'stifli-flex-mcp',
			__( 'Logs & Roll Back', 'stifli-flex-mcp' ),
			__( 'Logs & Roll Back', 'stifli-flex-mcp' ),
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

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reading tab param only
		$active_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'changelog';

		if ( 'changelog' === $active_tab ) {
			// Changelog tab assets
			wp_enqueue_style(
				'sflmcp-admin-changelog',
				plugin_dir_url( dirname( __FILE__ ) ) . 'assets/admin-changelog.css',
				array(),
				'1.0.0'
			);
			wp_enqueue_script(
				'sflmcp-admin-changelog',
				plugin_dir_url( dirname( __FILE__ ) ) . 'assets/admin-changelog.js',
				array( 'jquery' ),
				'1.0.0',
				true
			);
			wp_localize_script( 'sflmcp-admin-changelog', 'sflmcpChangelog', array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'sflmcp_changelog' ),
				'i18n'    => array(
					'loading'          => __( 'Loading...', 'stifli-flex-mcp' ),
					'noEntries'        => __( 'No changelog entries found.', 'stifli-flex-mcp' ),
					'error'            => __( 'Error loading data.', 'stifli-flex-mcp' ),
					'confirmRollback'  => __( 'Are you sure you want to rollback this change?', 'stifli-flex-mcp' ),
					'confirmRedo'      => __( 'Are you sure you want to redo this change?', 'stifli-flex-mcp' ),
					'confirmPurge'     => __( 'Are you sure you want to purge old entries? This cannot be undone.', 'stifli-flex-mcp' ),
					'rollbackSuccess'  => __( 'Change rolled back successfully.', 'stifli-flex-mcp' ),
					'redoSuccess'      => __( 'Change re-applied successfully.', 'stifli-flex-mcp' ),
					'exportEmpty'      => __( 'No data to export.', 'stifli-flex-mcp' ),
					'viewDetail'       => __( 'View', 'stifli-flex-mcp' ),
					'detail'           => __( 'Detail', 'stifli-flex-mcp' ),
					'rollback'         => __( 'Rollback', 'stifli-flex-mcp' ),
					'redo'             => __( 'Redo', 'stifli-flex-mcp' ),
					'active'           => __( 'Active', 'stifli-flex-mcp' ),
					'rolledBack'       => __( 'Rolled back', 'stifli-flex-mcp' ),
					'user'             => __( 'User', 'stifli-flex-mcp' ),
					'showing'          => __( 'Showing', 'stifli-flex-mcp' ),
					'of'               => __( 'of', 'stifli-flex-mcp' ),
					'prev'             => __( 'Prev', 'stifli-flex-mcp' ),
					'next'             => __( 'Next', 'stifli-flex-mcp' ),
					'entries'          => __( 'entries', 'stifli-flex-mcp' ),
					'page'             => __( 'Page', 'stifli-flex-mcp' ),
					'confirmSessionRollback' => __( 'Are you sure you want to rollback ALL changes from this session? This will undo every change made during this conversation.', 'stifli-flex-mcp' ),
					'rollbackSession'  => __( 'Rollback Entire Session', 'stifli-flex-mcp' ),
				),
			) );
		} else {
			// Debug log tab assets
			wp_enqueue_style(
				'sflmcp-admin-logs',
				plugin_dir_url( dirname( __FILE__ ) ) . 'assets/admin-logs.css',
				array(),
				'1.0.4'
			);
			wp_enqueue_script(
				'sflmcp-admin-logs',
				plugin_dir_url( dirname( __FILE__ ) ) . 'assets/admin-logs.js',
				array( 'jquery' ),
				'1.0.4',
				true
			);
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
	}

	/**
	 * Render the logs page with tabs
	 */
	public function render_page() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reading tab param only
		$active_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'changelog';
		?>
		<div class="wrap sflmcp-logs-page">
			<h1><?php echo esc_html__( 'StifLi Flex MCP - Logs', 'stifli-flex-mcp' ); ?></h1>

			<h2 class="nav-tab-wrapper">
				<a href="?page=sflmcp-logs&tab=changelog" class="nav-tab <?php echo 'changelog' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<?php echo esc_html__( '📝 Changelog', 'stifli-flex-mcp' ); ?>
				</a>
				<a href="?page=sflmcp-logs&tab=debug" class="nav-tab <?php echo 'debug' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<?php echo esc_html__( '📋 Debug Log', 'stifli-flex-mcp' ); ?>
				</a>
			</h2>

			<?php
			if ( 'changelog' === $active_tab ) {
				$this->render_changelog_tab();
			} else {
				$this->render_debug_tab();
			}
			?>
		</div>
		<?php
	}

	/**
	 * Render the Debug Log tab
	 */
	private function render_debug_tab() {
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
		<?php
	}

	/**
	 * Render the Changelog tab
	 */
	private function render_changelog_tab() {
		$enabled = get_option( 'sflmcp_changelog_enabled', true );
		?>
		<div class="sflmcp-changelog-wrap">
			<p>
				<label>
					<input type="checkbox" id="sflmcp-changelog-toggle" <?php checked( $enabled ); ?>>
					<?php esc_html_e( 'Enable Change Tracking', 'stifli-flex-mcp' ); ?>
				</label>
				<span class="description"><?php esc_html_e( 'When enabled, all mutating MCP tool operations are logged with before/after state for audit and rollback.', 'stifli-flex-mcp' ); ?></span>
			</p>

			<div class="sflmcp-changelog-stats">
				<div class="stat-box stat-total"><span class="stat-number" id="stat-total">-</span><span class="stat-label"><?php esc_html_e( 'Total', 'stifli-flex-mcp' ); ?></span></div>
				<div class="stat-box stat-create"><span class="stat-number" id="stat-creates">-</span><span class="stat-label"><?php esc_html_e( 'Creates', 'stifli-flex-mcp' ); ?></span></div>
				<div class="stat-box stat-update"><span class="stat-number" id="stat-updates">-</span><span class="stat-label"><?php esc_html_e( 'Updates', 'stifli-flex-mcp' ); ?></span></div>
				<div class="stat-box stat-delete"><span class="stat-number" id="stat-deletes">-</span><span class="stat-label"><?php esc_html_e( 'Deletes', 'stifli-flex-mcp' ); ?></span></div>
				<div class="stat-box stat-rolled-back"><span class="stat-number" id="stat-rolled-back">-</span><span class="stat-label"><?php esc_html_e( 'Rolled Back', 'stifli-flex-mcp' ); ?></span></div>
			</div>

			<div class="sflmcp-changelog-filters">
				<label><?php esc_html_e( 'Tool', 'stifli-flex-mcp' ); ?>
					<input type="text" id="sflmcp-filter-tool" placeholder="<?php esc_attr_e( 'e.g. wp_update_post', 'stifli-flex-mcp' ); ?>">
				</label>
				<label><?php esc_html_e( 'Operation', 'stifli-flex-mcp' ); ?>
					<select id="sflmcp-filter-operation">
						<option value=""><?php esc_html_e( 'All', 'stifli-flex-mcp' ); ?></option>
						<option value="create"><?php esc_html_e( 'Create', 'stifli-flex-mcp' ); ?></option>
						<option value="update"><?php esc_html_e( 'Update', 'stifli-flex-mcp' ); ?></option>
						<option value="delete"><?php esc_html_e( 'Delete', 'stifli-flex-mcp' ); ?></option>
						<option value="file_create"><?php esc_html_e( 'File Create', 'stifli-flex-mcp' ); ?></option>
						<option value="file_delete"><?php esc_html_e( 'File Delete', 'stifli-flex-mcp' ); ?></option>
					</select>
				</label>
				<label><?php esc_html_e( 'Object Type', 'stifli-flex-mcp' ); ?>
					<input type="text" id="sflmcp-filter-object" placeholder="<?php esc_attr_e( 'e.g. post, product', 'stifli-flex-mcp' ); ?>">
				</label>
				<label><?php esc_html_e( 'Status', 'stifli-flex-mcp' ); ?>
					<select id="sflmcp-filter-status">
						<option value=""><?php esc_html_e( 'All', 'stifli-flex-mcp' ); ?></option>
						<option value="0"><?php esc_html_e( 'Active', 'stifli-flex-mcp' ); ?></option>
						<option value="1"><?php esc_html_e( 'Rolled back', 'stifli-flex-mcp' ); ?></option>
					</select>
				</label>
				<label><?php esc_html_e( 'Source', 'stifli-flex-mcp' ); ?>
					<select id="sflmcp-filter-source">
						<option value=""><?php esc_html_e( 'All', 'stifli-flex-mcp' ); ?></option>
						<option value="mcp"><?php esc_html_e( 'MCP Connection', 'stifli-flex-mcp' ); ?></option>
						<option value="chat_agent"><?php esc_html_e( 'AI Chat Agent', 'stifli-flex-mcp' ); ?></option>
						<option value="copilot"><?php esc_html_e( 'Copilot Editor', 'stifli-flex-mcp' ); ?></option>
						<option value="automation"><?php esc_html_e( 'Automation Task', 'stifli-flex-mcp' ); ?></option>
						<option value="event_automation"><?php esc_html_e( 'Event Automation', 'stifli-flex-mcp' ); ?></option>
						<option value="wp_admin"><?php esc_html_e( 'WP Admin', 'stifli-flex-mcp' ); ?></option>
					</select>
				</label>
				<label><?php esc_html_e( 'From', 'stifli-flex-mcp' ); ?>
					<input type="date" id="sflmcp-filter-date-from">
				</label>
				<label><?php esc_html_e( 'To', 'stifli-flex-mcp' ); ?>
					<input type="date" id="sflmcp-filter-date-to">
				</label>
				<button class="button button-primary" id="sflmcp-filter-apply"><?php esc_html_e( 'Filter', 'stifli-flex-mcp' ); ?></button>
				<button class="button" id="sflmcp-filter-reset"><?php esc_html_e( 'Reset', 'stifli-flex-mcp' ); ?></button>
			</div>

			<div class="sflmcp-changelog-actions">
				<button class="button" id="sflmcp-export-btn"><?php esc_html_e( 'Export CSV', 'stifli-flex-mcp' ); ?></button>
				<input type="number" id="sflmcp-purge-days" class="sflmcp-purge-days" value="30" min="1" max="365">
				<button class="button" id="sflmcp-purge-btn"><?php esc_html_e( 'Purge older than (days)', 'stifli-flex-mcp' ); ?></button>
			</div>

			<table class="sflmcp-changelog-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'ID', 'stifli-flex-mcp' ); ?></th>
						<th><?php esc_html_e( 'Tool', 'stifli-flex-mcp' ); ?></th>
						<th><?php esc_html_e( 'Operation', 'stifli-flex-mcp' ); ?></th>
						<th><?php esc_html_e( 'Object', 'stifli-flex-mcp' ); ?></th>
						<th><?php esc_html_e( 'Source', 'stifli-flex-mcp' ); ?></th>
						<th><?php esc_html_e( 'Date', 'stifli-flex-mcp' ); ?></th>
						<th><?php esc_html_e( 'User', 'stifli-flex-mcp' ); ?></th>
						<th><?php esc_html_e( 'Status', 'stifli-flex-mcp' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'stifli-flex-mcp' ); ?></th>
					</tr>
				</thead>
				<tbody id="sflmcp-changelog-body">
					<tr><td colspan="9" class="sflmcp-loading-cell"><?php esc_html_e( 'Loading...', 'stifli-flex-mcp' ); ?></td></tr>
				</tbody>
			</table>

			<div class="sflmcp-changelog-pagination">
				<span class="pagination-info" id="sflmcp-pagination-info"></span>
				<div class="pagination-buttons" id="sflmcp-pagination-buttons"></div>
			</div>
		</div>

		<!-- Detail Modal -->
		<div class="sflmcp-modal-overlay" id="sflmcp-modal-overlay">
			<div class="sflmcp-modal">
				<div class="sflmcp-modal-header">
					<h3><?php esc_html_e( 'Change Detail', 'stifli-flex-mcp' ); ?> <span id="detail-id"></span></h3>
					<button class="sflmcp-modal-close">&times;</button>
				</div>
				<div class="sflmcp-modal-body">
					<div class="sflmcp-detail-grid">
						<div class="detail-item"><span class="detail-label"><?php esc_html_e( 'Tool', 'stifli-flex-mcp' ); ?></span><span class="detail-value" id="detail-tool"></span></div>
						<div class="detail-item"><span class="detail-label"><?php esc_html_e( 'Operation', 'stifli-flex-mcp' ); ?></span><span class="detail-value" id="detail-operation"></span></div>
						<div class="detail-item"><span class="detail-label"><?php esc_html_e( 'Object', 'stifli-flex-mcp' ); ?></span><span class="detail-value" id="detail-object"></span></div>
						<div class="detail-item"><span class="detail-label"><?php esc_html_e( 'Subtype', 'stifli-flex-mcp' ); ?></span><span class="detail-value" id="detail-subtype"></span></div>
						<div class="detail-item"><span class="detail-label"><?php esc_html_e( 'User', 'stifli-flex-mcp' ); ?></span><span class="detail-value" id="detail-user"></span></div>
						<div class="detail-item"><span class="detail-label"><?php esc_html_e( 'IP Address', 'stifli-flex-mcp' ); ?></span><span class="detail-value" id="detail-ip"></span></div>
						<div class="detail-item"><span class="detail-label"><?php esc_html_e( 'Source', 'stifli-flex-mcp' ); ?></span><span class="detail-value" id="detail-source"></span></div>
						<div class="detail-item"><span class="detail-label"><?php esc_html_e( 'Date', 'stifli-flex-mcp' ); ?></span><span class="detail-value" id="detail-date"></span></div>
						<div class="detail-item"><span class="detail-label"><?php esc_html_e( 'Session', 'stifli-flex-mcp' ); ?></span><span class="detail-value" id="detail-session"></span> <button id="sflmcp-rollback-session-btn" class="button button-small sflmcp-btn-rollback sflmcp-hidden sflmcp-ml-8" title="<?php esc_attr_e( 'Rollback Entire Session', 'stifli-flex-mcp' ); ?>">⏪ <?php esc_html_e( 'Rollback Entire Session', 'stifli-flex-mcp' ); ?></button></div>
						<div class="detail-item"><span class="detail-label"><?php esc_html_e( 'Status', 'stifli-flex-mcp' ); ?></span><span class="detail-value" id="detail-status"></span></div>
					</div>
					<h4><?php esc_html_e( 'Arguments', 'stifli-flex-mcp' ); ?></h4>
					<pre id="detail-args" class="sflmcp-detail-args"></pre>
					<div class="sflmcp-state-compare">
						<div class="state-panel before">
							<h4><?php esc_html_e( 'Before State', 'stifli-flex-mcp' ); ?></h4>
							<pre id="detail-before"></pre>
						</div>
						<div class="state-panel after">
							<h4><?php esc_html_e( 'After State', 'stifli-flex-mcp' ); ?></h4>
							<pre id="detail-after"></pre>
						</div>
					</div>
				</div>
			</div>
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

	/* ================================================================
	 * CHANGELOG AJAX HANDLERS
	 * ================================================================ */

	/* phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter */

	/**
	 * AJAX handler: Get changelog entries (paginated + filtered)
	 */
	public function ajax_get_changelog() {
		check_ajax_referer( 'sflmcp_changelog', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Insufficient permissions' );
		}

		$tracker  = StifliFlexMcp_ChangeTracker::getInstance();
		$page     = max( 1, intval( $_POST['page'] ?? 1 ) );
		$per_page = max( 1, min( 100, intval( $_POST['per_page'] ?? 25 ) ) );

		$filters = array(
			'limit'  => $per_page,
			'offset' => ( $page - 1 ) * $per_page,
		);
		if ( ! empty( $_POST['tool_name'] ) ) {
			$filters['tool_name'] = sanitize_text_field( wp_unslash( $_POST['tool_name'] ) );
		}
		if ( ! empty( $_POST['operation_type'] ) ) {
			$filters['operation_type'] = sanitize_key( wp_unslash( $_POST['operation_type'] ) );
		}
		if ( ! empty( $_POST['object_type'] ) ) {
			$filters['object_type'] = sanitize_key( wp_unslash( $_POST['object_type'] ) );
		}
		if ( ! empty( $_POST['date_from'] ) ) {
			$filters['date_from'] = sanitize_text_field( wp_unslash( $_POST['date_from'] ) ) . ' 00:00:00';
		}
		if ( ! empty( $_POST['date_to'] ) ) {
			$filters['date_to'] = sanitize_text_field( wp_unslash( $_POST['date_to'] ) ) . ' 23:59:59';
		}
		if ( isset( $_POST['rolled_back'] ) && '' !== $_POST['rolled_back'] ) {
			$filters['rolled_back'] = intval( $_POST['rolled_back'] );
		}
		if ( ! empty( $_POST['source'] ) ) {
			$filters['source'] = sanitize_key( wp_unslash( $_POST['source'] ) );
		}

		$result = $tracker->getHistory( $filters );

		// Source labels for display
		$source_labels = array(
			'mcp'              => __( 'MCP', 'stifli-flex-mcp' ),
			'chat_agent'       => __( 'AI Chat Agent', 'stifli-flex-mcp' ),
			'copilot'          => __( 'Copilot Editor', 'stifli-flex-mcp' ),
			'automation'       => __( 'Automation', 'stifli-flex-mcp' ),
			'event_automation' => __( 'Event Automation', 'stifli-flex-mcp' ),
			'wp_admin'         => __( 'WP Admin', 'stifli-flex-mcp' ),
		);

		// Enrich rows with user display names and source display
		foreach ( $result['rows'] as &$row ) {
			$uid = intval( $row['user_id'] ?? 0 );
			if ( $uid > 0 ) {
				$user = get_userdata( $uid );
				$row['user_display'] = $user ? $user->display_name . ' (#' . $uid . ')' : 'User #' . $uid;
			} else {
				$row['user_display'] = '-';
			}

			// Build source display string
			$src_key = $row['source'] ?? '';
			$src_type = isset( $source_labels[ $src_key ] ) ? $source_labels[ $src_key ] : ucfirst( $src_key );
			$src_label = ! empty( $row['source_label'] ) ? $row['source_label'] : '';
			$row['source_display'] = $src_label ? $src_type . ' — ' . $src_label : $src_type;
		}
		unset( $row );

		// Compute stats
		global $wpdb;
		$table = $wpdb->prefix . 'sflmcp_changelog';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix is safe; SchemaChange is a false positive triggered by the string 'create' in WHERE values.
		$stats = array(
			'total'       => (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" ),
			'creates'     => (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}` WHERE operation_type IN ('create','file_create')" ),
			'updates'     => (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}` WHERE operation_type = 'update'" ),
			'deletes'     => (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}` WHERE operation_type IN ('delete','file_delete')" ),
			'rolled_back' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}` WHERE rolled_back = 1" ),
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		wp_send_json_success( array(
			'rows'  => $result['rows'],
			'total' => $result['total'],
			'stats' => $stats,
		) );
	}

	/**
	 * AJAX handler: Get single changelog entry detail
	 */
	public function ajax_get_changelog_detail() {
		check_ajax_referer( 'sflmcp_changelog', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Insufficient permissions' );
		}

		$id = intval( $_POST['id'] ?? 0 );
		if ( ! $id ) {
			wp_send_json_error( 'Invalid ID' );
		}

		global $wpdb;
		$table = $wpdb->prefix . 'sflmcp_changelog';
		$table_sql = StifliFlexMcpUtils::wrapTableNameForQuery( $table );
		if ( '' === $table_sql ) {
			wp_send_json_error( 'Invalid changelog table' );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name sanitized via helper before interpolation.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table_sql} WHERE id = %d", $id ), ARRAY_A );
		if ( ! $row ) {
			wp_send_json_error( 'Entry not found' );
		}

		// Enrich with user display name
		$uid = intval( $row['user_id'] ?? 0 );
		if ( $uid > 0 ) {
			$user = get_userdata( $uid );
			$row['user_display'] = $user ? $user->display_name . ' (#' . $uid . ')' : 'User #' . $uid;
		} else {
			$row['user_display'] = '-';
		}

		// Enrich with source display
		$source_labels = array(
			'mcp'              => __( 'MCP', 'stifli-flex-mcp' ),
			'chat_agent'       => __( 'AI Chat Agent', 'stifli-flex-mcp' ),
			'copilot'          => __( 'Copilot Editor', 'stifli-flex-mcp' ),
			'automation'       => __( 'Automation', 'stifli-flex-mcp' ),
			'event_automation' => __( 'Event Automation', 'stifli-flex-mcp' ),
			'wp_admin'         => __( 'WP Admin', 'stifli-flex-mcp' ),
		);
		$src_key = $row['source'] ?? '';
		$src_type = isset( $source_labels[ $src_key ] ) ? $source_labels[ $src_key ] : ucfirst( $src_key );
		$src_label = ! empty( $row['source_label'] ) ? $row['source_label'] : '';
		$row['source_display'] = $src_label ? $src_type . ' — ' . $src_label : $src_type;

		wp_send_json_success( $row );
	}

	/**
	 * AJAX handler: Rollback a change
	 */
	public function ajax_rollback_change() {
		check_ajax_referer( 'sflmcp_changelog', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Insufficient permissions' );
		}

		$id = intval( $_POST['id'] ?? 0 );
		if ( ! $id ) {
			wp_send_json_error( 'Invalid ID' );
		}

		$tracker = StifliFlexMcp_ChangeTracker::getInstance();
		$result = $tracker->rollback( $id );

		if ( $result['success'] ) {
			wp_send_json_success( $result );
		} else {
			wp_send_json_error( $result['message'] );
		}
	}

	/**
	 * AJAX handler: Redo a rolled-back change
	 */
	public function ajax_redo_change() {
		check_ajax_referer( 'sflmcp_changelog', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Insufficient permissions' );
		}

		$id = intval( $_POST['id'] ?? 0 );
		if ( ! $id ) {
			wp_send_json_error( 'Invalid ID' );
		}

		$tracker = StifliFlexMcp_ChangeTracker::getInstance();
		$result = $tracker->redo( $id );

		if ( $result['success'] ) {
			wp_send_json_success( $result );
		} else {
			wp_send_json_error( $result['message'] );
		}
	}

	/**
	 * AJAX handler: Rollback all changes in a session
	 */
	public function ajax_rollback_session() {
		check_ajax_referer( 'sflmcp_changelog', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Insufficient permissions' );
		}

		$session_id = isset( $_POST['session_id'] ) ? sanitize_text_field( wp_unslash( $_POST['session_id'] ) ) : '';
		if ( empty( $session_id ) ) {
			wp_send_json_error( 'Invalid session ID' );
		}

		$tracker = StifliFlexMcp_ChangeTracker::getInstance();
		$result = $tracker->rollbackSession( $session_id );

		if ( $result['success'] ) {
			wp_send_json_success( $result );
		} else {
			wp_send_json_error( $result['message'] );
		}
	}

	/**
	 * AJAX handler: Purge old changelog entries
	 */
	public function ajax_purge_changelog() {
		check_ajax_referer( 'sflmcp_changelog', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Insufficient permissions' );
		}

		$days = max( 1, intval( $_POST['days'] ?? 30 ) );
		$tracker = StifliFlexMcp_ChangeTracker::getInstance();
		$deleted = $tracker->purge( $days );

		wp_send_json_success( array(
			'message' => sprintf(
				/* translators: 1: number of entries, 2: number of days */
				__( 'Purged %1$d entries older than %2$d days.', 'stifli-flex-mcp' ),
				$deleted,
				$days
			),
		) );
	}

	/**
	 * AJAX handler: Export changelog as CSV
	 */
	public function ajax_export_changelog() {
		check_ajax_referer( 'sflmcp_changelog', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Insufficient permissions' );
		}

		$filters_json = isset( $_POST['filters'] ) ? sanitize_text_field( wp_unslash( $_POST['filters'] ) ) : '{}';
		$req_filters = json_decode( $filters_json, true );
		if ( ! is_array( $req_filters ) ) {
			$req_filters = array();
		}

		$filters = array( 'limit' => 10000 );
		if ( ! empty( $req_filters['tool_name'] ) ) {
			$filters['tool_name'] = $req_filters['tool_name'];
		}
		if ( ! empty( $req_filters['operation_type'] ) ) {
			$filters['operation_type'] = $req_filters['operation_type'];
		}
		if ( ! empty( $req_filters['object_type'] ) ) {
			$filters['object_type'] = $req_filters['object_type'];
		}
		if ( ! empty( $req_filters['date_from'] ) ) {
			$filters['date_from'] = $req_filters['date_from'] . ' 00:00:00';
		}
		if ( ! empty( $req_filters['date_to'] ) ) {
			$filters['date_to'] = $req_filters['date_to'] . ' 23:59:59';
		}

		$tracker = StifliFlexMcp_ChangeTracker::getInstance();
		$data = $tracker->getHistory( $filters );

		$csv = "ID,Tool,Operation,Object Type,Object ID,Source,Source Detail,User ID,IP,Date,Rolled Back\n";
		foreach ( $data['rows'] as $row ) {
			$csv .= sprintf(
				"%d,%s,%s,%s,%s,%s,%s,%d,%s,%s,%s\n",
				$row['id'],
				$row['tool_name'],
				$row['operation_type'],
				$row['object_type'],
				$row['object_id'] ?? '',
				$row['source'] ?? '',
				str_replace( ',', ' ', $row['source_label'] ?? '' ),
				$row['user_id'],
				$row['ip_address'] ?? '',
				$row['created_at'],
				$row['rolled_back'] ? 'Yes' : 'No'
			);
		}

		wp_send_json_success( array( 'csv' => $csv ) );
	}

	/**
	 * AJAX handler: Toggle changelog enabled/disabled
	 */
	public function ajax_toggle_changelog() {
		check_ajax_referer( 'sflmcp_changelog', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Insufficient permissions' );
		}

		$enabled = intval( $_POST['enabled'] ?? 0 );
		update_option( 'sflmcp_changelog_enabled', (bool) $enabled );
		wp_send_json_success();
	}

	/* phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter */
}
