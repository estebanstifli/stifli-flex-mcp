<?php
/**
 * Event Automation Admin
 *
 * Handles the admin UI for event-triggered automations.
 *
 * @package StifliFlexMcp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Event Automation Admin class
 */
class StifliFlexMcp_Event_Automation_Admin {

	/**
	 * Constructor
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu' ), 25 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		
		// AJAX handlers
		add_action( 'wp_ajax_sflmcp_events_get_automations', array( $this, 'ajax_get_automations' ) );
		add_action( 'wp_ajax_sflmcp_events_get_automation', array( $this, 'ajax_get_automation' ) );
		add_action( 'wp_ajax_sflmcp_events_save_automation', array( $this, 'ajax_save_automation' ) );
		add_action( 'wp_ajax_sflmcp_events_delete_automation', array( $this, 'ajax_delete_automation' ) );
		add_action( 'wp_ajax_sflmcp_events_toggle_status', array( $this, 'ajax_toggle_status' ) );
		add_action( 'wp_ajax_sflmcp_events_get_triggers', array( $this, 'ajax_get_triggers' ) );
		add_action( 'wp_ajax_sflmcp_events_get_logs', array( $this, 'ajax_get_logs' ) );
		add_action( 'wp_ajax_sflmcp_events_test_automation', array( $this, 'ajax_test_automation' ) );
	}

	/**
	 * Add menu item
	 */
	public function add_menu() {
		add_submenu_page(
			'stifli-flex-mcp',
			__( 'Event Automations', 'stifli-flex-mcp' ),
			__( 'Event Automations', 'stifli-flex-mcp' ),
			'manage_options',
			'sflmcp-events',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Enqueue assets
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets( $hook ) {
		if ( 'stifli-flex-mcp_page_sflmcp-events' !== $hook ) {
			return;
		}

		// Use the same CSS as automation tasks for consistent styling
		wp_enqueue_style(
			'sflmcp-automation',
			plugins_url( 'assets/automation.css', __FILE__ ),
			array(),
			filemtime( plugin_dir_path( __FILE__ ) . 'assets/automation.css' )
		);

		// Additional events-specific styles
		wp_enqueue_style(
			'sflmcp-events',
			plugins_url( 'assets/events.css', __FILE__ ),
			array( 'sflmcp-automation' ),
			filemtime( plugin_dir_path( __FILE__ ) . 'assets/events.css' )
		);

		wp_enqueue_script(
			'sflmcp-events',
			plugins_url( 'assets/events.js', __FILE__ ),
			array( 'jquery' ),
			filemtime( plugin_dir_path( __FILE__ ) . 'assets/events.js' ),
			true
		);

		// Get tools for dropdown
		global $stifliFlexMcp;
		$tools = array();
		$all_tools = array();
		if ( isset( $stifliFlexMcp->model ) ) {
			$enabled_tools = $stifliFlexMcp->model->getToolsList();
			foreach ( $enabled_tools as $tool ) {
				$tools[] = array(
					'name'        => $tool['name'],
					'description' => $tool['description'] ?? '',
					'category'    => $tool['category'] ?? 'Other',
				);
			}
			$full_tools = $stifliFlexMcp->model->getTools();
			foreach ( $full_tools as $tool ) {
				$all_tools[] = array(
					'name'        => $tool['name'],
					'description' => $tool['description'] ?? '',
					'category'    => $tool['category'] ?? 'Other',
				);
			}
		}

		wp_localize_script( 'sflmcp-events', 'sflmcpEvents', array(
			'ajaxUrl'           => admin_url( 'admin-ajax.php' ),
			'adminUrl'          => admin_url( 'admin.php' ),
			'nonce'             => wp_create_nonce( 'sflmcp_events_nonce' ),
			'tools'             => $tools,
			'allTools'          => $all_tools,
			'woocommerceActive' => class_exists( 'WooCommerce' ),
			'i18n'              => array(
				'confirmDelete' => __( 'Are you sure you want to delete this automation?', 'stifli-flex-mcp' ),
				'saving'        => __( 'Saving...', 'stifli-flex-mcp' ),
				'saved'         => __( 'Saved!', 'stifli-flex-mcp' ),
				'error'         => __( 'An error occurred', 'stifli-flex-mcp' ),
				'noTrigger'     => __( 'Please select a trigger', 'stifli-flex-mcp' ),
				'noPrompt'      => __( 'Please enter a prompt', 'stifli-flex-mcp' ),
				'testing'       => __( 'Testing prompt...', 'stifli-flex-mcp' ),
				'testCompleted' => __( 'Test completed', 'stifli-flex-mcp' ),
			),
		));
	}

	/**
	 * Render main page
	 */
	public function render_page() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'list';
		
		?>
		<div class="wrap sflmcp-automation-wrap">
			<h1>
				<span class="dashicons dashicons-superhero-alt"></span>
				<?php esc_html_e( 'Event Automations', 'stifli-flex-mcp' ); ?>
			</h1>

			<nav class="nav-tab-wrapper sflmcp-automation-tabs">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=sflmcp-events&tab=list' ) ); ?>" 
				   class="nav-tab <?php echo 'list' === $tab ? 'nav-tab-active' : ''; ?>">
					<span class="dashicons dashicons-list-view"></span>
					<?php esc_html_e( 'Event Automations', 'stifli-flex-mcp' ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=sflmcp-events&tab=create' ) ); ?>" 
				   class="nav-tab <?php echo 'create' === $tab ? 'nav-tab-active' : ''; ?>">
					<span class="dashicons dashicons-plus-alt"></span>
					<?php esc_html_e( 'Create', 'stifli-flex-mcp' ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=sflmcp-events&tab=logs' ) ); ?>" 
				   class="nav-tab <?php echo 'logs' === $tab ? 'nav-tab-active' : ''; ?>">
					<span class="dashicons dashicons-backup"></span>
					<?php esc_html_e( 'Execution Logs', 'stifli-flex-mcp' ); ?>
				</a>
			</nav>

			<div class="sflmcp-automation-content">
				<?php
				switch ( $tab ) {
					case 'create':
						$this->render_create_tab();
						break;
					case 'logs':
						$this->render_logs_tab();
						break;
					default:
						$this->render_list_tab();
						break;
				}
				?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render automations list tab
	 */
	private function render_list_tab() {
		?>
		<div class="sflmcp-tasks-header">
			<div class="sflmcp-tasks-filters">
				<select id="sflmcp-event-filter-trigger">
					<option value=""><?php esc_html_e( 'All Triggers', 'stifli-flex-mcp' ); ?></option>
				</select>
				<select id="sflmcp-event-filter-status">
					<option value=""><?php esc_html_e( 'All Status', 'stifli-flex-mcp' ); ?></option>
					<option value="active"><?php esc_html_e( 'Active', 'stifli-flex-mcp' ); ?></option>
					<option value="paused"><?php esc_html_e( 'Paused', 'stifli-flex-mcp' ); ?></option>
					<option value="error"><?php esc_html_e( 'Error', 'stifli-flex-mcp' ); ?></option>
					<option value="draft"><?php esc_html_e( 'Draft', 'stifli-flex-mcp' ); ?></option>
				</select>
				<button type="button" class="button" id="sflmcp-refresh-events">
					<span class="dashicons dashicons-update"></span>
					<?php esc_html_e( 'Refresh', 'stifli-flex-mcp' ); ?>
				</button>
			</div>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=sflmcp-events&tab=create' ) ); ?>" class="button button-primary">
				<span class="dashicons dashicons-plus-alt"></span>
				<?php esc_html_e( 'New Automation', 'stifli-flex-mcp' ); ?>
			</a>
		</div>

		<div id="sflmcp-tasks-list" class="sflmcp-tasks-list">
			<div class="sflmcp-loading">
				<span class="spinner is-active"></span>
				<?php esc_html_e( 'Loading automations...', 'stifli-flex-mcp' ); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render create/edit tab
	 */
	private function render_create_tab() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$edit_id = isset( $_GET['edit'] ) ? intval( $_GET['edit'] ) : 0;
		$automation = null;

		if ( $edit_id > 0 ) {
			global $wpdb;
			$table = $wpdb->prefix . 'sflmcp_event_automations';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name from $wpdb->prefix is safe.
			$automation = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $edit_id ), ARRAY_A );
		}

		$is_edit = ! empty( $automation );
		
		// Get AI Chat settings
		$settings = get_option( 'sflmcp_client_settings', array() );
		$provider = $settings['provider'] ?? 'openai';
		$model    = $settings['model'] ?? 'gpt-4';
		$has_key  = ! empty( $settings['api_key'] );
		
		// Get enabled tools count
		global $stifliFlexMcp;
		$enabled_tools_count = 0;
		if ( isset( $stifliFlexMcp->model ) ) {
			$enabled_tools_count = count( $stifliFlexMcp->model->getToolsList() );
		}
		?>
		<form id="sflmcp-task-form" class="sflmcp-task-form">
			<input type="hidden" id="sflmcp-task-id" value="<?php echo esc_attr( $edit_id ); ?>">
			<input type="hidden" name="status" id="status" value="<?php echo esc_attr( $automation['status'] ?? 'draft' ); ?>">
			
			<!-- Step 1: Basic Info -->
			<div class="sflmcp-form-section">
				<h2>
					<span class="sflmcp-step-number">1</span>
					<?php esc_html_e( 'Basic Information', 'stifli-flex-mcp' ); ?>
				</h2>
				
				<div class="sflmcp-form-row">
					<label for="automation_name"><?php esc_html_e( 'Automation Name', 'stifli-flex-mcp' ); ?> <span class="required">*</span></label>
					<input type="text" id="automation_name" name="automation_name" required 
						   value="<?php echo esc_attr( $automation['automation_name'] ?? '' ); ?>"
						   placeholder="<?php esc_attr_e( 'e.g., Auto-process new orders', 'stifli-flex-mcp' ); ?>">
				</div>
			</div>

			<!-- Step 2: Trigger Event -->
			<div class="sflmcp-form-section">
				<h2>
					<span class="sflmcp-step-number">2</span>
					<?php esc_html_e( 'Trigger Event', 'stifli-flex-mcp' ); ?>
				</h2>
				
				<div class="sflmcp-form-row">
					<label><?php esc_html_e( 'Platform', 'stifli-flex-mcp' ); ?></label>
					<div class="sflmcp-trigger-family-cards">
						<div class="sflmcp-trigger-family-card active" data-family="wordpress">
							<span class="dashicons dashicons-wordpress"></span>
							<span class="sflmcp-family-name">WordPress</span>
						</div>
						<div class="sflmcp-trigger-family-card" data-family="woocommerce">
							<span class="dashicons dashicons-cart"></span>
							<span class="sflmcp-family-name">WooCommerce</span>
						</div>
					</div>
					<p id="sflmcp-wc-warning" class="sflmcp-platform-warning sflmcp-is-hidden">
						<span class="dashicons dashicons-warning"></span>
						<?php esc_html_e( 'WooCommerce is not installed. Triggers will not work until WooCommerce is active.', 'stifli-flex-mcp' ); ?>
					</p>
				</div>

				<div class="sflmcp-form-row">
					<label for="trigger_id"><?php esc_html_e( 'When this happens:', 'stifli-flex-mcp' ); ?> <span class="required">*</span></label>
					<select id="trigger_id" name="trigger_id" required data-value="<?php echo esc_attr( $automation['trigger_id'] ?? '' ); ?>">
						<option value=""><?php esc_html_e( '-- Select a trigger --', 'stifli-flex-mcp' ); ?></option>
					</select>
					<p class="description" id="trigger-description"></p>
				</div>

				<div class="sflmcp-form-row sflmcp-placeholders-row sflmcp-is-hidden">
					<label><?php esc_html_e( 'Available Placeholders', 'stifli-flex-mcp' ); ?></label>
					<div id="available-placeholders" class="sflmcp-placeholders"></div>
					<p class="description"><?php esc_html_e( 'Click a placeholder to insert it into your prompt.', 'stifli-flex-mcp' ); ?></p>
				</div>

				<div class="sflmcp-form-row">
					<label><?php esc_html_e( 'Conditions', 'stifli-flex-mcp' ); ?> <span class="optional">(<?php esc_html_e( 'optional', 'stifli-flex-mcp' ); ?>)</span></label>
					<p class="description"><?php esc_html_e( 'Only run when these conditions are met:', 'stifli-flex-mcp' ); ?></p>
					<div id="conditions-builder" class="sflmcp-conditions-builder"></div>
					<button type="button" class="button" id="add-condition">
						<span class="dashicons dashicons-plus"></span>
						<?php esc_html_e( 'Add Condition', 'stifli-flex-mcp' ); ?>
					</button>
					<input type="hidden" name="conditions" id="conditions-json" value="<?php echo esc_attr( $automation['conditions'] ?? '[]' ); ?>">
				</div>
			</div>

			<!-- Step 3: AI Prompt & Test Chat -->
			<div class="sflmcp-form-section">
				<h2 class="sflmcp-section-header-inline">
					<span class="sflmcp-step-number">3</span>
					<?php esc_html_e( 'AI Prompt', 'stifli-flex-mcp' ); ?>
					<span class="sflmcp-header-meta">
						<span class="sflmcp-model-badge"><?php echo esc_html( ucfirst( $provider ) . ' / ' . $model ); ?></span>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=stifli-flex-mcp&tab=chat' ) ); ?>" class="sflmcp-header-link"><?php esc_html_e( 'Change Settings', 'stifli-flex-mcp' ); ?></a>
						<?php if ( ! $has_key ) : ?>
							<span class="sflmcp-no-key-warning">
								<span class="dashicons dashicons-warning"></span>
								<?php esc_html_e( 'API Key not configured', 'stifli-flex-mcp' ); ?>
							</span>
						<?php endif; ?>
					</span>
				</h2>

				<!-- Chat-style Test Interface -->
				<div class="sflmcp-test-chat">
					<div class="sflmcp-test-chat-header">
						<span class="dashicons dashicons-format-chat"></span>
						<?php esc_html_e( 'Test Chat', 'stifli-flex-mcp' ); ?>
						<button type="button" id="sflmcp-clear-test-chat" class="button-link sflmcp-push-right">
							<?php esc_html_e( 'Clear', 'stifli-flex-mcp' ); ?>
						</button>
					</div>
					<div id="sflmcp-test-chat-messages" class="sflmcp-test-chat-messages">
						<div class="sflmcp-test-welcome">
							<span class="dashicons dashicons-lightbulb"></span>
							<?php esc_html_e( 'Write your prompt below and click "Test Prompt" to see how the AI responds. Tool executions will appear here.', 'stifli-flex-mcp' ); ?>
						</div>
					</div>
					<div class="sflmcp-test-chat-input">
						<textarea id="prompt" name="prompt" rows="4" required
								  placeholder="<?php esc_attr_e( 'Describe what the AI should do when this event fires. Use {{placeholders}} for dynamic data.', 'stifli-flex-mcp' ); ?>"><?php echo esc_textarea( $automation['prompt'] ?? '' ); ?></textarea>
						<div class="sflmcp-test-chat-actions">
							<span class="sflmcp-prompt-vars">
								<code>{{post_title}}</code> <code>{{post_content}}</code> <code>{{order_id}}</code>
							</span>
							<button type="button" id="sflmcp-test-prompt" class="button button-primary">
								<span class="dashicons dashicons-controls-play"></span>
								<?php esc_html_e( 'Test Prompt', 'stifli-flex-mcp' ); ?>
							</button>
						</div>
					</div>
				</div>

				<div class="sflmcp-form-row sflmcp-form-row-spaced">
					<label for="system_prompt"><?php esc_html_e( 'System Prompt (optional)', 'stifli-flex-mcp' ); ?></label>
					<textarea id="system_prompt" name="system_prompt" rows="3"
							  placeholder="<?php esc_attr_e( 'Custom instructions for the AI. Leave empty to use default.', 'stifli-flex-mcp' ); ?>"><?php echo esc_textarea( $automation['system_prompt'] ?? '' ); ?></textarea>
				</div>

				<!-- Tools detection summary (shown after test) -->
				<div id="sflmcp-test-summary" class="sflmcp-test-summary sflmcp-is-hidden">
					<div class="sflmcp-test-summary-header">
						<span class="dashicons dashicons-admin-tools"></span>
						<strong><?php esc_html_e( 'Tools Detected', 'stifli-flex-mcp' ); ?></strong>
					</div>
					<div id="sflmcp-detected-tools-list" class="sflmcp-detected-tools-list"></div>
					<div id="sflmcp-test-suggestion" class="sflmcp-test-suggestion"></div>
				</div>
			</div>

			<!-- Step 4: Tools Selection -->
			<div class="sflmcp-form-section">
				<h2 class="sflmcp-section-header-inline">
					<span class="sflmcp-step-number">4</span>
					<?php esc_html_e( 'Tools Selection', 'stifli-flex-mcp' ); ?>
					<span class="sflmcp-header-meta">
						<?php /* translators: %d: number of enabled tools */ ?>
						<span class="sflmcp-tools-count" id="sflmcp-tools-count-display"><?php echo esc_html( sprintf( __( '%d tools enabled', 'stifli-flex-mcp' ), $enabled_tools_count ) ); ?></span>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=sflmcp-server&tab=profiles' ) ); ?>" class="sflmcp-header-link"><?php esc_html_e( 'Manage Tools', 'stifli-flex-mcp' ); ?></a>
					</span>
				</h2>

				<div class="sflmcp-form-row">
					<label><?php esc_html_e( 'Tools Mode', 'stifli-flex-mcp' ); ?></label>
					<div class="sflmcp-radio-group">
						<label class="sflmcp-radio-option">
							<input type="radio" name="tools_mode" value="profile" <?php checked( empty( $automation['tools_enabled'] ) || $automation['tools_enabled'] === '[]' ); ?>>
							<span class="sflmcp-radio-label">
								<strong><?php esc_html_e( 'Use Active Profile', 'stifli-flex-mcp' ); ?></strong>
								<span><?php esc_html_e( 'Use tools from active MCP profile', 'stifli-flex-mcp' ); ?></span>
							</span>
						</label>
						<label class="sflmcp-radio-option">
							<input type="radio" name="tools_mode" value="detected">
							<span class="sflmcp-radio-label">
								<strong><?php esc_html_e( 'Detected Tools Only', 'stifli-flex-mcp' ); ?></strong>
								<span><?php esc_html_e( 'Only tools detected during test - saves tokens significantly (recommended)', 'stifli-flex-mcp' ); ?></span>
							</span>
						</label>
						<label class="sflmcp-radio-option">
							<input type="radio" name="tools_mode" value="custom" <?php checked( ! empty( $automation['tools_enabled'] ) && $automation['tools_enabled'] !== '[]' ); ?>>
							<span class="sflmcp-radio-label">
								<strong><?php esc_html_e( 'Custom Selection', 'stifli-flex-mcp' ); ?></strong>
								<span><?php esc_html_e( 'Manually select tools - saves tokens vs full profile', 'stifli-flex-mcp' ); ?></span>
							</span>
						</label>
					</div>
				</div>

				<div id="sflmcp-custom-tools-section" class="sflmcp-form-row sflmcp-is-hidden">
					<label><?php esc_html_e( 'Select Tools', 'stifli-flex-mcp' ); ?></label>
					<div class="sflmcp-tools-selector">
						<div class="sflmcp-tools-header">
							<input type="text" id="sflmcp-tools-search" placeholder="<?php esc_attr_e( 'Search tools...', 'stifli-flex-mcp' ); ?>">
							<label class="sflmcp-show-all-tools">
								<input type="checkbox" id="sflmcp-show-all-tools">
								<?php esc_html_e( 'Show all tools (including disabled)', 'stifli-flex-mcp' ); ?>
							</label>
						</div>
						<div id="sflmcp-tools-list" class="sflmcp-tools-list"></div>
					</div>
					<input type="hidden" id="sflmcp-allowed-tools" name="allowed_tools" value="<?php echo esc_attr( $automation['tools_enabled'] ?? '' ); ?>">
					<input type="hidden" id="sflmcp-detected-tools" name="detected_tools" value="">
					<input type="hidden" id="sflmcp-tools-mode" name="tools_mode_hidden" value="profile">
				</div>
				<input type="hidden" name="tools_enabled" id="tools-enabled-json" value="<?php echo esc_attr( $automation['tools_enabled'] ?? '[]' ); ?>">
			</div>

			<!-- Step 5: Output Actions -->
			<div class="sflmcp-form-section">
				<h2>
					<span class="sflmcp-step-number">5</span>
					<?php esc_html_e( 'Output Actions', 'stifli-flex-mcp' ); ?>
				</h2>

				<div class="sflmcp-form-row">
					<label><?php esc_html_e( 'What to do with the result?', 'stifli-flex-mcp' ); ?></label>
					<div class="sflmcp-checkbox-group">
						<label class="sflmcp-checkbox-option">
							<input type="checkbox" name="output_log" checked disabled>
							<span><?php esc_html_e( 'Save to execution log (always)', 'stifli-flex-mcp' ); ?></span>
						</label>
						<label class="sflmcp-checkbox-option">
							<input type="checkbox" id="sflmcp-output-email" name="output_email" value="1" <?php checked( ! empty( $automation['output_email'] ) ); ?>>
							<span><?php esc_html_e( 'Send email notification', 'stifli-flex-mcp' ); ?></span>
						</label>
						<label class="sflmcp-checkbox-option">
							<input type="checkbox" id="sflmcp-output-webhook" name="output_webhook" value="1" <?php checked( ! empty( $automation['output_webhook'] ) ); ?>>
							<span><?php esc_html_e( 'Send to Webhook (Slack, etc.)', 'stifli-flex-mcp' ); ?></span>
						</label>
						<label class="sflmcp-checkbox-option">
							<input type="checkbox" id="sflmcp-output-draft" name="output_draft" value="1" <?php checked( ! empty( $automation['output_draft'] ) ); ?>>
							<span><?php esc_html_e( 'Create draft post', 'stifli-flex-mcp' ); ?></span>
						</label>
					</div>
				</div>

				<!-- Email Config -->
				<div id="sflmcp-email-config" class="sflmcp-output-config<?php echo empty( $automation['output_email'] ) ? ' sflmcp-is-hidden' : ''; ?>">
					<div class="sflmcp-form-row">
						<label for="sflmcp-email-recipients"><?php esc_html_e( 'Recipients', 'stifli-flex-mcp' ); ?></label>
						<input type="text" id="sflmcp-email-recipients" name="email_recipients" 
							   value="<?php echo esc_attr( $automation['email_recipients'] ?? get_option( 'admin_email' ) ); ?>"
							   placeholder="email@example.com, another@example.com">
					</div>
					<div class="sflmcp-form-row">
						<label for="sflmcp-email-subject"><?php esc_html_e( 'Subject', 'stifli-flex-mcp' ); ?></label>
						<input type="text" id="sflmcp-email-subject" name="email_subject" 
							   value="<?php echo esc_attr( $automation['email_subject'] ?? '[{site_name}] Event: {automation_name} - {date}' ); ?>">
					</div>
				</div>

				<!-- Webhook Config -->
				<div id="sflmcp-webhook-config" class="sflmcp-output-config<?php echo empty( $automation['output_webhook'] ) ? ' sflmcp-is-hidden' : ''; ?>">
					<div class="sflmcp-form-row">
						<label><?php esc_html_e( 'Webhook Preset', 'stifli-flex-mcp' ); ?></label>
						<select id="sflmcp-webhook-preset" name="webhook_preset">
							<option value="custom" <?php selected( $automation['webhook_preset'] ?? '', 'custom' ); ?>><?php esc_html_e( 'Custom', 'stifli-flex-mcp' ); ?></option>
							<option value="slack" <?php selected( $automation['webhook_preset'] ?? '', 'slack' ); ?>><?php esc_html_e( 'Slack', 'stifli-flex-mcp' ); ?></option>
							<option value="discord" <?php selected( $automation['webhook_preset'] ?? '', 'discord' ); ?>><?php esc_html_e( 'Discord', 'stifli-flex-mcp' ); ?></option>
							<option value="telegram" <?php selected( $automation['webhook_preset'] ?? '', 'telegram' ); ?>><?php esc_html_e( 'Telegram', 'stifli-flex-mcp' ); ?></option>
						</select>
					</div>
					<div class="sflmcp-form-row">
						<label for="sflmcp-webhook-url"><?php esc_html_e( 'Webhook URL', 'stifli-flex-mcp' ); ?></label>
						<input type="url" id="sflmcp-webhook-url" name="webhook_url" 
							   value="<?php echo esc_attr( $automation['webhook_url'] ?? '' ); ?>"
							   placeholder="https://...">
					</div>
				</div>

				<!-- Draft Config -->
				<div id="sflmcp-draft-config" class="sflmcp-output-config<?php echo empty( $automation['output_draft'] ) ? ' sflmcp-is-hidden' : ''; ?>">
					<div class="sflmcp-form-row">
						<label for="sflmcp-draft-post-type"><?php esc_html_e( 'Post Type', 'stifli-flex-mcp' ); ?></label>
						<select id="sflmcp-draft-post-type" name="draft_post_type">
							<?php
							$post_types = get_post_types( array( 'public' => true ), 'objects' );
							$selected_type = $automation['draft_post_type'] ?? 'post';
							foreach ( $post_types as $post_type ) {
								printf(
									'<option value="%s" %s>%s</option>',
									esc_attr( $post_type->name ),
									selected( $selected_type, $post_type->name, false ),
									esc_html( $post_type->label )
								);
							}
							?>
						</select>
					</div>
				</div>
			</div>

			<!-- Hidden fields for provider/model (uses AI Chat Agent settings) -->
			<input type="hidden" id="provider" name="provider" value="">
			<input type="hidden" id="model" name="model" value="">
			<input type="hidden" id="max_tokens" name="max_tokens" value="2000">

			<!-- Form Actions -->
			<div class="sflmcp-form-actions">
				<button type="button" id="sflmcp-save-draft" class="button">
					<?php esc_html_e( 'Save as Draft', 'stifli-flex-mcp' ); ?>
				</button>
				<button type="submit" id="sflmcp-save-active" class="button button-primary">
					<span class="dashicons dashicons-controls-play"></span>
					<?php echo $is_edit ? esc_html__( 'Update & Activate', 'stifli-flex-mcp' ) : esc_html__( 'Create & Activate', 'stifli-flex-mcp' ); ?>
				</button>
			</div>
		</form>

		<!-- Test Modal (kept for backward compatibility but using chat style now) -->
		<div id="sflmcp-task-modal" class="sflmcp-modal sflmcp-is-hidden">
			<div class="sflmcp-modal-content">
				<div class="sflmcp-modal-header">
					<h3 id="sflmcp-modal-title"></h3>
					<button type="button" class="sflmcp-modal-close">&times;</button>
				</div>
				<div class="sflmcp-modal-body" id="sflmcp-modal-body"></div>
				<div class="sflmcp-modal-footer" id="sflmcp-modal-footer"></div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render logs tab
	 */
	private function render_logs_tab() {
		?>
		<div class="sflmcp-tasks-header">
			<div class="sflmcp-tasks-filters">
				<select id="sflmcp-log-filter-automation">
					<option value=""><?php esc_html_e( 'All Automations', 'stifli-flex-mcp' ); ?></option>
				</select>
				<select id="sflmcp-log-filter-status">
					<option value=""><?php esc_html_e( 'All Status', 'stifli-flex-mcp' ); ?></option>
					<option value="success"><?php esc_html_e( 'Success', 'stifli-flex-mcp' ); ?></option>
					<option value="error"><?php esc_html_e( 'Error', 'stifli-flex-mcp' ); ?></option>
					<option value="skipped"><?php esc_html_e( 'Skipped', 'stifli-flex-mcp' ); ?></option>
				</select>
				<button type="button" class="button" id="sflmcp-refresh-logs">
					<span class="dashicons dashicons-update"></span>
					<?php esc_html_e( 'Refresh', 'stifli-flex-mcp' ); ?>
				</button>
			</div>
		</div>

		<div id="sflmcp-logs-list" class="sflmcp-tasks-list">
			<div class="sflmcp-loading">
				<span class="spinner is-active"></span>
				<?php esc_html_e( 'Loading logs...', 'stifli-flex-mcp' ); ?>
			</div>
		</div>

		<!-- Log Detail Modal -->
		<div id="sflmcp-task-modal" class="sflmcp-modal sflmcp-is-hidden">
			<div class="sflmcp-modal-content">
				<div class="sflmcp-modal-header">
					<h3 id="sflmcp-modal-title"><?php esc_html_e( 'Log Details', 'stifli-flex-mcp' ); ?></h3>
					<button type="button" class="sflmcp-modal-close">&times;</button>
				</div>
				<div class="sflmcp-modal-body" id="sflmcp-modal-body"></div>
			</div>
		</div>
		<?php
	}

	/**
	 * AJAX: Get automations list
	 */
	public function ajax_get_automations() {
		check_ajax_referer( 'sflmcp_events_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied', 'stifli-flex-mcp' ) ) );
		}

		global $wpdb;
		$table = $wpdb->prefix . 'sflmcp_event_automations';
		
		$where = array( '1=1' );
		
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$trigger_id = isset( $_POST['trigger_id'] ) ? sanitize_text_field( wp_unslash( $_POST['trigger_id'] ) ) : '';
		if ( ! empty( $trigger_id ) ) {
			$where[] = $wpdb->prepare( 'trigger_id = %s', $trigger_id );
		}
		
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$status = isset( $_POST['status'] ) ? sanitize_text_field( wp_unslash( $_POST['status'] ) ) : '';
		if ( ! empty( $status ) ) {
			$where[] = $wpdb->prepare( 'status = %s', $status );
		}

		$where_sql = implode( ' AND ', $where );
		
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name from $wpdb->prefix is safe; dynamic WHERE built from prepare()d clauses.
		$automations = $wpdb->get_results( "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY created_at DESC", ARRAY_A );

		// Get trigger names
		$registry = StifliFlexMcp_Event_Trigger_Registry::get_instance();
		foreach ( $automations as &$auto ) {
			$trigger = $registry->get( $auto['trigger_id'] );
			$auto['trigger_name'] = $trigger ? $trigger['trigger_name'] : $auto['trigger_id'];
		}

		wp_send_json_success( array( 'automations' => $automations ) );
	}

	/**
	 * AJAX: Get single automation
	 */
	public function ajax_get_automation() {
		check_ajax_referer( 'sflmcp_events_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied', 'stifli-flex-mcp' ) ) );
		}

		$id = isset( $_POST['id'] ) ? intval( $_POST['id'] ) : 0;
		if ( ! $id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid ID', 'stifli-flex-mcp' ) ) );
		}

		global $wpdb;
		$table = $wpdb->prefix . 'sflmcp_event_automations';
		
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name from $wpdb->prefix is safe.
			$automation = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );

		if ( ! $automation ) {
			wp_send_json_error( array( 'message' => __( 'Automation not found', 'stifli-flex-mcp' ) ) );
		}

		wp_send_json_success( array( 'automation' => $automation ) );
	}

	/**
	 * AJAX: Save automation
	 */
	public function ajax_save_automation() {
		check_ajax_referer( 'sflmcp_events_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied', 'stifli-flex-mcp' ) ) );
		}

		$id              = isset( $_POST['id'] ) ? intval( $_POST['id'] ) : 0;
		$automation_name = isset( $_POST['automation_name'] ) ? sanitize_text_field( wp_unslash( $_POST['automation_name'] ) ) : '';
		$trigger_id      = isset( $_POST['trigger_id'] ) ? sanitize_text_field( wp_unslash( $_POST['trigger_id'] ) ) : '';
		$conditions      = isset( $_POST['conditions'] ) ? sanitize_text_field( wp_unslash( $_POST['conditions'] ) ) : '[]';
		$prompt          = isset( $_POST['prompt'] ) ? sanitize_textarea_field( wp_unslash( $_POST['prompt'] ) ) : '';
		$system_prompt   = isset( $_POST['system_prompt'] ) ? sanitize_textarea_field( wp_unslash( $_POST['system_prompt'] ) ) : '';
		$tools_enabled   = isset( $_POST['tools_enabled'] ) ? sanitize_text_field( wp_unslash( $_POST['tools_enabled'] ) ) : '[]';
		$provider        = isset( $_POST['provider'] ) ? sanitize_text_field( wp_unslash( $_POST['provider'] ) ) : '';
		$model           = isset( $_POST['model'] ) ? sanitize_text_field( wp_unslash( $_POST['model'] ) ) : '';
		$max_tokens      = isset( $_POST['max_tokens'] ) ? intval( $_POST['max_tokens'] ) : 2000;
		$status          = isset( $_POST['status'] ) ? sanitize_text_field( wp_unslash( $_POST['status'] ) ) : 'draft';

		// Output action fields
		$output_email     = isset( $_POST['output_email'] ) && $_POST['output_email'] === '1' ? 1 : 0;
		$email_recipients = isset( $_POST['email_recipients'] ) ? sanitize_text_field( wp_unslash( $_POST['email_recipients'] ) ) : '';
		$email_subject    = isset( $_POST['email_subject'] ) ? sanitize_text_field( wp_unslash( $_POST['email_subject'] ) ) : '';
		$output_webhook   = isset( $_POST['output_webhook'] ) && $_POST['output_webhook'] === '1' ? 1 : 0;
		$webhook_url      = isset( $_POST['webhook_url'] ) ? esc_url_raw( wp_unslash( $_POST['webhook_url'] ) ) : '';
		$webhook_preset   = isset( $_POST['webhook_preset'] ) ? sanitize_text_field( wp_unslash( $_POST['webhook_preset'] ) ) : 'custom';
		$output_draft     = isset( $_POST['output_draft'] ) && $_POST['output_draft'] === '1' ? 1 : 0;
		$draft_post_type  = isset( $_POST['draft_post_type'] ) ? sanitize_text_field( wp_unslash( $_POST['draft_post_type'] ) ) : 'post';

		if ( empty( $automation_name ) || empty( $trigger_id ) || empty( $prompt ) ) {
			wp_send_json_error( array( 'message' => __( 'Name, trigger, and prompt are required', 'stifli-flex-mcp' ) ) );
		}

		global $wpdb;
		$table = $wpdb->prefix . 'sflmcp_event_automations';

		$data = array(
			'automation_name'  => $automation_name,
			'trigger_id'       => $trigger_id,
			'conditions'       => $conditions,
			'prompt'           => $prompt,
			'system_prompt'    => $system_prompt,
			'tools_enabled'    => $tools_enabled,
			'provider'         => $provider ?: null,
			'model'            => $model ?: null,
			'max_tokens'       => $max_tokens,
			'output_email'     => $output_email,
			'email_recipients' => $email_recipients ?: null,
			'email_subject'    => $email_subject ?: null,
			'output_webhook'   => $output_webhook,
			'webhook_url'      => $webhook_url ?: null,
			'webhook_preset'   => $webhook_preset,
			'output_draft'     => $output_draft,
			'draft_post_type'  => $draft_post_type,
			'status'           => $status,
		);

		if ( $id > 0 ) {
			// Update
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$result = $wpdb->update( $table, $data, array( 'id' => $id ) );
		} else {
			// Insert
			$data['created_by'] = get_current_user_id();
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$result = $wpdb->insert( $table, $data );
			$id = $wpdb->insert_id;
		}

		if ( false === $result ) {
			wp_send_json_error( array( 'message' => __( 'Failed to save', 'stifli-flex-mcp' ) ) );
		}

		wp_send_json_success( array( 
			'message' => __( 'Saved successfully', 'stifli-flex-mcp' ),
			'id'      => $id,
		) );
	}

	/**
	 * AJAX: Delete automation
	 */
	public function ajax_delete_automation() {
		check_ajax_referer( 'sflmcp_events_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied', 'stifli-flex-mcp' ) ) );
		}

		$id = isset( $_POST['id'] ) ? intval( $_POST['id'] ) : 0;
		if ( ! $id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid ID', 'stifli-flex-mcp' ) ) );
		}

		global $wpdb;
		$table = $wpdb->prefix . 'sflmcp_event_automations';
		
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) );

		if ( ! $result ) {
			wp_send_json_error( array( 'message' => __( 'Failed to delete', 'stifli-flex-mcp' ) ) );
		}

		wp_send_json_success( array( 'message' => __( 'Deleted successfully', 'stifli-flex-mcp' ) ) );
	}

	/**
	 * AJAX: Toggle automation status
	 */
	public function ajax_toggle_status() {
		check_ajax_referer( 'sflmcp_events_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied', 'stifli-flex-mcp' ) ) );
		}

		$id     = isset( $_POST['id'] ) ? intval( $_POST['id'] ) : 0;
		$status = isset( $_POST['status'] ) ? sanitize_text_field( wp_unslash( $_POST['status'] ) ) : '';

		if ( ! $id || ! in_array( $status, array( 'active', 'paused' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid parameters', 'stifli-flex-mcp' ) ) );
		}

		global $wpdb;
		$table = $wpdb->prefix . 'sflmcp_event_automations';
		
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->update( $table, array( 'status' => $status ), array( 'id' => $id ) );

		if ( false === $result ) {
			wp_send_json_error( array( 'message' => __( 'Failed to update status', 'stifli-flex-mcp' ) ) );
		}

		wp_send_json_success( array( 'message' => __( 'Status updated', 'stifli-flex-mcp' ) ) );
	}

	/**
	 * AJAX: Get triggers list
	 */
	public function ajax_get_triggers() {
		check_ajax_referer( 'sflmcp_events_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied', 'stifli-flex-mcp' ) ) );
		}

		$registry = StifliFlexMcp_Event_Trigger_Registry::get_instance();
		
		// Get ALL triggers (not just available ones) - each has 'available' flag
		$grouped = $registry->get_grouped( false );

		$response = array( 
			'triggers'   => $registry->get_available(),
			'grouped'    => $grouped,
			'categories' => $registry->get_categories(),
		);

		// Include tools if requested
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! empty( $_POST['include_tools'] ) ) {
			global $wpdb;
			$tools_table = $wpdb->prefix . 'sflmcp_tools';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name from $wpdb->prefix is safe.
			$tools = $wpdb->get_results( "SELECT tool_name, tool_description, category FROM {$tools_table} WHERE enabled = 1 ORDER BY category, tool_name", ARRAY_A );
			$response['tools'] = array_map( function( $t ) {
				return array(
					'name'        => $t['tool_name'],
					'description' => $t['tool_description'],
					'category'    => $t['category'],
				);
			}, $tools );
		}

		wp_send_json_success( $response );
	}

	/**
	 * AJAX: Get logs
	 */
	public function ajax_get_logs() {
		check_ajax_referer( 'sflmcp_events_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied', 'stifli-flex-mcp' ) ) );
		}

		global $wpdb;
		$logs_table = $wpdb->prefix . 'sflmcp_event_logs';
		$auto_table = $wpdb->prefix . 'sflmcp_event_automations';
		
		$where = array( '1=1' );
		
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$automation_id = isset( $_POST['automation_id'] ) ? intval( $_POST['automation_id'] ) : 0;
		if ( $automation_id > 0 ) {
			$where[] = $wpdb->prepare( 'l.automation_id = %d', $automation_id );
		}
		
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$status = isset( $_POST['status'] ) ? sanitize_text_field( wp_unslash( $_POST['status'] ) ) : '';
		if ( ! empty( $status ) ) {
			$where[] = $wpdb->prepare( 'l.status = %s', $status );
		}

		$where_sql = implode( ' AND ', $where );
		
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table names from $wpdb->prefix are safe; dynamic WHERE built from prepare()d clauses.
		$logs = $wpdb->get_results( 
			"SELECT l.*, a.automation_name 
			 FROM {$logs_table} l 
			 LEFT JOIN {$auto_table} a ON l.automation_id = a.id 
			 WHERE {$where_sql} 
			 ORDER BY l.created_at DESC 
			 LIMIT 100", 
			ARRAY_A 
		);

		// Get automations for filter dropdown
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name from $wpdb->prefix is safe.
		$automations = $wpdb->get_results( "SELECT id, automation_name FROM {$auto_table} ORDER BY automation_name", ARRAY_A );

		wp_send_json_success( array( 
			'logs'        => $logs,
			'automations' => $automations,
		) );
	}

	/**
	 * AJAX: Test automation with sample payload
	 */
	public function ajax_test_automation() {
		stifli_flex_mcp_log( '[SFLMCP Events Test] Starting test automation' );

		check_ajax_referer( 'sflmcp_events_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			stifli_flex_mcp_log( '[SFLMCP Events Test] Permission denied' );
			wp_send_json_error( array( 'message' => __( 'Permission denied', 'stifli-flex-mcp' ) ) );
		}

		$trigger_id    = isset( $_POST['trigger_id'] ) ? sanitize_text_field( wp_unslash( $_POST['trigger_id'] ) ) : '';
		$prompt        = isset( $_POST['prompt'] ) ? sanitize_textarea_field( wp_unslash( $_POST['prompt'] ) ) : '';
		$system_prompt = isset( $_POST['system_prompt'] ) ? sanitize_textarea_field( wp_unslash( $_POST['system_prompt'] ) ) : '';
		$tools_enabled = isset( $_POST['tools_enabled'] ) ? sanitize_text_field( wp_unslash( $_POST['tools_enabled'] ) ) : '[]';
		$payload_json  = isset( $_POST['payload'] ) ? sanitize_text_field( wp_unslash( $_POST['payload'] ) ) : '{}';

		stifli_flex_mcp_log( '[SFLMCP Events Test] Trigger: ' . $trigger_id );
		stifli_flex_mcp_log( '[SFLMCP Events Test] Prompt length: ' . strlen( $prompt ) );
		stifli_flex_mcp_log( '[SFLMCP Events Test] Payload: ' . $payload_json );

		if ( empty( $trigger_id ) || empty( $prompt ) ) {
			stifli_flex_mcp_log( '[SFLMCP Events Test] Missing trigger or prompt' );
			wp_send_json_error( array( 'message' => __( 'Trigger and prompt are required', 'stifli-flex-mcp' ) ) );
		}

		$payload = json_decode( $payload_json, true );
		if ( ! is_array( $payload ) ) {
			$payload = array();
		}

		// Resolve placeholders
		$resolved_prompt = preg_replace_callback(
			'/\{\{(\w+)\}\}/',
			function( $matches ) use ( $payload ) {
				$key = $matches[1];
				return isset( $payload[ $key ] ) ? (string) $payload[ $key ] : $matches[0];
			},
			$prompt
		);

		stifli_flex_mcp_log( '[SFLMCP Events Test] Resolved prompt: ' . substr( $resolved_prompt, 0, 200 ) . '...' );

		// Create task object for execution
		$task = (object) array(
			'id'            => 0,
			'prompt'        => $resolved_prompt,
			'system_prompt' => $system_prompt,
			'tools_enabled' => $tools_enabled,
			'provider'      => null,
			'model'         => null,
			'max_tokens'    => 2000,
		);

		// Execute
		try {
			require_once __DIR__ . '/class-automation-engine.php';
			$engine = StifliFlexMcp_Automation_Engine::get_instance();

			stifli_flex_mcp_log( '[SFLMCP Events Test] Calling execute_task_internal' );

			$result = $engine->execute_task_internal( $task );

			stifli_flex_mcp_log( '[SFLMCP Events Test] Execution completed' );
			stifli_flex_mcp_log( '[SFLMCP Events Test] Response length: ' . strlen( $result['response'] ?? '' ) );
			stifli_flex_mcp_log( '[SFLMCP Events Test] Tools executed: ' . count( $result['tools_executed'] ?? array() ) );
			if ( ! empty( $result['error'] ) ) {
				stifli_flex_mcp_log( '[SFLMCP Events Test] Error: ' . $result['error'] );
			}

			wp_send_json_success( array(
				'resolved_prompt' => $resolved_prompt,
				'response'        => $result['response'] ?? '',
				'tools_executed'  => $result['tools_executed'] ?? array(),
				'tokens_used'     => $result['tokens_used'] ?? 0,
				'error'           => $result['error'] ?? null,
			) );
		} catch ( \Exception $e ) {
			stifli_flex_mcp_log( '[SFLMCP Events Test] Exception: ' . $e->getMessage() );
			stifli_flex_mcp_log( '[SFLMCP Events Test] Stack trace: ' . $e->getTraceAsString() );
			wp_send_json_error( array( 'message' => 'Execution error: ' . $e->getMessage() ) );
		}
	}
}
