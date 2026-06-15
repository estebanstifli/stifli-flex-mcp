<?php
/**
 * WooCommerce Customers & Coupons Tools
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * WooCommerce Customers class - REMOVED for WordPress.org compliance
 * Creating/managing users is not allowed per plugin guidelines.
 * See: https://make.wordpress.org/core/2020/11/05/application-passwords-integration-guide/
 */
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- StifliFlexMcp is the plugin prefix
class StifliFlexMcp_WC_Customers {
    
    public static function getTools() {
        // All customer tools removed for WordPress.org compliance
        return array();
    }
    
    public static function getCapabilities() {
        return array();
    }
    
    public static function dispatch($tool, $args, &$r, $addResultText, $utils) {
        // All customer dispatch removed for WordPress.org compliance
        return null; // Tool not handled by this module
    }
}

/**
 * Legacy dispatch code removed - the following tools were removed:
 * - wc_get_customers
 * - wc_create_customer
 * - wc_update_customer  
 * - wc_delete_customer
 */

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- StifliFlexMcp is the plugin prefix
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
                        'status'  => array('type' => 'string'),
                        'limit'   => array('type' => 'integer'),
                        'offset'  => array('type' => 'integer'),
                        'orderby' => array('type' => 'string'),
                        'order'   => array('type' => 'string'),
                    ),
                    'required' => array(),
                ),
            ),
            'wc_get_coupon' => array(
                'name' => 'wc_get_coupon',
                'description' => 'Get a WooCommerce coupon by ID.',
                'inputSchema' => array(
                    'type' => 'object',
                    'properties' => array(
                        'id' => array('type' => 'integer'),
                    ),
                    'required' => array('id'),
                ),
            ),
            'wc_get_coupon_count' => array(
                'name' => 'wc_get_coupon_count',
                'description' => 'Count WooCommerce coupons by status or return totals for all statuses.',
                'inputSchema' => array(
                    'type' => 'object',
                    'properties' => array(
                        'status' => array('type' => 'string'),
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
                        'product_ids'   => array('type' => 'array', 'items' => array('type' => 'integer')),
                        'excluded_product_ids' => array('type' => 'array', 'items' => array('type' => 'integer')),
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
            'wc_empty_coupon_trash' => array(
                'name' => 'wc_empty_coupon_trash',
                'description' => 'Permanently delete all coupons currently in trash.',
                'inputSchema' => array(
                    'type' => 'object',
                    'properties' => (object) array(),
                    'required' => array(),
                ),
            ),
        );
    }
    
    public static function getCapabilities() {
        return array(
            'wc_get_coupons' => 'edit_shop_coupons',
            'wc_get_coupon' => 'edit_shop_coupons',
            'wc_get_coupon_count' => 'edit_shop_coupons',
            'wc_create_coupon' => 'edit_shop_coupons',
            'wc_update_coupon' => 'edit_shop_coupons',
            'wc_delete_coupon' => 'delete_shop_coupons',
            'wc_empty_coupon_trash' => 'delete_shop_coupons',
        );
    }
    
    public static function dispatch($tool, $args, &$r, $addResultText, $utils) {
        if (!class_exists('WooCommerce')) {
            $r['error' ] = array('code' => -50000, 'message' => 'WooCommerce is not active');
            return true;
        }

        $buildCouponRow = function($coupon) {
            return array(
                'id' => $coupon->get_id(),
                'code' => $coupon->get_code(),
                'discount_type' => $coupon->get_discount_type(),
                'amount' => $coupon->get_amount(),
                'status' => get_post_status($coupon->get_id()),
                'expiry_date' => $coupon->get_date_expires() ? $coupon->get_date_expires()->date('Y-m-d') : null,
                'usage_count' => $coupon->get_usage_count(),
                'usage_limit' => $coupon->get_usage_limit(),
                'individual_use' => $coupon->get_individual_use(),
            );
        };

        $isShopCoupon = function($couponId) {
            $post = get_post((int) $couponId);
            return $post && 'shop_coupon' === $post->post_type;
        };
        
        switch ($tool) {
            case 'wc_get_coupons':
                $status = sanitize_key($utils::getArrayValue($args, 'status', 'any'));
                $allowed_status = array('any', 'publish', 'draft', 'pending', 'private', 'future', 'trash');
                if (!in_array($status, $allowed_status, true)) {
                    $status = 'any';
                }

                $query_status = 'any';
                if ('any' === $status) {
                    $query_status = array('publish', 'draft', 'pending', 'private', 'future', 'trash');
                } else {
                    $query_status = $status;
                }

                $query_args = array(
                    'posts_per_page' => intval($utils::getArrayValue($args, 'limit', 10)),
                    'offset' => intval($utils::getArrayValue($args, 'offset', 0)),
                    'post_type' => 'shop_coupon',
                    'post_status' => $query_status,
                    'orderby' => sanitize_key($utils::getArrayValue($args, 'orderby', 'date')),
                    'order' => sanitize_key($utils::getArrayValue($args, 'order', 'DESC')),
                );
                
                if (!empty($args['code'])) {
                    $query_args['s'] = sanitize_text_field($args['code']);
                }
                
                $coupons_query = new WP_Query($query_args);
                $result = array();
                
                foreach ($coupons_query->posts as $post) {
                    if ('shop_coupon' !== get_post_type($post->ID)) {
                        continue;
                    }
                    $coupon = new WC_Coupon($post->ID);
                    if (!$coupon->get_id()) {
                        continue;
                    }
                    $result[] = $buildCouponRow($coupon);
                }
                
                $addResultText($r, 'Found ' . count($result) . ' coupons: ' . wp_json_encode($result, JSON_PRETTY_PRINT));
                return true;

            case 'wc_get_coupon':
                $coupon_id = intval($utils::getArrayValue($args, 'id', 0));
                if (empty($coupon_id)) {
                    $r['error'] = array('code' => -50001, 'message' => 'id is required');
                    return true;
                }

                if (!$isShopCoupon($coupon_id)) {
                    $r['error'] = array('code' => -50002, 'message' => 'Coupon not found or invalid coupon post type');
                    return true;
                }

                $coupon = new WC_Coupon($coupon_id);
                if (!$coupon->get_id()) {
                    $r['error'] = array('code' => -50002, 'message' => 'Coupon not found');
                    return true;
                }

                $addResultText($r, 'Coupon: ' . wp_json_encode($buildCouponRow($coupon), JSON_PRETTY_PRINT));
                return true;

            case 'wc_get_coupon_count':
                $status = sanitize_key($utils::getArrayValue($args, 'status', 'any'));
                $allowed_status = array('any', 'publish', 'draft', 'pending', 'private', 'future', 'trash');
                if (!in_array($status, $allowed_status, true)) {
                    $status = 'any';
                }

                $countByStatus = function($postStatus) {
                    $query = new WP_Query(array(
                        'post_type' => 'shop_coupon',
                        'post_status' => $postStatus,
                        'posts_per_page' => 1,
                        'fields' => 'ids',
                        'no_found_rows' => false,
                        'cache_results' => false,
                        'update_post_meta_cache' => false,
                        'update_post_term_cache' => false,
                    ));
                    return (int) $query->found_posts;
                };

                if ('any' !== $status && '' !== $status) {
                    $countValue = $countByStatus($status);
                    $addResultText($r, 'Coupon count (' . $status . '): ' . $countValue);
                    return true;
                }

                $payload = array(
                    'publish' => $countByStatus('publish'),
                    'draft' => $countByStatus('draft'),
                    'pending' => $countByStatus('pending'),
                    'private' => $countByStatus('private'),
                    'future' => $countByStatus('future'),
                    'trash' => $countByStatus('trash'),
                );
                $payload['total'] = array_sum($payload);
                $addResultText($r, 'Coupon counts: ' . wp_json_encode($payload, JSON_PRETTY_PRINT));
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

                if (!$isShopCoupon($coupon_id)) {
                    $r['error'] = array('code' => -50002, 'message' => 'Coupon not found or invalid coupon post type');
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

                if (!$isShopCoupon($coupon_id)) {
                    $r['error'] = array('code' => -50002, 'message' => 'Coupon not found or invalid coupon post type');
                    return true;
                }
                
                $force = (bool) $utils::getArrayValue($args, 'force', false);
                if (!$force) {
                    $trashed = wp_trash_post($coupon_id);
                    if (!is_wp_error($trashed) && $trashed) {
                        $status_after = get_post_status($coupon_id);
                        if ('trash' === $status_after) {
                            $addResultText($r, 'Coupon moved to trash: ' . $coupon_id);
                            return true;
                        }
                    }
                }

                $result = wp_delete_post($coupon_id, $force);

                if (!$result) {
                    $r['error'] = array('code' => -50003, 'message' => 'Failed to delete coupon');
                    return true;
                }

                if ($force) {
                    $addResultText($r, 'Coupon permanently deleted: ' . $coupon_id);
                    return true;
                }

                $status_after = get_post_status($coupon_id);
                if ('trash' === $status_after) {
                    $addResultText($r, 'Coupon moved to trash: ' . $coupon_id);
                } else {
                    $addResultText($r, 'Coupon permanently deleted (trash unavailable or disabled): ' . $coupon_id);
                }
                return true;

            case 'wc_empty_coupon_trash':
                $trashQuery = new WP_Query(array(
                    'post_type' => 'shop_coupon',
                    'post_status' => 'trash',
                    'posts_per_page' => -1,
                    'fields' => 'ids',
                    'no_found_rows' => true,
                ));

                $deleted = array();
                foreach ($trashQuery->posts as $couponId) {
                    $result = wp_delete_post((int) $couponId, true);
                    if ($result) {
                        $deleted[] = (int) $couponId;
                    }
                }

                $addResultText($r, 'Deleted ' . count($deleted) . ' trashed coupons: ' . wp_json_encode($deleted));
                return true;
        }
        
        return null; // Tool not handled by this module
    }
}
