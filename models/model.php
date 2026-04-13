<?php
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

// Model MCP con tools completas + intención/consentimiento
class StifliFlexMcpModel {
    private $tools = false;

    /**
     * Dispatch a Custom Tool (Webhook/API call or WordPress Action)
     */
    private function dispatchCustomTool($toolName, $args, $rpcId, $response) {
        global $wpdb;
        $table = $wpdb->prefix . 'sflmcp_custom_tools';
        
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name safe, toolName is sanitized input.
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM `$table` WHERE tool_name = %s AND enabled = 1", $toolName));
        
        if (!$row) {
             $response['error'] = array('code' => -32601, 'message' => 'Custom tool not found or disabled: ' . $toolName);
             return $response;
        }
        
        $method = strtoupper($row->method);
        $endpoint = $row->endpoint;
        
        // =====================================================
        // TYPE: ACTION - Execute WordPress do_action()
        // Allows calling ANY WordPress/plugin action hook
        // =====================================================
        if ($method === 'ACTION') {
            // The endpoint is the action name (sanitized)
            $action_name = sanitize_key($endpoint);
            
            if (empty($action_name)) {
                $response['error'] = array('code' => -32602, 'message' => 'Action name cannot be empty');
                return $response;
            }
            
            // Check if this action has any registered callbacks
            $has_action = has_action($action_name);
            
            // If no handlers, check if it's a known plugin action and warn accordingly
            $warning_msg = '';
            if (!$has_action) {
                $known_plugins = array(
                    'woocommerce_' => array('WooCommerce', 'woocommerce/woocommerce.php'),
                    'w3tc_' => array('W3 Total Cache', 'w3-total-cache/w3-total-cache.php'),
                    'wp_super_cache_' => array('WP Super Cache', 'wp-super-cache/wp-cache.php'),
                    'elementor_' => array('Elementor', 'elementor/elementor.php'),
                    'wpcf7_' => array('Contact Form 7', 'contact-form-7/wp-contact-form-7.php'),
                    'yoast_' => array('Yoast SEO', 'wordpress-seo/wp-seo.php'),
                    'rank_math_' => array('Rank Math', 'seo-by-rank-math/rank-math.php'),
                    'jetpack_' => array('Jetpack', 'jetpack/jetpack.php'),
                    'wpml_' => array('WPML', 'sitepress-multilingual-cms/sitepress.php'),
                );
                
                foreach ($known_plugins as $prefix => $plugin_info) {
                    if (strpos($action_name, $prefix) === 0) {
                        $plugin_name = $plugin_info[0];
                        $plugin_file = $plugin_info[1];
                        if (!is_plugin_active($plugin_file)) {
                            $warning_msg = sprintf('Plugin "%s" is not active. ', $plugin_name);
                        } else {
                            $warning_msg = sprintf('Plugin "%s" is active but this hook has no handlers. The hook may only be available in specific contexts (admin, frontend, cron). ', $plugin_name);
                        }
                        break;
                    }
                }
                
                if (empty($warning_msg)) {
                    $warning_msg = 'No handlers registered for this action. It may be a custom hook that requires your own handler. ';
                }
            }
            
            // Allow filter to capture/modify results from actions
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- sflmcp is the plugin prefix
            $result = apply_filters( 'sflmcp_action_result', null, $action_name, $args );
            
            // Execute the WordPress action with args
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- intentionally calling dynamic action hooks as per Custom Tools feature
            do_action( $action_name, $args );
            
            // Build response
            if ($result !== null) {
                $response['result'] = array('content' => array(array('type' => 'text', 'text' => is_string($result) ? $result : wp_json_encode($result))));
            } else {
                if ($has_action) {
                    $status = 'Action executed successfully: ' . $action_name;
                } else {
                    $status = 'Warning: ' . $warning_msg . 'Action triggered: ' . $action_name;
                }
                $response['result'] = array('content' => array(array('type' => 'text', 'text' => $status)));
            }
            
            return $response;
        }
        
        // =====================================================
        // TYPE: HTTP (GET/POST/PUT/DELETE) - Remote Request
        // =====================================================
        $url = $endpoint;
        $headers_raw = $row->headers;
        
        // Parse headers from newline-separated format
        $headers = array();
        if (!empty($headers_raw)) {
            $lines = explode("\n", $headers_raw);
            foreach ($lines as $line) {
                $line = trim($line);
                if (strpos($line, ':') !== false) {
                    list($key, $val) = explode(':', $line, 2);
                    $headers[trim($key)] = trim($val);
                }
            }
        }
        
        // Replace {placeholder} in URL with args
        if (is_array($args)) {
            foreach ($args as $key => $value) {
                $url = str_replace('{' . $key . '}', rawurlencode((string) $value), $url);
            }
        }
        
        // Execute request
        $request_args = array(
            'method' => $method,
            'headers' => $headers,
            'timeout' => 30,
            'user-agent' => 'StifLi-Flex-MCP/1.0.5; ' . get_bloginfo('url')
        );
        
        if (in_array($method, array('POST', 'PUT', 'PATCH'), true)) {
            $request_args['body'] = wp_json_encode($args);
            if (!isset($headers['Content-Type'])) {
                $request_args['headers']['Content-Type'] = 'application/json';
            }
        }
        
        $remote_response = wp_remote_request($url, $request_args);
        
        if (is_wp_error($remote_response)) {
            $response['error'] = array('code' => -32000, 'message' => 'External tool error: ' . $remote_response->get_error_message());
            return $response;
        }
        
        $code = wp_remote_retrieve_response_code($remote_response);
        $body = wp_remote_retrieve_body($remote_response);
        
        // Try to parse JSON response
        $decoded = json_decode($body, true);
        $final_content = ($decoded !== null) ? wp_json_encode($decoded, JSON_PRETTY_PRINT) : $body;
        
        if ($code >= 400) {
             $response['result'] = array('content' => array( array('type' => 'text', 'text' => "Error $code: $final_content") ), 'isError' => true);
        } else {
             $response['result'] = array('content' => array( array('type' => 'text', 'text' => $final_content) ));
        }
        
        return $response;
    }

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
            'wp_upload_image',
            'wp_generate_image',
            'wp_generate_video',
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
            'wc_create_refund','wc_delete_refund',
            // Snippet write operations
            'snippet_create','snippet_update','snippet_delete',
            'snippet_activate','snippet_deactivate',
            // Changelog write operations
            'mcp_rollback_change','mcp_redo_change','mcp_rollback_session'
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
            'wc_get_settings',      // WooCommerce settings
            // Snippet sensitive reads (code content)
            'snippet_list','snippet_get',
            // Changelog sensitive reads
            'mcp_get_changelog','mcp_get_change_detail'
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
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- schema introspection requires SHOW TABLES with LIKE pattern.
        $table_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $like)) === $table;
        
        if ($table_exists) {
            $tools_tbl = StifliFlexMcpUtils::getPrefixedTable('sflmcp_tools');
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name from sanitized helper.
            $results = $wpdb->get_results(
                $wpdb->prepare( "SELECT tool_name, token_estimate FROM {$tools_tbl} WHERE enabled = %d", 1 ),
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
            
            // Custom tools are already filtered by enabled=1 in getCustomTools()
            // So if the tool starts with 'custom_', it's already enabled
            $is_custom_tool = strpos($name, 'custom_') === 0;
            
            // Abilities are already filtered by enabled=1 in getImportedAbilities()
            // So if the tool starts with 'ability_', it's already enabled
            $is_ability = strpos($name, 'ability_') === 0;
            
            // If table doesn't exist, tool is in enabled list, or it's a custom tool/ability, include it
            if (!$table_exists || array_key_exists($name, $enabled_tools) || $is_custom_tool || $is_ability) {
                // Categoría
                if (in_array($name, array('search', 'fetch'), true)) {
                    $tool['category'] = 'Core: OpenAI';
                } elseif ($is_custom_tool) {
                    $tool['category'] = 'Custom';
                } elseif ($is_ability) {
                    $tool['category'] = isset($tool['category']) ? $tool['category'] : 'Abilities';
                } else {
                    $tool['category'] = 'Core';
                }
                // Intención y consentimiento
                $meta = $this->getIntentForTool($name);
                $tool['intent'] = $meta['intent']; // read | sensitive_read | write
                $tool['requires_confirmation'] = $meta['requires_confirmation']; // bool
                if ($table_exists && !$is_custom_tool && !$is_ability) {
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
                    'description' => 'Upload an image from base64 data and create a media attachment. Useful for AI-generated images. Accepts raw base64 or data URL (data:image/png;base64,...). Returns attachment ID and URL.',
                    'inputSchema' => array(
                        'type' => 'object',
                        'properties' => array(
                            'image_data' => array('type' => 'string', 'description' => 'Base64 encoded image data. Accepts raw base64 string or data URL (e.g. data:image/png;base64,iVBOR...). Whitespace and newlines are stripped automatically.'),
                            'filename' => array('type' => 'string', 'description' => 'Filename with extension (e.g., "image.png"). If extension is missing or wrong, it will be corrected based on the actual image format.'),
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

                // AI Image Generation
                'wp_generate_image' => array(
                    'name' => 'wp_generate_image',
                    'description' => 'Generate an image using AI and save it as a WordPress media attachment. Uses the configured AI provider (OpenAI/Gemini). Returns attachment ID, URL and medium-size URL. Supports size (square, landscape, portrait or aspect ratio like 16:9) and quality (low, medium, high for OpenAI).',
                    'inputSchema' => array(
                        'type' => 'object',
                        'properties' => array(
                            'prompt'  => array('type' => 'string', 'description' => 'Detailed description of the image to generate'),
                            'size'    => array('type' => 'string', 'description' => 'Image size: square (default), landscape, portrait, or aspect ratio like 16:9 for Gemini'),
                            'quality' => array('type' => 'string', 'description' => 'Quality for OpenAI: low, medium (default), high'),
                            'alt_text' => array('type' => 'string', 'description' => 'Alt text for the image attachment'),
                            'title'   => array('type' => 'string', 'description' => 'Title for the image attachment'),
                            'post_id' => array('type' => 'integer', 'description' => 'Optional post ID to attach the image to'),
                        ),
                        'required' => array('prompt'),
                    ),
                ),

                // AI Video Generation
                'wp_generate_video' => array(
                    'name' => 'wp_generate_video',
                    'description' => 'Generate a video using AI (Google Veo or OpenAI Sora) and save it as a WordPress media attachment. Video generation is asynchronous and may take 1-5 minutes. Returns attachment ID, URL, duration, and provider info. Configure defaults in Multimedia Settings.',
                    'inputSchema' => array(
                        'type' => 'object',
                        'properties' => array(
                            'prompt'        => array('type' => 'string', 'description' => 'Detailed description of the video to generate. Be specific about scene, camera movement, lighting, style.'),
                            'image_url'     => array('type' => 'string', 'description' => 'Optional source/start-frame image. Can be a URL or a WordPress attachment ID. Veo uses it as the first frame; Sora uses it as visual reference. Supported: JPEG, PNG.'),
                            'image_end_url' => array('type' => 'string', 'description' => 'Optional end-frame image (Veo only). When both image_url and image_end_url are provided, Veo interpolates between the two frames. Can be a URL or attachment ID.'),
                            'duration'      => array('type' => 'string', 'description' => 'Video duration in seconds: 5, 6, 8 (Veo), or 4, 8, 12 (Sora). Default from settings.'),
                            'aspect_ratio'  => array('type' => 'string', 'description' => 'Aspect ratio: 16:9 (landscape), 9:16 (portrait/reels), 1:1 (square/Veo only). Default from settings.'),
                            'title'         => array('type' => 'string', 'description' => 'Title for the video attachment in the Media Library'),
                            'post_id'       => array('type' => 'integer', 'description' => 'Optional post ID to attach the video to'),
                        ),
                        'required' => array('prompt'),
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

                // Changelog / Audit Log
                'mcp_get_changelog' => array(
                    'name' => 'mcp_get_changelog',
                    'description' => 'Get the changelog/audit log of MCP tool operations. Supports filtering by tool, operation type, object type, date range, and rollback status. Returns paginated results with total count.',
                    'inputSchema' => array(
                        'type' => 'object',
                        'properties' => array(
                            'tool_name'      => array('type' => 'string', 'description' => 'Filter by tool name (e.g. wp_update_post).'),
                            'operation_type'  => array('type' => 'string', 'description' => 'Filter by operation: create, update, delete, file_create, file_delete, unknown.'),
                            'object_type'     => array('type' => 'string', 'description' => 'Filter by object type: post, page, comment, user, term, option, media, product, order, coupon, etc.'),
                            'date_from'       => array('type' => 'string', 'description' => 'Start date filter (YYYY-MM-DD).'),
                            'date_to'         => array('type' => 'string', 'description' => 'End date filter (YYYY-MM-DD).'),
                            'rolled_back'     => array('type' => 'integer', 'description' => '0=active only, 1=rolled-back only. Omit for all.'),
                            'page'            => array('type' => 'integer', 'description' => 'Page number (default 1).'),
                            'per_page'        => array('type' => 'integer', 'description' => 'Results per page (default 25, max 100).'),
                        ),
                        'required' => array(),
                    ),
                ),
                'mcp_get_change_detail' => array(
                    'name' => 'mcp_get_change_detail',
                    'description' => 'Get full detail of a single changelog entry including before/after state snapshots and arguments used.',
                    'inputSchema' => array(
                        'type' => 'object',
                        'properties' => array(
                            'id' => array('type' => 'integer', 'description' => 'Changelog entry ID.'),
                        ),
                        'required' => array('id'),
                    ),
                ),
                'mcp_rollback_change' => array(
                    'name' => 'mcp_rollback_change',
                    'description' => 'Rollback a specific changelog entry, reverting the change to the before-state. Only works on entries that have not already been rolled back.',
                    'inputSchema' => array(
                        'type' => 'object',
                        'properties' => array(
                            'id' => array('type' => 'integer', 'description' => 'Changelog entry ID to rollback.'),
                        ),
                        'required' => array('id'),
                    ),
                ),
                'mcp_redo_change' => array(
                    'name' => 'mcp_redo_change',
                    'description' => 'Redo a previously rolled back changelog entry, re-applying the after-state. Only works on entries that have been rolled back.',
                    'inputSchema' => array(
                        'type' => 'object',
                        'properties' => array(
                            'id' => array('type' => 'integer', 'description' => 'Changelog entry ID to redo.'),
                        ),
                        'required' => array('id'),
                    ),
                ),
                'mcp_rollback_session' => array(
                    'name' => 'mcp_rollback_session',
                    'description' => 'Rollback all changes made in a specific session (by session_id), in reverse chronological order (LIFO). Returns count of changes rolled back.',
                    'inputSchema' => array(
                        'type' => 'object',
                        'properties' => array(
                            'session_id' => array('type' => 'string', 'description' => 'Session ID to rollback all changes for.'),
                        ),
                        'required' => array('session_id'),
                    ),
                ),
            );

            // Merge Snippets tools if a snippet plugin is available
            require_once dirname(__FILE__) . '/snippets/snippets.php';
            if ( class_exists( 'StifliFlexMcp_Snippets' ) ) {
                $tools = array_merge( $tools, StifliFlexMcp_Snippets::getTools() );
            }

            // Merge WooCommerce tools if available
            // Lazy load modules ensures compatibility with all load orders
            if ( class_exists( 'WooCommerce' ) ) {
                require_once dirname(__FILE__) . '/woocommerce/wc-products.php';
                require_once dirname(__FILE__) . '/woocommerce/wc-orders.php';
                require_once dirname(__FILE__) . '/woocommerce/wc-customers-coupons.php';
                require_once dirname(__FILE__) . '/woocommerce/wc-system.php';

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
        
        // Add Custom Tools
        $custom_tools = $this->getCustomTools();
        if (!empty($custom_tools)) {
            foreach ($custom_tools as $tool) {
                // Ensure proper structure
                if (!isset($tool['name']) || !isset($tool['inputSchema'])) continue;
                $this->tools[$tool['name']] = $tool;
            }
        }
        
        // Add WordPress Abilities (WordPress 6.9+)
        $abilities = $this->getImportedAbilities();
        if (!empty($abilities)) {
            foreach ($abilities as $tool) {
                if (!isset($tool['name']) || !isset($tool['inputSchema'])) continue;
                $this->tools[$tool['name']] = $tool;
            }
        }
        
        return $this->tools;
    }

    /**
     * Get defined custom tools from database
     */
    private function getCustomTools() {
        global $wpdb;
        $table = $wpdb->prefix . 'sflmcp_custom_tools';
        
        // Check if table exists first (during updates it might not exist yet)
        $like = $wpdb->esc_like($table);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- schema check requires direct query.
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) ) !== $table ) {
            return array();
        }
        
        $tools = array();
        
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- cache disabled for fresh tools, table name is safe.
        $results = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `$table` WHERE enabled = %d", 1 ) );
        
        if (!$results) return array();
        
        foreach ($results as $row) {
            $schema = json_decode($row->arguments, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $schema = array('type' => 'object', 'properties' => (object) array(), 'required' => array());
            }
            
            $tools[] = array(
                'name' => $row->tool_name,
                'description' => $row->tool_description,
                'inputSchema' => $schema,
                'method' => $row->method,
                'endpoint' => $row->endpoint,
                'headers' => $row->headers,
                'category' => 'Custom',
                'intent' => 'sensitive_read', // Default safe intent
                'requires_confirmation' => true, // Always require confirmation for external calls
            );
        }
        
        return $tools;
    }

    /**
     * Get imported WordPress Abilities from database (WordPress 6.9+)
     * These are abilities from other plugins that have been imported via the admin UI.
     */
    private function getImportedAbilities() {
        global $wpdb;
        $table = StifliFlexMcpUtils::getPrefixedTable('sflmcp_abilities', false);
        
        // Check if table exists first
        $like = $wpdb->esc_like($table);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- schema check.
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $like)) !== $table) {
            return array();
        }
        
        $tools = array();
        $table_safe = StifliFlexMcpUtils::getPrefixedTable('sflmcp_abilities');
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name from sanitized helper.
        $results = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table_safe} WHERE enabled = %d", 1));
        
        if (!$results) {
            return array();
        }
        
        foreach ($results as $row) {
            $input_schema = json_decode($row->input_schema, true);
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($input_schema)) {
                $input_schema = array('type' => 'object', 'properties' => (object) array(), 'required' => array());
            }
            
            // Convert ability name to tool name: "allsi/search-image" -> "ability_allsi_search_image"
            $tool_name = 'ability_' . str_replace(array('/', '-'), '_', $row->ability_name);
            
            $tools[] = array(
                'name' => $tool_name,
                'description' => $row->ability_description ?: $row->ability_label,
                'inputSchema' => $input_schema,
                'category' => 'Abilities - ' . $row->ability_category,
                'intent' => 'sensitive_read', // Abilities may have side effects
                'requires_confirmation' => true,
                // Store original ability name for execution
                '_ability_name' => $row->ability_name,
                '_is_ability' => true,
            );
        }
        
        return $tools;
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
            'wp_generate_image' => 'upload_files',
            'wp_generate_video' => 'upload_files',
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
            // Changelog
            'mcp_get_changelog' => 'manage_options',
            'mcp_get_change_detail' => 'manage_options',
            'mcp_rollback_change' => 'manage_options',
            'mcp_redo_change' => 'manage_options',
            'mcp_rollback_session' => 'manage_options',
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

        // Merge Snippets capabilities if available
        if ( class_exists( 'StifliFlexMcp_Snippets' ) ) {
            $map = array_merge( $map, StifliFlexMcp_Snippets::getCapabilities() );
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

        // Change Tracker: capture before-state for mutating tools
        $changeSnapshot = null;
        if ( class_exists( 'StifliFlexMcp_ChangeTracker' ) && get_option( 'sflmcp_changelog_enabled', true ) ) {
            $changeTracker  = StifliFlexMcp_ChangeTracker::getInstance();
            $changeSnapshot = $changeTracker->captureBeforeState( $tool, is_array( $args ) ? $args : array() );
        }

        // Helper closure to record a tracked change before any early return
        $recordChangeIfNeeded = function() use ( $tool, $args, &$changeSnapshot, &$r ) {
            if ( null !== $changeSnapshot && ! isset( $r['error'] ) && class_exists( 'StifliFlexMcp_ChangeTracker' ) ) {
                try {
                    $tracker = StifliFlexMcp_ChangeTracker::getInstance();
                    $tracker->recordChange( $tool, is_array( $args ) ? $args : array(), $changeSnapshot, $r );
                } catch ( \Exception $e ) {
                    stifli_flex_mcp_log( 'ChangeTracker error: ' . $e->getMessage() );
                }
            }
        };

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
                
                // Normalize base64: accept data URL or raw base64
                $detected_mime = null;
                $image_data = trim($image_data);
                if (preg_match('/^data:([^;]+);base64,(.*)$/s', $image_data, $b64match)) {
                    $detected_mime = $b64match[1];
                    $image_data = $b64match[2];
                }
                // Strip whitespace/newlines that LLMs may inject
                $image_data = preg_replace('/\s+/', '', $image_data);
                // Fix base64 padding if missing
                $pad = strlen($image_data) % 4;
                if ($pad) {
                    $image_data .= str_repeat('=', 4 - $pad);
                }
                $decoded = base64_decode($image_data, true);
                
                if ($decoded === false || strlen($decoded) < 8) { $r['error'] = array('code' => -42602, 'message' => 'Invalid base64 data'); break; }
                
                // Detect MIME from binary header if not from data URL
                if (!$detected_mime) {
                    $header = substr($decoded, 0, 16);
                    if (substr($header, 0, 8) === "\x89PNG\r\n\x1a\n") {
                        $detected_mime = 'image/png';
                    } elseif (substr($header, 0, 2) === "\xff\xd8") {
                        $detected_mime = 'image/jpeg';
                    } elseif (substr($header, 0, 4) === 'GIF8') {
                        $detected_mime = 'image/gif';
                    } elseif (substr($header, 0, 4) === 'RIFF' && substr($header, 8, 4) === 'WEBP') {
                        $detected_mime = 'image/webp';
                    }
                }
                // Ensure filename has the right extension based on detected MIME
                if ($detected_mime) {
                    $mime_to_ext = array('image/png' => 'png', 'image/jpeg' => 'jpg', 'image/gif' => 'gif', 'image/webp' => 'webp');
                    $ext = isset($mime_to_ext[$detected_mime]) ? $mime_to_ext[$detected_mime] : null;
                    if ($ext) {
                        $current_ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                        if (!$current_ext || !in_array($current_ext, array('png', 'jpg', 'jpeg', 'gif', 'webp'), true)) {
                            $filename = pathinfo($filename, PATHINFO_FILENAME) . '.' . $ext;
                            $filename = sanitize_file_name($filename);
                        }
                    }
                }
                
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
            case 'wp_generate_image':
                stifli_flex_mcp_log('wp_generate_image: === START ===');
                $prompt = sanitize_text_field( $utils::getArrayValue( $args, 'prompt', '' ) );
                if ( empty( $prompt ) ) {
                    stifli_flex_mcp_log('wp_generate_image: ERROR - prompt is empty');
                    $r['error'] = array( 'code' => -42602, 'message' => 'prompt required' );
                    break;
                }
                if ( ! current_user_can( 'upload_files' ) ) {
                    stifli_flex_mcp_log('wp_generate_image: ERROR - user lacks upload_files capability');
                    $r['error'] = array( 'code' => 'permission_denied', 'message' => 'Insufficient permissions to upload files' );
                    break;
                }
                $img_size    = sanitize_text_field( $utils::getArrayValue( $args, 'size', 'square' ) );
                $img_quality = sanitize_text_field( $utils::getArrayValue( $args, 'quality', 'medium' ) );
                $img_alt     = sanitize_text_field( $utils::getArrayValue( $args, 'alt_text', '' ) );
                $img_title   = sanitize_text_field( $utils::getArrayValue( $args, 'title', '' ) );
                $img_post_id = intval( $utils::getArrayValue( $args, 'post_id', 0 ) );
                stifli_flex_mcp_log('wp_generate_image: prompt="' . substr( $prompt, 0, 120 ) . '" size=' . $img_size . ' quality=' . $img_quality . ' post_id=' . $img_post_id);

                // Load multimedia settings (dedicated — independent from Chat Agent)
                $mm_settings     = get_option( 'sflmcp_multimedia_settings', array() );
                $provider        = ! empty( $mm_settings['image_provider'] ) ? $mm_settings['image_provider'] : 'openai';
                stifli_flex_mcp_log('wp_generate_image: provider=' . $provider);

                // Resolve API key: multimedia settings only (no Chat Agent fallback)
                $encrypted_key = '';
                if ( $provider === 'gemini' ) {
                    $encrypted_key = ! empty( $mm_settings['gemini_api_key'] ) ? $mm_settings['gemini_api_key'] : '';
                } else {
                    $encrypted_key = ! empty( $mm_settings['openai_api_key'] ) ? $mm_settings['openai_api_key'] : '';
                }

                // Decrypt API key (same logic as StifliFlexMcp_Client_Admin)
                $api_key = '';
                if ( ! empty( $encrypted_key ) ) {
                    if ( class_exists( 'StifliFlexMcp_Client_Admin' ) ) {
                        $api_key = StifliFlexMcp_Client_Admin::decrypt_value( $encrypted_key );
                    } else {
                        $api_key = $encrypted_key; // fallback: may already be plain
                    }
                }
                if ( empty( $api_key ) ) {
                    stifli_flex_mcp_log('wp_generate_image: ERROR - no API key configured for provider=' . $provider);
                    $r['error'] = array( 'code' => -32603, 'message' => 'No AI API key configured. Go to StifLi Flex MCP > Multimedia Settings to set one.' );
                    break;
                }
                stifli_flex_mcp_log('wp_generate_image: API key resolved (length=' . strlen( $api_key ) . ')');

                $image_binary = false;
                $mime_type    = 'image/png';
                $gen_error    = '';

                if ( $provider === 'gemini' ) {
                    // --- Gemini image generation ---
                    $gemini_model = ! empty( $mm_settings['gemini_model'] ) ? $mm_settings['gemini_model'] : 'gemini-2.5-flash-image';
                    $default_ratio = ! empty( $mm_settings['gemini_aspect_ratio'] ) ? $mm_settings['gemini_aspect_ratio'] : '1:1';
                    // Map size to Gemini aspect ratio
                    $aspect_map = array(
                        'square'    => '1:1',
                        'landscape' => '16:9',
                        'portrait'  => '9:16',
                        'wide'      => '21:9',
                    );
                    $valid_ratios = array( '1:1', '2:3', '3:2', '3:4', '4:3', '4:5', '5:4', '9:16', '16:9', '21:9' );
                    $aspect_ratio = isset( $aspect_map[ $img_size ] ) ? $aspect_map[ $img_size ] : ( in_array( $img_size, $valid_ratios, true ) ? $img_size : $default_ratio );

                    // Imagen models use a different API (generateImages) vs Gemini flash (generateContent)
                    $is_imagen = ( strpos( $gemini_model, 'imagen' ) === 0 );
                    stifli_flex_mcp_log('wp_generate_image: Gemini model=' . $gemini_model . ' is_imagen=' . ( $is_imagen ? 'yes' : 'no' ) . ' aspect_ratio=' . $aspect_ratio);

                    if ( $is_imagen ) {
                        // --- Imagen 4 API ---
                        $api_url = 'https://generativelanguage.googleapis.com/v1beta/models/' . $gemini_model . ':generateImages?key=' . $api_key;
                        stifli_flex_mcp_log('wp_generate_image: Calling Imagen API...');
                        $body    = array(
                            'prompt' => $prompt,
                            'config' => array(
                                'numberOfImages' => 1,
                                'aspectRatio'    => $aspect_ratio,
                                'outputOptions'  => array(
                                    'mimeType' => 'image/png',
                                ),
                            ),
                        );
                        $resp = wp_remote_post( $api_url, array(
                            'headers' => array( 'Content-Type' => 'application/json' ),
                            'body'    => wp_json_encode( $body ),
                            'timeout' => 120,
                        ) );
                        if ( is_wp_error( $resp ) ) {
                            $gen_error = 'Imagen API error: ' . $resp->get_error_message();
                            stifli_flex_mcp_log('wp_generate_image: Imagen WP error: ' . $gen_error);
                        } else {
                            $http_code = wp_remote_retrieve_response_code( $resp );
                            $resp_body = json_decode( wp_remote_retrieve_body( $resp ), true );
                            stifli_flex_mcp_log('wp_generate_image: Imagen response HTTP ' . $http_code);
                            if ( 200 !== $http_code ) {
                                $gen_error = 'Imagen API error (HTTP ' . $http_code . '): ' . ( isset( $resp_body['error']['message'] ) ? $resp_body['error']['message'] : 'Unknown error' );
                                stifli_flex_mcp_log('wp_generate_image: ' . $gen_error);
                            } else {
                                $b64_data = '';
                                if ( isset( $resp_body['generatedImages'][0]['image']['imageBytes'] ) ) {
                                    $b64_data  = $resp_body['generatedImages'][0]['image']['imageBytes'];
                                    $mime_type = 'image/png';
                                }
                                if ( empty( $b64_data ) ) {
                                    $gen_error = 'Imagen returned no image data.';
                                } else {
                                    // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- decoding AI-generated image binary.
                                    $image_binary = base64_decode( $b64_data );
                                    if ( false === $image_binary ) {
                                        $gen_error    = 'Failed to decode Imagen base64 image data.';
                                        $image_binary = false;
                                    }
                                }
                            }
                        }
                    } else {
                        // --- Gemini Flash generateContent API ---
                        $api_url = 'https://generativelanguage.googleapis.com/v1beta/models/' . $gemini_model . ':generateContent?key=' . $api_key;
                        stifli_flex_mcp_log('wp_generate_image: Calling Gemini generateContent API...');
                    $body    = array(
                        'contents' => array(
                            array(
                                'parts' => array(
                                    array( 'text' => $prompt ),
                                ),
                            ),
                        ),
                        'generationConfig' => array(
                            'responseModalities' => array( 'IMAGE', 'TEXT' ),
                            'imageConfig' => array(
                                'aspectRatio' => $aspect_ratio,
                            ),
                        ),
                    );
                    $resp = wp_remote_post( $api_url, array(
                        'headers' => array( 'Content-Type' => 'application/json' ),
                        'body'    => wp_json_encode( $body ),
                        'timeout' => 120,
                    ) );
                    if ( is_wp_error( $resp ) ) {
                        $gen_error = 'Gemini API error: ' . $resp->get_error_message();
                        stifli_flex_mcp_log('wp_generate_image: Gemini WP error: ' . $gen_error);
                    } else {
                        $http_code = wp_remote_retrieve_response_code( $resp );
                        $resp_body = json_decode( wp_remote_retrieve_body( $resp ), true );
                        stifli_flex_mcp_log('wp_generate_image: Gemini response HTTP ' . $http_code);
                        if ( 200 !== $http_code ) {
                            $gen_error = 'Gemini API error (HTTP ' . $http_code . '): ' . ( isset( $resp_body['error']['message'] ) ? $resp_body['error']['message'] : 'Unknown error' );
                            stifli_flex_mcp_log('wp_generate_image: ' . $gen_error);
                        } else {
                            // Extract image from response
                            $parts_arr = isset( $resp_body['candidates'][0]['content']['parts'] ) ? $resp_body['candidates'][0]['content']['parts'] : array();
                            $b64_data  = '';
                            foreach ( $parts_arr as $part ) {
                                if ( isset( $part['inlineData']['data'] ) ) {
                                    $b64_data  = $part['inlineData']['data'];
                                    $mime_type = isset( $part['inlineData']['mimeType'] ) ? $part['inlineData']['mimeType'] : 'image/png';
                                    break;
                                } elseif ( isset( $part['inline_data']['data'] ) ) {
                                    $b64_data  = $part['inline_data']['data'];
                                    $mime_type = isset( $part['inline_data']['mime_type'] ) ? $part['inline_data']['mime_type'] : 'image/png';
                                    break;
                                }
                            }
                            if ( empty( $b64_data ) ) {
                                $finish = isset( $resp_body['candidates'][0]['finishReason'] ) ? $resp_body['candidates'][0]['finishReason'] : 'UNKNOWN';
                                if ( in_array( $finish, array( 'IMAGE_SAFETY', 'IMAGE_PROHIBITED_CONTENT' ), true ) ) {
                                    $gen_error = 'Gemini blocked image generation due to safety filters (reason: ' . $finish . ').';
                                } else {
                                    // Retry once with reinforced prompt
                                    $retry_prompt = 'Generate an image based on this description (you MUST return an image, not text): ' . $prompt;
                                    $body['contents'][0]['parts'][0]['text'] = $retry_prompt;
                                    $resp2 = wp_remote_post( $api_url, array(
                                        'headers' => array( 'Content-Type' => 'application/json' ),
                                        'body'    => wp_json_encode( $body ),
                                        'timeout' => 120,
                                    ) );
                                    if ( ! is_wp_error( $resp2 ) && 200 === wp_remote_retrieve_response_code( $resp2 ) ) {
                                        $resp_body2 = json_decode( wp_remote_retrieve_body( $resp2 ), true );
                                        $parts2     = isset( $resp_body2['candidates'][0]['content']['parts'] ) ? $resp_body2['candidates'][0]['content']['parts'] : array();
                                        foreach ( $parts2 as $part ) {
                                            if ( isset( $part['inlineData']['data'] ) ) {
                                                $b64_data  = $part['inlineData']['data'];
                                                $mime_type = isset( $part['inlineData']['mimeType'] ) ? $part['inlineData']['mimeType'] : 'image/png';
                                                break;
                                            } elseif ( isset( $part['inline_data']['data'] ) ) {
                                                $b64_data  = $part['inline_data']['data'];
                                                $mime_type = isset( $part['inline_data']['mime_type'] ) ? $part['inline_data']['mime_type'] : 'image/png';
                                                break;
                                            }
                                        }
                                    }
                                    if ( empty( $b64_data ) ) {
                                        $gen_error = 'Gemini returned no image data after retry (finishReason: ' . $finish . ').';
                                    }
                                }
                            }
                            if ( ! empty( $b64_data ) ) {
                                // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- decoding AI-generated image binary.
                                $image_binary = base64_decode( $b64_data );
                                if ( false === $image_binary ) {
                                    $gen_error = 'Failed to decode Gemini base64 image data.';
                                    $image_binary = false;
                                }
                            }
                        }
                    }
                    } // end Gemini flash else
                } else {
                    // --- OpenAI image generation (configurable model with dall-e-3 fallback) ---
                    stifli_flex_mcp_log('wp_generate_image: Using OpenAI provider');
                    $oai_model      = ! empty( $mm_settings['openai_model'] ) ? $mm_settings['openai_model'] : 'gpt-image-1';
                    $default_size   = ! empty( $mm_settings['openai_size'] ) ? $mm_settings['openai_size'] : 'square';
                    $default_qual   = ! empty( $mm_settings['openai_quality'] ) ? $mm_settings['openai_quality'] : 'medium';
                    $oai_style      = ! empty( $mm_settings['openai_style'] ) ? $mm_settings['openai_style'] : 'natural';
                    $oai_bg         = ! empty( $mm_settings['openai_background'] ) ? $mm_settings['openai_background'] : 'auto';
                    $oai_out_format = ! empty( $mm_settings['openai_output_format'] ) ? $mm_settings['openai_output_format'] : 'png';

                    // Use tool arg if provided, otherwise use settings default
                    $effective_size    = ( $img_size !== 'square' || ! empty( $args['size'] ) ) ? $img_size : $default_size;
                    $effective_quality = ( $img_quality !== 'medium' || ! empty( $args['quality'] ) ) ? $img_quality : $default_qual;

                    $size_map = array(
                        'square'    => '1024x1024',
                        'landscape' => '1536x1024',
                        'portrait'  => '1024x1536',
                    );
                    $oai_size = isset( $size_map[ $effective_size ] ) ? $size_map[ $effective_size ] : '1024x1024';

                    $quality_map = array(
                        'low'      => 'low',
                        'medium'   => 'medium',
                        'high'     => 'high',
                        'standard' => 'medium',
                        'hd'       => 'high',
                    );
                    $oai_quality = isset( $quality_map[ $effective_quality ] ) ? $quality_map[ $effective_quality ] : 'medium';

                    $oai_body = array(
                        'prompt'  => $prompt,
                        'n'       => 1,
                        'size'    => $oai_size,
                        'model'   => $oai_model,
                        'quality' => $oai_quality,
                    );

                    // Add model-specific parameters
                    if ( $oai_model === 'gpt-image-1' ) {
                        $oai_body['output_format'] = $oai_out_format;
                        if ( $oai_bg !== 'auto' ) {
                            $oai_body['background'] = $oai_bg;
                        }
                    } elseif ( $oai_model === 'dall-e-3' ) {
                        $oai_body['style'] = $oai_style;
                        // DALL-E 3 only supports specific sizes
                        $dalle3_sizes = array( '1024x1024', '1792x1024', '1024x1792' );
                        if ( ! in_array( $oai_size, $dalle3_sizes, true ) ) {
                            $dalle3_remap = array( '1536x1024' => '1792x1024', '1024x1536' => '1024x1792' );
                            $oai_body['size'] = isset( $dalle3_remap[ $oai_size ] ) ? $dalle3_remap[ $oai_size ] : '1024x1024';
                        }
                        // DALL-E 3 uses standard/hd quality
                        $dalle3_qual = array( 'high' => 'hd', 'medium' => 'standard', 'low' => 'standard' );
                        $oai_body['quality'] = isset( $dalle3_qual[ $oai_quality ] ) ? $dalle3_qual[ $oai_quality ] : 'standard';
                    }
                    stifli_flex_mcp_log('wp_generate_image: OpenAI model=' . $oai_body['model'] . ' size=' . $oai_body['size'] . ' quality=' . $oai_body['quality']);
                    $oai_resp = wp_remote_post( 'https://api.openai.com/v1/images/generations', array(
                        'headers' => array(
                            'Authorization' => 'Bearer ' . $api_key,
                            'Content-Type'  => 'application/json',
                        ),
                        'body'    => wp_json_encode( $oai_body ),
                        'timeout' => 120,
                    ) );

                    $oai_ok   = false;
                    $oai_data = array();
                    if ( is_wp_error( $oai_resp ) ) {
                        $gen_error = 'OpenAI API error: ' . $oai_resp->get_error_message();
                        stifli_flex_mcp_log('wp_generate_image: OpenAI WP error: ' . $gen_error);
                    } else {
                        $oai_http = wp_remote_retrieve_response_code( $oai_resp );
                        $oai_json = json_decode( wp_remote_retrieve_body( $oai_resp ), true );

                        // Fallback to DALL-E 3 if gpt-image-1 requires verification
                        $must_verify = ( 403 === $oai_http )
                            && isset( $oai_json['error']['message'] )
                            && stripos( $oai_json['error']['message'], 'must be verified' ) !== false;

                        if ( $must_verify && $oai_model !== 'dall-e-3' ) {
                            $dalle3_size_map = array(
                                '1536x1024' => '1792x1024',
                                '1024x1536' => '1024x1792',
                                '1024x1024' => '1024x1024',
                            );
                            $dalle3_quality_map = array(
                                'high'   => 'hd',
                                'medium' => 'standard',
                                'low'    => 'standard',
                            );
                            $oai_body['model']   = 'dall-e-3';
                            $oai_body['size']    = isset( $dalle3_size_map[ $oai_size ] ) ? $dalle3_size_map[ $oai_size ] : '1024x1024';
                            $oai_body['quality'] = isset( $dalle3_quality_map[ $oai_quality ] ) ? $dalle3_quality_map[ $oai_quality ] : 'standard';
                            $oai_body['style']   = $oai_style;
                            unset( $oai_body['output_format'], $oai_body['background'] );

                            $oai_resp = wp_remote_post( 'https://api.openai.com/v1/images/generations', array(
                                'headers' => array(
                                    'Authorization' => 'Bearer ' . $api_key,
                                    'Content-Type'  => 'application/json',
                                ),
                                'body'    => wp_json_encode( $oai_body ),
                                'timeout' => 120,
                            ) );
                            if ( ! is_wp_error( $oai_resp ) ) {
                                $oai_http = wp_remote_retrieve_response_code( $oai_resp );
                                $oai_json = json_decode( wp_remote_retrieve_body( $oai_resp ), true );
                            } else {
                                $gen_error = 'OpenAI DALL-E 3 fallback error: ' . $oai_resp->get_error_message();
                            }
                        }

                        if ( empty( $gen_error ) ) {
                            if ( 200 !== $oai_http || ! is_array( $oai_json ) ) {
                                $err_msg = isset( $oai_json['error']['message'] ) ? $oai_json['error']['message'] : 'Unknown error';
                                $gen_error = 'OpenAI API error (HTTP ' . $oai_http . '): ' . $err_msg;
                            } else {
                                $oai_data = isset( $oai_json['data'][0] ) ? $oai_json['data'][0] : array();
                                $oai_ok   = true;
                            }
                        }
                    }

                    if ( $oai_ok ) {
                        if ( ! empty( $oai_data['b64_json'] ) ) {
                            // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- decoding AI-generated image binary.
                            $image_binary = base64_decode( $oai_data['b64_json'] );
                            $mime_type    = 'image/png';
                        } elseif ( ! empty( $oai_data['url'] ) ) {
                            // DALL-E 3 returns a URL — download it
                            $dl = wp_remote_get( $oai_data['url'], array( 'timeout' => 60 ) );
                            if ( ! is_wp_error( $dl ) && 200 === wp_remote_retrieve_response_code( $dl ) ) {
                                $image_binary = wp_remote_retrieve_body( $dl );
                                $ct = wp_remote_retrieve_header( $dl, 'content-type' );
                                if ( stripos( $ct, 'jpeg' ) !== false || stripos( $ct, 'jpg' ) !== false ) {
                                    $mime_type = 'image/jpeg';
                                } elseif ( stripos( $ct, 'webp' ) !== false ) {
                                    $mime_type = 'image/webp';
                                } else {
                                    $mime_type = 'image/png';
                                }
                            } else {
                                $gen_error = 'Failed to download generated image from OpenAI.';
                            }
                        } else {
                            $gen_error = 'OpenAI response contained no image data (no b64_json or url).';
                        }
                    }
                }

                // Handle generation error
                if ( false === $image_binary || empty( $image_binary ) ) {
                    stifli_flex_mcp_log('wp_generate_image: FAILED - ' . ( $gen_error ? $gen_error : 'unknown error' ));
                    $r['error'] = array( 'code' => -32603, 'message' => $gen_error ? $gen_error : 'Image generation failed.' );
                    break;
                }
                stifli_flex_mcp_log('wp_generate_image: Image binary received, size=' . strlen( $image_binary ) . ' bytes, mime=' . $mime_type);

                // Save as WordPress media attachment
                if ( ! function_exists( 'wp_upload_dir' ) ) {
                    require_once ABSPATH . 'wp-admin/includes/file.php';
                }
                if ( ! function_exists( 'media_handle_sideload' ) ) {
                    require_once ABSPATH . 'wp-admin/includes/media.php';
                    require_once ABSPATH . 'wp-admin/includes/image.php';
                }

                $ext_map  = array( 'image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp' );
                $ext      = isset( $ext_map[ $mime_type ] ) ? $ext_map[ $mime_type ] : 'png';
                $filename = 'ai-generated-' . gmdate( 'Ymd-His' ) . '-' . wp_generate_password( 6, false ) . '.' . $ext;

                $upload_dir = wp_upload_dir();
                $temp_file  = $upload_dir['path'] . '/' . wp_unique_filename( $upload_dir['path'], $filename );

                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- writing temp binary from AI API to create media attachment.
                if ( file_put_contents( $temp_file, $image_binary ) === false ) {
                    $r['error'] = array( 'code' => 'write_error', 'message' => 'Failed to write image file' );
                    break;
                }

                $file_array = array(
                    'name'     => $filename,
                    'tmp_name' => $temp_file,
                );
                $att_id = media_handle_sideload( $file_array, $img_post_id );

                if ( is_wp_error( $att_id ) ) {
                    stifli_flex_mcp_log('wp_generate_image: Sideload error: ' . $att_id->get_error_message());
                    wp_delete_file( $temp_file );
                    $r['error'] = array( 'code' => 'upload_error', 'message' => $att_id->get_error_message() );
                    break;
                }
                stifli_flex_mcp_log('wp_generate_image: Saved as attachment ID=' . $att_id);

                // ── Post-processing: resize/compress if enabled ──
                $pp_enabled = ! empty( $mm_settings['pp_enabled'] ) && '1' === $mm_settings['pp_enabled'];
                if ( $pp_enabled ) {
                    $att_file = get_attached_file( $att_id );
                    if ( $att_file && file_exists( $att_file ) ) {
                        $pp_max_w  = isset( $mm_settings['pp_max_width'] ) ? intval( $mm_settings['pp_max_width'] ) : 0;
                        $pp_max_h  = isset( $mm_settings['pp_max_height'] ) ? intval( $mm_settings['pp_max_height'] ) : 0;
                        $pp_qual   = isset( $mm_settings['pp_quality'] ) ? intval( $mm_settings['pp_quality'] ) : 82;
                        $pp_format = ! empty( $mm_settings['pp_format'] ) ? $mm_settings['pp_format'] : 'original';

                        $editor = wp_get_image_editor( $att_file );
                        if ( ! is_wp_error( $editor ) ) {
                            $editor->set_quality( $pp_qual );

                            // Resize if limits are set
                            if ( $pp_max_w > 0 || $pp_max_h > 0 ) {
                                $cur_size = $editor->get_size();
                                $needs_resize = false;
                                if ( $pp_max_w > 0 && $cur_size['width'] > $pp_max_w ) {
                                    $needs_resize = true;
                                }
                                if ( $pp_max_h > 0 && $cur_size['height'] > $pp_max_h ) {
                                    $needs_resize = true;
                                }
                                if ( $needs_resize ) {
                                    $editor->resize( $pp_max_w > 0 ? $pp_max_w : null, $pp_max_h > 0 ? $pp_max_h : null );
                                }
                            }

                            // Determine save path/format
                            $save_mime = null;
                            if ( 'original' !== $pp_format ) {
                                $format_mime_map = array( 'jpeg' => 'image/jpeg', 'webp' => 'image/webp', 'png' => 'image/png' );
                                $save_mime = isset( $format_mime_map[ $pp_format ] ) ? $format_mime_map[ $pp_format ] : null;
                            }

                            if ( $save_mime && $save_mime !== $mime_type ) {
                                // Convert format: save new file and update attachment
                                $new_ext  = ( 'image/jpeg' === $save_mime ) ? 'jpg' : ( ( 'image/webp' === $save_mime ) ? 'webp' : 'png' );
                                $new_name = pathinfo( $att_file, PATHINFO_FILENAME ) . '.' . $new_ext;
                                $new_path = pathinfo( $att_file, PATHINFO_DIRNAME ) . '/' . $new_name;
                                $saved    = $editor->save( $new_path, $save_mime );
                                if ( ! is_wp_error( $saved ) ) {
                                    // Remove old file and update attachment
                                    wp_delete_file( $att_file );
                                    update_attached_file( $att_id, $saved['path'] );
                                    $mime_type = $save_mime;
                                    wp_update_post( array( 'ID' => $att_id, 'post_mime_type' => $save_mime ) );
                                    // Regenerate metadata for the new file
                                    if ( function_exists( 'wp_generate_attachment_metadata' ) ) {
                                        $meta = wp_generate_attachment_metadata( $att_id, $saved['path'] );
                                        wp_update_attachment_metadata( $att_id, $meta );
                                    }
                                }
                            } else {
                                // Same format: overwrite in place
                                $saved = $editor->save( $att_file );
                                if ( ! is_wp_error( $saved ) && function_exists( 'wp_generate_attachment_metadata' ) ) {
                                    $meta = wp_generate_attachment_metadata( $att_id, $saved['path'] );
                                    wp_update_attachment_metadata( $att_id, $meta );
                                }
                            }
                        }
                    }
                }

                // Set alt text and title
                if ( $img_alt ) {
                    update_post_meta( $att_id, '_wp_attachment_image_alt', $img_alt );
                }
                if ( $img_title ) {
                    wp_update_post( array( 'ID' => $att_id, 'post_title' => $img_title ) );
                }

                $att_url    = wp_get_attachment_url( $att_id );
                $medium_url = '';
                $medium_arr = wp_get_attachment_image_src( $att_id, 'medium' );
                if ( $medium_arr ) {
                    $medium_url = $medium_arr[0];
                }

                $result_data = array(
                    'attachment_id'  => $att_id,
                    'url'            => $att_url,
                    'medium_url'     => $medium_url ? $medium_url : $att_url,
                    'provider'       => $provider,
                    'model'          => $provider === 'gemini' ? ( isset( $gemini_model ) ? $gemini_model : 'gemini' ) : ( isset( $oai_body['model'] ) ? $oai_body['model'] : 'openai' ),
                    'post_processed' => $pp_enabled,
                    'prompt'         => $prompt,
                );
                stifli_flex_mcp_log('wp_generate_image: === SUCCESS === attachment_id=' . $att_id . ' url=' . $att_url . ' provider=' . $provider . ' post_processed=' . ( $pp_enabled ? 'yes' : 'no' ));
                $addResultText( $r, wp_json_encode( $result_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );

                // Also add an image content block for MCP clients that support it
                $r['result']['content'][] = array(
                    'type'     => 'image',
                    'data'     => $medium_url ? $medium_url : $att_url,
                    'mimeType' => $mime_type,
                );
                stifli_flex_mcp_log('wp_generate_image: === END ===');
                break;

            case 'wp_generate_video':
                stifli_flex_mcp_log('wp_generate_video: === START ===');
                // Ensure PHP doesn't kill us during long generation + polling.
                // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, Squiz.PHP.DiscouragedFunctions.Discouraged -- set_time_limit required for video generation (60-300s).
                @set_time_limit( 0 );
                ignore_user_abort( true );
                $vid_prompt = sanitize_text_field( $utils::getArrayValue( $args, 'prompt', '' ) );
                if ( empty( $vid_prompt ) ) {
                    stifli_flex_mcp_log('wp_generate_video: ERROR - prompt is empty');
                    $r['error'] = array( 'code' => -42602, 'message' => 'prompt required' );
                    break;
                }
                if ( ! current_user_can( 'upload_files' ) ) {
                    stifli_flex_mcp_log('wp_generate_video: ERROR - user lacks upload_files capability');
                    $r['error'] = array( 'code' => 'permission_denied', 'message' => 'Insufficient permissions to upload files' );
                    break;
                }

                // Load multimedia settings
                $vid_mm      = get_option( 'sflmcp_multimedia_settings', array() );
                $vid_provider = ! empty( $vid_mm['video_provider'] ) ? $vid_mm['video_provider'] : 'gemini';
                $vid_duration = sanitize_text_field( $utils::getArrayValue( $args, 'duration', '' ) );
                if ( empty( $vid_duration ) ) {
                    $vid_duration = ! empty( $vid_mm['video_duration'] ) ? $vid_mm['video_duration'] : '5';
                }
                $vid_aspect = sanitize_text_field( $utils::getArrayValue( $args, 'aspect_ratio', '' ) );
                if ( empty( $vid_aspect ) ) {
                    $vid_aspect = ! empty( $vid_mm['video_aspect_ratio'] ) ? $vid_mm['video_aspect_ratio'] : '16:9';
                }
                $vid_title   = sanitize_text_field( $utils::getArrayValue( $args, 'title', '' ) );
                $vid_post_id = intval( $utils::getArrayValue( $args, 'post_id', 0 ) );
                $vid_poll    = intval( ! empty( $vid_mm['video_poll_interval'] ) ? $vid_mm['video_poll_interval'] : 10 );
                $vid_max_wait = intval( ! empty( $vid_mm['video_max_wait'] ) ? $vid_mm['video_max_wait'] : 300 );
                stifli_flex_mcp_log('wp_generate_video: prompt="' . substr( $vid_prompt, 0, 120 ) . '" provider=' . $vid_provider . ' duration=' . $vid_duration . 's aspect=' . $vid_aspect . ' poll=' . $vid_poll . 's max_wait=' . $vid_max_wait . 's');

                // ── Resolve optional reference images (source frame + end frame) ──
                $vid_image_url     = sanitize_text_field( $utils::getArrayValue( $args, 'image_url', '' ) );
                $vid_image_end_url = sanitize_text_field( $utils::getArrayValue( $args, 'image_end_url', '' ) );

                /**
                 * Helper: resolve an image reference to base64 + mime.
                 * Accepts a URL (http/https) or a numeric WP attachment ID.
                 * Returns array('data' => base64_string, 'mime' => 'image/jpeg') or null on failure.
                 */
                $resolveImageToBase64 = function ( $ref ) {
                    if ( empty( $ref ) ) {
                        return null;
                    }
                    $binary  = false;
                    $img_mime = 'image/jpeg';

                    if ( is_numeric( $ref ) ) {
                        // WordPress attachment ID
                        $file = get_attached_file( intval( $ref ) );
                        if ( $file && file_exists( $file ) ) {
                            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading local WP attachment for API upload.
                            $binary  = file_get_contents( $file );
                            $img_mime = wp_check_filetype( $file )['type'] ?: 'image/jpeg';
                        }
                    } elseif ( filter_var( $ref, FILTER_VALIDATE_URL ) ) {
                        // External URL
                        $dl = wp_remote_get( $ref, array( 'timeout' => 30 ) );
                        if ( ! is_wp_error( $dl ) && 200 === wp_remote_retrieve_response_code( $dl ) ) {
                            $binary  = wp_remote_retrieve_body( $dl );
                            $ct      = wp_remote_retrieve_header( $dl, 'content-type' );
                            if ( stripos( $ct, 'png' ) !== false ) {
                                $img_mime = 'image/png';
                            } elseif ( stripos( $ct, 'webp' ) !== false ) {
                                $img_mime = 'image/webp';
                            }
                        }
                    }
                    if ( false === $binary || empty( $binary ) ) {
                        return null;
                    }
                    // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- encoding image for AI API.
                    return array( 'data' => base64_encode( $binary ), 'mime' => $img_mime );
                };

                $vid_src_image = $resolveImageToBase64( $vid_image_url );
                $vid_end_image = $resolveImageToBase64( $vid_image_end_url );
                if ( $vid_src_image ) {
                    stifli_flex_mcp_log('wp_generate_video: Source image resolved, mime=' . $vid_src_image['mime'] . ' base64_len=' . strlen( $vid_src_image['data'] ));
                }
                if ( $vid_end_image ) {
                    stifli_flex_mcp_log('wp_generate_video: End image resolved, mime=' . $vid_end_image['mime'] . ' base64_len=' . strlen( $vid_end_image['data'] ));
                }

                // Resolve API key — shared key from multimedia settings (no separate video keys)
                $vid_encrypted_key = '';
                if ( 'gemini' === $vid_provider ) {
                    $vid_encrypted_key = ! empty( $vid_mm['gemini_api_key'] ) ? $vid_mm['gemini_api_key'] : '';
                } else {
                    $vid_encrypted_key = ! empty( $vid_mm['openai_api_key'] ) ? $vid_mm['openai_api_key'] : '';
                }
                $vid_api_key = '';
                if ( ! empty( $vid_encrypted_key ) ) {
                    if ( class_exists( 'StifliFlexMcp_Client_Admin' ) ) {
                        $vid_api_key = StifliFlexMcp_Client_Admin::decrypt_value( $vid_encrypted_key );
                    } else {
                        $vid_api_key = $vid_encrypted_key;
                    }
                }
                if ( empty( $vid_api_key ) ) {
                    stifli_flex_mcp_log('wp_generate_video: ERROR - no API key configured for provider=' . $vid_provider);
                    $r['error'] = array( 'code' => -32603, 'message' => 'No video AI API key configured. Go to StifLi Flex MCP > Multimedia > Videos to set one.' );
                    break;
                }
                stifli_flex_mcp_log('wp_generate_video: API key resolved (length=' . strlen( $vid_api_key ) . ')');

                $vid_binary   = false;
                $vid_mime     = 'video/mp4';
                $vid_gen_err  = '';
                $vid_model_used = '';

                if ( 'gemini' === $vid_provider ) {
                    // ── Google Veo video generation ──
                    $vid_gem_model = ! empty( $vid_mm['video_gemini_model'] ) ? $vid_mm['video_gemini_model'] : 'veo-3.0-generate-preview';
                    $vid_model_used = $vid_gem_model;
                    stifli_flex_mcp_log('wp_generate_video: Using Veo model=' . $vid_gem_model . ' src_image=' . ( $vid_src_image ? 'yes' : 'no' ) . ' end_image=' . ( $vid_end_image ? 'yes' : 'no' ));

                    // Step 1: Submit generation request
                    $veo_api_url = 'https://generativelanguage.googleapis.com/v1beta/models/' . $vid_gem_model . ':predictLongRunning?key=' . $vid_api_key;
                    stifli_flex_mcp_log('wp_generate_video: Submitting Veo generation request...');

                    // Build the instance object — add reference images when provided
                    $veo_instance = array( 'prompt' => $vid_prompt );
                    if ( $vid_src_image ) {
                        $veo_instance['image'] = array(
                            'bytesBase64Encoded' => $vid_src_image['data'],
                            'mimeType'           => $vid_src_image['mime'],
                        );
                    }
                    // End-frame for interpolation (Veo only)
                    if ( $vid_end_image ) {
                        $veo_instance['endImage'] = array(
                            'bytesBase64Encoded' => $vid_end_image['data'],
                            'mimeType'           => $vid_end_image['mime'],
                        );
                    }

                    $veo_params = array(
                        'aspectRatio'     => $vid_aspect,
                        'durationSeconds' => intval( $vid_duration ),
                    );
                    // personGeneration is not supported when using image input
                    if ( empty( $vid_src_image ) && empty( $vid_end_image ) ) {
                        $veo_params['personGeneration'] = 'allow_all';
                    }

                    $veo_body = array(
                        'instances'  => array( $veo_instance ),
                        'parameters' => $veo_params,
                    );
                    $veo_resp = wp_remote_post( $veo_api_url, array(
                        'headers' => array( 'Content-Type' => 'application/json' ),
                        'body'    => wp_json_encode( $veo_body ),
                        'timeout' => 60,
                    ) );
                    if ( is_wp_error( $veo_resp ) ) {
                        $vid_gen_err = 'Veo API error: ' . $veo_resp->get_error_message();
                        stifli_flex_mcp_log('wp_generate_video: Veo WP error: ' . $vid_gen_err);
                    } else {
                        $veo_http = wp_remote_retrieve_response_code( $veo_resp );
                        $veo_json = json_decode( wp_remote_retrieve_body( $veo_resp ), true );
                        stifli_flex_mcp_log('wp_generate_video: Veo submit response HTTP ' . $veo_http);
                        if ( 200 !== $veo_http ) {
                            $vid_gen_err = 'Veo API error (HTTP ' . $veo_http . '): ' . ( isset( $veo_json['error']['message'] ) ? $veo_json['error']['message'] : wp_remote_retrieve_body( $veo_resp ) );
                            stifli_flex_mcp_log('wp_generate_video: ' . $vid_gen_err);
                        } else {
                            // Extract operation name for polling
                            $veo_op_name = isset( $veo_json['name'] ) ? $veo_json['name'] : '';
                            if ( empty( $veo_op_name ) ) {
                                $vid_gen_err = 'Veo did not return an operation name. Response: ' . wp_json_encode( $veo_json );
                                stifli_flex_mcp_log('wp_generate_video: ' . $vid_gen_err);
                            } else {
                                stifli_flex_mcp_log('wp_generate_video: Veo operation started: ' . $veo_op_name);
                            }
                        }
                    }

                    // Step 2: Poll for completion
                    if ( empty( $vid_gen_err ) && ! empty( $veo_op_name ) ) {
                        $veo_poll_url = 'https://generativelanguage.googleapis.com/v1beta/' . $veo_op_name . '?key=' . $vid_api_key;
                        $veo_elapsed  = 0;
                        $veo_done     = false;
                        $veo_result   = null;
                        stifli_flex_mcp_log('wp_generate_video: Starting Veo poll loop (interval=' . $vid_poll . 's, max=' . $vid_max_wait . 's)');

                        while ( $veo_elapsed < $vid_max_wait ) {
                            // phpcs:ignore WordPress.WP.AlternativeFunctions.sleep_sleep -- async poll wait for video generation.
                            sleep( $vid_poll );
                            $veo_elapsed += $vid_poll;

                            $poll_resp = wp_remote_get( $veo_poll_url, array( 'timeout' => 30 ) );
                            if ( is_wp_error( $poll_resp ) ) {
                                continue; // retry
                            }
                            $poll_http = wp_remote_retrieve_response_code( $poll_resp );
                            $poll_json = json_decode( wp_remote_retrieve_body( $poll_resp ), true );

                            if ( 200 !== $poll_http ) {
                                continue; // retry
                            }

                            $veo_is_done = isset( $poll_json['done'] ) && true === $poll_json['done'];
                            if ( $veo_is_done ) {
                                // Check for errors
                                if ( isset( $poll_json['error'] ) ) {
                                    $vid_gen_err = 'Veo generation failed: ' . ( isset( $poll_json['error']['message'] ) ? $poll_json['error']['message'] : wp_json_encode( $poll_json['error'] ) );
                                    stifli_flex_mcp_log('wp_generate_video: Veo operation error: ' . $vid_gen_err);
                                } else {
                                    $veo_result = $poll_json;
                                    stifli_flex_mcp_log('wp_generate_video: Veo operation completed after ' . $veo_elapsed . 's');
                                }
                                $veo_done = true;
                                break;
                            } else {
                                stifli_flex_mcp_log('wp_generate_video: Veo poll ' . $veo_elapsed . 's/' . $vid_max_wait . 's - still processing...');
                            }
                        }

                        if ( ! $veo_done && empty( $vid_gen_err ) ) {
                            $vid_gen_err = 'Veo video generation timed out after ' . $vid_max_wait . ' seconds. The video may still be processing. Operation: ' . $veo_op_name;
                            stifli_flex_mcp_log('wp_generate_video: ' . $vid_gen_err);
                        }
                    }

                    // Step 3: Extract video data
                    if ( empty( $vid_gen_err ) && $veo_result ) {
                        $veo_videos = array();
                        // Response structure varies: response.generateVideoResponse.generatedSamples[] OR response.generatedSamples[]
                        if ( isset( $veo_result['response']['generateVideoResponse']['generatedSamples'] ) ) {
                            $veo_videos = $veo_result['response']['generateVideoResponse']['generatedSamples'];
                        } elseif ( isset( $veo_result['response']['generatedSamples'] ) ) {
                            $veo_videos = $veo_result['response']['generatedSamples'];
                        } elseif ( isset( $veo_result['response']['predictions'] ) ) {
                            $veo_videos = $veo_result['response']['predictions'];
                        }

                        // Check RAI filters at either nesting level
                        $veo_rai = array();
                        if ( isset( $veo_result['response']['generateVideoResponse']['raiMediaFilteredReasons'] ) ) {
                            $veo_rai = $veo_result['response']['generateVideoResponse']['raiMediaFilteredReasons'];
                        } elseif ( isset( $veo_result['response']['raiMediaFilteredReasons'] ) ) {
                            $veo_rai = $veo_result['response']['raiMediaFilteredReasons'];
                        }

                        if ( empty( $veo_videos ) ) {
                            if ( ! empty( $veo_rai[0] ) ) {
                                $vid_gen_err = 'Veo blocked video generation due to safety filters: ' . $veo_rai[0];
                            } else {
                                $vid_gen_err = 'Veo returned no video data. Response: ' . wp_json_encode( $veo_result );
                            }
                            stifli_flex_mcp_log('wp_generate_video: ' . $vid_gen_err);
                        } else {
                            stifli_flex_mcp_log('wp_generate_video: Veo returned ' . count( $veo_videos ) . ' video sample(s)');
                            $first_video = $veo_videos[0];
                            // Try encodedVideo (base64) first, then URI
                            if ( isset( $first_video['video']['encodedVideo'] ) ) {
                                // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- decoding AI-generated video binary.
                                $vid_binary = base64_decode( $first_video['video']['encodedVideo'] );
                                if ( false === $vid_binary ) {
                                    $vid_gen_err = 'Failed to decode Veo base64 video data.';
                                    $vid_binary  = false;
                                }
                            } elseif ( isset( $first_video['video']['uri'] ) ) {
                                // Download from URI — append API key for authenticated access
                                $veo_dl_url = $first_video['video']['uri'];
                                $veo_dl_url .= ( strpos( $veo_dl_url, '?' ) !== false ? '&' : '?' ) . 'key=' . $vid_api_key;
                                stifli_flex_mcp_log('wp_generate_video: Downloading Veo video from URI...');
                                $vid_dl = wp_remote_get( $veo_dl_url, array( 'timeout' => 120 ) );
                                if ( is_wp_error( $vid_dl ) ) {
                                    $vid_gen_err = 'Failed to download Veo video: ' . $vid_dl->get_error_message();
                                } elseif ( 200 !== wp_remote_retrieve_response_code( $vid_dl ) ) {
                                    $vid_gen_err = 'Failed to download Veo video (HTTP ' . wp_remote_retrieve_response_code( $vid_dl ) . ')';
                                } else {
                                    $vid_binary = wp_remote_retrieve_body( $vid_dl );
                                }
                            } elseif ( isset( $first_video['encodedVideo'] ) ) {
                                // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- decoding AI-generated video binary.
                                $vid_binary = base64_decode( $first_video['encodedVideo'] );
                                if ( false === $vid_binary ) {
                                    $vid_gen_err = 'Failed to decode Veo base64 video data.';
                                    $vid_binary  = false;
                                }
                            } elseif ( isset( $first_video['bytesBase64Encoded'] ) ) {
                                // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- decoding AI-generated video binary.
                                $vid_binary = base64_decode( $first_video['bytesBase64Encoded'] );
                                if ( false === $vid_binary ) {
                                    $vid_gen_err = 'Failed to decode Veo base64 video data.';
                                    $vid_binary  = false;
                                }
                            } else {
                                $vid_gen_err = 'Veo video sample has no recognized data field. Keys: ' . implode( ', ', array_keys( $first_video ) );
                            }
                        }
                    }
                } else {
                    // ── OpenAI Sora video generation ──
                    $vid_oai_model = ! empty( $vid_mm['video_openai_model'] ) ? $vid_mm['video_openai_model'] : 'sora-2';
                    $vid_model_used = $vid_oai_model;
                    stifli_flex_mcp_log('wp_generate_video: Using Sora model=' . $vid_oai_model . ' src_image=' . ( $vid_src_image ? 'yes' : 'no' ));

                    // Map aspect ratio to Sora size format (only sizes supported by the API)
                    $sora_size_map = array(
                        '16:9' => '1280x720',
                        '9:16' => '720x1280',
                    );
                    $sora_size = isset( $sora_size_map[ $vid_aspect ] ) ? $sora_size_map[ $vid_aspect ] : '1280x720';

                    // Map duration to Sora allowed seconds: "4", "8", "12"
                    $vid_dur_int    = intval( $vid_duration );
                    $sora_seconds_allowed = array( 4, 8, 12 );
                    $sora_seconds   = '8'; // default
                    $best_diff      = PHP_INT_MAX;
                    foreach ( $sora_seconds_allowed as $s_val ) {
                        $diff = abs( $vid_dur_int - $s_val );
                        if ( $diff < $best_diff ) {
                            $best_diff = $diff;
                            $sora_seconds = (string) $s_val;
                        }
                    }

                    // Step 1: Submit generation request via multipart/form-data
                    $boundary = 'sflmcp' . wp_generate_password( 16, false );
                    $multipart_body = '';

                    // Add text fields
                    $sora_fields = array(
                        'model'   => $vid_oai_model,
                        'prompt'  => $vid_prompt,
                        'seconds' => $sora_seconds,
                        'size'    => $sora_size,
                    );
                    foreach ( $sora_fields as $fname => $fval ) {
                        $multipart_body .= '--' . $boundary . "\r\n";
                        $multipart_body .= 'Content-Disposition: form-data; name="' . $fname . '"' . "\r\n\r\n";
                        $multipart_body .= $fval . "\r\n";
                    }

                    // Image reference (multipart file upload)
                    if ( $vid_src_image ) {
                        $img_ext_map  = array( 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp' );
                        $img_ext      = isset( $img_ext_map[ $vid_src_image['mime'] ] ) ? $img_ext_map[ $vid_src_image['mime'] ] : 'jpg';
                        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- decoding previously encoded image for multipart upload.
                        $img_binary   = base64_decode( $vid_src_image['data'] );

                        // Sora requires input_reference to match requested width x height exactly.
                        $sora_dims  = explode( 'x', $sora_size );
                        $target_w   = intval( $sora_dims[0] );
                        $target_h   = intval( $sora_dims[1] );
                        $gd_src     = @imagecreatefromstring( $img_binary );
                        if ( $gd_src ) {
                            $orig_w = imagesx( $gd_src );
                            $orig_h = imagesy( $gd_src );
                            if ( $orig_w !== $target_w || $orig_h !== $target_h ) {
                                stifli_flex_mcp_log( 'wp_generate_video: Resizing reference image from ' . $orig_w . 'x' . $orig_h . ' to ' . $target_w . 'x' . $target_h );
                                $gd_dst = imagecreatetruecolor( $target_w, $target_h );
                                // Preserve transparency for PNG.
                                imagealphablending( $gd_dst, false );
                                imagesavealpha( $gd_dst, true );
                                imagecopyresampled( $gd_dst, $gd_src, 0, 0, 0, 0, $target_w, $target_h, $orig_w, $orig_h );
                                imagedestroy( $gd_src );
                                // Output as JPEG for smaller size and broader compatibility.
                                ob_start();
                                imagejpeg( $gd_dst, null, 90 );
                                $img_binary = ob_get_clean();
                                imagedestroy( $gd_dst );
                                $img_ext = 'jpg';
                                $vid_src_image['mime'] = 'image/jpeg';
                                stifli_flex_mcp_log( 'wp_generate_video: Resized image size=' . strlen( $img_binary ) . ' bytes' );
                            } else {
                                imagedestroy( $gd_src );
                            }
                        }

                        $multipart_body .= '--' . $boundary . "\r\n";
                        $multipart_body .= 'Content-Disposition: form-data; name="input_reference"; filename="reference.' . $img_ext . '"' . "\r\n";
                        $multipart_body .= 'Content-Type: ' . $vid_src_image['mime'] . "\r\n\r\n";
                        $multipart_body .= $img_binary . "\r\n";
                    }

                    $multipart_body .= '--' . $boundary . '--' . "\r\n";

                    stifli_flex_mcp_log('wp_generate_video: Submitting Sora generation request (model=' . $vid_oai_model . ', size=' . $sora_size . ', seconds=' . $sora_seconds . ', payload=' . strlen( $multipart_body ) . ' bytes)...');
                    // Increase timeout for large payloads (e.g. image file uploads)
                    $sora_submit_timeout = strlen( $multipart_body ) > 500000 ? 120 : 60;
                    $sora_resp = wp_remote_post( 'https://api.openai.com/v1/videos', array(
                        'headers' => array(
                            'Authorization' => 'Bearer ' . $vid_api_key,
                            'Content-Type'  => 'multipart/form-data; boundary=' . $boundary,
                        ),
                        'body'    => $multipart_body,
                        'timeout' => $sora_submit_timeout,
                    ) );
                    $sora_gen_id = '';
                    if ( is_wp_error( $sora_resp ) ) {
                        $vid_gen_err = 'Sora API error: ' . $sora_resp->get_error_message();
                        stifli_flex_mcp_log('wp_generate_video: Sora WP error: ' . $vid_gen_err);
                    } else {
                        $sora_http = wp_remote_retrieve_response_code( $sora_resp );
                        $sora_json = json_decode( wp_remote_retrieve_body( $sora_resp ), true );
                        stifli_flex_mcp_log('wp_generate_video: Sora submit response HTTP ' . $sora_http);
                        if ( $sora_http < 200 || $sora_http >= 300 ) {
                            $sora_err_msg = '';
                            if ( isset( $sora_json['error']['message'] ) ) {
                                $sora_err_msg = $sora_json['error']['message'];
                            } elseif ( is_array( $sora_json ) ) {
                                $sora_err_msg = wp_json_encode( $sora_json );
                            } else {
                                $sora_err_msg = wp_remote_retrieve_body( $sora_resp );
                            }
                            $vid_gen_err = 'Sora API error (HTTP ' . $sora_http . '): ' . $sora_err_msg;
                            stifli_flex_mcp_log('wp_generate_video: ' . $vid_gen_err);
                        } else {
                            $sora_gen_id = isset( $sora_json['id'] ) ? $sora_json['id'] : '';
                            if ( empty( $sora_gen_id ) ) {
                                $vid_gen_err = 'Sora did not return a video ID. Response: ' . wp_json_encode( $sora_json );
                                stifli_flex_mcp_log('wp_generate_video: ' . $vid_gen_err);
                            } else {
                                $sora_status = isset( $sora_json['status'] ) ? $sora_json['status'] : 'unknown';
                                stifli_flex_mcp_log('wp_generate_video: Sora generation started: ID=' . $sora_gen_id . ' status=' . $sora_status);
                            }
                        }
                    }

                    // Step 2: Poll for completion via GET /v1/videos/{video_id}
                    if ( empty( $vid_gen_err ) && ! empty( $sora_gen_id ) ) {
                        $sora_poll_url = 'https://api.openai.com/v1/videos/' . $sora_gen_id;
                        $sora_elapsed  = 0;
                        $sora_done     = false;
                        stifli_flex_mcp_log('wp_generate_video: Starting Sora poll loop (interval=' . $vid_poll . 's, max=' . $vid_max_wait . 's)');

                        while ( $sora_elapsed < $vid_max_wait ) {
                            // phpcs:ignore WordPress.WP.AlternativeFunctions.sleep_sleep -- async poll wait for video generation.
                            sleep( $vid_poll );
                            $sora_elapsed += $vid_poll;

                            $spoll = wp_remote_get( $sora_poll_url, array(
                                'headers' => array( 'Authorization' => 'Bearer ' . $vid_api_key ),
                                'timeout' => 15,
                            ) );
                            if ( is_wp_error( $spoll ) ) {
                                stifli_flex_mcp_log('wp_generate_video: Sora poll error at ' . $sora_elapsed . 's: ' . $spoll->get_error_message());
                                continue;
                            }
                            $spoll_json = json_decode( wp_remote_retrieve_body( $spoll ), true );
                            $spoll_status = isset( $spoll_json['status'] ) ? $spoll_json['status'] : '';
                            $spoll_progress = isset( $spoll_json['progress'] ) ? $spoll_json['progress'] : 0;

                            if ( 'completed' === $spoll_status ) {
                                $sora_done = true;
                                stifli_flex_mcp_log('wp_generate_video: Sora completed after ' . $sora_elapsed . 's');
                                break;
                            } elseif ( 'failed' === $spoll_status ) {
                                $fail_reason = '';
                                if ( isset( $spoll_json['error']['message'] ) ) {
                                    $fail_reason = $spoll_json['error']['message'];
                                } elseif ( isset( $spoll_json['failure_reason'] ) ) {
                                    $fail_reason = $spoll_json['failure_reason'];
                                } else {
                                    $fail_reason = 'Unknown failure';
                                }
                                $vid_gen_err = 'Sora video generation failed: ' . $fail_reason;
                                stifli_flex_mcp_log('wp_generate_video: Sora failed: ' . $vid_gen_err);
                                $sora_done = true;
                                break;
                            }
                            // status is 'in_progress' or 'queued'
                            stifli_flex_mcp_log('wp_generate_video: Sora poll ' . $sora_elapsed . 's/' . $vid_max_wait . 's - status=' . $spoll_status . ' progress=' . $spoll_progress . '%');
                        }

                        if ( ! $sora_done && empty( $vid_gen_err ) ) {
                            $vid_gen_err = 'Sora video generation timed out after ' . $vid_max_wait . ' seconds. Video ID: ' . $sora_gen_id;
                            stifli_flex_mcp_log('wp_generate_video: ' . $vid_gen_err);
                        }
                    }

                    // Step 3: Download the video via GET /v1/videos/{video_id}/content
                    if ( empty( $vid_gen_err ) && ! empty( $sora_gen_id ) && $sora_done ) {
                        $sora_dl_url = 'https://api.openai.com/v1/videos/' . $sora_gen_id . '/content';
                        stifli_flex_mcp_log('wp_generate_video: Downloading Sora video from content endpoint...');
                        $vid_dl = wp_remote_get( $sora_dl_url, array(
                            'headers' => array( 'Authorization' => 'Bearer ' . $vid_api_key ),
                            'timeout' => 120,
                        ) );
                        if ( is_wp_error( $vid_dl ) ) {
                            $vid_gen_err = 'Failed to download Sora video: ' . $vid_dl->get_error_message();
                            stifli_flex_mcp_log('wp_generate_video: ' . $vid_gen_err);
                        } else {
                            $sora_dl_http = wp_remote_retrieve_response_code( $vid_dl );
                            if ( 200 !== $sora_dl_http && 302 !== $sora_dl_http ) {
                                $vid_gen_err = 'Failed to download Sora video (HTTP ' . $sora_dl_http . ')';
                                stifli_flex_mcp_log('wp_generate_video: ' . $vid_gen_err);
                            } else {
                                $vid_binary = wp_remote_retrieve_body( $vid_dl );
                                $vid_ct = wp_remote_retrieve_header( $vid_dl, 'content-type' );
                                if ( stripos( $vid_ct, 'webm' ) !== false ) {
                                    $vid_mime = 'video/webm';
                                }
                                stifli_flex_mcp_log('wp_generate_video: Sora video downloaded, size=' . strlen( $vid_binary ) . ' bytes, content-type=' . $vid_ct);
                            }
                        }
                    }
                }

                // Handle generation error
                if ( false === $vid_binary || empty( $vid_binary ) ) {
                    stifli_flex_mcp_log('wp_generate_video: FAILED - ' . ( $vid_gen_err ? $vid_gen_err : 'unknown error' ));
                    $r['error'] = array( 'code' => -32603, 'message' => $vid_gen_err ? $vid_gen_err : 'Video generation failed.' );
                    break;
                }
                stifli_flex_mcp_log('wp_generate_video: Video binary received, size=' . strlen( $vid_binary ) . ' bytes, mime=' . $vid_mime);

                // Save as WordPress media attachment
                if ( ! function_exists( 'wp_upload_dir' ) ) {
                    require_once ABSPATH . 'wp-admin/includes/file.php';
                }
                if ( ! function_exists( 'media_handle_sideload' ) ) {
                    require_once ABSPATH . 'wp-admin/includes/media.php';
                    require_once ABSPATH . 'wp-admin/includes/image.php';
                }

                $vid_ext_map  = array( 'video/mp4' => 'mp4', 'video/webm' => 'webm' );
                $vid_ext      = isset( $vid_ext_map[ $vid_mime ] ) ? $vid_ext_map[ $vid_mime ] : 'mp4';
                $vid_filename = 'ai-video-' . gmdate( 'Ymd-His' ) . '-' . wp_generate_password( 6, false ) . '.' . $vid_ext;

                $vid_upload_dir = wp_upload_dir();
                $vid_temp_file  = $vid_upload_dir['path'] . '/' . wp_unique_filename( $vid_upload_dir['path'], $vid_filename );

                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- writing temp binary from AI API to create media attachment.
                if ( file_put_contents( $vid_temp_file, $vid_binary ) === false ) {
                    $r['error'] = array( 'code' => 'write_error', 'message' => 'Failed to write video file' );
                    break;
                }

                $vid_file_array = array(
                    'name'     => $vid_filename,
                    'tmp_name' => $vid_temp_file,
                    'type'     => $vid_mime,
                );
                $vid_att_id = media_handle_sideload( $vid_file_array, $vid_post_id );

                if ( is_wp_error( $vid_att_id ) ) {
                    stifli_flex_mcp_log('wp_generate_video: Sideload error: ' . $vid_att_id->get_error_message());
                    wp_delete_file( $vid_temp_file );
                    $r['error'] = array( 'code' => 'upload_error', 'message' => $vid_att_id->get_error_message() );
                    break;
                }
                stifli_flex_mcp_log('wp_generate_video: Saved as attachment ID=' . $vid_att_id);

                // Set title
                if ( $vid_title ) {
                    wp_update_post( array( 'ID' => $vid_att_id, 'post_title' => $vid_title ) );
                }

                $vid_att_url = wp_get_attachment_url( $vid_att_id );

                $vid_result_data = array(
                    'attachment_id'    => $vid_att_id,
                    'url'              => $vid_att_url,
                    'provider'         => $vid_provider,
                    'model'            => $vid_model_used,
                    'duration'         => $vid_duration,
                    'aspect_ratio'     => $vid_aspect,
                    'mime_type'        => $vid_mime,
                    'prompt'           => $vid_prompt,
                    'has_source_image' => ! empty( $vid_src_image ),
                    'has_end_image'    => ! empty( $vid_end_image ),
                );
                stifli_flex_mcp_log('wp_generate_video: === SUCCESS === attachment_id=' . $vid_att_id . ' url=' . $vid_att_url . ' provider=' . $vid_provider . ' model=' . $vid_model_used . ' duration=' . $vid_duration . 's');
                $addResultText( $r, wp_json_encode( $vid_result_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
                stifli_flex_mcp_log('wp_generate_video: === END ===');
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
                // SSRF protection: block requests to private/reserved IP ranges.
                $fetch_host = wp_parse_url( $url, PHP_URL_HOST );
                if ( ! $fetch_host ) { $r['error'] = array('code' => -42602, 'message' => 'Invalid URL: cannot resolve host.'); break; }
                $fetch_ip = gethostbyname( $fetch_host );
                if ( $fetch_ip === $fetch_host && ! filter_var( $fetch_host, FILTER_VALIDATE_IP ) ) {
                    $r['error'] = array('code' => -42602, 'message' => 'Invalid URL: DNS resolution failed.'); break;
                }
                if ( $fetch_ip && ! filter_var( $fetch_ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
                    $r['error'] = array('code' => -42603, 'message' => 'Blocked: target resolves to a private or reserved IP range.'); break;
                }
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

            /* ── Changelog / Audit Log Tools ───────────────────── */

            case 'mcp_get_changelog':
                $tracker  = StifliFlexMcp_ChangeTracker::getInstance();
                $cl_page  = max( 1, intval( $args['page'] ?? 1 ) );
                $cl_pp    = max( 1, min( 100, intval( $args['per_page'] ?? 25 ) ) );
                $cl_f     = array( 'limit' => $cl_pp, 'offset' => ( $cl_page - 1 ) * $cl_pp );
                if ( ! empty( $args['tool_name'] ) )      $cl_f['tool_name']      = sanitize_text_field( $args['tool_name'] );
                if ( ! empty( $args['operation_type'] ) )  $cl_f['operation_type']  = sanitize_key( $args['operation_type'] );
                if ( ! empty( $args['object_type'] ) )     $cl_f['object_type']     = sanitize_key( $args['object_type'] );
                if ( ! empty( $args['date_from'] ) )       $cl_f['date_from']       = sanitize_text_field( $args['date_from'] ) . ' 00:00:00';
                if ( ! empty( $args['date_to'] ) )         $cl_f['date_to']         = sanitize_text_field( $args['date_to'] ) . ' 23:59:59';
                if ( isset( $args['rolled_back'] ) )       $cl_f['rolled_back']     = intval( $args['rolled_back'] );
                $cl_data  = $tracker->getHistory( $cl_f );
                $addResultText( $r, wp_json_encode( array(
                    'page'     => $cl_page,
                    'per_page' => $cl_pp,
                    'total'    => $cl_data['total'],
                    'rows'     => $cl_data['rows'],
                ), JSON_PRETTY_PRINT ) );
                break;

            case 'mcp_get_change_detail':
                $cl_id = intval( $args['id'] ?? 0 );
                if ( ! $cl_id ) {
                    $r['error'] = array( 'code' => -32602, 'message' => 'Missing required parameter: id' );
                    break;
                }
                global $wpdb;
                $cl_tbl = $wpdb->prefix . 'sflmcp_changelog';
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name from $wpdb->prefix is safe.
                $cl_row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$cl_tbl}` WHERE id = %d", $cl_id ), ARRAY_A );
                if ( ! $cl_row ) {
                    $r['error'] = array( 'code' => -32602, 'message' => 'Changelog entry not found.' );
                    break;
                }
                $addResultText( $r, wp_json_encode( $cl_row, JSON_PRETTY_PRINT ) );
                break;

            case 'mcp_rollback_change':
                $cl_id = intval( $args['id'] ?? 0 );
                if ( ! $cl_id ) {
                    $r['error'] = array( 'code' => -32602, 'message' => 'Missing required parameter: id' );
                    break;
                }
                $cl_res = StifliFlexMcp_ChangeTracker::getInstance()->rollback( $cl_id );
                if ( $cl_res['success'] ) {
                    $addResultText( $r, 'Rollback successful: ' . $cl_res['message'] );
                } else {
                    $r['error'] = array( 'code' => -32603, 'message' => $cl_res['message'] );
                }
                break;

            case 'mcp_redo_change':
                $cl_id = intval( $args['id'] ?? 0 );
                if ( ! $cl_id ) {
                    $r['error'] = array( 'code' => -32602, 'message' => 'Missing required parameter: id' );
                    break;
                }
                $cl_res = StifliFlexMcp_ChangeTracker::getInstance()->redo( $cl_id );
                if ( $cl_res['success'] ) {
                    $addResultText( $r, 'Redo successful: ' . $cl_res['message'] );
                } else {
                    $r['error'] = array( 'code' => -32603, 'message' => $cl_res['message'] );
                }
                break;

            case 'mcp_rollback_session':
                $cl_sid = sanitize_text_field( $args['session_id'] ?? '' );
                if ( empty( $cl_sid ) ) {
                    $r['error'] = array( 'code' => -32602, 'message' => 'Missing required parameter: session_id' );
                    break;
                }
                $cl_res = StifliFlexMcp_ChangeTracker::getInstance()->rollbackSession( $cl_sid );
                if ( $cl_res['success'] ) {
                    $addResultText( $r, 'Session rollback complete. ' . $cl_res['message'] );
                } else {
                    $r['error'] = array( 'code' => -32603, 'message' => $cl_res['message'] );
                }
                break;

            default:
                // Try to route to WooCommerce modules if tool starts with wc_
                if ( strpos( $tool, 'wc_' ) === 0 && class_exists( 'WooCommerce' ) ) {
                    // Lazy load WC modules if not already loaded
                    require_once dirname(__FILE__) . '/woocommerce/wc-products.php';
                    require_once dirname(__FILE__) . '/woocommerce/wc-orders.php';
                    require_once dirname(__FILE__) . '/woocommerce/wc-customers-coupons.php';
                    require_once dirname(__FILE__) . '/woocommerce/wc-system.php';
                    
                    // Try WC Products module
                    if ( class_exists( 'StifliFlexMcp_WC_Products' ) ) {
                        $result = StifliFlexMcp_WC_Products::dispatch( $tool, $args, $r, $addResultText, $utils );
                        if ( $result !== null ) {
                            $recordChangeIfNeeded();
                            return $r;
                        }
                    }
                    
                    // Try WC Orders module
                    if ( class_exists( 'StifliFlexMcp_WC_Orders' ) ) {
                        $result = StifliFlexMcp_WC_Orders::dispatch( $tool, $args, $r, $addResultText, $utils );
                        if ( $result !== null ) {
                            $recordChangeIfNeeded();
                            return $r;
                        }
                    }
                    
                    // Try WC Customers module
                    if ( class_exists( 'StifliFlexMcp_WC_Customers' ) ) {
                        $result = StifliFlexMcp_WC_Customers::dispatch( $tool, $args, $r, $addResultText, $utils );
                        if ( $result !== null ) {
                            $recordChangeIfNeeded();
                            return $r;
                        }
                    }
                    
                    // Try WC Coupons module
                    if ( class_exists( 'StifliFlexMcp_WC_Coupons' ) ) {
                        $result = StifliFlexMcp_WC_Coupons::dispatch( $tool, $args, $r, $addResultText, $utils );
                        if ( $result !== null ) {
                            $recordChangeIfNeeded();
                            return $r;
                        }
                    }
                    
                    // Try WC System module
                    if ( class_exists( 'StifliFlexMcp_WC_System' ) ) {
                        $result = StifliFlexMcp_WC_System::dispatch( $tool, $args, $r, $addResultText, $utils );
                        if ( $result !== null ) {
                            $recordChangeIfNeeded();
                            return $r;
                        }
                    }
                }
                
                // Try Snippets module (snippet_* tools)
                if ( strpos( $tool, 'snippet_' ) === 0 ) {
                    require_once dirname(__FILE__) . '/snippets/snippets.php';
                    if ( class_exists( 'StifliFlexMcp_Snippets' ) ) {
                        $result = StifliFlexMcp_Snippets::dispatch( $tool, $args, $r, $addResultText, $utils );
                        if ( $result !== null ) {
                            $recordChangeIfNeeded();
                            return $r;
                        }
                    }
                }

                // Try Custom Tools (from sflmcp_custom_tools table)
                if ( strpos( $tool, 'custom_' ) === 0 ) {
                    $r = $this->dispatchCustomTool( $tool, $args, $id, $r );
                    $recordChangeIfNeeded();
                    return $r;
                }
                
                // Try WordPress Abilities (ability_* tools from sflmcp_abilities table)
                if ( strpos( $tool, 'ability_' ) === 0 ) {
                    $r = $this->dispatchAbility( $tool, $args, $id, $r );
                    $recordChangeIfNeeded();
                    return $r;
                }
                
                // If not handled by any WooCommerce module or unknown tool
                $r['error'] = array('code' => -42609, 'message' => 'Unknown tool');
        }

        // Change Tracker: record change if operation succeeded
        $recordChangeIfNeeded();

        return $r;
    }

    /**
     * Dispatch a WordPress Ability (WordPress 6.9+)
     * 
     * @param string $tool The tool name (ability_*).
     * @param array $args The tool arguments.
     * @param mixed $rpcId The JSON-RPC request ID.
     * @param array $r The result array (passed by reference).
     * @return array The result array.
     */
    private function dispatchAbility( $tool, $args, $rpcId, $r ) {
        // Check if WordPress Abilities API is available
        if ( ! function_exists( 'wp_get_ability' ) ) {
            $r['error'] = array(
                'code' => -32603,
                'message' => 'WordPress Abilities API not available. Requires WordPress 6.9+',
            );
            return $r;
        }

        // Get the original ability name from the tool definition
        $tools = $this->getTools();
        if ( ! isset( $tools[ $tool ] ) || ! isset( $tools[ $tool ]['_ability_name'] ) ) {
            $r['error'] = array(
                'code' => -32602,
                'message' => 'Ability not found or not properly configured',
            );
            return $r;
        }

        $ability_name = $tools[ $tool ]['_ability_name'];
        
        // Use wp_get_ability() to get the specific ability
        $ability = wp_get_ability( $ability_name );
        if ( ! $ability ) {
            $r['error'] = array(
                'code' => -32602,
                'message' => sprintf( 'Ability "%s" not found in registry. The plugin may have been deactivated.', $ability_name ),
            );
            return $r;
        }

        // Check permission - abilities have their own permission_callback
        // The check_permission method may not exist on all ability implementations
        // so we'll let execute() handle permission checking internally

        // Execute the ability with input arguments
        $result = $ability->execute( $args );

        if ( is_wp_error( $result ) ) {
            $r['error'] = array(
                'code' => -32603,
                'message' => $result->get_error_message(),
            );
            return $r;
        }

        // Format successful result
        $result_text = is_array( $result ) ? wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) : (string) $result;
        
        $r['result'] = array(
            'content' => array(
                array(
                    'type' => 'text',
                    'text' => $result_text,
                ),
            ),
        );

        return $r;
    }
}
