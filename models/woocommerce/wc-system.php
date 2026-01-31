<?php
/**
 * WooCommerce System Tools
 * Handles reports, tax, shipping, payment gateways, system status, settings, webhooks
 */

if (!defined('ABSPATH')) {
    exit;
}

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- StifliFlexMcp is the plugin prefix
class StifliFlexMcp_WC_System {
    
    public static function getTools() {
        return array(
            // Reports
            'wc_get_sales_report' => array(
                'name' => 'wc_get_sales_report',
                'description' => 'Get sales report for a date range (date_min, date_max, period).',
                'inputSchema' => array(
                    'type' => 'object',
                    'properties' => array(
                        'date_min' => array('type' => 'string'),
                        'date_max' => array('type' => 'string'),
                        'period'   => array('type' => 'string'),
                    ),
                    'required' => array(),
                ),
            ),
            'wc_get_top_sellers_report' => array(
                'name' => 'wc_get_top_sellers_report',
                'description' => 'Get top sellers report (date_min, date_max, limit).',
                'inputSchema' => array(
                    'type' => 'object',
                    'properties' => array(
                        'date_min' => array('type' => 'string'),
                        'date_max' => array('type' => 'string'),
                        'limit'    => array('type' => 'integer'),
                    ),
                    'required' => array(),
                ),
            ),
            
            // Tax
            'wc_get_tax_classes' => array(
                'name' => 'wc_get_tax_classes',
                'description' => 'List all WooCommerce tax classes.',
                'inputSchema' => array(
                    'type' => 'object',
                    'properties' => (object) array(),
                    'required' => array(),
                ),
            ),
            'wc_get_tax_rates' => array(
                'name' => 'wc_get_tax_rates',
                'description' => 'List WooCommerce tax rates. Optionally filter by class.',
                'inputSchema' => array(
                    'type' => 'object',
                    'properties' => array(
                        'class' => array('type' => 'string'),
                    ),
                    'required' => array(),
                ),
            ),
            'wc_create_tax_rate' => array(
                'name' => 'wc_create_tax_rate',
                'description' => 'Create a tax rate.',
                'inputSchema' => array(
                    'type' => 'object',
                    'properties' => array(
                        'country'  => array('type' => 'string'),
                        'state'    => array('type' => 'string'),
                        'postcode' => array('type' => 'string'),
                        'city'     => array('type' => 'string'),
                        'rate'     => array('type' => 'string'),
                        'name'     => array('type' => 'string'),
                        'priority' => array('type' => 'integer'),
                        'compound' => array('type' => 'boolean'),
                        'shipping' => array('type' => 'boolean'),
                        'class'    => array('type' => 'string'),
                    ),
                    'required' => array(),
                ),
            ),
            'wc_update_tax_rate' => array(
                'name' => 'wc_update_tax_rate',
                'description' => 'Update a tax rate by ID.',
                'inputSchema' => array(
                    'type' => 'object',
                    'properties' => array(
                        'id'       => array('type' => 'integer'),
                        'rate'     => array('type' => 'string'),
                        'name'     => array('type' => 'string'),
                        'priority' => array('type' => 'integer'),
                    ),
                    'required' => array('id'),
                ),
            ),
            'wc_delete_tax_rate' => array(
                'name' => 'wc_delete_tax_rate',
                'description' => 'Delete a tax rate by ID.',
                'inputSchema' => array(
                    'type' => 'object',
                    'properties' => array(
                        'id'    => array('type' => 'integer'),
                        'force' => array('type' => 'boolean'),
                    ),
                    'required' => array('id'),
                ),
            ),
            
            // Shipping
            'wc_get_shipping_zones' => array(
                'name' => 'wc_get_shipping_zones',
                'description' => 'List all shipping zones.',
                'inputSchema' => array(
                    'type' => 'object',
                    'properties' => (object) array(),
                    'required' => array(),
                ),
            ),
            'wc_get_shipping_zone_methods' => array(
                'name' => 'wc_get_shipping_zone_methods',
                'description' => 'Get shipping methods for a specific zone.',
                'inputSchema' => array(
                    'type' => 'object',
                    'properties' => array(
                        'zone_id' => array('type' => 'integer'),
                    ),
                    'required' => array('zone_id'),
                ),
            ),
            'wc_create_shipping_zone' => array(
                'name' => 'wc_create_shipping_zone',
                'description' => 'Create a shipping zone.',
                'inputSchema' => array(
                    'type' => 'object',
                    'properties' => array(
                        'name'  => array('type' => 'string'),
                        'order' => array('type' => 'integer'),
                    ),
                    'required' => array('name'),
                ),
            ),
            'wc_update_shipping_zone' => array(
                'name' => 'wc_update_shipping_zone',
                'description' => 'Update a shipping zone.',
                'inputSchema' => array(
                    'type' => 'object',
                    'properties' => array(
                        'id'    => array('type' => 'integer'),
                        'name'  => array('type' => 'string'),
                        'order' => array('type' => 'integer'),
                    ),
                    'required' => array('id'),
                ),
            ),
            'wc_delete_shipping_zone' => array(
                'name' => 'wc_delete_shipping_zone',
                'description' => 'Delete a shipping zone.',
                'inputSchema' => array(
                    'type' => 'object',
                    'properties' => array(
                        'id'    => array('type' => 'integer'),
                        'force' => array('type' => 'boolean'),
                    ),
                    'required' => array('id'),
                ),
            ),
            
            // Payment Gateways
            'wc_get_payment_gateways' => array(
                'name' => 'wc_get_payment_gateways',
                'description' => 'List all payment gateways.',
                'inputSchema' => array(
                    'type' => 'object',
                    'properties' => (object) array(),
                    'required' => array(),
                ),
            ),
            'wc_update_payment_gateway' => array(
                'name' => 'wc_update_payment_gateway',
                'description' => 'Update a payment gateway settings by ID.',
                'inputSchema' => array(
                    'type' => 'object',
                    'properties' => array(
                        'id'      => array('type' => 'string'),
                        'enabled' => array('type' => 'boolean'),
                        'title'   => array('type' => 'string'),
                        'settings'=> array('type' => 'object'),
                    ),
                    'required' => array('id'),
                ),
            ),
            
            // System Status
            'wc_get_system_status' => array(
                'name' => 'wc_get_system_status',
                'description' => 'Get WooCommerce system status information.',
                'inputSchema' => array(
                    'type' => 'object',
                    'properties' => (object) array(),
                    'required' => array(),
                ),
            ),
            'wc_run_system_status_tool' => array(
                'name' => 'wc_run_system_status_tool',
                'description' => 'Run a system status tool (clear_transients, clear_expired_transients, delete_orphaned_variations, etc).',
                'inputSchema' => array(
                    'type' => 'object',
                    'properties' => array(
                        'tool' => array('type' => 'string'),
                    ),
                    'required' => array('tool'),
                ),
            ),
            
            // Settings
            'wc_get_settings' => array(
                'name' => 'wc_get_settings',
                'description' => 'Get WooCommerce settings. Optionally specify group (general, products, tax, shipping, checkout, account).',
                'inputSchema' => array(
                    'type' => 'object',
                    'properties' => array(
                        'group' => array('type' => 'string'),
                    ),
                    'required' => array(),
                ),
            ),
            'wc_update_setting_option' => array(
                'name' => 'wc_update_setting_option',
                'description' => 'Update a WooCommerce setting option.',
                'inputSchema' => array(
                    'type' => 'object',
                    'properties' => array(
                        'group' => array('type' => 'string'),
                        'id'    => array('type' => 'string'),
                        'value' => array('type' => 'string'),
                    ),
                    'required' => array('group', 'id', 'value'),
                ),
            ),
            
            // Webhooks
            'wc_get_webhooks' => array(
                'name' => 'wc_get_webhooks',
                'description' => 'List WooCommerce webhooks.',
                'inputSchema' => array(
                    'type' => 'object',
                    'properties' => array(
                        'status' => array('type' => 'string'),
                        'limit'  => array('type' => 'integer'),
                    ),
                    'required' => array(),
                ),
            ),
            'wc_create_webhook' => array(
                'name' => 'wc_create_webhook',
                'description' => 'Create a WooCommerce webhook.',
                'inputSchema' => array(
                    'type' => 'object',
                    'properties' => array(
                        'name'         => array('type' => 'string'),
                        'status'       => array('type' => 'string'),
                        'topic'        => array('type' => 'string'),
                        'delivery_url' => array('type' => 'string'),
                    ),
                    'required' => array('name', 'topic', 'delivery_url'),
                ),
            ),
            'wc_update_webhook' => array(
                'name' => 'wc_update_webhook',
                'description' => 'Update a WooCommerce webhook.',
                'inputSchema' => array(
                    'type' => 'object',
                    'properties' => array(
                        'id'           => array('type' => 'integer'),
                        'name'         => array('type' => 'string'),
                        'status'       => array('type' => 'string'),
                        'delivery_url' => array('type' => 'string'),
                    ),
                    'required' => array('id'),
                ),
            ),
            'wc_delete_webhook' => array(
                'name' => 'wc_delete_webhook',
                'description' => 'Delete a WooCommerce webhook.',
                'inputSchema' => array(
                    'type' => 'object',
                    'properties' => array(
                        'id'    => array('type' => 'integer'),
                        'force' => array('type' => 'boolean'),
                    ),
                    'required' => array('id'),
                ),
            ),
        );
    }
    
    public static function getCapabilities() {
        return array(
            'wc_create_tax_rate' => 'manage_woocommerce',
            'wc_update_tax_rate' => 'manage_woocommerce',
            'wc_delete_tax_rate' => 'manage_woocommerce',
            'wc_create_shipping_zone' => 'manage_woocommerce',
            'wc_update_shipping_zone' => 'manage_woocommerce',
            'wc_delete_shipping_zone' => 'manage_woocommerce',
            'wc_update_payment_gateway' => 'manage_woocommerce',
            'wc_run_system_status_tool' => 'manage_woocommerce',
            'wc_update_setting_option' => 'manage_woocommerce',
            'wc_create_webhook' => 'manage_woocommerce',
            'wc_update_webhook' => 'manage_woocommerce',
            'wc_delete_webhook' => 'manage_woocommerce',
        );
    }
    
    public static function dispatch($tool, $args, &$r, $addResultText, $utils) {
        if (!class_exists('WooCommerce')) {
            $r['error'] = array('code' => -50000, 'message' => 'WooCommerce is not active');
            return true;
        }
        
        switch ($tool) {
            // Reports
            case 'wc_get_sales_report':
                $date_min = sanitize_text_field($utils::getArrayValue($args, 'date_min', gmdate('Y-m-d', strtotime('-30 days'))));
                $date_max = sanitize_text_field($utils::getArrayValue($args, 'date_max', gmdate('Y-m-d')));
                
                global $wpdb;
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- WooCommerce aggregates require manual SQL for performance.
                $total_sales = $wpdb->get_var($wpdb->prepare(
                    "SELECT SUM(meta_value) FROM {$wpdb->postmeta} pm
                    LEFT JOIN {$wpdb->posts} p ON pm.post_id = p.ID
                    WHERE pm.meta_key = '_order_total'
                    AND p.post_type = 'shop_order'
                    AND p.post_status IN ('wc-completed', 'wc-processing')
                    AND p.post_date >= %s
                    AND p.post_date <= %s",
                    $date_min . ' 00:00:00',
                    $date_max . ' 23:59:59'
                ));
                
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- WooCommerce aggregates require manual SQL for performance.
                $order_count = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->posts}
                    WHERE post_type = 'shop_order'
                    AND post_status IN ('wc-completed', 'wc-processing')
                    AND post_date >= %s
                    AND post_date <= %s",
                    $date_min . ' 00:00:00',
                    $date_max . ' 23:59:59'
                ));
                
                $result = array(
                    'total_sales' => floatval($total_sales),
                    'order_count' => intval($order_count),
                    'period' => array('start' => $date_min, 'end' => $date_max),
                );
                
                $addResultText($r, 'Sales report: ' . wp_json_encode($result, JSON_PRETTY_PRINT));
                return true;
                
            case 'wc_get_top_sellers_report':
                $limit = intval($utils::getArrayValue($args, 'limit', 10));
                
                global $wpdb;
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- WooCommerce sales reports rely on direct SQL joins.
                $top_sellers = $wpdb->get_results($wpdb->prepare(
                    "SELECT pm.meta_value as product_id, SUM(oim.meta_value) as qty
                    FROM {$wpdb->prefix}woocommerce_order_items oi
                    LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim ON oi.order_item_id = oim.order_item_id
                    LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta pm ON oi.order_item_id = pm.order_item_id
                    WHERE oim.meta_key = '_qty' AND pm.meta_key = '_product_id'
                    GROUP BY pm.meta_value
                    ORDER BY qty DESC
                    LIMIT %d",
                    $limit
                ));
                
                $result = array();
                foreach ($top_sellers as $item) {
                    $product = wc_get_product(intval($item->product_id));
                    if ($product) {
                        $result[] = array(
                            'product_id' => intval($item->product_id),
                            'name' => $product->get_name(),
                            'quantity_sold' => intval($item->qty),
                        );
                    }
                }
                
                $addResultText($r, 'Top sellers: ' . wp_json_encode($result, JSON_PRETTY_PRINT));
                return true;
                
            // Tax
            case 'wc_get_tax_classes':
                $tax_classes = WC_Tax::get_tax_classes();
                $result = array('Standard'); // Default class
                foreach ($tax_classes as $class) {
                    $result[] = $class;
                }
                
                $addResultText($r, 'Tax classes: ' . wp_json_encode($result, JSON_PRETTY_PRINT));
                return true;
                
            case 'wc_get_tax_rates':
                $tax_class = sanitize_text_field($utils::getArrayValue($args, 'tax_class', ''));
                $rates = WC_Tax::get_rates_for_tax_class($tax_class);
                
                $result = array();
                foreach ($rates as $rate) {
                    $result[] = array(
                        'id' => $rate->tax_rate_id,
                        'country' => $rate->tax_rate_country,
                        'state' => $rate->tax_rate_state,
                        'rate' => $rate->tax_rate,
                        'name' => $rate->tax_rate_name,
                        'priority' => $rate->tax_rate_priority,
                        'compound' => $rate->tax_rate_compound,
                        'shipping' => $rate->tax_rate_shipping,
                        'class' => $rate->tax_rate_class,
                    );
                }
                
                $addResultText($r, 'Found ' . count($result) . ' tax rates: ' . wp_json_encode($result, JSON_PRETTY_PRINT));
                return true;
                
            case 'wc_create_tax_rate':
                global $wpdb;
                
                $tax_rate = array(
                    'tax_rate_country' => sanitize_text_field($utils::getArrayValue($args, 'country', '')),
                    'tax_rate_state' => sanitize_text_field($utils::getArrayValue($args, 'state', '')),
                    'tax_rate' => sanitize_text_field($utils::getArrayValue($args, 'rate', '0')),
                    'tax_rate_name' => sanitize_text_field($utils::getArrayValue($args, 'name', 'Tax')),
                    'tax_rate_priority' => intval($utils::getArrayValue($args, 'priority', 1)),
                    'tax_rate_compound' => intval($utils::getArrayValue($args, 'compound', 0)),
                    'tax_rate_shipping' => intval($utils::getArrayValue($args, 'shipping', 1)),
                    'tax_rate_order' => intval($utils::getArrayValue($args, 'order', 0)),
                    'tax_rate_class' => sanitize_text_field($utils::getArrayValue($args, 'tax_class', '')),
                );
                
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- WooCommerce core stores tax rates in custom tables.
                $wpdb->insert($wpdb->prefix . 'woocommerce_tax_rates', $tax_rate);
                $tax_rate_id = $wpdb->insert_id;
                
                $addResultText($r, 'Tax rate created with ID: ' . $tax_rate_id);
                return true;
                
            case 'wc_update_tax_rate':
                $tax_rate_id = intval($utils::getArrayValue($args, 'id', 0));
                if (empty($tax_rate_id)) {
                    $r['error'] = array('code' => -50001, 'message' => 'id is required');
                    return true;
                }
                
                global $wpdb;
                $update_data = array();
                
                if (isset($args['country'])) {
                    $update_data['tax_rate_country'] = sanitize_text_field($args['country']);
                }
                if (isset($args['state'])) {
                    $update_data['tax_rate_state'] = sanitize_text_field($args['state']);
                }
                if (isset($args['rate'])) {
                    $update_data['tax_rate'] = sanitize_text_field($args['rate']);
                }
                if (isset($args['name'])) {
                    $update_data['tax_rate_name'] = sanitize_text_field($args['name']);
                }
                if (isset($args['priority'])) {
                    $update_data['tax_rate_priority'] = intval($args['priority']);
                }
                
                if (!empty($update_data)) {
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- WooCommerce core stores tax rates in custom tables.
                    $wpdb->update(
                        $wpdb->prefix . 'woocommerce_tax_rates',
                        $update_data,
                        array('tax_rate_id' => $tax_rate_id)
                    );
                }
                
                $addResultText($r, 'Tax rate updated: ' . $tax_rate_id);
                return true;
                
            case 'wc_delete_tax_rate':
                $tax_rate_id = intval($utils::getArrayValue($args, 'id', 0));
                if (empty($tax_rate_id)) {
                    $r['error'] = array('code' => -50001, 'message' => 'id is required');
                    return true;
                }
                
                WC_Tax::_delete_tax_rate($tax_rate_id);
                
                $addResultText($r, 'Tax rate deleted: ' . $tax_rate_id);
                return true;
                
            // Shipping Zones
            case 'wc_get_shipping_zones':
                $zones = WC_Shipping_Zones::get_zones();
                $result = array();
                
                foreach ($zones as $zone) {
                    $result[] = array(
                        'id' => $zone['id'],
                        'zone_name' => $zone['zone_name'],
                        'zone_order' => $zone['zone_order'],
                        'formatted_zone_location' => $zone['formatted_zone_location'],
                    );
                }
                
                $addResultText($r, 'Found ' . count($result) . ' shipping zones: ' . wp_json_encode($result, JSON_PRETTY_PRINT));
                return true;
                
            case 'wc_get_shipping_zone_methods':
                $zone_id = intval($utils::getArrayValue($args, 'zone_id', 0));
                if (empty($zone_id)) {
                    $r['error'] = array('code' => -50001, 'message' => 'zone_id is required');
                    return true;
                }
                
                $zone = WC_Shipping_Zones::get_zone($zone_id);
                $methods = $zone->get_shipping_methods();
                $result = array();
                
                foreach ($methods as $method) {
                    $result[] = array(
                        'id' => $method->instance_id,
                        'method_id' => $method->id,
                        'title' => $method->title,
                        'enabled' => $method->enabled,
                    );
                }
                
                $addResultText($r, 'Found ' . count($result) . ' shipping methods: ' . wp_json_encode($result, JSON_PRETTY_PRINT));
                return true;
                
            case 'wc_create_shipping_zone':
                $zone_name = sanitize_text_field($utils::getArrayValue($args, 'name', ''));
                if (empty($zone_name)) {
                    $r['error'] = array('code' => -50001, 'message' => 'name is required');
                    return true;
                }
                
                $zone = new WC_Shipping_Zone();
                $zone->set_zone_name($zone_name);
                
                if (isset($args['zone_order'])) {
                    $zone->set_zone_order(intval($args['zone_order']));
                }
                
                $zone_id = $zone->save();
                
                $addResultText($r, 'Shipping zone created with ID: ' . $zone_id);
                return true;
                
            case 'wc_update_shipping_zone':
                $zone_id = intval($utils::getArrayValue($args, 'id', 0));
                if (empty($zone_id)) {
                    $r['error'] = array('code' => -50001, 'message' => 'id is required');
                    return true;
                }
                
                $zone = WC_Shipping_Zones::get_zone($zone_id);
                
                if (!$zone) {
                    $r['error'] = array('code' => -50002, 'message' => 'Shipping zone not found');
                    return true;
                }
                
                if (isset($args['name'])) {
                    $zone->set_zone_name(sanitize_text_field($args['name']));
                }
                if (isset($args['zone_order'])) {
                    $zone->set_zone_order(intval($args['zone_order']));
                }
                
                $zone->save();
                
                $addResultText($r, 'Shipping zone updated: ' . $zone_id);
                return true;
                
            case 'wc_delete_shipping_zone':
                $zone_id = intval($utils::getArrayValue($args, 'id', 0));
                if (empty($zone_id)) {
                    $r['error'] = array('code' => -50001, 'message' => 'id is required');
                    return true;
                }
                
                $zone = WC_Shipping_Zones::get_zone($zone_id);
                
                if ($zone) {
                    $zone->delete();
                    $addResultText($r, 'Shipping zone deleted: ' . $zone_id);
                } else {
                    $r['error'] = array('code' => -50002, 'message' => 'Shipping zone not found');
                }
                return true;
                
            // Payment Gateways
            case 'wc_get_payment_gateways':
                $gateways = WC()->payment_gateways->payment_gateways();
                $result = array();
                
                foreach ($gateways as $gateway) {
                    $result[] = array(
                        'id' => $gateway->id,
                        'title' => $gateway->title,
                        'description' => $gateway->description,
                        'enabled' => $gateway->enabled,
                    );
                }
                
                $addResultText($r, 'Found ' . count($result) . ' payment gateways: ' . wp_json_encode($result, JSON_PRETTY_PRINT));
                return true;
                
            case 'wc_update_payment_gateway':
                $gateway_id = sanitize_key($utils::getArrayValue($args, 'id', ''));
                if (empty($gateway_id)) {
                    $r['error'] = array('code' => -50001, 'message' => 'id is required');
                    return true;
                }
                
                $gateways = WC()->payment_gateways->payment_gateways();
                
                if (!isset($gateways[$gateway_id])) {
                    $r['error'] = array('code' => -50002, 'message' => 'Payment gateway not found');
                    return true;
                }
                
                $gateway = $gateways[$gateway_id];
                
                if (isset($args['enabled'])) {
                    $gateway->enabled = $args['enabled'] ? 'yes' : 'no';
                }
                if (isset($args['title'])) {
                    $gateway->title = sanitize_text_field($args['title']);
                }
                if (isset($args['settings']) && is_array($args['settings'])) {
                    foreach ($args['settings'] as $key => $value) {
                        $gateway->settings[$key] = $value;
                    }
                }
                
                update_option($gateway->get_option_key(), $gateway->settings);
                
                $addResultText($r, 'Payment gateway updated: ' . $gateway_id);
                return true;
                
            // System Status
            case 'wc_get_system_status':
                $serverSoftware = isset($_SERVER['SERVER_SOFTWARE']) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : 'Unknown';
                $userAgent = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : 'Unknown';
                $status = array(
                    'environment' => array(
                        'wp_version' => get_bloginfo('version'),
                        'wc_version' => WC()->version,
                        'php_version' => phpversion(),
                        'server_info' => $serverSoftware,
                        'user_agent' => $userAgent,
                    ),
                    'database' => array(
                        'wc_database_version' => get_option('woocommerce_db_version'),
                    ),
                    'active_plugins' => count(get_option('active_plugins', array())),
                );
                
                $addResultText($r, 'System status: ' . wp_json_encode($status, JSON_PRETTY_PRINT));
                return true;
                
            case 'wc_run_system_status_tool':
                $tool_name = sanitize_key($utils::getArrayValue($args, 'tool', ''));
                if (empty($tool_name)) {
                    $r['error'] = array('code' => -50001, 'message' => 'tool is required');
                    return true;
                }
                
                // Run specific system tools
                switch ($tool_name) {
                    case 'clear_transients':
                        wc_delete_product_transients();
                        wc_delete_shop_order_transients();
                        $addResultText($r, 'Transients cleared');
                        break;
                        
                    case 'delete_orphaned_variations':
                        global $wpdb;
                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- field updates require direct SQL for orphaned WooCommerce posts.
                        $result = $wpdb->query(
                            "DELETE products
                            FROM {$wpdb->posts} products
                            LEFT JOIN {$wpdb->posts} wp ON wp.ID = products.post_parent
                            WHERE products.post_type = 'product_variation'
                            AND products.post_parent > 0
                            AND wp.ID IS NULL"
                        );
                        $addResultText($r, 'Deleted ' . $result . ' orphaned variations');
                        break;
                        
                    default:
                        $r['error'] = array('code' => -50005, 'message' => 'Unknown tool: ' . $tool_name);
                }
                return true;
                
            // Settings
            case 'wc_get_settings':
                $group = sanitize_key($utils::getArrayValue($args, 'group', 'general'));
                
                $settings = array();
                switch ($group) {
                    case 'general':
                        $settings = array(
                            'store_address' => get_option('woocommerce_store_address'),
                            'store_city' => get_option('woocommerce_store_city'),
                            'default_country' => get_option('woocommerce_default_country'),
                            'currency' => get_option('woocommerce_currency'),
                        );
                        break;
                    case 'products':
                        $settings = array(
                            'weight_unit' => get_option('woocommerce_weight_unit'),
                            'dimension_unit' => get_option('woocommerce_dimension_unit'),
                        );
                        break;
                }
                
                $addResultText($r, 'Settings: ' . wp_json_encode($settings, JSON_PRETTY_PRINT));
                return true;
                
            case 'wc_update_setting_option':
                $group = sanitize_key($utils::getArrayValue($args, 'group', ''));
                $option_id = sanitize_key($utils::getArrayValue($args, 'id', ''));
                $value = $utils::getArrayValue($args, 'value', '');
                
                if (empty($group)) {
                    $r['error'] = array('code' => -50001, 'message' => 'group is required');
                    return true;
                }
                
                if (empty($option_id)) {
                    $r['error'] = array('code' => -50001, 'message' => 'id is required');
                    return true;
                }
                
                // Use WC Settings API if available
                $option_name = $option_id;
                
                // Common WC setting groups map to specific options
                $settings_map = array(
                    'general' => array(
                        'woocommerce_store_address' => 'woocommerce_store_address',
                        'woocommerce_store_address_2' => 'woocommerce_store_address_2',
                        'woocommerce_store_city' => 'woocommerce_store_city',
                        'woocommerce_default_country' => 'woocommerce_default_country',
                        'woocommerce_store_postcode' => 'woocommerce_store_postcode',
                        'woocommerce_currency' => 'woocommerce_currency',
                    ),
                );
                
                // If option_id doesn't start with woocommerce_, prefix it
                if (strpos($option_name, 'woocommerce_') !== 0) {
                    $option_name = 'woocommerce_' . $option_id;
                }
                
                update_option($option_name, $value);
                
                $addResultText($r, 'Setting updated: ' . $option_name . ' = ' . $value);
                return true;
                
            // Webhooks
            case 'wc_get_webhooks':
                $args_query = array(
                    'post_type' => 'shop_webhook',
                    'posts_per_page' => intval($utils::getArrayValue($args, 'limit', 10)),
                    'post_status' => 'any',
                );
                
                if (!empty($args['status'])) {
                    $args_query['post_status'] = sanitize_key($args['status']);
                }
                
                $webhooks_query = new WP_Query($args_query);
                $result = array();
                
                foreach ($webhooks_query->posts as $post) {
                    $webhook = new WC_Webhook($post->ID);
                    $result[] = array(
                        'id' => $webhook->get_id(),
                        'name' => $webhook->get_name(),
                        'status' => $webhook->get_status(),
                        'topic' => $webhook->get_topic(),
                        'delivery_url' => $webhook->get_delivery_url(),
                    );
                }
                
                $addResultText($r, 'Found ' . count($result) . ' webhooks: ' . wp_json_encode($result, JSON_PRETTY_PRINT));
                return true;
                
            case 'wc_create_webhook':
                $name = sanitize_text_field($utils::getArrayValue($args, 'name', ''));
                $topic = sanitize_text_field($utils::getArrayValue($args, 'topic', ''));
                $delivery_url = esc_url_raw($utils::getArrayValue($args, 'delivery_url', ''));
                
                if (empty($name) || empty($topic) || empty($delivery_url)) {
                    $r['error'] = array('code' => -50001, 'message' => 'name, topic, and delivery_url are required');
                    return true;
                }
                
                $webhook = new WC_Webhook();
                $webhook->set_name($name);
                $webhook->set_topic($topic);
                $webhook->set_delivery_url($delivery_url);
                
                if (!empty($args['status'])) {
                    $webhook->set_status(sanitize_key($args['status']));
                }
                
                $webhook_id = $webhook->save();
                
                $addResultText($r, 'Webhook created with ID: ' . $webhook_id);
                return true;
                
            case 'wc_update_webhook':
                $webhook_id = intval($utils::getArrayValue($args, 'id', 0));
                if (empty($webhook_id)) {
                    $r['error'] = array('code' => -50001, 'message' => 'id is required');
                    return true;
                }
                
                $webhook = new WC_Webhook($webhook_id);
                
                if (!$webhook->get_id()) {
                    $r['error'] = array('code' => -50002, 'message' => 'Webhook not found');
                    return true;
                }
                
                if (!empty($args['name'])) {
                    $webhook->set_name(sanitize_text_field($args['name']));
                }
                if (!empty($args['status'])) {
                    $webhook->set_status(sanitize_key($args['status']));
                }
                if (!empty($args['topic'])) {
                    $webhook->set_topic(sanitize_text_field($args['topic']));
                }
                if (!empty($args['delivery_url'])) {
                    $webhook->set_delivery_url(esc_url_raw($args['delivery_url']));
                }
                
                $webhook->save();
                
                $addResultText($r, 'Webhook updated: ' . $webhook_id);
                return true;
                
            case 'wc_delete_webhook':
                $webhook_id = intval($utils::getArrayValue($args, 'id', 0));
                if (empty($webhook_id)) {
                    $r['error'] = array('code' => -50001, 'message' => 'id is required');
                    return true;
                }
                
                $force = (bool) $utils::getArrayValue($args, 'force', false);
                $webhook = new WC_Webhook($webhook_id);
                
                if ($webhook->get_id()) {
                    $webhook->delete($force);
                    $addResultText($r, 'Webhook deleted: ' . $webhook_id);
                } else {
                    $r['error'] = array('code' => -50002, 'message' => 'Webhook not found');
                }
                return true;
        }
        
        return null; // Tool not handled by this module
    }
}
