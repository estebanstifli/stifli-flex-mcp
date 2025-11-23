<?php
/**
 * WooCommerce Customers & Coupons Tools
 */

if (!defined('ABSPATH')) {
    exit;
}

class StifliFlexMcp_WC_Customers {
    
    public static function getTools() {
        return array(
            'wc_get_customers' => array(
                'name' => 'wc_get_customers',
                'description' => 'List WooCommerce customers with filters (email, role, search, limit, offset, orderby, order).',
                'inputSchema' => array(
                    'type' => 'object',
                    'properties' => array(
                        'email'   => array('type' => 'string'),
                        'role'    => array('type' => 'string'),
                        'search'  => array('type' => 'string'),
                        'limit'   => array('type' => 'integer'),
                        'offset'  => array('type' => 'integer'),
                        'orderby' => array('type' => 'string'),
                        'order'   => array('type' => 'string'),
                    ),
                    'required' => array(),
                ),
            ),
            'wc_create_customer' => array(
                'name' => 'wc_create_customer',
                'description' => 'Create a WooCommerce customer (email, first_name, last_name, username, password, billing, shipping).',
                'inputSchema' => array(
                    'type' => 'object',
                    'properties' => array(
                        'email'      => array('type' => 'string'),
                        'first_name' => array('type' => 'string'),
                        'last_name'  => array('type' => 'string'),
                        'username'   => array('type' => 'string'),
                        'password'   => array('type' => 'string'),
                        'billing'    => array('type' => 'object'),
                        'shipping'   => array('type' => 'object'),
                    ),
                    'required' => array('email'),
                ),
            ),
            'wc_update_customer' => array(
                'name' => 'wc_update_customer',
                'description' => 'Update a WooCommerce customer by ID.',
                'inputSchema' => array(
                    'type' => 'object',
                    'properties' => array(
                        'id'         => array('type' => 'integer'),
                        'email'      => array('type' => 'string'),
                        'first_name' => array('type' => 'string'),
                        'last_name'  => array('type' => 'string'),
                        'billing'    => array('type' => 'object'),
                        'shipping'   => array('type' => 'object'),
                    ),
                    'required' => array('id'),
                ),
            ),
            'wc_delete_customer' => array(
                'name' => 'wc_delete_customer',
                'description' => 'Delete a WooCommerce customer by ID. Pass reassign to reassign orders.',
                'inputSchema' => array(
                    'type' => 'object',
                    'properties' => array(
                        'id'       => array('type' => 'integer'),
                        'reassign' => array('type' => 'integer'),
                        'force'    => array('type' => 'boolean'),
                    ),
                    'required' => array('id'),
                ),
            ),
        );
    }
    
    public static function getCapabilities() {
        return array(
            'wc_create_customer' => 'edit_users',
            'wc_update_customer' => 'edit_users',
            'wc_delete_customer' => 'delete_users',
        );
    }
    
    public static function dispatch($tool, $args, &$r, $addResultText, $utils) {
        if (!class_exists('WooCommerce')) {
            $r['error'] = array('code' => -50000, 'message' => 'WooCommerce is not active');
            return true;
        }
        
        switch ($tool) {
            case 'wc_get_customers':
                $query_args = array(
                    'limit' => intval($utils::getArrayValue($args, 'limit', 10)),
                    'offset' => intval($utils::getArrayValue($args, 'offset', 0)),
                    'orderby' => sanitize_key($utils::getArrayValue($args, 'orderby', 'registered')),
                    'order' => sanitize_key($utils::getArrayValue($args, 'order', 'DESC')),
                );
                
                if (!empty($args['email'])) {
                    $query_args['email'] = sanitize_email($args['email']);
                }
                if (!empty($args['search'])) {
                    $query_args['search'] = '*' . sanitize_text_field($args['search']) . '*';
                }
                if (!empty($args['role'])) {
                    $query_args['role'] = sanitize_key($args['role']);
                }
                
                $customer_query = new WP_User_Query($query_args);
                $customers = $customer_query->get_results();
                $result = array();
                
                foreach ($customers as $user) {
                    $customer = new WC_Customer($user->ID);
                    $result[] = array(
                        'id' => $customer->get_id(),
                        'email' => $customer->get_email(),
                        'first_name' => $customer->get_first_name(),
                        'last_name' => $customer->get_last_name(),
                        'username' => $customer->get_username(),
                        'billing' => array(
                            'first_name' => $customer->get_billing_first_name(),
                            'last_name' => $customer->get_billing_last_name(),
                            'email' => $customer->get_billing_email(),
                            'phone' => $customer->get_billing_phone(),
                        ),
                        'total_spent' => $customer->get_total_spent(),
                        'orders_count' => $customer->get_order_count(),
                    );
                }
                
                $addResultText($r, 'Found ' . count($result) . ' customers: ' . wp_json_encode($result, JSON_PRETTY_PRINT));
                return true;
                
            case 'wc_create_customer':
                $email = sanitize_email($utils::getArrayValue($args, 'email', ''));
                if (empty($email)) {
                    $r['error'] = array('code' => -50001, 'message' => 'email is required');
                    return true;
                }
                
                $username = sanitize_user($utils::getArrayValue($args, 'username', $email));
                $password = $utils::getArrayValue($args, 'password', wp_generate_password());
                
                $user_id = wc_create_new_customer($email, $username, $password);
                
                if (is_wp_error($user_id)) {
                    $r['error'] = array('code' => -50004, 'message' => $user_id->get_error_message());
                    return true;
                }
                
                $customer = new WC_Customer($user_id);
                
                if (!empty($args['first_name'])) {
                    $customer->set_first_name(sanitize_text_field($args['first_name']));
                }
                if (!empty($args['last_name'])) {
                    $customer->set_last_name(sanitize_text_field($args['last_name']));
                }
                
                // Billing
                if (!empty($args['billing']) && is_array($args['billing'])) {
                    $customer->set_billing_first_name(sanitize_text_field($utils::getArrayValue($args['billing'], 'first_name', '')));
                    $customer->set_billing_last_name(sanitize_text_field($utils::getArrayValue($args['billing'], 'last_name', '')));
                    $customer->set_billing_email(sanitize_email($utils::getArrayValue($args['billing'], 'email', $email)));
                    $customer->set_billing_phone(sanitize_text_field($utils::getArrayValue($args['billing'], 'phone', '')));
                    $customer->set_billing_address_1(sanitize_text_field($utils::getArrayValue($args['billing'], 'address_1', '')));
                    $customer->set_billing_address_2(sanitize_text_field($utils::getArrayValue($args['billing'], 'address_2', '')));
                    $customer->set_billing_city(sanitize_text_field($utils::getArrayValue($args['billing'], 'city', '')));
                    $customer->set_billing_postcode(sanitize_text_field($utils::getArrayValue($args['billing'], 'postcode', '')));
                    $customer->set_billing_country(sanitize_text_field($utils::getArrayValue($args['billing'], 'country', '')));
                    $customer->set_billing_state(sanitize_text_field($utils::getArrayValue($args['billing'], 'state', '')));
                }
                
                // Shipping
                if (!empty($args['shipping']) && is_array($args['shipping'])) {
                    $customer->set_shipping_first_name(sanitize_text_field($utils::getArrayValue($args['shipping'], 'first_name', '')));
                    $customer->set_shipping_last_name(sanitize_text_field($utils::getArrayValue($args['shipping'], 'last_name', '')));
                    $customer->set_shipping_address_1(sanitize_text_field($utils::getArrayValue($args['shipping'], 'address_1', '')));
                    $customer->set_shipping_address_2(sanitize_text_field($utils::getArrayValue($args['shipping'], 'address_2', '')));
                    $customer->set_shipping_city(sanitize_text_field($utils::getArrayValue($args['shipping'], 'city', '')));
                    $customer->set_shipping_postcode(sanitize_text_field($utils::getArrayValue($args['shipping'], 'postcode', '')));
                    $customer->set_shipping_country(sanitize_text_field($utils::getArrayValue($args['shipping'], 'country', '')));
                    $customer->set_shipping_state(sanitize_text_field($utils::getArrayValue($args['shipping'], 'state', '')));
                }
                
                $customer->save();
                
                $addResultText($r, 'Customer created with ID: ' . $user_id);
                return true;
                
            case 'wc_update_customer':
                $customer_id = intval($utils::getArrayValue($args, 'id', 0));
                if (empty($customer_id)) {
                    $r['error'] = array('code' => -50001, 'message' => 'id is required');
                    return true;
                }
                
                $customer = new WC_Customer($customer_id);
                if (!$customer->get_id()) {
                    $r['error'] = array('code' => -50002, 'message' => 'Customer not found');
                    return true;
                }
                
                if (!empty($args['email'])) {
                    $customer->set_email(sanitize_email($args['email']));
                }
                if (!empty($args['first_name'])) {
                    $customer->set_first_name(sanitize_text_field($args['first_name']));
                }
                if (!empty($args['last_name'])) {
                    $customer->set_last_name(sanitize_text_field($args['last_name']));
                }
                
                // Update billing if provided
                if (isset($args['billing']) && is_array($args['billing'])) {
                    foreach ($args['billing'] as $key => $value) {
                        $method = 'set_billing_' . $key;
                        if (method_exists($customer, $method)) {
                            $customer->$method(sanitize_text_field($value));
                        }
                    }
                }
                
                // Update shipping if provided
                if (isset($args['shipping']) && is_array($args['shipping'])) {
                    foreach ($args['shipping'] as $key => $value) {
                        $method = 'set_shipping_' . $key;
                        if (method_exists($customer, $method)) {
                            $customer->$method(sanitize_text_field($value));
                        }
                    }
                }
                
                $customer->save();
                
                $addResultText($r, 'Customer updated: ' . $customer_id);
                return true;
                
            case 'wc_delete_customer':
                $customer_id = intval($utils::getArrayValue($args, 'id', 0));
                if (empty($customer_id)) {
                    $r['error'] = array('code' => -50001, 'message' => 'id is required');
                    return true;
                }
                
                $reassign = intval($utils::getArrayValue($args, 'reassign', 0));
                
                if (!function_exists('wp_delete_user')) {
                    require_once(ABSPATH . 'wp-admin/includes/user.php');
                }
                $result = wp_delete_user($customer_id, $reassign);
                
                if ($result) {
                    $addResultText($r, 'Customer deleted: ' . $customer_id);
                } else {
                    $r['error'] = array('code' => -50003, 'message' => 'Failed to delete customer');
                }
                return true;
        }
        
        return false;
    }
}

class StifliFlexMcp_WC_Coupons {
    
    public static function getTools() {
        return array(
            'wc_get_coupons' => array(
                'name' => 'wc_get_coupons',
                'description' => 'List WooCommerce coupons with filters (code, limit, offset, orderby, order).',
                'inputSchema' => array(
                    'type' => 'object',
                    'properties' => array(
                        'code'    => array('type' => 'string'),
                        'limit'   => array('type' => 'integer'),
                        'offset'  => array('type' => 'integer'),
                        'orderby' => array('type' => 'string'),
                        'order'   => array('type' => 'string'),
                    ),
                    'required' => array(),
                ),
            ),
            'wc_create_coupon' => array(
                'name' => 'wc_create_coupon',
                'description' => 'Create a WooCommerce coupon (code, discount_type, amount, expiry_date, usage_limit, etc).',
                'inputSchema' => array(
                    'type' => 'object',
                    'properties' => array(
                        'code'          => array('type' => 'string'),
                        'discount_type' => array('type' => 'string'),
                        'amount'        => array('type' => 'string'),
                        'expiry_date'   => array('type' => 'string'),
                        'usage_limit'   => array('type' => 'integer'),
                        'individual_use'=> array('type' => 'boolean'),
                        'product_ids'   => array('type' => 'array'),
                        'excluded_product_ids' => array('type' => 'array'),
                    ),
                    'required' => array('code'),
                ),
            ),
            'wc_update_coupon' => array(
                'name' => 'wc_update_coupon',
                'description' => 'Update a WooCommerce coupon by ID.',
                'inputSchema' => array(
                    'type' => 'object',
                    'properties' => array(
                        'id'            => array('type' => 'integer'),
                        'code'          => array('type' => 'string'),
                        'discount_type' => array('type' => 'string'),
                        'amount'        => array('type' => 'string'),
                        'expiry_date'   => array('type' => 'string'),
                        'usage_limit'   => array('type' => 'integer'),
                    ),
                    'required' => array('id'),
                ),
            ),
            'wc_delete_coupon' => array(
                'name' => 'wc_delete_coupon',
                'description' => 'Delete a WooCommerce coupon by ID.',
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
            'wc_create_coupon' => 'edit_shop_coupons',
            'wc_update_coupon' => 'edit_shop_coupons',
            'wc_delete_coupon' => 'delete_shop_coupons',
        );
    }
    
    public static function dispatch($tool, $args, &$r, $addResultText, $utils) {
        if (!class_exists('WooCommerce')) {
            $r['error' ] = array('code' => -50000, 'message' => 'WooCommerce is not active');
            return true;
        }
        
        switch ($tool) {
            case 'wc_get_coupons':
                $query_args = array(
                    'posts_per_page' => intval($utils::getArrayValue($args, 'limit', 10)),
                    'offset' => intval($utils::getArrayValue($args, 'offset', 0)),
                    'post_type' => 'shop_coupon',
                    'post_status' => 'publish',
                    'orderby' => sanitize_key($utils::getArrayValue($args, 'orderby', 'date')),
                    'order' => sanitize_key($utils::getArrayValue($args, 'order', 'DESC')),
                );
                
                if (!empty($args['code'])) {
                    $query_args['s'] = sanitize_text_field($args['code']);
                }
                
                $coupons_query = new WP_Query($query_args);
                $result = array();
                
                foreach ($coupons_query->posts as $post) {
                    $coupon = new WC_Coupon($post->ID);
                    $result[] = array(
                        'id' => $coupon->get_id(),
                        'code' => $coupon->get_code(),
                        'discount_type' => $coupon->get_discount_type(),
                        'amount' => $coupon->get_amount(),
                        'expiry_date' => $coupon->get_date_expires() ? $coupon->get_date_expires()->date('Y-m-d') : null,
                        'usage_count' => $coupon->get_usage_count(),
                        'usage_limit' => $coupon->get_usage_limit(),
                        'individual_use' => $coupon->get_individual_use(),
                    );
                }
                
                $addResultText($r, 'Found ' . count($result) . ' coupons: ' . wp_json_encode($result, JSON_PRETTY_PRINT));
                return true;
                
            case 'wc_create_coupon':
                $code = sanitize_text_field($utils::getArrayValue($args, 'code', ''));
                if (empty($code)) {
                    $r['error'] = array('code' => -50001, 'message' => 'code is required');
                    return true;
                }
                
                $coupon = new WC_Coupon();
                $coupon->set_code($code);
                
                if (!empty($args['discount_type'])) {
                    $coupon->set_discount_type(sanitize_key($args['discount_type']));
                }
                if (isset($args['amount'])) {
                    $coupon->set_amount(sanitize_text_field($args['amount']));
                }
                if (!empty($args['expiry_date'])) {
                    $coupon->set_date_expires(sanitize_text_field($args['expiry_date']));
                }
                if (isset($args['usage_limit'])) {
                    $coupon->set_usage_limit(intval($args['usage_limit']));
                }
                if (isset($args['individual_use'])) {
                    $coupon->set_individual_use((bool) $args['individual_use']);
                }
                if (!empty($args['product_ids']) && is_array($args['product_ids'])) {
                    $coupon->set_product_ids(array_map('intval', $args['product_ids']));
                }
                if (!empty($args['excluded_product_ids']) && is_array($args['excluded_product_ids'])) {
                    $coupon->set_excluded_product_ids(array_map('intval', $args['excluded_product_ids']));
                }
                if (isset($args['minimum_amount'])) {
                    $coupon->set_minimum_amount(sanitize_text_field($args['minimum_amount']));
                }
                if (isset($args['maximum_amount'])) {
                    $coupon->set_maximum_amount(sanitize_text_field($args['maximum_amount']));
                }
                
                $coupon_id = $coupon->save();
                
                $addResultText($r, 'Coupon created with ID: ' . $coupon_id);
                return true;
                
            case 'wc_update_coupon':
                $coupon_id = intval($utils::getArrayValue($args, 'id', 0));
                if (empty($coupon_id)) {
                    $r['error'] = array('code' => -50001, 'message' => 'id is required');
                    return true;
                }
                
                $coupon = new WC_Coupon($coupon_id);
                if (!$coupon->get_id()) {
                    $r['error'] = array('code' => -50002, 'message' => 'Coupon not found');
                    return true;
                }
                
                if (!empty($args['code'])) {
                    $coupon->set_code(sanitize_text_field($args['code']));
                }
                if (isset($args['discount_type'])) {
                    $coupon->set_discount_type(sanitize_key($args['discount_type']));
                }
                if (isset($args['amount'])) {
                    $coupon->set_amount(sanitize_text_field($args['amount']));
                }
                if (isset($args['expiry_date'])) {
                    $coupon->set_date_expires(sanitize_text_field($args['expiry_date']));
                }
                if (isset($args['usage_limit'])) {
                    $coupon->set_usage_limit(intval($args['usage_limit']));
                }
                if (isset($args['individual_use'])) {
                    $coupon->set_individual_use((bool) $args['individual_use']);
                }
                
                $coupon->save();
                
                $addResultText($r, 'Coupon updated: ' . $coupon_id);
                return true;
                
            case 'wc_delete_coupon':
                $coupon_id = intval($utils::getArrayValue($args, 'id', 0));
                if (empty($coupon_id)) {
                    $r['error'] = array('code' => -50001, 'message' => 'id is required');
                    return true;
                }
                
                $force = (bool) $utils::getArrayValue($args, 'force', false);
                $result = wp_delete_post($coupon_id, $force);
                
                if ($result) {
                    $addResultText($r, 'Coupon deleted: ' . $coupon_id);
                } else {
                    $r['error'] = array('code' => -50003, 'message' => 'Failed to delete coupon');
                }
                return true;
        }
        
        return false;
    }
}
