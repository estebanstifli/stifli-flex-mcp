<?php
/**
 * WooCommerce Orders Tools
 * Handles orders and order notes
 */

if (!defined('ABSPATH')) {
    exit;
}

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- StifliFlexMcp is the plugin prefix
class StifliFlexMcp_WC_Orders {
    
    public static function getTools() {
        return array(
            // Orders
            'wc_get_orders' => array(
                'name' => 'wc_get_orders',
                'description' => 'List WooCommerce orders with filters (status, customer, product, limit, offset, orderby, order, after, before).',
                'inputSchema' => array(
                    'type' => 'object',
                    'properties' => array(
                        'status'   => array('type' => 'string'),
                        'customer' => array('type' => 'integer'),
                        'product'  => array('type' => 'integer'),
                        'limit'    => array('type' => 'integer'),
                        'offset'   => array('type' => 'integer'),
                        'orderby'  => array('type' => 'string'),
                        'order'    => array('type' => 'string'),
                        'after'    => array('type' => 'string'),
                        'before'   => array('type' => 'string'),
                    ),
                    'required' => array(),
                ),
            ),
            'wc_create_order' => array(
                'name' => 'wc_create_order',
                'description' => 'Create a WooCommerce order (customer_id, billing, shipping, line_items, shipping_lines, fee_lines, coupon_lines, status, payment_method).',
                'inputSchema' => array(
                    'type' => 'object',
                    'properties' => array(
                        'customer_id'    => array('type' => 'integer'),
                        'billing'        => array('type' => 'object'),
                        'shipping'       => array('type' => 'object'),
                        'line_items'     => array('type' => 'array'),
                        'shipping_lines' => array('type' => 'array'),
                        'fee_lines'      => array('type' => 'array'),
                        'coupon_lines'   => array('type' => 'array'),
                        'status'         => array('type' => 'string'),
                        'payment_method' => array('type' => 'string'),
                    ),
                    'required' => array(),
                ),
            ),
            'wc_update_order' => array(
                'name' => 'wc_update_order',
                'description' => 'Update a WooCommerce order by ID.',
                'inputSchema' => array(
                    'type' => 'object',
                    'properties' => array(
                        'order_id'       => array('type' => 'integer'),
                        'status'         => array('type' => 'string'),
                        'billing'        => array('type' => 'object'),
                        'shipping'       => array('type' => 'object'),
                        'line_items'     => array('type' => 'array'),
                        'payment_method' => array('type' => 'string'),
                    ),
                    'required' => array('order_id'),
                ),
            ),
            'wc_delete_order' => array(
                'name' => 'wc_delete_order',
                'description' => 'Delete a WooCommerce order by ID. Pass force=true to delete permanently.',
                'inputSchema' => array(
                    'type' => 'object',
                    'properties' => array(
                        'order_id' => array('type' => 'integer'),
                        'force'    => array('type' => 'boolean'),
                    ),
                    'required' => array('order_id'),
                ),
            ),
            'wc_batch_update_orders' => array(
                'name' => 'wc_batch_update_orders',
                'description' => 'Batch update multiple orders at once. Each item in updates array requires order_id and optional status, meta_data, etc.',
                'inputSchema' => array(
                    'type' => 'object',
                    'properties' => array(
                        'updates' => array(
                            'type' => 'array',
                            'description' => 'Array of order updates. Each item: { order_id: int, status?: string, meta_data?: array }',
                        ),
                    ),
                    'required' => array('updates'),
                ),
            ),
            
            // Order Notes
            'wc_get_order_notes' => array(
                'name' => 'wc_get_order_notes',
                'description' => 'Get notes for a specific order.',
                'inputSchema' => array(
                    'type' => 'object',
                    'properties' => array(
                        'order_id' => array('type' => 'integer'),
                        'type'     => array('type' => 'string'),
                    ),
                    'required' => array('order_id'),
                ),
            ),
            'wc_create_order_note' => array(
                'name' => 'wc_create_order_note',
                'description' => 'Create a note for an order.',
                'inputSchema' => array(
                    'type' => 'object',
                    'properties' => array(
                        'order_id'         => array('type' => 'integer'),
                        'note'             => array('type' => 'string'),
                        'customer_note'    => array('type' => 'boolean'),
                        'added_by_user'    => array('type' => 'boolean'),
                    ),
                    'required' => array('order_id', 'note'),
                ),
            ),
            'wc_delete_order_note' => array(
                'name' => 'wc_delete_order_note',
                'description' => 'Delete an order note.',
                'inputSchema' => array(
                    'type' => 'object',
                    'properties' => array(
                        'order_id' => array('type' => 'integer'),
                        'note_id'  => array('type' => 'integer'),
                        'force'    => array('type' => 'boolean'),
                    ),
                    'required' => array('order_id', 'note_id'),
                ),
            ),
            // Refunds Management
            'wc_create_refund' => array(
                'name' => 'wc_create_refund',
                'description' => 'Create a refund for an order. Returns the created refund ID and details.',
                'inputSchema' => array(
                    'type' => 'object',
                    'properties' => array(
                        'order_id'     => array('type' => 'integer', 'description' => 'Order ID to refund'),
                        'amount'       => array('type' => 'number', 'description' => 'Refund amount (optional, defaults to 0)'),
                        'reason'       => array('type' => 'string', 'description' => 'Reason for refund'),
                        'line_items'   => array(
                            'type' => 'array',
                            'description' => 'Array of line items to refund with qty and refund_total',
                            'items' => array(
                                'type' => 'object',
                                'properties' => array(
                                    'id'           => array('type' => 'integer', 'description' => 'Order item ID'),
                                    'qty'          => array('type' => 'integer', 'description' => 'Quantity to refund'),
                                    'refund_total' => array('type' => 'number', 'description' => 'Refund amount for this item'),
                                    'refund_tax'   => array(
                                        'type' => 'array',
                                        'description' => 'Tax refund amounts by rate ID',
                                        'items' => array('type' => 'number'),
                                    ),
                                ),
                            ),
                        ),
                        'restock_items' => array('type' => 'boolean', 'description' => 'Whether to restock items (default false)'),
                    ),
                    'required' => array('order_id'),
                ),
            ),
            'wc_get_refunds' => array(
                'name' => 'wc_get_refunds',
                'description' => 'Get refunds for an order or all refunds. Returns array of refund objects.',
                'inputSchema' => array(
                    'type' => 'object',
                    'properties' => array(
                        'order_id' => array('type' => 'integer', 'description' => 'Order ID (optional, returns all refunds if omitted)'),
                        'limit'    => array('type' => 'integer', 'description' => 'Number of refunds to return (default 50)'),
                        'offset'   => array('type' => 'integer', 'description' => 'Offset for pagination (default 0)'),
                    ),
                    'required' => array(),
                ),
            ),
            'wc_delete_refund' => array(
                'name' => 'wc_delete_refund',
                'description' => 'Delete a refund by ID.',
                'inputSchema' => array(
                    'type' => 'object',
                    'properties' => array(
                        'refund_id' => array('type' => 'integer', 'description' => 'Refund ID to delete'),
                        'force'     => array('type' => 'boolean', 'description' => 'Whether to bypass trash and force deletion (default false)'),
                    ),
                    'required' => array('refund_id'),
                ),
            ),
        );
    }
    
    public static function getCapabilities() {
        return array(
            'wc_create_order' => 'edit_shop_orders',
            'wc_update_order' => 'edit_shop_orders',
            'wc_delete_order' => 'delete_shop_orders',
            'wc_batch_update_orders' => 'edit_shop_orders',
            'wc_create_order_note' => 'edit_shop_orders',
            'wc_delete_order_note' => 'edit_shop_orders',
            // Refunds capabilities
            'wc_create_refund' => 'edit_shop_orders',
            'wc_delete_refund' => 'delete_shop_orders',
        );
    }
    
    public static function dispatch($tool, $args, &$r, $addResultText, $utils) {
        if (!class_exists('WooCommerce')) {
            $r['error'] = array('code' => -50000, 'message' => 'WooCommerce is not active');
            return true;
        }
        
        switch ($tool) {
            case 'wc_get_orders':
                $query_args = array(
                    'limit' => intval($utils::getArrayValue($args, 'limit', 10)),
                    'offset' => intval($utils::getArrayValue($args, 'offset', 0)),
                    'orderby' => sanitize_key($utils::getArrayValue($args, 'orderby', 'date')),
                    'order' => sanitize_key($utils::getArrayValue($args, 'order', 'DESC')),
                );
                
                if (!empty($args['status'])) {
                    $query_args['status'] = sanitize_text_field($args['status']);
                }
                if (!empty($args['customer_id'])) {
                    $query_args['customer_id'] = intval($args['customer_id']);
                }
                if (!empty($args['product_id'])) {
                    $query_args['product'] = intval($args['product_id']);
                }
                if (!empty($args['date_created_min'])) {
                    $query_args['date_created'] = '>=' . sanitize_text_field($args['date_created_min']);
                }
                if (!empty($args['date_created_max'])) {
                    $query_args['date_created'] = '<=' . sanitize_text_field($args['date_created_max']);
                }
                
                $orders = wc_get_orders($query_args);
                $result = array();
                
                foreach ($orders as $order) {
                    $result[] = array(
                        'id' => $order->get_id(),
                        'status' => $order->get_status(),
                        'total' => $order->get_total(),
                        'currency' => $order->get_currency(),
                        'date_created' => $order->get_date_created()->date('Y-m-d H:i:s'),
                        'customer_id' => $order->get_customer_id(),
                        'billing' => array(
                            'first_name' => $order->get_billing_first_name(),
                            'last_name' => $order->get_billing_last_name(),
                            'email' => $order->get_billing_email(),
                        ),
                        'items_count' => count($order->get_items()),
                    );
                }
                
                $addResultText($r, 'Found ' . count($result) . ' orders: ' . wp_json_encode($result, JSON_PRETTY_PRINT));
                return true;
                
            case 'wc_create_order':
                $order = wc_create_order();
                
                if (!empty($args['status'])) {
                    $order->set_status(sanitize_key($args['status']));
                }
                if (!empty($args['customer_id'])) {
                    $order->set_customer_id(intval($args['customer_id']));
                }
                
                // Billing
                if (!empty($args['billing']) && is_array($args['billing'])) {
                    $order->set_billing_first_name(sanitize_text_field($utils::getArrayValue($args['billing'], 'first_name', '')));
                    $order->set_billing_last_name(sanitize_text_field($utils::getArrayValue($args['billing'], 'last_name', '')));
                    $order->set_billing_email(sanitize_email($utils::getArrayValue($args['billing'], 'email', '')));
                    $order->set_billing_phone(sanitize_text_field($utils::getArrayValue($args['billing'], 'phone', '')));
                    $order->set_billing_address_1(sanitize_text_field($utils::getArrayValue($args['billing'], 'address_1', '')));
                    $order->set_billing_address_2(sanitize_text_field($utils::getArrayValue($args['billing'], 'address_2', '')));
                    $order->set_billing_city(sanitize_text_field($utils::getArrayValue($args['billing'], 'city', '')));
                    $order->set_billing_postcode(sanitize_text_field($utils::getArrayValue($args['billing'], 'postcode', '')));
                    $order->set_billing_country(sanitize_text_field($utils::getArrayValue($args['billing'], 'country', '')));
                    $order->set_billing_state(sanitize_text_field($utils::getArrayValue($args['billing'], 'state', '')));
                }
                
                // Shipping
                if (!empty($args['shipping']) && is_array($args['shipping'])) {
                    $order->set_shipping_first_name(sanitize_text_field($utils::getArrayValue($args['shipping'], 'first_name', '')));
                    $order->set_shipping_last_name(sanitize_text_field($utils::getArrayValue($args['shipping'], 'last_name', '')));
                    $order->set_shipping_address_1(sanitize_text_field($utils::getArrayValue($args['shipping'], 'address_1', '')));
                    $order->set_shipping_address_2(sanitize_text_field($utils::getArrayValue($args['shipping'], 'address_2', '')));
                    $order->set_shipping_city(sanitize_text_field($utils::getArrayValue($args['shipping'], 'city', '')));
                    $order->set_shipping_postcode(sanitize_text_field($utils::getArrayValue($args['shipping'], 'postcode', '')));
                    $order->set_shipping_country(sanitize_text_field($utils::getArrayValue($args['shipping'], 'country', '')));
                    $order->set_shipping_state(sanitize_text_field($utils::getArrayValue($args['shipping'], 'state', '')));
                }
                
                // Line items
                if (!empty($args['line_items']) && is_array($args['line_items'])) {
                    foreach ($args['line_items'] as $item) {
                        $product_id = intval($utils::getArrayValue($item, 'product_id', 0));
                        $quantity = intval($utils::getArrayValue($item, 'quantity', 1));
                        
                        if ($product_id > 0) {
                            $product = wc_get_product($product_id);
                            if ($product) {
                                $order->add_product($product, $quantity);
                            }
                        }
                    }
                }
                
                // Payment method
                if (!empty($args['payment_method'])) {
                    $order->set_payment_method(sanitize_text_field($args['payment_method']));
                }
                if (!empty($args['payment_method_title'])) {
                    $order->set_payment_method_title(sanitize_text_field($args['payment_method_title']));
                }
                
                $order->calculate_totals();
                $order_id = $order->save();
                
                $addResultText($r, 'Order created with ID: ' . $order_id);
                return true;
                
            case 'wc_update_order':
                $order_id = intval($utils::getArrayValue($args, 'order_id', 0));
                if (empty($order_id)) {
                    $r['error'] = array('code' => -50001, 'message' => 'order_id is required');
                    return true;
                }
                
                $order = wc_get_order($order_id);
                if (!$order) {
                    $r['error'] = array('code' => -50002, 'message' => 'Order not found');
                    return true;
                }
                
                if (!empty($args['status'])) {
                    $order->set_status(sanitize_key($args['status']));
                }
                if (isset($args['customer_id'])) {
                    $order->set_customer_id(intval($args['customer_id']));
                }
                
                // Update billing if provided
                if (isset($args['billing']) && is_array($args['billing'])) {
                    foreach ($args['billing'] as $key => $value) {
                        $method = 'set_billing_' . $key;
                        if (method_exists($order, $method)) {
                            $order->$method(sanitize_text_field($value));
                        }
                    }
                }
                
                // Update shipping if provided
                if (isset($args['shipping']) && is_array($args['shipping'])) {
                    foreach ($args['shipping'] as $key => $value) {
                        $method = 'set_shipping_' . $key;
                        if (method_exists($order, $method)) {
                            $order->$method(sanitize_text_field($value));
                        }
                    }
                }
                
                $order->calculate_totals();
                $order->save();
                
                $addResultText($r, 'Order updated: ' . $order_id);
                return true;
                
            case 'wc_delete_order':
                $order_id = intval($utils::getArrayValue($args, 'order_id', 0));
                if (empty($order_id)) {
                    $r['error'] = array('code' => -50001, 'message' => 'order_id is required');
                    return true;
                }
                
                $force = (bool) $utils::getArrayValue($args, 'force', false);
                $order = wc_get_order($order_id);
                
                if (!$order) {
                    $r['error'] = array('code' => -50002, 'message' => 'Order not found');
                    return true;
                }
                
                $result = $order->delete($force);
                
                if ($result) {
                    $addResultText($r, 'Order deleted: ' . $order_id);
                } else {
                    $r['error'] = array('code' => -50003, 'message' => 'Failed to delete order');
                }
                return true;
                
            case 'wc_batch_update_orders':
                $updates = $utils::getArrayValue($args, 'updates', array());
                if (empty($updates) || !is_array($updates)) {
                    $r['error'] = array('code' => -50001, 'message' => 'updates array is required');
                    return true;
                }
                
                $results = array();
                foreach ($updates as $update) {
                    if (empty($update['order_id'])) {
                        continue;
                    }
                    
                    $order = wc_get_order(intval($update['order_id']));
                    if (!$order) {
                        continue;
                    }
                    
                    if (isset($update['status'])) {
                        $order->set_status(sanitize_key($update['status']));
                    }
                    
                    $order->save();
                    $results[] = $update['order_id'];
                }
                
                $addResultText($r, 'Updated ' . count($results) . ' orders: ' . implode(', ', $results));
                return true;
                
            case 'wc_get_order_notes':
                $order_id = intval($utils::getArrayValue($args, 'order_id', 0));
                if (empty($order_id)) {
                    $r['error'] = array('code' => -50001, 'message' => 'order_id is required');
                    return true;
                }
                
                $order = wc_get_order($order_id);
                if (!$order) {
                    $r['error'] = array('code' => -50002, 'message' => 'Order not found');
                    return true;
                }
                
                $type = sanitize_text_field($utils::getArrayValue($args, 'type', ''));
                $notes = wc_get_order_notes(array(
                    'order_id' => $order_id,
                    'type' => $type,
                ));
                
                $result = array();
                foreach ($notes as $note) {
                    $result[] = array(
                        'id' => $note->id,
                        'content' => $note->content,
                        'date_created' => $note->date_created,
                        'customer_note' => $note->customer_note,
                        'added_by' => $note->added_by,
                    );
                }
                
                $addResultText($r, 'Found ' . count($result) . ' notes: ' . wp_json_encode($result, JSON_PRETTY_PRINT));
                return true;
                
            case 'wc_create_order_note':
                $order_id = intval($utils::getArrayValue($args, 'order_id', 0));
                $note = wp_kses_post($utils::getArrayValue($args, 'note', ''));
                
                if (empty($order_id) || empty($note)) {
                    $r['error'] = array('code' => -50001, 'message' => 'order_id and note are required');
                    return true;
                }
                
                $order = wc_get_order($order_id);
                if (!$order) {
                    $r['error'] = array('code' => -50002, 'message' => 'Order not found');
                    return true;
                }
                
                $is_customer_note = (bool) $utils::getArrayValue($args, 'customer_note', false);
                $added_by_user = (bool) $utils::getArrayValue($args, 'added_by_user', false);
                
                $note_id = $order->add_order_note($note, $is_customer_note ? 1 : 0, $added_by_user);
                
                $addResultText($r, 'Order note created with ID: ' . $note_id);
                return true;
                
            case 'wc_delete_order_note':
                $order_id = intval($utils::getArrayValue($args, 'order_id', 0));
                $note_id = intval($utils::getArrayValue($args, 'note_id', 0));
                
                if (empty($order_id) || empty($note_id)) {
                    $r['error'] = array('code' => -50001, 'message' => 'order_id and note_id are required');
                    return true;
                }
                
                $order = wc_get_order($order_id);
                if (!$order) {
                    $r['error'] = array('code' => -50002, 'message' => 'Order not found');
                    return true;
                }
                
                $result = wc_delete_order_note($note_id);
                
                if ($result) {
                    $addResultText($r, 'Order note deleted: ' . $note_id);
                } else {
                    $r['error'] = array('code' => -50003, 'message' => 'Failed to delete order note');
                }
                return true;
            
            // Refunds Management
            case 'wc_create_refund':
                $order_id = intval($utils::getArrayValue($args, 'order_id', 0));
                
                if (empty($order_id)) {
                    $r['error'] = array('code' => -50001, 'message' => 'order_id is required');
                    return true;
                }
                
                $order = wc_get_order($order_id);
                if (!$order) {
                    $r['error'] = array('code' => -50002, 'message' => 'Order not found');
                    return true;
                }
                
                $amount = floatval($utils::getArrayValue($args, 'amount', 0));
                $reason = sanitize_text_field($utils::getArrayValue($args, 'reason', ''));
                $line_items = $utils::getArrayValue($args, 'line_items', array());
                $restock_items = (bool) $utils::getArrayValue($args, 'restock_items', false);
                
                // Prepare line items for refund
                $refund_line_items = array();
                if (!empty($line_items) && is_array($line_items)) {
                    foreach ($line_items as $item) {
                        $item_id = intval($utils::getArrayValue($item, 'id', 0));
                        $qty = intval($utils::getArrayValue($item, 'qty', 0));
                        $refund_total = floatval($utils::getArrayValue($item, 'refund_total', 0));
                        $refund_tax = $utils::getArrayValue($item, 'refund_tax', array());
                        
                        if ($item_id > 0) {
                            $refund_line_items[$item_id] = array(
                                'qty' => $qty,
                                'refund_total' => $refund_total,
                                'refund_tax' => is_array($refund_tax) ? $refund_tax : array(),
                            );
                        }
                    }
                }
                
                // Create refund
                $refund = wc_create_refund(array(
                    'order_id' => $order_id,
                    'amount' => $amount,
                    'reason' => $reason,
                    'line_items' => $refund_line_items,
                    'restock_items' => $restock_items,
                ));
                
                if (is_wp_error($refund)) {
                    $r['error'] = array('code' => -50003, 'message' => 'Failed to create refund: ' . $refund->get_error_message());
                    return true;
                }
                
                $refund_data = array(
                    'id' => $refund->get_id(),
                    'order_id' => $refund->get_parent_id(),
                    'amount' => $refund->get_amount(),
                    'reason' => $refund->get_reason(),
                    'date_created' => $refund->get_date_created() ? $refund->get_date_created()->date('Y-m-d H:i:s') : '',
                    'refunded_by' => $refund->get_refunded_by(),
                );
                
                $addResultText($r, 'Refund created: #' . $refund->get_id() . ' for order #' . $order_id . ' - Amount: ' . $refund->get_amount() . ' ' . $order->get_currency());
                $r['result']['refund'] = $refund_data;
                return true;
            
            case 'wc_get_refunds':
                $order_id = intval($utils::getArrayValue($args, 'order_id', 0));
                $limit = intval($utils::getArrayValue($args, 'limit', 50));
                $offset = intval($utils::getArrayValue($args, 'offset', 0));
                
                $refunds_data = array();
                
                if ($order_id > 0) {
                    // Get refunds for specific order
                    $order = wc_get_order($order_id);
                    if (!$order) {
                        $r['error'] = array('code' => -50002, 'message' => 'Order not found');
                        return true;
                    }
                    
                    $refunds = $order->get_refunds();
                } else {
                    // Get all refunds
                    $query_args = array(
                        'type' => 'shop_order_refund',
                        'limit' => $limit,
                        'offset' => $offset,
                        'orderby' => 'date',
                        'order' => 'DESC',
                    );
                    $refunds = wc_get_orders($query_args);
                }
                
                foreach ($refunds as $refund) {
                    $refunds_data[] = array(
                        'id' => $refund->get_id(),
                        'order_id' => $refund->get_parent_id(),
                        'amount' => $refund->get_amount(),
                        'reason' => $refund->get_reason(),
                        'date_created' => $refund->get_date_created() ? $refund->get_date_created()->date('Y-m-d H:i:s') : '',
                        'refunded_by' => $refund->get_refunded_by(),
                        'line_items_count' => count($refund->get_items()),
                    );
                }
                
                $addResultText($r, 'Found ' . count($refunds_data) . ' refund(s)');
                $r['result']['refunds'] = $refunds_data;
                $r['result']['count'] = count($refunds_data);
                return true;
            
            case 'wc_delete_refund':
                $refund_id = intval($utils::getArrayValue($args, 'refund_id', 0));
                $force = (bool) $utils::getArrayValue($args, 'force', false);
                
                if (empty($refund_id)) {
                    $r['error'] = array('code' => -50001, 'message' => 'refund_id is required');
                    return true;
                }
                
                $refund = wc_get_order($refund_id);
                if (!$refund || $refund->get_type() !== 'shop_order_refund') {
                    $r['error'] = array('code' => -50002, 'message' => 'Refund not found');
                    return true;
                }
                
                $result = $refund->delete($force);
                
                if ($result) {
                    $addResultText($r, 'Refund deleted: #' . $refund_id . ($force ? ' (permanently)' : ' (moved to trash)'));
                } else {
                    $r['error'] = array('code' => -50003, 'message' => 'Failed to delete refund');
                }
                return true;
        }
        
        return null; // Tool not handled by this module
    }
}
