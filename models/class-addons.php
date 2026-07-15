<?php
/**
 * Optional addon registry and persisted activation state.
 *
 * @package StifliFlexMcp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class StifliFlexMcp_Addons {
	const OPTION_NAME = 'sflmcp_enabled_addons';
	const ONBOARDING_OPTION = 'sflmcp_addons_onboarding_pending';

	/**
	 * Return every product layer exposed to administrators.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function get_registry() {
		return array(
			'mcp_server' => array(
				'label'       => __( 'MCP Server', 'stifli-flex-mcp' ),
				'description' => __( 'Core MCP endpoints, Multimedia tools, and Logs & Roll Back.', 'stifli-flex-mcp' ),
				'required'    => true,
			),
			'ai_chat' => array(
				'label'       => __( 'AI Chat Agent', 'stifli-flex-mcp' ),
				'description' => __( 'Chat with AI providers and run site tools from WordPress admin.', 'stifli-flex-mcp' ),
				'required'    => false,
			),
			'copilot' => array(
				'label'       => __( 'AI Copilot', 'stifli-flex-mcp' ),
				'description' => __( 'Contextual assistant shown across WordPress admin screens.', 'stifli-flex-mcp' ),
				'required'    => false,
				'requires'    => array( 'ai_chat' ),
			),
			'automations' => array(
				'label'       => __( 'Automations', 'stifli-flex-mcp' ),
				'description' => __( 'Scheduled AI tasks and event-triggered workflows.', 'stifli-flex-mcp' ),
				'required'    => false,
			),
			'seo' => array(
				'label'       => __( 'SEO', 'stifli-flex-mcp' ),
				'description' => __( 'SEO administration and Google Search Console integration.', 'stifli-flex-mcp' ),
				'required'    => false,
			),
			'plugin_integrations' => array(
				'label'       => __( 'Plugin Integrations', 'stifli-flex-mcp' ),
				'description' => __( 'Discover and expose tools provided by compatible WordPress plugins.', 'stifli-flex-mcp' ),
				'required'    => false,
			),
		);
	}

	/**
	 * Return normalized optional addon identifiers.
	 *
	 * Existing installations retain the previously available modules until
	 * an administrator explicitly changes the selection.
	 *
	 * @return string[]
	 */
	public static function get_enabled() {
		$saved = get_option( self::OPTION_NAME, false );
		if ( false === $saved ) {
			if ( '' !== (string) get_option( 'sflmcp_db_version', '' ) ) {
				$saved = array_keys( self::get_optional_registry() );
			} else {
				$saved = array();
			}
		}

		return self::normalize( $saved );
	}

	/**
	 * Check whether a layer is active.
	 *
	 * @param string $addon_id Addon identifier.
	 * @return bool
	 */
	public static function is_enabled( $addon_id ) {
		$registry = self::get_registry();
		if ( isset( $registry[ $addon_id ]['required'] ) && $registry[ $addon_id ]['required'] ) {
			return true;
		}

		return in_array( $addon_id, self::get_enabled(), true );
	}

	/**
	 * Persist a normalized addon selection.
	 *
	 * @param array $addon_ids Requested addon identifiers.
	 * @return string[]
	 */
	public static function save( $addon_ids ) {
		$enabled = self::normalize( $addon_ids );
		update_option( self::OPTION_NAME, $enabled, false );

		if ( ! in_array( 'automations', $enabled, true ) ) {
			wp_clear_scheduled_hook( 'sflmcp_process_automation_tasks' );
		}

		return $enabled;
	}

	/**
	 * Establish the core-only default before a fresh activation creates tables.
	 *
	 * @return bool True when a fresh installation was initialized.
	 */
	public static function initialize_fresh_install() {
		if ( false !== get_option( self::OPTION_NAME, false ) || '' !== (string) get_option( 'sflmcp_db_version', '' ) ) {
			return false;
		}

		update_option( self::OPTION_NAME, array(), false );
		update_option( self::ONBOARDING_OPTION, 1, false );
		return true;
	}

	/**
	 * Register onboarding and persistence hooks in WordPress admin.
	 */
	public static function register_admin_hooks() {
		if ( ! is_admin() ) {
			return;
		}

		add_action( 'admin_menu', array( __CLASS__, 'register_onboarding_page' ), 5 );
		add_action( 'admin_init', array( __CLASS__, 'maybe_redirect_to_onboarding' ), 1 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_onboarding_assets' ) );
		add_action( 'admin_post_sflmcp_save_addons', array( __CLASS__, 'handle_save' ) );
	}

	/**
	 * Load the shared MCP admin styles on the hidden onboarding page.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public static function enqueue_onboarding_assets( $hook ) {
		if ( 'admin_page_sflmcp-setup' !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'sflmcp-admin-styles',
			plugin_dir_url( dirname( __FILE__ ) ) . 'assets/admin-styles.css',
			array(),
			'1.0.6'
		);
	}

	/**
	 * Register a hidden page used only by the first-activation flow.
	 */
	public static function register_onboarding_page() {
		add_submenu_page(
			null,
			__( 'Set up StifLi Flex MCP', 'stifli-flex-mcp' ),
			__( 'Set up StifLi Flex MCP', 'stifli-flex-mcp' ),
			'manage_options',
			'sflmcp-setup',
			array( __CLASS__, 'render_onboarding_page' )
		);
	}

	/**
	 * Redirect a single fresh activation to the layer selector.
	 */
	public static function maybe_redirect_to_onboarding() {
		if ( ! get_option( self::ONBOARDING_OPTION, false ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( ( defined( 'DOING_AJAX' ) && DOING_AJAX ) || isset( $_GET['activate-multi'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'sflmcp-setup' === $page ) {
			return;
		}

		wp_safe_redirect( admin_url( 'admin.php?page=sflmcp-setup' ) );
		exit;
	}

	/**
	 * Persist the selection from onboarding or MCP Server settings.
	 */
	public static function handle_save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage addons.', 'stifli-flex-mcp' ) );
		}

		check_admin_referer( 'sflmcp_save_addons' );
		$requested = isset( $_POST['addons'] ) ? (array) wp_unslash( $_POST['addons'] ) : array();
		self::save( $requested );
		delete_option( self::ONBOARDING_OPTION );

		$context = isset( $_POST['context'] ) ? sanitize_key( wp_unslash( $_POST['context'] ) ) : 'settings';
		if ( 'onboarding' === $context ) {
			$redirect_url = admin_url( 'admin.php?page=sflmcp-server&addons-updated=1' );
		} else {
			$redirect_url = admin_url( 'admin.php?page=sflmcp-server&tab=addons&addons-updated=1' );
		}

		wp_safe_redirect( $redirect_url );
		exit;
	}

	/**
	 * Render the first-activation screen.
	 */
	public static function render_onboarding_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap sflmcp-addon-setup">
			<h1><?php esc_html_e( 'Choose your StifLi Flex MCP layers', 'stifli-flex-mcp' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Only selected addons will load their PHP classes, hooks, menus, and background jobs. You can change this later in MCP Server settings.', 'stifli-flex-mcp' ); ?></p>
			<?php self::render_selector( true ); ?>
		</div>
		<?php
	}

	/**
	 * Render the reusable addon selection form.
	 *
	 * @param bool $onboarding Whether the selector is shown during first activation.
	 */
	public static function render_selector( $onboarding = false ) {
		$registry = self::get_registry();
		$enabled = self::get_enabled();
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="sflmcp_save_addons">
			<input type="hidden" name="context" value="<?php echo $onboarding ? 'onboarding' : 'settings'; ?>">
			<?php wp_nonce_field( 'sflmcp_save_addons' ); ?>

			<div class="sflmcp-addon-grid">
				<?php foreach ( $registry as $addon_id => $addon ) :
					$required = ! empty( $addon['required'] );
					$is_enabled = $required || in_array( $addon_id, $enabled, true );
					?>
					<label class="sflmcp-addon-card<?php echo $required ? ' is-required' : ''; ?>">
						<input type="checkbox" name="addons[]" value="<?php echo esc_attr( $addon_id ); ?>" <?php checked( $is_enabled ); ?> <?php disabled( $required ); ?>>
						<span>
							<strong><?php echo esc_html( $addon['label'] ); ?></strong>
							<?php if ( $required ) : ?>
								<small><?php esc_html_e( 'Always active', 'stifli-flex-mcp' ); ?></small>
							<?php endif; ?>
							<span class="description"><?php echo esc_html( $addon['description'] ); ?></span>
							<?php if ( ! empty( $addon['requires'] ) ) : ?>
								<span class="description"><?php esc_html_e( 'Also enables AI Chat Agent because it shares the AI provider runtime.', 'stifli-flex-mcp' ); ?></span>
							<?php endif; ?>
						</span>
					</label>
				<?php endforeach; ?>
			</div>

			<?php if ( $onboarding ) : ?>
				<p class="description"><?php esc_html_e( 'The recommended default is MCP Server only. Multimedia and Logs & Roll Back are already included.', 'stifli-flex-mcp' ); ?></p>
			<?php endif; ?>
			<?php submit_button( $onboarding ? __( 'Continue to MCP Server', 'stifli-flex-mcp' ) : __( 'Save Add-ons', 'stifli-flex-mcp' ) ); ?>
		</form>
		<?php
	}

	/**
	 * @return array<string,array<string,mixed>>
	 */
	private static function get_optional_registry() {
		return array_filter(
			self::get_registry(),
			static function( $addon ) {
				return empty( $addon['required'] );
			}
		);
	}

	/**
	 * @param mixed $addon_ids Addon identifiers.
	 * @return string[]
	 */
	private static function normalize( $addon_ids ) {
		$registry = self::get_optional_registry();
		$addon_ids = is_array( $addon_ids ) ? array_map( 'sanitize_key', $addon_ids ) : array();
		$enabled = array_values( array_intersect( array_keys( $registry ), $addon_ids ) );

		foreach ( $enabled as $addon_id ) {
			$dependencies = isset( $registry[ $addon_id ]['requires'] ) ? $registry[ $addon_id ]['requires'] : array();
			foreach ( $dependencies as $dependency ) {
				if ( isset( $registry[ $dependency ] ) && ! in_array( $dependency, $enabled, true ) ) {
					$enabled[] = $dependency;
				}
			}
		}

		return array_values( array_unique( $enabled ) );
	}
}

if ( ! function_exists( 'stifli_flex_mcp_addon_enabled' ) ) {
	function stifli_flex_mcp_addon_enabled( $addon_id ) {
		return StifliFlexMcp_Addons::is_enabled( $addon_id );
	}
}
