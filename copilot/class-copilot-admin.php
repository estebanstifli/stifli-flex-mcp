<?php
/**
 * AI Copilot — Contextual floating assistant for the WordPress admin.
 *
 * Appears as a small bubble on every admin page, detects the current
 * screen context (post editor, product editor, media library, etc.)
 * and injects that context into the system prompt so the AI can give
 * page-aware suggestions.
 *
 * Reuses the same provider / API-key / model configured in AI Chat Agent.
 *
 * @package StifliFlexMcp
 * @since 2.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class StifliFlexMcp_Copilot_Admin {

	/** Asset version — bump when JS/CSS change. */
	const ASSET_VERSION = '1.0.0';

	/** Nonce action used by all Copilot AJAX calls. */
	const NONCE_ACTION = 'sflmcp_copilot';

	/* ----------------------------------------------------------
	 * Bootstrap
	 * ---------------------------------------------------------- */

	public function __construct() {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_footer',          array( $this, 'render_widget' ) );

		// AJAX.
		add_action( 'wp_ajax_sflmcp_copilot_chat', array( $this, 'ajax_chat' ) );
		add_action( 'wp_ajax_sflmcp_copilot_execute_tool', array( $this, 'ajax_execute_tool' ) );
	}

	/* ----------------------------------------------------------
	 * Enqueue JS / CSS on every admin page
	 * ---------------------------------------------------------- */

	/**
	 * @param string $hook Current admin page hook suffix.
	 */
	public function enqueue_assets( $hook ) {

		// Only load when the user has configured an API key.
		$settings = $this->get_chat_settings();
		if ( empty( $settings['api_key'] ) ) {
			return;
		}

		wp_enqueue_style(
			'sflmcp-copilot',
			plugin_dir_url( __FILE__ ) . 'assets/copilot.css',
			array(),
			self::ASSET_VERSION
		);

		wp_enqueue_script(
			'sflmcp-copilot',
			plugin_dir_url( __FILE__ ) . 'assets/copilot.js',
			array( 'jquery' ),
			self::ASSET_VERSION,
			true
		);

		// Build minimal quick-actions map per screen (JS will pick the right ones).
		$screen = get_current_screen();

		wp_localize_script( 'sflmcp-copilot', 'sflmcpCopilot', array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( self::NONCE_ACTION ),
			'screen'  => array(
				'id'       => $screen ? $screen->id : '',
				'base'     => $screen ? $screen->base : '',
				'postType' => $screen ? ( $screen->post_type ?: '' ) : '',
				'taxonomy' => $screen ? ( $screen->taxonomy ?: '' ) : '',
			),
			'i18n' => array(
				'placeholder' => __( 'Ask Copilot…', 'stifli-flex-mcp' ),
				'send'        => __( 'Send', 'stifli-flex-mcp' ),
				'close'       => __( 'Close', 'stifli-flex-mcp' ),
				'thinking'    => __( 'Thinking…', 'stifli-flex-mcp' ),
				'error'       => __( 'Error:', 'stifli-flex-mcp' ),
				'noApiKey'    => __( 'Configure your API key in AI Chat Agent settings first.', 'stifli-flex-mcp' ),
				'title'       => __( 'AI Copilot', 'stifli-flex-mcp' ),
			),
		) );
	}

	/* ----------------------------------------------------------
	 * Render the widget HTML in the footer of every admin page
	 * ---------------------------------------------------------- */

	public function render_widget() {
		$settings = $this->get_chat_settings();
		if ( empty( $settings['api_key'] ) ) {
			return;
		}
		?>
		<div id="sflmcp-copilot-widget" class="sflmcp-copilot-widget" style="display:none;">
			<!-- Toggle bubble -->
			<button type="button" id="sflmcp-copilot-toggle" class="sflmcp-copilot-toggle" aria-label="<?php esc_attr_e( 'Toggle AI Copilot', 'stifli-flex-mcp' ); ?>">
				<span class="sflmcp-copilot-icon">
					<svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
						<path d="M12 2C6.48 2 2 6.48 2 12c0 1.54.36 2.98.97 4.29L2 22l5.71-.97A9.96 9.96 0 0012 22c5.52 0 10-4.48 10-10S17.52 2 12 2zm-1 15h-1.5v-1.5H11V17zm2.07-4.75l-.9.92c-.5.51-.82.91-.82 1.91h-1.5v-.38c0-.76.32-1.44.82-1.94l1.24-1.26c.25-.26.38-.6.38-.96A1.51 1.51 0 0010.8 9.5c-.84 0-1.5.68-1.5 1.5H7.8c0-1.66 1.34-3 3-3s3 1.34 3 3c0 .66-.27 1.26-.73 1.75z" fill="currentColor"/>
					</svg>
				</span>
			</button>

			<!-- Chat panel -->
			<div id="sflmcp-copilot-panel" class="sflmcp-copilot-panel">
				<div class="sflmcp-copilot-header">
					<span class="sflmcp-copilot-title"><?php esc_html_e( 'AI Copilot', 'stifli-flex-mcp' ); ?></span>
					<button type="button" id="sflmcp-copilot-close" class="sflmcp-copilot-close" aria-label="<?php esc_attr_e( 'Close', 'stifli-flex-mcp' ); ?>">&times;</button>
				</div>

				<!-- Quick action chips (injected by JS based on context) -->
				<div id="sflmcp-copilot-actions" class="sflmcp-copilot-actions"></div>

				<div id="sflmcp-copilot-messages" class="sflmcp-copilot-messages"></div>

				<div class="sflmcp-copilot-input-area">
					<textarea id="sflmcp-copilot-input" class="sflmcp-copilot-input" rows="1" placeholder="<?php esc_attr_e( 'Ask Copilot…', 'stifli-flex-mcp' ); ?>"></textarea>
					<button type="button" id="sflmcp-copilot-send" class="sflmcp-copilot-send" aria-label="<?php esc_attr_e( 'Send', 'stifli-flex-mcp' ); ?>">
						<svg width="16" height="16" viewBox="0 0 24 24" fill="none"><path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z" fill="currentColor"/></svg>
					</button>
				</div>
			</div>
		</div>
		<?php
	}

	/* ----------------------------------------------------------
	 * AJAX: Chat
	 * ---------------------------------------------------------- */

	public function ajax_chat() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied', 'stifli-flex-mcp' ) ) );
		}

		$message      = sanitize_textarea_field( wp_unslash( $_POST['message'] ?? '' ) );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON data must be decoded raw
		$page_context = isset( $_POST['page_context'] ) ? json_decode( wp_unslash( $_POST['page_context'] ), true ) : array();
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON data must be decoded raw
		$conversation = isset( $_POST['conversation'] ) ? json_decode( wp_unslash( $_POST['conversation'] ), true ) : array();
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON data must be decoded raw
		$tool_result  = isset( $_POST['tool_result'] ) ? json_decode( wp_unslash( $_POST['tool_result'] ), true ) : null;

		// Either a message or a tool result is required.
		if ( empty( $message ) && empty( $tool_result ) ) {
			wp_send_json_error( array( 'message' => __( 'Message is required', 'stifli-flex-mcp' ) ) );
		}

		// Reuse the AI Chat Agent settings.
		$settings = $this->get_chat_settings();
		$advanced = $this->get_advanced_settings();

		if ( empty( $settings['api_key'] ) ) {
			wp_send_json_error( array( 'message' => __( 'API Key is required. Configure it in AI Chat Agent settings.', 'stifli-flex-mcp' ) ) );
		}

		$handler = $this->get_provider_handler( $settings['provider'] );
		if ( ! $handler ) {
			wp_send_json_error( array( 'message' => __( 'Invalid provider', 'stifli-flex-mcp' ) ) );
		}

		// Build context-aware system prompt.
		$system_prompt = $this->build_system_prompt( $page_context, $advanced['system_prompt'] );

		// Get MCP tools (same set as the full chat) for context-based actions.
		$tools = $this->get_mcp_tools();

		// Trim conversation to last 6 tool cycles to keep the copilot lightweight.
		$conversation = $this->trim_conversation( $conversation, 6 );

		$result = $handler->send_message( array(
			'api_key'          => $settings['api_key'],
			'model'            => $settings['model'],
			'message'          => $message,
			'conversation'     => $conversation,
			'tools'            => $tools,
			'tool_result'      => $tool_result,
			'system_prompt'    => $system_prompt,
			'temperature'      => $advanced['temperature'],
			'max_tokens'       => min( (int) $advanced['max_tokens'], 2048 ), // cap for copilot
			'top_p'            => $advanced['top_p'],
			'frequency_penalty' => $advanced['frequency_penalty'],
			'presence_penalty'  => $advanced['presence_penalty'],
			'explicit_caching'  => ! empty( $advanced['explicit_caching'] ),
		) );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( $result );
	}

	/* ----------------------------------------------------------
	 * System prompt builder
	 * ---------------------------------------------------------- */

	/**
	 * Build a system prompt that includes the current admin page context.
	 *
	 * @param array  $ctx    Page context sent from JS.
	 * @param string $base   Base system prompt from advanced settings.
	 * @return string
	 */
	private function build_system_prompt( $ctx, $base ) {
		$site_name = get_bloginfo( 'name' );
		$site_url  = get_bloginfo( 'url' );

		$parts = array();
		$parts[] = sprintf(
			'You are AI Copilot for the WordPress site "%s" (%s). You are a contextual assistant that appears on every admin page. Be concise and actionable — this is a quick-help widget, not a long conversation.',
			$site_name,
			$site_url
		);

		// Add user-configured base prompt if any.
		if ( ! empty( $base ) ) {
			$parts[] = $base;
		}

		// Inject page-specific context.
		if ( ! empty( $ctx ) ) {
			$parts[] = $this->format_page_context( $ctx );
		}

		$parts[] = 'You have access to MCP tools to read and modify WordPress/WooCommerce data. Use them when the user asks you to make changes. IMPORTANT: Execute only ONE tool at a time.';
		$parts[] = 'Answer in the same language the user writes in.';

		return implode( "\n\n", array_filter( $parts ) );
	}

	/**
	 * Convert the JS-collected page context into a human-readable block.
	 *
	 * @param array $ctx Associative array of context data.
	 * @return string
	 */
	private function format_page_context( $ctx ) {
		$screen_id = sanitize_text_field( $ctx['screen'] ?? '' );
		$lines     = array( '--- CURRENT ADMIN PAGE CONTEXT ---' );
		$lines[]   = 'Screen: ' . $screen_id;

		// Post editor context.
		if ( ! empty( $ctx['post'] ) && is_array( $ctx['post'] ) ) {
			$p = $ctx['post'];
			$lines[] = sprintf( 'Editing %s (#%s): "%s"',
				sanitize_text_field( $p['post_type'] ?? 'post' ),
				absint( $p['id'] ?? 0 ),
				sanitize_text_field( $p['title'] ?? '' )
			);
			if ( ! empty( $p['content'] ) ) {
				// Truncate content to ~2000 chars to save tokens.
				$content = wp_strip_all_tags( wp_unslash( $p['content'] ) );
				if ( mb_strlen( $content ) > 2000 ) {
					$content = mb_substr( $content, 0, 2000 ) . '… [truncated]';
				}
				$lines[] = 'Content: ' . $content;
			}
			if ( ! empty( $p['excerpt'] ) ) {
				$lines[] = 'Excerpt: ' . sanitize_text_field( $p['excerpt'] );
			}
			if ( ! empty( $p['categories'] ) ) {
				$lines[] = 'Categories: ' . sanitize_text_field( $p['categories'] );
			}
			if ( ! empty( $p['tags'] ) ) {
				$lines[] = 'Tags: ' . sanitize_text_field( $p['tags'] );
			}
			if ( ! empty( $p['status'] ) ) {
				$lines[] = 'Status: ' . sanitize_text_field( $p['status'] );
			}
			if ( ! empty( $p['slug'] ) ) {
				$lines[] = 'Slug: ' . sanitize_text_field( $p['slug'] );
			}
		}

		// WooCommerce product context.
		if ( ! empty( $ctx['product'] ) && is_array( $ctx['product'] ) ) {
			$pr = $ctx['product'];
			$lines[] = sprintf( 'WooCommerce Product (#%s): "%s"',
				absint( $pr['id'] ?? 0 ),
				sanitize_text_field( $pr['name'] ?? '' )
			);
			if ( isset( $pr['price'] ) ) {
				$lines[] = 'Price: ' . sanitize_text_field( $pr['price'] );
			}
			if ( isset( $pr['sku'] ) ) {
				$lines[] = 'SKU: ' . sanitize_text_field( $pr['sku'] );
			}
			if ( isset( $pr['stock'] ) ) {
				$lines[] = 'Stock: ' . sanitize_text_field( $pr['stock'] );
			}
			if ( ! empty( $pr['short_description'] ) ) {
				$desc = wp_strip_all_tags( wp_unslash( $pr['short_description'] ) );
				$lines[] = 'Short description: ' . mb_substr( $desc, 0, 500 );
			}
		}

		// WooCommerce order context.
		if ( ! empty( $ctx['order'] ) && is_array( $ctx['order'] ) ) {
			$o = $ctx['order'];
			$lines[] = sprintf( 'WooCommerce Order #%s — Status: %s — Total: %s',
				absint( $o['id'] ?? 0 ),
				sanitize_text_field( $o['status'] ?? '' ),
				sanitize_text_field( $o['total'] ?? '' )
			);
			if ( ! empty( $o['items'] ) ) {
				$lines[] = 'Items: ' . sanitize_text_field( $o['items'] );
			}
		}

		// Media library context.
		if ( ! empty( $ctx['media'] ) && is_array( $ctx['media'] ) ) {
			$lines[] = 'Media Library — selected items: ' . absint( $ctx['media']['count'] ?? 0 );
		}

		// Comments context.
		if ( ! empty( $ctx['comments'] ) && is_array( $ctx['comments'] ) ) {
			$lines[] = sprintf( 'Comments page — pending: %s',
				absint( $ctx['comments']['pending'] ?? 0 )
			);
		}

		// Plugins context.
		if ( ! empty( $ctx['plugins'] ) && is_array( $ctx['plugins'] ) ) {
			$lines[] = sprintf( 'Plugins page — active: %s, inactive: %s',
				absint( $ctx['plugins']['active'] ?? 0 ),
				absint( $ctx['plugins']['inactive'] ?? 0 )
			);
		}

		// Generic screen info when nothing specific was detected.
		if ( count( $lines ) <= 2 ) {
			$lines[] = 'No specific editable content detected on this page.';
		}

		$lines[] = '--- END CONTEXT ---';
		return implode( "\n", $lines );
	}

	/* ----------------------------------------------------------
	 * Helpers — reuse AI Chat Agent config
	 * ---------------------------------------------------------- */

	/**
	 * Get the AI Chat Agent settings (provider, api_key, model).
	 *
	 * @return array
	 */
	private function get_chat_settings() {
		$defaults = array(
			'provider'   => 'openai',
			'api_key'    => '',
			'model'      => '',
			'permission' => 'ask',
		);

		$settings = get_option( 'sflmcp_client_settings', array() );
		$settings = wp_parse_args( $settings, $defaults );

		// Decrypt the API key.
		$settings['api_key'] = StifliFlexMcp_Client_Admin::decrypt_value( $settings['api_key'] );

		return $settings;
	}

	/**
	 * Get advanced settings from AI Chat Agent.
	 *
	 * @return array
	 */
	private function get_advanced_settings() {
		$defaults = array(
			'system_prompt'     => '',
			'temperature'       => 0.7,
			'max_tokens'        => 4096,
			'top_p'             => 1,
			'frequency_penalty' => 0,
			'presence_penalty'  => 0,
			'explicit_caching'  => true,
		);

		$settings = get_option( 'sflmcp_client_settings_advanced', array() );
		return wp_parse_args( $settings, $defaults );
	}

	/**
	 * Instantiate the active provider handler.
	 *
	 * @param string $provider Provider slug.
	 * @return StifliFlexMcp_Client_Provider_Base|null
	 */
	private function get_provider_handler( $provider ) {
		$map = array(
			'openai' => 'StifliFlexMcp_Client_OpenAI',
			'claude' => 'StifliFlexMcp_Client_Claude',
			'gemini' => 'StifliFlexMcp_Client_Gemini',
		);

		if ( ! isset( $map[ $provider ] ) || ! class_exists( $map[ $provider ] ) ) {
			return null;
		}

		return new $map[ $provider ]();
	}

	/**
	 * Get the full MCP tool list.
	 *
	 * @return array
	 */
	private function get_mcp_tools() {
		global $stifliFlexMcp;

		if ( ! isset( $stifliFlexMcp, $stifliFlexMcp->model ) ) {
			return array();
		}

		$all   = $stifliFlexMcp->model->getToolsList();
		$tools = array();
		foreach ( $all as $tool ) {
			$tools[] = array(
				'name'        => $tool['name'],
				'description' => $tool['description'] ?? '',
				'inputSchema' => $tool['inputSchema'] ?? array( 'type' => 'object', 'properties' => new stdClass() ),
			);
		}
		return $tools;
	}

	/**
	 * Simple conversation trimmer — keeps the last N tool cycles.
	 *
	 * @param mixed $conversation Conversation array.
	 * @param int   $max_cycles   Max tool cycles to keep.
	 * @return array
	 */
	private function trim_conversation( $conversation, $max_cycles = 6 ) {
		if ( ! is_array( $conversation ) ) {
			return array();
		}

		// Quick limit: at most 30 messages total for the copilot.
		if ( count( $conversation ) > 30 ) {
			$conversation = array_slice( $conversation, -30 );
		}

		return array_values( $conversation );
	}

	/* ----------------------------------------------------------
	 * AJAX: Execute MCP Tool (copilot-specific nonce)
	 * ---------------------------------------------------------- */

	public function ajax_execute_tool() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied', 'stifli-flex-mcp' ) ) );
		}

		$tool_name = sanitize_text_field( wp_unslash( $_POST['tool_name'] ?? '' ) );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON data must be decoded raw
		$arguments = isset( $_POST['arguments'] ) ? json_decode( wp_unslash( $_POST['arguments'] ), true ) : array();

		if ( empty( $tool_name ) ) {
			wp_send_json_error( array( 'message' => __( 'Tool name is required', 'stifli-flex-mcp' ) ) );
		}

		global $stifliFlexMcp;

		if ( ! isset( $stifliFlexMcp, $stifliFlexMcp->model ) ) {
			wp_send_json_error( array( 'message' => __( 'MCP model not available', 'stifli-flex-mcp' ) ) );
		}

		$result = $stifliFlexMcp->model->dispatchTool( $tool_name, $arguments, null );

		if ( isset( $result['error'] ) ) {
			wp_send_json_error( array( 'message' => $result['error']['message'] ?? __( 'Tool execution failed', 'stifli-flex-mcp' ) ) );
		}

		wp_send_json_success( $result['result'] ?? $result );
	}
}
