<?php
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

// Load WooCommerce modules if WooCommerce is active
if ( class_exists( 'WooCommerce' ) ) {
    require_once dirname(__FILE__) . '/woocommerce/wc-products.php';
    require_once dirname(__FILE__) . '/woocommerce/wc-orders.php';
    require_once dirname(__FILE__) . '/woocommerce/wc-customers-coupons.php';
    require_once dirname(__FILE__) . '/woocommerce/wc-system.php';
}

// Model MCP con tools completas + intención/consentimiento
class StifliFlexMcpModel {
    private $tools = false;

    /**
     * Clasificación de intención y confirmación por tool.
     */
    private function getIntentForTool(string $name): array {
        // Escritura/mutación
        $WRITE = array(
            'wp_create_post','wp_update_post','wp_delete_post',
            'wp_create_comment','wp_update_comment','wp_delete_comment',
            // Removed for WordPress.org compliance: wp_create_user, wp_update_user, wp_delete_user
            'wp_upload_image_from_url',
            // Removed: wp_activate_plugin, wp_deactivate_plugin, wp_install_plugin, wp_install_theme, wp_switch_theme (WordPress.org compliance)
            'wp_update_option','wp_delete_option',
            'wp_update_post_meta','wp_delete_post_meta',
            'wp_create_term','wp_delete_term',
            'wp_create_nav_menu','wp_add_nav_menu_item','wp_update_nav_menu_item','wp_delete_nav_menu_item','wp_delete_nav_menu',
            'wp_create_page','wp_update_page','wp_delete_page',
            'wp_create_category','wp_update_category','wp_delete_category',
            'wp_create_tag','wp_update_tag','wp_delete_tag',
            'wp_update_media_item','wp_delete_media_item',
            'wp_update_settings',
            // WordPress - Additional write operations
            'wp_update_user_meta','wp_delete_user_meta',
            'wp_restore_post_revision',
            // WooCommerce write operations
            'wc_create_product','wc_update_product','wc_delete_product','wc_batch_update_products',
            'wc_create_product_variation','wc_update_product_variation','wc_delete_product_variation',
            'wc_create_product_category','wc_update_product_category','wc_delete_product_category',
            'wc_create_product_tag','wc_update_product_tag','wc_delete_product_tag',
            'wc_create_product_review','wc_update_product_review','wc_delete_product_review',
            'wc_create_order','wc_update_order','wc_delete_order','wc_batch_update_orders',
            'wc_create_order_note','wc_delete_order_note',
            // Removed for WordPress.org compliance: wc_create_customer, wc_update_customer, wc_delete_customer
            'wc_create_coupon','wc_update_coupon','wc_delete_coupon',
            'wc_create_tax_rate','wc_update_tax_rate','wc_delete_tax_rate',
            'wc_create_shipping_zone','wc_update_shipping_zone','wc_delete_shipping_zone',
            'wc_update_payment_gateway',
            'wc_run_system_status_tool',
            'wc_update_setting_option',
            'wc_create_webhook','wc_update_webhook','wc_delete_webhook',
            // WooCommerce - Stock & Refunds
            'wc_update_stock','wc_set_stock_status',
            'wc_create_refund','wc_delete_refund'
        );

        // Lectura sensible (requiere permisos elevados o toca red externa)
        $SENSITIVE_READ = array(
            'wp_get_option',        // requiere manage_options en dispatch
            'wp_get_post_meta',     // requiere manage_options en dispatch
            'wp_get_settings',      // requiere manage_options
            'fetch',                // red externa: tratar como lectura sensible
            // WordPress - Additional sensitive reads
            'wp_get_user_meta',     // user privacy data
            'wp_get_site_health',   // system information
            // WooCommerce sensitive reads (wc_get_customers removed for WordPress.org compliance)
            'wc_get_orders',        // order data privacy
            'wc_get_order_notes',   // order notes may contain sensitive info
            'wc_get_system_status', // system information
            'wc_get_settings'       // WooCommerce settings
        );

        if (in_array($name, $WRITE, true)) {
            return array('intent' => 'write', 'requires_confirmation' => true);
        }
        if (in_array($name, $SENSITIVE_READ, true)) {
            return array('intent' => 'sensitive_read', 'requires_confirmation' => true);
        }
        return array('intent' => 'read', 'requires_confirmation' => false);
    }

    /**
     * Devuelve la lista de tools con categoría + intención + confirmación.
     * Filtra por herramientas habilitadas en wp_sflmcp_tools.
     */
    public function getToolsList() {
        global $wpdb;
        $tools = $this->getTools();
        if (!is_array($tools)) {
            return [];
        }
        
        // Get enabled tools from database
        $table = StifliFlexMcpUtils::getPrefixedTable('sflmcp_tools', false);
        $enabled_tools = array();

        // Check if table exists first.
        $like = $wpdb->esc_like($table);
        $table_exists_query = 'SHOW TABLES LIKE %s';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- schema introspection requires SHOW TABLES.
        $table_exists = $wpdb->get_var($wpdb->prepare($table_exists_query, $like)) === $table;
        
        if ($table_exists) {
            $tools_query = StifliFlexMcpUtils::formatSqlWithTables(
                'SELECT tool_name, token_estimate FROM %s WHERE enabled = %%d',
                'sflmcp_tools'
            );
            $results = $wpdb->get_results(
                $wpdb->prepare($tools_query, 1),
                ARRAY_A
            );
            foreach ($results as $row) {
                $name = isset($row['tool_name']) ? $row['tool_name'] : '';
                if ('' === $name) {
                    continue;
                }
                $enabled_tools[$name] = isset($row['token_estimate']) ? (int) $row['token_estimate'] : 0;
            }
        }
        
        // Filter tools by enabled status
        $filtered_tools = array();
        foreach ($tools as $tool) {
            $name = StifliFlexMcpUtils::getArrayValue($tool, 'name', '');
            if ('' === $name) {
                continue;
            }
            // If table doesn't exist or tool is in enabled list, include it
            if (!$table_exists || array_key_exists($name, $enabled_tools)) {
                // Categoría
                if (in_array($name, array('search', 'fetch'), true)) {
                    $tool['category'] = 'Core: OpenAI';
                } else {
                    $tool['category'] = 'Core';
                }
                // Intención y consentimiento
                $meta = $this->getIntentForTool($name);
                $tool['intent'] = $meta['intent']; // read | sensitive_read | write
                $tool['requires_confirmation'] = $meta['requires_confirmation']; // bool
                if ($table_exists) {
                    $tool['tokenEstimate'] = isset($enabled_tools[$name]) ? (int) $enabled_tools[$name] : StifliFlexMcpUtils::estimateToolTokenUsage($tool);
                } else {
                    $tool['tokenEstimate'] = StifliFlexMcpUtils::estimateToolTokenUsage($tool);
                }
                
                $filtered_tools[] = $tool;
            }
        }
        
        return array_values($filtered_tools);
    }

    /**
     * Definición completa de tools usadas en dispatch (sin duplicados).
     */
    public function getTools() {
        if (empty($this->tools)) {
            $tools = array(
                // Diagnóstico
                'mcp_ping' => array(
                    'name' => 'mcp_ping',
                    'description' => 'Simple connectivity check. Returns the current GMT time and the WordPress site name.',
                    'inputSchema' => array(
                        'type' => 'object',
                        'properties' => (object) array(),
                        'required' => array(),
                    ),
                ),

                // Posts (lectura)
                'wp_get_posts' => array(
                    'name' => 'wp_get_posts',
                    'description' => 'List posts with filters.',
                    'inputSchema' => array(
                        'type' => 'object',
                        'properties' => array(
                            'post_type'   => array('type' => 'string'),
                            'post_status' => array('type' => 'string'),
                            'search'      => array('type' => 'string'),
                            'limit'       => array('type' => 'integer'),
                            'offset'      => array('type' => 'integer'),
                            'paged'       => array('type' => 'integer'),
                            'after'       => array('type' => 'string'),
                            'before'      => array('type' => 'string'),
                        ),
                        'required' => array(),
                    ),
                ),
                'wp_get_post' => array(
                    'name' => 'wp_get_post',
                    'description' => 'Get a single post by ID.',
                    'inputSchema' => array(
                        'type' => 'object',
                        'properties' => array(
                            'ID' => array('type' => 'integer'),
                        ),
                        'required' => array('ID'),
                    ),
                ),

                // Posts (mutación)
                'wp_create_post' => array(
                    'name' => 'wp_create_post',
                    'description' => 'Create a post. Requires post_title. Optional: post_content, post_status, post_type, post_excerpt, post_author, meta_input, post_category, tax_input, etc. The parameters should match the standard WordPress wp_insert_post() function.',
                    'inputSchema' => array(
                        'type' => 'object',
                        'properties' => array(
                            'post_title'   => array('type' => 'string'),
                            'post_content' => array('type' => 'string'),
                            'post_status'  => array('type' => 'string'),
                            'post_type'    => array('type' => 'string'),
                            'post_excerpt' => array('type' => 'string'),
                            'post_author'  => array('type' => 'integer'),
                            'meta_input'   => array('type' => 'object'),
                            'post_name'    => array('type' => 'string'),
                            'post_category'=> array('type' => 'array', 'items' => array('type' => 'integer')),
                            'tax_input'    => array('type' => 'object'),
                        ),
                        'required' => array('post_title'),
                    ),
                ),
                'wp_update_post' => array(
                    'name' => 'wp_update_post',
                    'description' => 'Update a post by ID. The "fields" object should use the standard parameters accepted by the WordPress wp_update_post() function, such as post_title, post_content, post_category (array of category IDs), tax_input, etc.',
                    'inputSchema' => array(
                        'type' => 'object',
                        'properties' => array(
                            'ID' => array('type' => 'integer'),
                            'fields' => array('type' => 'object'),
                            'meta_input' => array('type' => 'object'),
                        ),
                        'required' => array('ID'),
                    ),
                ),
                'wp_delete_post' => array(
                    'name' => 'wp_delete_post',
                    'description' => 'Delete a post by ID.',
                    'inputSchema' => array(
                        'type' => 'object',
                        'properties' => array(
                            'ID'    => array('type' => 'integer'),
                            'force' => array('type' => 'boolean'),
                        ),
                        'required' => array('ID'),
                    ),
                ),

                // Comentarios
                'wp_get_comments' => array(
                    'name' => 'wp_get_comments',
                    'description' => 'List comments. Supports post_id, status, search, limit, offset, paged.',
                    'inputSchema' => array(
                        'type' => 'object',
                        'properties' => array(
                            'post_id' => array('type' => 'integer'),
                            'status'  => array('type' => 'string'),
                            'search'  => array('type' => 'string'),
                            'limit'   => array('type' => 'integer'),
                            'offset'  => array('type' => 'integer'),
                            'paged'   => array('type' => 'integer'),
                        ),
                        'required' => array(),
                    ),
                ),
                'wp_create_comment' => array(
                    'name' => 'wp_create_comment',
                    'description' => 'Create a comment. Requires post_id and comment_content.',
                    'inputSchema' => array(
                        'type' => 'object',
                        'properties' => array(
                            'post_id' => array('type' => 'integer'),
                            'comment_content' => array('type' => 'string'),
                            'comment_author' => array('type' => 'string'),
                            'comment_author_email' => array('type' => 'string'),
                            'comment_author_url' => array('type' => 'string'),
                            'comment_approved' => array('type' => 'integer'),
                        ),
                        'required' => array('post_id','comment_content'),
                    ),
                ),
                'wp_update_comment' => array(
                    'name' => 'wp_update_comment',
                    'description' => 'Update a comment by comment_ID with fields object.',
                    'inputSchema' => array(
                        'type' => 'object',
                        'properties' => array(
                            'comment_ID' => array('type' => 'integer'),
                            'fields' => array('type' => 'object'),
                        ),
                        'required' => array('comment_ID'),
                    ),
                ),
                'wp_delete_comment' => array(
                    'name' => 'wp_delete_comment',
                    'description' => 'Delete a comment by comment_ID. Optional force flag.',
                    'inputSchema' => array(
                        'type' => 'object',
                        'properties' => array(
                            'comment_ID' => array('type' => 'integer'),
                            'force' => array('type' => 'boolean'),
                        ),
                        'required' => array('comment_ID'),
                    ),
                ),

                // Usuarios
                'wp_get_users' => array(
                    'name' => 'wp_get_users',
                    'description' => 'Retrieve users (fields: ID, user_login, display_name, roles). If no limit supplied, returns 10. `paged` ignored if `offset` is used.',
                    'inputSchema' => array(
                        'type' => 'object',
                        'properties' => array(
                            'search' => array('type' => 'string'),
                            'role'   => array('type' => 'string'),
                            'limit'  => array('type' => 'integer'),
                            'offset' => array('type' => 'integer'),
                            'paged'  => array('type' => 'integer'),
                        ),
                        'required' => array(),
                    ),
                ),
                // Removed for WordPress.org compliance: wp_create_user, wp_update_user

                // Media
                'wp_get_media' => array(
                    'name' => 'wp_get_media',
                    'description' => 'List media attachments (limit, offset).',
                    'inputSchema' => array(
                        'type' => 'object',
                        'properties' => array(
                            'limit'  => array('type' => 'integer'),
                            'offset' => array('type' => 'integer'),
                        ),
                        'required' => array(),
                    ),
                ),
                'wp_get_media_item' => array(
                    'name' => 'wp_get_media_item',
                    'description' => 'Get media item details by ID.',
                    'inputSchema' => array(
                        'type' => 'object',
                        'properties' => array(
                            'ID' => array('type' => 'integer'),
                        ),
                        'required' => array('ID'),
                    ),
                ),
                'wp_upload_image_from_url' => array(
                    'name' => 'wp_upload_image_from_url',
                    'description' => 'Download an image from a public URL and create a media attachment. Returns attachment ID and URL.',
                    'inputSchema' => array(
                        'type' => 'object',
                        'properties' => array(
                            'url' => array('type' => 'string'),
                        ),
                        'required' => array('url'),
                    ),
                ),
                'wp_upload_image' => array(
                    'name' => 'wp_upload_image',
                    'description' => 'Upload an image from base64 data and create a media attachment. Useful for AI-generated images. Returns attachment ID and URL.',
                    'inputSchema' => array(
                        'type' => 'object',
                        'properties' => array(
                            'image_data' => array('type' => 'string', 'description' => 'Base64 encoded image data'),
                            'filename' => array('type' => 'string', 'description' => 'Filename with extension (e.g., "image.png")'),
                            'alt_text' => array('type' => 'string', 'description' => 'Alt text for the image'),
                            'title' => array('type' => 'string', 'description' => 'Title for the image'),
                            'post_id' => array('type' => 'integer', 'description' => 'Optional post ID to attach the image to'),
                        ),
                        'required' => array('image_data', 'filename'),
                    ),
                ),

                // Plugins / Temas
                'wp_list_plugins' => array(
                    'name' => 'wp_list_plugins',
                    'description' => 'List installed plugins (returns array of {Name, Version}).',
                    'inputSchema' => array(
                        'type' => 'object',
                        'properties' => array(
                            'search' => array('type' => 'string'),
                        ),
                        'required' => array(),
                    ),
                ),
                // Removed tools for WordPress.org compliance (Issues #5 & #6):
                // - wp_activate_plugin (activates plugins)
                // - wp_deactivate_plugin (deactivates plugins)
                // - wp_install_plugin (installs plugins)
                // - wp_install_theme (installs themes)
                // - wp_switch_theme (switches active theme)
                'wp_get_themes' => array(
                    'name' => 'wp_get_themes',
                    'description' => 'List installed themes.',
                    'inputSchema' => array(
                        'type' => 'object',
                        'properties' => (object) array(),
                        'required' => array(),
                    ),
                ),

                // Taxonomías y términos
                'wp_get_taxonomies' => array(
                    'name' => 'wp_get_taxonomies',
                    'description' => 'List registered taxonomies.',
                    'inputSchema' => array(
                        'type' => 'object',
                        'properties' => (object) array(),
                        'required' => array(),
                    ),
                ),
                'wp_get_terms' => array(
                    'name' => 'wp_get_terms',
                    'description' => 'List terms for a taxonomy (taxonomy required).',
                    'inputSchema' => array(
                        'type' => 'object',
                        'properties' => array(
                            'taxonomy' => array('type' => 'string'),
                        ),
                        'required' => array('taxonomy'),
                    ),
                ),
                'wp_create_term' => array(
                    'name' => 'wp_create_term',
                    'description' => 'Create a term in a taxonomy (taxonomy and name required).',
                    'inputSchema' => array(
                        'type' => 'object',
                        'properties' => array(
                            'taxonomy' => array('type' => 'string'),
                            'name'     => array('type' => 'string'),
                        ),
                        'required' => array('taxonomy','name'),
                    ),
                ),
                'wp_delete_term' => array(
                    'name' => 'wp_delete_term',
                    'description' => 'Delete a term by term_id and taxonomy.',
                    'inputSchema' => array(
                        'type' => 'object',
                        'properties' => array(
                            'term_id'  => array('type' => 'integer'),
                            'taxonomy' => array('type' => 'string'),
                        ),
                        'required' => array('term_id','taxonomy'),
                    ),
                ),

                // Menús de navegación
                'wp_get_nav_menus' => array(
                    'name' => 'wp_get_nav_menus',
                    'description' => 'List all navigation menus.',
                    'inputSchema' => array(
                        'type' => 'object',
                        'properties' => (object) array(),
                        'required' => array(),
                    ),
                ),
                'wp_create_nav_menu' => array(
                    'name' => 'wp_create_nav_menu',
                    'description' => 'Create a new navigation menu. Requires menu_name.',
                    'inputSchema' => array(
                        'type' => 'object',
                        'properties' => array(
                            'menu_name' => array('type' => 'string'),
                        ),
                        'required' => array('menu_name'),
                    ),
                ),
                'wp_add_nav_menu_item' => array(
                    'name' => 'wp_add_nav_menu_item',
                    'description' => 'Add an item to a navigation menu. Requires menu_id, menu_item_title, menu_item_type (post_type, custom, taxonomy), menu_item_object (page, post, category, etc.), menu_item_object_id.',
                    'inputSchema' => array(
                        'type' => 'object',
                        'properties' => array(
                            'menu_id' => array('type' => 'integer'),
                            'menu_item_title' => array('type' => 'string'),
                            'menu_item_type' => array('type' => 'string'),
                            'menu_item_object' => array('type' => 'string'),
                            'menu_item_object_id' => array('type' => 'integer'),
                            'menu_item_url' => array('type' => 'string'),
                            'menu_item_parent_id' => array('type' => 'integer'),
                        ),
                        'required' => array('menu_id', 'menu_item_title', 'menu_item_type'),
                    ),
                ),
                'wp_update_nav_menu_item' => array(
                    'name' => 'wp_update_nav_menu_item',
                    'description' => 'Update a navigation menu item. Requires menu_id, menu_item_id, and fields object.',
                    'inputSchema' => array(
                        'type' => 'object',
                        'properties' => array(
                            'menu_id' => array('type' => 'integer'),
                            'menu_item_id' => array('type' => 'integer'),
                            'fields' => array('type' => 'object'),
                        ),
                        'required' => array('menu_id', 'menu_item_id'),
                    ),
                ),
                'wp_delete_nav_menu_item' => array(
                    'name' => 'wp_delete_nav_menu_item',
                    'description' => 'Delete a navigation menu item by menu_item_id.',
                    'inputSchema' => array(
                        'type' => 'object',
                        'properties' => array(
                            'menu_item_id' => array('type' => 'integer'),
                        ),
                        'required' => array('menu_item_id'),
                    ),
                ),
                'wp_delete_nav_menu' => array(
                    'name' => 'wp_delete_nav_menu',
                    'description' => 'Delete a navigation menu by menu_id.',
                    'inputSchema' => array(
                        'type' => 'object',
                        'properties' => array(
                            'menu_id' => array('type' => 'integer'),
                        ),
                        'required' => array('menu_id'),
                    ),
                ),

                // Opciones / Meta (lectura sensible + escritura)
                'wp_get_option' => array(
                    'name' => 'wp_get_option',
                    'description' => 'Get a WordPress option value by name.',
                    'inputSchema' => array(
                        'type' => 'object',
                        'properties' => array(
                            'option' => array('type' => 'string'),
                        ),
                        'required' => array('option'),
                    ),
                ),
                'wp_update_option' => array(
                    'name' => 'wp_update_option',
                    'description' => 'Update a WordPress option.',
                    'inputSchema' => array(
                        'type' => 'object',
                        'properties' => array(
                            'option' => array('type' => 'string'),
                            'value'  => array('type' => 'string'),
                        ),
                        'required' => array('option','value'),
                    ),
                ),
                'wp_delete_option' => array(
                    'name' => 'wp_delete_option',
                    'description' => 'Delete a WordPress option.',
                    'inputSchema' => array(
                        'type' => 'object',
                        'properties' => array(
                            'option' => array('type' => 'string'),
                        ),
                        'required' => array('option'),
                    ),
                ),
                'wp_get_post_meta' => array(
                    'name' => 'wp_get_post_meta',
                    'description' => 'Get post meta (post_id, meta_key, single).',
                    'inputSchema' => array(
                        'type' => 'object',
                        'properties' => array(
                            'post_id'  => array('type' => 'integer'),
                            'meta_key' => array('type' => 'string'),
                            'single'   => array('type' => 'boolean'),
                        ),
                        'required' => array('post_id','meta_key'),
                    ),
                ),
                'wp_update_post_meta' => array(
                    'name' => 'wp_update_post_meta',
                    'description' => 'Update post meta (post_id, meta_key, meta_value).',
                    'inputSchema' => array(
                        'type' => 'object',
                        'properties' => array(
                            'post_id'    => array('type' => 'integer'),
                            'meta_key'   => array('type' => 'string'),
                            'meta_value' => array('type' => 'string'),
                        ),
                        'required' => array('post_id','meta_key','meta_value'),
                    ),
                ),
                'wp_delete_post_meta' => array(
                    'name' => 'wp_delete_post_meta',
                    'description' => 'Delete post meta (post_id, meta_key, meta_value optional).',
                    'inputSchema' => array(
                        'type' => 'object',
                        'properties' => array(
                            'post_id'    => array('type' => 'integer'),
                            'meta_key'   => array('type' => 'string'),
                            'meta_value' => array('type' => 'string'),
                        ),
                        'required' => array('post_id','meta_key'),
                    ),
                ),

                // Búsqueda y red
                'search' => array(
                    'name' => 'search',
                    'description' => 'Simple search across posts (q or query param).',
                    'inputSchema' => array(
                        'type' => 'object',
                        'properties' => array(
                            'q'     => array('type' => 'string'),
                            'limit' => array('type' => 'integer'),
                        ),
                        'required' => array(),
                    ),
                ),
                'fetch' => array(
                    'name' => 'fetch',
                    'description' => 'Fetch a URL using WordPress HTTP API (url required, method optional).',
                    'inputSchema' => array(
                        'type' => 'object',
                        'properties' => array(
                            'url'     => array('type' => 'string'),
                            'method'  => array('type' => 'string'),
                            'headers' => array('type' => 'object'),
                            'body'    => array('type' => 'string'),
                        ),
                        'required' => array('url'),
                    ),
                ),
                
                // Pages
                'wp_get_pages' => array(
                    'name' => 'wp_get_pages',
                    'description' => 'List pages with filters (post_status, search, limit, offset, orderby, order).',
                    'inputSchema' => array(
                        'type' => 'object',
                        'properties' => array(
                            'post_status' => array('type' => 'string'),
                            'search'      => array('type' => 'string'),
                            'limit'       => array('type' => 'integer'),
                            'offset'      => array('type' => 'integer'),
                            'orderby'     => array('type' => 'string'),
                            'order'       => array('type' => 'string'),
                        ),
                        'required' => array(),
                    ),
                ),
                'wp_create_page' => array(
                    'name' => 'wp_create_page',
                    'description' => 'Create a new page (post_title, post_content, post_status, post_author, post_parent, menu_order, meta_input).',
                    'inputSchema' => array(
                        'type' => 'object',
                        'properties' => array(
                            'post_title'   => array('type' => 'string'),
                            'post_content' => array('type' => 'string'),
                            'post_status'  => array('type' => 'string'),
                            'post_author'  => array('type' => 'integer'),
                            'post_parent'  => array('type' => 'integer'),
                            'menu_order'   => array('type' => 'integer'),
                            'meta_input'   => array('type' => 'object'),
                        ),
                        'required' => array('post_title'),
                    ),
                ),
                'wp_update_page' => array(
                    'name' => 'wp_update_page',
                    'description' => 'Update a page by ID (post_title, post_content, post_status, post_author, post_parent, menu_order, meta_input).',
                    'inputSchema' => array(
                        'type' => 'object',
                        'properties' => array(
                            'ID'           => array('type' => 'integer'),
                            'post_title'   => array('type' => 'string'),
                            'post_content' => array('type' => 'string'),
                            'post_status'  => array('type' => 'string'),
                            'post_author'  => array('type' => 'integer'),
                            'post_parent'  => array('type' => 'integer'),
                            'menu_order'   => array('type' => 'integer'),
                            'meta_input'   => array('type' => 'object'),
                        ),
                        'required' => array('ID'),
                    ),
                ),
                'wp_delete_page' => array(
                    'name' => 'wp_delete_page',
                    'description' => 'Delete a page by ID. Pass force=true to skip trash.',
                    'inputSchema' => array(
                        'type' => 'object',
                        'properties' => array(
                            'ID'    => array('type' => 'integer'),
                            'force' => array('type' => 'boolean'),
                        ),
                        'required' => array('ID'),
                    ),
                ),
                
                // Removed for WordPress.org compliance: wp_delete_user
                
                // User Meta
                'wp_get_user_meta' => array(
                    'name' => 'wp_get_user_meta',
                    'description' => 'Get user meta by user_id and optional meta_key. Returns all meta if key not specified.',
                    'inputSchema' => array(
                        'type' => 'object',
                        'properties' => array(
                            'user_id'  => array('type' => 'integer'),
                            'meta_key' => array('type' => 'string'),
                        ),
                        'required' => array('user_id'),
                    ),
                ),
                'wp_update_user_meta' => array(
                    'name' => 'wp_update_user_meta',
                    'description' => 'Update user meta by user_id and meta_key with meta_value.',
                    'inputSchema' => array(
                        'type' => 'object',
                        'properties' => array(
                            'user_id'    => array('type' => 'integer'),
                            'meta_key'   => array('type' => 'string'),
                            'meta_value' => array('type' => 'string'),
                        ),
                        'required' => array('user_id', 'meta_key', 'meta_value'),
                    ),
                ),
                'wp_delete_user_meta' => array(
                    'name' => 'wp_delete_user_meta',
                    'description' => 'Delete user meta by user_id and meta_key.',
                    'inputSchema' => array(
                        'type' => 'object',
                        'properties' => array(
                            'user_id'  => array('type' => 'integer'),
                            'meta_key' => array('type' => 'string'),
                        ),
                        'required' => array('user_id', 'meta_key'),
                    ),
                ),
                
                // Categories
                'wp_get_categories' => array(
                    'name' => 'wp_get_categories',
                    'description' => 'List categories (hide_empty, search, limit).',
                    'inputSchema' => array(
                        'type' => 'object',
                        'properties' => array(
                            'hide_empty' => array('type' => 'boolean'),
                            'search'     => array('type' => 'string'),
                            'limit'      => array('type' => 'integer'),
                        ),
                        'required' => array(),
                    ),
                ),
                'wp_create_category' => array(
                    'name' => 'wp_create_category',
                    'description' => 'Create a category (name required, slug, parent, description optional).',
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
                'wp_update_category' => array(
                    'name' => 'wp_update_category',
                    'description' => 'Update a category by term_id (name, slug, parent, description).',
                    'inputSchema' => array(
                        'type' => 'object',
                        'properties' => array(
                            'term_id'     => array('type' => 'integer'),
                            'name'        => array('type' => 'string'),
                            'slug'        => array('type' => 'string'),
                            'parent'      => array('type' => 'integer'),
                            'description' => array('type' => 'string'),
                        ),
                        'required' => array('term_id'),
                    ),
                ),
                'wp_delete_category' => array(
                    'name' => 'wp_delete_category',
                    'description' => 'Delete a category by term_id.',
                    'inputSchema' => array(
                        'type' => 'object',
                        'properties' => array(
                            'term_id' => array('type' => 'integer'),
                        ),
                        'required' => array('term_id'),
                    ),
                ),
                
                // Tags
                'wp_get_tags' => array(
                    'name' => 'wp_get_tags',
                    'description' => 'List tags (hide_empty, search, limit).',
                    'inputSchema' => array(
                        'type' => 'object',
                        'properties' => array(
                            'hide_empty' => array('type' => 'boolean'),
                            'search'     => array('type' => 'string'),
                            'limit'      => array('type' => 'integer'),
                        ),
                        'required' => array(),
                    ),
                ),
                'wp_create_tag' => array(
                    'name' => 'wp_create_tag',
                    'description' => 'Create a tag (name required, slug, description optional).',
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
                'wp_update_tag' => array(
                    'name' => 'wp_update_tag',
                    'description' => 'Update a tag by term_id (name, slug, description).',
                    'inputSchema' => array(
                        'type' => 'object',
                        'properties' => array(
                            'term_id'     => array('type' => 'integer'),
                            'name'        => array('type' => 'string'),
                            'slug'        => array('type' => 'string'),
                            'description' => array('type' => 'string'),
                        ),
                        'required' => array('term_id'),
                    ),
                ),
                'wp_delete_tag' => array(
                    'name' => 'wp_delete_tag',
                    'description' => 'Delete a tag by term_id.',
                    'inputSchema' => array(
                        'type' => 'object',
                        'properties' => array(
                            'term_id' => array('type' => 'integer'),
                        ),
                        'required' => array('term_id'),
                    ),
                ),
                
                // Media
                'wp_update_media_item' => array(
                    'name' => 'wp_update_media_item',
                    'description' => 'Update media item metadata (ID required, post_title, post_content, post_excerpt).',
                    'inputSchema' => array(
                        'type' => 'object',
                        'properties' => array(
                            'ID'           => array('type' => 'integer'),
                            'post_title'   => array('type' => 'string'),
                            'post_content' => array('type' => 'string'),
                            'post_excerpt' => array('type' => 'string'),
                        ),
                        'required' => array('ID'),
                    ),
                ),
                'wp_delete_media_item' => array(
                    'name' => 'wp_delete_media_item',
                    'description' => 'Delete a media item by ID. Pass force=true to delete permanently.',
                    'inputSchema' => array(
                        'type' => 'object',
                        'properties' => array(
                            'ID'    => array('type' => 'integer'),
                            'force' => array('type' => 'boolean'),
                        ),
                        'required' => array('ID'),
                    ),
                ),
                
                // Menus
                'wp_get_menus' => array(
                    'name' => 'wp_get_menus',
                    'description' => 'List all navigation menus (alias for wp_get_nav_menus).',
                    'inputSchema' => array(
                        'type' => 'object',
                        'properties' => (object) array(),
                        'required' => array(),
                    ),
                ),
                'wp_get_menu' => array(
                    'name' => 'wp_get_menu',
                    'description' => 'Get a specific menu with its items (menu_id or menu_location required).',
                    'inputSchema' => array(
                        'type' => 'object',
                        'properties' => array(
                            'menu_id'       => array('type' => 'integer'),
                            'menu_location' => array('type' => 'string'),
                        ),
                        'required' => array(),
                    ),
                ),
                
                // Settings
                'wp_get_settings' => array(
                    'name' => 'wp_get_settings',
                    'description' => 'Get WordPress settings. Optionally pass "keys" array to get specific options.',
                    'inputSchema' => array(
                        'type' => 'object',
                        'properties' => array(
                            'keys' => array('type' => 'array', 'items' => array('type' => 'string')),
                        ),
                        'required' => array(),
                    ),
                ),
                'wp_update_settings' => array(
                    'name' => 'wp_update_settings',
                    'description' => 'Update WordPress settings. Pass "settings" object with key-value pairs.',
                    'inputSchema' => array(
                        'type' => 'object',
                        'properties' => array(
                            'settings' => array('type' => 'object'),
                        ),
                        'required' => array('settings'),
                    ),
                ),
                
                // Post Revisions
                'wp_get_post_revisions' => array(
                    'name' => 'wp_get_post_revisions',
                    'description' => 'Get revisions for a post by post_id.',
                    'inputSchema' => array(
                        'type' => 'object',
                        'properties' => array(
                            'post_id' => array('type' => 'integer'),
                        ),
                        'required' => array('post_id'),
                    ),
                ),
                'wp_restore_post_revision' => array(
                    'name' => 'wp_restore_post_revision',
                    'description' => 'Restore a post to a specific revision by revision_id.',
                    'inputSchema' => array(
                        'type' => 'object',
                        'properties' => array(
                            'revision_id' => array('type' => 'integer'),
                        ),
                        'required' => array('revision_id'),
                    ),
                ),
                
                // Custom Post Types
                'wp_get_post_types' => array(
                    'name' => 'wp_get_post_types',
                    'description' => 'Get all registered post types with their details (labels, capabilities, public status, etc).',
                    'inputSchema' => array(
                        'type' => 'object',
                        'properties' => array(
                            'public_only' => array('type' => 'boolean'),
                        ),
                        'required' => array(),
                    ),
                ),
                
                // Site Health
                'wp_get_site_health' => array(
                    'name' => 'wp_get_site_health',
                    'description' => 'Get WordPress site health information (version, PHP, database, plugins, themes, debug mode).',
                    'inputSchema' => array(
                        'type' => 'object',
                        'properties' => (object) array(),
                        'required' => array(),
                    ),
                ),
            );

            // Merge WooCommerce tools if available
            if ( class_exists( 'WooCommerce' ) ) {
                if ( class_exists( 'StifliFlexMcp_WC_Products' ) ) {
                    $tools = array_merge( $tools, StifliFlexMcp_WC_Products::getTools() );
                }
                if ( class_exists( 'StifliFlexMcp_WC_Orders' ) ) {
                    $tools = array_merge( $tools, StifliFlexMcp_WC_Orders::getTools() );
                }
                if ( class_exists( 'StifliFlexMcp_WC_Customers' ) ) {
                    $tools = array_merge( $tools, StifliFlexMcp_WC_Customers::getTools() );
                }
                if ( class_exists( 'StifliFlexMcp_WC_Coupons' ) ) {
                    $tools = array_merge( $tools, StifliFlexMcp_WC_Coupons::getTools() );
                }
                if ( class_exists( 'StifliFlexMcp_WC_System' ) ) {
                    $tools = array_merge( $tools, StifliFlexMcp_WC_System::getTools() );
                }
            }

            $this->tools = $tools;
        }
        return $this->tools;
    }

    /**
     * Exportar herramientas como funciones OpenAI/ChatGPT (+metadata)
     */
    public function getOpenAIFunctions() {
        $tools = $this->getToolsList();
        $funcs = array();
        foreach ($tools as $t) {
            $f = array(
                'name' => $t['name'],
                'description' => isset($t['description']) ? $t['description'] : '',
                'parameters' => array(
                    'type' => 'object',
                    'properties' => (isset($t['inputSchema']) ? $t['inputSchema']['properties'] : new stdClass()),
                    'required' => (isset($t['inputSchema']) && isset($t['inputSchema']['required']) ? $t['inputSchema']['required'] : array()),
                ),
                // NUEVO: metadata para controlar confirmaciones en el cliente
                'metadata' => array(
                    'intent' => $t['intent'] ?? 'read',
                    'requires_confirmation' => $t['requires_confirmation'] ?? false,
                    'category' => $t['category'] ?? 'Core',
                ),
            );
            $funcs[] = $f;
        }
        return $funcs;
    }

    /**
     * Validación básica de argumentos
     */
    public function validateArgumentsSchema($schema, $args, & $err = '') {
        $err = '';
        if (!is_array($schema) || empty($schema['type']) || $schema['type'] !== 'object') {
            return true; // sin esquema
        }
        $props = isset($schema['properties']) ? $schema['properties'] : array();
        // required
        if (!empty($schema['required']) && is_array($schema['required'])) {
            foreach ($schema['required'] as $rk) {
                if (!isset($args[$rk])) {
                    $err = 'Missing required parameter: ' . $rk;
                    return false;
                }
            }
        }
        // tipos básicos
        foreach ($props as $k => $p) {
            if (!isset($args[$k])) continue;
            $val = $args[$k];
            if (!isset($p['type'])) continue;
            $type = $p['type'];
            switch ($type) {
                case 'string':
                    if (!is_string($val)) { $err = "Parameter $k must be a string"; return false; }
                    break;
                case 'integer':
                    if (!is_int($val) && !(is_string($val) && ctype_digit($val))) { $err = "Parameter $k must be an integer"; return false; }
                    break;
                case 'boolean':
                    if (!is_bool($val) && !in_array($val, array(true,false,0,1,'0','1'), true)) { $err = "Parameter $k must be boolean"; return false; }
                    break;
                case 'object':
                    if (!is_array($val) && !is_object($val)) { $err = "Parameter $k must be an object"; return false; }
                    break;
                case 'array':
                    if (!is_array($val)) { $err = "Parameter $k must be an array"; return false; }
                    break;
                default:
                    break;
            }
        }
        return true;
    }

    /**
     * Capacidades WP para tools de escritura (lecturas sensibles se chequean en dispatch).
     */
    public function getToolCapability($tool) {
        $map = array(
            // posts
            'wp_create_post' => 'edit_posts',
            'wp_update_post' => 'edit_posts',
            'wp_delete_post' => 'delete_posts',
            // pages
            'wp_create_page' => 'edit_pages',
            'wp_update_page' => 'edit_pages',
            'wp_delete_page' => 'delete_pages',
            // comments
            'wp_create_comment' => 'moderate_comments',
            'wp_update_comment' => 'moderate_comments',
            'wp_delete_comment' => 'moderate_comments',
            // Removed for WordPress.org compliance: wp_create_user, wp_update_user, wp_delete_user
            // user meta
            'wp_get_user_meta' => 'list_users',
            'wp_update_user_meta' => 'edit_users',
            'wp_delete_user_meta' => 'edit_users',
            // post revisions
            'wp_restore_post_revision' => 'edit_posts',
            // site health
            'wp_get_site_health' => 'manage_options',
            // categories
            'wp_create_category' => 'manage_categories',
            'wp_update_category' => 'manage_categories',
            'wp_delete_category' => 'manage_categories',
            // tags
            'wp_create_tag' => 'manage_categories',
            'wp_update_tag' => 'manage_categories',
            'wp_delete_tag' => 'manage_categories',
            // media
            'wp_upload_image_from_url' => 'upload_files',
            'wp_upload_image' => 'upload_files',
            'wp_update_media_item' => 'upload_files',
            'wp_delete_media_item' => 'delete_posts',
            // plugins/themes
            'wp_activate_plugin' => 'activate_plugins',
            'wp_deactivate_plugin' => 'activate_plugins',
            'wp_install_plugin' => 'install_plugins',
            'wp_install_theme' => 'install_themes',
            'wp_switch_theme' => 'switch_themes',
            // options/meta/settings
            'wp_update_option' => 'manage_options',
            'wp_delete_option' => 'manage_options',
            'wp_update_post_meta' => 'manage_options',
            'wp_delete_post_meta' => 'manage_options',
            'wp_get_settings' => 'manage_options',
            'wp_update_settings' => 'manage_options',
            // terms
            'wp_create_term' => 'manage_categories',
            'wp_delete_term' => 'manage_categories',
            // menús
            'wp_create_nav_menu' => 'edit_theme_options',
            'wp_add_nav_menu_item' => 'edit_theme_options',
            'wp_update_nav_menu_item' => 'edit_theme_options',
            'wp_delete_nav_menu_item' => 'edit_theme_options',
            'wp_delete_nav_menu' => 'edit_theme_options',
        );

        // Merge WooCommerce capabilities if available
        if ( class_exists( 'WooCommerce' ) ) {
            if ( class_exists( 'StifliFlexMcp_WC_Products' ) ) {
                $map = array_merge( $map, StifliFlexMcp_WC_Products::getCapabilities() );
            }
            if ( class_exists( 'StifliFlexMcp_WC_Orders' ) ) {
                $map = array_merge( $map, StifliFlexMcp_WC_Orders::getCapabilities() );
            }
            if ( class_exists( 'StifliFlexMcp_WC_Customers' ) ) {
                $map = array_merge( $map, StifliFlexMcp_WC_Customers::getCapabilities() );
            }
            if ( class_exists( 'StifliFlexMcp_WC_Coupons' ) ) {
                $map = array_merge( $map, StifliFlexMcp_WC_Coupons::getCapabilities() );
            }
            if ( class_exists( 'StifliFlexMcp_WC_System' ) ) {
                $map = array_merge( $map, StifliFlexMcp_WC_System::getCapabilities() );
            }
        }

        return isset($map[$tool]) ? $map[$tool] : null;
    }

    public function dispatchTool($tool, $args, $id = null) {
        $r = array('jsonrpc' => '2.0', 'id' => $id);
        $utils = 'StifliFlexMcpUtils';
        $frame = class_exists('StifliFlexMcpFrame') ? StifliFlexMcpFrame::_() : null;
        $addResultText = function(array &$r, string $text) {
            if (!isset($r['result']['content'])) {
                $r['result']['content'] = [];
            }
            $r['result']['content'][] = array('type' => 'text', 'text' => $text);
        };
        $cleanHtml = function($v) { return wp_kses_post( wp_unslash( $v ) ); };
        $postExcerpt = function($p) {
            return wp_trim_words( wp_strip_all_tags( isset($p->post_excerpt) && !empty($p->post_excerpt) ? $p->post_excerpt : $p->post_content ), 55 );
        };

        // Validate args against tool schema (basic) before dispatching
        $tools_map = $this->getTools();
        if (isset($tools_map[$tool]) && !empty($tools_map[$tool]['inputSchema'])) {
            $schema = $tools_map[$tool]['inputSchema'];
            $errMsg = '';
            if (!$this->validateArgumentsSchema($schema, is_array($args) ? $args : array(), $errMsg)) {
                $r['error'] = array('code' => -42602, 'message' => 'Invalid arguments: ' . $errMsg);
                return $r;
            }
        }
        // --- INICIO LÓGICA DE DISPATCH ADAPTADA ---
        // Enforce capability mapping for mutating tools (centralized)
        $required_cap = $this->getToolCapability($tool);
        if (!empty($required_cap) && !current_user_can($required_cap)) {
            return array('jsonrpc' => '2.0', 'id' => $id, 'error' => array('code' => 'permission_denied', 'message' => 'Insufficient permissions to execute ' . $tool . '. Required capability: ' . $required_cap));
        }
        switch ($tool) {
            case 'mcp_ping':
                $pingData = array(
                    'time' => gmdate('Y-m-d H:i:s'),
                    'name' => get_bloginfo('name'),
                );
                $addResultText($r, 'Ping successful: ' . wp_json_encode($pingData, JSON_PRETTY_PRINT));
                break;
            case 'wp_get_posts':
                $q = array(
                    'post_type' => sanitize_key($utils::getArrayValue($args, 'post_type', 'post')),
                    'post_status' => sanitize_key($utils::getArrayValue($args, 'post_status', 'publish')),
                    's' => sanitize_text_field($utils::getArrayValue($args, 'search')),
                    'posts_per_page' => max(1, intval($utils::getArrayValue($args, 'limit', 10, 1))),
                );
                if (isset($args['offset'])) {
                    $q['offset'] = max(0, intval($args['offset']));
                }
                if (isset($args['paged'])) {
                    $q['paged'] = max(1, intval($args['paged']));
                }
                $date = array();
                if (!empty($args['after'])) {
                    $date['after'] = $args['after'];
                }
                if (!empty($args['before'])) {
                    $date['before'] = $args['before'];
                }
                if ($date) {
                    $q['date_query'] = array($date);
                }
                $rows = array();
                foreach (get_posts($q) as $p) {
                    $rows[] = array(
                        'ID' => $p->ID,
                        'post_title' => $p->post_title,
                        'post_status' => $p->post_status,
                        'post_excerpt' => $postExcerpt($p),
                        'permalink' => get_permalink($p),
                    );
                }
                $addResultText($r, wp_json_encode($rows, JSON_PRETTY_PRINT));
                break;
            case 'wp_get_post':
                if (empty($args['ID'])) {
                    $r['error'] = array('code' => -42602, 'message' => 'ID required');
                    break;
                }
                $p = get_post(intval($args['ID']));
                if (!$p) {
                    $r['error'] = array('code' => -42600, 'message' => 'Post not found');
                    break;
                }
                $out = array(
                    'ID' => $p->ID,
                    'post_title' => $p->post_title,
                    'post_status' => $p->post_status,
                    'post_content' => $cleanHtml($p->post_content),
                    'post_excerpt' => $postExcerpt($p),
                    'permalink' => get_permalink($p),
                    'post_date' => $p->post_date,
                    'post_modified' => $p->post_modified,
                );
                $addResultText($r, wp_json_encode($out, JSON_PRETTY_PRINT));
                break;
            case 'wp_create_post':
                if (empty($args['post_title'])) {
                    $r['error'] = array('code' => -42602, 'message' => 'post_title required');
                    break;
                }
                $ins = array(
                    'post_title' => sanitize_text_field($args['post_title']),
                    'post_status' => sanitize_key($utils::getArrayValue($args, 'post_status', 'draft')),
                    'post_type' => sanitize_key($utils::getArrayValue($args, 'post_type', 'post')),
                );
                if (!empty($args['post_content'])) {
                    $ins['post_content'] = $args['post_content'];
                }
                if (!empty($args['post_excerpt'])) {
                    $ins['post_excerpt'] = $cleanHtml($args['post_excerpt']);
                }
                if (!empty($args['post_name'])) {
                    $ins['post_name'] = sanitize_title($args['post_name']);
                }
                if (!empty($args['meta_input']) && is_array($args['meta_input'])) {
                    $ins['meta_input'] = $args['meta_input'];
                }
                $new = wp_insert_post($ins, true);
                if (is_wp_error($new)) {
                    $r['error'] = array('code' => $new->get_error_code(), 'message' => $new->get_error_message());
                } else {
                    if (empty($ins['meta_input']) && !empty($args['meta_input']) && is_array($args['meta_input'])) {
                        foreach ($args['meta_input'] as $k => $v) {
                            update_post_meta($new, sanitize_key($k), maybe_serialize($v));
                        }
                    }
                    $addResultText($r, 'Post created ID ' . $new);
                }
                break;
            case 'wp_update_post':
                if (empty($args['ID'])) {
                    $r['error'] = array('code' => -42602, 'message' => 'ID required');
                    break;
                }
                $c = array('ID' => intval($args['ID']));
                if (!empty($args['fields']) && is_array($args['fields'])) {
                    foreach ($args['fields'] as $k => $v) {
                        $c[$k] = in_array($k, array('post_content', 'post_excerpt'), true) ? $cleanHtml($v) : sanitize_text_field($v);
                    }
                }
                $u = ( count($c) > 1 ) ? wp_update_post($c, true) : $c['ID'];
                if (is_wp_error($u)) {
                    $r['error'] = array('code' => $u->get_error_code(), 'message' => $u->get_error_message());
                    break;
                }
                if (!empty($args['meta_input']) && is_array($args['meta_input'])) {
                    foreach ($args['meta_input'] as $k => $v) {
                        update_post_meta($u, sanitize_key($k), maybe_serialize($v));
                    }
                }
                $addResultText($r, 'Post #' . $u . ' updated');
                break;
            case 'wp_delete_post':
                if (empty($args['ID'])) {
                    $r['error'] = array('code' => -42602, 'message' => 'ID required');
                    break;
                }
                $del = wp_delete_post(intval($args['ID']), !empty($args['force']));
                if ($del) {
                    $addResultText($r, 'Post #' . $args['ID'] . ' deleted');
                } else {
                    $r['error'] = array('code' => -42603, 'message' => 'Deletion failed');
                }
                break;
            
            // Pages (son posts con post_type='page')
            case 'wp_get_pages':
                $pargs = array(
                    'post_type' => 'page',
                    'post_status' => $utils::getArrayValue($args, 'post_status', 'publish'),
                    'numberposts' => max(1, $utils::getArrayValue($args, 'limit', 10, 1)),
                    'orderby' => $utils::getArrayValue($args, 'orderby', 'date'),
                    'order' => $utils::getArrayValue($args, 'order', 'DESC'),
                );
                if (isset($args['search'])) {
                    $pargs['s'] = sanitize_text_field($args['search']);
                }
                if (isset($args['offset'])) {
                    $pargs['offset'] = max(0, intval($args['offset']));
                }
                $list = array();
                foreach (get_posts($pargs) as $p) {
                    $list[] = array(
                        'ID' => $p->ID,
                        'post_title' => $p->post_title,
                        'post_status' => $p->post_status,
                        'post_date' => $p->post_date,
                        'post_modified' => $p->post_modified,
                        'post_author' => $p->post_author,
                        'post_parent' => $p->post_parent,
                        'menu_order' => $p->menu_order,
                    );
                }
                $addResultText($r, wp_json_encode($list, JSON_PRETTY_PRINT));
                break;
            case 'wp_create_page':
                $pdata = array(
                    'post_type' => 'page',
                    'post_title' => $cleanHtml($utils::getArrayValue($args, 'post_title', '')),
                    'post_content' => $cleanHtml($utils::getArrayValue($args, 'post_content', '')),
                    'post_status' => $utils::getArrayValue($args, 'post_status', 'draft'),
                );
                if (!empty($args['post_author'])) {
                    $pdata['post_author'] = intval($args['post_author']);
                }
                if (isset($args['post_parent'])) {
                    $pdata['post_parent'] = intval($args['post_parent']);
                }
                if (isset($args['menu_order'])) {
                    $pdata['menu_order'] = intval($args['menu_order']);
                }
                if (!empty($args['meta_input']) && is_array($args['meta_input'])) {
                    $pdata['meta_input'] = $args['meta_input'];
                }
                $u = wp_insert_post($pdata, true);
                if (is_wp_error($u)) {
                    $r['error'] = array('code' => -42603, 'message' => $u->get_error_message());
                } else {
                    $addResultText($r, 'Page #' . $u . ' created');
                }
                break;
            case 'wp_update_page':
                if (empty($args['ID'])) {
                    $r['error'] = array('code' => -42602, 'message' => 'ID required');
                    break;
                }
                $pdata = array('ID' => intval($args['ID']), 'post_type' => 'page');
                foreach (array('post_title', 'post_content', 'post_status', 'post_author', 'post_parent', 'menu_order') as $k) {
                    if (isset($args[$k])) {
                        $pdata[$k] = in_array($k, array('post_title', 'post_content'), true) ? $cleanHtml($args[$k]) : $args[$k];
                    }
                }
                $u = wp_update_post($pdata, true);
                if (is_wp_error($u)) {
                    $r['error'] = array('code' => -42603, 'message' => $u->get_error_message());
                    break;
                }
                if (!empty($args['meta_input']) && is_array($args['meta_input'])) {
                    foreach ($args['meta_input'] as $k => $v) {
                        update_post_meta($u, sanitize_key($k), maybe_serialize($v));
                    }
                }
                $addResultText($r, 'Page #' . $u . ' updated');
                break;
            case 'wp_delete_page':
                if (empty($args['ID'])) {
                    $r['error'] = array('code' => -42602, 'message' => 'ID required');
                    break;
                }
                $del = wp_delete_post(intval($args['ID']), !empty($args['force']));
                if ($del) {
                    $addResultText($r, 'Page #' . $args['ID'] . ' deleted');
                } else {
                    $r['error'] = array('code' => -42603, 'message' => 'Deletion failed');
                }
                break;
            
            case 'wp_get_comments':
                $cargs = array(
                    'post_id' => $utils::getArrayValue($args, 'post_id', 0, 1),
                    'status' => $utils::getArrayValue($args, 'status', 'approve'),
                    'search' => $utils::getArrayValue($args, 'search'),
                    'number' => max(1, $utils::getArrayValue($args, 'limit', 10, 1)),
                );
                if (isset($args['offset'])) {
                    $cargs['offset'] = max(0, intval($args['offset']));
                }
                if (isset($args['paged'])) {
                    $cargs['paged'] = max(1, intval($args['paged']));
                }
                $list = array();
                foreach (get_comments($cargs) as $c) {
                    $list[] = array(
                        'comment_ID' => $c->comment_ID,
                        'comment_post_ID' => $c->comment_post_ID,
                        'comment_author' => $c->comment_author,
                        'comment_content' => wp_trim_words(wp_strip_all_tags($c->comment_content), 40),
                        'comment_date' => $c->comment_date,
                        'comment_approved' => $c->comment_approved,
                    );
                }
                $addResultText($r, wp_json_encode($list, JSON_PRETTY_PRINT));
                break;
            case 'wp_create_comment':
                if (empty($args['post_id']) || empty($args['comment_content'])) {
                    $r['error'] = array('code' => -42602, 'message' => 'post_id & comment_content required');
                    break;
                }
                $ins = array(
                    'comment_post_ID' => intval($args['post_id']),
                    'comment_content' => $cleanHtml($args['comment_content']),
                    'comment_author' => sanitize_text_field($utils::getArrayValue($args, 'comment_author')),
                    'comment_author_email' => sanitize_email($utils::getArrayValue($args, 'comment_author_email')),
                    'comment_author_url' => esc_url_raw($utils::getArrayValue($args, 'comment_author_url')),
                    'comment_approved' => $utils::getArrayValue($args, 'comment_approved', 1),
                );
                $cid = wp_insert_comment($ins);
                if (is_wp_error($cid)) {
                    $r['error'] = array(
                        'code' => $cid instanceof WP_Error ? $cid->get_error_code() : -1,
                        'message' => $cid instanceof WP_Error ? $cid->get_error_message() : 'Unknown error occurred.'
                    );
                } elseif ($cid === false) {
                    $r['error'] = array(
                        'code' => -1,
                        'message' => 'Unknown error occurred while creating the comment.'
                    );
                } elseif (is_int($cid)) {
                    $addResultText($r, 'Comment created successfully with ID ' . $cid);
                } else {
                    $r['error'] = array(
                        'code' => -1,
                        'message' => 'Unexpected return type from wp_insert_comment.'
                    );
                }
                break;
            case 'wp_update_comment':
                if (empty($args['comment_ID'])) {
                    $r['error'] = array('code' => -42602, 'message' => 'comment_ID required');
                    break;
                }
                $c = array('comment_ID' => intval($args['comment_ID']));
                if (!empty($args['fields']) && is_array($args['fields'])) {
                    foreach ($args['fields'] as $k => $v) {
                        $c[$k] = ( 'comment_content' === $k ) ? $cleanHtml($v) : sanitize_text_field($v);
                    }
                }
                $cid = wp_update_comment($c, true);
                if (is_wp_error($cid)) {
                    $r['error'] = array('code' => $cid->get_error_code(), 'message' => $cid->get_error_message());
                } else {
                    $addResultText($r, 'Comment #' . $cid . ' updated');
                }
                break;
            case 'wp_delete_comment':
                if (empty($args['comment_ID'])) {
                    $r['error'] = array('code' => -42602, 'message' => 'comment_ID required');
                    break;
                }
                $done = wp_delete_comment(intval($args['comment_ID']), !empty($args['force']));
                if ($done) {
                    $addResultText($r, 'Comment #' . $args['comment_ID'] . ' deleted');
                } else {
                    $r['error'] = array('code' => -42603, 'message' => 'Deletion failed');
                }
                break;
            case 'wp_get_users':
                $q = array(
                    'search' => '*' . esc_attr($utils::getArrayValue($args, 'search')) . '*',
                    'role' => $utils::getArrayValue($args, 'role'),
                    'number' => max(1, intval($utils::getArrayValue($args, 'limit', 10, 1))),
                );
                if (isset($args['offset'])) {
                    $q['offset'] = max(0, intval($args['offset']));
                }
                if (isset($args['paged'])) {
                    $q['paged'] = max(1, intval($args['paged']));
                }
                $rows = array();
                foreach (get_users($q) as $u) {
                    $rows[] = array(
                        'ID' => $u->ID,
                        'user_login' => $u->user_login,
                        'display_name' => $u->display_name,
                        'roles' => $u->roles,
                    );
                }
                $addResultText($r, wp_json_encode($rows, JSON_PRETTY_PRINT));
                break;
            // Removed for WordPress.org compliance: wp_create_user, wp_update_user, wp_delete_user
                
            // User Meta
            case 'wp_get_user_meta':
                $user_id = intval($utils::getArrayValue($args, 'user_id', 0));
                if (empty($user_id)) {
                    $r['error'] = array('code' => -42602, 'message' => 'user_id required');
                    break;
                }
                
                $meta_key = $utils::getArrayValue($args, 'meta_key', '');
                
                if (!empty($meta_key)) {
                    $value = get_user_meta($user_id, sanitize_key($meta_key), true);
                    $addResultText($r, 'User meta ' . $meta_key . ': ' . wp_json_encode($value, JSON_PRETTY_PRINT));
                } else {
                    // Get all meta
                    $all_meta = get_user_meta($user_id);
                    $cleaned = array();
                    foreach ($all_meta as $key => $values) {
                        $cleaned[$key] = count($values) === 1 ? $values[0] : $values;
                    }
                    $addResultText($r, 'All user meta for user #' . $user_id . ': ' . wp_json_encode($cleaned, JSON_PRETTY_PRINT));
                }
                break;
                
            case 'wp_update_user_meta':
                $user_id = intval($utils::getArrayValue($args, 'user_id', 0));
                $meta_key = $utils::getArrayValue($args, 'meta_key', '');
                $meta_value = $utils::getArrayValue($args, 'meta_value', '');
                
                if (empty($user_id) || empty($meta_key)) {
                    $r['error'] = array('code' => -42602, 'message' => 'user_id and meta_key required');
                    break;
                }
                
                $updated = update_user_meta($user_id, sanitize_key($meta_key), $meta_value);
                
                if ($updated !== false) {
                    $addResultText($r, 'User meta updated for user #' . $user_id . ', key: ' . $meta_key);
                } else {
                    $r['error'] = array('code' => -42603, 'message' => 'Failed to update user meta');
                }
                break;
                
            case 'wp_delete_user_meta':
                $user_id = intval($utils::getArrayValue($args, 'user_id', 0));
                $meta_key = $utils::getArrayValue($args, 'meta_key', '');
                
                if (empty($user_id) || empty($meta_key)) {
                    $r['error'] = array('code' => -42602, 'message' => 'user_id and meta_key required');
                    break;
                }
                
                $deleted = delete_user_meta($user_id, sanitize_key($meta_key));
                
                if ($deleted) {
                    $addResultText($r, 'User meta deleted for user #' . $user_id . ', key: ' . $meta_key);
                } else {
                    $r['error'] = array('code' => -42603, 'message' => 'Failed to delete user meta');
                }
                break;
                
            case 'wp_list_plugins':
                if (!function_exists('get_plugins')) {
                    require_once ABSPATH . 'wp-admin/includes/plugin.php';
                }
                $all = get_plugins();
                $rows = array();
                foreach ($all as $file => $meta) {
                    $rows[] = array('file' => $file, 'Name' => $meta['Name'] ?? '', 'Version' => $meta['Version'] ?? '', 'active' => is_plugin_active($file));
                }
                $addResultText($r, wp_json_encode($rows, JSON_PRETTY_PRINT));
                break;
            // Removed cases for WordPress.org compliance (Issues #5 & #6):
            // - wp_activate_plugin, wp_deactivate_plugin, wp_install_plugin (Issue #5)
            // - wp_install_theme, wp_switch_theme (Issue #6)
            case 'wp_get_themes':
                $themes = wp_get_themes();
                $out = array();
                foreach ($themes as $slug => $theme) {
                    $out[] = array('slug' => $slug, 'Name' => $theme->get('Name'), 'Version' => $theme->get('Version'));
                }
                $addResultText($r, wp_json_encode($out, JSON_PRETTY_PRINT));
                break;
            case 'wp_get_media':
                $q = array('post_type' => 'attachment', 'posts_per_page' => max(1, intval($utils::getArrayValue($args, 'limit', 20, 1))));
                if (isset($args['offset'])) { $q['offset'] = max(0, intval($args['offset'])); }
                $rows = array();
                foreach (get_posts($q) as $a) {
                    $rows[] = array('ID' => $a->ID, 'post_title' => $a->post_title, 'mime_type' => get_post_mime_type($a), 'url' => wp_get_attachment_url($a->ID));
                }
                $addResultText($r, wp_json_encode($rows, JSON_PRETTY_PRINT));
                break;
            case 'wp_get_media_item':
                if (empty($args['ID'])) { $r['error'] = array('code' => -42602, 'message' => 'ID required'); break; }
                $att = get_post(intval($args['ID']));
                if (!$att || 'attachment' !== $att->post_type) { $r['error'] = array('code' => -42600, 'message' => 'Media not found'); break; }
                $meta = wp_get_attachment_metadata($att->ID);
                $out = array('ID' => $att->ID, 'post_title' => $att->post_title, 'mime_type' => get_post_mime_type($att), 'url' => wp_get_attachment_url($att->ID), 'meta' => $meta);
                $addResultText($r, wp_json_encode($out, JSON_PRETTY_PRINT));
                break;
            case 'wp_upload_image_from_url':
                $url = esc_url_raw($utils::getArrayValue($args, 'url'));
                // Debug logging (remove for production or wrap in WP_DEBUG check)
                // stifli_flex_mcp_log('wp_upload_image_from_url: URL received = ' . $url);
                
                if (!$url) { $r['error'] = array('code' => -42602, 'message' => 'url required'); break; }
                if (!current_user_can('upload_files')) { $r['error'] = array('code' => 'permission_denied', 'message' => 'Insufficient permissions to upload files'); break; }
                
                // Temporarily allow all MIME types for images
                add_filter('upload_mimes', function($mimes) {
                    $mimes['jpg|jpeg|jpe'] = 'image/jpeg';
                    $mimes['png'] = 'image/png';
                    $mimes['gif'] = 'image/gif';
                    $mimes['webp'] = 'image/webp';
                    return $mimes;
                });
                
                stifli_flex_mcp_log('wp_upload_image_from_url: Starting download...');
                if (!function_exists('download_url')) {
                    require_once ABSPATH . 'wp-admin/includes/file.php';
                }
                if (!function_exists('media_handle_sideload')) {
                    require_once ABSPATH . 'wp-admin/includes/media.php';
                    require_once ABSPATH . 'wp-admin/includes/image.php';
                }
                $tmp = download_url($url);
                
                if (is_wp_error($tmp)) { 
                    stifli_flex_mcp_log('wp_upload_image_from_url: Download error = ' . $tmp->get_error_message());
                    $r['error'] = array('code' => 'download_error', 'message' => $tmp->get_error_message()); 
                    break; 
                }
                
                stifli_flex_mcp_log('wp_upload_image_from_url: Downloaded to temp file = ' . $tmp);
                
                // Get file extension from URL or detect from downloaded file
                $file = array();
                $basename = wp_basename($url);
                stifli_flex_mcp_log('wp_upload_image_from_url: Original basename = ' . $basename);
                
                $parsed_url = wp_parse_url($url);
                $path = isset($parsed_url['path']) ? $parsed_url['path'] : '';
                
                // If URL doesn't have a clear extension (e.g., Unsplash URLs), detect from file
                if (!preg_match('/\.(jpg|jpeg|png|gif|webp)$/i', $basename)) {
                    stifli_flex_mcp_log('wp_upload_image_from_url: No extension in URL, detecting MIME type...');
                    
                    // Try to detect MIME type from file content
                    if (function_exists('mime_content_type')) {
                        $mime = mime_content_type($tmp);
                    } else {
                        $finfo = finfo_open(FILEINFO_MIME_TYPE);
                        $mime = finfo_file($finfo, $tmp);
                        finfo_close($finfo);
                    }
                    
                    stifli_flex_mcp_log('wp_upload_image_from_url: Detected MIME type = ' . $mime);
                    
                    $ext = 'jpg'; // default
                    if (strpos($mime, 'png') !== false) $ext = 'png';
                    else if (strpos($mime, 'gif') !== false) $ext = 'gif';
                    else if (strpos($mime, 'webp') !== false) $ext = 'webp';
                    
                    $basename = 'image-' . time() . '.' . $ext;
                    stifli_flex_mcp_log('wp_upload_image_from_url: New basename = ' . $basename);
                }
                
                $file['name'] = $basename;
                $file['tmp_name'] = $tmp;
                
                // Force proper MIME type
                $file_info = wp_check_filetype($basename);
                $file['type'] = $file_info['type'];
                
                $fileLog = wp_json_encode($file);
                if (false === $fileLog) {
                    $fileLog = '[unserializable]';
                }
                stifli_flex_mcp_log('wp_upload_image_from_url: File array = ' . $fileLog);
                stifli_flex_mcp_log('wp_upload_image_from_url: Calling media_handle_sideload...');
                
                $att_id = media_handle_sideload($file, 0);
                
                if (is_wp_error($att_id)) { 
                    stifli_flex_mcp_log('wp_upload_image_from_url: Sideload error = ' . $att_id->get_error_message());
                    $errorDataLog = wp_json_encode($att_id->get_error_data());
                    if (false === $errorDataLog) {
                        $errorDataLog = '[unserializable]';
                    }
                    stifli_flex_mcp_log('wp_upload_image_from_url: Sideload error data = ' . $errorDataLog);
                    @wp_delete_file($file['tmp_name']); 
                    $r['error'] = array('code' => 'sideload_error', 'message' => $att_id->get_error_message()); 
                    break; 
                }
                
                stifli_flex_mcp_log('wp_upload_image_from_url: Success! Attachment ID = ' . $att_id);
                
                $att_url = wp_get_attachment_url($att_id);
                
                // Set alt text and title if provided
                $alt_text = sanitize_text_field($utils::getArrayValue($args, 'alt_text', ''));
                $title = sanitize_text_field($utils::getArrayValue($args, 'title', ''));
                if ($alt_text) update_post_meta($att_id, '_wp_attachment_image_alt', $alt_text);
                if ($title) wp_update_post(array('ID' => $att_id, 'post_title' => $title));
                
                $addResultText($r, 'Image uploaded successfully. Attachment ID: ' . $att_id . ', URL: ' . $att_url);
                break;
            case 'wp_upload_image':
                $image_data = $utils::getArrayValue($args, 'image_data');
                $filename = sanitize_file_name($utils::getArrayValue($args, 'filename', 'image.png'));
                $post_id = intval($utils::getArrayValue($args, 'post_id', 0));
                
                if (!$image_data) { $r['error'] = array('code' => -42602, 'message' => 'image_data required'); break; }
                if (!current_user_can('upload_files')) { $r['error'] = array('code' => 'permission_denied', 'message' => 'Insufficient permissions to upload files'); break; }
                
                // Decode base64 (remove data:image/png;base64, prefix if present)
                $image_data = preg_replace('/^data:image\/\w+;base64,/', '', $image_data);
                $decoded = base64_decode($image_data, true);
                
                if ($decoded === false) { $r['error'] = array('code' => -42602, 'message' => 'Invalid base64 data'); break; }
                
                // Save to temp file
                if (!function_exists('wp_upload_dir')) {
                    require_once ABSPATH . 'wp-admin/includes/file.php';
                }
                if (!function_exists('media_handle_sideload')) {
                    require_once ABSPATH . 'wp-admin/includes/media.php';
                    require_once ABSPATH . 'wp-admin/includes/image.php';
                }
                $upload_dir = wp_upload_dir();
                $temp_file = $upload_dir['path'] . '/' . wp_unique_filename($upload_dir['path'], $filename);
                
                if (file_put_contents($temp_file, $decoded) === false) {
                    $r['error'] = array('code' => 'write_error', 'message' => 'Failed to write file');
                    break;
                }
                
                // Create attachment
                $file_array = array(
                    'name' => $filename,
                    'tmp_name' => $temp_file,
                );
                
                $att_id = media_handle_sideload($file_array, $post_id);
                
                if (is_wp_error($att_id)) {
                    @wp_delete_file($temp_file);
                    $r['error'] = array('code' => 'upload_error', 'message' => $att_id->get_error_message());
                    break;
                }
                
                // Set alt text and title if provided
                $alt_text = sanitize_text_field($utils::getArrayValue($args, 'alt_text', ''));
                $title = sanitize_text_field($utils::getArrayValue($args, 'title', ''));
                if ($alt_text) update_post_meta($att_id, '_wp_attachment_image_alt', $alt_text);
                if ($title) wp_update_post(array('ID' => $att_id, 'post_title' => $title));
                
                $att_url = wp_get_attachment_url($att_id);
                $addResultText($r, 'Image uploaded successfully. Attachment ID: ' . $att_id . ', URL: ' . $att_url);
                break;
            case 'wp_update_media_item':
                if (empty($args['ID'])) { $r['error'] = array('code' => -42602, 'message' => 'ID required'); break; }
                $att = get_post(intval($args['ID']));
                if (!$att || 'attachment' !== $att->post_type) { $r['error'] = array('code' => -42600, 'message' => 'Media not found'); break; }
                $upd = array('ID' => intval($args['ID']));
                if (isset($args['post_title'])) { $upd['post_title'] = sanitize_text_field($args['post_title']); }
                if (isset($args['post_content'])) { $upd['post_content'] = sanitize_textarea_field($args['post_content']); }
                if (isset($args['post_excerpt'])) { $upd['post_excerpt'] = sanitize_textarea_field($args['post_excerpt']); }
                $result = wp_update_post($upd, true);
                if (is_wp_error($result)) { $r['error'] = array('code' => $result->get_error_code(), 'message' => $result->get_error_message()); } else { $addResultText($r, 'Media item #' . $args['ID'] . ' updated'); }
                break;
            case 'wp_delete_media_item':
                if (empty($args['ID'])) { $r['error'] = array('code' => -42602, 'message' => 'ID required'); break; }
                $att = get_post(intval($args['ID']));
                if (!$att || 'attachment' !== $att->post_type) { $r['error'] = array('code' => -42600, 'message' => 'Media not found'); break; }
                $force = isset($args['force']) ? (bool)$args['force'] : false;
                $deleted = wp_delete_attachment(intval($args['ID']), $force);
                if ($deleted) { $addResultText($r, 'Media item #' . $args['ID'] . ' deleted'); } else { $r['error'] = array('code' => -42603, 'message' => 'Media deletion failed'); }
                break;
            case 'wp_get_taxonomies':
                $tax = get_taxonomies(array(), 'objects');
                $out = array();
                foreach ($tax as $k => $o) { $out[] = array('name' => $k, 'label' => $o->label); }
                $addResultText($r, wp_json_encode($out, JSON_PRETTY_PRINT));
                break;
            case 'wp_get_terms':
                $taxonomy = sanitize_text_field($utils::getArrayValue($args, 'taxonomy'));
                if (!$taxonomy) { $r['error'] = array('code' => -42602, 'message' => 'taxonomy required'); break; }
                $terms = get_terms(array('taxonomy' => $taxonomy, 'hide_empty' => false));
                $out = array();
                foreach ($terms as $t) { $out[] = array('term_id' => $t->term_id, 'name' => $t->name, 'slug' => $t->slug, 'count' => $t->count); }
                $addResultText($r, wp_json_encode($out, JSON_PRETTY_PRINT));
                break;
            case 'wp_create_term':
                $taxonomy = sanitize_text_field($utils::getArrayValue($args, 'taxonomy'));
                $name = sanitize_text_field($utils::getArrayValue($args, 'name'));
                if (!$taxonomy || !$name) { $r['error'] = array('code' => -42602, 'message' => 'taxonomy & name required'); break; }
                $res = wp_insert_term($name, $taxonomy);
                if (is_wp_error($res)) { $r['error'] = array('code' => $res->get_error_code(), 'message' => $res->get_error_message()); } else { $addResultText($r, 'Term created: ' . json_encode($res)); }
                break;
            case 'wp_delete_term':
                $term_id = intval($utils::getArrayValue($args, 'term_id'));
                $taxonomy = sanitize_text_field($utils::getArrayValue($args, 'taxonomy'));
                if (!$term_id || !$taxonomy) { $r['error'] = array('code' => -42602, 'message' => 'term_id & taxonomy required'); break; }
                $done = wp_delete_term($term_id, $taxonomy);
                if (is_wp_error($done)) { $r['error'] = array('code' => $done->get_error_code(), 'message' => $done->get_error_message()); } else { $addResultText($r, 'Term deleted'); }
                break;
            
            // Categories (son terms con taxonomy='category')
            case 'wp_get_categories':
                $cargs = array(
                    'taxonomy' => 'category',
                    'hide_empty' => isset($args['hide_empty']) ? (bool)$args['hide_empty'] : false,
                    'number' => max(1, $utils::getArrayValue($args, 'limit', 100, 1)),
                );
                if (isset($args['search'])) {
                    $cargs['search'] = sanitize_text_field($args['search']);
                }
                $cats = get_terms($cargs);
                if (is_wp_error($cats)) { $r['error'] = array('code' => $cats->get_error_code(), 'message' => $cats->get_error_message()); break; }
                $list = array();
                foreach ($cats as $cat) {
                    $list[] = array('term_id' => $cat->term_id, 'name' => $cat->name, 'slug' => $cat->slug, 'count' => $cat->count, 'parent' => $cat->parent);
                }
                $addResultText($r, wp_json_encode($list, JSON_PRETTY_PRINT));
                break;
            case 'wp_create_category':
                if (empty($args['name'])) { $r['error'] = array('code' => -42602, 'message' => 'name required'); break; }
                $cargs = array('name' => sanitize_text_field($args['name']));
                if (isset($args['slug'])) { $cargs['slug'] = sanitize_title($args['slug']); }
                if (isset($args['parent'])) { $cargs['parent'] = intval($args['parent']); }
                if (isset($args['description'])) { $cargs['description'] = sanitize_textarea_field($args['description']); }
                $result = wp_insert_term($cargs['name'], 'category', $cargs);
                if (is_wp_error($result)) { $r['error'] = array('code' => $result->get_error_code(), 'message' => $result->get_error_message()); } else { $addResultText($r, 'Category created with ID ' . $result['term_id']); }
                break;
            case 'wp_update_category':
                if (empty($args['term_id'])) { $r['error'] = array('code' => -42602, 'message' => 'term_id required'); break; }
                $cargs = array();
                if (isset($args['name'])) { $cargs['name'] = sanitize_text_field($args['name']); }
                if (isset($args['slug'])) { $cargs['slug'] = sanitize_title($args['slug']); }
                if (isset($args['parent'])) { $cargs['parent'] = intval($args['parent']); }
                if (isset($args['description'])) { $cargs['description'] = sanitize_textarea_field($args['description']); }
                $result = wp_update_term(intval($args['term_id']), 'category', $cargs);
                if (is_wp_error($result)) { $r['error'] = array('code' => $result->get_error_code(), 'message' => $result->get_error_message()); } else { $addResultText($r, 'Category updated'); }
                break;
            case 'wp_delete_category':
                if (empty($args['term_id'])) { $r['error'] = array('code' => -42602, 'message' => 'term_id required'); break; }
                $done = wp_delete_term(intval($args['term_id']), 'category');
                if (is_wp_error($done)) { $r['error'] = array('code' => $done->get_error_code(), 'message' => $done->get_error_message()); } else { $addResultText($r, 'Category deleted'); }
                break;
            
            // Tags (son terms con taxonomy='post_tag')
            case 'wp_get_tags':
                $targs = array(
                    'taxonomy' => 'post_tag',
                    'hide_empty' => isset($args['hide_empty']) ? (bool)$args['hide_empty'] : false,
                    'number' => max(1, $utils::getArrayValue($args, 'limit', 100, 1)),
                );
                if (isset($args['search'])) {
                    $targs['search'] = sanitize_text_field($args['search']);
                }
                $tags = get_terms($targs);
                if (is_wp_error($tags)) { $r['error'] = array('code' => $tags->get_error_code(), 'message' => $tags->get_error_message()); break; }
                $list = array();
                foreach ($tags as $tag) {
                    $list[] = array('term_id' => $tag->term_id, 'name' => $tag->name, 'slug' => $tag->slug, 'count' => $tag->count);
                }
                $addResultText($r, wp_json_encode($list, JSON_PRETTY_PRINT));
                break;
            case 'wp_create_tag':
                if (empty($args['name'])) { $r['error'] = array('code' => -42602, 'message' => 'name required'); break; }
                $targs = array('name' => sanitize_text_field($args['name']));
                if (isset($args['slug'])) { $targs['slug'] = sanitize_title($args['slug']); }
                if (isset($args['description'])) { $targs['description'] = sanitize_textarea_field($args['description']); }
                $result = wp_insert_term($targs['name'], 'post_tag', $targs);
                if (is_wp_error($result)) { $r['error'] = array('code' => $result->get_error_code(), 'message' => $result->get_error_message()); } else { $addResultText($r, 'Tag created with ID ' . $result['term_id']); }
                break;
            case 'wp_update_tag':
                if (empty($args['term_id'])) { $r['error'] = array('code' => -42602, 'message' => 'term_id required'); break; }
                $targs = array();
                if (isset($args['name'])) { $targs['name'] = sanitize_text_field($args['name']); }
                if (isset($args['slug'])) { $targs['slug'] = sanitize_title($args['slug']); }
                if (isset($args['description'])) { $targs['description'] = sanitize_textarea_field($args['description']); }
                $result = wp_update_term(intval($args['term_id']), 'post_tag', $targs);
                if (is_wp_error($result)) { $r['error'] = array('code' => $result->get_error_code(), 'message' => $result->get_error_message()); } else { $addResultText($r, 'Tag updated'); }
                break;
            case 'wp_delete_tag':
                if (empty($args['term_id'])) { $r['error'] = array('code' => -42602, 'message' => 'term_id required'); break; }
                $done = wp_delete_term(intval($args['term_id']), 'post_tag');
                if (is_wp_error($done)) { $r['error'] = array('code' => $done->get_error_code(), 'message' => $done->get_error_message()); } else { $addResultText($r, 'Tag deleted'); }
                break;
            
            case 'wp_get_nav_menus':
            case 'wp_get_menus':  // Alias
                $menus = wp_get_nav_menus();
                $out = array();
                foreach ($menus as $menu) {
                    $out[] = array('term_id' => $menu->term_id, 'name' => $menu->name, 'slug' => $menu->slug);
                }
                $addResultText($r, wp_json_encode($out, JSON_PRETTY_PRINT));
                break;
            case 'wp_get_menu':
                $menu_id = isset($args['menu_id']) ? intval($args['menu_id']) : 0;
                $menu_location = isset($args['menu_location']) ? sanitize_text_field($args['menu_location']) : '';
                
                if ($menu_location) {
                    $locations = get_nav_menu_locations();
                    $menu_id = isset($locations[$menu_location]) ? $locations[$menu_location] : 0;
                }
                
                if (!$menu_id) { $r['error'] = array('code' => -42602, 'message' => 'menu_id or menu_location required'); break; }
                
                $menu = wp_get_nav_menu_object($menu_id);
                if (!$menu) { $r['error'] = array('code' => -42600, 'message' => 'Menu not found'); break; }
                
                $items = wp_get_nav_menu_items($menu_id);
                $menu_items = array();
                if ($items) {
                    foreach ($items as $item) {
                        $menu_items[] = array(
                            'ID' => $item->ID,
                            'title' => $item->title,
                            'url' => $item->url,
                            'menu_order' => $item->menu_order,
                            'parent' => $item->menu_item_parent,
                            'type' => $item->type,
                            'object' => $item->object,
                            'object_id' => $item->object_id,
                        );
                    }
                }
                
                $out = array('term_id' => $menu->term_id, 'name' => $menu->name, 'slug' => $menu->slug, 'items' => $menu_items);
                $addResultText($r, wp_json_encode($out, JSON_PRETTY_PRINT));
                break;
            case 'wp_create_nav_menu':
                if (empty($args['menu_name'])) {
                    $r['error'] = array('code' => -42602, 'message' => 'menu_name required');
                    break;
                }
                $menu_id = wp_create_nav_menu(sanitize_text_field($args['menu_name']));
                if (is_wp_error($menu_id)) {
                    $r['error'] = array('code' => $menu_id->get_error_code(), 'message' => $menu_id->get_error_message());
                } else {
                    $addResultText($r, 'Navigation menu created with ID ' . $menu_id);
                }
                break;
            case 'wp_add_nav_menu_item':
                if (empty($args['menu_id']) || empty($args['menu_item_title']) || empty($args['menu_item_type'])) {
                    $r['error'] = array('code' => -42602, 'message' => 'menu_id, menu_item_title, menu_item_type required');
                    break;
                }
                $item = array(
                    'menu-item-title' => sanitize_text_field($args['menu_item_title']),
                    'menu-item-type' => sanitize_key($args['menu_item_type']),
                    'menu-item-object' => isset($args['menu_item_object']) ? sanitize_key($args['menu_item_object']) : '',
                    'menu-item-object-id' => isset($args['menu_item_object_id']) ? intval($args['menu_item_object_id']) : 0,
                    'menu-item-url' => isset($args['menu_item_url']) ? esc_url_raw($args['menu_item_url']) : '',
                    'menu-item-parent-id' => isset($args['menu_item_parent_id']) ? intval($args['menu_item_parent_id']) : 0,
                    'menu-item-status' => 'publish',
                );
                $item_id = wp_update_nav_menu_item(intval($args['menu_id']), 0, $item);
                if (is_wp_error($item_id)) {
                    $r['error'] = array('code' => $item_id->get_error_code(), 'message' => $item_id->get_error_message());
                } else {
                    $addResultText($r, 'Menu item added with ID ' . $item_id . ' to menu ' . $args['menu_id']);
                }
                break;
            case 'wp_update_nav_menu_item':
                if (empty($args['menu_id']) || empty($args['menu_item_id'])) {
                    $r['error'] = array('code' => -42602, 'message' => 'menu_id & menu_item_id required');
                    break;
                }
                $item = array();
                if (!empty($args['fields']) && is_array($args['fields'])) {
                    foreach ($args['fields'] as $k => $v) {
                        $item['menu-item-' . $k] = sanitize_text_field($v);
                    }
                }
                $item_id = wp_update_nav_menu_item(intval($args['menu_id']), intval($args['menu_item_id']), $item);
                if (is_wp_error($item_id)) {
                    $r['error'] = array('code' => $item_id->get_error_code(), 'message' => $item_id->get_error_message());
                } else {
                    $addResultText($r, 'Menu item #' . $args['menu_item_id'] . ' updated in menu ' . $args['menu_id']);
                }
                break;
            case 'wp_delete_nav_menu_item':
                if (empty($args['menu_item_id'])) {
                    $r['error'] = array('code' => -42602, 'message' => 'menu_item_id required');
                    break;
                }
                $deleted = wp_delete_post(intval($args['menu_item_id']), true);
                if ($deleted) {
                    $addResultText($r, 'Menu item #' . $args['menu_item_id'] . ' deleted');
                } else {
                    $r['error'] = array('code' => -42603, 'message' => 'Deletion failed');
                }
                break;
            case 'wp_delete_nav_menu':
                if (empty($args['menu_id'])) {
                    $r['error'] = array('code' => -42602, 'message' => 'menu_id required');
                    break;
                }
                $deleted = wp_delete_nav_menu(intval($args['menu_id']));
                if (is_wp_error($deleted)) {
                    $r['error'] = array('code' => $deleted->get_error_code(), 'message' => $deleted->get_error_message());
                } else {
                    $addResultText($r, 'Navigation menu #' . $args['menu_id'] . ' deleted');
                }
                break;
            case 'search':
                $s = sanitize_text_field($utils::getArrayValue($args, 'q', $utils::getArrayValue($args, 'query', '')));
                $limit = max(1, intval($utils::getArrayValue($args, 'limit', 10, 1)));
                $q = new WP_Query(array('s' => $s, 'posts_per_page' => $limit));
                $out = array();
                foreach ($q->posts as $p) { $out[] = array('ID' => $p->ID, 'post_title' => $p->post_title, 'excerpt' => $postExcerpt($p), 'permalink' => get_permalink($p)); }
                $addResultText($r, wp_json_encode($out, JSON_PRETTY_PRINT));
                break;
            case 'fetch':
                $url = esc_url_raw($utils::getArrayValue($args, 'url'));
                if (!$url) { $r['error'] = array('code' => -42602, 'message' => 'url required'); break; }
                $method = strtoupper($utils::getArrayValue($args, 'method', 'GET'));
                $opts = array();
                if (!empty($args['headers']) && is_array($args['headers'])) { $opts['headers'] = $args['headers']; }
                if (!empty($args['body'])) { $opts['body'] = $args['body']; }
                if ('GET' === $method) { $resp = wp_remote_get($url, $opts); } else { $resp = wp_remote_request($url, array_merge($opts, array('method' => $method))); }
                if (is_wp_error($resp)) { $r['error'] = array('code' => 'fetch_error', 'message' => $resp->get_error_message()); break; }
                $code = wp_remote_retrieve_response_code($resp);
                $body = wp_remote_retrieve_body($resp);
                $maxlen = 2000;
                $body_short = (strlen($body) > $maxlen) ? substr($body, 0, $maxlen) . "... [truncated]" : $body;
                $addResultText($r, "Fetch status: $code\n" . $body_short);
                break;
            case 'wp_get_post_meta':
                if (!current_user_can('manage_options')) {
                    $r['error'] = array('code' => 'permission_denied', 'message' => 'No tienes permisos para manipular meta.');
                    break;
                }
                $post_id = isset($args['post_id']) ? intval($args['post_id']) : 0;
                $meta_key = isset($args['meta_key']) ? sanitize_text_field($args['meta_key']) : '';
                if (!$post_id || !$meta_key) {
                    $r['error'] = array('code' => 'invalid_params', 'message' => 'Faltan parámetros.');
                    break;
                }
                $single = isset($args['single']) ? (bool)$args['single'] : true;
                $value = get_post_meta($post_id, $meta_key, $single);
                $metaValueLog = wp_json_encode($value, JSON_PRETTY_PRINT);
                if (false === $metaValueLog) {
                    $metaValueLog = '[unserializable]';
                }
                $addResultText($r, 'Valor de meta (' . $meta_key . ') para post ' . $post_id . ': ' . $metaValueLog);
                break;
            case 'wp_update_post_meta':
                if (!current_user_can('manage_options')) {
                    $r['error'] = array('code' => 'permission_denied', 'message' => 'No tienes permisos para manipular meta.');
                    break;
                }
                $post_id = isset($args['post_id']) ? intval($args['post_id']) : 0;
                $meta_key = isset($args['meta_key']) ? sanitize_text_field($args['meta_key']) : '';
                $meta_value = isset($args['meta_value']) ? maybe_serialize($args['meta_value']) : null;
                if (!$post_id || !$meta_key) {
                    $r['error'] = array('code' => 'invalid_params', 'message' => 'Faltan parámetros.');
                    break;
                }
                $updated = update_post_meta($post_id, $meta_key, $meta_value);
                if ($updated) {
                    $addResultText($r, 'Meta creado/actualizado para post ' . $post_id . ' (' . $meta_key . ')');
                } else {
                    $addResultText($r, 'No se pudo crear/actualizar el metadato para post ' . $post_id . ' (' . $meta_key . ')');
                }
                break;
            case 'wp_delete_post_meta':
                if (!current_user_can('manage_options')) {
                    $r['error'] = array('code' => 'permission_denied', 'message' => 'No tienes permisos para manipular meta.');
                    break;
                }
                $post_id = isset($args['post_id']) ? intval($args['post_id']) : 0;
                $meta_key = isset($args['meta_key']) ? sanitize_text_field($args['meta_key']) : '';
                $meta_value = isset($args['meta_value']) ? $args['meta_value'] : null;
                if (!$post_id || !$meta_key) {
                    $r['error'] = array('code' => 'invalid_params', 'message' => 'Faltan parámetros.');
                    break;
                }
                $deleted = delete_post_meta($post_id, $meta_key, $meta_value);
                if ($deleted) {
                    $addResultText($r, 'Metadato (' . $meta_key . ') eliminado para post ' . $post_id);
                } else {
                    $addResultText($r, 'No se eliminó el metadato (' . $meta_key . ') para post ' . $post_id);
                }
                break;
            case 'wp_get_option':
                if (!current_user_can('manage_options')) {
                    $r['error'] = array('code' => 'permission_denied', 'message' => 'No tienes permisos para manipular opciones.');
                    break;
                }
                $option = isset($args['option']) ? sanitize_text_field($args['option']) : '';
                if (!$option) {
                    $r['error'] = array('code' => 'invalid_params', 'message' => 'Falta el parámetro option.');
                    break;
                }
                $val = get_option($option);
                $optionValueLog = wp_json_encode($val, JSON_PRETTY_PRINT);
                if (false === $optionValueLog) {
                    $optionValueLog = '[unserializable]';
                }
                $addResultText($r, 'Valor de opción (' . $option . '): ' . $optionValueLog);
                break;
            case 'wp_update_option':
                if (!current_user_can('manage_options')) {
                    $r['error'] = array('code' => 'permission_denied', 'message' => 'No tienes permisos para manipular opciones.');
                    break;
                }
                $option = isset($args['option']) ? sanitize_text_field($args['option']) : '';
                $value = isset($args['value']) ? $args['value'] : null;
                if (!$option) {
                    $r['error'] = array('code' => 'invalid_params', 'message' => 'Falta el parámetro option.');
                    break;
                }
                $old_val = get_option($option, null);
                $updated = update_option($option, $value);
                if ($updated) {
                    $addResultText($r, 'Opción (' . $option . ') actualizada correctamente.');
                } else if ($old_val === $value) {
                    $addResultText($r, 'La opción (' . $option . ') ya tenía ese valor, no se modificó.');
                } else {
                    $addResultText($r, 'No se pudo actualizar la opción (' . $option . ').');
                }
                break;
            case 'wp_delete_option':
                if (!current_user_can('manage_options')) {
                    $r['error'] = array('code' => 'permission_denied', 'message' => 'No tienes permisos para manipular opciones.');
                    break;
                }
                $option = isset($args['option']) ? sanitize_text_field($args['option']) : '';
                if (!$option) {
                    $r['error'] = array('code' => 'invalid_params', 'message' => 'Falta el parámetro option.');
                    break;
                }
                $deleted = delete_option($option);
                if ($deleted) {
                    $addResultText($r, 'Opción (' . $option . ') eliminada');
                } else {
                    $addResultText($r, 'No se eliminó la opción (' . $option . ')');
                }
                break;
            case 'wp_get_settings':
                if (!current_user_can('manage_options')) {
                    $r['error'] = array('code' => 'permission_denied', 'message' => 'No tienes permisos para leer configuración.');
                    break;
                }
                $keys = isset($args['keys']) && is_array($args['keys']) ? $args['keys'] : array();
                if (empty($keys)) {
                    // Return common settings if no keys specified
                    $keys = array('blogname', 'blogdescription', 'siteurl', 'home', 'admin_email', 'users_can_register', 'default_role', 'timezone_string', 'date_format', 'time_format', 'posts_per_page', 'comments_per_page');
                }
                $settings = array();
                foreach ($keys as $key) {
                    $settings[$key] = get_option(sanitize_text_field($key));
                }
                $addResultText($r, wp_json_encode($settings, JSON_PRETTY_PRINT));
                break;
            case 'wp_update_settings':
                if (!current_user_can('manage_options')) {
                    $r['error'] = array('code' => 'permission_denied', 'message' => 'No tienes permisos para actualizar configuración.');
                    break;
                }
                $settings = isset($args['settings']) && is_array($args['settings']) ? $args['settings'] : array();
                if (empty($settings)) {
                    $r['error'] = array('code' => 'invalid_params', 'message' => 'Falta el parámetro settings (debe ser un objeto con pares clave-valor).');
                    break;
                }
                $updated = array();
                foreach ($settings as $key => $value) {
                    $key = sanitize_text_field($key);
                    $result = update_option($key, $value);
                    $updated[$key] = $result;
                }
                $addResultText($r, 'Configuración actualizada: ' . wp_json_encode($updated, JSON_PRETTY_PRINT));
                break;
                
            // Post Revisions
            case 'wp_get_post_revisions':
                $post_id = intval($utils::getArrayValue($args, 'post_id', 0));
                if (empty($post_id)) {
                    $r['error'] = array('code' => -42602, 'message' => 'post_id required');
                    break;
                }
                
                $revisions = wp_get_post_revisions($post_id);
                $result = array();
                
                foreach ($revisions as $revision) {
                    $result[] = array(
                        'id' => $revision->ID,
                        'post_author' => $revision->post_author,
                        'post_date' => $revision->post_date,
                        'post_title' => $revision->post_title,
                        'post_modified' => $revision->post_modified,
                    );
                }
                
                $addResultText($r, 'Found ' . count($result) . ' revisions: ' . wp_json_encode($result, JSON_PRETTY_PRINT));
                break;
                
            case 'wp_restore_post_revision':
                $revision_id = intval($utils::getArrayValue($args, 'revision_id', 0));
                if (empty($revision_id)) {
                    $r['error'] = array('code' => -42602, 'message' => 'revision_id required');
                    break;
                }
                
                $restored = wp_restore_post_revision($revision_id);
                
                if ($restored) {
                    $addResultText($r, 'Post restored to revision #' . $revision_id . ', restored post ID: ' . $restored);
                } else {
                    $r['error'] = array('code' => -42603, 'message' => 'Failed to restore revision');
                }
                break;
                
            // Custom Post Types
            case 'wp_get_post_types':
                $public_only = (bool) $utils::getArrayValue($args, 'public_only', false);
                
                $args_query = array();
                if ($public_only) {
                    $args_query['public'] = true;
                }
                
                $post_types = get_post_types($args_query, 'objects');
                $result = array();
                
                foreach ($post_types as $post_type) {
                    $result[] = array(
                        'name' => $post_type->name,
                        'label' => $post_type->label,
                        'labels' => (array) $post_type->labels,
                        'public' => $post_type->public,
                        'hierarchical' => $post_type->hierarchical,
                        'has_archive' => $post_type->has_archive,
                        'supports' => get_all_post_type_supports($post_type->name),
                        'taxonomies' => get_object_taxonomies($post_type->name),
                        'rest_enabled' => $post_type->show_in_rest,
                    );
                }
                
                $addResultText($r, 'Found ' . count($result) . ' post types: ' . wp_json_encode($result, JSON_PRETTY_PRINT));
                break;
                
            // Site Health
            case 'wp_get_site_health':
                global $wpdb;
                
                $serverSoftware = isset($_SERVER['SERVER_SOFTWARE']) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : 'Unknown';
                $userAgent = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : 'Unknown';
                $health = array(
                    'wordpress' => array(
                        'version' => get_bloginfo('version'),
                        'site_url' => get_site_url(),
                        'home_url' => get_home_url(),
                        'is_multisite' => is_multisite(),
                        'language' => get_bloginfo('language'),
                    ),
                    'server' => array(
                        'php_version' => phpversion(),
                        'server_software' => $serverSoftware,
                        'https' => is_ssl(),
                        'user_agent' => $userAgent,
                    ),
                    'database' => array(
                        'extension' => $wpdb->use_mysqli ? 'mysqli' : 'mysql',
                        'server_version' => $wpdb->db_version(),
                        'client_version' => $wpdb->db_server_info(),
                        'database_name' => DB_NAME,
                        'database_user' => DB_USER,
                        'database_host' => DB_HOST,
                        'database_charset' => DB_CHARSET,
                        'table_prefix' => $wpdb->prefix,
                    ),
                    'theme' => array(
                        'name' => wp_get_theme()->get('Name'),
                        'version' => wp_get_theme()->get('Version'),
                        'author' => wp_get_theme()->get('Author'),
                        'parent_theme' => wp_get_theme()->get('Template'),
                    ),
                    'plugins' => array(
                        'active' => count(get_option('active_plugins', array())),
                        'total' => count(get_plugins()),
                    ),
                    'debug' => array(
                        'wp_debug' => defined('WP_DEBUG') && WP_DEBUG,
                        'wp_debug_log' => defined('WP_DEBUG_LOG') && WP_DEBUG_LOG,
                        'wp_debug_display' => defined('WP_DEBUG_DISPLAY') && WP_DEBUG_DISPLAY,
                        'script_debug' => defined('SCRIPT_DEBUG') && SCRIPT_DEBUG,
                    ),
                    'constants' => array(
                        'wp_memory_limit' => WP_MEMORY_LIMIT,
                        'wp_max_memory_limit' => WP_MAX_MEMORY_LIMIT,
                        'wp_content_dir' => WP_CONTENT_DIR,
                        'wp_plugin_dir' => WP_PLUGIN_DIR,
                        'uploads_dir' => wp_upload_dir()['basedir'] ?? 'Unknown',
                    ),
                );
                
                // Add WooCommerce info if active
                if (class_exists('WooCommerce')) {
                    $health['woocommerce'] = array(
                        'version' => WC()->version,
                        'database_version' => get_option('woocommerce_db_version'),
                    );
                }
                
                $addResultText($r, 'Site health: ' . wp_json_encode($health, JSON_PRETTY_PRINT));
                break;
                
            default:
                // Try to route to WooCommerce modules if tool starts with wc_
                if ( strpos( $tool, 'wc_' ) === 0 && class_exists( 'WooCommerce' ) ) {
                    $dispatched = false;
                    
                    // Try WC Products module
                    if ( class_exists( 'StifliFlexMcp_WC_Products' ) ) {
                        $result = StifliFlexMcp_WC_Products::dispatch( $tool, $args, $r, $addResultText, $utils );
                        if ( $result !== null ) {
                            return $result;
                        }
                    }
                    
                    // Try WC Orders module
                    if ( class_exists( 'StifliFlexMcp_WC_Orders' ) ) {
                        $result = StifliFlexMcp_WC_Orders::dispatch( $tool, $args, $r, $addResultText, $utils );
                        if ( $result !== null ) {
                            return $result;
                        }
                    }
                    
                    // Try WC Customers module
                    if ( class_exists( 'StifliFlexMcp_WC_Customers' ) ) {
                        $result = StifliFlexMcp_WC_Customers::dispatch( $tool, $args, $r, $addResultText, $utils );
                        if ( $result !== null ) {
                            return $result;
                        }
                    }
                    
                    // Try WC Coupons module
                    if ( class_exists( 'StifliFlexMcp_WC_Coupons' ) ) {
                        $result = StifliFlexMcp_WC_Coupons::dispatch( $tool, $args, $r, $addResultText, $utils );
                        if ( $result !== null ) {
                            return $result;
                        }
                    }
                    
                    // Try WC System module
                    if ( class_exists( 'StifliFlexMcp_WC_System' ) ) {
                        $result = StifliFlexMcp_WC_System::dispatch( $tool, $args, $r, $addResultText, $utils );
                        if ( $result !== null ) {
                            return $result;
                        }
                    }
                }
                
                // If not handled by any WooCommerce module or unknown tool
                $r['error'] = array('code' => -42609, 'message' => 'Unknown tool');
        }
        return $r;
    }
}
