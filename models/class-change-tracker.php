<?php
/**
 * Change Tracker — Audit log + rollback/redo for all MCP tool mutations.
 *
 * Records before/after state for every mutating tool dispatch so that
 * operations can be reviewed, rolled back, or re-applied from the admin
 * Changelog UI or through the mcp_* changelog tools.
 *
 * @package StifLi_Flex_MCP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter */

class StifliFlexMcp_ChangeTracker {

	/** @var self|null */
	private static $instance = null;

	/** @var string Resolved table name (with prefix). */
	private $table;

	/** @var string Current MCP session id (if any). */
	private $session_id = '';

	/**
	 * Source context for the current request.
	 * Set by callers before dispatching tools.
	 * array( 'source' => string, 'label' => string )
	 */
	private static $source_context = null;

	/**
	 * Tools that mutate data and should be tracked.
	 * Mapped to their operation type and object type so the tracker knows
	 * what to snapshot before execution.
	 *
	 * Format: 'tool_name' => array( operation_type, object_type, id_arg_key|null )
	 */
	private static $tracked_tools = array(
		// --- WordPress Posts / Pages ---
		'wp_create_post'            => array( 'create', 'post', null ),
		'wp_update_post'            => array( 'update', 'post', 'ID' ),
		'wp_delete_post'            => array( 'delete', 'post', 'ID' ),
		'wp_create_page'            => array( 'create', 'page', null ),
		'wp_update_page'            => array( 'update', 'page', 'ID' ),
		'wp_delete_page'            => array( 'delete', 'page', 'ID' ),
		// --- Comments ---
		'wp_create_comment'         => array( 'create', 'comment', null ),
		'wp_update_comment'         => array( 'update', 'comment', 'comment_ID' ),
		'wp_delete_comment'         => array( 'delete', 'comment', 'comment_ID' ),
		// --- User Meta ---
		'wp_update_user_meta'       => array( 'update', 'user_meta', 'user_id' ),
		'wp_delete_user_meta'       => array( 'delete', 'user_meta', 'user_id' ),
		// --- Terms / Categories / Tags ---
		'wp_create_term'            => array( 'create', 'term', null ),
		'wp_delete_term'            => array( 'delete', 'term', 'term_id' ),
		'wp_create_category'        => array( 'create', 'category', null ),
		'wp_update_category'        => array( 'update', 'category', 'term_id' ),
		'wp_delete_category'        => array( 'delete', 'category', 'term_id' ),
		'wp_create_tag'             => array( 'create', 'tag', null ),
		'wp_update_tag'             => array( 'update', 'tag', 'term_id' ),
		'wp_delete_tag'             => array( 'delete', 'tag', 'term_id' ),
		// --- Media ---
		'wp_upload_image_from_url'  => array( 'file_create', 'media', null ),
		'wp_upload_image'           => array( 'file_create', 'media', null ),
		'wp_generate_image'         => array( 'file_create', 'media', null ),
		'wp_generate_video'         => array( 'file_create', 'media', null ),
		'wp_update_media_item'      => array( 'update', 'media', 'ID' ),
		'wp_delete_media_item'      => array( 'file_delete', 'media', 'ID' ),
		// --- Navigation Menus ---
		'wp_create_nav_menu'        => array( 'create', 'nav_menu', null ),
		'wp_add_nav_menu_item'      => array( 'create', 'nav_menu_item', null ),
		'wp_update_nav_menu_item'   => array( 'update', 'nav_menu_item', 'menu_item_id' ),
		'wp_delete_nav_menu_item'   => array( 'delete', 'nav_menu_item', 'menu_item_id' ),
		'wp_delete_nav_menu'        => array( 'delete', 'nav_menu', 'menu_id' ),
		// --- Options / Settings ---
		'wp_update_option'          => array( 'update', 'option', 'option' ),
		'wp_delete_option'          => array( 'delete', 'option', 'option' ),
		'wp_update_settings'        => array( 'update', 'option', null ),
		// --- Post Meta ---
		'wp_update_post_meta'       => array( 'update', 'post_meta', 'post_id' ),
		'wp_delete_post_meta'       => array( 'delete', 'post_meta', 'post_id' ),
		// --- Revisions ---
		'wp_restore_post_revision'  => array( 'update', 'post', 'revision_id' ),
		// --- WooCommerce Products ---
		'wc_create_product'             => array( 'create', 'product', null ),
		'wc_update_product'             => array( 'update', 'product', 'product_id' ),
		'wc_delete_product'             => array( 'delete', 'product', 'product_id' ),
		'wc_batch_update_products'      => array( 'update', 'product', null ),
		'wc_update_stock'               => array( 'update', 'product_stock', 'product_id' ),
		'wc_set_stock_status'           => array( 'update', 'product_stock', 'product_id' ),
		'wc_create_product_variation'   => array( 'create', 'product_variation', null ),
		'wc_update_product_variation'   => array( 'update', 'product_variation', 'variation_id' ),
		'wc_delete_product_variation'   => array( 'delete', 'product_variation', 'variation_id' ),
		'wc_create_product_category'    => array( 'create', 'product_category', null ),
		'wc_update_product_category'    => array( 'update', 'product_category', 'term_id' ),
		'wc_delete_product_category'    => array( 'delete', 'product_category', 'term_id' ),
		'wc_create_product_tag'         => array( 'create', 'product_tag', null ),
		'wc_update_product_tag'         => array( 'update', 'product_tag', 'term_id' ),
		'wc_delete_product_tag'         => array( 'delete', 'product_tag', 'term_id' ),
		'wc_create_product_review'      => array( 'create', 'product_review', null ),
		'wc_update_product_review'      => array( 'update', 'product_review', 'review_id' ),
		'wc_delete_product_review'      => array( 'delete', 'product_review', 'review_id' ),
		// --- WooCommerce Orders ---
		'wc_create_order'               => array( 'create', 'order', null ),
		'wc_update_order'               => array( 'update', 'order', 'order_id' ),
		'wc_delete_order'               => array( 'delete', 'order', 'order_id' ),
		'wc_batch_update_orders'        => array( 'update', 'order', null ),
		'wc_create_order_note'          => array( 'create', 'order_note', null ),
		'wc_delete_order_note'          => array( 'delete', 'order_note', 'note_id' ),
		'wc_create_refund'              => array( 'create', 'refund', null ),
		'wc_delete_refund'              => array( 'delete', 'refund', 'refund_id' ),
		// --- WooCommerce Coupons ---
		'wc_create_coupon'              => array( 'create', 'coupon', null ),
		'wc_update_coupon'              => array( 'update', 'coupon', 'coupon_id' ),
		'wc_delete_coupon'              => array( 'delete', 'coupon', 'coupon_id' ),
		// --- WooCommerce System ---
		'wc_create_tax_rate'            => array( 'create', 'tax_rate', null ),
		'wc_update_tax_rate'            => array( 'update', 'tax_rate', 'tax_rate_id' ),
		'wc_delete_tax_rate'            => array( 'delete', 'tax_rate', 'tax_rate_id' ),
		'wc_create_shipping_zone'       => array( 'create', 'shipping_zone', null ),
		'wc_update_shipping_zone'       => array( 'update', 'shipping_zone', 'zone_id' ),
		'wc_delete_shipping_zone'       => array( 'delete', 'shipping_zone', 'zone_id' ),
		'wc_update_payment_gateway'     => array( 'update', 'payment_gateway', 'gateway_id' ),
		'wc_update_setting_option'      => array( 'update', 'wc_setting', null ),
		'wc_create_webhook'             => array( 'create', 'webhook', null ),
		'wc_update_webhook'             => array( 'update', 'webhook', 'webhook_id' ),
		'wc_delete_webhook'             => array( 'delete', 'webhook', 'webhook_id' ),
		// --- Snippets ---
		'snippet_create'                => array( 'create', 'snippet', null ),
		'snippet_update'                => array( 'update', 'snippet', 'snippet_id' ),
		'snippet_delete'                => array( 'delete', 'snippet', 'snippet_id' ),
		'snippet_activate'              => array( 'update', 'snippet', 'snippet_id' ),
		'snippet_deactivate'            => array( 'update', 'snippet', 'snippet_id' ),
	);

	private function __construct() {
		global $wpdb;
		$this->table = $wpdb->prefix . 'sflmcp_changelog';
	}

	public static function getInstance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/** Set the current MCP session id for grouping. */
	public function setSessionId( $session_id ) {
		$this->session_id = sanitize_text_field( (string) $session_id );
	}

	/**
	 * Set the source context for the current request.
	 *
	 * @param string $source One of: mcp, chat_agent, copilot, automation, event_automation, wp_admin.
	 * @param string $label  Human-readable label (e.g. OAuth client name, task name).
	 */
	public static function setSourceContext( $source, $label = '' ) {
		self::$source_context = array(
			'source' => sanitize_key( $source ),
			'label'  => sanitize_text_field( (string) $label ),
		);
	}

	/**
	 * Auto-detect the source of the current request.
	 *
	 * @return array array( 'source' => string, 'label' => string )
	 */
	private static function detectSource() {
		if ( null !== self::$source_context ) {
			return self::$source_context;
		}

		// Auto-detect from request context
		if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$action = isset( $_REQUEST['action'] ) ? sanitize_key( $_REQUEST['action'] ) : '';
			if ( 'sflmcp_client_execute_tool' === $action ) {
				return array( 'source' => 'chat_agent', 'label' => 'AI Chat Agent' );
			}
			if ( 'sflmcp_copilot_execute_tool' === $action ) {
				return array( 'source' => 'copilot', 'label' => 'Copilot Editor' );
			}
		}

		if ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) {
			return array( 'source' => 'automation', 'label' => 'Scheduled Task' );
		}

		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return array( 'source' => 'mcp', 'label' => '' );
		}

		return array( 'source' => 'wp_admin', 'label' => '' );
	}

	/** Reset source context (for test isolation). */
	public static function resetSourceContext() {
		self::$source_context = null;
	}

	/* ------------------------------------------------------------------ */
	/*  PUBLIC API : before / after / rollback / redo / query              */
	/* ------------------------------------------------------------------ */

	/**
	 * Capture before-state for a tool about to execute.
	 *
	 * @param string $tool Tool name.
	 * @param array  $args Tool arguments.
	 * @return array|null Snapshot array or null if tool is not tracked.
	 */
	public function captureBeforeState( $tool, $args ) {
		// Check known tools first
		if ( isset( self::$tracked_tools[ $tool ] ) ) {
			$meta = self::$tracked_tools[ $tool ];
			return $this->snapshotBefore( $meta[0], $meta[1], $meta[2], $args );
		}

		// Unknown tool (custom_, ability_, or any future tool) — track generically
		if ( $this->isLikelyMutating( $tool ) ) {
			return array(
				'operation_type' => 'unknown',
				'object_type'    => 'custom',
				'object_id'      => null,
				'object_subtype' => $tool,
				'before_state'   => null,
			);
		}

		return null;
	}

	/**
	 * Record completed change after successful dispatch.
	 *
	 * @param string $tool     Tool name.
	 * @param array  $args     Tool arguments.
	 * @param array  $snapshot Snapshot from captureBeforeState().
	 * @param array  $result   JSON-RPC result array.
	 */
	public function recordChange( $tool, $args, $snapshot, $result ) {
		global $wpdb;

		// Extract created object id from result text when possible
		$object_id = isset( $snapshot['object_id'] ) ? $snapshot['object_id'] : null;
		if ( null === $object_id && isset( $result['result']['content'][0]['text'] ) ) {
			$object_id = $this->extractObjectIdFromResult( $result['result']['content'][0]['text'], $snapshot['object_type'] );
		}

		$after_state  = $this->captureAfterState( $snapshot, $object_id, $args );
		$before_json  = isset( $snapshot['before_state'] ) ? wp_json_encode( $snapshot['before_state'], JSON_UNESCAPED_SLASHES ) : null;
		$after_json   = null !== $after_state ? wp_json_encode( $after_state, JSON_UNESCAPED_SLASHES ) : null;
		$args_json    = wp_json_encode( $args, JSON_UNESCAPED_SLASHES );
		$file_backup  = isset( $snapshot['file_backup_path'] ) ? $snapshot['file_backup_path'] : null;
		$src          = self::detectSource();

		$wpdb->insert(
			$this->table,
			array(
				'session_id'       => $this->session_id ? $this->session_id : null,
				'tool_name'        => sanitize_text_field( $tool ),
				'operation_type'   => sanitize_key( $snapshot['operation_type'] ),
				'object_type'      => sanitize_key( $snapshot['object_type'] ),
				'object_id'        => null !== $object_id ? (string) $object_id : null,
				'object_subtype'   => isset( $snapshot['object_subtype'] ) ? sanitize_text_field( $snapshot['object_subtype'] ) : null,
				'args_json'        => $args_json,
				'before_state'     => $before_json,
				'after_state'      => $after_json,
				'file_backup_path' => $file_backup,
				'source'           => $src['source'],
				'source_label'     => $src['label'] ? $src['label'] : null,
				'user_id'          => get_current_user_id(),
				'ip_address'       => self::getClientIp(),
				'created_at'       => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
		);
	}

	/**
	 * Rollback a single changelog entry.
	 *
	 * @param int $changelog_id Row id in wp_sflmcp_changelog.
	 * @return array array( 'success' => bool, 'message' => string )
	 */
	public function rollback( $changelog_id ) {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM `{$this->table}` WHERE id = %d", $changelog_id ),
			ARRAY_A
		);
		if ( ! $row ) {
			return array( 'success' => false, 'message' => 'Changelog entry not found.' );
		}
		if ( (int) $row['rolled_back'] === 1 ) {
			return array( 'success' => false, 'message' => 'Already rolled back.' );
		}

		$result = $this->executeRollback( $row );
		if ( $result['success'] ) {
			$wpdb->update(
				$this->table,
				array( 'rolled_back' => 1, 'rolled_back_at' => current_time( 'mysql' ) ),
				array( 'id' => $changelog_id ),
				array( '%d', '%s' ),
				array( '%d' )
			);
		}
		return $result;
	}

	/**
	 * Redo (re-apply) a previously rolled-back change.
	 *
	 * @param int $changelog_id Row id.
	 * @return array
	 */
	public function redo( $changelog_id ) {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM `{$this->table}` WHERE id = %d", $changelog_id ),
			ARRAY_A
		);
		if ( ! $row ) {
			return array( 'success' => false, 'message' => 'Changelog entry not found.' );
		}
		if ( (int) $row['rolled_back'] !== 1 ) {
			return array( 'success' => false, 'message' => 'Entry has not been rolled back.' );
		}

		$result = $this->executeRedo( $row );
		if ( $result['success'] ) {
			$wpdb->update(
				$this->table,
				array( 'rolled_back' => 0, 'rolled_back_at' => null ),
				array( 'id' => $changelog_id ),
				array( '%d', '%s' ),
				array( '%d' )
			);
		}
		return $result;
	}

	/**
	 * Rollback all changes in a session (LIFO order).
	 *
	 * @param string $session_id The MCP session id.
	 * @return array
	 */
	public function rollbackSession( $session_id ) {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id FROM `{$this->table}` WHERE session_id = %s AND rolled_back = 0 ORDER BY id DESC",
				$session_id
			),
			ARRAY_A
		);
		if ( empty( $rows ) ) {
			return array( 'success' => false, 'message' => 'No changes found for this session.' );
		}
		$results = array();
		foreach ( $rows as $row ) {
			$results[] = $this->rollback( (int) $row['id'] );
		}
		$ok = count( array_filter( $results, function( $r ) { return $r['success']; } ) );
		return array(
			'success' => $ok > 0,
			'message' => sprintf( '%d of %d changes rolled back.', $ok, count( $results ) ),
			'details' => $results,
		);
	}

	/**
	 * Query changelog with filters.
	 *
	 * @param array $filters Associative: tool_name, object_type, operation_type,
	 *                       session_id, date_from, date_to, rolled_back, limit, offset.
	 * @return array
	 */
	public function getHistory( $filters = array() ) {
		global $wpdb;

		$where  = array( '1=1' );
		$values = array();

		if ( ! empty( $filters['tool_name'] ) ) {
			$where[]  = 'tool_name = %s';
			$values[] = $filters['tool_name'];
		}
		if ( ! empty( $filters['object_type'] ) ) {
			$where[]  = 'object_type = %s';
			$values[] = $filters['object_type'];
		}
		if ( ! empty( $filters['operation_type'] ) ) {
			$where[]  = 'operation_type = %s';
			$values[] = $filters['operation_type'];
		}
		if ( ! empty( $filters['session_id'] ) ) {
			$where[]  = 'session_id = %s';
			$values[] = $filters['session_id'];
		}
		if ( ! empty( $filters['date_from'] ) ) {
			$where[]  = 'created_at >= %s';
			$values[] = $filters['date_from'];
		}
		if ( ! empty( $filters['date_to'] ) ) {
			$where[]  = 'created_at <= %s';
			$values[] = $filters['date_to'];
		}
		if ( isset( $filters['rolled_back'] ) ) {
			$where[]  = 'rolled_back = %d';
			$values[] = (int) $filters['rolled_back'];
		}
		if ( ! empty( $filters['source'] ) ) {
			$where[]  = 'source = %s';
			$values[] = $filters['source'];
		}

		$limit  = isset( $filters['limit'] ) ? max( 1, intval( $filters['limit'] ) ) : 50;
		$offset = isset( $filters['offset'] ) ? max( 0, intval( $filters['offset'] ) ) : 0;

		$where_sql = implode( ' AND ', $where );
		$sql       = "SELECT * FROM `{$this->table}` WHERE {$where_sql} ORDER BY id DESC LIMIT %d OFFSET %d";
		$values[]  = $limit;
		$values[]  = $offset;

		if ( count( $values ) > 2 ) {
			$rows = $wpdb->get_results( $wpdb->prepare( $sql, $values ), ARRAY_A );
		} else {
			$rows = $wpdb->get_results( $wpdb->prepare( $sql, $limit, $offset ), ARRAY_A );
		}

		// Count total
		$count_sql = "SELECT COUNT(*) FROM `{$this->table}` WHERE {$where_sql}";
		if ( count( $values ) > 2 ) {
			$count_values = array_slice( $values, 0, -2 );
			$total = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $count_values ) );
		} else {
			$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$this->table}`" );
		}

		return array( 'total' => $total, 'rows' => is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Purge entries older than N days.
	 *
	 * @param int $days Number of days to keep.
	 * @return int Number of deleted rows.
	 */
	public function purge( $days = 30 ) {
		global $wpdb;
		$cutoff = wp_date( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

		// Clean file backups first
		$backups = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT file_backup_path FROM `{$this->table}` WHERE created_at < %s AND file_backup_path IS NOT NULL",
				$cutoff
			)
		);
		if ( is_array( $backups ) ) {
			foreach ( $backups as $path ) {
				if ( ! empty( $path ) && file_exists( $path ) ) {
					wp_delete_file( $path );
				}
			}
		}

		return (int) $wpdb->query(
			$wpdb->prepare( "DELETE FROM `{$this->table}` WHERE created_at < %s", $cutoff )
		);
	}

	/* ------------------------------------------------------------------ */
	/*  BEFORE-STATE SNAPSHOT                                             */
	/* ------------------------------------------------------------------ */

	private function snapshotBefore( $op, $obj_type, $id_key, $args ) {
		$snap = array(
			'operation_type' => $op,
			'object_type'    => $obj_type,
			'object_id'      => null,
			'object_subtype' => null,
			'before_state'   => null,
		);

		$id = null !== $id_key && isset( $args[ $id_key ] ) ? $args[ $id_key ] : null;
		$snap['object_id'] = $id;

		switch ( $obj_type ) {
			case 'post':
			case 'page':
				if ( $id && ( 'update' === $op || 'delete' === $op ) ) {
					$post = get_post( (int) $id, ARRAY_A );
					if ( $post ) {
						$snap['before_state'] = $post;
						$snap['before_state']['meta'] = get_post_meta( (int) $id );
						$snap['object_subtype'] = isset( $post['post_type'] ) ? $post['post_type'] : null;
					}
				}
				break;

			case 'comment':
				if ( $id && ( 'update' === $op || 'delete' === $op ) ) {
					$comment = get_comment( (int) $id, ARRAY_A );
					if ( $comment ) {
						$snap['before_state'] = $comment;
					}
				}
				break;

			case 'user_meta':
				if ( $id ) {
					$meta_key = isset( $args['meta_key'] ) ? $args['meta_key'] : null;
					if ( $meta_key ) {
						$snap['before_state'] = array(
							'meta_key'   => $meta_key,
							'meta_value' => get_user_meta( (int) $id, $meta_key, true ),
						);
						$snap['object_subtype'] = $meta_key;
					}
				}
				break;

			case 'term':
			case 'category':
			case 'tag':
				if ( $id && ( 'update' === $op || 'delete' === $op ) ) {
					$taxonomy = 'term';
					if ( 'category' === $obj_type ) {
						$taxonomy = 'category';
					} elseif ( 'tag' === $obj_type ) {
						$taxonomy = 'post_tag';
					} elseif ( isset( $args['taxonomy'] ) ) {
						$taxonomy = $args['taxonomy'];
					}
					$term = get_term( (int) $id, $taxonomy, ARRAY_A );
					if ( $term && ! is_wp_error( $term ) ) {
						$snap['before_state'] = $term;
						$snap['object_subtype'] = $taxonomy;
					}
				}
				if ( isset( $args['taxonomy'] ) ) {
					$snap['object_subtype'] = $args['taxonomy'];
				}
				break;

			case 'media':
				if ( $id && ( 'update' === $op || 'file_delete' === $op ) ) {
					$post = get_post( (int) $id, ARRAY_A );
					if ( $post ) {
						$snap['before_state'] = $post;
						$snap['before_state']['meta'] = get_post_meta( (int) $id );
						$snap['before_state']['file'] = get_attached_file( (int) $id );
					}
					// For file_delete, back up the physical file
					if ( 'file_delete' === $op ) {
						$file = get_attached_file( (int) $id );
						if ( $file && file_exists( $file ) ) {
							$backup = $this->backupFile( $file, (int) $id );
							if ( $backup ) {
								$snap['file_backup_path'] = $backup;
							}
						}
					}
				}
				break;

			case 'nav_menu':
			case 'nav_menu_item':
				if ( $id && ( 'update' === $op || 'delete' === $op ) ) {
					if ( 'nav_menu' === $obj_type ) {
						$menu = wp_get_nav_menu_object( (int) $id );
						if ( $menu ) {
							$snap['before_state'] = (array) $menu;
						}
					} else {
						$post = get_post( (int) $id, ARRAY_A );
						if ( $post ) {
							$snap['before_state'] = $post;
							$snap['before_state']['meta'] = get_post_meta( (int) $id );
						}
					}
				}
				break;

			case 'option':
				if ( $id ) {
					$snap['before_state'] = array(
						'option_name'  => $id,
						'option_value' => get_option( $id ),
					);
					$snap['object_subtype'] = 'wp_options';
				}
				break;

			case 'post_meta':
				if ( $id ) {
					$meta_key = isset( $args['meta_key'] ) ? $args['meta_key'] : null;
					if ( $meta_key ) {
						$snap['before_state'] = array(
							'meta_key'   => $meta_key,
							'meta_value' => get_post_meta( (int) $id, $meta_key, true ),
						);
						$snap['object_subtype'] = $meta_key;
					}
				}
				break;

			case 'product':
			case 'product_stock':
				if ( $id && ( 'update' === $op || 'delete' === $op ) && function_exists( 'wc_get_product' ) ) {
					$product = wc_get_product( (int) $id );
					if ( $product ) {
						$snap['before_state'] = $product->get_data();
						$snap['object_subtype'] = $product->get_type();
					}
				}
				break;

			case 'product_variation':
				if ( $id && ( 'update' === $op || 'delete' === $op ) && function_exists( 'wc_get_product' ) ) {
					$variation = wc_get_product( (int) $id );
					if ( $variation ) {
						$snap['before_state'] = $variation->get_data();
					}
				}
				break;

			case 'product_category':
			case 'product_tag':
				$taxonomy = 'product_category' === $obj_type ? 'product_cat' : 'product_tag';
				if ( $id && ( 'update' === $op || 'delete' === $op ) ) {
					$term = get_term( (int) $id, $taxonomy, ARRAY_A );
					if ( $term && ! is_wp_error( $term ) ) {
						$snap['before_state'] = $term;
					}
				}
				$snap['object_subtype'] = $taxonomy;
				break;

			case 'order':
				if ( $id && ( 'update' === $op || 'delete' === $op ) && function_exists( 'wc_get_order' ) ) {
					$order = wc_get_order( (int) $id );
					if ( $order ) {
						$snap['before_state'] = $order->get_data();
					}
				}
				break;

			case 'coupon':
				if ( $id && ( 'update' === $op || 'delete' === $op ) ) {
					$post = get_post( (int) $id, ARRAY_A );
					if ( $post ) {
						$snap['before_state'] = $post;
						$snap['before_state']['meta'] = get_post_meta( (int) $id );
					}
				}
				break;

			case 'tax_rate':
				if ( $id && ( 'update' === $op || 'delete' === $op ) ) {
					global $wpdb;
					$snap['before_state'] = $wpdb->get_row(
						$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}woocommerce_tax_rates WHERE tax_rate_id = %d", (int) $id ),
						ARRAY_A
					);
				}
				break;

			case 'shipping_zone':
				if ( $id && ( 'update' === $op || 'delete' === $op ) && class_exists( 'WC_Shipping_Zone' ) ) {
					$zone = new WC_Shipping_Zone( (int) $id );
					$snap['before_state'] = array(
						'zone_id'    => $zone->get_id(),
						'zone_name'  => $zone->get_zone_name(),
						'zone_order' => $zone->get_zone_order(),
					);
				}
				break;

			case 'payment_gateway':
				if ( $id && function_exists( 'WC' ) ) {
					$gateways = WC()->payment_gateways()->payment_gateways();
					if ( isset( $gateways[ $id ] ) ) {
						$snap['before_state'] = array(
							'id'       => $id,
							'enabled'  => $gateways[ $id ]->enabled,
							'settings' => $gateways[ $id ]->settings,
						);
					}
				}
				break;

			case 'wc_setting':
				// Generic WC setting — just note the args
				$snap['object_subtype'] = isset( $args['group'] ) ? $args['group'] : null;
				if ( ! empty( $args['id'] ) ) {
					$snap['object_id'] = $args['id'];
					$snap['before_state'] = array( 'option_value' => get_option( $args['id'] ) );
				}
				break;

			case 'webhook':
				if ( $id && ( 'update' === $op || 'delete' === $op ) && class_exists( 'WC_Webhook' ) ) {
					$wh = new WC_Webhook( (int) $id );
					$snap['before_state'] = array(
						'webhook_id'   => $wh->get_id(),
						'name'         => $wh->get_name(),
						'status'       => $wh->get_status(),
						'topic'        => $wh->get_topic(),
						'delivery_url' => $wh->get_delivery_url(),
					);
				}
				break;

			default:
				// product_review, order_note, refund, snippet, custom — minimal snapshot
				break;
		}

		return $snap;
	}

	/* ------------------------------------------------------------------ */
	/*  AFTER-STATE CAPTURE                                               */
	/* ------------------------------------------------------------------ */

	private function captureAfterState( $snapshot, $object_id, $args ) {
		$op       = $snapshot['operation_type'];
		$obj_type = $snapshot['object_type'];

		if ( 'delete' === $op || 'file_delete' === $op ) {
			return null; // Object is gone
		}
		if ( null === $object_id ) {
			return null; // Could not determine created id
		}

		switch ( $obj_type ) {
			case 'post':
			case 'page':
				$post = get_post( (int) $object_id, ARRAY_A );
				if ( $post ) {
					$post['meta'] = get_post_meta( (int) $object_id );
					return $post;
				}
				break;

			case 'comment':
				$comment = get_comment( (int) $object_id, ARRAY_A );
				if ( $comment ) {
					return $comment;
				}
				break;

			case 'term':
			case 'category':
			case 'tag':
				$taxonomy = 'term';
				if ( 'category' === $obj_type ) {
					$taxonomy = 'category';
				} elseif ( 'tag' === $obj_type ) {
					$taxonomy = 'post_tag';
				} elseif ( isset( $args['taxonomy'] ) ) {
					$taxonomy = $args['taxonomy'];
				}
				$term = get_term( (int) $object_id, $taxonomy, ARRAY_A );
				if ( $term && ! is_wp_error( $term ) ) {
					return $term;
				}
				break;

			case 'media':
				$post = get_post( (int) $object_id, ARRAY_A );
				if ( $post ) {
					$post['meta'] = get_post_meta( (int) $object_id );
					$post['file'] = get_attached_file( (int) $object_id );
					return $post;
				}
				break;

			case 'nav_menu':
				$menu = wp_get_nav_menu_object( (int) $object_id );
				if ( $menu ) {
					return (array) $menu;
				}
				break;

			case 'nav_menu_item':
				$post = get_post( (int) $object_id, ARRAY_A );
				if ( $post ) {
					$post['meta'] = get_post_meta( (int) $object_id );
					return $post;
				}
				break;

			case 'option':
				return array( 'option_name' => $object_id, 'option_value' => get_option( $object_id ) );

			case 'post_meta':
				$mk = isset( $args['meta_key'] ) ? $args['meta_key'] : null;
				if ( $mk ) {
					return array( 'meta_key' => $mk, 'meta_value' => get_post_meta( (int) $object_id, $mk, true ) );
				}
				break;

			case 'user_meta':
				$mk = isset( $args['meta_key'] ) ? $args['meta_key'] : null;
				if ( $mk ) {
					return array( 'meta_key' => $mk, 'meta_value' => get_user_meta( (int) $object_id, $mk, true ) );
				}
				break;

			case 'product':
			case 'product_stock':
				if ( function_exists( 'wc_get_product' ) ) {
					$p = wc_get_product( (int) $object_id );
					if ( $p ) {
						return $p->get_data();
					}
				}
				break;

			case 'product_variation':
				if ( function_exists( 'wc_get_product' ) ) {
					$v = wc_get_product( (int) $object_id );
					if ( $v ) {
						return $v->get_data();
					}
				}
				break;

			case 'product_category':
			case 'product_tag':
				$taxonomy = 'product_category' === $obj_type ? 'product_cat' : 'product_tag';
				$term = get_term( (int) $object_id, $taxonomy, ARRAY_A );
				if ( $term && ! is_wp_error( $term ) ) {
					return $term;
				}
				break;

			case 'order':
				if ( function_exists( 'wc_get_order' ) ) {
					$o = wc_get_order( (int) $object_id );
					if ( $o ) {
						return $o->get_data();
					}
				}
				break;

			case 'coupon':
				$post = get_post( (int) $object_id, ARRAY_A );
				if ( $post ) {
					$post['meta'] = get_post_meta( (int) $object_id );
					return $post;
				}
				break;

			case 'tax_rate':
				global $wpdb;
				$rate = $wpdb->get_row(
					$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}woocommerce_tax_rates WHERE tax_rate_id = %d", (int) $object_id ),
					ARRAY_A
				);
				if ( $rate ) {
					return $rate;
				}
				break;

			case 'shipping_zone':
				if ( class_exists( 'WC_Shipping_Zone' ) ) {
					$zone = new WC_Shipping_Zone( (int) $object_id );
					return array(
						'zone_id'    => $zone->get_id(),
						'zone_name'  => $zone->get_zone_name(),
						'zone_order' => $zone->get_zone_order(),
					);
				}
				break;

			case 'payment_gateway':
				if ( function_exists( 'WC' ) ) {
					$gateways = WC()->payment_gateways()->payment_gateways();
					if ( isset( $gateways[ $object_id ] ) ) {
						return array(
							'id'       => $object_id,
							'enabled'  => $gateways[ $object_id ]->enabled,
							'settings' => $gateways[ $object_id ]->settings,
						);
					}
				}
				break;

			case 'wc_setting':
				if ( ! empty( $object_id ) ) {
					return array( 'option_value' => get_option( $object_id ) );
				}
				break;

			case 'webhook':
				if ( class_exists( 'WC_Webhook' ) ) {
					$wh = new WC_Webhook( (int) $object_id );
					return array(
						'webhook_id'   => $wh->get_id(),
						'name'         => $wh->get_name(),
						'status'       => $wh->get_status(),
						'topic'        => $wh->get_topic(),
						'delivery_url' => $wh->get_delivery_url(),
					);
				}
				break;
		}

		return null;
	}

	/* ------------------------------------------------------------------ */
	/*  ROLLBACK EXECUTION                                                */
	/* ------------------------------------------------------------------ */

	private function executeRollback( $row ) {
		$op       = $row['operation_type'];
		$obj_type = $row['object_type'];
		$obj_id   = $row['object_id'];
		$before   = ! empty( $row['before_state'] ) ? json_decode( $row['before_state'], true ) : null;
		$after    = ! empty( $row['after_state'] ) ? json_decode( $row['after_state'], true ) : null;

		switch ( $op ) {
			case 'create':
				return $this->rollbackCreate( $obj_type, $obj_id );

			case 'update':
				if ( null === $before ) {
					return array( 'success' => false, 'message' => 'No before-state available for rollback.' );
				}
				return $this->rollbackUpdate( $obj_type, $obj_id, $before );

			case 'delete':
				if ( null === $before ) {
					return array( 'success' => false, 'message' => 'No before-state available for rollback.' );
				}
				return $this->rollbackDelete( $obj_type, $before );

			case 'file_create':
				return $this->rollbackCreate( $obj_type, $obj_id );

			case 'file_delete':
				return $this->rollbackFileDelete( $row, $before );
		}

		return array( 'success' => false, 'message' => 'Unsupported operation type for rollback: ' . $op );
	}

	/** Reverse a create: delete the newly created object. */
	private function rollbackCreate( $obj_type, $obj_id ) {
		if ( empty( $obj_id ) ) {
			return array( 'success' => false, 'message' => 'No object ID to delete.' );
		}

		switch ( $obj_type ) {
			case 'post':
			case 'page':
			case 'nav_menu_item':
			case 'media':
				if ( 'media' === $obj_type ) {
					wp_delete_attachment( (int) $obj_id, true );
				} else {
					wp_delete_post( (int) $obj_id, true );
				}
				return array( 'success' => true, 'message' => "Deleted {$obj_type} #{$obj_id}." );

			case 'comment':
			case 'order_note':
			case 'product_review':
				wp_delete_comment( (int) $obj_id, true );
				return array( 'success' => true, 'message' => "Deleted comment #{$obj_id}." );

			case 'term':
			case 'category':
			case 'tag':
			case 'product_category':
			case 'product_tag':
				$taxonomy = $this->resolveTaxonomy( $obj_type );
				wp_delete_term( (int) $obj_id, $taxonomy );
				return array( 'success' => true, 'message' => "Deleted term #{$obj_id}." );

			case 'nav_menu':
				wp_delete_nav_menu( (int) $obj_id );
				return array( 'success' => true, 'message' => "Deleted nav menu #{$obj_id}." );

			case 'product':
			case 'product_variation':
				wp_delete_post( (int) $obj_id, true );
				return array( 'success' => true, 'message' => "Deleted product #{$obj_id}." );

			case 'order':
				if ( function_exists( 'wc_get_order' ) ) {
					$order = wc_get_order( (int) $obj_id );
					if ( $order ) {
						$order->delete( true );
						return array( 'success' => true, 'message' => "Deleted order #{$obj_id}." );
					}
				}
				return array( 'success' => false, 'message' => "Order #{$obj_id} not found." );

			case 'coupon':
				wp_delete_post( (int) $obj_id, true );
				return array( 'success' => true, 'message' => "Deleted coupon #{$obj_id}." );

			case 'webhook':
				if ( class_exists( 'WC_Webhook' ) ) {
					$wh = new WC_Webhook( (int) $obj_id );
					$wh->delete( true );
					return array( 'success' => true, 'message' => "Deleted webhook #{$obj_id}." );
				}
				return array( 'success' => false, 'message' => 'WooCommerce not available.' );

			case 'snippet':
			case 'custom':
			case 'refund':
			case 'tax_rate':
			case 'shipping_zone':
				return array( 'success' => false, 'message' => "Rollback for {$obj_type} create is not yet supported." );
		}

		return array( 'success' => false, 'message' => "Unknown object type: {$obj_type}." );
	}

	/** Reverse an update: restore from before-state. */
	private function rollbackUpdate( $obj_type, $obj_id, $before ) {
		switch ( $obj_type ) {
			case 'post':
			case 'page':
				$data = $before;
				unset( $data['meta'] );
				wp_update_post( $data );
				if ( ! empty( $before['meta'] ) && is_array( $before['meta'] ) ) {
					foreach ( $before['meta'] as $key => $values ) {
						delete_post_meta( (int) $obj_id, $key );
						if ( is_array( $values ) ) {
							foreach ( $values as $v ) {
								add_post_meta( (int) $obj_id, $key, maybe_unserialize( $v ) );
							}
						}
					}
				}
				return array( 'success' => true, 'message' => "Restored {$obj_type} #{$obj_id} to previous state." );

			case 'comment':
				wp_update_comment( $before );
				return array( 'success' => true, 'message' => "Restored comment #{$obj_id}." );

			case 'user_meta':
				if ( isset( $before['meta_key'] ) ) {
					update_user_meta( (int) $obj_id, $before['meta_key'], $before['meta_value'] );
					return array( 'success' => true, 'message' => "Restored user meta '{$before['meta_key']}' for user #{$obj_id}." );
				}
				return array( 'success' => false, 'message' => 'Missing meta_key in before state.' );

			case 'category':
			case 'tag':
			case 'term':
			case 'product_category':
			case 'product_tag':
				$taxonomy = $this->resolveTaxonomy( $obj_type, $before );
				$update   = array();
				if ( isset( $before['name'] ) ) {
					$update['name'] = $before['name'];
				}
				if ( isset( $before['slug'] ) ) {
					$update['slug'] = $before['slug'];
				}
				if ( isset( $before['description'] ) ) {
					$update['description'] = $before['description'];
				}
				if ( isset( $before['parent'] ) ) {
					$update['parent'] = $before['parent'];
				}
				wp_update_term( (int) $obj_id, $taxonomy, $update );
				return array( 'success' => true, 'message' => "Restored term #{$obj_id}." );

			case 'option':
				if ( isset( $before['option_name'], $before['option_value'] ) ) {
					update_option( $before['option_name'], $before['option_value'] );
					return array( 'success' => true, 'message' => "Restored option '{$before['option_name']}'." );
				}
				return array( 'success' => false, 'message' => 'Incomplete option before-state.' );

			case 'post_meta':
				if ( isset( $before['meta_key'] ) ) {
					update_post_meta( (int) $obj_id, $before['meta_key'], $before['meta_value'] );
					return array( 'success' => true, 'message' => "Restored post meta '{$before['meta_key']}' for post #{$obj_id}." );
				}
				return array( 'success' => false, 'message' => 'Missing meta_key.' );

			case 'media':
				$data = $before;
				unset( $data['meta'], $data['file'] );
				wp_update_post( $data );
				return array( 'success' => true, 'message' => "Restored media #{$obj_id} metadata." );

			case 'nav_menu_item':
				$data = $before;
				unset( $data['meta'] );
				wp_update_post( $data );
				return array( 'success' => true, 'message' => "Restored menu item #{$obj_id}." );

			case 'product':
			case 'product_stock':
				if ( function_exists( 'wc_get_product' ) ) {
					$product = wc_get_product( (int) $obj_id );
					if ( $product ) {
						if ( isset( $before['name'] ) ) {
							$product->set_name( $before['name'] );
						}
						if ( isset( $before['status'] ) ) {
							$product->set_status( $before['status'] );
						}
						if ( isset( $before['regular_price'] ) ) {
							$product->set_regular_price( $before['regular_price'] );
						}
						if ( isset( $before['sale_price'] ) ) {
							$product->set_sale_price( $before['sale_price'] );
						}
						if ( isset( $before['stock_quantity'] ) ) {
							$product->set_stock_quantity( $before['stock_quantity'] );
						}
						if ( isset( $before['stock_status'] ) ) {
							$product->set_stock_status( $before['stock_status'] );
						}
						if ( isset( $before['description'] ) ) {
							$product->set_description( $before['description'] );
						}
						if ( isset( $before['short_description'] ) ) {
							$product->set_short_description( $before['short_description'] );
						}
						$product->save();
						return array( 'success' => true, 'message' => "Restored product #{$obj_id}." );
					}
				}
				return array( 'success' => false, 'message' => "Product #{$obj_id} not found." );

			case 'order':
				if ( function_exists( 'wc_get_order' ) ) {
					$order = wc_get_order( (int) $obj_id );
					if ( $order ) {
						if ( isset( $before['status'] ) ) {
							$order->set_status( $before['status'] );
						}
						$order->save();
						return array( 'success' => true, 'message' => "Restored order #{$obj_id} status." );
					}
				}
				return array( 'success' => false, 'message' => "Order #{$obj_id} not found." );

			case 'coupon':
				$data = $before;
				unset( $data['meta'] );
				wp_update_post( $data );
				if ( ! empty( $before['meta'] ) ) {
					foreach ( $before['meta'] as $key => $values ) {
						delete_post_meta( (int) $obj_id, $key );
						if ( is_array( $values ) ) {
							foreach ( $values as $v ) {
								add_post_meta( (int) $obj_id, $key, maybe_unserialize( $v ) );
							}
						}
					}
				}
				return array( 'success' => true, 'message' => "Restored coupon #{$obj_id}." );

			case 'payment_gateway':
				if ( isset( $before['settings'] ) && function_exists( 'WC' ) ) {
					$gateways = WC()->payment_gateways()->payment_gateways();
					if ( isset( $gateways[ $obj_id ] ) ) {
						update_option( $gateways[ $obj_id ]->get_option_key(), $before['settings'] );
						return array( 'success' => true, 'message' => "Restored payment gateway '{$obj_id}'." );
					}
				}
				return array( 'success' => false, 'message' => 'Could not restore payment gateway.' );

			case 'wc_setting':
				if ( isset( $before['option_value'] ) && ! empty( $obj_id ) ) {
					update_option( $obj_id, $before['option_value'] );
					return array( 'success' => true, 'message' => "Restored WC setting '{$obj_id}'." );
				}
				return array( 'success' => false, 'message' => 'Incomplete WC setting state.' );

			default:
				return array( 'success' => false, 'message' => "Rollback for {$obj_type} update not yet supported." );
		}
	}

	/** Reverse a delete: re-create from before-state. */
	private function rollbackDelete( $obj_type, $before ) {
		switch ( $obj_type ) {
			case 'post':
			case 'page':
				$data = $before;
				$meta = isset( $data['meta'] ) ? $data['meta'] : array();
				unset( $data['meta'], $data['ID'] );
				$new_id = wp_insert_post( $data );
				if ( $new_id && ! is_wp_error( $new_id ) ) {
					foreach ( $meta as $key => $values ) {
						if ( is_array( $values ) ) {
							foreach ( $values as $v ) {
								add_post_meta( $new_id, $key, maybe_unserialize( $v ) );
							}
						}
					}
					return array( 'success' => true, 'message' => "Re-created {$obj_type} as #{$new_id} (original was #{$before['ID']})." );
				}
				return array( 'success' => false, 'message' => 'Failed to re-create post.' );

			case 'comment':
				$data = $before;
				unset( $data['comment_ID'] );
				$new_id = wp_insert_comment( $data );
				if ( $new_id ) {
					return array( 'success' => true, 'message' => "Re-created comment as #{$new_id}." );
				}
				return array( 'success' => false, 'message' => 'Failed to re-create comment.' );

			case 'term':
			case 'category':
			case 'tag':
			case 'product_category':
			case 'product_tag':
				$taxonomy = $this->resolveTaxonomy( $obj_type, $before );
				$name     = isset( $before['name'] ) ? $before['name'] : '';
				$term_args = array();
				if ( isset( $before['slug'] ) ) {
					$term_args['slug'] = $before['slug'];
				}
				if ( isset( $before['description'] ) ) {
					$term_args['description'] = $before['description'];
				}
				if ( isset( $before['parent'] ) ) {
					$term_args['parent'] = $before['parent'];
				}
				$result = wp_insert_term( $name, $taxonomy, $term_args );
				if ( ! is_wp_error( $result ) ) {
					return array( 'success' => true, 'message' => "Re-created term '{$name}' as #{$result['term_id']}." );
				}
				return array( 'success' => false, 'message' => 'Failed to re-create term: ' . $result->get_error_message() );

			case 'option':
				if ( isset( $before['option_name'], $before['option_value'] ) ) {
					update_option( $before['option_name'], $before['option_value'] );
					return array( 'success' => true, 'message' => "Restored option '{$before['option_name']}'." );
				}
				return array( 'success' => false, 'message' => 'Incomplete option data.' );

			case 'nav_menu':
				if ( isset( $before['name'] ) ) {
					$id = wp_create_nav_menu( $before['name'] );
					if ( ! is_wp_error( $id ) ) {
						return array( 'success' => true, 'message' => "Re-created nav menu '{$before['name']}' as #{$id}." );
					}
				}
				return array( 'success' => false, 'message' => 'Failed to re-create nav menu.' );

			case 'coupon':
				$data = $before;
				$meta = isset( $data['meta'] ) ? $data['meta'] : array();
				unset( $data['meta'], $data['ID'] );
				$new_id = wp_insert_post( $data );
				if ( $new_id && ! is_wp_error( $new_id ) ) {
					foreach ( $meta as $key => $values ) {
						if ( is_array( $values ) ) {
							foreach ( $values as $v ) {
								add_post_meta( $new_id, $key, maybe_unserialize( $v ) );
							}
						}
					}
					return array( 'success' => true, 'message' => "Re-created coupon as #{$new_id}." );
				}
				return array( 'success' => false, 'message' => 'Failed to re-create coupon.' );

			default:
				return array( 'success' => false, 'message' => "Rollback for {$obj_type} delete not yet supported." );
		}
	}

	/** Reverse a file delete: restore file from backup. */
	private function rollbackFileDelete( $row, $before ) {
		$backup = $row['file_backup_path'];
		if ( empty( $backup ) || ! file_exists( $backup ) ) {
			return array( 'success' => false, 'message' => 'File backup not available.' );
		}
		if ( empty( $before ) || ! isset( $before['file'] ) ) {
			return array( 'success' => false, 'message' => 'Original file path unknown.' );
		}

		$original_path = $before['file'];
		$dir = dirname( $original_path );
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		// Restore file
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy
		if ( ! copy( $backup, $original_path ) ) {
			return array( 'success' => false, 'message' => 'Failed to restore file from backup.' );
		}

		// Re-create attachment post
		$data = $before;
		unset( $data['meta'], $data['file'] );
		$data['ID'] = 0;
		$new_id = wp_insert_attachment( $data, $original_path );
		if ( $new_id && ! is_wp_error( $new_id ) ) {
			// Re-generate attachment metadata
			require_once ABSPATH . 'wp-admin/includes/image.php';
			$attach_data = wp_generate_attachment_metadata( $new_id, $original_path );
			wp_update_attachment_metadata( $new_id, $attach_data );

			// Restore custom meta
			if ( ! empty( $before['meta'] ) ) {
				foreach ( $before['meta'] as $key => $values ) {
					if ( is_array( $values ) ) {
						foreach ( $values as $v ) {
							add_post_meta( $new_id, $key, maybe_unserialize( $v ) );
						}
					}
				}
			}

			return array( 'success' => true, 'message' => "Restored media file and created attachment #{$new_id}." );
		}

		return array( 'success' => false, 'message' => 'File restored but attachment creation failed.' );
	}

	/* ------------------------------------------------------------------ */
	/*  REDO EXECUTION                                                    */
	/* ------------------------------------------------------------------ */

	private function executeRedo( $row ) {
		$op       = $row['operation_type'];
		$obj_type = $row['object_type'];
		$after    = ! empty( $row['after_state'] ) ? json_decode( $row['after_state'], true ) : null;
		$args     = ! empty( $row['args_json'] ) ? json_decode( $row['args_json'], true ) : array();

		if ( 'create' === $op || 'file_create' === $op ) {
			// Re-execute the original tool with the same arguments
			if ( class_exists( 'StifliFlexMcpModel' ) ) {
				$model  = new StifliFlexMcpModel();
				$result = $model->dispatchTool( $row['tool_name'], $args );
				if ( ! isset( $result['error'] ) ) {
					return array( 'success' => true, 'message' => "Re-executed {$row['tool_name']}." );
				}
				return array( 'success' => false, 'message' => 'Re-execution failed: ' . ( isset( $result['error']['message'] ) ? $result['error']['message'] : 'Unknown error' ) );
			}
			return array( 'success' => false, 'message' => 'Model not available.' );
		}

		if ( 'update' === $op && null !== $after ) {
			// Apply the after-state again
			return $this->applyState( $obj_type, $row['object_id'], $after );
		}

		if ( 'delete' === $op || 'file_delete' === $op ) {
			// Re-delete the object (it was re-created by rollback)
			return $this->rollbackCreate( $obj_type, $row['object_id'] );
		}

		return array( 'success' => false, 'message' => 'Redo not supported for this operation.' );
	}

	/** Apply a captured state as the new state (for redo of updates). */
	private function applyState( $obj_type, $obj_id, $state ) {
		switch ( $obj_type ) {
			case 'post':
			case 'page':
				$data = $state;
				unset( $data['meta'] );
				$data['ID'] = (int) $obj_id;
				wp_update_post( $data );
				return array( 'success' => true, 'message' => "Re-applied state to {$obj_type} #{$obj_id}." );

			case 'option':
				if ( isset( $state['option_name'], $state['option_value'] ) ) {
					update_option( $state['option_name'], $state['option_value'] );
					return array( 'success' => true, 'message' => "Re-applied option '{$state['option_name']}'." );
				}
				break;

			case 'product':
			case 'product_stock':
				if ( function_exists( 'wc_get_product' ) ) {
					$product = wc_get_product( (int) $obj_id );
					if ( $product ) {
						if ( isset( $state['name'] ) ) {
							$product->set_name( $state['name'] );
						}
						if ( isset( $state['regular_price'] ) ) {
							$product->set_regular_price( $state['regular_price'] );
						}
						if ( isset( $state['stock_quantity'] ) ) {
							$product->set_stock_quantity( $state['stock_quantity'] );
						}
						$product->save();
						return array( 'success' => true, 'message' => "Re-applied state to product #{$obj_id}." );
					}
				}
				break;
		}

		return array( 'success' => false, 'message' => "Redo apply-state not supported for {$obj_type}." );
	}

	/* ------------------------------------------------------------------ */
	/*  HELPERS                                                           */
	/* ------------------------------------------------------------------ */

	/** Determine if a tool name looks like it mutates. */
	private function isLikelyMutating( $tool ) {
		return (bool) preg_match( '/^(custom_|ability_)/', $tool );
	}

	/** Extract a numeric object ID from the result text. */
	private function extractObjectIdFromResult( $text, $obj_type ) {
		// Common patterns: "ID: 42", "id":42, "#42", "Post 42 created"
		if ( preg_match( '/"id"\s*:\s*(\d+)/i', $text, $m ) ) {
			return $m[1];
		}
		if ( preg_match( '/(?:ID|#|id)\s*[:=]?\s*(\d+)/', $text, $m ) ) {
			return $m[1];
		}
		if ( preg_match( '/(\d+)\s+created/i', $text, $m ) ) {
			return $m[1];
		}
		return null;
	}

	/** Resolve taxonomy name from object_type. */
	private function resolveTaxonomy( $obj_type, $before = null ) {
		switch ( $obj_type ) {
			case 'category':
				return 'category';
			case 'tag':
				return 'post_tag';
			case 'product_category':
				return 'product_cat';
			case 'product_tag':
				return 'product_tag';
			default:
				return isset( $before['taxonomy'] ) ? $before['taxonomy'] : 'category';
		}
	}

	/** Safely retrieve client IP. */
	private static function getClientIp() {
		if ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
			return sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
		}
		return '';
	}

	/** Backup a physical file before deletion. */
	private function backupFile( $file_path, $attachment_id ) {
		$upload_dir = wp_upload_dir();
		$backup_dir = $upload_dir['basedir'] . '/sflmcp-backups/' . gmdate( 'Y/m' );
		if ( ! is_dir( $backup_dir ) ) {
			wp_mkdir_p( $backup_dir );
			// Protect backup directory
			$htaccess = dirname( $backup_dir, 2 ) . '/.htaccess';
			if ( ! file_exists( $htaccess ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				file_put_contents( $htaccess, "Order deny,allow\nDeny from all" );
			}
			$index = dirname( $backup_dir, 2 ) . '/index.php';
			if ( ! file_exists( $index ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				file_put_contents( $index, '<?php // Silence is golden.' );
			}
		}
		$ext    = pathinfo( $file_path, PATHINFO_EXTENSION );
		$backup = $backup_dir . '/media_' . $attachment_id . '_' . time() . '.' . $ext;

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy
		if ( copy( $file_path, $backup ) ) {
			return $backup;
		}
		return null;
	}

	/* ------------------------------------------------------------------ */
	/*  TABLE CREATION (called on activation)                             */
	/* ------------------------------------------------------------------ */

	public static function createTable() {
		global $wpdb;
		$table = $wpdb->prefix . 'sflmcp_changelog';
		$like  = $wpdb->esc_like( $table );
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) );
		if ( $exists === $table ) {
			return;
		}
		$charset_collate = $wpdb->get_charset_collate();
		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			session_id VARCHAR(191) DEFAULT NULL,
			tool_name VARCHAR(191) NOT NULL,
			operation_type VARCHAR(30) NOT NULL,
			object_type VARCHAR(50) NOT NULL,
			object_id VARCHAR(191) DEFAULT NULL,
			object_subtype VARCHAR(100) DEFAULT NULL,
			args_json LONGTEXT DEFAULT NULL,
			before_state LONGTEXT DEFAULT NULL,
			after_state LONGTEXT DEFAULT NULL,
			file_backup_path VARCHAR(500) DEFAULT NULL,
			source VARCHAR(50) DEFAULT NULL,
			source_label VARCHAR(255) DEFAULT NULL,
			user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			ip_address VARCHAR(45) DEFAULT NULL,
			rolled_back TINYINT(1) NOT NULL DEFAULT 0,
			rolled_back_at DATETIME DEFAULT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY idx_session (session_id),
			KEY idx_tool (tool_name),
			KEY idx_object (object_type, object_id),
			KEY idx_created (created_at),
			KEY idx_rolled_back (rolled_back),
			KEY idx_source (source)
		) {$charset_collate};";
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Add source columns to existing tables (migration).
	 * Safe to call multiple times — checks before altering.
	 */
	public static function migrateAddSourceColumns() {
		global $wpdb;
		$table = $wpdb->prefix . 'sflmcp_changelog';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$cols = $wpdb->get_col( "SHOW COLUMNS FROM `{$table}`", 0 );
		if ( ! in_array( 'source', $cols, true ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN `source` VARCHAR(50) DEFAULT NULL AFTER `file_backup_path`, ADD COLUMN `source_label` VARCHAR(255) DEFAULT NULL AFTER `source`, ADD KEY `idx_source` (`source`)" );
		}
	}
}

/* phpcs:enable */
