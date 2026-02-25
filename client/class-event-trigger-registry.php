<?php
/**
 * Event Trigger Registry
 *
 * Manages the catalog of available event triggers for automations.
 *
 * @package StifliFlexMcp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Event Trigger Registry class
 */
class StifliFlexMcp_Event_Trigger_Registry {

	/**
	 * Singleton instance
	 *
	 * @var StifliFlexMcp_Event_Trigger_Registry|null
	 */
	private static $instance = null;

	/**
	 * Cached triggers from database
	 *
	 * @var array
	 */
	private $triggers = array();

	/**
	 * Get singleton instance
	 *
	 * @return StifliFlexMcp_Event_Trigger_Registry
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor
	 */
	private function __construct() {
		$this->load_triggers();
	}

	/**
	 * Load triggers from database
	 */
	private function load_triggers() {
		global $wpdb;
		$table = $wpdb->prefix . 'sflmcp_event_triggers';
		
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( "SELECT * FROM {$table} WHERE is_active = 1 ORDER BY category, trigger_name", ARRAY_A );
		
		if ( ! empty( $rows ) ) {
			foreach ( $rows as $row ) {
				$this->triggers[ $row['trigger_id'] ] = $row;
			}
		}
	}

	/**
	 * Get all triggers
	 *
	 * @return array
	 */
	public function get_all() {
		return $this->triggers;
	}

	/**
	 * Get a specific trigger by ID
	 *
	 * @param string $trigger_id Trigger ID.
	 * @return array|null
	 */
	public function get( $trigger_id ) {
		return isset( $this->triggers[ $trigger_id ] ) ? $this->triggers[ $trigger_id ] : null;
	}

	/**
	 * Get triggers by category
	 *
	 * @param string|null $category Category name or null for all.
	 * @return array
	 */
	public function get_by_category( $category = null ) {
		if ( null === $category ) {
			return $this->triggers;
		}
		
		return array_filter( $this->triggers, function( $t ) use ( $category ) {
			return strpos( $t['category'], $category ) === 0;
		});
	}

	/**
	 * Get all unique categories
	 *
	 * @return array
	 */
	public function get_categories() {
		$categories = array();
		foreach ( $this->triggers as $trigger ) {
			$cat = $trigger['category'];
			if ( ! isset( $categories[ $cat ] ) ) {
				$categories[ $cat ] = array(
					'name'  => $cat,
					'count' => 0,
				);
			}
			$categories[ $cat ]['count']++;
		}
		return array_values( $categories );
	}

	/**
	 * Get available triggers (plugin is active)
	 *
	 * @return array
	 */
	public function get_available() {
		return array_filter( $this->triggers, function( $t ) {
			if ( empty( $t['plugin_required'] ) ) {
				return true;
			}
			return $this->is_plugin_active( $t['plugin_required'] );
		});
	}

	/**
	 * Check if a plugin is active
	 *
	 * @param string $plugin Plugin slug.
	 * @return bool
	 */
	private function is_plugin_active( $plugin ) {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			include_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		
		// Check common plugin file patterns
		$patterns = array(
			$plugin . '/' . $plugin . '.php',
			$plugin . '/class-' . $plugin . '.php',
		);
		
		foreach ( $patterns as $pattern ) {
			if ( is_plugin_active( $pattern ) ) {
				return true;
			}
		}
		
		// Special cases
		switch ( $plugin ) {
			case 'woocommerce':
				return class_exists( 'WooCommerce' );
			case 'contact-form-7':
				return class_exists( 'WPCF7' );
			case 'gravityforms':
				return class_exists( 'GFForms' );
			case 'wpforms':
				return function_exists( 'wpforms' );
		}
		
		return false;
	}

	/**
	 * Get triggers grouped by category
	 *
	 * @param bool $only_available Only include available triggers.
	 * @return array
	 */
	public function get_grouped( $only_available = true ) {
		$triggers = $only_available ? $this->get_available() : $this->triggers;
		$grouped  = array();
		
		foreach ( $triggers as $trigger ) {
			$cat = $trigger['category'];
			if ( ! isset( $grouped[ $cat ] ) ) {
				$grouped[ $cat ] = array();
			}
			$grouped[ $cat ][] = $trigger;
		}
		
		return $grouped;
	}

	/**
	 * Get payload schema for a trigger
	 *
	 * @param string $trigger_id Trigger ID.
	 * @return array
	 */
	public function get_payload_schema( $trigger_id ) {
		$trigger = $this->get( $trigger_id );
		if ( ! $trigger || empty( $trigger['payload_schema'] ) ) {
			return array();
		}
		
		$schema = json_decode( $trigger['payload_schema'], true );
		return is_array( $schema ) ? $schema : array();
	}

	/**
	 * Register a custom trigger (runtime only, not persisted)
	 *
	 * @param string $trigger_id Trigger ID.
	 * @param array  $config     Trigger configuration.
	 */
	public function register( $trigger_id, $config ) {
		$this->triggers[ $trigger_id ] = wp_parse_args( $config, array(
			'trigger_id'         => $trigger_id,
			'trigger_name'       => $trigger_id,
			'trigger_description' => '',
			'hook_name'          => '',
			'hook_priority'      => 10,
			'hook_accepted_args' => 1,
			'category'           => 'Custom',
			'plugin_required'    => null,
			'payload_schema'     => '[]',
			'is_active'          => 1,
			'is_system'          => 0,
		));
	}

	/**
	 * Refresh triggers from database
	 */
	public function refresh() {
		$this->triggers = array();
		$this->load_triggers();
	}
}
