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
	const ASSET_VERSION = '1.1.8';

	/** Nonce action used by all Copilot AJAX calls. */
	const NONCE_ACTION = 'sflmcp_copilot';

	/** Option key for Copilot-specific settings. */
	const OPTION_KEY = 'sflmcp_copilot_options';

	/** Option key for WebMCP settings. */
	const WEBMCP_OPTION_KEY = 'sflmcp_copilot_webmcp';

	/* ----------------------------------------------------------
	 * Bootstrap
	 * ---------------------------------------------------------- */

	public function __construct() {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_footer',          array( $this, 'render_widget' ) );

		// Admin menu — add "AI Copilot" submenu below MCP Server.
		add_action( 'admin_menu', array( $this, 'register_admin_menu' ), 22 );

		// AJAX.
		add_action( 'wp_ajax_sflmcp_copilot_chat', array( $this, 'ajax_chat' ) );
		add_action( 'wp_ajax_sflmcp_copilot_execute_tool', array( $this, 'ajax_execute_tool' ) );
		add_action( 'wp_ajax_sflmcp_copilot_save_settings', array( $this, 'ajax_save_settings' ) );
		add_action( 'wp_ajax_sflmcp_copilot_save_webmcp', array( $this, 'ajax_save_webmcp' ) );
	}

	/* ----------------------------------------------------------
	 * Copilot settings
	 * ---------------------------------------------------------- */

	/**
	 * Get Copilot settings with defaults.
	 *
	 * @return array { enabled: bool, tools_mode: 'local'|'local_mcp_subset'|'local_mcp_full' }
	 */
	public function get_copilot_settings() {
		$defaults = array(
			'enabled'    => true,
			'tools_mode' => 'local', // 'local' | 'local_mcp_subset' | 'local_mcp_full'
		);
		$saved = get_option( self::OPTION_KEY, array() );
		return wp_parse_args( $saved, $defaults );
	}

	/**
	 * Get WebMCP settings with defaults.
	 *
	 * @return array { enabled: bool }
	 */
	public function get_webmcp_settings() {
		$defaults = array(
			'enabled'        => false,
			'language'       => 'en',
			'system_prompt'  => '',
			'disabled_tools' => array(),
		);
		$saved = get_option( self::WEBMCP_OPTION_KEY, array() );
		$settings = wp_parse_args( $saved, $defaults );
		if ( ! is_array( $settings['disabled_tools'] ) ) {
			$settings['disabled_tools'] = array();
		}
		return $settings;
	}

	/* ----------------------------------------------------------
	 * Enqueue JS / CSS on every admin page
	 * ---------------------------------------------------------- */

	/**
	 * @param string $hook Current admin page hook suffix.
	 */
	public function enqueue_assets( $hook ) {

		// Settings page script (needed regardless of copilot enabled state).
		if ( str_ends_with( $hook, '_page_sflmcp-copilot' ) ) {
			wp_enqueue_style(
				'sflmcp-copilot-settings',
				plugin_dir_url( __FILE__ ) . 'assets/copilot-settings.css',
				array(),
				self::ASSET_VERSION
			);
			wp_enqueue_script(
				'sflmcp-copilot-settings',
				plugin_dir_url( __FILE__ ) . 'assets/copilot-settings.js',
				array( 'jquery' ),
				self::ASSET_VERSION,
				true
			);
		}

		// Only load when the user has configured an API key.
		$settings = $this->get_chat_settings();
		$has_api_key = ! empty( $settings['api_key'] );

		// WebMCP bridge — loads on editor pages even without API key (uses browser AI).
		$webmcp_opts = $this->get_webmcp_settings();
		$screen      = get_current_screen();
		$is_editor   = $screen && ( $screen->base === 'post' || $screen->base === 'page' );

		if ( ! empty( $webmcp_opts['enabled'] ) && $is_editor ) {
			$webmcp_deps = array( 'jquery' );
			if ( function_exists( 'register_block_type' ) ) {
				$webmcp_deps[] = 'wp-data';
				$webmcp_deps[] = 'wp-blocks';
				$webmcp_deps[] = 'wp-block-editor';
			}

			// Editor bridge is required for WebMCP tool execution.
			wp_enqueue_style(
				'sflmcp-copilot-editor',
				plugin_dir_url( __FILE__ ) . 'assets/copilot-editor.css',
				array(),
				self::ASSET_VERSION
			);

			wp_enqueue_script(
				'sflmcp-copilot-editor',
				plugin_dir_url( __FILE__ ) . 'assets/copilot-editor.js',
				$webmcp_deps,
				self::ASSET_VERSION,
				true
			);

			wp_enqueue_script(
				'sflmcp-copilot-webmcp',
				plugin_dir_url( __FILE__ ) . 'assets/copilot-webmcp.js',
				array( 'sflmcp-copilot-editor' ),
				self::ASSET_VERSION,
				true
			);
		}

		// Check if Copilot is enabled (the master toggle).
		$copilot_settings = $this->get_copilot_settings();
		$copilot_on       = ! empty( $copilot_settings['enabled'] );

		// Widget needs at least one mode: API key or WebMCP.
		$webmcp_on = ! empty( $webmcp_opts['enabled'] );
		if ( ! $copilot_on && ! $webmcp_on ) {
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

		// Editor bridge — load on post/page editor screens (Gutenberg or Classic).
		// May already be enqueued by the WebMCP bridge above.
		if ( $is_editor ) {
			if ( ! wp_style_is( 'sflmcp-copilot-editor', 'enqueued' ) ) {
				wp_enqueue_style(
					'sflmcp-copilot-editor',
					plugin_dir_url( __FILE__ ) . 'assets/copilot-editor.css',
					array( 'sflmcp-copilot' ),
					self::ASSET_VERSION
				);
			}

			if ( ! wp_script_is( 'sflmcp-copilot-editor', 'enqueued' ) ) {
				$editor_deps = array( 'jquery', 'sflmcp-copilot' );
				// Add Gutenberg dependencies when available.
				if ( function_exists( 'register_block_type' ) ) {
					$editor_deps[] = 'wp-data';
					$editor_deps[] = 'wp-blocks';
					$editor_deps[] = 'wp-block-editor';
				}

				wp_enqueue_script(
					'sflmcp-copilot-editor',
					plugin_dir_url( __FILE__ ) . 'assets/copilot-editor.js',
					$editor_deps,
					self::ASSET_VERSION,
					true
				);
			}
		}

		$webmcp_settings = $this->get_webmcp_settings();

		wp_localize_script( 'sflmcp-copilot', 'sflmcpCopilot', array(
			'ajaxUrl'              => admin_url( 'admin-ajax.php' ),
			'nonce'                => wp_create_nonce( self::NONCE_ACTION ),
			'hasApiKey'            => $has_api_key,
			'debug'                => ( defined( 'SFLMCP_DEBUG' ) && SFLMCP_DEBUG ),
			'webmcpLanguage'       => $webmcp_settings['language'],
			'webmcpSystemPrompt'   => $webmcp_settings['system_prompt'],
			'webmcpDisabledTools'  => $webmcp_settings['disabled_tools'],
			'screen'         => array(
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
		$settings         = $this->get_chat_settings();
		$copilot_settings = $this->get_copilot_settings();
		$webmcp_settings  = $this->get_webmcp_settings();

		$has_api_key    = ! empty( $settings['api_key'] );
		$copilot_on     = ! empty( $copilot_settings['enabled'] );
		$webmcp_on      = ! empty( $webmcp_settings['enabled'] );

		// Widget shows when the master toggle is enabled — regardless of API key.
		if ( ! $copilot_on && ! $webmcp_on ) {
			return;
		}

		// Determine which mode badges to show.
		$show_api_badge   = $has_api_key && $copilot_on;
		$show_webmcp_badge = $webmcp_on; // JS will hide if navigator.modelContext is absent.
		?>
		<div id="sflmcp-copilot-widget" class="sflmcp-copilot-widget"
			data-has-api="<?php echo esc_attr( $show_api_badge ? '1' : '0' ); ?>"
			data-webmcp="<?php echo esc_attr( $show_webmcp_badge ? '1' : '0' ); ?>">
			<!-- Toggle bubble -->
			<button type="button" id="sflmcp-copilot-toggle" class="sflmcp-copilot-toggle" aria-label="<?php esc_attr_e( 'Toggle AI Copilot', 'stifli-flex-mcp' ); ?>">
				<span class="sflmcp-copilot-icon">
					<svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
						<path d="M12 2C6.48 2 2 6.48 2 12c0 1.54.36 2.98.97 4.29L2 22l5.71-.97A9.96 9.96 0 0012 22c5.52 0 10-4.48 10-10S17.52 2 12 2zm-1 15h-1.5v-1.5H11V17zm2.07-4.75l-.9.92c-.5.51-.82.91-.82 1.91h-1.5v-.38c0-.76.32-1.44.82-1.94l1.24-1.26c.25-.26.38-.6.38-.96A1.51 1.51 0 0010.8 9.5c-.84 0-1.5.68-1.5 1.5H7.8c0-1.66 1.34-3 3-3s3 1.34 3 3c0 .66-.27 1.26-.73 1.75z" fill="currentColor"/>
					</svg>
				</span>
				<!-- WebMCP active dot — shown by JS when navigator.modelContext registers tools -->
				<span id="sflmcp-copilot-webmcp-dot" class="sflmcp-copilot-webmcp-dot sflmcp-hidden" title="<?php esc_attr_e( 'WebMCP active (Browser AI)', 'stifli-flex-mcp' ); ?>"></span>
			</button>

			<!-- Chat panel -->
			<div id="sflmcp-copilot-panel" class="sflmcp-copilot-panel">
				<div class="sflmcp-copilot-header">
					<span class="sflmcp-copilot-title">
						<?php esc_html_e( 'AI Copilot', 'stifli-flex-mcp' ); ?>
						<!-- Source badges — JS controls visibility -->
						<span id="sflmcp-copilot-badges" class="sflmcp-copilot-badges">
							<?php if ( $show_api_badge ) : ?>
								<span class="sflmcp-copilot-badge sflmcp-copilot-badge--api" title="<?php esc_attr_e( 'Using API key (cloud AI)', 'stifli-flex-mcp' ); ?>">
									<svg width="10" height="10" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/></svg>
									API
								</span>
							<?php endif; ?>
							<span id="sflmcp-copilot-badge-webmcp" class="sflmcp-copilot-badge sflmcp-copilot-badge--webmcp sflmcp-hidden" title="<?php esc_attr_e( 'WebMCP — Browser AI (free)', 'stifli-flex-mcp' ); ?>">
								<svg width="10" height="10" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/></svg>
								WebMCP
							</span>
						</span>
					</span>
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

		// Determine which tools to expose based on Copilot settings.
		$copilot_opts = $this->get_copilot_settings();
		$tools_mode   = $copilot_opts['tools_mode'] ?? 'local';

		$tools = array();

		// Local editor tools (always included when on an editor page).
		$local_tools = $this->get_local_editor_tools( $page_context );
		if ( ! empty( $local_tools ) ) {
			$tools = $local_tools;
		}

		if ( $tools_mode === 'local_mcp_subset' ) {
			$tools = array_merge( $tools, $this->get_mcp_tools_subset( $page_context ) );
		} elseif ( $tools_mode === 'local_mcp_full' ) {
			$tools = array_merge( $tools, $this->get_mcp_tools() );
		}

		// Always include wp_generate_image in the editor when available (needed for image tools).
		if ( ! empty( $page_context['post'] ) && $tools_mode === 'local' ) {
			$img_tool = $this->get_single_mcp_tool( 'wp_generate_image' );
			if ( $img_tool ) {
				$tools[] = $img_tool;
			}
		}

		// Trim conversation to last 6 tool cycles to keep the copilot lightweight.
		$conversation = $this->trim_conversation( $conversation, 6 );

		// Remove any orphaned tool_result messages (safety net for corrupted histories).
		$conversation = $this->sanitize_conversation( $conversation );

		$result = $handler->send_message( array(
			'api_key'          => $settings['api_key'],
			'model'            => $settings['model'],
			'message'          => $message,
			'conversation'     => $conversation,
			'tools'            => $tools,
			'tool_result'      => $tool_result,
			'system_prompt'    => $system_prompt,
			'temperature'      => $advanced['temperature'],
			'max_tokens'       => min( (int) $advanced['max_tokens'], 4096 ), // cap for copilot
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

		// Local editor tool instructions (only when on a post/page editor).
		if ( ! empty( $ctx['post'] ) ) {
			$parts[] = 'EDITOR TOOLS: You have local "copilot_*" tools that modify the editor visually (title, excerpt, slug, status, categories, tags, content, blocks, images). These are INSTANT and do NOT save to the database — the user will see the change in real-time and can Keep or Undo it. ALWAYS prefer copilot_* tools over wp_update_post or similar MCP tools when the user wants to edit the current post/page. For block operations: block_index is 0-based and corresponds to the block indices shown in the context above.';
			$parts[] = 'IMAGE WORKFLOW: To generate an image and place it in the post, use this 2-step flow: (1) Call wp_generate_image with the prompt and the current post_id — this returns attachment_id and url. (2) Then call copilot_set_featured_image (to set as featured) or copilot_insert_image_block (to place it in the content at a specific position). Example: "generate a cat image and put it after the 3rd paragraph" → wp_generate_image → copilot_insert_image_block with position=3.';
		}
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
			$editor_type = sanitize_text_field( $p['editor_type'] ?? 'unknown' );
			$lines[] = sprintf( 'Editing %s (#%s): "%s"  [editor: %s]',
				sanitize_text_field( $p['post_type'] ?? 'post' ),
				absint( $p['id'] ?? 0 ),
				sanitize_text_field( $p['title'] ?? '' ),
				$editor_type
			);

			if ( ! empty( $p['status'] ) ) {
				$lines[] = 'Status: ' . sanitize_text_field( $p['status'] );
			}
			if ( ! empty( $p['slug'] ) ) {
				$lines[] = 'Slug: ' . sanitize_text_field( $p['slug'] );
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
			if ( ! empty( $p['featured_image'] ) ) {
				$lines[] = 'Featured image: ' . esc_url( $p['featured_image'] );
			}

			// Block-level content (Gutenberg) — much richer than raw content.
			if ( ! empty( $p['blocks'] ) && is_array( $p['blocks'] ) ) {
				$lines[] = '';
				$lines[] = 'BLOCKS (' . count( $p['blocks'] ) . ' total):';
				foreach ( $p['blocks'] as $b ) {
					$idx  = absint( $b['index'] ?? 0 );
					$type = sanitize_text_field( $b['type'] ?? 'unknown' );
					$text = '';

					// Build a concise preview of the block content.
					if ( ! empty( $b['content'] ) ) {
						$text = wp_strip_all_tags( wp_unslash( $b['content'] ) );
					} elseif ( ! empty( $b['value'] ) ) {
						$text = wp_strip_all_tags( wp_unslash( $b['value'] ) );
					} elseif ( ! empty( $b['citation'] ) ) {
						$text = wp_strip_all_tags( wp_unslash( $b['citation'] ) );
					} elseif ( ! empty( $b['url'] ) ) {
						$text = esc_url( $b['url'] );
					}

					$extra = '';
					if ( ! empty( $b['level'] ) ) {
						$extra .= ' H' . absint( $b['level'] );
					}
					if ( ! empty( $b['alt'] ) ) {
						$extra .= ' alt="' . sanitize_text_field( $b['alt'] ) . '"';
					}
					if ( ! empty( $b['innerBlockCount'] ) ) {
						$extra .= ' (' . absint( $b['innerBlockCount'] ) . ' inner blocks)';
					}

					$lines[] = sprintf( '  [%d] %s%s: %s', $idx, $type, $extra, $text );
				}
			} elseif ( ! empty( $p['content'] ) ) {
				// Classic editor fallback — full plain content.
				$content = wp_strip_all_tags( wp_unslash( $p['content'] ) );
				$lines[] = 'Content: ' . $content;
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
			if ( isset( $pr['regular_price'] ) ) {
				$lines[] = 'Regular price: ' . sanitize_text_field( $pr['regular_price'] );
			}
			if ( isset( $pr['sale_price'] ) ) {
				$lines[] = 'Sale price: ' . sanitize_text_field( $pr['sale_price'] );
			}
			if ( isset( $pr['sku'] ) ) {
				$lines[] = 'SKU: ' . sanitize_text_field( $pr['sku'] );
			}
			if ( isset( $pr['stock'] ) ) {
				$lines[] = 'Stock: ' . sanitize_text_field( $pr['stock'] );
			}
			if ( isset( $pr['stock_status'] ) ) {
				$lines[] = 'Stock status: ' . sanitize_text_field( $pr['stock_status'] );
			}
			if ( isset( $pr['product_type'] ) ) {
				$lines[] = 'Product type: ' . sanitize_text_field( $pr['product_type'] );
			}
			if ( isset( $pr['weight'] ) ) {
				$lines[] = 'Weight: ' . sanitize_text_field( $pr['weight'] );
			}
			$dims = array();
			foreach ( array( 'length', 'width', 'height' ) as $dk ) {
				if ( ! empty( $pr[ $dk ] ) ) {
					$dims[] = $dk . ': ' . sanitize_text_field( $pr[ $dk ] );
				}
			}
			if ( $dims ) {
				$lines[] = 'Dimensions: ' . implode( ', ', $dims );
			}
			if ( ! empty( $pr['categories'] ) ) {
				$lines[] = 'Categories: ' . sanitize_text_field( $pr['categories'] );
			}
			if ( ! empty( $pr['tags'] ) ) {
				$lines[] = 'Tags: ' . sanitize_text_field( $pr['tags'] );
			}
			if ( ! empty( $pr['short_description'] ) ) {
				$desc = wp_strip_all_tags( wp_unslash( $pr['short_description'] ) );
				$lines[] = 'Short description: ' . $desc;
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
	 * Get local editor tool definitions (copilot_*).
	 *
	 * These tools execute in the browser (via copilot-editor.js), NOT server-side.
	 * We include them in the tool list so the AI knows about them and can call them.
	 *
	 * @param array $ctx Page context from JS.
	 * @return array Tool schemas (empty if not on an editor page).
	 */
	private function get_local_editor_tools( $ctx ) {
		// Only register editor tools when we're actually on a post editor.
		if ( empty( $ctx['post'] ) ) {
			return array();
		}

		$is_gutenberg = ( ( $ctx['post']['editor_type'] ?? '' ) === 'gutenberg' );

		$tools = array();

		$tools[] = array(
			'name'        => 'copilot_set_title',
			'description' => 'Set the post/page title in the editor. The change is visual and immediate — the user will see Keep/Undo buttons.',
			'inputSchema' => array(
				'type'       => 'object',
				'properties' => array(
					'title' => array( 'type' => 'string', 'description' => 'The new title' ),
				),
				'required' => array( 'title' ),
			),
		);

		$tools[] = array(
			'name'        => 'copilot_set_excerpt',
			'description' => 'Set the post/page excerpt in the editor.',
			'inputSchema' => array(
				'type'       => 'object',
				'properties' => array(
					'excerpt' => array( 'type' => 'string', 'description' => 'The new excerpt text' ),
				),
				'required' => array( 'excerpt' ),
			),
		);

		$tools[] = array(
			'name'        => 'copilot_set_slug',
			'description' => 'Set the post/page URL slug in the editor.',
			'inputSchema' => array(
				'type'       => 'object',
				'properties' => array(
					'slug' => array( 'type' => 'string', 'description' => 'The new slug (URL-safe)' ),
				),
				'required' => array( 'slug' ),
			),
		);

		$tools[] = array(
			'name'        => 'copilot_set_status',
			'description' => 'Change the post status (draft, publish, pending, private).',
			'inputSchema' => array(
				'type'       => 'object',
				'properties' => array(
					'status' => array( 'type' => 'string', 'enum' => array( 'draft', 'publish', 'pending', 'private' ), 'description' => 'The new status' ),
				),
				'required' => array( 'status' ),
			),
		);

		$tools[] = array(
			'name'        => 'copilot_set_categories',
			'description' => 'Set the post categories by name. Replaces current selection.',
			'inputSchema' => array(
				'type'       => 'object',
				'properties' => array(
					'categories' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'Array of category names' ),
				),
				'required' => array( 'categories' ),
			),
		);

		$tools[] = array(
			'name'        => 'copilot_set_tags',
			'description' => 'Set the post tags by name.',
			'inputSchema' => array(
				'type'       => 'object',
				'properties' => array(
					'tags' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'Array of tag names' ),
				),
				'required' => array( 'tags' ),
			),
		);

		$tools[] = array(
			'name'        => 'copilot_replace_content',
			'description' => 'Replace the entire post content. Provide full HTML or block markup. Use this when rewriting the whole content.',
			'inputSchema' => array(
				'type'       => 'object',
				'properties' => array(
					'content' => array( 'type' => 'string', 'description' => 'The new HTML content (can include block markup for Gutenberg)' ),
				),
				'required' => array( 'content' ),
			),
		);

		$tools[] = array(
			'name'        => 'copilot_find_replace',
			'description' => 'Find and replace text in the post content. Works across all blocks (Gutenberg) or the full HTML (Classic). Case-sensitive.',
			'inputSchema' => array(
				'type'       => 'object',
				'properties' => array(
					'search'  => array( 'type' => 'string', 'description' => 'The text to find' ),
					'replace' => array( 'type' => 'string', 'description' => 'The replacement text' ),
				),
				'required' => array( 'search', 'replace' ),
			),
		);

		$tools[] = array(
			'name'        => 'copilot_insert_block',
			'description' => 'Insert a new block at a given position. In Classic Editor, appends a paragraph at the end.',
			'inputSchema' => array(
				'type'       => 'object',
				'properties' => array(
					'content'    => array( 'type' => 'string', 'description' => 'Block text/HTML content' ),
					'block_type' => array( 'type' => 'string', 'description' => 'Block type (e.g. core/paragraph, core/heading, core/image, core/list). Default: core/paragraph' ),
					'position'   => array( 'type' => 'integer', 'description' => 'Insert at this 0-based block index. Omit to append at end.' ),
					'level'      => array( 'type' => 'integer', 'description' => 'Heading level (2-6) — only for core/heading blocks' ),
				),
				'required' => array( 'content' ),
			),
		);

		// Gutenberg-only block tools.
		if ( $is_gutenberg ) {
			$tools[] = array(
				'name'        => 'copilot_update_block',
				'description' => 'Update the text content of a specific block by its 0-based index. Gutenberg only.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'block_index' => array( 'type' => 'integer', 'description' => 'The 0-based block index (see BLOCKS list in context)' ),
						'content'     => array( 'type' => 'string', 'description' => 'The new text/HTML content for the block' ),
					),
					'required' => array( 'block_index', 'content' ),
				),
			);

			$tools[] = array(
				'name'        => 'copilot_delete_block',
				'description' => 'Delete a specific block by its 0-based index. Gutenberg only.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'block_index' => array( 'type' => 'integer', 'description' => 'The 0-based block index to delete' ),
					),
					'required' => array( 'block_index' ),
				),
			);
		}

		// Image tools — available in both editors.
		$tools[] = array(
			'name'        => 'copilot_set_featured_image',
			'description' => 'Set the featured image (post thumbnail) from a WordPress media attachment ID. Use after wp_generate_image to set the generated image as featured.',
			'inputSchema' => array(
				'type'       => 'object',
				'properties' => array(
					'attachment_id' => array( 'type' => 'integer', 'description' => 'The WordPress media attachment ID' ),
				),
				'required' => array( 'attachment_id' ),
			),
		);

		$tools[] = array(
			'name'        => 'copilot_insert_image_block',
			'description' => 'Insert an image into the post content at a specific position. In Gutenberg, creates an image block. In Classic, inserts an <img> tag. Use after wp_generate_image to place the image in the content.',
			'inputSchema' => array(
				'type'       => 'object',
				'properties' => array(
					'url'           => array( 'type' => 'string', 'description' => 'The image URL' ),
					'attachment_id' => array( 'type' => 'integer', 'description' => 'Optional WordPress attachment ID' ),
					'alt'           => array( 'type' => 'string', 'description' => 'Alt text for the image' ),
					'caption'       => array( 'type' => 'string', 'description' => 'Optional image caption' ),
					'position'      => array( 'type' => 'integer', 'description' => 'Block index to insert at (0-based). Omit to append at end. Gutenberg only.' ),
				),
				'required' => array( 'url' ),
			),
		);

		return $tools;
	}

	/**
	 * Get a relevant subset of MCP tools based on the current page context.
	 *
	 * @param array $ctx Page context from JS.
	 * @return array
	 */
	private function get_mcp_tools_subset( $ctx ) {
		$all = $this->get_mcp_tools();
		if ( empty( $all ) ) {
			return array();
		}

		// Define which tool prefixes are relevant per context.
		$relevant_prefixes = array(
			'mcp_ping',          // always useful
			'wp_get_posts',
			'wp_get_taxonomies',
			'wp_get_terms',
			'wp_get_media',
			'wp_get_users',
		);

		// Post editor context — add post/taxonomy/media tools.
		if ( ! empty( $ctx['post'] ) ) {
			$relevant_prefixes = array_merge( $relevant_prefixes, array(
				'wp_create_post',
				'wp_update_post',
				'wp_get_post_meta',
				'wp_set_post_meta',
				'wp_create_term',
				'wp_create_tag',
				'wp_upload',
				'wp_get_categories',
				'wp_get_tags',
				'aiwu_image',
				'wp_generate_image',
			) );
		}

		// WooCommerce product context — add product tools.
		if ( ! empty( $ctx['product'] ) ) {
			$relevant_prefixes = array_merge( $relevant_prefixes, array(
				'wc_get_products',
				'wc_update_product',
				'wc_get_product_categories',
				'wc_get_product_tags',
				'wc_create_product',
				'wp_generate_image',
			) );
		}

		// WooCommerce order context.
		if ( ! empty( $ctx['order'] ) ) {
			$relevant_prefixes = array_merge( $relevant_prefixes, array(
				'wc_get_orders',
				'wc_update_order',
				'wc_get_order_notes',
				'wc_create_order_note',
			) );
		}

		$subset = array();
		foreach ( $all as $tool ) {
			foreach ( $relevant_prefixes as $prefix ) {
				if ( strpos( $tool['name'], $prefix ) === 0 || $tool['name'] === $prefix ) {
					$subset[] = $tool;
					break;
				}
			}
		}

		return $subset;
	}

	/* ----------------------------------------------------------
	 * Admin Menu — AI Copilot settings page
	 * ---------------------------------------------------------- */

	/**
	 * Register the "AI Copilot" submenu under StifLi Flex MCP.
	 */
	public function register_admin_menu() {
		add_submenu_page(
			'stifli-flex-mcp',
			__( 'AI Copilot', 'stifli-flex-mcp' ),
			__( 'AI Copilot', 'stifli-flex-mcp' ),
			'manage_options',
			'sflmcp-copilot',
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Render the AI Copilot settings page.
	 */
	public function render_settings_page() {
		$opts = $this->get_copilot_settings();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'AI Copilot Settings', 'stifli-flex-mcp' ); ?></h1>

			<div id="sflmcp-copilot-settings-notice" class="notice notice-success is-dismissible">
				<p><?php esc_html_e( 'Settings saved.', 'stifli-flex-mcp' ); ?></p>
			</div>

			<form id="sflmcp-copilot-settings-form" method="post">
				<?php wp_nonce_field( 'sflmcp_copilot_settings', 'sflmcp_copilot_settings_nonce' ); ?>
				<table class="form-table" role="presentation">

					<!-- Enabled toggle -->
					<tr>
						<th scope="row">
							<label for="sflmcp-copilot-enabled"><?php esc_html_e( 'AI Copilot', 'stifli-flex-mcp' ); ?></label>
						</th>
						<td>
							<label>
								<input type="checkbox" id="sflmcp-copilot-enabled" name="enabled" value="1" <?php checked( $opts['enabled'] ); ?>>
								<?php esc_html_e( 'Enable the floating AI Copilot widget on all admin pages', 'stifli-flex-mcp' ); ?>
							</label>
							<p class="description">
								<?php esc_html_e( 'When disabled, the copilot bubble will not appear anywhere.', 'stifli-flex-mcp' ); ?>
							</p>
						</td>
					</tr>

					<!-- Tools mode -->
					<tr>
						<th scope="row">
							<label for="sflmcp-copilot-tools-mode"><?php esc_html_e( 'Available Tools', 'stifli-flex-mcp' ); ?></label>
						</th>
						<td>
							<select id="sflmcp-copilot-tools-mode" name="tools_mode">
								<option value="local" <?php selected( $opts['tools_mode'], 'local' ); ?>>
									<?php esc_html_e( 'Only local editor tools (by context)', 'stifli-flex-mcp' ); ?>
								</option>
								<option value="local_mcp_subset" <?php selected( $opts['tools_mode'], 'local_mcp_subset' ); ?>>
									<?php esc_html_e( 'Local + relevant MCP tools subset', 'stifli-flex-mcp' ); ?>
								</option>
								<option value="local_mcp_full" <?php selected( $opts['tools_mode'], 'local_mcp_full' ); ?>>
									<?php esc_html_e( 'Local + all MCP tools (default profile)', 'stifli-flex-mcp' ); ?>
								</option>
							</select>
							<p class="description">
								<?php esc_html_e( 'Controls which tools are available to the AI when using the Copilot.', 'stifli-flex-mcp' ); ?>
								<br>
								<strong><?php esc_html_e( 'Local only:', 'stifli-flex-mcp' ); ?></strong>
								<?php esc_html_e( 'Fast visual editor tools (title, content, blocks, categories, etc.). No server calls for tool execution. Recommended for best performance.', 'stifli-flex-mcp' ); ?>
								<br>
								<strong><?php esc_html_e( 'Local + subset:', 'stifli-flex-mcp' ); ?></strong>
								<?php esc_html_e( 'Adds a context-aware subset of MCP tools (posts, taxonomies, media, products, orders) to the local tools.', 'stifli-flex-mcp' ); ?>
								<br>
								<strong><?php esc_html_e( 'Local + all MCP:', 'stifli-flex-mcp' ); ?></strong>
								<?php esc_html_e( 'Full access to all enabled MCP tools. Uses more AI context tokens and may be slower.', 'stifli-flex-mcp' ); ?>
							</p>
						</td>
					</tr>

				</table>

				<?php submit_button( __( 'Save Settings', 'stifli-flex-mcp' ), 'primary', 'sflmcp-copilot-save' ); ?>
			</form>

			<hr>

			<?php
			$webmcp_opts = $this->get_webmcp_settings();
			?>
			<h2>
				<?php esc_html_e( 'WebMCP — Browser AI (Zero Cost)', 'stifli-flex-mcp' ); ?>
				<span class="sflmcp-webmcp-beta-badge">BETA</span>
			</h2>
			<p class="description">
				<?php esc_html_e( 'WebMCP lets Chrome\'s built-in AI (Gemini Nano) control the WordPress editor directly — no API key needed, zero cost. The browser\'s native AI discovers the editor tools and can modify titles, content, blocks, categories, tags and more.', 'stifli-flex-mcp' ); ?>
			</p>
			<p class="description sflmcp-webmcp-beta-notice">
				<strong><?php esc_html_e( 'Beta Notice:', 'stifli-flex-mcp' ); ?></strong>
				<?php esc_html_e( 'This feature uses Chrome\'s experimental Gemini Nano model which runs locally in the browser. Nano is a compact model with limited reasoning — results may vary depending on task complexity. Quality will improve as Google updates the on-device model.', 'stifli-flex-mcp' ); ?>
			</p>

			<div class="sflmcp-webmcp-requirements">
				<strong><?php esc_html_e( 'Requirements to use WebMCP:', 'stifli-flex-mcp' ); ?></strong>
				<ol>
					<li><?php esc_html_e( 'Chrome version 146.0.7672.0 or higher (Chrome Canary or Beta recommended).', 'stifli-flex-mcp' ); ?></li>
					<li><?php
						printf(
							esc_html__( 'Enable the WebMCP flag: navigate to %s and set it to "Enabled". Relaunch Chrome.', 'stifli-flex-mcp' ),
							'<code>chrome://flags/#enable-webmcp-testing</code>'
						);
					?></li>
					<li><?php
						printf(
							esc_html__( 'Enable the Prompt API: navigate to %s and set it to "Enabled". Relaunch Chrome.', 'stifli-flex-mcp' ),
							'<code>chrome://flags/#prompt-api-for-gemini-nano</code>'
						);
					?></li>
					<li><?php
						printf(
							esc_html__( 'Verify Gemini Nano is downloaded: open %s and check model availability.', 'stifli-flex-mcp' ),
							'<code>chrome://on-device-internals</code>'
						);
					?></li>
				</ol>
			</div>

			<form id="sflmcp-copilot-webmcp-form" method="post">
				<?php wp_nonce_field( 'sflmcp_copilot_webmcp', 'sflmcp_copilot_webmcp_nonce' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="sflmcp-webmcp-enabled"><?php esc_html_e( 'WebMCP Bridge', 'stifli-flex-mcp' ); ?></label>
						</th>
						<td>
							<label>
								<input type="checkbox" id="sflmcp-webmcp-enabled" name="webmcp_enabled" value="1" <?php checked( $webmcp_opts['enabled'] ); ?>>
								<?php esc_html_e( 'Expose editor tools to the browser\'s built-in AI via navigator.modelContext', 'stifli-flex-mcp' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="sflmcp-webmcp-language"><?php esc_html_e( 'AI Language', 'stifli-flex-mcp' ); ?></label>
						</th>
						<td>
							<select id="sflmcp-webmcp-language" name="webmcp_language">
								<option value="en" <?php selected( $webmcp_opts['language'], 'en' ); ?>>English</option>
								<option value="es" <?php selected( $webmcp_opts['language'], 'es' ); ?>>Español</option>
								<option value="ja" <?php selected( $webmcp_opts['language'], 'ja' ); ?>>日本語</option>
							</select>
							<p class="description">
								<?php esc_html_e( 'Input/output language for Gemini Nano. Chrome currently supports: English, Spanish, Japanese.', 'stifli-flex-mcp' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="sflmcp-webmcp-system-prompt"><?php esc_html_e( 'System Prompt', 'stifli-flex-mcp' ); ?></label>
						</th>
						<td>
							<textarea id="sflmcp-webmcp-system-prompt" name="webmcp_system_prompt" rows="12" class="large-text code"><?php echo esc_textarea( $webmcp_opts['system_prompt'] ); ?></textarea>
							<p class="description">
								<?php esc_html_e( 'Custom system prompt injected into Gemini Nano. Leave empty to use the built-in default. Use this to experiment with instructions that improve tool usage.', 'stifli-flex-mcp' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<?php esc_html_e( 'Enabled Tools', 'stifli-flex-mcp' ); ?>
						</th>
						<td>
							<p class="description sflmcp-webmcp-tools-description">
								<?php esc_html_e( 'Uncheck tools to disable them. Fewer tools = smaller system prompt = better results from Gemini Nano.', 'stifli-flex-mcp' ); ?>
							</p>
							<?php
							$all_tools = array(
								'copilot_set_title'        => __( 'Set post title', 'stifli-flex-mcp' ),
								'copilot_set_excerpt'      => __( 'Set excerpt', 'stifli-flex-mcp' ),
								'copilot_set_slug'         => __( 'Set URL slug', 'stifli-flex-mcp' ),
								'copilot_set_status'       => __( 'Change post status', 'stifli-flex-mcp' ),
								'copilot_set_categories'   => __( 'Set categories', 'stifli-flex-mcp' ),
								'copilot_set_tags'         => __( 'Set tags', 'stifli-flex-mcp' ),
								'copilot_replace_content'  => __( 'Replace entire content', 'stifli-flex-mcp' ),
								'copilot_find_replace'     => __( 'Find & replace text', 'stifli-flex-mcp' ),
								'copilot_insert_block'     => __( 'Insert new block', 'stifli-flex-mcp' ),
								'copilot_update_block'     => __( 'Update block by index', 'stifli-flex-mcp' ),
								'copilot_delete_block'     => __( 'Delete block by index', 'stifli-flex-mcp' ),
								'copilot_set_featured_image'   => __( 'Set featured image', 'stifli-flex-mcp' ),
								'copilot_insert_image_block'   => __( 'Insert image block', 'stifli-flex-mcp' ),
								'copilot_get_context'      => __( 'Read editor context', 'stifli-flex-mcp' ),
							);
							$disabled = $webmcp_opts['disabled_tools'];
							foreach ( $all_tools as $tool_name => $label ) :
								$is_disabled = in_array( $tool_name, $disabled, true );
							?>
								<label class="sflmcp-webmcp-tool-label">
									<input type="checkbox"
										class="sflmcp-webmcp-tool-check"
										data-tool="<?php echo esc_attr( $tool_name ); ?>"
										<?php checked( ! $is_disabled ); ?>>
									<code><?php echo esc_html( $tool_name ); ?></code>
									&mdash; <?php echo esc_html( $label ); ?>
								</label>
							<?php endforeach; ?>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Save WebMCP Settings', 'stifli-flex-mcp' ), 'secondary', 'sflmcp-copilot-webmcp-save' ); ?>
			</form>

			<details class="sflmcp-webmcp-details">
				<summary><?php esc_html_e( 'Registered WebMCP Tools (14)', 'stifli-flex-mcp' ); ?></summary>
				<p>
					<code>copilot_set_title</code>, <code>copilot_set_excerpt</code>, <code>copilot_set_slug</code>,
					<code>copilot_set_status</code>, <code>copilot_set_categories</code>, <code>copilot_set_tags</code>,
					<code>copilot_replace_content</code>, <code>copilot_find_replace</code>, <code>copilot_insert_block</code>,
					<code>copilot_update_block</code>, <code>copilot_delete_block</code>,
					<code>copilot_set_featured_image</code>, <code>copilot_insert_image_block</code>,
					<code>copilot_get_context</code>
				</p>
			</details>
		</div>
		<?php
	}

	/**
	 * AJAX handler to save Copilot settings.
	 */
	public function ajax_save_settings() {
		check_ajax_referer( 'sflmcp_copilot_settings', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied', 'stifli-flex-mcp' ) ) );
		}

		$enabled    = ! empty( $_POST['enabled'] );
		$tools_mode = sanitize_text_field( wp_unslash( $_POST['tools_mode'] ?? 'local' ) );

		$valid_modes = array( 'local', 'local_mcp_subset', 'local_mcp_full' );
		if ( ! in_array( $tools_mode, $valid_modes, true ) ) {
			$tools_mode = 'local';
		}

		update_option( self::OPTION_KEY, array(
			'enabled'    => $enabled,
			'tools_mode' => $tools_mode,
		) );

		wp_send_json_success();
	}

	/**
	 * AJAX handler to save WebMCP settings.
	 */
	public function ajax_save_webmcp() {
		check_ajax_referer( 'sflmcp_copilot_webmcp', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied', 'stifli-flex-mcp' ) ) );
		}

		$enabled  = ! empty( $_POST['webmcp_enabled'] );
		$language = sanitize_text_field( wp_unslash( $_POST['webmcp_language'] ?? 'en' ) );

		if ( ! in_array( $language, array( 'en', 'es', 'ja' ), true ) ) {
			$language = 'en';
		}

		$system_prompt = '';
		if ( isset( $_POST['webmcp_system_prompt'] ) ) {
			$system_prompt = sanitize_textarea_field( wp_unslash( $_POST['webmcp_system_prompt'] ) );
		}

		$disabled_tools = array();
		if ( ! empty( $_POST['webmcp_disabled_tools'] ) && is_array( $_POST['webmcp_disabled_tools'] ) ) {
			$raw_disabled_tools = wp_unslash( $_POST['webmcp_disabled_tools'] );
			foreach ( $raw_disabled_tools as $tool ) {
				$disabled_tools[] = sanitize_text_field( $tool );
			}
		}

		update_option( self::WEBMCP_OPTION_KEY, array(
			'enabled'        => $enabled,
			'language'       => $language,
			'system_prompt'  => $system_prompt,
			'disabled_tools' => $disabled_tools,
		) );

		wp_send_json_success();
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
	 * Retrieve a single MCP tool definition by name.
	 */
	private function get_single_mcp_tool( $name ) {
		global $stifliFlexMcp;

		if ( ! isset( $stifliFlexMcp, $stifliFlexMcp->model ) ) {
			return null;
		}

		$all = $stifliFlexMcp->model->getToolsList();
		foreach ( $all as $tool ) {
			if ( ( $tool['name'] ?? '' ) === $name ) {
				return array(
					'name'        => $tool['name'],
					'description' => $tool['description'] ?? '',
					'inputSchema' => $tool['inputSchema'] ?? array( 'type' => 'object', 'properties' => new stdClass() ),
				);
			}
		}
		return null;
	}

	/**
	 * Trim conversation while respecting tool_use / tool_result boundaries.
	 *
	 * Walks backwards counting tool-result cycles. Once the limit is exceeded
	 * the cut is placed at the nearest *safe* boundary — a plain-text user
	 * message — so orphaned tool_result references are impossible.
	 *
	 * @param mixed $conversation Conversation array.
	 * @param int   $max_cycles   Max tool cycles to keep.
	 * @return array
	 */
	private function trim_conversation( $conversation, $max_cycles = 6 ) {
		if ( ! is_array( $conversation ) ) {
			return array();
		}

		$conversation = array_values( $conversation );
		$total        = count( $conversation );
		if ( $total === 0 ) {
			return array();
		}

		$tool_count = 0;
		$cut_index  = 0;

		for ( $i = $total - 1; $i >= 0; $i-- ) {
			$msg = $conversation[ $i ];
			if ( ! is_array( $msg ) ) {
				continue;
			}
			$tool_count += $this->count_tool_results( $msg );

			if ( $tool_count > $max_cycles ) {
				$cut_index = $this->find_safe_cut( $conversation, $i, $total );
				break;
			}
		}

		// Hard cap: if still very large, find a safe boundary near -30.
		$trimmed = array_slice( $conversation, $cut_index );
		if ( count( $trimmed ) > 40 ) {
			$target  = count( $trimmed ) - 30;
			$safe    = $this->find_safe_cut( $trimmed, $target, count( $trimmed ) );
			$trimmed = array_slice( $trimmed, $safe );
		}

		return array_values( $trimmed );
	}

	/**
	 * Count tool-result items inside a single conversation message.
	 */
	private function count_tool_results( $msg ) {
		$count = 0;

		// OpenAI Responses API.
		if ( ( $msg['type'] ?? '' ) === 'function_call_output' ) {
			return 1;
		}

		// Claude: content[] with tool_result blocks.
		$content = $msg['content'] ?? null;
		if ( is_array( $content ) ) {
			foreach ( $content as $block ) {
				if ( is_array( $block ) && ( $block['type'] ?? '' ) === 'tool_result' ) {
					$count++;
				}
			}
		}

		// Gemini: parts[] with functionResponse.
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
	 * A safe point is a plain-text user message (no tool_result / functionResponse).
	 */
	private function find_safe_cut( $conversation, $from, $total ) {
		for ( $i = $from; $i < $total; $i++ ) {
			$msg = $conversation[ $i ];
			if ( ! is_array( $msg ) ) {
				continue;
			}

			$type = $msg['type'] ?? '';
			if ( $type === 'function_call' || $type === 'function_call_output' ) {
				continue;
			}

			if ( ( $msg['role'] ?? '' ) !== 'user' ) {
				continue;
			}

			// Reject if contains tool_result blocks (Claude).
			$content = $msg['content'] ?? null;
			if ( is_array( $content ) ) {
				$has_tr = false;
				foreach ( $content as $block ) {
					if ( is_array( $block ) && ( $block['type'] ?? '' ) === 'tool_result' ) {
						$has_tr = true;
						break;
					}
				}
				if ( $has_tr ) {
					continue;
				}
			}

			// Reject if contains functionResponse (Gemini).
			$parts = $msg['parts'] ?? null;
			if ( is_array( $parts ) ) {
				$has_fr = false;
				foreach ( $parts as $part ) {
					if ( is_array( $part ) && isset( $part['functionResponse'] ) ) {
						$has_fr = true;
						break;
					}
				}
				if ( $has_fr ) {
					continue;
				}
			}

			return $i;
		}

		// No safe point found — keep everything to avoid breaking chains.
		return 0;
	}

	/**
	 * Remove orphaned tool_result messages whose matching tool_use was lost.
	 *
	 * For Claude: a user message with tool_result blocks is only valid if the
	 * immediately preceding assistant message contains the matching tool_use IDs.
	 * For OpenAI: function_call_output must follow function_call with matching call_id.
	 *
	 * @param array $conversation Sanitized conversation.
	 * @return array
	 */
	private function sanitize_conversation( $conversation ) {
		if ( ! is_array( $conversation ) || count( $conversation ) < 2 ) {
			return $conversation;
		}

		$clean = array();
		$prev  = null;

		foreach ( $conversation as $msg ) {
			if ( ! is_array( $msg ) ) {
				$clean[] = $msg;
				$prev    = $msg;
				continue;
			}

			// --- Claude: user message with tool_result blocks ---
			$content = $msg['content'] ?? null;
			if ( ( $msg['role'] ?? '' ) === 'user' && is_array( $content ) ) {
				$has_tool_result = false;
				foreach ( $content as $block ) {
					if ( is_array( $block ) && ( $block['type'] ?? '' ) === 'tool_result' ) {
						$has_tool_result = true;
						break;
					}
				}

				if ( $has_tool_result ) {
					// Collect tool_use IDs from previous assistant message.
					$prev_ids = array();
					if ( is_array( $prev ) && ( $prev['role'] ?? '' ) === 'assistant' ) {
						$pc = $prev['content'] ?? array();
						if ( is_array( $pc ) ) {
							foreach ( $pc as $pb ) {
								if ( is_array( $pb ) && ( $pb['type'] ?? '' ) === 'tool_use' && ! empty( $pb['id'] ) ) {
									$prev_ids[ $pb['id'] ] = true;
								}
							}
						}
					}

					// Drop tool_result blocks with no matching tool_use.
					if ( empty( $prev_ids ) ) {
						// No preceding tool_use at all — skip entire message.
						continue;
					}

					$filtered = array();
					foreach ( $content as $block ) {
						if ( is_array( $block ) && ( $block['type'] ?? '' ) === 'tool_result' ) {
							if ( ! empty( $block['tool_use_id'] ) && isset( $prev_ids[ $block['tool_use_id'] ] ) ) {
								$filtered[] = $block;
							}
							// else: orphaned — drop it.
						} else {
							$filtered[] = $block;
						}
					}

					if ( empty( $filtered ) ) {
						continue; // Nothing left in this message.
					}
					$msg['content'] = $filtered;
				}
			}

			// --- OpenAI: function_call_output without preceding function_call ---
			if ( ( $msg['type'] ?? '' ) === 'function_call_output' ) {
				$call_id = $msg['call_id'] ?? '';
				if ( ! is_array( $prev ) || ( $prev['type'] ?? '' ) !== 'function_call' || ( $prev['call_id'] ?? '' ) !== $call_id ) {
					continue; // Orphaned.
				}
			}

			$clean[] = $msg;
			$prev    = $msg;
		}

		return array_values( $clean );
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

		if ( class_exists( 'StifliFlexMcp_ChangeTracker' ) ) {
			StifliFlexMcp_ChangeTracker::setSourceContext( 'copilot', 'Copilot Editor' );
			$session_id = isset( $_POST['session_id'] ) ? sanitize_text_field( wp_unslash( $_POST['session_id'] ) ) : '';
			if ( $session_id ) {
				StifliFlexMcp_ChangeTracker::getInstance()->setSessionId( $session_id );
			}
		}

		$result = $stifliFlexMcp->model->dispatchTool( $tool_name, $arguments, null );

		if ( isset( $result['error'] ) ) {
			wp_send_json_error( array( 'message' => $result['error']['message'] ?? __( 'Tool execution failed', 'stifli-flex-mcp' ) ) );
		}

		wp_send_json_success( $result['result'] ?? $result );
	}
}
