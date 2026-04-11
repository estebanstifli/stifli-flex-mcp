<?php
/**
 * Event Automation Engine
 *
 * Handles the execution of event-triggered automations.
 *
 * @package StifliFlexMcp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Event Automation Engine class
 */
class StifliFlexMcp_Event_Automation_Engine {

	/**
	 * Singleton instance
	 *
	 * @var StifliFlexMcp_Event_Automation_Engine|null
	 */
	private static $instance = null;

	/**
	 * Registry instance
	 *
	 * @var StifliFlexMcp_Event_Trigger_Registry
	 */
	private $registry;

	/**
	 * Active automations cache
	 *
	 * @var array
	 */
	private $active_automations = array();

	/**
	 * Rate limiting: max executions per minute
	 */
	const RATE_LIMIT_PER_MINUTE = 10;

	/**
	 * Execution timeout in seconds
	 */
	const EXECUTION_TIMEOUT = 30;

	/**
	 * Get singleton instance
	 *
	 * @return StifliFlexMcp_Event_Automation_Engine
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
		$this->registry = StifliFlexMcp_Event_Trigger_Registry::get_instance();
	}

	/**
	 * Initialize - hook all active automations
	 */
	public function init() {
		// Only run on frontend and during cron
		if ( is_admin() && ! wp_doing_ajax() && ! defined( 'DOING_CRON' ) ) {
			return;
		}

		$this->load_active_automations();
		$this->hook_automations();
	}

	/**
	 * Load active automations from database
	 */
	private function load_active_automations() {
		global $wpdb;
		$table = $wpdb->prefix . 'sflmcp_event_automations';
		
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$this->active_automations = $wpdb->get_results(
			"SELECT * FROM {$table} WHERE status = 'active'",
			ARRAY_A
		);
	}

	/**
	 * Hook all active automations to their triggers
	 */
	private function hook_automations() {
		if ( empty( $this->active_automations ) ) {
			return;
		}

		foreach ( $this->active_automations as $automation ) {
			$trigger = $this->registry->get( $automation['trigger_id'] );
			if ( ! $trigger ) {
				continue;
			}

			// Create closure for this automation
			add_action(
				$trigger['hook_name'],
				function( ...$args ) use ( $automation, $trigger ) {
					$this->handle_trigger( $automation, $trigger, $args );
				},
				intval( $trigger['hook_priority'] ),
				intval( $trigger['hook_accepted_args'] )
			);
		}
	}

	/**
	 * Handle a trigger firing
	 *
	 * @param array $automation Automation configuration.
	 * @param array $trigger    Trigger configuration.
	 * @param array $args       Hook arguments.
	 */
	private function handle_trigger( $automation, $trigger, $args ) {
		$start_time = microtime( true );

		try {
			// Check rate limit
			if ( ! $this->check_rate_limit( $automation['id'] ) ) {
				$this->log_execution(
					$automation['id'],
					$trigger['trigger_id'],
					array(),
					'',
					'',
					array(),
					0,
					0,
					'skipped',
					'Rate limit exceeded'
				);
				return;
			}

			// Build payload from hook arguments
			$payload = $this->build_payload( $trigger, $args );

			// Check conditions
			if ( ! $this->evaluate_conditions( $automation['conditions'], $payload ) ) {
				$this->log_execution(
					$automation['id'],
					$trigger['trigger_id'],
					$payload,
					'',
					'',
					array(),
					0,
					0,
					'skipped',
					'Conditions not met'
				);
				return;
			}

			// Resolve placeholders in prompt
			$prompt = $this->resolve_placeholders( $automation['prompt'], $payload );

			// Get tools
			$tools_enabled = ! empty( $automation['tools_enabled'] ) 
				? json_decode( $automation['tools_enabled'], true ) 
				: array();

			// Execute using main automation engine
			$result = $this->execute_prompt(
				$prompt,
				$automation['system_prompt'],
				$tools_enabled,
				$automation['provider'],
				$automation['model'],
				intval( $automation['max_tokens'] )
			);

			$execution_time = microtime( true ) - $start_time;

			// Log success
			$this->log_execution(
				$automation['id'],
				$trigger['trigger_id'],
				$payload,
				$prompt,
				$result['response'] ?? '',
				$result['tools_executed'] ?? array(),
				$result['tokens_used'] ?? 0,
				$execution_time,
				'success',
				null
			);

			// Execute output actions
			$this->execute_output_actions( $automation, $result, $payload );

			// Update run count
			$this->update_automation_stats( $automation['id'] );

		} catch ( Exception $e ) {
			$execution_time = microtime( true ) - $start_time;
			
			$this->log_execution(
				$automation['id'],
				$trigger['trigger_id'],
				$payload ?? array(),
				$prompt ?? '',
				'',
				array(),
				0,
				$execution_time,
				'error',
				$e->getMessage()
			);

			$this->update_automation_error( $automation['id'], $e->getMessage() );
		}
	}

	/**
	 * Build payload from hook arguments
	 *
	 * @param array $trigger Trigger configuration.
	 * @param array $args    Hook arguments.
	 * @return array
	 */
	private function build_payload( $trigger, $args ) {
		$payload = array(
			'trigger_id'   => $trigger['trigger_id'],
			'trigger_name' => $trigger['trigger_name'],
			'timestamp'    => current_time( 'mysql' ),
			'site_url'     => home_url(),
			'site_name'    => get_bloginfo( 'name' ),
		);

		// Build payload based on trigger type
		$trigger_id = $trigger['trigger_id'];

		// WordPress Posts
		if ( strpos( $trigger_id, 'wp_post' ) === 0 || strpos( $trigger_id, 'wp_page' ) === 0 ) {
			$payload = array_merge( $payload, $this->build_post_payload( $trigger_id, $args ) );
		}
		// WordPress Users
		elseif ( strpos( $trigger_id, 'wp_user' ) === 0 ) {
			$payload = array_merge( $payload, $this->build_user_payload( $trigger_id, $args ) );
		}
		// WordPress Comments
		elseif ( strpos( $trigger_id, 'wp_comment' ) === 0 ) {
			$payload = array_merge( $payload, $this->build_comment_payload( $trigger_id, $args ) );
		}
		// WordPress Media
		elseif ( strpos( $trigger_id, 'wp_media' ) === 0 ) {
			$payload = array_merge( $payload, $this->build_media_payload( $trigger_id, $args ) );
		}
		// WordPress System
		elseif ( strpos( $trigger_id, 'wp_plugin' ) === 0 || strpos( $trigger_id, 'wp_theme' ) === 0 ) {
			$payload = array_merge( $payload, $this->build_system_payload( $trigger_id, $args ) );
		}
		// WooCommerce Orders
		elseif ( strpos( $trigger_id, 'wc_order' ) === 0 || $trigger_id === 'wc_payment_complete' ) {
			$payload = array_merge( $payload, $this->build_wc_order_payload( $trigger_id, $args ) );
		}
		// WooCommerce Products
		elseif ( strpos( $trigger_id, 'wc_product' ) === 0 ) {
			$payload = array_merge( $payload, $this->build_wc_product_payload( $trigger_id, $args ) );
		}
		// WooCommerce Customers
		elseif ( strpos( $trigger_id, 'wc_customer' ) === 0 ) {
			$payload = array_merge( $payload, $this->build_wc_customer_payload( $trigger_id, $args ) );
		}
		// WooCommerce Cart
		elseif ( strpos( $trigger_id, 'wc_' ) === 0 ) {
			$payload = array_merge( $payload, $this->build_wc_cart_payload( $trigger_id, $args ) );
		}
		// Forms
		elseif ( strpos( $trigger_id, 'cf7_' ) === 0 ) {
			$payload = array_merge( $payload, $this->build_cf7_payload( $args ) );
		}
		elseif ( strpos( $trigger_id, 'gf_' ) === 0 ) {
			$payload = array_merge( $payload, $this->build_gf_payload( $args ) );
		}
		elseif ( strpos( $trigger_id, 'wpforms_' ) === 0 ) {
			$payload = array_merge( $payload, $this->build_wpforms_payload( $args ) );
		}

		return apply_filters( 'sflmcp_event_payload', $payload, $trigger, $args );
	}

	/**
	 * Build post payload
	 *
	 * @param string $trigger_id Trigger ID.
	 * @param array  $args       Hook arguments.
	 * @return array
	 */
	private function build_post_payload( $trigger_id, $args ) {
		$payload = array();

		if ( $trigger_id === 'wp_post_status_changed' ) {
			// transition_post_status: $new_status, $old_status, $post
			$payload['new_status'] = $args[0] ?? '';
			$payload['old_status'] = $args[1] ?? '';
			$post = $args[2] ?? null;
		} elseif ( $trigger_id === 'wp_post_updated' ) {
			// post_updated: $post_ID, $post_after, $post_before
			$post = $args[1] ?? null;
			$payload['post_before'] = isset( $args[2] ) ? $args[2]->post_title : '';
		} else {
			// publish_post, wp_trash_post, etc: $post_id, $post
			$post_id = $args[0] ?? 0;
			$post = isset( $args[1] ) ? $args[1] : get_post( $post_id );
		}

		if ( $post ) {
			$payload['post_id']      = $post->ID;
			$payload['post_title']   = $post->post_title;
			$payload['post_content'] = wp_trim_words( $post->post_content, 100 );
			$payload['post_excerpt'] = $post->post_excerpt;
			$payload['post_type']    = $post->post_type;
			$payload['post_status']  = $post->post_status;
			$payload['post_url']     = get_permalink( $post->ID );
			$payload['post_author']  = get_the_author_meta( 'display_name', $post->post_author );
			
			// Categories and tags
			$categories = wp_get_post_categories( $post->ID, array( 'fields' => 'names' ) );
			$payload['post_categories'] = is_array( $categories ) ? implode( ', ', $categories ) : '';
			
			$tags = wp_get_post_tags( $post->ID, array( 'fields' => 'names' ) );
			$payload['post_tags'] = is_array( $tags ) ? implode( ', ', $tags ) : '';
		}

		return $payload;
	}

	/**
	 * Build user payload
	 *
	 * @param string $trigger_id Trigger ID.
	 * @param array  $args       Hook arguments.
	 * @return array
	 */
	private function build_user_payload( $trigger_id, $args ) {
		$payload = array();

		if ( $trigger_id === 'wp_user_login' ) {
			// wp_login: $user_login, $user
			$payload['user_login'] = $args[0] ?? '';
			$user = $args[1] ?? null;
		} elseif ( $trigger_id === 'wp_user_login_failed' ) {
			// wp_login_failed: $username
			$payload['username'] = $args[0] ?? '';
			return $payload;
		} elseif ( $trigger_id === 'wp_user_role_changed' ) {
			// set_user_role: $user_id, $role, $old_roles
			$user_id = $args[0] ?? 0;
			$payload['new_role']  = $args[1] ?? '';
			$payload['old_roles'] = is_array( $args[2] ?? array() ) ? implode( ', ', $args[2] ) : '';
			$user = get_userdata( $user_id );
		} elseif ( $trigger_id === 'wp_user_profile_updated' ) {
			// profile_update: $user_id, $old_user_data
			$user_id = $args[0] ?? 0;
			$user = get_userdata( $user_id );
		} else {
			// user_register, delete_user, wp_logout: $user_id
			$user_id = $args[0] ?? 0;
			$user = get_userdata( $user_id );
		}

		if ( $user ) {
			$payload['user_id']    = $user->ID;
			$payload['user_email'] = $user->user_email;
			$payload['user_login'] = $user->user_login;
			$payload['user_name']  = $user->display_name;
			$payload['user_role']  = implode( ', ', $user->roles );
		}

		return $payload;
	}

	/**
	 * Build comment payload
	 *
	 * @param string $trigger_id Trigger ID.
	 * @param array  $args       Hook arguments.
	 * @return array
	 */
	private function build_comment_payload( $trigger_id, $args ) {
		$payload = array();

		if ( $trigger_id === 'wp_comment_status_changed' ) {
			// transition_comment_status: $new_status, $old_status, $comment
			$payload['new_status'] = $args[0] ?? '';
			$payload['old_status'] = $args[1] ?? '';
			$comment = $args[2] ?? null;
		} elseif ( $trigger_id === 'wp_comment_posted' ) {
			// comment_post: $comment_ID, $comment_approved, $commentdata
			$comment_id = $args[0] ?? 0;
			$payload['comment_approved'] = $args[1] ?? 0;
			$comment = get_comment( $comment_id );
		} else {
			// spam_comment, etc: $comment_id
			$comment_id = $args[0] ?? 0;
			$comment = get_comment( $comment_id );
		}

		if ( $comment ) {
			$payload['comment_id']           = $comment->comment_ID;
			$payload['comment_content']      = wp_trim_words( $comment->comment_content, 50 );
			$payload['comment_author']       = $comment->comment_author;
			$payload['comment_author_email'] = $comment->comment_author_email;
			$payload['post_id']              = $comment->comment_post_ID;
			
			$post = get_post( $comment->comment_post_ID );
			if ( $post ) {
				$payload['post_title'] = $post->post_title;
				$payload['post_url']   = get_permalink( $post->ID );
			}
		}

		return $payload;
	}

	/**
	 * Build media payload
	 *
	 * @param string $trigger_id Trigger ID.
	 * @param array  $args       Hook arguments.
	 * @return array
	 */
	private function build_media_payload( $trigger_id, $args ) {
		$attachment_id = $args[0] ?? 0;
		$payload = array(
			'attachment_id' => $attachment_id,
		);

		$attachment = get_post( $attachment_id );
		if ( $attachment ) {
			$payload['file_name'] = basename( get_attached_file( $attachment_id ) );
			$payload['file_type'] = $attachment->post_mime_type;
			$payload['file_url']  = wp_get_attachment_url( $attachment_id );
			$payload['file_title'] = $attachment->post_title;
		}

		return $payload;
	}

	/**
	 * Build system payload
	 *
	 * @param string $trigger_id Trigger ID.
	 * @param array  $args       Hook arguments.
	 * @return array
	 */
	private function build_system_payload( $trigger_id, $args ) {
		$payload = array();

		if ( strpos( $trigger_id, 'wp_plugin' ) === 0 ) {
			// activated_plugin, deactivated_plugin: $plugin, $network_wide
			$payload['plugin_file']   = $args[0] ?? '';
			$payload['plugin_name']   = dirname( $args[0] ?? '' );
			$payload['network_wide']  = $args[1] ?? false;
		} elseif ( $trigger_id === 'wp_theme_switched' ) {
			// switch_theme: $new_name, $new_theme, $old_theme
			$payload['new_theme'] = $args[0] ?? '';
			$payload['old_theme'] = isset( $args[2] ) ? $args[2]->get( 'Name' ) : '';
		}

		return $payload;
	}

	/**
	 * Build WooCommerce order payload
	 *
	 * @param string $trigger_id Trigger ID.
	 * @param array  $args       Hook arguments.
	 * @return array
	 */
	private function build_wc_order_payload( $trigger_id, $args ) {
		$payload = array();

		if ( ! class_exists( 'WooCommerce' ) ) {
			return $payload;
		}

		if ( $trigger_id === 'wc_order_status_changed' ) {
			// woocommerce_order_status_changed: $order_id, $old_status, $new_status, $order
			$order_id = $args[0] ?? 0;
			$payload['old_status'] = $args[1] ?? '';
			$payload['new_status'] = $args[2] ?? '';
		} elseif ( $trigger_id === 'wc_order_refunded' ) {
			// woocommerce_order_refunded: $order_id, $refund_id
			$order_id = $args[0] ?? 0;
			$payload['refund_id'] = $args[1] ?? 0;
		} else {
			// Most order hooks: $order_id
			$order_id = $args[0] ?? 0;
		}

		$order = wc_get_order( $order_id );
		if ( $order ) {
			$payload['order_id']        = $order->get_id();
			$payload['order_number']    = $order->get_order_number();
			$payload['order_total']     = $order->get_total();
			$payload['order_status']    = $order->get_status();
			$payload['order_currency']  = $order->get_currency();
			$payload['customer_email']  = $order->get_billing_email();
			$payload['customer_name']   = $order->get_billing_first_name() . ' ' . $order->get_billing_last_name();
			$payload['customer_phone']  = $order->get_billing_phone();
			$payload['items_count']     = $order->get_item_count();
			$payload['payment_method']  = $order->get_payment_method_title();
			$payload['shipping_method'] = $order->get_shipping_method();
			
			// Items summary
			$items = array();
			foreach ( $order->get_items() as $item ) {
				$items[] = $item->get_name() . ' x' . $item->get_quantity();
			}
			$payload['items_summary'] = implode( ', ', array_slice( $items, 0, 5 ) );
			
			// Shipping address
			$payload['shipping_address'] = $order->get_formatted_shipping_address();
		}

		return $payload;
	}

	/**
	 * Build WooCommerce product payload
	 *
	 * @param string $trigger_id Trigger ID.
	 * @param array  $args       Hook arguments.
	 * @return array
	 */
	private function build_wc_product_payload( $trigger_id, $args ) {
		$payload = array();

		if ( ! class_exists( 'WooCommerce' ) ) {
			return $payload;
		}

		$product_id = $args[0] ?? 0;
		
		// For stock hooks, arg is the product object
		if ( in_array( $trigger_id, array( 'wc_product_stock_changed', 'wc_product_low_stock', 'wc_product_out_of_stock' ), true ) ) {
			$product = $args[0] ?? null;
			if ( is_object( $product ) && method_exists( $product, 'get_id' ) ) {
				$product_id = $product->get_id();
			}
		}

		$product = wc_get_product( $product_id );
		if ( $product ) {
			$payload['product_id']       = $product->get_id();
			$payload['product_name']     = $product->get_name();
			$payload['product_sku']      = $product->get_sku();
			$payload['product_price']    = $product->get_price();
			$payload['product_url']      = $product->get_permalink();
			$payload['stock_quantity']   = $product->get_stock_quantity();
			$payload['stock_status']     = $product->get_stock_status();
			$payload['product_type']     = $product->get_type();
			
			// Categories
			$categories = wc_get_product_category_list( $product->get_id() );
			$payload['product_categories'] = wp_strip_all_tags( $categories );
		}

		return $payload;
	}

	/**
	 * Build WooCommerce customer payload
	 *
	 * @param string $trigger_id Trigger ID.
	 * @param array  $args       Hook arguments.
	 * @return array
	 */
	private function build_wc_customer_payload( $trigger_id, $args ) {
		$payload = array();

		if ( ! class_exists( 'WooCommerce' ) ) {
			return $payload;
		}

		// woocommerce_created_customer: $customer_id, $new_customer_data, $password_generated
		$customer_id = $args[0] ?? 0;
		
		$customer = new WC_Customer( $customer_id );
		if ( $customer->get_id() ) {
			$payload['customer_id']    = $customer->get_id();
			$payload['customer_email'] = $customer->get_email();
			$payload['customer_name']  = $customer->get_first_name() . ' ' . $customer->get_last_name();
		}

		return $payload;
	}

	/**
	 * Build WooCommerce cart payload
	 *
	 * @param string $trigger_id Trigger ID.
	 * @param array  $args       Hook arguments.
	 * @return array
	 */
	private function build_wc_cart_payload( $trigger_id, $args ) {
		$payload = array();

		if ( ! class_exists( 'WooCommerce' ) ) {
			return $payload;
		}

		if ( $trigger_id === 'wc_add_to_cart' ) {
			// woocommerce_add_to_cart: $cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data
			$payload['cart_item_key'] = $args[0] ?? '';
			$payload['product_id']    = $args[1] ?? 0;
			$payload['quantity']      = $args[2] ?? 1;
			$payload['variation_id']  = $args[3] ?? 0;
			
			$product = wc_get_product( $payload['product_id'] );
			if ( $product ) {
				$payload['product_name']  = $product->get_name();
				$payload['product_price'] = $product->get_price();
			}
		} elseif ( $trigger_id === 'wc_checkout_complete' ) {
			// woocommerce_checkout_order_processed: $order_id, $posted_data, $order
			$payload['order_id'] = $args[0] ?? 0;
		} elseif ( $trigger_id === 'wc_coupon_applied' ) {
			// woocommerce_applied_coupon: $coupon_code
			$payload['coupon_code'] = $args[0] ?? '';
		}

		return $payload;
	}

	/**
	 * Build Contact Form 7 payload
	 *
	 * @param array $args Hook arguments.
	 * @return array
	 */
	private function build_cf7_payload( $args ) {
		$payload = array();

		$submission = $args[0] ?? null;
		if ( ! $submission || ! is_object( $submission ) ) {
			return $payload;
		}

		$contact_form = $submission->get_contact_form();
		if ( $contact_form ) {
			$payload['form_id']    = $contact_form->id();
			$payload['form_title'] = $contact_form->title();
		}

		$posted_data = $submission->get_posted_data();
		if ( ! empty( $posted_data ) ) {
			$payload['posted_data'] = wp_json_encode( $posted_data );
			// Common fields
			$payload['your_name']    = $posted_data['your-name'] ?? '';
			$payload['your_email']   = $posted_data['your-email'] ?? '';
			$payload['your_subject'] = $posted_data['your-subject'] ?? '';
			$payload['your_message'] = $posted_data['your-message'] ?? '';
		}

		return $payload;
	}

	/**
	 * Build Gravity Forms payload
	 *
	 * @param array $args Hook arguments.
	 * @return array
	 */
	private function build_gf_payload( $args ) {
		$payload = array();

		// gform_after_submission: $entry, $form
		$entry = $args[0] ?? array();
		$form  = $args[1] ?? array();

		if ( ! empty( $form ) ) {
			$payload['form_id']    = $form['id'] ?? 0;
			$payload['form_title'] = $form['title'] ?? '';
		}

		if ( ! empty( $entry ) ) {
			$payload['entry_id']     = $entry['id'] ?? 0;
			$payload['source_url']   = $entry['source_url'] ?? '';
			$payload['date_created'] = $entry['date_created'] ?? '';
			$payload['entry']        = wp_json_encode( $entry );
		}

		return $payload;
	}

	/**
	 * Build WPForms payload
	 *
	 * @param array $args Hook arguments.
	 * @return array
	 */
	private function build_wpforms_payload( $args ) {
		$payload = array();

		// wpforms_process_complete: $fields, $entry, $form_data, $entry_id
		$fields    = $args[0] ?? array();
		$entry     = $args[1] ?? array();
		$form_data = $args[2] ?? array();

		if ( ! empty( $form_data ) ) {
			$payload['form_id']    = $form_data['id'] ?? 0;
			$payload['form_title'] = $form_data['settings']['form_title'] ?? '';
		}

		if ( ! empty( $fields ) ) {
			$payload['fields'] = wp_json_encode( $fields );
			// Extract common field values
			foreach ( $fields as $field ) {
				if ( isset( $field['type'] ) && isset( $field['value'] ) ) {
					$key = sanitize_key( $field['name'] ?? $field['type'] );
					$payload[ 'field_' . $key ] = $field['value'];
				}
			}
		}

		return $payload;
	}

	/**
	 * Evaluate conditions
	 *
	 * @param string|null $conditions_json JSON conditions string.
	 * @param array       $payload         Payload data.
	 * @return bool
	 */
	private function evaluate_conditions( $conditions_json, $payload ) {
		if ( empty( $conditions_json ) ) {
			return true;
		}

		$conditions = json_decode( $conditions_json, true );
		if ( empty( $conditions ) || ! is_array( $conditions ) ) {
			return true;
		}

		foreach ( $conditions as $condition ) {
			$field    = $condition['field'] ?? '';
			$operator = $condition['operator'] ?? 'equals';
			$value    = $condition['value'] ?? '';

			if ( empty( $field ) ) {
				continue;
			}

			$actual = $payload[ $field ] ?? '';

			switch ( $operator ) {
				case 'equals':
					if ( $actual !== $value ) {
						return false;
					}
					break;
				case 'not_equals':
					if ( $actual === $value ) {
						return false;
					}
					break;
				case 'contains':
					if ( strpos( (string) $actual, $value ) === false ) {
						return false;
					}
					break;
				case 'not_contains':
					if ( strpos( (string) $actual, $value ) !== false ) {
						return false;
					}
					break;
				case 'starts_with':
					if ( strpos( (string) $actual, $value ) !== 0 ) {
						return false;
					}
					break;
				case 'ends_with':
					if ( substr( (string) $actual, -strlen( $value ) ) !== $value ) {
						return false;
					}
					break;
				case 'greater_than':
					if ( floatval( $actual ) <= floatval( $value ) ) {
						return false;
					}
					break;
				case 'less_than':
					if ( floatval( $actual ) >= floatval( $value ) ) {
						return false;
					}
					break;
				case 'is_empty':
					if ( ! empty( $actual ) ) {
						return false;
					}
					break;
				case 'is_not_empty':
					if ( empty( $actual ) ) {
						return false;
					}
					break;
			}
		}

		return true;
	}

	/**
	 * Resolve placeholders in prompt
	 *
	 * @param string $prompt  Prompt with placeholders.
	 * @param array  $payload Payload data.
	 * @return string
	 */
	private function resolve_placeholders( $prompt, $payload ) {
		return preg_replace_callback(
			'/\{\{(\w+)\}\}/',
			function( $matches ) use ( $payload ) {
				$key = $matches[1];
				return isset( $payload[ $key ] ) ? (string) $payload[ $key ] : $matches[0];
			},
			$prompt
		);
	}

	/**
	 * Execute prompt using main automation engine
	 *
	 * @param string      $prompt        Prompt text.
	 * @param string|null $system_prompt System prompt.
	 * @param array       $tools_enabled Enabled tools.
	 * @param string|null $provider      AI provider.
	 * @param string|null $model         AI model.
	 * @param int         $max_tokens    Max tokens.
	 * @return array
	 */
	private function execute_prompt( $prompt, $system_prompt, $tools_enabled, $provider, $model, $max_tokens ) {
		// Use main automation engine
		if ( ! class_exists( 'StifliFlexMcp_Automation_Engine' ) ) {
			require_once __DIR__ . '/class-automation-engine.php';
		}

		$engine = StifliFlexMcp_Automation_Engine::get_instance();

		// Set source context for event-triggered automations
		if ( class_exists( 'StifliFlexMcp_ChangeTracker' ) ) {
			StifliFlexMcp_ChangeTracker::setSourceContext( 'event_automation', 'Event Automation' );
		}

		// Build a temporary task-like object
		$task = (object) array(
			'id'            => 0,
			'prompt'        => $prompt,
			'system_prompt' => $system_prompt,
			'tools_enabled' => is_array( $tools_enabled ) ? wp_json_encode( $tools_enabled ) : $tools_enabled,
			'provider'      => $provider,
			'model'         => $model,
			'max_tokens'    => $max_tokens,
		);

		// Execute and capture result
		$result = $engine->execute_task_internal( $task );

		return $result;
	}

	/**
	 * Check rate limit
	 *
	 * @param int $automation_id Automation ID.
	 * @return bool
	 */
	private function check_rate_limit( $automation_id ) {
		$transient_key = 'sflmcp_event_rate_' . $automation_id;
		$count = get_transient( $transient_key );

		if ( false === $count ) {
			set_transient( $transient_key, 1, MINUTE_IN_SECONDS );
			return true;
		}

		if ( $count >= self::RATE_LIMIT_PER_MINUTE ) {
			return false;
		}

		set_transient( $transient_key, $count + 1, MINUTE_IN_SECONDS );
		return true;
	}

	/**
	 * Log execution
	 *
	 * @param int         $automation_id  Automation ID.
	 * @param string      $trigger_id     Trigger ID.
	 * @param array       $payload        Trigger payload.
	 * @param string      $prompt_sent    Resolved prompt.
	 * @param string      $response       AI response.
	 * @param array       $tools_executed Executed tools.
	 * @param int         $tokens_used    Tokens used.
	 * @param float       $execution_time Execution time.
	 * @param string      $status         Status (success/error/skipped).
	 * @param string|null $error_message  Error message.
	 */
	private function log_execution( $automation_id, $trigger_id, $payload, $prompt_sent, $response, $tools_executed, $tokens_used, $execution_time, $status, $error_message ) {
		global $wpdb;
		$table = $wpdb->prefix . 'sflmcp_event_logs';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			$table,
			array(
				'automation_id'   => $automation_id,
				'trigger_id'      => $trigger_id,
				'trigger_payload' => wp_json_encode( $payload ),
				'prompt_sent'     => $prompt_sent,
				'response'        => $response,
				'tools_executed'  => wp_json_encode( $tools_executed ),
				'tokens_used'     => $tokens_used,
				'execution_time'  => $execution_time,
				'status'          => $status,
				'error_message'   => $error_message,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%f', '%s', '%s' )
		);
	}

	/**
	 * Update automation stats
	 *
	 * @param int $automation_id Automation ID.
	 */
	private function update_automation_stats( $automation_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'sflmcp_event_automations';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare(
			"UPDATE {$table} SET run_count = run_count + 1, last_run = %s WHERE id = %d",
			current_time( 'mysql' ),
			$automation_id
		) );
	}

	/**
	 * Update automation with error
	 *
	 * @param int    $automation_id Automation ID.
	 * @param string $error_message Error message.
	 */
	private function update_automation_error( $automation_id, $error_message ) {
		global $wpdb;
		$table = $wpdb->prefix . 'sflmcp_event_automations';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$table,
			array(
				'last_error' => $error_message,
				'status'     => 'error',
			),
			array( 'id' => $automation_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Execute output actions (email, webhook, draft post)
	 *
	 * @param array $automation Automation configuration.
	 * @param array $result     Execution result.
	 * @param array $payload    Trigger payload.
	 */
	private function execute_output_actions( $automation, $result, $payload ) {
		$response = $result['response'] ?? '';
		$tools_executed = $result['tools_executed'] ?? array();

		// Check if any output actions are enabled
		$has_email   = ! empty( $automation['output_email'] ) && ! empty( $automation['email_recipients'] );
		$has_webhook = ! empty( $automation['output_webhook'] ) && ! empty( $automation['webhook_url'] );
		$has_draft   = ! empty( $automation['output_draft'] );

		if ( ! $has_email && ! $has_webhook && ! $has_draft ) {
			return; // No output actions configured
		}

		stifli_flex_mcp_log( sprintf(
			'[Event Automation] Executing output actions for automation "%s" (ID: %d) - Email: %s, Webhook: %s, Draft: %s',
			$automation['automation_name'] ?? 'unknown',
			$automation['id'] ?? 0,
			$has_email ? 'yes' : 'no',
			$has_webhook ? 'yes' : 'no',
			$has_draft ? 'yes' : 'no'
		) );

		// Build content for output actions
		$content = $this->build_output_content( $response, $tools_executed );

		// Send email notification
		if ( $has_email ) {
			$this->send_email_notification( $automation, $content, $payload );
		}

		// Send to webhook
		if ( $has_webhook ) {
			$this->send_webhook( $automation, $content, $payload, $result );
		}

		// Create draft post
		if ( $has_draft ) {
			$this->create_draft_post( $automation, $content, $payload );
		}
	}

	/**
	 * Build output content from response and tools
	 *
	 * @param string $response        AI text response.
	 * @param array  $tools_executed  Executed tools.
	 * @return string
	 */
	private function build_output_content( $response, $tools_executed ) {
		$content = '';

		// Add text response
		if ( ! empty( $response ) ) {
			$content .= $response;
		}

		// Add tools executed summary
		if ( ! empty( $tools_executed ) ) {
			$content .= "\n\n---\n\n**Tools Executed:**\n\n";
			foreach ( $tools_executed as $tool ) {
				$tool_name = is_array( $tool ) ? ( $tool['name'] ?? 'unknown' ) : $tool;
				$tool_result = is_array( $tool ) && isset( $tool['result'] ) ? wp_json_encode( $tool['result'], JSON_PRETTY_PRINT ) : '';
				$content .= "- **{$tool_name}**";
				if ( $tool_result ) {
					$content .= "\n```json\n{$tool_result}\n```";
				}
				$content .= "\n";
			}
		}

		return $content;
	}

	/**
	 * Send email notification
	 *
	 * @param array  $automation Automation configuration.
	 * @param string $content    Email content.
	 * @param array  $payload    Trigger payload.
	 */
	private function send_email_notification( $automation, $content, $payload ) {
		$recipients = array_map( 'trim', explode( ',', $automation['email_recipients'] ) );
		$recipients = array_filter( $recipients, 'is_email' );

		if ( empty( $recipients ) ) {
			stifli_flex_mcp_log( '[Event Automation] Email skipped: no valid recipients in "' . ( $automation['email_recipients'] ?? '' ) . '"' );
			return;
		}

		// Build subject with placeholders
		$subject = $automation['email_subject'] ?? '[{site_name}] Event: {automation_name} - {date}';
		$subject = str_replace(
			array( '{site_name}', '{automation_name}', '{date}', '{trigger_id}' ),
			array(
				get_bloginfo( 'name' ),
				$automation['automation_name'],
				wp_date( 'Y-m-d H:i:s' ),
				$automation['trigger_id'],
			),
			$subject
		);

		// Add payload placeholders
		foreach ( $payload as $key => $value ) {
			if ( is_scalar( $value ) ) {
				$subject = str_replace( "{{$key}}", (string) $value, $subject );
			}
		}

		// Build email body
		$body = "## Automation: {$automation['automation_name']}\n\n";
		$body .= "**Trigger:** {$automation['trigger_id']}\n";
		$body .= "**Time:** " . wp_date( 'Y-m-d H:i:s' ) . "\n\n";
		$body .= "---\n\n";
		$body .= $content;

		// Convert markdown to HTML for email
		$html_body = wpautop( $body );

		$headers = array( 'Content-Type: text/html; charset=UTF-8' );

		foreach ( $recipients as $to ) {
			wp_mail( $to, $subject, $html_body, $headers );
		}

		stifli_flex_mcp_log( '[Event Automation] Email sent to: ' . implode( ', ', $recipients ) );
	}

	/**
	 * Send to webhook
	 *
	 * @param array  $automation Automation configuration.
	 * @param string $content    Content.
	 * @param array  $payload    Trigger payload.
	 * @param array  $result     Full result.
	 */
	private function send_webhook( $automation, $content, $payload, $result ) {
		$url = $automation['webhook_url'];
		$preset = $automation['webhook_preset'] ?? 'custom';

		// Build webhook payload based on preset
		switch ( $preset ) {
			case 'slack':
				$webhook_payload = array(
					'text'   => "*Event: {$automation['automation_name']}*\n\n{$content}",
					'blocks' => array(
						array(
							'type' => 'header',
							'text' => array(
								'type'  => 'plain_text',
								'text'  => $automation['automation_name'],
								'emoji' => true,
							),
						),
						array(
							'type' => 'section',
							'text' => array(
								'type' => 'mrkdwn',
								'text' => substr( $content, 0, 3000 ), // Slack limit
							),
						),
					),
				);
				break;

			case 'discord':
				$webhook_payload = array(
					'content' => "**{$automation['automation_name']}**",
					'embeds'  => array(
						array(
							'title'       => $automation['automation_name'],
							'description' => substr( $content, 0, 4096 ), // Discord limit
							'color'       => 5814783, // Blue color
							'timestamp'   => gmdate( 'c' ),
						),
					),
				);
				break;

			case 'telegram':
				// For Telegram, URL should include the token and chat_id in query params
				$webhook_payload = array(
					'text'       => "*{$automation['automation_name']}*\n\n" . substr( $content, 0, 4096 ),
					'parse_mode' => 'Markdown',
				);
				break;

			default: // custom
				$webhook_payload = array(
					'automation_id'   => $automation['id'],
					'automation_name' => $automation['automation_name'],
					'trigger_id'      => $automation['trigger_id'],
					'trigger_payload' => $payload,
					'response'        => $result['response'] ?? '',
					'tools_executed'  => $result['tools_executed'] ?? array(),
					'tokens_used'     => $result['tokens_used'] ?? 0,
					'timestamp'       => wp_date( 'c' ),
					'site_url'        => home_url(),
				);
		}

		$response = wp_remote_post( $url, array(
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode( $webhook_payload ),
			'timeout' => 15,
		) );

		if ( is_wp_error( $response ) ) {
			stifli_flex_mcp_log( '[Event Automation] Webhook error: ' . $response->get_error_message() );
		} else {
			stifli_flex_mcp_log( '[Event Automation] Webhook sent to: ' . $url );
		}
	}

	/**
	 * Create draft post
	 *
	 * @param array  $automation Automation configuration.
	 * @param string $content    Post content.
	 * @param array  $payload    Trigger payload.
	 */
	private function create_draft_post( $automation, $content, $payload ) {
		$post_type = $automation['draft_post_type'] ?? 'post';

		// Build post title from automation name and date
		$title = sprintf(
			'%s - %s',
			$automation['automation_name'],
			wp_date( 'Y-m-d H:i' )
		);

		// If payload has a title, use it
		if ( ! empty( $payload['post_title'] ) ) {
			$title = $payload['post_title'] . ' - ' . $automation['automation_name'];
		}

		$post_data = array(
			'post_title'   => $title,
			'post_content' => $content,
			'post_status'  => 'draft',
			'post_type'    => $post_type,
			'post_author'  => $automation['created_by'] ?? 1,
			'meta_input'   => array(
				'_sflmcp_automation_id' => $automation['id'],
				'_sflmcp_trigger_id'    => $automation['trigger_id'],
			),
		);

		$post_id = wp_insert_post( $post_data );

		if ( is_wp_error( $post_id ) ) {
			stifli_flex_mcp_log( '[Event Automation] Draft post error: ' . $post_id->get_error_message() );
		} else {
			stifli_flex_mcp_log( '[Event Automation] Draft post created: ' . $post_id );
		}
	}
}
