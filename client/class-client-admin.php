<?php
/**
 * MCP Client Admin - Chat interface for AI agents
 *
 * @package StifliFlexMcp
 * @since 1.0.5
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class StifliFlexMcp_Client_Admin
 * 
 * Handles the admin interface for the MCP chat client
 */
class StifliFlexMcp_Client_Admin {

	/**
	 * Option name for client settings
	 */
	const OPTION_NAME = 'sflmcp_client_settings';

	/**
	 * Transient prefix for chat history
	 */
	const HISTORY_TRANSIENT_PREFIX = 'sflmcp_chat_history_';

	/**
	 * Chat history expiration in seconds (7 days)
	 */
	const HISTORY_EXPIRATION = 604800;

	/**
	 * Initialize the client admin
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_submenu_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_sflmcp_client_chat', array( $this, 'ajax_chat' ) );
		add_action( 'wp_ajax_sflmcp_client_save_settings', array( $this, 'ajax_save_settings' ) );
		add_action( 'wp_ajax_sflmcp_client_save_advanced', array( $this, 'ajax_save_advanced' ) );
		add_action( 'wp_ajax_sflmcp_client_execute_tool', array( $this, 'ajax_execute_tool' ) );
		add_action( 'wp_ajax_sflmcp_client_save_history', array( $this, 'ajax_save_history' ) );
		add_action( 'wp_ajax_sflmcp_client_load_history', array( $this, 'ajax_load_history' ) );
		add_action( 'wp_ajax_sflmcp_client_clear_history', array( $this, 'ajax_clear_history' ) );
	}

	/**
	 * Add submenu page under StifLi Flex MCP
	 */
	public function add_submenu_page() {
		add_submenu_page(
			'stifli-flex-mcp',
			__( 'AI Chat Client', 'stifli-flex-mcp' ),
			__( 'AI Chat', 'stifli-flex-mcp' ),
			'manage_options',
			'sflmcp-client',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Enqueue assets for the client page
	 *
	 * @param string $hook The current admin page hook.
	 */
	public function enqueue_assets( $hook ) {
		if ( 'stifli-flex-mcp_page_sflmcp-client' !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'sflmcp-client',
			plugin_dir_url( __FILE__ ) . 'assets/client.css',
			array(),
			'1.0.6'
		);

		wp_enqueue_script(
			'sflmcp-client',
			plugin_dir_url( __FILE__ ) . 'assets/client.js',
			array( 'jquery' ),
			'1.0.6',
			true
		);

		$settings = $this->get_settings();
		$advanced = $this->get_advanced_settings();

		wp_localize_script( 'sflmcp-client', 'sflmcpClient', array(
			'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( 'sflmcp_client' ),
			'settings' => array(
				'provider'   => $settings['provider'] ?? 'openai',
				'model'      => $settings['model'] ?? '',
				'permission' => $settings['permission'] ?? 'ask',
			),
			'advanced' => $advanced,
			'models'   => $this->get_available_models(),
			'tools'    => $this->get_tools_info(), // Tools with name and description for display
			'i18n'     => array(
				'send'              => __( 'Send', 'stifli-flex-mcp' ),
				'thinking'          => __( 'Thinking...', 'stifli-flex-mcp' ),
				'executingTool'     => __( 'Executing tool:', 'stifli-flex-mcp' ),
				'toolResult'        => __( 'Tool result:', 'stifli-flex-mcp' ),
				'allowTool'         => __( 'Allow this tool?', 'stifli-flex-mcp' ),
				'allow'             => __( 'Allow', 'stifli-flex-mcp' ),
				'deny'              => __( 'Deny', 'stifli-flex-mcp' ),
				'toolDenied'        => __( 'Tool execution denied by user', 'stifli-flex-mcp' ),
				'error'             => __( 'Error:', 'stifli-flex-mcp' ),
				'apiKeyRequired'    => __( 'API Key is required', 'stifli-flex-mcp' ),
				'messageRequired'   => __( 'Please enter a message', 'stifli-flex-mcp' ),
				'settingsSaved'     => __( 'Settings saved', 'stifli-flex-mcp' ),
				'clearChat'         => __( 'Clear Chat', 'stifli-flex-mcp' ),
				'welcome'           => __( 'Welcome to AI Chat Client', 'stifli-flex-mcp' ),
				'welcomeDesc'       => __( 'Configure your API key above and start chatting!', 'stifli-flex-mcp' ),
				'historyRestored'   => __( 'Previous conversation restored', 'stifli-flex-mcp' ),
				'historyCleared'    => __( 'Chat history cleared', 'stifli-flex-mcp' ),
				'autoSaved'         => __( 'Auto-saved', 'stifli-flex-mcp' ),
			),
		) );
	}

	/**
	 * Get client settings
	 *
	 * @return array
	 */
	public function get_settings() {
		$defaults = array(
			'provider'   => 'openai',
			'api_key'    => '',
			'model'      => 'gpt-5.2-chat-latest',
			'permission' => 'ask',
		);

		$settings = get_option( self::OPTION_NAME, array() );
		return wp_parse_args( $settings, $defaults );
	}

	/**
	 * Get advanced settings
	 *
	 * @return array
	 */
	public function get_advanced_settings() {
		$defaults = array(
			'system_prompt'      => __( 'You are an AI assistant with access to WordPress and WooCommerce tools. Use them carefully and always explain what you are doing.', 'stifli-flex-mcp' ),
			'tool_display'       => 'full',
			'max_tools_per_turn' => 10,
			'temperature'        => 0.7,
			'max_tokens'         => 4096,
			'top_p'              => 1.0,
			'frequency_penalty'  => 0,
			'presence_penalty'   => 0,
		);

		$settings = get_option( self::OPTION_NAME . '_advanced', array() );
		return wp_parse_args( $settings, $defaults );
	}

	/**
	 * Get available models for each provider
	 *
	 * @return array
	 */
	public function get_available_models() {
		return array(
			'openai' => array(
				'gpt-5.2-chat-latest' => 'GPT-5.2 Instant (Dec 2025) [RECOMMENDED]',
				'gpt-5.2'             => 'GPT-5.2 Thinking (Adaptive Reasoning)',
				'gpt-5'               => 'GPT-5',
				'gpt-5-mini'          => 'GPT-5 Mini',
				'gpt-5-nano'          => 'GPT-5 Nano',
				'gpt-4o'              => 'GPT-4o',
				'gpt-4o-mini'         => 'GPT-4o Mini',
			),
			'claude' => array(
				'claude-sonnet-4-5-20250929'  => 'Claude Sonnet 4.5 (2025-09-29) [RECOMMENDED]',
				'claude-haiku-4-5-20251001'   => 'Claude Haiku 4.5 (2025-10-01) [FASTEST]',
				'claude-opus-4-5-20251101'    => 'Claude Opus 4.5 (2025-11-01)',
				'claude-sonnet-4-20250514'    => 'Claude Sonnet 4 (2025-05-14)',
				'claude-opus-4-20250514'      => 'Claude Opus 4 (2025-05-14)',
				'claude-opus-4-1-20250805'    => 'Claude Opus 4.1 (2025-08-05)',
				'claude-haiku-4-5'            => 'Legacy: Claude Haiku 4.5 (alias)',
				'claude-opus-4-5'             => 'Legacy: Claude Opus 4.5 (alias)',
				'claude-opus-4-1'             => 'Legacy: Claude Opus 4.1 (alias)',
				'claude-3-5-sonnet-20241022'  => 'Legacy: Claude 3.5 Sonnet (2024-10-22)',
				'claude-3-5-sonnet-20240620'  => 'Legacy: Claude 3.5 Sonnet (2024-06-20)',
				'claude-3-opus-20240229'      => 'Legacy: Claude 3 Opus (2024-02-29)',
				'claude-3-sonnet-20240229'    => 'Legacy: Claude 3 Sonnet (2024-02-29)',
				'claude-3-haiku'              => 'Legacy: Claude 3 Haiku (alias)',
			),
			'gemini' => array(
				'gemini-2.5-pro'        => 'Gemini 2.5 Pro (Reasoning) [ADVANCED]',
				'gemini-2.5-flash'      => 'Gemini 2.5 Flash (Balanced) [RECOMMENDED]',
				'gemini-2.5-flash-lite' => 'Gemini 2.5 Flash-Lite (Fast) [EFFICIENT]',
				'gemini-2.0-flash'      => 'Gemini 2.0 Flash (Agents)',
				'gemini-2.0-flash-lite' => 'Gemini 2.0 Flash-Lite (Efficient)',
			),
		);
	}

	/**
	 * Render the client page
	 */
	public function render_page() {
		$settings = $this->get_settings();
		$advanced = $this->get_advanced_settings();
		$models   = $this->get_available_models();
		$active_tab = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : 'chat';
		?>
		<div class="wrap sflmcp-client-wrap">
			<h1><?php esc_html_e( 'AI Chat Client', 'stifli-flex-mcp' ); ?></h1>
			
			<!-- Tab Navigation -->
			<nav class="nav-tab-wrapper sflmcp-tabs">
				<a href="<?php echo esc_url( add_query_arg( 'tab', 'chat' ) ); ?>" 
				   class="nav-tab <?php echo 'chat' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<span class="dashicons dashicons-format-chat"></span>
					<?php esc_html_e( 'Chat', 'stifli-flex-mcp' ); ?>
				</a>
				<a href="<?php echo esc_url( add_query_arg( 'tab', 'advanced' ) ); ?>" 
				   class="nav-tab <?php echo 'advanced' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<span class="dashicons dashicons-admin-settings"></span>
					<?php esc_html_e( 'Advanced Settings', 'stifli-flex-mcp' ); ?>
				</a>
			</nav>

			<?php if ( 'chat' === $active_tab ) : ?>
				<?php $this->render_chat_tab( $settings, $models ); ?>
			<?php else : ?>
				<?php $this->render_advanced_tab( $advanced, $models ); ?>
			<?php endif; ?>
			
			<!-- Tool Execution Modal -->
			<div id="sflmcp-tool-modal" class="sflmcp-modal" style="display:none;">
				<div class="sflmcp-modal-content">
					<div class="sflmcp-modal-header">
						<span class="dashicons dashicons-admin-tools"></span>
						<h3><?php esc_html_e( 'Tool Execution Request', 'stifli-flex-mcp' ); ?></h3>
					</div>
					<div class="sflmcp-modal-body">
						<p><?php esc_html_e( 'The AI wants to execute the following tool:', 'stifli-flex-mcp' ); ?></p>
						<div class="sflmcp-tool-info">
							<div class="sflmcp-tool-name"></div>
							<div class="sflmcp-tool-args"></div>
						</div>
					</div>
					<div class="sflmcp-modal-footer">
						<button type="button" class="button button-secondary sflmcp-modal-deny">
							<?php esc_html_e( 'Deny', 'stifli-flex-mcp' ); ?>
						</button>
						<button type="button" class="button button-primary sflmcp-modal-allow">
							<?php esc_html_e( 'Allow', 'stifli-flex-mcp' ); ?>
						</button>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the Chat tab
	 *
	 * @param array $settings Current settings.
	 * @param array $models   Available models.
	 */
	private function render_chat_tab( $settings, $models ) {
		?>
		<!-- Settings Panel -->
		<div class="sflmcp-client-settings">
			<div class="sflmcp-settings-row">
				<div class="sflmcp-setting-group">
					<label for="sflmcp-provider"><?php esc_html_e( 'AI Provider', 'stifli-flex-mcp' ); ?></label>
					<select id="sflmcp-provider" name="provider">
						<option value="openai" <?php selected( $settings['provider'], 'openai' ); ?>>OpenAI</option>
						<option value="claude" <?php selected( $settings['provider'], 'claude' ); ?>>Claude (Anthropic)</option>
						<option value="gemini" <?php selected( $settings['provider'], 'gemini' ); ?>>Gemini (Google)</option>
					</select>
				</div>
				
				<div class="sflmcp-setting-group">
					<label for="sflmcp-api-key"><?php esc_html_e( 'API Key', 'stifli-flex-mcp' ); ?></label>
					<input type="password" id="sflmcp-api-key" name="api_key" value="<?php echo esc_attr( $settings['api_key'] ); ?>" placeholder="sk-..." />
				</div>
				
				<div class="sflmcp-setting-group">
					<label for="sflmcp-model"><?php esc_html_e( 'Model', 'stifli-flex-mcp' ); ?></label>
					<select id="sflmcp-model" name="model">
						<?php foreach ( $models as $provider => $provider_models ) : ?>
							<?php foreach ( $provider_models as $model_id => $model_name ) : ?>
								<option value="<?php echo esc_attr( $model_id ); ?>" 
										data-provider="<?php echo esc_attr( $provider ); ?>"
										<?php selected( $settings['model'], $model_id ); ?>
										<?php echo $settings['provider'] !== $provider ? 'style="display:none;"' : ''; ?>>
									<?php echo esc_html( $model_name ); ?>
								</option>
							<?php endforeach; ?>
						<?php endforeach; ?>
					</select>
				</div>
				
				<div class="sflmcp-setting-group">
					<label for="sflmcp-permission"><?php esc_html_e( 'Tool Permissions', 'stifli-flex-mcp' ); ?></label>
					<select id="sflmcp-permission" name="permission">
						<option value="always" <?php selected( $settings['permission'], 'always' ); ?>><?php esc_html_e( 'Always Allow', 'stifli-flex-mcp' ); ?></option>
						<option value="ask" <?php selected( $settings['permission'], 'ask' ); ?>><?php esc_html_e( 'Ask User', 'stifli-flex-mcp' ); ?></option>
					</select>
				</div>
				
				<div class="sflmcp-setting-group sflmcp-setting-actions">
					<button type="button" id="sflmcp-save-settings" class="button button-primary">
						<?php esc_html_e( 'Save Settings', 'stifli-flex-mcp' ); ?>
					</button>
				</div>
			</div>
		</div>
		
		<!-- Chat Container -->
		<div class="sflmcp-chat-container">
			<div class="sflmcp-chat-header">
				<span class="sflmcp-chat-title"><?php esc_html_e( 'Chat with AI', 'stifli-flex-mcp' ); ?></span>
				<div class="sflmcp-chat-actions">
					<span id="sflmcp-autosave-indicator" class="sflmcp-autosave-indicator" style="display:none;">
						<span class="dashicons dashicons-saved"></span>
						<?php esc_html_e( 'Saved', 'stifli-flex-mcp' ); ?>
					</span>
					<button type="button" id="sflmcp-clear-chat" class="button button-secondary">
						<?php esc_html_e( 'Clear Chat', 'stifli-flex-mcp' ); ?>
					</button>
				</div>
			</div>
			
			<div class="sflmcp-chat-messages" id="sflmcp-chat-messages">
				<div class="sflmcp-welcome-message">
					<div class="sflmcp-welcome-icon">🤖</div>
					<h3><?php esc_html_e( 'Welcome to AI Chat Client', 'stifli-flex-mcp' ); ?></h3>
					<p><?php esc_html_e( 'This chat can execute MCP tools on your WordPress site. Configure your API key above and start chatting!', 'stifli-flex-mcp' ); ?></p>
					<p class="sflmcp-welcome-hint"><?php esc_html_e( 'Try: "List my recent posts" or "Show WooCommerce orders"', 'stifli-flex-mcp' ); ?></p>
				</div>
			</div>
			
			<div class="sflmcp-chat-input-container">
				<textarea id="sflmcp-chat-input" 
						  placeholder="<?php esc_attr_e( 'Type your message...', 'stifli-flex-mcp' ); ?>" 
						  rows="2"></textarea>
				<button type="button" id="sflmcp-send-btn" class="button button-primary">
					<span class="dashicons dashicons-arrow-right-alt"></span>
					<?php esc_html_e( 'Send', 'stifli-flex-mcp' ); ?>
				</button>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the Advanced Settings tab
	 *
	 * @param array $advanced Advanced settings.
	 * @param array $models   Available models.
	 */
	private function render_advanced_tab( $advanced, $models ) {
		$settings = $this->get_settings();
		?>
		<div class="sflmcp-advanced-settings">
			<p class="description">
				<?php esc_html_e( 'These settings are auto-saved when you change them.', 'stifli-flex-mcp' ); ?>
				<span id="sflmcp-advanced-save-indicator" class="sflmcp-save-indicator" style="display:none;">
					<span class="dashicons dashicons-saved"></span>
					<?php esc_html_e( 'Saved', 'stifli-flex-mcp' ); ?>
				</span>
			</p>

			<table class="form-table sflmcp-advanced-form">
				<!-- System Prompt -->
				<tr>
					<th scope="row">
						<label for="sflmcp-system-prompt"><?php esc_html_e( 'System Prompt', 'stifli-flex-mcp' ); ?></label>
					</th>
					<td>
						<textarea id="sflmcp-system-prompt" name="system_prompt" rows="5" class="large-text"><?php echo esc_textarea( $advanced['system_prompt'] ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Instructions sent with every chat message. Define the AI personality and behavior.', 'stifli-flex-mcp' ); ?></p>
					</td>
				</tr>

				<!-- Tool Display Mode -->
				<tr>
					<th scope="row">
						<label for="sflmcp-tool-display"><?php esc_html_e( 'Tool Display Mode', 'stifli-flex-mcp' ); ?></label>
					</th>
					<td>
						<select id="sflmcp-tool-display" name="tool_display">
							<option value="full" <?php selected( $advanced['tool_display'], 'full' ); ?>><?php esc_html_e( 'Full (name, description, parameters)', 'stifli-flex-mcp' ); ?></option>
							<option value="compact" <?php selected( $advanced['tool_display'], 'compact' ); ?>><?php esc_html_e( 'Compact (name, short description)', 'stifli-flex-mcp' ); ?></option>
							<option value="name_only" <?php selected( $advanced['tool_display'], 'name_only' ); ?>><?php esc_html_e( 'Name Only (tool name)', 'stifli-flex-mcp' ); ?></option>
							<option value="hidden" <?php selected( $advanced['tool_display'], 'hidden' ); ?>><?php esc_html_e( 'Hidden (collapse by default)', 'stifli-flex-mcp' ); ?></option>
						</select>
						<p class="description"><?php esc_html_e( 'How tool executions are displayed in the chat.', 'stifli-flex-mcp' ); ?></p>
					</td>
				</tr>

				<!-- Max Tools Per Turn -->
				<tr>
					<th scope="row">
						<label for="sflmcp-max-tools"><?php esc_html_e( 'Max Tools Per Turn', 'stifli-flex-mcp' ); ?></label>
					</th>
					<td>
						<input type="number" id="sflmcp-max-tools" name="max_tools_per_turn" value="<?php echo esc_attr( $advanced['max_tools_per_turn'] ); ?>" min="1" max="50" class="small-text" />
						<p class="description"><?php esc_html_e( 'Maximum number of tools that can be executed in a single AI response. Default: 10.', 'stifli-flex-mcp' ); ?></p>
					</td>
				</tr>

				<!-- Model Selection (duplicate for convenience) -->
				<tr>
					<th scope="row">
						<label for="sflmcp-adv-provider"><?php esc_html_e( 'AI Provider & Model', 'stifli-flex-mcp' ); ?></label>
					</th>
					<td>
						<select id="sflmcp-adv-provider" name="adv_provider" style="width:200px;">
							<option value="openai" <?php selected( $settings['provider'], 'openai' ); ?>>OpenAI</option>
							<option value="claude" <?php selected( $settings['provider'], 'claude' ); ?>>Claude (Anthropic)</option>
							<option value="gemini" <?php selected( $settings['provider'], 'gemini' ); ?>>Gemini (Google)</option>
						</select>
						<select id="sflmcp-adv-model" name="adv_model" style="width:350px;">
							<?php foreach ( $models as $provider => $provider_models ) : ?>
								<?php foreach ( $provider_models as $model_id => $model_name ) : ?>
									<option value="<?php echo esc_attr( $model_id ); ?>" 
											data-provider="<?php echo esc_attr( $provider ); ?>"
											<?php selected( $settings['model'], $model_id ); ?>
											<?php echo $settings['provider'] !== $provider ? 'style="display:none;"' : ''; ?>>
										<?php echo esc_html( $model_name ); ?>
									</option>
								<?php endforeach; ?>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( 'Also configurable in the Chat tab.', 'stifli-flex-mcp' ); ?></p>
					</td>
				</tr>

				<!-- Model Parameters Section -->
				<tr>
					<th scope="row" colspan="2">
						<h2 class="sflmcp-section-title">
							<span class="dashicons dashicons-admin-generic"></span>
							<?php esc_html_e( 'Model Parameters', 'stifli-flex-mcp' ); ?>
						</h2>
						<p class="description"><?php esc_html_e( 'Fine-tune the AI behavior. Not all parameters are supported by all models.', 'stifli-flex-mcp' ); ?></p>
					</th>
				</tr>

				<!-- Temperature -->
				<tr class="sflmcp-model-param" data-providers="openai,claude,gemini">
					<th scope="row">
						<label for="sflmcp-temperature"><?php esc_html_e( 'Temperature', 'stifli-flex-mcp' ); ?></label>
					</th>
					<td>
						<input type="range" id="sflmcp-temperature" name="temperature" value="<?php echo esc_attr( $advanced['temperature'] ); ?>" min="0" max="2" step="0.1" />
						<span id="sflmcp-temperature-value"><?php echo esc_html( $advanced['temperature'] ); ?></span>
						<p class="description"><?php esc_html_e( 'Controls randomness. 0 = deterministic, 2 = very creative. Default: 0.7', 'stifli-flex-mcp' ); ?></p>
					</td>
				</tr>

				<!-- Max Tokens -->
				<tr class="sflmcp-model-param" data-providers="openai,claude,gemini">
					<th scope="row">
						<label for="sflmcp-max-tokens"><?php esc_html_e( 'Max Tokens', 'stifli-flex-mcp' ); ?></label>
					</th>
					<td>
						<input type="number" id="sflmcp-max-tokens" name="max_tokens" value="<?php echo esc_attr( $advanced['max_tokens'] ); ?>" min="100" max="128000" step="100" class="regular-text" />
						<p class="description"><?php esc_html_e( 'Maximum length of the response. Default: 4096', 'stifli-flex-mcp' ); ?></p>
					</td>
				</tr>

				<!-- Top P -->
				<tr class="sflmcp-model-param" data-providers="openai,claude,gemini">
					<th scope="row">
						<label for="sflmcp-top-p"><?php esc_html_e( 'Top P (Nucleus Sampling)', 'stifli-flex-mcp' ); ?></label>
					</th>
					<td>
						<input type="range" id="sflmcp-top-p" name="top_p" value="<?php echo esc_attr( $advanced['top_p'] ); ?>" min="0" max="1" step="0.05" />
						<span id="sflmcp-top-p-value"><?php echo esc_html( $advanced['top_p'] ); ?></span>
						<p class="description"><?php esc_html_e( 'Alternative to temperature. 1.0 considers all tokens. Default: 1.0', 'stifli-flex-mcp' ); ?></p>
					</td>
				</tr>

				<!-- Frequency Penalty (OpenAI only) -->
				<tr class="sflmcp-model-param" data-providers="openai">
					<th scope="row">
						<label for="sflmcp-frequency-penalty"><?php esc_html_e( 'Frequency Penalty', 'stifli-flex-mcp' ); ?></label>
					</th>
					<td>
						<input type="range" id="sflmcp-frequency-penalty" name="frequency_penalty" value="<?php echo esc_attr( $advanced['frequency_penalty'] ); ?>" min="0" max="2" step="0.1" />
						<span id="sflmcp-frequency-penalty-value"><?php echo esc_html( $advanced['frequency_penalty'] ); ?></span>
						<p class="description"><?php esc_html_e( 'Reduces repetition of tokens. OpenAI only. Default: 0', 'stifli-flex-mcp' ); ?></p>
					</td>
				</tr>

				<!-- Presence Penalty (OpenAI only) -->
				<tr class="sflmcp-model-param" data-providers="openai">
					<th scope="row">
						<label for="sflmcp-presence-penalty"><?php esc_html_e( 'Presence Penalty', 'stifli-flex-mcp' ); ?></label>
					</th>
					<td>
						<input type="range" id="sflmcp-presence-penalty" name="presence_penalty" value="<?php echo esc_attr( $advanced['presence_penalty'] ); ?>" min="0" max="2" step="0.1" />
						<span id="sflmcp-presence-penalty-value"><?php echo esc_html( $advanced['presence_penalty'] ); ?></span>
						<p class="description"><?php esc_html_e( 'Encourages new topics. OpenAI only. Default: 0', 'stifli-flex-mcp' ); ?></p>
					</td>
				</tr>
			</table>
		</div>
		<?php
	}

	/**
	 * AJAX handler for saving settings
	 */
	public function ajax_save_settings() {
		check_ajax_referer( 'sflmcp_client', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied', 'stifli-flex-mcp' ) ) );
		}

		$settings = array(
			'provider'   => sanitize_text_field( wp_unslash( $_POST['provider'] ?? 'openai' ) ),
			'api_key'    => sanitize_text_field( wp_unslash( $_POST['api_key'] ?? '' ) ),
			'model'      => sanitize_text_field( wp_unslash( $_POST['model'] ?? '' ) ),
			'permission' => sanitize_text_field( wp_unslash( $_POST['permission'] ?? 'ask' ) ),
		);

		update_option( self::OPTION_NAME, $settings );

		wp_send_json_success( array( 'message' => __( 'Settings saved', 'stifli-flex-mcp' ) ) );
	}

	/**
	 * AJAX handler for saving advanced settings
	 */
	public function ajax_save_advanced() {
		check_ajax_referer( 'sflmcp_client', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied', 'stifli-flex-mcp' ) ) );
		}

		$advanced = array(
			'system_prompt'      => sanitize_textarea_field( wp_unslash( $_POST['system_prompt'] ?? '' ) ),
			'tool_display'       => sanitize_text_field( wp_unslash( $_POST['tool_display'] ?? 'full' ) ),
			'max_tools_per_turn' => absint( $_POST['max_tools_per_turn'] ?? 10 ),
			'temperature'        => floatval( $_POST['temperature'] ?? 0.7 ),
			'max_tokens'         => absint( $_POST['max_tokens'] ?? 4096 ),
			'top_p'              => floatval( $_POST['top_p'] ?? 1.0 ),
			'frequency_penalty'  => floatval( $_POST['frequency_penalty'] ?? 0 ),
			'presence_penalty'   => floatval( $_POST['presence_penalty'] ?? 0 ),
		);

		// Validate ranges
		$advanced['temperature']       = max( 0, min( 2, $advanced['temperature'] ) );
		$advanced['max_tokens']        = max( 100, min( 128000, $advanced['max_tokens'] ) );
		$advanced['top_p']             = max( 0, min( 1, $advanced['top_p'] ) );
		$advanced['frequency_penalty'] = max( 0, min( 2, $advanced['frequency_penalty'] ) );
		$advanced['presence_penalty']  = max( 0, min( 2, $advanced['presence_penalty'] ) );
		$advanced['max_tools_per_turn'] = max( 1, min( 50, $advanced['max_tools_per_turn'] ) );

		update_option( self::OPTION_NAME . '_advanced', $advanced );

		// Also update provider/model if sent from advanced tab
		if ( isset( $_POST['adv_provider'] ) && isset( $_POST['adv_model'] ) ) {
			$settings = $this->get_settings();
			$settings['provider'] = sanitize_text_field( wp_unslash( $_POST['adv_provider'] ) );
			$settings['model']    = sanitize_text_field( wp_unslash( $_POST['adv_model'] ) );
			update_option( self::OPTION_NAME, $settings );
		}

		wp_send_json_success( array( 'message' => __( 'Settings saved', 'stifli-flex-mcp' ) ) );
	}

	/**
	 * AJAX handler for saving chat history
	 */
	public function ajax_save_history() {
		check_ajax_referer( 'sflmcp_client', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied', 'stifli-flex-mcp' ) ) );
		}

		$user_id = get_current_user_id();
		$history = isset( $_POST['history'] ) ? json_decode( wp_unslash( $_POST['history'] ), true ) : array();

		if ( ! is_array( $history ) ) {
			$history = array();
		}

		// Store in transient (7 days expiration)
		set_transient( self::HISTORY_TRANSIENT_PREFIX . $user_id, $history, self::HISTORY_EXPIRATION );

		wp_send_json_success( array( 'message' => __( 'History saved', 'stifli-flex-mcp' ) ) );
	}

	/**
	 * AJAX handler for loading chat history
	 */
	public function ajax_load_history() {
		check_ajax_referer( 'sflmcp_client', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied', 'stifli-flex-mcp' ) ) );
		}

		$user_id = get_current_user_id();
		$history = get_transient( self::HISTORY_TRANSIENT_PREFIX . $user_id );

		if ( false === $history ) {
			$history = array();
		}

		wp_send_json_success( array( 'history' => $history ) );
	}

	/**
	 * AJAX handler for clearing chat history
	 */
	public function ajax_clear_history() {
		check_ajax_referer( 'sflmcp_client', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied', 'stifli-flex-mcp' ) ) );
		}

		$user_id = get_current_user_id();
		delete_transient( self::HISTORY_TRANSIENT_PREFIX . $user_id );

		wp_send_json_success( array( 'message' => __( 'History cleared', 'stifli-flex-mcp' ) ) );
	}

	/**
	 * AJAX handler for chat messages
	 */
	public function ajax_chat() {
		check_ajax_referer( 'sflmcp_client', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied', 'stifli-flex-mcp' ) ) );
		}

		$message       = sanitize_textarea_field( wp_unslash( $_POST['message'] ?? '' ) );
		$provider      = sanitize_text_field( wp_unslash( $_POST['provider'] ?? 'openai' ) );
		$api_key       = sanitize_text_field( wp_unslash( $_POST['api_key'] ?? '' ) );
		$model         = sanitize_text_field( wp_unslash( $_POST['model'] ?? '' ) );
		$conversation  = isset( $_POST['conversation'] ) ? json_decode( wp_unslash( $_POST['conversation'] ), true ) : array();
		$tool_result   = isset( $_POST['tool_result'] ) ? json_decode( wp_unslash( $_POST['tool_result'] ), true ) : null;

		// Get advanced settings for model parameters
		$advanced = $this->get_advanced_settings();

		if ( empty( $api_key ) ) {
			wp_send_json_error( array( 'message' => __( 'API Key is required', 'stifli-flex-mcp' ) ) );
		}

		// Get MCP tools
		$tools = $this->get_mcp_tools();

		// Initialize the appropriate provider handler
		$handler = $this->get_provider_handler( $provider );
		if ( ! $handler ) {
			wp_send_json_error( array( 'message' => __( 'Invalid provider', 'stifli-flex-mcp' ) ) );
		}

		// Send message to AI with advanced parameters
		$result = $handler->send_message( array(
			'api_key'       => $api_key,
			'model'         => $model,
			'message'       => $message,
			'conversation'  => $conversation,
			'tools'         => $tools,
			'tool_result'   => $tool_result,
			'system_prompt' => $advanced['system_prompt'],
			'temperature'   => $advanced['temperature'],
			'max_tokens'    => $advanced['max_tokens'],
			'top_p'         => $advanced['top_p'],
			'frequency_penalty' => $advanced['frequency_penalty'],
			'presence_penalty'  => $advanced['presence_penalty'],
		) );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( $result );
	}

	/**
	 * Get MCP tools from the model
	 *
	 * @return array
	 */
	private function get_mcp_tools() {
		global $stifliFlexMcp;
		
		if ( ! isset( $stifliFlexMcp ) || ! isset( $stifliFlexMcp->model ) ) {
			return array();
		}

		$all_tools = $stifliFlexMcp->model->getToolsList();
		$formatted = array();

		foreach ( $all_tools as $tool ) {
			$formatted[] = array(
				'name'        => $tool['name'],
				'description' => $tool['description'],
				'inputSchema' => $tool['inputSchema'] ?? array( 'type' => 'object', 'properties' => new stdClass() ),
			);
		}

		return $formatted;
	}

	/**
	 * Get tools info for JavaScript (name + description only, for display purposes)
	 *
	 * @return array Associative array keyed by tool name
	 */
	private function get_tools_info() {
		global $stifliFlexMcp;
		
		if ( ! isset( $stifliFlexMcp ) || ! isset( $stifliFlexMcp->model ) ) {
			return array();
		}

		$all_tools = $stifliFlexMcp->model->getToolsList();
		$tools_info = array();

		foreach ( $all_tools as $tool ) {
			$tools_info[ $tool['name'] ] = array(
				'name'        => $tool['name'],
				'description' => $tool['description'] ?? '',
			);
		}

		return $tools_info;
	}

	/**
	 * Get the appropriate provider handler
	 *
	 * @param string $provider Provider name.
	 * @return object|null
	 */
	private function get_provider_handler( $provider ) {
		$handlers = array(
			'openai' => 'StifliFlexMcp_Client_OpenAI',
			'claude' => 'StifliFlexMcp_Client_Claude',
			'gemini' => 'StifliFlexMcp_Client_Gemini',
		);

		if ( ! isset( $handlers[ $provider ] ) ) {
			return null;
		}

		$class = $handlers[ $provider ];
		if ( ! class_exists( $class ) ) {
			return null;
		}

		return new $class();
	}

	/**
	 * AJAX handler for executing a tool
	 */
	public function ajax_execute_tool() {
		check_ajax_referer( 'sflmcp_client', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied', 'stifli-flex-mcp' ) ) );
		}

		$tool_name = sanitize_text_field( wp_unslash( $_POST['tool_name'] ?? '' ) );
		$arguments = isset( $_POST['arguments'] ) ? json_decode( wp_unslash( $_POST['arguments'] ), true ) : array();

		if ( empty( $tool_name ) ) {
			wp_send_json_error( array( 'message' => __( 'Tool name is required', 'stifli-flex-mcp' ) ) );
		}

		global $stifliFlexMcp;

		if ( ! isset( $stifliFlexMcp ) || ! isset( $stifliFlexMcp->model ) ) {
			wp_send_json_error( array( 'message' => __( 'MCP model not available', 'stifli-flex-mcp' ) ) );
		}

		// Execute the tool
		$result = $stifliFlexMcp->model->dispatchTool( $tool_name, $arguments, null );

		if ( isset( $result['error'] ) ) {
			wp_send_json_error( array(
				'message' => $result['error']['message'] ?? __( 'Tool execution failed', 'stifli-flex-mcp' ),
			) );
		}

		// Extract text content from result
		$content = '';
		if ( isset( $result['result']['content'] ) && is_array( $result['result']['content'] ) ) {
			foreach ( $result['result']['content'] as $item ) {
				if ( isset( $item['text'] ) ) {
					$content .= $item['text'] . "\n";
				}
			}
		}

		wp_send_json_success( array(
			'success' => true,
			'content' => trim( $content ),
			'raw'     => $result['result'] ?? null,
		) );
	}
}
