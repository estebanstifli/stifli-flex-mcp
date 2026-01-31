<?php
/**
 * WooCommerce Products Tools
 * Handles products, variations, categories, tags, and reviews
 */

if (!defined('ABSPATH')) {
    exit;
}

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- StifliFlexMcp is the plugin prefix
class StifliFlexMcp_WC_Products {
    
    /**
     * Get tool definitions for products
     */
    public static function getTools() {
        return array(
            // Products
            'wc_get_products' => array(
                'name' => 'wc_get_products',
                'description' => 'List WooCommerce products with filters (status, category, tag, search, limit, offset, orderby, order).',
                'inputSchema' => array(
                    'type' => 'object',
                    'properties' => array(
                        'status'   => array('type' => 'string'),
                        'category' => array('type' => 'string'),
                        'tag'      => array('type' => 'string'),
                        'search'   => array('type' => 'string'),
                        'limit'    => array('type' => 'integer'),
                        'offset'   => array('type' => 'integer'),
                        'orderby'  => array('type' => 'string'),
                        'order'    => array('type' => 'string'),
                        'type'     => array('type' => 'string'),
                    ),
                    'required' => array(),
                ),
            ),
            'wc_create_product' => array(
                'name' => 'wc_create_product',
                'description' => 'Create a WooCommerce product (name, type, regular_price, description, short_description, categories, tags, images, stock_quantity, etc).',
                'inputSchema' => array(
                    'type' => 'object',
                    'properties' => array(
                        'name'              => array('type' => 'string'),
                        'type'              => array('type' => 'string'),
                        'regular_price'     => array('type' => 'string'),
                        'sale_price'        => array('type' => 'string'),
                        'description'       => array('type' => 'string'),
                        'short_description' => array('type' => 'string'),
                        'sku'               => array('type' => 'string'),
                        'manage_stock'      => array('type' => 'boolean'),
                        'stock_quantity'    => array('type' => 'integer'),
                        'stock_status'      => array('type' => 'string'),
                        'categories'        => array('type' => 'array'),
                        'tags'              => array('type' => 'array'),
                        'images'            => array('type' => 'array'),
                        'status'            => array('type' => 'string'),
                    ),
                    'required' => array('name'),
                ),
            ),
            'wc_update_product' => array(
                'name' => 'wc_update_product',
                'description' => 'Update a WooCommerce product by ID.',
                'inputSchema' => array(
                    'type' => 'object',
                    'properties' => array(
                        'product_id'        => array('type' => 'integer'),
                        'name'              => array('type' => 'string'),
                        'regular_price'     => array('type' => 'string'),
                        'sale_price'        => array('type' => 'string'),
                        'description'       => array('type' => 'string'),
                        'short_description' => array('type' => 'string'),
                        'sku'               => array('type' => 'string'),
                        'manage_stock'      => array('type' => 'boolean'),
                        'stock_quantity'    => array('type' => 'integer'),
                        'stock_status'      => array('type' => 'string'),
                        'categories'        => array('type' => 'array'),
                        'tags'              => array('type' => 'array'),
                        'status'            => array('type' => 'string'),
                    ),
                    'required' => array('product_id'),
                ),
            ),
            'wc_delete_product' => array(
                'name' => 'wc_delete_product',
                'description' => 'Delete a WooCommerce product by ID. Pass force=true to delete permanently.',
                'inputSchema' => array(
                    'type' => 'object',
                    'properties' => array(
                        'product_id' => array('type' => 'integer'),
                        'force'      => array('type' => 'boolean'),
                    ),
                    'required' => array('product_id'),
                ),
            ),
            'wc_batch_update_products' => array(
                'name' => 'wc_batch_update_products',
                'description' => 'Batch update multiple products at once. Pass arrays: create, update, delete.',
                'inputSchema' => array(
                    'type' => 'object',
                    'properties' => array(
                        'create' => array('type' => 'array'),
                        'update' => array('type' => 'array'),
                        'delete' => array('type' => 'array'),
                    ),
                    'required' => array(),
                ),
            ),
            
            // Product Variations
            'wc_get_product_variations' => array(
                'name' => 'wc_get_product_variations',
                'description' => 'Get variations of a variable product by product_id.',
                'inputSchema' => array(
                    'type' => 'object',
                    'properties' => array(
                        'product_id' => array('type' => 'integer'),
                    ),
                    'required' => array('product_id'),
                ),
            ),
            'wc_create_product_variation' => array(
                'name' => 'wc_create_product_variation',
                'description' => 'Create a product variation for a variable product.',
                'inputSchema' => array(
                    'type' => 'object',
                    'properties' => array(
                        'product_id'    => array('type' => 'integer'),
                        'regular_price' => array('type' => 'string'),
                        'attributes'    => array('type' => 'object'),
                        'sku'           => array('type' => 'string'),
                        'stock_quantity'=> array('type' => 'integer'),
                    ),
                    'required' => array('product_id'),
                ),
            ),
            'wc_update_product_variation' => array(
                'name' => 'wc_update_product_variation',
                'description' => 'Update a product variation.',
                'inputSchema' => array(
                    'type' => 'object',
                    'properties' => array(
                        'product_id'    => array('type' => 'integer'),
                        'variation_id'  => array('type' => 'integer'),
                        'regular_price' => array('type' => 'string'),
                        'attributes'    => array('type' => 'object'),
                        'stock_quantity'=> array('type' => 'integer'),
                    ),
                    'required' => array('product_id', 'variation_id'),
                ),
            ),
            'wc_delete_product_variation' => array(
                'name' => 'wc_delete_product_variation',
                'description' => 'Delete a product variation.',
                'inputSchema' => array(
                    'type' => 'object',
                    'properties' => array(
                        'product_id'   => array('type' => 'integer'),
                        'variation_id' => array('type' => 'integer'),
                        'force'        => array('type' => 'boolean'),
                    ),
                    'required' => array('product_id', 'variation_id'),
                ),
            ),
            
            // Product Categories
            'wc_get_product_categories' => array(
                'name' => 'wc_get_product_categories',
                'description' => 'List WooCommerce product categories.',
                'inputSchema' => array(
                    'type' => 'object',
                    'properties' => array(
                        'hide_empty' => array('type' => 'boolean'),
                        'limit'      => array('type' => 'integer'),
                        'search'     => array('type' => 'string'),
                    ),
                    'required' => array(),
                ),
            ),
            'wc_create_product_category' => array(
                'name' => 'wc_create_product_category',
                'description' => 'Create a product category.',
                'inputSchema' => array(
                    'type' => 'object',
                    'properties' => array(
                        'name'        => array('type' => 'string'),
                        'slug'        => array('type' => 'string'),
                        'parent'      => array('type' => 'integer'),
                        'description' => array('type' => 'string'),
                    ),
                    'required' => array('name'),
                ),
            ),
            'wc_update_product_category' => array(
                'name' => 'wc_update_product_category',
                'description' => 'Update a product category.',
                'inputSchema' => array(
                    'type' => 'object',
                    'properties' => array(
                        'category_id' => array('type' => 'integer'),
                        'name'        => array('type' => 'string'),
                        'slug'        => array('type' => 'string'),
                        'description' => array('type' => 'string'),
                    ),
                    'required' => array('category_id'),
                ),
            ),
            'wc_delete_product_category' => array(
                'name' => 'wc_delete_product_category',
                'description' => 'Delete a product category by ID.',
                'inputSchema' => array(
                    'type' => 'object',
                    'properties' => array(
                        'category_id' => array('type' => 'integer'),
                        'force'       => array('type' => 'boolean'),
                    ),
                    'required' => array('category_id'),
                ),
            ),
            
            // Product Tags
            'wc_get_product_tags' => array(
                'name' => 'wc_get_product_tags',
                'description' => 'List WooCommerce product tags.',
                'inputSchema' => array(
                    'type' => 'object',
                    'properties' => array(
                        'hide_empty' => array('type' => 'boolean'),
                        'limit'      => array('type' => 'integer'),
                        'search'     => array('type' => 'string'),
                    ),
                    'required' => array(),
                ),
            ),
            'wc_create_product_tag' => array(
                'name' => 'wc_create_product_tag',
                'description' => 'Create a product tag.',
                'inputSchema' => array(
                    'type' => 'object',
                    'properties' => array(
                        'name'        => array('type' => 'string'),
                        'slug'        => array('type' => 'string'),
                        'description' => array('type' => 'string'),
                    ),
                    'required' => array('name'),
                ),
            ),
            'wc_update_product_tag' => array(
                'name' => 'wc_update_product_tag',
                'description' => 'Update a product tag.',
                'inputSchema' => array(
                    'type' => 'object',
                    'properties' => array(
                        'tag_id'      => array('type' => 'integer'),
                        'name'        => array('type' => 'string'),
                        'slug'        => array('type' => 'string'),
                        'description' => array('type' => 'string'),
                    ),
                    'required' => array('tag_id'),
                ),
            ),
            'wc_delete_product_tag' => array(
                'name' => 'wc_delete_product_tag',
                'description' => 'Delete a product tag by ID.',
                'inputSchema' => array(
                    'type' => 'object',
                    'properties' => array(
                        'tag_id' => array('type' => 'integer'),
                        'force'  => array('type' => 'boolean'),
                    ),
                    'required' => array('tag_id'),
                ),
            ),
            
            // Product Reviews
            'wc_get_product_reviews' => array(
                'name' => 'wc_get_product_reviews',
                'description' => 'List product reviews. Optionally filter by product_id.',
                'inputSchema' => array(
                    'type' => 'object',
                    'properties' => array(
                        'product_id' => array('type' => 'integer'),
                        'status'     => array('type' => 'string'),
                        'limit'      => array('type' => 'integer'),
                    ),
                    'required' => array(),
                ),
            ),
            'wc_create_product_review' => array(
                'name' => 'wc_create_product_review',
                'description' => 'Create a product review.',
                'inputSchema' => array(
                    'type' => 'object',
                    'properties' => array(
                        'product_id' => array('type' => 'integer'),
                        'content'    => array('type' => 'string'),
                        'author'     => array('type' => 'string'),
                        'email'      => array('type' => 'string'),
                        'rating'     => array('type' => 'integer'),
                    ),
                    'required' => array('product_id', 'content'),
                ),
            ),
            'wc_update_product_review' => array(
                'name' => 'wc_update_product_review',
                'description' => 'Update a product review.',
                'inputSchema' => array(
                    'type' => 'object',
                    'properties' => array(
                        'review_id' => array('type' => 'integer'),
                        'content'   => array('type' => 'string'),
                        'status'    => array('type' => 'string'),
                        'rating'    => array('type' => 'integer'),
                    ),
                    'required' => array('review_id'),
                ),
            ),
            'wc_delete_product_review' => array(
                'name' => 'wc_delete_product_review',
                'description' => 'Delete a product review.',
                'inputSchema' => array(
                    'type' => 'object',
                    'properties' => array(
                        'review_id' => array('type' => 'integer'),
                        'force'     => array('type' => 'boolean'),
                    ),
                    'required' => array('review_id'),
                ),
            ),
            
            // Stock Management
            'wc_update_stock' => array(
                'name' => 'wc_update_stock',
                'description' => 'Update stock quantity for a product.',
                'inputSchema' => array(
                    'type' => 'object',
                    'properties' => array(
                        'product_id' => array('type' => 'integer'),
                        'quantity'   => array('type' => 'integer'),
                        'operation'  => array('type' => 'string'), // 'set', 'increase', 'decrease'
                    ),
                    'required' => array('product_id', 'quantity'),
                ),
            ),
            'wc_get_low_stock_products' => array(
                'name' => 'wc_get_low_stock_products',
                'description' => 'Get products with low stock (below threshold).',
                'inputSchema' => array(
                    'type' => 'object',
                    'properties' => array(
                        'threshold' => array('type' => 'integer'),
                        'limit'     => array('type' => 'integer'),
                    ),
                    'required' => array(),
                ),
            ),
            'wc_set_stock_status' => array(
                'name' => 'wc_set_stock_status',
                'description' => 'Set stock status for a product (instock, outofstock, onbackorder).',
                'inputSchema' => array(
                    'type' => 'object',
                    'properties' => array(
                        'product_id' => array('type' => 'integer'),
                        'status'     => array('type' => 'string'),
                    ),
                    'required' => array('product_id', 'status'),
                ),
            ),
        );
    }
    
    /**
     * Get capability mappings for product tools
     */
    public static function getCapabilities() {
        return array(
            'wc_create_product' => 'edit_products',
            'wc_update_product' => 'edit_products',
            'wc_delete_product' => 'delete_products',
            'wc_batch_update_products' => 'edit_products',
            'wc_create_product_variation' => 'edit_products',
            'wc_update_product_variation' => 'edit_products',
            'wc_delete_product_variation' => 'delete_products',
            'wc_create_product_category' => 'manage_product_terms',
            'wc_update_product_category' => 'manage_product_terms',
            'wc_delete_product_category' => 'manage_product_terms',
            'wc_create_product_tag' => 'manage_product_terms',
            'wc_update_product_tag' => 'manage_product_terms',
            'wc_delete_product_tag' => 'manage_product_terms',
            'wc_create_product_review' => 'moderate_comments',
            'wc_update_product_review' => 'moderate_comments',
            'wc_delete_product_review' => 'moderate_comments',
            'wc_update_stock' => 'edit_products',
            'wc_set_stock_status' => 'edit_products',
        );
    }
    
    /**
     * Dispatch product tool execution
     */
    public static function dispatch($tool, $args, &$r, $addResultText, $utils) {
        if (!class_exists('WooCommerce')) {
            $r['error'] = array('code' => -50000, 'message' => 'WooCommerce is not active');
            return true;
        }
        
        switch ($tool) {
            case 'wc_get_products':
                $query_args = array(
                    'limit' => intval($utils::getArrayValue($args, 'limit', 10)),
                    'offset' => intval($utils::getArrayValue($args, 'offset', 0)),
                    'status' => sanitize_text_field($utils::getArrayValue($args, 'status', 'any')),
                    'orderby' => sanitize_key($utils::getArrayValue($args, 'orderby', 'date')),
                    'order' => sanitize_key($utils::getArrayValue($args, 'order', 'DESC')),
                );
                
                if (!empty($args['type'])) {
                    $query_args['type'] = sanitize_text_field($args['type']);
                }
                if (!empty($args['category'])) {
                    $query_args['category'] = array(sanitize_text_field($args['category']));
                }
                if (!empty($args['tag'])) {
                    $query_args['tag'] = array(sanitize_text_field($args['tag']));
                }
                if (!empty($args['search'])) {
                    $query_args['s'] = sanitize_text_field($args['search']);
                }
                
                $products = wc_get_products($query_args);
                $result = array();
                foreach ($products as $product) {
                    $result[] = array(
                        'id' => $product->get_id(),
                        'name' => $product->get_name(),
                        'slug' => $product->get_slug(),
                        'type' => $product->get_type(),
                        'status' => $product->get_status(),
                        'price' => $product->get_price(),
                        'regular_price' => $product->get_regular_price(),
                        'sale_price' => $product->get_sale_price(),
                        'sku' => $product->get_sku(),
                        'stock_quantity' => $product->get_stock_quantity(),
                        'stock_status' => $product->get_stock_status(),
                        'permalink' => $product->get_permalink(),
                    );
                }
                $addResultText($r, 'Found ' . count($result) . ' products: ' . wp_json_encode($result, JSON_PRETTY_PRINT));
                return true;
                
            case 'wc_create_product':
                $product_type = sanitize_key($utils::getArrayValue($args, 'type', 'simple'));
                
                // Create product based on type
                switch ($product_type) {
                    case 'variable':
                        $product = new WC_Product_Variable();
                        break;
                    case 'grouped':
                        $product = new WC_Product_Grouped();
                        break;
                    case 'external':
                        $product = new WC_Product_External();
                        break;
                    default:
                        $product = new WC_Product_Simple();
                }
                
                $product->set_name(sanitize_text_field($utils::getArrayValue($args, 'name', '')));
                
                if (!empty($args['description'])) {
                    $product->set_description(wp_kses_post($args['description']));
                }
                if (!empty($args['short_description'])) {
                    $product->set_short_description(wp_kses_post($args['short_description']));
                }
                if (!empty($args['sku'])) {
                    $product->set_sku(sanitize_text_field($args['sku']));
                }
                if (isset($args['regular_price'])) {
                    $product->set_regular_price(sanitize_text_field($args['regular_price']));
                }
                if (isset($args['sale_price'])) {
                    $product->set_sale_price(sanitize_text_field($args['sale_price']));
                }
                if (!empty($args['status'])) {
                    $product->set_status(sanitize_key($args['status']));
                }
                if (isset($args['stock_quantity'])) {
                    $product->set_stock_quantity(intval($args['stock_quantity']));
                    $product->set_manage_stock(true);
                }
                if (!empty($args['categories'])) {
                    $cat_ids = array_map('intval', (array) $args['categories']);
                    $product->set_category_ids($cat_ids);
                }
                if (!empty($args['tags'])) {
                    $tag_ids = array_map('intval', (array) $args['tags']);
                    $product->set_tag_ids($tag_ids);
                }
                
                // Handle attributes for variable products
                if (!empty($args['attributes']) && is_array($args['attributes'])) {
                    $attributes = array();
                    $position = 0;
                    
                    foreach ($args['attributes'] as $attr_data) {
                        $attr_name = sanitize_text_field($attr_data['name'] ?? '');
                        if (empty($attr_name)) {
                            continue;
                        }
                        
                        $attribute = new WC_Product_Attribute();
                        $attribute->set_name($attr_name);
                        $attribute->set_options(isset($attr_data['options']) ? (array) $attr_data['options'] : array());
                        $attribute->set_visible(isset($attr_data['visible']) ? (bool) $attr_data['visible'] : true);
                        $attribute->set_variation(isset($attr_data['variation']) ? (bool) $attr_data['variation'] : false);
                        $attribute->set_position($position++);
                        
                        $attributes[] = $attribute;
                    }
                    
                    if (!empty($attributes)) {
                        $product->set_attributes($attributes);
                    }
                }
                
                $product_id = $product->save();
                $addResultText($r, 'Product created with ID: ' . $product_id . ' (type: ' . $product_type . ')');
                return true;
                
            case 'wc_update_product':
                $product_id = intval($utils::getArrayValue($args, 'product_id', 0));
                if (empty($product_id)) {
                    $r['error'] = array('code' => -50001, 'message' => 'product_id is required');
                    return true;
                }
                
                $product = wc_get_product($product_id);
                if (!$product) {
                    $r['error'] = array('code' => -50002, 'message' => 'Product not found');
                    return true;
                }
                
                if (!empty($args['name'])) {
                    $product->set_name(sanitize_text_field($args['name']));
                }
                if (isset($args['description'])) {
                    $product->set_description(wp_kses_post($args['description']));
                }
                if (isset($args['short_description'])) {
                    $product->set_short_description(wp_kses_post($args['short_description']));
                }
                if (isset($args['regular_price'])) {
                    $product->set_regular_price(sanitize_text_field($args['regular_price']));
                }
                if (isset($args['sale_price'])) {
                    $product->set_sale_price(sanitize_text_field($args['sale_price']));
                }
                if (!empty($args['sku'])) {
                    $product->set_sku(sanitize_text_field($args['sku']));
                }
                if (!empty($args['status'])) {
                    $product->set_status(sanitize_key($args['status']));
                }
                if (isset($args['stock_quantity'])) {
                    $product->set_stock_quantity(intval($args['stock_quantity']));
                    $product->set_manage_stock(true);
                }
                if (isset($args['categories'])) {
                    $cat_ids = array_map('intval', (array) $args['categories']);
                    $product->set_category_ids($cat_ids);
                }
                if (isset($args['tags'])) {
                    $tag_ids = array_map('intval', (array) $args['tags']);
                    $product->set_tag_ids($tag_ids);
                }
                
                $product->save();
                $addResultText($r, 'Product updated: ' . $product_id);
                return true;
                
            case 'wc_delete_product':
                $product_id = intval($utils::getArrayValue($args, 'product_id', 0));
                if (empty($product_id)) {
                    $r['error'] = array('code' => -50001, 'message' => 'product_id is required');
                    return true;
                }
                
                $force = (bool) $utils::getArrayValue($args, 'force', false);
                $result = wp_delete_post($product_id, $force);
                
                if ($result) {
                    $addResultText($r, 'Product deleted: ' . $product_id);
                } else {
                    $r['error'] = array('code' => -50003, 'message' => 'Failed to delete product');
                }
                return true;
                
            case 'wc_batch_update_products':
                $updates = $utils::getArrayValue($args, 'updates', array());
                if (empty($updates) || !is_array($updates)) {
                    $r['error'] = array('code' => -50001, 'message' => 'updates array is required');
                    return true;
                }
                
                $results = array();
                foreach ($updates as $update) {
                    if (empty($update['product_id'])) {
                        continue;
                    }
                    
                    $product = wc_get_product(intval($update['product_id']));
                    if (!$product) {
                        continue;
                    }
                    
                    if (isset($update['regular_price'])) {
                        $product->set_regular_price(sanitize_text_field($update['regular_price']));
                    }
                    if (isset($update['sale_price'])) {
                        $product->set_sale_price(sanitize_text_field($update['sale_price']));
                    }
                    if (isset($update['stock_quantity'])) {
                        $product->set_stock_quantity(intval($update['stock_quantity']));
                    }
                    if (isset($update['status'])) {
                        $product->set_status(sanitize_key($update['status']));
                    }
                    
                    $product->save();
                    $results[] = $update['product_id'];
                }
                
                $addResultText($r, 'Updated ' . count($results) . ' products: ' . implode(', ', $results));
                return true;
                
            case 'wc_get_product_variations':
                $product_id = intval($utils::getArrayValue($args, 'product_id', 0));
                if (empty($product_id)) {
                    $r['error'] = array('code' => -50001, 'message' => 'product_id is required');
                    return true;
                }
                
                $product = wc_get_product($product_id);
                if (!$product || !$product->is_type('variable')) {
                    $r['error'] = array('code' => -50002, 'message' => 'Product is not a variable product');
                    return true;
                }
                
                $variations = $product->get_available_variations();
                $result = array();
                foreach ($variations as $variation_data) {
                    $variation = wc_get_product($variation_data['variation_id']);
                    if ($variation) {
                        $result[] = array(
                            'id' => $variation->get_id(),
                            'sku' => $variation->get_sku(),
                            'price' => $variation->get_price(),
                            'regular_price' => $variation->get_regular_price(),
                            'sale_price' => $variation->get_sale_price(),
                            'stock_quantity' => $variation->get_stock_quantity(),
                            'attributes' => $variation->get_attributes(),
                        );
                    }
                }
                
                $addResultText($r, 'Found ' . count($result) . ' variations: ' . wp_json_encode($result, JSON_PRETTY_PRINT));
                return true;
                
            case 'wc_create_product_variation':
                $product_id = intval($utils::getArrayValue($args, 'product_id', 0));
                if (empty($product_id)) {
                    $r['error'] = array('code' => -50001, 'message' => 'product_id is required');
                    return true;
                }
                
                $product = wc_get_product($product_id);
                if (!$product || !$product->is_type('variable')) {
                    $r['error'] = array('code' => -50002, 'message' => 'Product is not a variable product');
                    return true;
                }
                
                $variation = new WC_Product_Variation();
                $variation->set_parent_id($product_id);
                
                if (!empty($args['attributes']) && is_array($args['attributes'])) {
                    $variation->set_attributes($args['attributes']);
                }
                if (isset($args['regular_price'])) {
                    $variation->set_regular_price(sanitize_text_field($args['regular_price']));
                }
                if (isset($args['sale_price'])) {
                    $variation->set_sale_price(sanitize_text_field($args['sale_price']));
                }
                if (!empty($args['sku'])) {
                    $variation->set_sku(sanitize_text_field($args['sku']));
                }
                if (isset($args['stock_quantity'])) {
                    $variation->set_stock_quantity(intval($args['stock_quantity']));
                    $variation->set_manage_stock(true);
                }
                
                $variation_id = $variation->save();
                $addResultText($r, 'Variation created with ID: ' . $variation_id);
                return true;
                
            case 'wc_update_product_variation':
                $variation_id = intval($utils::getArrayValue($args, 'variation_id', 0));
                if (empty($variation_id)) {
                    $r['error'] = array('code' => -50001, 'message' => 'variation_id is required');
                    return true;
                }
                
                $variation = wc_get_product($variation_id);
                if (!$variation || !$variation->is_type('variation')) {
                    $r['error'] = array('code' => -50002, 'message' => 'Variation not found');
                    return true;
                }
                
                if (isset($args['regular_price'])) {
                    $variation->set_regular_price(sanitize_text_field($args['regular_price']));
                }
                if (isset($args['sale_price'])) {
                    $variation->set_sale_price(sanitize_text_field($args['sale_price']));
                }
                if (isset($args['sku'])) {
                    $variation->set_sku(sanitize_text_field($args['sku']));
                }
                if (isset($args['stock_quantity'])) {
                    $variation->set_stock_quantity(intval($args['stock_quantity']));
                }
                if (isset($args['attributes']) && is_array($args['attributes'])) {
                    $variation->set_attributes($args['attributes']);
                }
                
                $variation->save();
                $addResultText($r, 'Variation updated: ' . $variation_id);
                return true;
                
            case 'wc_delete_product_variation':
                $variation_id = intval($utils::getArrayValue($args, 'variation_id', 0));
                if (empty($variation_id)) {
                    $r['error'] = array('code' => -50001, 'message' => 'variation_id is required');
                    return true;
                }
                
                $force = (bool) $utils::getArrayValue($args, 'force', false);
                $result = wp_delete_post($variation_id, $force);
                
                if ($result) {
                    $addResultText($r, 'Variation deleted: ' . $variation_id);
                } else {
                    $r['error'] = array('code' => -50003, 'message' => 'Failed to delete variation');
                }
                return true;
                
            case 'wc_get_product_categories':
                $args_tax = array(
                    'taxonomy' => 'product_cat',
                    'hide_empty' => (bool) $utils::getArrayValue($args, 'hide_empty', false),
                    'number' => intval($utils::getArrayValue($args, 'limit', 100)),
                    'offset' => intval($utils::getArrayValue($args, 'offset', 0)),
                );
                
                if (!empty($args['search'])) {
                    $args_tax['search'] = sanitize_text_field($args['search']);
                }
                
                $terms = get_terms($args_tax);
                $result = array();
                
                if (!is_wp_error($terms)) {
                    foreach ($terms as $term) {
                        $result[] = array(
                            'id' => $term->term_id,
                            'name' => $term->name,
                            'slug' => $term->slug,
                            'count' => $term->count,
                            'parent' => $term->parent,
                        );
                    }
                }
                
                $addResultText($r, 'Found ' . count($result) . ' categories: ' . wp_json_encode($result, JSON_PRETTY_PRINT));
                return true;
                
            case 'wc_create_product_category':
                $name = sanitize_text_field($utils::getArrayValue($args, 'name', ''));
                if (empty($name)) {
                    $r['error'] = array('code' => -50001, 'message' => 'name is required');
                    return true;
                }
                
                $term_args = array();
                if (!empty($args['slug'])) {
                    $term_args['slug'] = sanitize_title($args['slug']);
                }
                if (!empty($args['description'])) {
                    $term_args['description'] = sanitize_textarea_field($args['description']);
                }
                if (!empty($args['parent'])) {
                    $term_args['parent'] = intval($args['parent']);
                }
                
                $result = wp_insert_term($name, 'product_cat', $term_args);
                
                if (is_wp_error($result)) {
                    $r['error'] = array('code' => -50004, 'message' => $result->get_error_message());
                } else {
                    $addResultText($r, 'Category created with ID: ' . $result['term_id']);
                }
                return true;
                
            case 'wc_update_product_category':
                $term_id = intval($utils::getArrayValue($args, 'category_id', 0));
                if (empty($term_id)) {
                    $r['error'] = array('code' => -50001, 'message' => 'category_id is required');
                    return true;
                }
                
                $term_args = array();
                if (!empty($args['name'])) {
                    $term_args['name'] = sanitize_text_field($args['name']);
                }
                if (isset($args['slug'])) {
                    $term_args['slug'] = sanitize_title($args['slug']);
                }
                if (isset($args['description'])) {
                    $term_args['description'] = sanitize_textarea_field($args['description']);
                }
                if (isset($args['parent'])) {
                    $term_args['parent'] = intval($args['parent']);
                }
                
                $result = wp_update_term($term_id, 'product_cat', $term_args);
                
                if (is_wp_error($result)) {
                    $r['error'] = array('code' => -50004, 'message' => $result->get_error_message());
                } else {
                    $addResultText($r, 'Category updated: ' . $term_id);
                }
                return true;
                
            case 'wc_delete_product_category':
                $term_id = intval($utils::getArrayValue($args, 'category_id', 0));
                if (empty($term_id)) {
                    $r['error'] = array('code' => -50001, 'message' => 'category_id is required');
                    return true;
                }
                
                $result = wp_delete_term($term_id, 'product_cat');
                
                if (is_wp_error($result)) {
                    $r['error'] = array('code' => -50004, 'message' => $result->get_error_message());
                } else {
                    $addResultText($r, 'Category deleted: ' . $term_id);
                }
                return true;
                
            case 'wc_get_product_tags':
                $args_tax = array(
                    'taxonomy' => 'product_tag',
                    'hide_empty' => (bool) $utils::getArrayValue($args, 'hide_empty', false),
                    'number' => intval($utils::getArrayValue($args, 'limit', 100)),
                    'offset' => intval($utils::getArrayValue($args, 'offset', 0)),
                );
                
                if (!empty($args['search'])) {
                    $args_tax['search'] = sanitize_text_field($args['search']);
                }
                
                $terms = get_terms($args_tax);
                $result = array();
                
                if (!is_wp_error($terms)) {
                    foreach ($terms as $term) {
                        $result[] = array(
                            'id' => $term->term_id,
                            'name' => $term->name,
                            'slug' => $term->slug,
                            'count' => $term->count,
                        );
                    }
                }
                
                $addResultText($r, 'Found ' . count($result) . ' tags: ' . wp_json_encode($result, JSON_PRETTY_PRINT));
                return true;
                
            case 'wc_create_product_tag':
                $name = sanitize_text_field($utils::getArrayValue($args, 'name', ''));
                if (empty($name)) {
                    $r['error'] = array('code' => -50001, 'message' => 'name is required');
                    return true;
                }
                
                $term_args = array();
                if (!empty($args['slug'])) {
                    $term_args['slug'] = sanitize_title($args['slug']);
                }
                if (!empty($args['description'])) {
                    $term_args['description'] = sanitize_textarea_field($args['description']);
                }
                
                $result = wp_insert_term($name, 'product_tag', $term_args);
                
                if (is_wp_error($result)) {
                    $r['error'] = array('code' => -50004, 'message' => $result->get_error_message());
                } else {
                    $addResultText($r, 'Tag created with ID: ' . $result['term_id']);
                }
                return true;
                
            case 'wc_update_product_tag':
                $term_id = intval($utils::getArrayValue($args, 'tag_id', 0));
                if (empty($term_id)) {
                    $r['error'] = array('code' => -50001, 'message' => 'tag_id is required');
                    return true;
                }
                
                $term_args = array();
                if (!empty($args['name'])) {
                    $term_args['name'] = sanitize_text_field($args['name']);
                }
                if (isset($args['slug'])) {
                    $term_args['slug'] = sanitize_title($args['slug']);
                }
                if (isset($args['description'])) {
                    $term_args['description'] = sanitize_textarea_field($args['description']);
                }
                
                $result = wp_update_term($term_id, 'product_tag', $term_args);
                
                if (is_wp_error($result)) {
                    $r['error'] = array('code' => -50004, 'message' => $result->get_error_message());
                } else {
                    $addResultText($r, 'Tag updated: ' . $term_id);
                }
                return true;
                
            case 'wc_delete_product_tag':
                $term_id = intval($utils::getArrayValue($args, 'tag_id', 0));
                if (empty($term_id)) {
                    $r['error'] = array('code' => -50001, 'message' => 'tag_id is required');
                    return true;
                }
                
                $result = wp_delete_term($term_id, 'product_tag');
                
                if (is_wp_error($result)) {
                    $r['error'] = array('code' => -50004, 'message' => $result->get_error_message());
                } else {
                    $addResultText($r, 'Tag deleted: ' . $term_id);
                }
                return true;
                
            case 'wc_get_product_reviews':
                $query_args = array(
                    'post_type' => 'product',
                    'status' => 'approve',
                    'number' => intval($utils::getArrayValue($args, 'limit', 10)),
                    'offset' => intval($utils::getArrayValue($args, 'offset', 0)),
                );
                
                if (!empty($args['product_id'])) {
                    $query_args['post_id'] = intval($args['product_id']);
                }
                
                $comments = get_comments($query_args);
                $result = array();
                
                foreach ($comments as $comment) {
                    $result[] = array(
                        'id' => $comment->comment_ID,
                        'product_id' => $comment->comment_post_ID,
                        'author' => $comment->comment_author,
                        'email' => $comment->comment_author_email,
                        'content' => $comment->comment_content,
                        'rating' => get_comment_meta($comment->comment_ID, 'rating', true),
                        'date' => $comment->comment_date,
                    );
                }
                
                $addResultText($r, 'Found ' . count($result) . ' reviews: ' . wp_json_encode($result, JSON_PRETTY_PRINT));
                return true;
                
            case 'wc_create_product_review':
                $product_id = intval($utils::getArrayValue($args, 'product_id', 0));
                $content = wp_kses_post($utils::getArrayValue($args, 'content', ''));
                
                if (empty($product_id) || empty($content)) {
                    $r['error'] = array('code' => -50001, 'message' => 'product_id and content are required');
                    return true;
                }
                
                $comment_data = array(
                    'comment_post_ID' => $product_id,
                    'comment_content' => $content,
                    'comment_type' => 'review',
                    'comment_approved' => 1,
                );
                
                if (!empty($args['author'])) {
                    $comment_data['comment_author'] = sanitize_text_field($args['author']);
                }
                if (!empty($args['email'])) {
                    $comment_data['comment_author_email'] = sanitize_email($args['email']);
                }
                
                $comment_id = wp_insert_comment($comment_data);
                
                if ($comment_id && !empty($args['rating'])) {
                    update_comment_meta($comment_id, 'rating', intval($args['rating']));
                }
                
                $addResultText($r, 'Review created with ID: ' . $comment_id);
                return true;
                
            case 'wc_update_product_review':
                $review_id = intval($utils::getArrayValue($args, 'review_id', 0));
                if (empty($review_id)) {
                    $r['error'] = array('code' => -50001, 'message' => 'review_id is required');
                    return true;
                }
                
                $comment_data = array('comment_ID' => $review_id);
                
                if (isset($args['content'])) {
                    $comment_data['comment_content'] = wp_kses_post($args['content']);
                }
                if (isset($args['status'])) {
                    $comment_data['comment_approved'] = sanitize_key($args['status']);
                }
                
                $result = wp_update_comment($comment_data);
                
                if ($result && isset($args['rating'])) {
                    update_comment_meta($review_id, 'rating', intval($args['rating']));
                }
                
                $addResultText($r, 'Review updated: ' . $review_id);
                return true;
                
            case 'wc_delete_product_review':
                $review_id = intval($utils::getArrayValue($args, 'review_id', 0));
                if (empty($review_id)) {
                    $r['error'] = array('code' => -50001, 'message' => 'review_id is required');
                    return true;
                }
                
                $force = (bool) $utils::getArrayValue($args, 'force', false);
                $result = wp_delete_comment($review_id, $force);
                
                if ($result) {
                    $addResultText($r, 'Review deleted: ' . $review_id);
                } else {
                    $r['error'] = array('code' => -50003, 'message' => 'Failed to delete review');
                }
                return true;
                
            // Stock Management
            case 'wc_update_stock':
                $product_id = intval($utils::getArrayValue($args, 'product_id', 0));
                $quantity = intval($utils::getArrayValue($args, 'quantity', 0));
                $operation = sanitize_key($utils::getArrayValue($args, 'operation', 'set'));
                
                if (empty($product_id)) {
                    $r['error'] = array('code' => -50001, 'message' => 'product_id is required');
                    return true;
                }
                
                $product = wc_get_product($product_id);
                if (!$product) {
                    $r['error'] = array('code' => -50002, 'message' => 'Product not found');
                    return true;
                }
                
                $product->set_manage_stock(true);
                
                switch ($operation) {
                    case 'increase':
                        $new_stock = $product->get_stock_quantity() + $quantity;
                        $product->set_stock_quantity($new_stock);
                        break;
                    case 'decrease':
                        $new_stock = $product->get_stock_quantity() - $quantity;
                        $product->set_stock_quantity($new_stock);
                        break;
                    case 'set':
                    default:
                        $product->set_stock_quantity($quantity);
                        break;
                }
                
                $product->save();
                
                $addResultText($r, 'Stock updated for product #' . $product_id . ': ' . $product->get_stock_quantity());
                return true;
                
            case 'wc_get_low_stock_products':
                $threshold = intval($utils::getArrayValue($args, 'threshold', 5));
                $limit = intval($utils::getArrayValue($args, 'limit', 50));
                
                $query_args = array(
                    'limit' => $limit,
                    'stock_quantity' => array(0, $threshold),
                    'stock_status' => 'instock',
                );
                
                $products = wc_get_products($query_args);
                $result = array();
                
                foreach ($products as $product) {
                    if ($product->managing_stock()) {
                        $stock_qty = $product->get_stock_quantity();
                        if ($stock_qty !== null && $stock_qty <= $threshold) {
                            $result[] = array(
                                'id' => $product->get_id(),
                                'name' => $product->get_name(),
                                'sku' => $product->get_sku(),
                                'stock_quantity' => $stock_qty,
                                'stock_status' => $product->get_stock_status(),
                            );
                        }
                    }
                }
                
                $addResultText($r, 'Found ' . count($result) . ' low stock products: ' . wp_json_encode($result, JSON_PRETTY_PRINT));
                return true;
                
            case 'wc_set_stock_status':
                $product_id = intval($utils::getArrayValue($args, 'product_id', 0));
                $status = sanitize_key($utils::getArrayValue($args, 'status', 'instock'));
                
                if (empty($product_id)) {
                    $r['error'] = array('code' => -50001, 'message' => 'product_id is required');
                    return true;
                }
                
                if (!in_array($status, array('instock', 'outofstock', 'onbackorder'), true)) {
                    $r['error'] = array('code' => -50007, 'message' => 'Invalid stock status. Must be: instock, outofstock, or onbackorder');
                    return true;
                }
                
                $product = wc_get_product($product_id);
                if (!$product) {
                    $r['error'] = array('code' => -50002, 'message' => 'Product not found');
                    return true;
                }
                
                $product->set_stock_status($status);
                $product->save();
                
                $addResultText($r, 'Stock status set to "' . $status . '" for product #' . $product_id);
                return true;
        }
        
        return null; // Tool not handled by this module
    }
}
