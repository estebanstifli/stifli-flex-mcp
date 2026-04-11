<?php
/**
 * Automation Admin - Dashboard for managing automation tasks
 *
 * @package StifliFlexMcp
 * @since 2.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class StifliFlexMcp_Automation_Admin
 * 
 * Handles the admin interface for automation tasks
 */
class StifliFlexMcp_Automation_Admin {

	/**
	 * Engine instance
	 *
	 * @var StifliFlexMcp_Automation_Engine
	 */
	private $engine;

	/**
	 * Constructor
	 */
	public function __construct() {
		require_once __DIR__ . '/class-automation-engine.php';
		$this->engine = StifliFlexMcp_Automation_Engine::get_instance();

		add_action( 'admin_menu', array( $this, 'add_submenu_page' ), 20 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		// AJAX handlers
		add_action( 'wp_ajax_sflmcp_automation_get_tasks', array( $this, 'ajax_get_tasks' ) );
		add_action( 'wp_ajax_sflmcp_automation_save_task', array( $this, 'ajax_save_task' ) );
		add_action( 'wp_ajax_sflmcp_automation_delete_task', array( $this, 'ajax_delete_task' ) );
		add_action( 'wp_ajax_sflmcp_automation_toggle_task', array( $this, 'ajax_toggle_task' ) );
		add_action( 'wp_ajax_sflmcp_automation_run_task', array( $this, 'ajax_run_task' ) );
		add_action( 'wp_ajax_sflmcp_automation_test_prompt', array( $this, 'ajax_test_prompt' ) );
		add_action( 'wp_ajax_sflmcp_automation_test_start', array( $this, 'ajax_test_start' ) );
		add_action( 'wp_ajax_sflmcp_automation_test_step', array( $this, 'ajax_test_step' ) );
		add_action( 'wp_ajax_sflmcp_automation_get_logs', array( $this, 'ajax_get_logs' ) );
		add_action( 'wp_ajax_sflmcp_automation_get_templates', array( $this, 'ajax_get_templates' ) );
	}

	/**
	 * Add submenu page
	 */
	public function add_submenu_page() {
		add_submenu_page(
			'stifli-flex-mcp',
			__( 'Automation Tasks', 'stifli-flex-mcp' ),
			__( 'Automation Tasks', 'stifli-flex-mcp' ),
			'manage_options',
			'sflmcp-automation',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Enqueue assets
	 *
	 * @param string $hook Current admin page.
	 */
	public function enqueue_assets( $hook ) {
		if ( 'stifli-flex-mcp_page_sflmcp-automation' !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'sflmcp-automation',
			plugin_dir_url( __FILE__ ) . 'assets/automation.css',
			array(),
			'2.1.0'
		);

		wp_enqueue_script(
			'sflmcp-automation',
			plugin_dir_url( __FILE__ ) . 'assets/automation.js',
			array( 'jquery' ),
			'2.1.0',
			true
		);

		// Get tools for dropdown
		global $stifliFlexMcp;
		$tools = array();
		$all_tools_list = array();
		if ( isset( $stifliFlexMcp->model ) ) {
			// Enabled tools only
			$enabled_tools = $stifliFlexMcp->model->getToolsList();
			foreach ( $enabled_tools as $tool ) {
				$tools[] = array(
					'name'        => $tool['name'],
					'description' => $tool['description'] ?? '',
				);
			}
			// All tools (including disabled)
			$full_tools = $stifliFlexMcp->model->getTools();
			foreach ( $full_tools as $tool ) {
				$all_tools_list[] = array(
					'name'        => $tool['name'],
					'description' => $tool['description'] ?? '',
				);
			}
		}

		wp_localize_script( 'sflmcp-automation', 'sflmcpAutomation', array(
			'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
			'nonce'     => wp_create_nonce( 'sflmcp_automation' ),
			'presets'   => StifliFlexMcp_Automation_Engine::get_schedule_presets(),
			'tools'     => $tools,
			'allTools'  => $all_tools_list,
			'templates' => $this->get_default_templates(),
			'i18n'      => array(
				'confirmDelete'   => __( 'Are you sure you want to delete this task?', 'stifli-flex-mcp' ),
				'taskSaved'       => __( 'Task saved successfully', 'stifli-flex-mcp' ),
				'taskDeleted'     => __( 'Task deleted', 'stifli-flex-mcp' ),
				'taskRunning'     => __( 'Running task...', 'stifli-flex-mcp' ),
				'taskCompleted'   => __( 'Task completed', 'stifli-flex-mcp' ),
				'taskFailed'      => __( 'Task failed', 'stifli-flex-mcp' ),
				'testing'         => __( 'Testing prompt...', 'stifli-flex-mcp' ),
				'testCompleted'   => __( 'Test completed', 'stifli-flex-mcp' ),
				'noTasks'         => __( 'No automation tasks yet. Create your first task!', 'stifli-flex-mcp' ),
				'saveTools'       => __( 'Save these tools', 'stifli-flex-mcp' ),
				'active'          => __( 'Active', 'stifli-flex-mcp' ),
				'paused'          => __( 'Paused', 'stifli-flex-mcp' ),
				'error'           => __( 'Error', 'stifli-flex-mcp' ),
				'draft'           => __( 'Draft', 'stifli-flex-mcp' ),
				'moreTemplates'   => __( 'More templates...', 'stifli-flex-mcp' ),
			),
		) );
	}

	/**
	 * Render the admin page
	 */
	public function render_page() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Tab selection for display only
		$active_tab = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : 'tasks';
		?>
		<div class="wrap sflmcp-automation-wrap">
			<h1>
				<span class="dashicons dashicons-clock"></span>
				<?php esc_html_e( 'Automation Tasks', 'stifli-flex-mcp' ); ?>
			</h1>

			<nav class="nav-tab-wrapper sflmcp-automation-tabs">
				<a href="<?php echo esc_url( add_query_arg( 'tab', 'tasks' ) ); ?>" 
				   class="nav-tab <?php echo 'tasks' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<span class="dashicons dashicons-list-view"></span>
					<?php esc_html_e( 'Tasks', 'stifli-flex-mcp' ); ?>
				</a>
				<a href="<?php echo esc_url( add_query_arg( 'tab', 'create' ) ); ?>" 
				   class="nav-tab <?php echo 'create' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<span class="dashicons dashicons-plus-alt"></span>
					<?php esc_html_e( 'Create Task', 'stifli-flex-mcp' ); ?>
				</a>
				<a href="<?php echo esc_url( add_query_arg( 'tab', 'logs' ) ); ?>" 
				   class="nav-tab <?php echo 'logs' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<span class="dashicons dashicons-backup"></span>
					<?php esc_html_e( 'Execution Logs', 'stifli-flex-mcp' ); ?>
				</a>
				<a href="<?php echo esc_url( add_query_arg( 'tab', 'templates' ) ); ?>" 
				   class="nav-tab <?php echo 'templates' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<span class="dashicons dashicons-category"></span>
					<?php esc_html_e( 'Templates', 'stifli-flex-mcp' ); ?>
				</a>
			</nav>

			<div class="sflmcp-automation-content">
				<?php
				switch ( $active_tab ) {
					case 'create':
						$this->render_create_tab();
						break;
					case 'logs':
						$this->render_logs_tab();
						break;
					case 'templates':
						$this->render_templates_tab();
						break;
					default:
						$this->render_tasks_tab();
						break;
				}
				?>
			</div>

			<!-- WP-Cron Notice -->
			<div class="sflmcp-cron-notice" style="margin-top: 24px; padding: 12px 16px; background: #fff8e5; border: 1px solid #dba617; border-left-width: 4px; border-radius: 4px;">
				<p style="margin: 0; color: #996800;">
					<span class="dashicons dashicons-info" style="color: #dba617; margin-right: 6px;"></span>
					<strong><?php esc_html_e( 'Important:', 'stifli-flex-mcp' ); ?></strong>
					<?php esc_html_e( 'WordPress WP-Cron only runs when there are visits. For low-traffic sites, we recommend setting up a real server cron:', 'stifli-flex-mcp' ); ?>
				</p>
				<code style="display: block; margin-top: 8px; padding: 8px 12px; background: #f6f7f7; border-radius: 3px; font-size: 12px; color: #50575e; word-break: break-all;">* * * * * wget -q -O - <?php echo esc_url( site_url( '/wp-cron.php?doing_wp_cron' ) ); ?> > /dev/null 2>&1</code>
			</div>
		</div>
		<?php
	}

	/**
	 * Render tasks list tab
	 */
	private function render_tasks_tab() {
		?>
		<div class="sflmcp-tasks-header">
			<div class="sflmcp-tasks-filters">
				<select id="sflmcp-task-filter-status">
					<option value=""><?php esc_html_e( 'All Status', 'stifli-flex-mcp' ); ?></option>
					<option value="active"><?php esc_html_e( 'Active', 'stifli-flex-mcp' ); ?></option>
					<option value="paused"><?php esc_html_e( 'Paused', 'stifli-flex-mcp' ); ?></option>
					<option value="error"><?php esc_html_e( 'Error', 'stifli-flex-mcp' ); ?></option>
					<option value="draft"><?php esc_html_e( 'Draft', 'stifli-flex-mcp' ); ?></option>
				</select>
				<button type="button" class="button" id="sflmcp-refresh-tasks">
					<span class="dashicons dashicons-update"></span>
					<?php esc_html_e( 'Refresh', 'stifli-flex-mcp' ); ?>
				</button>
			</div>
			<a href="<?php echo esc_url( add_query_arg( 'tab', 'create' ) ); ?>" class="button button-primary">
				<span class="dashicons dashicons-plus-alt"></span>
				<?php esc_html_e( 'New Task', 'stifli-flex-mcp' ); ?>
			</a>
		</div>

		<div id="sflmcp-tasks-list" class="sflmcp-tasks-list">
			<div class="sflmcp-loading">
				<span class="spinner is-active"></span>
				<?php esc_html_e( 'Loading tasks...', 'stifli-flex-mcp' ); ?>
			</div>
		</div>

		<!-- Task Actions Modal -->
		<div id="sflmcp-task-modal" class="sflmcp-modal" style="display:none;">
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
	 * Render create task tab
	 */
	private function render_create_tab() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Edit ID from URL
		$edit_id = isset( $_GET['edit'] ) ? intval( $_GET['edit'] ) : 0;
		$task = null;
		
		if ( $edit_id > 0 ) {
			global $wpdb;
			$table = $wpdb->prefix . 'sflmcp_automation_tasks';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name from $wpdb->prefix is safe.
			$task = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $edit_id ) );
		}
		?>
		<form id="sflmcp-task-form" class="sflmcp-task-form">
			<input type="hidden" id="sflmcp-task-id" value="<?php echo esc_attr( $task ? $task->id : '' ); ?>">
			
			<!-- Step 1: Basic Info -->
			<div class="sflmcp-form-section">
				<h2>
					<span class="sflmcp-step-number">1</span>
					<?php esc_html_e( 'Basic Information', 'stifli-flex-mcp' ); ?>
				</h2>
				
				<div class="sflmcp-form-row">
					<label for="sflmcp-task-name"><?php esc_html_e( 'Task Name', 'stifli-flex-mcp' ); ?> <span class="required">*</span></label>
					<input type="text" id="sflmcp-task-name" name="task_name" required 
						   value="<?php echo esc_attr( $task ? $task->task_name : '' ); ?>"
						   placeholder="<?php esc_attr_e( 'e.g., Daily Sales Report', 'stifli-flex-mcp' ); ?>">
				</div>

				<div class="sflmcp-form-row sflmcp-templates-quick">
					<label><?php esc_html_e( 'Quick Start from Template', 'stifli-flex-mcp' ); ?></label>
					<div class="sflmcp-template-buttons" id="sflmcp-quick-templates">
						<!-- Populated by JS -->
					</div>
				</div>
			</div>

			<!-- Step 2: AI Prompt & Test Chat -->
			<div class="sflmcp-form-section">
				<?php
				$settings = get_option( 'sflmcp_client_settings', array() );
				$provider = $settings['provider'] ?? 'openai';
				$model    = $settings['model'] ?? 'gpt-5.2-chat-latest';
				$has_key  = ! empty( $settings['api_key'] );
				?>
				<h2 class="sflmcp-section-header-inline">
					<span class="sflmcp-step-number">2</span>
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
						<button type="button" id="sflmcp-clear-test-chat" class="button-link" style="margin-left:auto;">
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
						<textarea id="sflmcp-task-prompt" name="prompt" rows="4" required
								  placeholder="<?php esc_attr_e( 'Describe what the AI should do...', 'stifli-flex-mcp' ); ?>"><?php echo esc_textarea( $task ? $task->prompt : '' ); ?></textarea>
						<div class="sflmcp-test-chat-actions">
							<span class="sflmcp-prompt-vars">
								<code>{site_name}</code> <code>{date}</code> <code>{datetime}</code> <code>{admin_email}</code> <code>{day_of_week}</code>
							</span>
							<button type="button" id="sflmcp-test-prompt" class="button button-primary">
								<span class="dashicons dashicons-controls-play"></span>
								<?php esc_html_e( 'Test Prompt', 'stifli-flex-mcp' ); ?>
							</button>
						</div>
					</div>
				</div>

				<div class="sflmcp-form-row" style="margin-top:16px;">
					<label for="sflmcp-task-system-prompt"><?php esc_html_e( 'System Prompt (optional)', 'stifli-flex-mcp' ); ?></label>
					<textarea id="sflmcp-task-system-prompt" name="system_prompt" rows="3"
							  placeholder="<?php esc_attr_e( 'Custom instructions for the AI...', 'stifli-flex-mcp' ); ?>"><?php echo esc_textarea( $task ? $task->system_prompt : '' ); ?></textarea>
				</div>

				<!-- Tools detection summary (shown after test) -->
				<div id="sflmcp-test-summary" class="sflmcp-test-summary" style="display:none;">
					<div class="sflmcp-test-summary-header">
						<span class="dashicons dashicons-admin-tools"></span>
						<strong><?php esc_html_e( 'Tools Detected', 'stifli-flex-mcp' ); ?></strong>
					</div>
					<div id="sflmcp-detected-tools-list" class="sflmcp-detected-tools-list"></div>
					<div id="sflmcp-test-suggestion" class="sflmcp-test-suggestion"></div>
				</div>
			</div>

			<!-- Step 3: Tools Selection -->
			<?php
			// Get enabled tools count
			global $stifliFlexMcp;
			$enabled_tools_count = 0;
			if ( isset( $stifliFlexMcp->model ) ) {
				$enabled_tools_count = count( $stifliFlexMcp->model->getToolsList() );
			}
			?>
			<div class="sflmcp-form-section">
				<h2 class="sflmcp-section-header-inline">
					<span class="sflmcp-step-number">3</span>
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
							<input type="radio" name="tools_mode" value="profile" checked>
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
							<input type="radio" name="tools_mode" value="custom">
							<span class="sflmcp-radio-label">
								<strong><?php esc_html_e( 'Custom Selection', 'stifli-flex-mcp' ); ?></strong>
								<span><?php esc_html_e( 'Manually select tools - saves tokens vs full profile', 'stifli-flex-mcp' ); ?></span>
							</span>
						</label>
					</div>
				</div>

				<div id="sflmcp-custom-tools-section" class="sflmcp-form-row" style="display:none;">
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
					<input type="hidden" id="sflmcp-allowed-tools" name="allowed_tools" value="<?php echo esc_attr( $task ? $task->allowed_tools : '' ); ?>">
					<input type="hidden" id="sflmcp-detected-tools" name="detected_tools" value="">
					<input type="hidden" id="sflmcp-tools-mode" name="tools_mode" value="profile">
				</div>
			</div>

			<!-- Step 4: Schedule -->
			<div class="sflmcp-form-section">
				<h2>
					<span class="sflmcp-step-number">4</span>
					<?php esc_html_e( 'Schedule', 'stifli-flex-mcp' ); ?>
					<span class="sflmcp-header-meta">
						<?php /* translators: %s: timezone string */ ?>
						<span class="sflmcp-timezone-info"><?php echo esc_html( sprintf( __( 'Timezone: %s', 'stifli-flex-mcp' ), wp_timezone_string() ) ); ?></span>
					</span>
				</h2>

				<div class="sflmcp-form-row">
					<label><?php esc_html_e( 'Run Frequency', 'stifli-flex-mcp' ); ?></label>
					<div id="sflmcp-schedule-presets" class="sflmcp-schedule-presets">
						<!-- Populated by JS -->
					</div>
					<input type="hidden" id="sflmcp-schedule-preset" name="schedule_preset" value="<?php echo esc_attr( $task ? $task->schedule_preset : 'daily_morning' ); ?>">
					<input type="hidden" id="sflmcp-schedule-timezone" name="schedule_timezone" value="<?php echo esc_attr( wp_timezone_string() ); ?>">
				</div>

				<div id="sflmcp-schedule-time-row" class="sflmcp-form-row" style="display:none;">
					<label for="sflmcp-schedule-time"><?php esc_html_e( 'Custom Time', 'stifli-flex-mcp' ); ?></label>
					<input type="time" id="sflmcp-schedule-time" name="schedule_time" value="<?php echo esc_attr( $task ? $task->schedule_time : '08:00' ); ?>">
				</div>

				<div class="sflmcp-next-runs" id="sflmcp-next-runs">
					<strong><?php esc_html_e( 'Next executions:', 'stifli-flex-mcp' ); ?></strong>
					<ul id="sflmcp-next-runs-list"></ul>
				</div>
			</div>

			<!-- Step 5: Guardrails -->
			<div class="sflmcp-form-section">
				<h2>
					<span class="sflmcp-step-number">5</span>
					<?php esc_html_e( 'Guardrails', 'stifli-flex-mcp' ); ?>
				</h2>

				<div class="sflmcp-form-row">
					<label for="sflmcp-token-budget"><?php esc_html_e( 'Monthly Token Budget', 'stifli-flex-mcp' ); ?></label>
					<div style="display: flex; align-items: center; gap: 10px;">
						<input type="number" id="sflmcp-token-budget" name="token_budget_monthly" min="0" step="1000"
							   value="<?php echo esc_attr( $task ? intval( $task->token_budget_monthly ?? 0 ) : '0' ); ?>"
							   placeholder="0" style="max-width: 180px;">
						<span class="description"><?php esc_html_e( 'tokens/month (0 = unlimited)', 'stifli-flex-mcp' ); ?></span>
					</div>
					<p class="description" style="margin-top: 6px;">
						<?php esc_html_e( 'If the task exceeds this budget in a calendar month, it will be skipped until next month. Tip: a typical daily blog post task uses ~10,000-30,000 tokens per execution.', 'stifli-flex-mcp' ); ?>
					</p>
				</div>
			</div>

			<!-- Step 6: Output Actions -->
			<div class="sflmcp-form-section">
				<h2>
					<span class="sflmcp-step-number">6</span>
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
							<input type="checkbox" id="sflmcp-output-email" name="output_email">
							<span><?php esc_html_e( 'Send email notification', 'stifli-flex-mcp' ); ?></span>
						</label>
						<label class="sflmcp-checkbox-option">
							<input type="checkbox" id="sflmcp-output-webhook" name="output_webhook">
							<span><?php esc_html_e( 'Send to Webhook (Slack, etc.)', 'stifli-flex-mcp' ); ?></span>
						</label>
						<label class="sflmcp-checkbox-option">
							<input type="checkbox" id="sflmcp-output-draft" name="output_draft">
							<span><?php esc_html_e( 'Create draft post', 'stifli-flex-mcp' ); ?></span>
						</label>
					</div>
				</div>

				<!-- Email Config -->
				<div id="sflmcp-email-config" class="sflmcp-output-config" style="display:none;">
					<div class="sflmcp-form-row">
						<label for="sflmcp-email-recipients"><?php esc_html_e( 'Recipients', 'stifli-flex-mcp' ); ?></label>
						<input type="text" id="sflmcp-email-recipients" name="email_recipients" 
							   value="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>"
							   placeholder="email@example.com, another@example.com">
					</div>
					<div class="sflmcp-form-row">
						<label for="sflmcp-email-subject"><?php esc_html_e( 'Subject', 'stifli-flex-mcp' ); ?></label>
						<input type="text" id="sflmcp-email-subject" name="email_subject" 
							   value="[{site_name}] Task: {task_name} - {date}">
					</div>
				</div>

				<!-- Webhook Config -->
				<div id="sflmcp-webhook-config" class="sflmcp-output-config" style="display:none;">
					<div class="sflmcp-form-row">
						<label><?php esc_html_e( 'Webhook Preset', 'stifli-flex-mcp' ); ?></label>
						<select id="sflmcp-webhook-preset">
							<option value="custom"><?php esc_html_e( 'Custom', 'stifli-flex-mcp' ); ?></option>
							<option value="slack"><?php esc_html_e( 'Slack', 'stifli-flex-mcp' ); ?></option>
							<option value="discord"><?php esc_html_e( 'Discord', 'stifli-flex-mcp' ); ?></option>
							<option value="telegram"><?php esc_html_e( 'Telegram', 'stifli-flex-mcp' ); ?></option>
						</select>
					</div>
					<div class="sflmcp-form-row">
						<label for="sflmcp-webhook-url"><?php esc_html_e( 'Webhook URL', 'stifli-flex-mcp' ); ?></label>
						<input type="url" id="sflmcp-webhook-url" name="webhook_url" placeholder="https://...">
					</div>
				</div>

				<!-- Draft Config -->
				<div id="sflmcp-draft-config" class="sflmcp-output-config" style="display:none;">
					<div class="sflmcp-form-row">
						<label for="sflmcp-draft-post-type"><?php esc_html_e( 'Post Type', 'stifli-flex-mcp' ); ?></label>
						<select id="sflmcp-draft-post-type" name="draft_post_type">
							<?php
							$post_types = get_post_types( array( 'public' => true ), 'objects' );
							foreach ( $post_types as $post_type ) {
								printf(
									'<option value="%s">%s</option>',
									esc_attr( $post_type->name ),
									esc_html( $post_type->label )
								);
							}
							?>
						</select>
					</div>
				</div>
			</div>

			<!-- Form Actions -->
			<div class="sflmcp-form-actions">
				<button type="button" id="sflmcp-save-draft" class="button">
					<?php esc_html_e( 'Save as Draft', 'stifli-flex-mcp' ); ?>
				</button>
				<button type="submit" id="sflmcp-save-active" class="button button-primary">
					<span class="dashicons dashicons-controls-play"></span>
					<?php esc_html_e( 'Create & Activate Task', 'stifli-flex-mcp' ); ?>
				</button>
			</div>
		</form>
		<?php
	}

	/**
	 * Render execution logs tab
	 */
	private function render_logs_tab() {
		?>
		<div class="sflmcp-logs-header">
			<div class="sflmcp-logs-filters">
				<select id="sflmcp-log-filter-task">
					<option value=""><?php esc_html_e( 'All Tasks', 'stifli-flex-mcp' ); ?></option>
				</select>
				<select id="sflmcp-log-filter-status">
					<option value=""><?php esc_html_e( 'All Status', 'stifli-flex-mcp' ); ?></option>
					<option value="success"><?php esc_html_e( 'Success', 'stifli-flex-mcp' ); ?></option>
					<option value="error"><?php esc_html_e( 'Error', 'stifli-flex-mcp' ); ?></option>
					<option value="running"><?php esc_html_e( 'Running', 'stifli-flex-mcp' ); ?></option>
				</select>
				<select id="sflmcp-log-filter-date">
					<option value="7"><?php esc_html_e( 'Last 7 days', 'stifli-flex-mcp' ); ?></option>
					<option value="30"><?php esc_html_e( 'Last 30 days', 'stifli-flex-mcp' ); ?></option>
					<option value="90"><?php esc_html_e( 'Last 90 days', 'stifli-flex-mcp' ); ?></option>
				</select>
				<button type="button" class="button" id="sflmcp-refresh-logs">
					<span class="dashicons dashicons-update"></span>
					<?php esc_html_e( 'Refresh', 'stifli-flex-mcp' ); ?>
				</button>
			</div>
		</div>

		<div class="sflmcp-logs-stats" id="sflmcp-logs-stats">
			<!-- Populated by JS -->
		</div>

		<table class="wp-list-table widefat fixed striped" id="sflmcp-logs-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Time', 'stifli-flex-mcp' ); ?></th>
					<th><?php esc_html_e( 'Task', 'stifli-flex-mcp' ); ?></th>
					<th><?php esc_html_e( 'Status', 'stifli-flex-mcp' ); ?></th>
					<th><?php esc_html_e( 'Duration', 'stifli-flex-mcp' ); ?></th>
					<th><?php esc_html_e( 'Tokens', 'stifli-flex-mcp' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'stifli-flex-mcp' ); ?></th>
				</tr>
			</thead>
			<tbody id="sflmcp-logs-body">
				<tr>
					<td colspan="6" class="sflmcp-loading">
						<span class="spinner is-active"></span>
						<?php esc_html_e( 'Loading logs...', 'stifli-flex-mcp' ); ?>
					</td>
				</tr>
			</tbody>
		</table>

		<!-- Log Detail Modal -->
		<div id="sflmcp-log-modal" class="sflmcp-modal" style="display:none;">
			<div class="sflmcp-modal-content sflmcp-modal-large">
				<div class="sflmcp-modal-header">
					<h3><?php esc_html_e( 'Execution Details', 'stifli-flex-mcp' ); ?></h3>
					<button type="button" class="sflmcp-modal-close">&times;</button>
				</div>
				<div class="sflmcp-modal-body" id="sflmcp-log-detail"></div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render templates tab
	 */
	private function render_templates_tab() {
		$templates = $this->get_default_templates();
		?>
		<div class="sflmcp-templates-intro">
			<p><?php esc_html_e( 'Choose a template to quickly create a new automation task. Templates provide preset prompts and configurations for common use cases.', 'stifli-flex-mcp' ); ?></p>
		</div>

		<div class="sflmcp-templates-grid">
			<?php foreach ( $templates as $template ) : ?>
				<div class="sflmcp-template-card" data-template="<?php echo esc_attr( $template['slug'] ); ?>">
					<div class="sflmcp-template-icon">
						<span class="dashicons <?php echo esc_attr( $template['icon'] ); ?>"></span>
					</div>
					<div class="sflmcp-template-content">
						<h3><?php echo esc_html( $template['name'] ); ?></h3>
						<p><?php echo esc_html( $template['description'] ); ?></p>
						<div class="sflmcp-template-meta">
							<span class="sflmcp-template-category"><?php echo esc_html( $template['category'] ); ?></span>
							<span class="sflmcp-template-schedule"><?php echo esc_html( $template['suggested_schedule'] ); ?></span>
						</div>
					</div>
					<div class="sflmcp-template-actions">
						<button type="button" class="button button-primary sflmcp-use-template">
							<?php esc_html_e( 'Use Template', 'stifli-flex-mcp' ); ?>
						</button>
					</div>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Get default templates
	 *
	 * @return array Templates.
	 */
	private function get_default_templates() {
		return array(
			array(
				'slug'               => 'daily-sales-report',
				'name'               => __( 'Daily Sales Report', 'stifli-flex-mcp' ),
				'description'        => __( 'Generate a comprehensive daily sales report with top products and stock alerts.', 'stifli-flex-mcp' ),
				'category'           => __( 'E-commerce', 'stifli-flex-mcp' ),
				'icon'               => 'dashicons-chart-bar',
				'suggested_schedule' => 'daily_morning',
				'prompt'             => "Generate a sales report for yesterday:\n\n1. **Summary**: Total orders, revenue, average order value\n2. **Top 5 Products**: Best sellers with units and revenue\n3. **Low Stock Alert**: Products with stock < 10 that sold yesterday\n4. **Order Status**: Count of pending, processing, completed orders\n\nFormat as a clear, scannable report.",
				'suggested_tools'    => array( 'wc_get_orders', 'wc_get_products' ),
			),
			array(
				'slug'               => 'autoblog-trending',
				'name'               => __( 'Daily Trending Article', 'stifli-flex-mcp' ),
				'description'        => __( 'Generate a daily blog article based on trending topics in your niche.', 'stifli-flex-mcp' ),
				'category'           => __( 'Autoblogging', 'stifli-flex-mcp' ),
				'icon'               => 'dashicons-edit',
				'suggested_schedule' => 'daily_morning',
				'prompt'             => "Create a new blog article for today:\n\n1. **Topic Research**: Based on my existing posts, identify a related topic I haven't covered yet\n2. **Content Creation**: Write a 600-800 word article that:\n   - Has an engaging, SEO-friendly title\n   - Includes an introduction hook\n   - Has 3-4 main sections with H2 headings\n   - Provides actionable tips or insights\n   - Ends with a call-to-action\n\n3. **SEO**: Include relevant internal links to 2-3 existing posts\n\n4. **Save**: Create the post as a DRAFT with:\n   - Appropriate categories based on existing taxonomy\n   - Meta description (155 chars)\n\nIMPORTANT: Make content unique, valuable, and different from existing posts.",
				'suggested_tools'    => array( 'wp_get_posts', 'wp_create_post', 'wp_get_categories' ),
			),
			array(
				'slug'               => 'autoblog-roundup',
				'name'               => __( 'Weekly Content Roundup', 'stifli-flex-mcp' ),
				'description'        => __( 'Create a weekly roundup post summarizing your best content or curating external sources.', 'stifli-flex-mcp' ),
				'category'           => __( 'Autoblogging', 'stifli-flex-mcp' ),
				'icon'               => 'dashicons-list-view',
				'suggested_schedule' => 'weekly_sunday',
				'prompt'             => "Create a weekly content roundup post:\n\n1. **This Week's Posts**: Get all posts published in the last 7 days\n2. **Structure**: Create a roundup article with:\n   - Brief intro mentioning the week number/date range\n   - Summary of each post (2-3 sentences + link)\n   - Highlight the most commented/engaged post\n   - Quick tips section with 3 actionable takeaways from the week\n   - Closing with what's coming next week\n\n3. **Title Format**: 'Weekly Roundup: Best of [Date Range]'\n\n4. **Save**: Create as DRAFT in 'Roundup' or 'News' category\n\nIf no posts were published this week, create a list of the top 5 evergreen posts instead.",
				'suggested_tools'    => array( 'wp_get_posts', 'wp_create_post', 'wp_get_comments' ),
			),
			array(
				'slug'               => 'low-stock-alert',
				'name'               => __( 'Low Stock Alert', 'stifli-flex-mcp' ),
				'description'        => __( 'Monitor inventory and alert when products are running low.', 'stifli-flex-mcp' ),
				'category'           => __( 'E-commerce', 'stifli-flex-mcp' ),
				'icon'               => 'dashicons-warning',
				'suggested_schedule' => 'daily_evening',
				'prompt'             => "Check inventory for products with low stock:\n\n1. Find all products with stock_quantity < 5\n2. For each, show: name, SKU, current stock, sales in last 7 days\n3. Prioritize by recent sales (high sales = urgent restock)\n4. Calculate recommended restock quantity based on average sales\n\nOnly alert if there are products needing restock.",
				'suggested_tools'    => array( 'wc_get_products' ),
			),
			array(
				'slug'               => 'comment-moderation',
				'name'               => __( 'Comment Moderation Assistant', 'stifli-flex-mcp' ),
				'description'        => __( 'Review pending comments and classify as spam, approve, or needs review.', 'stifli-flex-mcp' ),
				'category'           => __( 'Moderation', 'stifli-flex-mcp' ),
				'icon'               => 'dashicons-shield',
				'suggested_schedule' => 'every_6_hours',
				'prompt'             => "Review all comments pending moderation:\n\n1. Get comments with status 'hold'\n2. For each, analyze:\n   - Is it spam? (suspicious links, generic text)\n   - Is it offensive? (inappropriate language)\n   - Is it a legitimate question?\n\n3. Classify each as:\n   - ✅ APPROVE: Legitimate comments\n   - 🗑️ SPAM: Mark as spam\n   - ⚠️ REVIEW: Needs human review (explain why)\n\n4. Generate suggested replies for questions\n\nDO NOT approve or delete automatically. Only report recommendations.",
				'suggested_tools'    => array( 'wp_get_comments', 'wp_get_post' ),
			),
			array(
				'slug'               => 'weekly-content-summary',
				'name'               => __( 'Weekly Content Summary', 'stifli-flex-mcp' ),
				'description'        => __( 'Summarize blog activity and suggest new content ideas.', 'stifli-flex-mcp' ),
				'category'           => __( 'Content', 'stifli-flex-mcp' ),
				'icon'               => 'dashicons-admin-post',
				'suggested_schedule' => 'weekly_monday',
				'prompt'             => "Analyze blog content from the past week:\n\n1. **Posts Published**: List this week's posts with titles\n2. **Engagement**: Count comments received, identify most discussed post\n3. **Content Gaps**: Based on existing content, suggest 3 new post ideas\n4. **Draft Post**: Write a 200-word draft for the best idea\n\nSave the draft with title 'Content Idea - {date}'",
				'suggested_tools'    => array( 'wp_get_posts', 'wp_get_comments', 'wp_create_post' ),
			),
			array(
				'slug'               => 'seo-meta-optimizer',
				'name'               => __( 'SEO Meta Optimizer', 'stifli-flex-mcp' ),
				'description'        => __( 'Find and update posts with missing or poor meta descriptions.', 'stifli-flex-mcp' ),
				'category'           => __( 'Content', 'stifli-flex-mcp' ),
				'icon'               => 'dashicons-search',
				'suggested_schedule' => 'weekly_sunday',
				'prompt'             => "Audit SEO meta descriptions:\n\n1. Find posts/pages with empty or short (<120 chars) meta descriptions\n2. For each (max 5):\n   - Read the content\n   - Generate an SEO-optimized meta description (155-160 chars)\n   - Include the main keyword naturally\n\n3. Update the meta descriptions\n4. Generate a report of changes made",
				'suggested_tools'    => array( 'wp_get_posts', 'wp_get_post_meta', 'wp_update_post_meta' ),
			),
			array(
				'slug'               => 'review-response-generator',
				'name'               => __( 'Review Response Generator', 'stifli-flex-mcp' ),
				'description'        => __( 'Generate personalized responses for product reviews.', 'stifli-flex-mcp' ),
				'category'           => __( 'E-commerce', 'stifli-flex-mcp' ),
				'icon'               => 'dashicons-star-half',
				'suggested_schedule' => 'daily_morning',
				'prompt'             => "Manage product reviews from the last 7 days:\n\n1. Find reviews without responses\n2. For each review:\n   - Positive (4-5 stars): Generate a personalized thank you\n   - Negative (1-2 stars): Generate empathetic response offering solution\n   - Neutral (3 stars): Ask for specific feedback\n\n3. Save responses as draft comments\n4. Report: X reviews processed, X responses generated\n\nIMPORTANT: Make responses unique, mention the product and specific review points.",
				'suggested_tools'    => array( 'wp_get_comments', 'wc_get_products', 'wp_create_comment' ),
			),
			array(
				'slug'               => 'coupon-cleanup',
				'name'               => __( 'Expired Coupons Cleanup', 'stifli-flex-mcp' ),
				'description'        => __( 'Clean up expired coupons that haven\'t been used recently.', 'stifli-flex-mcp' ),
				'category'           => __( 'E-commerce', 'stifli-flex-mcp' ),
				'icon'               => 'dashicons-tickets-alt',
				'suggested_schedule' => 'monthly_first',
				'prompt'             => "Perform coupon maintenance:\n\n1. List coupons expired more than 30 days ago\n2. Check each for orders in the last 60 days\n3. If no recent orders, mark for deletion\n4. Generate report:\n   - Coupons to delete (name, expiry date)\n   - Coupons kept (and why)\n   - Total active coupons remaining\n\nDO NOT delete automatically - just generate the report.",
				'suggested_tools'    => array( 'wc_get_coupons' ),
			),
			array(
				'slug'               => 'performance-insights',
				'name'               => __( 'Weekly Performance Insights', 'stifli-flex-mcp' ),
				'description'        => __( 'Generate a comprehensive weekly site performance report.', 'stifli-flex-mcp' ),
				'category'           => __( 'Analytics', 'stifli-flex-mcp' ),
				'icon'               => 'dashicons-chart-line',
				'suggested_schedule' => 'weekly_monday',
				'prompt'             => "Generate a weekly performance report:\n\n1. **Content**:\n   - Posts published this week vs last week\n   - Comments received and response rate\n   - Most popular post by comments\n\n2. **E-commerce** (if WooCommerce):\n   - Week-over-week sales comparison\n   - Trending products\n   - New vs returning customers\n\n3. **Users**:\n   - New registrations\n   - Comment activity\n\n4. **Recommendations**:\n   - 3 actionable items to improve engagement\n   - 1 content opportunity\n\nFormat as a dashboard-style text report.",
				'suggested_tools'    => array( 'wp_get_posts', 'wp_get_comments', 'wp_get_users', 'wc_get_orders' ),
			),
		);
	}

	/**
	 * AJAX: Get tasks list
	 */
	public function ajax_get_tasks() {
		check_ajax_referer( 'sflmcp_automation', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied', 'stifli-flex-mcp' ) ) );
		}

		global $wpdb;
		$table = $wpdb->prefix . 'sflmcp_automation_tasks';

		$status = isset( $_POST['status'] ) ? sanitize_text_field( wp_unslash( $_POST['status'] ) ) : '';

		$where = '';
		if ( ! empty( $status ) ) {
			$where = $wpdb->prepare( ' WHERE status = %s', $status );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name from $wpdb->prefix is safe.
		$tasks = $wpdb->get_results( "SELECT * FROM {$table}{$where} ORDER BY created_at DESC" );

		wp_send_json_success( array( 'tasks' => $tasks ) );
	}

	/**
	 * AJAX: Save task
	 */
	public function ajax_save_task() {
		check_ajax_referer( 'sflmcp_automation', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied', 'stifli-flex-mcp' ) ) );
		}

		global $wpdb;
		$table = $wpdb->prefix . 'sflmcp_automation_tasks';

		// Get form data
		$task_id     = isset( $_POST['task_id'] ) ? intval( $_POST['task_id'] ) : 0;
		$task_name   = isset( $_POST['task_name'] ) ? sanitize_text_field( wp_unslash( $_POST['task_name'] ) ) : '';
		$prompt      = isset( $_POST['prompt'] ) ? sanitize_textarea_field( wp_unslash( $_POST['prompt'] ) ) : '';
		$system_prompt = isset( $_POST['system_prompt'] ) ? sanitize_textarea_field( wp_unslash( $_POST['system_prompt'] ) ) : '';
		$schedule_preset = isset( $_POST['schedule_preset'] ) ? sanitize_text_field( wp_unslash( $_POST['schedule_preset'] ) ) : 'daily_morning';
		$schedule_time = isset( $_POST['schedule_time'] ) ? sanitize_text_field( wp_unslash( $_POST['schedule_time'] ) ) : '08:00';
		$schedule_timezone = isset( $_POST['schedule_timezone'] ) ? sanitize_text_field( wp_unslash( $_POST['schedule_timezone'] ) ) : wp_timezone_string();
		$tools_mode  = isset( $_POST['tools_mode'] ) ? sanitize_text_field( wp_unslash( $_POST['tools_mode'] ) ) : 'profile';
		$allowed_tools_raw = isset( $_POST['allowed_tools'] ) ? sanitize_text_field( wp_unslash( $_POST['allowed_tools'] ) ) : '';
		$detected_tools_raw = isset( $_POST['detected_tools'] ) ? sanitize_text_field( wp_unslash( $_POST['detected_tools'] ) ) : '';
		$status      = isset( $_POST['status'] ) ? sanitize_text_field( wp_unslash( $_POST['status'] ) ) : 'draft';

		// Build tools config JSON
		$tools_config = array(
			'mode'     => $tools_mode,
			'custom'   => array_filter( explode( ',', $allowed_tools_raw ) ),
			'detected' => array_filter( explode( ',', $detected_tools_raw ) ),
		);
		$allowed_tools = wp_json_encode( $tools_config );

		// Validate required fields
		if ( empty( $task_name ) || empty( $prompt ) ) {
			wp_send_json_error( array( 'message' => __( 'Task name and prompt are required', 'stifli-flex-mcp' ) ) );
		}

		// Build output config
		$token_budget_monthly = isset( $_POST['token_budget_monthly'] ) ? intval( $_POST['token_budget_monthly'] ) : 0;

		$output_action = 'log';
		$output_config = array();

		if ( ! empty( $_POST['output_email'] ) ) {
			$output_action = 'email';
			$output_config['recipients'] = isset( $_POST['email_recipients'] ) ? sanitize_text_field( wp_unslash( $_POST['email_recipients'] ) ) : '';
			$output_config['subject_template'] = isset( $_POST['email_subject'] ) ? sanitize_text_field( wp_unslash( $_POST['email_subject'] ) ) : '';
		} elseif ( ! empty( $_POST['output_webhook'] ) ) {
			$output_action = 'webhook';
			$output_config['url'] = isset( $_POST['webhook_url'] ) ? esc_url_raw( wp_unslash( $_POST['webhook_url'] ) ) : '';
		} elseif ( ! empty( $_POST['output_draft'] ) ) {
			$output_action = 'draft';
			$output_config['post_type'] = isset( $_POST['draft_post_type'] ) ? sanitize_text_field( wp_unslash( $_POST['draft_post_type'] ) ) : 'post';
		}

		$now = current_time( 'mysql', true );

		// Calculate next run
		$temp_task = (object) array(
			'schedule_preset'   => $schedule_preset,
			'schedule_time'     => $schedule_time,
			'schedule_timezone' => $schedule_timezone,
		);
		$next_run = $this->engine->calculate_next_run( $temp_task );

		$data = array(
			'task_name'         => $task_name,
			'task_description'  => '',
			'prompt'            => $prompt,
			'system_prompt'     => $system_prompt,
			'schedule_preset'   => $schedule_preset,
			'schedule_time'     => $schedule_time,
			'schedule_timezone' => $schedule_timezone,
			'allowed_tools'     => $allowed_tools,
			'output_action'     => $output_action,
			'output_config'     => wp_json_encode( $output_config ),
			'token_budget_monthly' => $token_budget_monthly,
			'status'            => $status,
			'next_run'          => $next_run,
			'updated_at'        => $now,
		);

		if ( $task_id > 0 ) {
			// Update existing task
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update( $table, $data, array( 'id' => $task_id ) );
		} else {
			// Create new task
			$data['created_by'] = get_current_user_id();
			$data['created_at'] = $now;
			
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->insert( $table, $data );
			$task_id = $wpdb->insert_id;
		}

		wp_send_json_success( array(
			'message' => __( 'Task saved successfully', 'stifli-flex-mcp' ),
			'task_id' => $task_id,
		) );
	}

	/**
	 * AJAX: Delete task
	 */
	public function ajax_delete_task() {
		check_ajax_referer( 'sflmcp_automation', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied', 'stifli-flex-mcp' ) ) );
		}

		$task_id = isset( $_POST['task_id'] ) ? intval( $_POST['task_id'] ) : 0;

		if ( $task_id <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Invalid task ID', 'stifli-flex-mcp' ) ) );
		}

		global $wpdb;
		$table = $wpdb->prefix . 'sflmcp_automation_tasks';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( $table, array( 'id' => $task_id ), array( '%d' ) );

		// Also delete logs for this task
		$logs_table = $wpdb->prefix . 'sflmcp_automation_logs';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( $logs_table, array( 'task_id' => $task_id ), array( '%d' ) );

		wp_send_json_success( array( 'message' => __( 'Task deleted', 'stifli-flex-mcp' ) ) );
	}

	/**
	 * AJAX: Toggle task status
	 */
	public function ajax_toggle_task() {
		check_ajax_referer( 'sflmcp_automation', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied', 'stifli-flex-mcp' ) ) );
		}

		$task_id = isset( $_POST['task_id'] ) ? intval( $_POST['task_id'] ) : 0;
		$status  = isset( $_POST['status'] ) ? sanitize_text_field( wp_unslash( $_POST['status'] ) ) : '';

		if ( $task_id <= 0 || ! in_array( $status, array( 'active', 'paused' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid request', 'stifli-flex-mcp' ) ) );
		}

		global $wpdb;
		$table = $wpdb->prefix . 'sflmcp_automation_tasks';
		$now   = current_time( 'mysql', true );

		$data = array(
			'status'     => $status,
			'updated_at' => $now,
		);

		// If activating, recalculate next_run
		if ( 'active' === $status ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name from $wpdb->prefix is safe.
			$task = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $task_id ) );
			if ( $task ) {
				$data['next_run']    = $this->engine->calculate_next_run( $task );
				$data['retry_count'] = 0;
			}
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update( $table, $data, array( 'id' => $task_id ) );

		wp_send_json_success( array( 'message' => __( 'Task updated', 'stifli-flex-mcp' ) ) );
	}

	/**
	 * AJAX: Run task immediately
	 */
	public function ajax_run_task() {
		check_ajax_referer( 'sflmcp_automation', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied', 'stifli-flex-mcp' ) ) );
		}

		$task_id = isset( $_POST['task_id'] ) ? intval( $_POST['task_id'] ) : 0;

		if ( $task_id <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Invalid task ID', 'stifli-flex-mcp' ) ) );
		}

		$result = $this->engine->run_task_now( $task_id );

		if ( $result['success'] ) {
			wp_send_json_success( array(
				'message'  => __( 'Task completed successfully', 'stifli-flex-mcp' ),
				'response' => $result['result']['response'] ?? '',
				'tools'    => $result['result']['tools_called'] ?? array(),
			) );
		} else {
			wp_send_json_error( array( 'message' => $result['error'] ) );
		}
	}

	/**
	 * AJAX: Test prompt
	 */
	public function ajax_test_prompt() {
		check_ajax_referer( 'sflmcp_automation', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied', 'stifli-flex-mcp' ) ) );
		}

		$prompt        = isset( $_POST['prompt'] ) ? sanitize_textarea_field( wp_unslash( $_POST['prompt'] ) ) : '';
		$system_prompt = isset( $_POST['system_prompt'] ) ? sanitize_textarea_field( wp_unslash( $_POST['system_prompt'] ) ) : '';

		if ( empty( $prompt ) ) {
			wp_send_json_error( array( 'message' => __( 'Prompt is required', 'stifli-flex-mcp' ) ) );
		}

		$result = $this->engine->test_prompt( array(
			'prompt'        => $prompt,
			'system_prompt' => $system_prompt,
		) );

		if ( $result['success'] ) {
			wp_send_json_success( $result );
		} else {
			wp_send_json_error( array( 'message' => $result['error'] ) );
		}
	}

	/**
	 * AJAX: Start test prompt (step-based approach)
	 */
	public function ajax_test_start() {
		check_ajax_referer( 'sflmcp_automation', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied', 'stifli-flex-mcp' ) ) );
		}

		set_time_limit( 120 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- long-running AI task requires extended execution time.

		$prompt        = isset( $_POST['prompt'] ) ? sanitize_textarea_field( wp_unslash( $_POST['prompt'] ) ) : '';
		$system_prompt = isset( $_POST['system_prompt'] ) ? sanitize_textarea_field( wp_unslash( $_POST['system_prompt'] ) ) : '';

		if ( empty( $prompt ) ) {
			wp_send_json_error( array( 'message' => __( 'Prompt is required', 'stifli-flex-mcp' ) ) );
		}

		$result = $this->engine->test_prompt_start( array(
			'prompt'        => $prompt,
			'system_prompt' => $system_prompt,
		) );

		if ( $result['success'] ) {
			wp_send_json_success( $result );
		} else {
			wp_send_json_error( array( 'message' => $result['error'] ) );
		}
	}

	/**
	 * AJAX: Execute one step of test prompt
	 */
	public function ajax_test_step() {
		check_ajax_referer( 'sflmcp_automation', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied', 'stifli-flex-mcp' ) ) );
		}

		set_time_limit( 120 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- long-running AI task requires extended execution time.

		$session_id = isset( $_POST['session_id'] ) ? sanitize_text_field( wp_unslash( $_POST['session_id'] ) ) : '';

		if ( empty( $session_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Session ID is required', 'stifli-flex-mcp' ) ) );
		}

		$result = $this->engine->test_prompt_step( $session_id );

		if ( $result['success'] ) {
			wp_send_json_success( $result );
		} else {
			wp_send_json_error( array( 'message' => $result['error'] ) );
		}
	}

	/**
	 * AJAX: Get execution logs
	 */
	public function ajax_get_logs() {
		check_ajax_referer( 'sflmcp_automation', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied', 'stifli-flex-mcp' ) ) );
		}

		global $wpdb;
		$logs_table  = $wpdb->prefix . 'sflmcp_automation_logs';
		$tasks_table = $wpdb->prefix . 'sflmcp_automation_tasks';

		$task_id = isset( $_POST['task_id'] ) ? intval( $_POST['task_id'] ) : 0;
		$status  = isset( $_POST['status'] ) ? sanitize_text_field( wp_unslash( $_POST['status'] ) ) : '';
		$days    = isset( $_POST['days'] ) ? intval( $_POST['days'] ) : 7;

		$where_clauses = array();
		$where_values  = array();

		// Date filter
		$date_limit = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );
		$where_clauses[] = 'l.started_at >= %s';
		$where_values[]  = $date_limit;

		if ( $task_id > 0 ) {
			$where_clauses[] = 'l.task_id = %d';
			$where_values[]  = $task_id;
		}

		if ( ! empty( $status ) ) {
			$where_clauses[] = 'l.status = %s';
			$where_values[]  = $status;
		}

		$where = implode( ' AND ', $where_clauses );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table names from $wpdb->prefix are safe; dynamic WHERE built from prepare()d clauses.
		$logs = $wpdb->get_results( $wpdb->prepare(
			"SELECT l.*, t.task_name 
			 FROM {$logs_table} l 
			 LEFT JOIN {$tasks_table} t ON l.task_id = t.id 
			 WHERE {$where} 
			 ORDER BY l.started_at DESC 
			 LIMIT 100",
			$where_values
		) );

		// Get tasks for filter dropdown
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix is safe.
		$tasks = $wpdb->get_results( "SELECT id, task_name FROM {$tasks_table} ORDER BY task_name" );

		// Calculate stats
		$stats = array(
			'total'         => count( $logs ),
			'success'       => 0,
			'error'         => 0,
			'total_tokens'  => 0,
			'avg_duration'  => 0,
		);

		$total_duration = 0;
		foreach ( $logs as $log ) {
			if ( 'success' === $log->status ) {
				$stats['success']++;
			} elseif ( 'error' === $log->status ) {
				$stats['error']++;
			}
			$stats['total_tokens'] += intval( $log->tokens_input ) + intval( $log->tokens_output );
			$total_duration        += intval( $log->execution_time_ms );
		}

		if ( $stats['total'] > 0 ) {
			$stats['avg_duration'] = round( $total_duration / $stats['total'] );
			$stats['success_rate'] = round( ( $stats['success'] / $stats['total'] ) * 100, 1 );
		}

		wp_send_json_success( array(
			'logs'  => $logs,
			'tasks' => $tasks,
			'stats' => $stats,
		) );
	}

	/**
	 * AJAX: Get templates
	 */
	public function ajax_get_templates() {
		check_ajax_referer( 'sflmcp_automation', 'nonce' );

		wp_send_json_success( array( 'templates' => $this->get_default_templates() ) );
	}
}
