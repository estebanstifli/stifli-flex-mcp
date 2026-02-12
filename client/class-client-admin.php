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
	 * Encryption cipher method
	 */
	const CIPHER = 'aes-256-cbc';

	/**
	 * Encrypt a value for safe storage in the database.
	 *
	 * @param string $plain_text The plain text to encrypt.
	 * @return string The base64-encoded encrypted value (iv:ciphertext).
	 */
	private static function encrypt_value( $plain_text ) {
		if ( empty( $plain_text ) ) {
			return '';
		}

		$key = hash( 'sha256', wp_salt( 'auth' ), true );
		$iv  = openssl_random_pseudo_bytes( openssl_cipher_iv_length( self::CIPHER ) );

		$encrypted = openssl_encrypt( $plain_text, self::CIPHER, $key, 0, $iv );
		if ( false === $encrypted ) {
			return $plain_text; // Fallback to plain text on failure.
		}

		// Store as base64(iv):base64(ciphertext)
		return base64_encode( $iv ) . ':' . $encrypted;
	}

	/**
	 * Decrypt a value from the database.
	 *
	 * @param string $encrypted_text The encrypted value (iv:ciphertext).
	 * @return string The decrypted plain text.
	 */
	private static function decrypt_value( $encrypted_text ) {
		if ( empty( $encrypted_text ) ) {
			return '';
		}

		// If the value doesn't contain ':', it's not encrypted (legacy plain text).
		if ( strpos( $encrypted_text, ':' ) === false ) {
			return $encrypted_text;
		}

		$parts = explode( ':', $encrypted_text, 2 );
		if ( count( $parts ) !== 2 ) {
			return $encrypted_text;
		}

		$iv         = base64_decode( $parts[0] );
		$ciphertext = $parts[1];
		$key        = hash( 'sha256', wp_salt( 'auth' ), true );

		$decrypted = openssl_decrypt( $ciphertext, self::CIPHER, $key, 0, $iv );
		if ( false === $decrypted ) {
			return $encrypted_text; // Return as-is if decryption fails.
		}

		return $decrypted;
	}

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
	 * Add submenu page under StifLi Flex MCP — first item (AI Chat Agent)
	 */
	public function add_submenu_page() {
		add_submenu_page(
			'stifli-flex-mcp',
			__( 'AI Chat Agent', 'stifli-flex-mcp' ),
			__( 'AI Chat Agent', 'stifli-flex-mcp' ),
			'manage_options',
			'stifli-flex-mcp', // Same slug as parent → replaces auto-generated first submenu
			array( $this, 'render_page' )
		);
	}

	/**
	 * Enqueue assets for the client page
	 *
	 * @param string $hook The current admin page hook.
	 */
	public function enqueue_assets( $hook ) {
		// AI Chat Agent is now the parent page (same slug as menu)
		if ( 'toplevel_page_stifli-flex-mcp' !== $hook ) {
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
				'stop'              => __( 'Stop', 'stifli-flex-mcp' ),
				'stopped'           => __( 'Stopped by user', 'stifli-flex-mcp' ),
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
				'welcome'           => __( 'Welcome to AI Chat Agent', 'stifli-flex-mcp' ),
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
		$settings = wp_parse_args( $settings, $defaults );

		// Decrypt the API key.
		$settings['api_key'] = self::decrypt_value( $settings['api_key'] );

		return $settings;
	}

	/**
	 * Get advanced settings
	 *
	 * @return array
	 */
	public function get_advanced_settings() {
		$defaults = array(
			'system_prompt'         => __( 'You are an AI assistant with access to WordPress and WooCommerce tools. Use them carefully and always explain what you are doing.', 'stifli-flex-mcp' ),
			'tool_display'          => 'full',
			'max_history_turns'     => 10,
			'max_tools_per_turn'    => 10,
			'temperature'           => 0.7,
			'max_tokens'            => 4096,
			'top_p'                 => 1.0,
			'frequency_penalty'     => 0,
			'presence_penalty'      => 0,
			'enable_suggestions'    => true,
			'suggestions_count'     => 3,
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
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Tab selection for display only, no data processing
		$active_tab = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : 'chat';
		?>
		<div class="wrap sflmcp-client-wrap">
			<h1><?php esc_html_e( 'AI Chat Agent', 'stifli-flex-mcp' ); ?></h1>
			
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
					<div class="sflmcp-api-key-wrapper">
						<input type="password" id="sflmcp-api-key" name="api_key" value="<?php echo esc_attr( $settings['api_key'] ); ?>" placeholder="sk-..." />
						<button type="button" id="sflmcp-api-key-toggle" class="sflmcp-api-key-toggle" title="<?php esc_attr_e( 'Show/Hide API Key', 'stifli-flex-mcp' ); ?>">
							<span class="dashicons dashicons-visibility"></span>
						</button>
					</div>
				</div>
				
				<div class="sflmcp-setting-group">
					<label for="sflmcp-model"><?php esc_html_e( 'Model', 'stifli-flex-mcp' ); ?></label>
					<select id="sflmcp-model" name="model">
						<?php foreach ( $models as $provider => $provider_models ) : ?>
							<?php foreach ( $provider_models as $model_id => $model_name ) : ?>
								<option value="<?php echo esc_attr( $model_id ); ?>" 
										data-provider="<?php echo esc_attr( $provider ); ?>"
										<?php selected( $settings['model'], $model_id ); ?>
										<?php echo $settings['provider'] !== $provider ? 'disabled hidden' : ''; ?>>
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
					<span id="sflmcp-settings-autosave" class="sflmcp-autosave-indicator" style="display:none;">
						<span class="dashicons dashicons-saved"></span>
						<?php esc_html_e( 'Saved', 'stifli-flex-mcp' ); ?>
					</span>
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
					<h3><?php esc_html_e( 'Welcome to AI Chat Agent', 'stifli-flex-mcp' ); ?></h3>
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
				<!-- AI Provider & Model (first for convenience) -->
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
											<?php echo $settings['provider'] !== $provider ? 'disabled hidden' : ''; ?>>
										<?php echo esc_html( $model_name ); ?>
									</option>
								<?php endforeach; ?>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( 'Also configurable in the Chat tab.', 'stifli-flex-mcp' ); ?></p>
					</td>
				</tr>

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

					<!-- Max History Turns -->
					<tr>
						<th scope="row">
							<label for="sflmcp-max-history-turns"><?php esc_html_e( 'Max Tool Cycles in History', 'stifli-flex-mcp' ); ?></label>
						</th>
						<td>
							<input type="number" id="sflmcp-max-history-turns" name="max_history_turns" value="<?php echo esc_attr( $advanced['max_history_turns'] ?? 10 ); ?>" min="1" max="100" class="small-text" />
							<p class="description"><?php esc_html_e( 'Max tool call/result cycles kept in history. Plain text messages are always kept. Default: 10.', 'stifli-flex-mcp' ); ?></p>
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

				<!-- Suggestions Section -->
				<tr>
					<th scope="row" colspan="2">
						<h2 class="sflmcp-section-title">
							<span class="dashicons dashicons-lightbulb"></span>
							<?php esc_html_e( 'Suggested Replies', 'stifli-flex-mcp' ); ?>
						</h2>
						<p class="description"><?php esc_html_e( 'Show clickable suggestion chips after each AI response.', 'stifli-flex-mcp' ); ?></p>
					</th>
				</tr>

				<!-- Enable Suggestions -->
				<tr>
					<th scope="row">
						<label for="sflmcp-enable-suggestions"><?php esc_html_e( 'Enable Suggestions', 'stifli-flex-mcp' ); ?></label>
					</th>
					<td>
						<label>
							<input type="checkbox" id="sflmcp-enable-suggestions" name="enable_suggestions" value="1" <?php checked( $advanced['enable_suggestions'] ); ?> />
							<?php esc_html_e( 'Show suggested replies as clickable chips', 'stifli-flex-mcp' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'When enabled, the AI will provide quick reply suggestions that you can click to send.', 'stifli-flex-mcp' ); ?></p>
					</td>
				</tr>

				<!-- Number of Suggestions -->
				<tr>
					<th scope="row">
						<label for="sflmcp-suggestions-count"><?php esc_html_e( 'Number of Suggestions', 'stifli-flex-mcp' ); ?></label>
					</th>
					<td>
						<input type="number" id="sflmcp-suggestions-count" name="suggestions_count" value="<?php echo esc_attr( $advanced['suggestions_count'] ); ?>" min="1" max="6" class="small-text" />
						<p class="description"><?php esc_html_e( 'How many suggestions to show after each response. Default: 3 (max 6).', 'stifli-flex-mcp' ); ?></p>
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
			'api_key'    => self::encrypt_value( sanitize_text_field( wp_unslash( $_POST['api_key'] ?? '' ) ) ),
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
			'max_history_turns'  => absint( $_POST['max_history_turns'] ?? 10 ),
			'max_tools_per_turn' => absint( $_POST['max_tools_per_turn'] ?? 10 ),
			'temperature'        => floatval( $_POST['temperature'] ?? 0.7 ),
			'max_tokens'         => absint( $_POST['max_tokens'] ?? 4096 ),
			'top_p'              => floatval( $_POST['top_p'] ?? 1.0 ),
			'frequency_penalty'  => floatval( $_POST['frequency_penalty'] ?? 0 ),
			'presence_penalty'   => floatval( $_POST['presence_penalty'] ?? 0 ),
			'enable_suggestions' => ! empty( $_POST['enable_suggestions'] ),
			'suggestions_count'  => absint( $_POST['suggestions_count'] ?? 3 ),
		);

		// Validate ranges
		$advanced['temperature']       = max( 0, min( 2, $advanced['temperature'] ) );
		$advanced['max_tokens']        = max( 100, min( 128000, $advanced['max_tokens'] ) );
		$advanced['top_p']             = max( 0, min( 1, $advanced['top_p'] ) );
		$advanced['frequency_penalty'] = max( 0, min( 2, $advanced['frequency_penalty'] ) );
		$advanced['presence_penalty']  = max( 0, min( 2, $advanced['presence_penalty'] ) );
		$advanced['max_tools_per_turn'] = max( 1, min( 50, $advanced['max_tools_per_turn'] ) );
		$advanced['max_history_turns'] = max( 1, min( 100, $advanced['max_history_turns'] ) );
		$advanced['suggestions_count']  = max( 1, min( 6, $advanced['suggestions_count'] ) );

		update_option( self::OPTION_NAME . '_advanced', $advanced );

		// Also update provider/model if sent from advanced tab
		if ( isset( $_POST['adv_provider'] ) && isset( $_POST['adv_model'] ) ) {
			$settings = $this->get_settings();
			$settings['provider'] = sanitize_text_field( wp_unslash( $_POST['adv_provider'] ) );
			$settings['model']    = sanitize_text_field( wp_unslash( $_POST['adv_model'] ) );
			// Re-encrypt the API key before saving back.
			$settings['api_key'] = self::encrypt_value( $settings['api_key'] );
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
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON data must be decoded raw, then sanitized after parsing
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
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON data must be decoded raw, then sanitized after parsing
		$conversation = isset( $_POST['conversation'] ) ? json_decode( wp_unslash( $_POST['conversation'] ), true ) : array();
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON data must be decoded raw, then sanitized after parsing
		$tool_result   = isset( $_POST['tool_result'] ) ? json_decode( wp_unslash( $_POST['tool_result'] ), true ) : null;

		// Get advanced settings for model parameters
		$advanced = $this->get_advanced_settings();

		if ( empty( $api_key ) ) {
			wp_send_json_error( array( 'message' => __( 'API Key is required', 'stifli-flex-mcp' ) ) );
		}

		// Trim history sent to the provider to control payload size.
		$max_history_turns = absint( $advanced['max_history_turns'] ?? 10 );
		$conversation = $this->trim_conversation_history( $conversation, $max_history_turns, $tool_result );

		// Get MCP tools (always full JSON Schemas)
		$tools = $this->get_mcp_tools();

		// Initialize the appropriate provider handler
		$handler = $this->get_provider_handler( $provider );
		if ( ! $handler ) {
			wp_send_json_error( array( 'message' => __( 'Invalid provider', 'stifli-flex-mcp' ) ) );
		}

		// Build system prompt with suggestions instruction if enabled
		$system_prompt = $advanced['system_prompt'];
		if ( ! empty( $advanced['enable_suggestions'] ) ) {
			$count = intval( $advanced['suggestions_count'] );
			$system_prompt .= "\n\n" . sprintf(
				/* translators: %d is the number of suggestions to provide */
				__( 'SUGGESTIONS: At the end of your response, provide exactly %d short follow-up questions or actions the user might want to take next. Format them EXACTLY like this, one per line:
[SUGGESTION] First suggestion here
[SUGGESTION] Second suggestion here
[SUGGESTION] Third suggestion here
Keep each suggestion under 50 characters. Only include the suggestions at the very end of your response. IMPORTANT: Write the suggestions in the same language the user is using in their messages.', 'stifli-flex-mcp' ),
				$count
			);
		}

		// Add instruction to execute tools one at a time for better reliability
		$system_prompt .= "\n\n" . __( 'IMPORTANT: When using tools, execute only ONE tool at a time. Wait for the result before deciding if you need another tool. Never call multiple tools in parallel.', 'stifli-flex-mcp' );

		// Send message to AI with advanced parameters
		$result = $handler->send_message( array(
			'api_key'       => $api_key,
			'model'         => $model,
			'message'       => $message,
			'conversation'  => $conversation,
			'tools'         => $tools,
			'tool_result'   => $tool_result,
			'system_prompt' => $system_prompt,
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
			$name = $tool['name'];
			$desc = $tool['description'] ?? '';
			$schema = $tool['inputSchema'] ?? array( 'type' => 'object', 'properties' => new stdClass() );

			$formatted[] = array(
				'name'        => $name,
				'description' => $desc,
				'inputSchema' => $schema,
			);
		}

		stifli_flex_mcp_log( sprintf( '[Client] Tools payload mode=full tools=%d', count( $formatted ) ) );
		return $formatted;
	}

	/**
	 * Trim conversation history to a maximum number of recent turns.
	 *
	 * A "tool cycle" counts as one turn: the assistant's tool_use/function_call plus its
	 * corresponding tool_result/function_call_output. Plain text messages (user/assistant)
	 * are kept freely and don't count toward the limit.
	 *
	 * The cut point is always placed at a safe boundary — just before a plain-text user
	 * message — so orphaned tool_result/function_call_output references are impossible.
	 *
	 * @param mixed $conversation Conversation array.
	 * @param int   $max_turns Max tool cycles to keep.
	 * @param mixed $tool_result Optional tool_result payload (unused, kept for signature compat).
	 * @return array
	 */
	private function trim_conversation_history( $conversation, $max_turns, $tool_result = null ) {
		if ( ! is_array( $conversation ) ) {
			return array();
		}

		$conversation = array_values( $conversation );
		$max_turns = absint( $max_turns );
		if ( $max_turns <= 0 ) {
			$max_turns = 10;
		}

		$orig_count = count( $conversation );
		if ( $orig_count === 0 ) {
			return array();
		}

		// Walk backwards counting tool cycles. Each tool_result / function_call_output /
		// functionResponse counts as one cycle. When we exceed max_turns, we know we
		// need to trim everything before a safe boundary.
		$tool_count  = 0;
		$cut_index   = 0; // default: keep everything

		for ( $i = $orig_count - 1; $i >= 0; $i-- ) {
			$msg = $conversation[ $i ];
			if ( ! is_array( $msg ) ) {
				continue;
			}

			$tool_count += $this->count_tool_results_in_message( $msg );

			if ( $tool_count > $max_turns ) {
				// Find the nearest safe boundary at or after $i: a plain-text user message.
				$cut_index = $this->find_safe_cut_point( $conversation, $i, $orig_count );
				break;
			}
		}

		$trimmed = array_slice( $conversation, $cut_index );
		$new_count = count( $trimmed );
		if ( $new_count !== $orig_count ) {
			stifli_flex_mcp_log( sprintf( '[Client] Trimmed conversation %d -> %d (max_tool_cycles=%d)', $orig_count, $new_count, $max_turns ) );
		}
		return $trimmed;
	}

	/**
	 * Count how many tool result items a single conversation message contains.
	 *
	 * Handles all three provider formats:
	 * - Claude: user message with content[] containing tool_result blocks
	 * - OpenAI: item with type = function_call_output
	 * - Gemini: user/model message with parts[] containing functionResponse
	 *
	 * @param array $msg Conversation message.
	 * @return int Number of tool results in this message.
	 */
	private function count_tool_results_in_message( $msg ) {
		$count = 0;

		// OpenAI Responses API: each function_call_output is one tool result.
		if ( ( $msg['type'] ?? '' ) === 'function_call_output' ) {
			return 1;
		}

		// Claude: content is array of blocks.
		$content = $msg['content'] ?? null;
		if ( is_array( $content ) ) {
			foreach ( $content as $block ) {
				if ( is_array( $block ) && ( $block['type'] ?? '' ) === 'tool_result' ) {
					$count++;
				}
			}
		}

		// Gemini: parts is array of parts.
		$parts = $msg['parts'] ?? null;
		if ( is_array( $parts ) ) {
			foreach ( $parts as $part ) {
				if ( is_array( $part ) && isset( $part['functionResponse'] ) ) {
					$count++;
				}
			}
		}

		return $count;
	}

	/**
	 * Find the nearest safe cut point at or after $from.
	 *
	 * A safe cut point is a plain-text user message — one that does NOT contain
	 * tool_result (Claude), function_call_output (OpenAI), or functionResponse (Gemini).
	 * Cutting here guarantees no orphaned tool references.
	 *
	 * @param array $conversation Full conversation array.
	 * @param int   $from Start searching from this index.
	 * @param int   $total Total conversation length.
	 * @return int The index to slice from (0 = keep all if no safe point found).
	 */
	private function find_safe_cut_point( $conversation, $from, $total ) {
		for ( $i = $from; $i < $total; $i++ ) {
			$msg = $conversation[ $i ];
			if ( ! is_array( $msg ) ) {
				continue;
			}

			// Skip OpenAI function_call and function_call_output items.
			$type = $msg['type'] ?? '';
			if ( $type === 'function_call' || $type === 'function_call_output' ) {
				continue;
			}

			// Must be a user-role message.
			if ( ( $msg['role'] ?? '' ) !== 'user' ) {
				continue;
			}

			// Reject if it contains any tool_result blocks (Claude).
			$content = $msg['content'] ?? null;
			if ( is_array( $content ) ) {
				$has_tool_result = false;
				foreach ( $content as $block ) {
					if ( is_array( $block ) && ( $block['type'] ?? '' ) === 'tool_result' ) {
						$has_tool_result = true;
						break;
					}
				}
				if ( $has_tool_result ) {
					continue;
				}
			}

			// Reject if it contains any functionResponse parts (Gemini).
			$parts = $msg['parts'] ?? null;
			if ( is_array( $parts ) ) {
				$has_func_response = false;
				foreach ( $parts as $part ) {
					if ( is_array( $part ) && isset( $part['functionResponse'] ) ) {
						$has_func_response = true;
						break;
					}
				}
				if ( $has_func_response ) {
					continue;
				}
			}

			// This is a safe plain-text user message.
			return $i;
		}

		// No safe cut point found — keep the entire conversation to avoid breaking tool chains.
		return 0;
	}

	/**
	 * Shorten text without breaking multibyte safety too much.
	 *
	 * @param string $text Text.
	 * @param int    $max_len Max length.
	 * @return string
	 */
	private function shorten_text( $text, $max_len ) {
		$text = trim( (string) $text );
		$max_len = intval( $max_len );
		if ( $max_len <= 0 || $text === '' ) {
			return $text;
		}
		if ( function_exists( 'mb_strlen' ) && function_exists( 'mb_substr' ) ) {
			if ( mb_strlen( $text ) <= $max_len ) {
				return $text;
			}
			return mb_substr( $text, 0, $max_len - 1 ) . '…';
		}
		if ( strlen( $text ) <= $max_len ) {
			return $text;
		}
		return substr( $text, 0, $max_len - 1 ) . '…';
	}

	/**
	 * Compact a JSON Schema by stripping verbose keys like descriptions.
	 * Keeps structure needed for tool calling (type/properties/required/enum/items/etc).
	 *
	 * @param mixed $schema Schema.
	 * @return mixed
	 */
	private function compact_json_schema( $schema ) {
		if ( is_object( $schema ) ) {
			$schema = json_decode( wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ), true );
		}
		if ( ! is_array( $schema ) ) {
			return $schema;
		}

		$strip_keys = array(
			'description',
			'title',
			'examples',
			'example',
			'default',
			'$comment',
			'deprecated',
			'readOnly',
			'writeOnly',
		);
		foreach ( $strip_keys as $k ) {
			if ( array_key_exists( $k, $schema ) ) {
				unset( $schema[ $k ] );
			}
		}

		foreach ( $schema as $k => $v ) {
			if ( is_array( $v ) || is_object( $v ) ) {
				$schema[ $k ] = $this->compact_json_schema( $v );
			}
		}

		if ( ! isset( $schema['type'] ) ) {
			$schema['type'] = 'object';
		}
		if ( ! isset( $schema['properties'] ) ) {
			$schema['properties'] = new stdClass();
		}

		return $schema;
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
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON data must be decoded raw, then sanitized after parsing
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
