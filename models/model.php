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
    public function getIntentForTool(string $name): array {
        // Escritura/mutación
        $WRITE = array(
            'wp_create_post','wp_update_post','wp_delete_post',
            'wp_set_featured_image',
            'wp_create_comment','wp_update_comment','wp_delete_comment',
            'wp_rm_update_post_seo',
            // Yoast SEO write
            'yoast_set_meta',
            // ACF write
            'acf_update_field',
            // Gravity Forms write
            'gf_update_entry',
            // Removed for WordPress.org compliance: wp_create_user, wp_update_user, wp_delete_user
            'wp_upload_image_from_url',
            'wp_upload_image',
            'wp_generate_image',
            'wp_generate_video',
            // Removed: wp_activate_plugin, wp_deactivate_plugin, wp_install_plugin, wp_install_theme, wp_switch_theme (WordPress.org compliance)
            'wp_update_option',
            'wp_update_post_meta','wp_delete_post_meta',
            'wp_create_term','wp_delete_term',
            'wp_update_term',
            'wp_update_term_meta','wp_delete_term_meta',
            'wp_create_nav_menu','wp_add_nav_menu_item','wp_update_nav_menu_item','wp_delete_nav_menu_item','wp_delete_nav_menu',
            'wp_reorder_menu_items',
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
            'wc_create_product_variation','wc_update_product_variation','wc_delete_product_variation','wc_batch_update_variations',
            'wc_create_product_attribute','wc_set_product_attributes',
            'wc_create_product_category','wc_update_product_category','wc_delete_product_category',
            'wc_create_product_tag','wc_update_product_tag','wc_delete_product_tag',
            'wc_create_product_review','wc_update_product_review','wc_delete_product_review',
            'wc_create_order','wc_update_order','wc_delete_order','wc_batch_update_orders',
            'wc_create_order_note','wc_delete_order_note',
            // Removed for WordPress.org compliance: wc_create_customer, wc_update_customer, wc_delete_customer
            'wc_create_coupon','wc_update_coupon','wc_delete_coupon','wc_empty_coupon_trash',
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
            'mcp_rollback_change','mcp_redo_change','mcp_rollback_session',
            
        );

        // Lectura sensible (requiere permisos elevados o toca red externa)
        $SENSITIVE_READ = array(
            'wp_get_option',        // requiere manage_options en dispatch
            'wp_get_plugin_settings', // plugin options may contain secrets
            'wp_get_post_meta',     // requiere manage_options en dispatch
            'wp_get_settings',      // requiere manage_options
            'wp_rm_get_head',
            'wp_rm_get_post_seo',
            // Yoast SEO reads
            'yoast_get_meta',
            'yoast_reindex',
            // ACF reads
            'acf_get_field_groups',
            'acf_get_fields',
            // WPForms reads
            'wpforms_list_forms',
            'wpforms_get_entries',
            // Gravity Forms reads
            'gf_list_forms',
            'gf_get_entries',
            // Forminator reads
            'forminator_list_forms',
            'forminator_get_entries',
            'fetch',                // red externa: tratar como lectura sensible
            // WordPress - Additional sensitive reads
            'wp_get_user_meta',     // user privacy data
            'wp_get_site_health',   // system information
            'wp_get_term_meta',     // may include private/encoded data
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
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name from sanitized helper.
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

            $allowed_by_integration = apply_filters('sflmcp_is_tool_enabled_for_integrations', true, $name, 'list', $tool);
            if (!$allowed_by_integration) {
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
                    'description' => 'Connectivity check with optional lightweight diagnostics. Returns the current GMT time, site info, and optional DNS/reachability details.',
                    'inputSchema' => array(
                        'type' => 'object',
                        'properties' => array(
                            'diagnostics' => array('type' => 'boolean'),
                            'timeout_sec' => array('type' => 'integer'),
                        ),
                        'required' => array(),
                    ),
                ),

                // Posts (lectura)
                'wp_get_posts' => array(
                    'name' => 'wp_get_posts',
                    'description' => 'List posts with filters. Optional enrichments: author, featured media, taxonomies, and pagination metadata.',
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
                            'include_author' => array('type' => 'boolean'),
                            'include_featured_media' => array('type' => 'boolean'),
                            'include_taxonomies' => array('type' => 'boolean'),
                            'include_pagination' => array('type' => 'boolean'),
                        ),
                        'required' => array(),
                    ),
                ),
                'wp_get_post' => array(
                    'name' => 'wp_get_post',
                    'description' => 'Get a single post by ID. Optional enrichments: author, featured media, and taxonomies.',
                    'inputSchema' => array(
                        'type' => 'object',
                        'properties' => array(
                            'ID' => array('type' => 'integer'),
                            'include_author' => array('type' => 'boolean'),
                            'include_featured_media' => array('type' => 'boolean'),
                            'include_taxonomies' => array('type' => 'boolean'),
                        ),
                        'required' => array('ID'),
                    ),
                ),

                // Posts (mutación)
                'wp_create_post' => array(
                    'name' => 'wp_create_post',
                    'description' => 'Create a post. Requires post_title. Optional: post_content, post_status, post_type, post_excerpt, post_author, featured_media (attachment ID), meta_input, post_category, tax_input, etc.',
                    'inputSchema' => array(
                        'type' => 'object',
                        'properties' => array(
                            'post_title'   => array('type' => 'string'),
                            'post_content' => array('type' => 'string'),
                            'post_status'  => array('type' => 'string'),
                            'post_type'    => array('type' => 'string'),
                            'post_excerpt' => array('type' => 'string'),
                            'post_author'  => array('type' => 'integer'),
                            'featured_media' => array('type' => 'integer', 'description' => 'Attachment ID to use as featured image (thumbnail).'),
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
                    'description' => 'Update a post by ID. The "fields" object should use the standard parameters accepted by the WordPress wp_update_post() function. Optional top-level featured_media (attachment ID) sets the featured image.',
                    'inputSchema' => array(
                        'type' => 'object',
                        'properties' => array(
                            'ID' => array('type' => 'integer'),
                            'fields' => array('type' => 'object'),
                            'meta_input' => array('type' => 'object'),
                            'featured_media' => array('type' => 'integer', 'description' => 'Attachment ID to set as featured image.'),
                        ),
                        'required' => array('ID'),
                    ),
                ),
                'wp_set_featured_image' => array(
                    'name' => 'wp_set_featured_image',
                    'description' => 'Set or remove the featured image (post thumbnail) of a post. Pass attachment_id=0 to remove.',
                    'inputSchema' => array(
                        'type' => 'object',
                        'properties' => array(
                            'post_id'       => array('type' => 'integer'),
                            'attachment_id' => array('type' => 'integer', 'description' => 'Attachment post ID. Use 0 to clear the featured image.'),
                        ),
                        'required' => array('post_id', 'attachment_id'),
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
                    'description' => 'List comments. Supports post_id, status, search, limit, offset, paged, after, before, and optional post title or pagination metadata.',
                    'inputSchema' => array(
                        'type' => 'object',
                        'properties' => array(
                            'post_id' => array('type' => 'integer'),
                            'status'  => array('type' => 'string'),
                            'search'  => array('type' => 'string'),
                            'limit'   => array('type' => 'integer'),
                            'offset'  => array('type' => 'integer'),
                            'paged'   => array('type' => 'integer'),
                            'after'   => array('type' => 'string'),
                            'before'  => array('type' => 'string'),
                            'include_post_title' => array('type' => 'boolean'),
                            'include_pagination' => array('type' => 'boolean'),
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
                    'description' => 'Retrieve users (fields: ID, user_login, display_name, roles). Optional enrichments: registration date, avatar URL, post counts, and pagination metadata.',
                    'inputSchema' => array(
                        'type' => 'object',
                        'properties' => array(
                            'search' => array('type' => 'string'),
                            'role'   => array('type' => 'string'),
                            'limit'  => array('type' => 'integer'),
                            'offset' => array('type' => 'integer'),
                            'paged'  => array('type' => 'integer'),
                            'include_registered_date' => array('type' => 'boolean'),
                            'include_avatar_url' => array('type' => 'boolean'),
                            'include_post_counts' => array('type' => 'boolean'),
                            'include_pagination' => array('type' => 'boolean'),
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
                    'description' => 'Create a term in any registered taxonomy (taxonomy and name required). Optional: slug, description, parent. Generalized replacement for wp_create_category and wp_create_tag.',
                    'inputSchema' => array(
                        'type' => 'object',
                        'properties' => array(
                            'taxonomy'    => array('type' => 'string'),
                            'name'        => array('type' => 'string'),
                            'slug'        => array('type' => 'string'),
                            'description' => array('type' => 'string'),
                            'parent'      => array('type' => 'integer'),
                        ),
                        'required' => array('taxonomy','name'),
                    ),
                ),
                'wp_update_term' => array(
                    'name' => 'wp_update_term',
                    'description' => 'Update a term in any registered taxonomy. Generalized replacement for wp_update_category and wp_update_tag.',
                    'inputSchema' => array(
                        'type' => 'object',
                        'properties' => array(
                            'term_id'     => array('type' => 'integer'),
                            'taxonomy'    => array('type' => 'string'),
                            'name'        => array('type' => 'string'),
                            'slug'        => array('type' => 'string'),
                            'description' => array('type' => 'string'),
                            'parent'      => array('type' => 'integer'),
                        ),
                        'required' => array('term_id','taxonomy'),
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
                'wp_get_term_meta' => array(
                    'name' => 'wp_get_term_meta',
                    'description' => 'Get term meta. Provide term_id and optional meta_key. Secrets-like values are redacted in the output.',
                    'inputSchema' => array(
                        'type' => 'object',
                        'properties' => array(
                            'term_id'  => array('type' => 'integer'),
                            'meta_key' => array('type' => 'string'),
                            'single'   => array('type' => 'boolean'),
                        ),
                        'required' => array('term_id'),
                    ),
                ),
                'wp_update_term_meta' => array(
                    'name' => 'wp_update_term_meta',
                    'description' => 'Update term meta (term_id, meta_key, meta_value).',
                    'inputSchema' => array(
                        'type' => 'object',
                        'properties' => array(
                            'term_id'    => array('type' => 'integer'),
                            'meta_key'   => array('type' => 'string'),
                            'meta_value' => array('type' => 'string'),
                        ),
                        'required' => array('term_id','meta_key','meta_value'),
                    ),
                ),
                'wp_delete_term_meta' => array(
                    'name' => 'wp_delete_term_meta',
                    'description' => 'Delete term meta (term_id, meta_key, meta_value optional).',
                    'inputSchema' => array(
                        'type' => 'object',
                        'properties' => array(
                            'term_id'    => array('type' => 'integer'),
                            'meta_key'   => array('type' => 'string'),
                            'meta_value' => array('type' => 'string'),
                        ),
                        'required' => array('term_id','meta_key'),
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
                'wp_reorder_menu_items' => array(
                    'name' => 'wp_reorder_menu_items',
                    'description' => 'Reorder items in a navigation menu in a single operation. Provide menu_id and items array of {item_id, menu_order, parent_id?}. Records previous order for one-click rollback.',
                    'inputSchema' => array(
                        'type' => 'object',
                        'properties' => array(
                            'menu_id' => array('type' => 'integer'),
                            'items'   => array(
                                'type' => 'array',
                                'items' => array(
                                    'type' => 'object',
                                    'properties' => array(
                                        'item_id'    => array('type' => 'integer'),
                                        'menu_order' => array('type' => 'integer'),
                                        'parent_id'  => array('type' => 'integer'),
                                    ),
                                    'required' => array('item_id','menu_order'),
                                ),
                            ),
                        ),
                        'required' => array('menu_id','items'),
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
                'wp_get_plugin_settings' => array(
                    'name' => 'wp_get_plugin_settings',
                    'description' => 'Inspect plugin-related WordPress options safely by plugin slug/prefixes with recursive secret redaction. Requires manage_options.',
                    'inputSchema' => array(
                        'type' => 'object',
                        'properties' => array(
                            'plugin_slug' => array('type' => 'string', 'description' => 'Plugin slug, e.g. stifli-flex-mcp, rank-math, yoast.'),
                            'option_prefixes' => array(
                                'type' => 'array',
                                'items' => array('type' => 'string'),
                                'description' => 'Optional extra option-name prefixes to include.',
                            ),
                            'limit' => array('type' => 'integer', 'description' => 'Max options to return (default 100, hard cap 300).'),
                            'summary' => array('type' => 'boolean', 'description' => 'If true, return summary only.'),
                        ),
                        'required' => array('plugin_slug'),
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
                // wp_delete_option intentionally removed for safety: cannot be reliably undone.
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
                    'description' => 'Search posts with optional post type, author, category, tag, status, date, and sort filters plus paging and pagination metadata (q or query param).',
                    'inputSchema' => array(
                        'type' => 'object',
                        'properties' => array(
                            'q'     => array('type' => 'string'),
                            'limit' => array('type' => 'integer'),
                            'offset' => array('type' => 'integer'),
                            'paged' => array('type' => 'integer'),
                            'post_type' => array('type' => 'string'),
                            'post_status' => array('type' => 'string'),
                            'author' => array('type' => 'integer'),
                            'category' => array('type' => 'string'),
                            'tag' => array('type' => 'string'),
                            'orderby' => array('type' => 'string'),
                            'order' => array('type' => 'string'),
                            'after' => array('type' => 'string'),
                            'before' => array('type' => 'string'),
                            'include_pagination' => array('type' => 'boolean'),
                        ),
                        'required' => array(),
                    ),
                ),
                'fetch' => array(
                    'name' => 'fetch',
                    'description' => 'Fetch a URL using WordPress HTTP API (url required, method optional). Optional controls: query params, custom request/response headers, timeout, redirects, HEAD-only mode, text extraction, and max body bytes.',
                    'inputSchema' => array(
                        'type' => 'object',
                        'properties' => array(
                            'url'     => array('type' => 'string'),
                            'method'  => array('type' => 'string'),
                            'headers' => array('type' => 'object'),
                            'query_params' => array('type' => 'object'),
                            'body'    => array('type' => 'string'),
                            'timeout_sec' => array('type' => 'integer'),
                            'max_redirects' => array('type' => 'integer'),
                            'head_only' => array('type' => 'boolean'),
                            'include_headers' => array('type' => 'boolean'),
                            'include_request_headers' => array('type' => 'boolean'),
                            'extract_text' => array('type' => 'boolean'),
                            'max_bytes' => array('type' => 'integer'),
                            'accept' => array('type' => 'string'),
                            'content_type' => array('type' => 'string'),
                            'user_agent' => array('type' => 'string'),
                        ),
                        'required' => array('url'),
                    ),
                ),

                // Rank Math SEO
                'wp_rm_get_head' => array(
                    'name' => 'wp_rm_get_head',
                    'description' => 'Get rendered SEO head HTML for a URL using Rank Math endpoint. Requires Rank Math Headless CMS Support enabled.',
                    'inputSchema' => array(
                        'type' => 'object',
                        'properties' => array(
                            'url' => array('type' => 'string'),
                        ),
                        'required' => array('url'),
                    ),
                ),
                'wp_rm_get_post_seo' => array(
                    'name' => 'wp_rm_get_post_seo',
                    'description' => 'Get Rank Math SEO post meta fields for a post ID.',
                    'inputSchema' => array(
                        'type' => 'object',
                        'properties' => array(
                            'post_id' => array('type' => 'integer'),
                        ),
                        'required' => array('post_id'),
                    ),
                ),
                'wp_rm_update_post_seo' => array(
                    'name' => 'wp_rm_update_post_seo',
                    'description' => 'Update Rank Math SEO post meta fields for a post ID.',
                    'inputSchema' => array(
                        'type' => 'object',
                        'properties' => array(
                            'post_id' => array('type' => 'integer'),
                            'title' => array('type' => 'string'),
                            'description' => array('type' => 'string'),
                            'focus_keyword' => array('type' => 'string'),
                            'canonical_url' => array('type' => 'string'),
                            'facebook_title' => array('type' => 'string'),
                            'facebook_description' => array('type' => 'string'),
                            'facebook_image' => array('type' => 'string'),
                            'twitter_title' => array('type' => 'string'),
                            'twitter_description' => array('type' => 'string'),
                            'twitter_image' => array('type' => 'string'),
                        ),
                        'required' => array('post_id'),
                    ),
                ),

                // Yoast SEO
                'yoast_get_meta' => array(
                    'name' => 'yoast_get_meta',
                    'description' => 'Get Yoast SEO meta fields for a post (title, description, focus keyword, canonical, robots, OG, Twitter). Requires Yoast SEO plugin active.',
                    'inputSchema' => array(
                        'type' => 'object',
                        'properties' => array(
                            'post_id' => array('type' => 'integer', 'description' => 'Post ID to read Yoast meta from'),
                        ),
                        'required' => array('post_id'),
                    ),
                ),
                'yoast_set_meta' => array(
                    'name' => 'yoast_set_meta',
                    'description' => 'Set Yoast SEO meta fields for a post. Accepts title, description, focus_keyword, canonical, noindex, nofollow, facebook_title, facebook_description, facebook_image, twitter_title, twitter_description, twitter_image. Requires Yoast SEO plugin active.',
                    'inputSchema' => array(
                        'type' => 'object',
                        'properties' => array(
                            'post_id'              => array('type' => 'integer'),
                            'title'                => array('type' => 'string'),
                            'description'          => array('type' => 'string'),
                            'focus_keyword'        => array('type' => 'string'),
                            'canonical'            => array('type' => 'string'),
                            'noindex'              => array('type' => 'boolean'),
                            'nofollow'             => array('type' => 'boolean'),
                            'facebook_title'       => array('type' => 'string'),
                            'facebook_description' => array('type' => 'string'),
                            'facebook_image'       => array('type' => 'string'),
                            'twitter_title'        => array('type' => 'string'),
                            'twitter_description'  => array('type' => 'string'),
                            'twitter_image'        => array('type' => 'string'),
                        ),
                        'required' => array('post_id'),
                    ),
                ),
                'yoast_reindex' => array(
                    'name' => 'yoast_reindex',
                    'description' => 'Clear Yoast SEO indexables cache for a post or for all posts (site-wide). Requires Yoast SEO plugin active.',
                    'inputSchema' => array(
                        'type' => 'object',
                        'properties' => array(
                            'post_id' => array('type' => 'integer', 'description' => 'Post ID to reindex, or omit for site-wide cache clear'),
                        ),
                        'required' => array(),
                    ),
                ),
                // Advanced Custom Fields (ACF)
                'acf_get_field_groups' => array(
                    'name' => 'acf_get_field_groups',
                    'description' => 'List all ACF field groups with their keys, titles and location rules. Requires Advanced Custom Fields plugin active.',
                    'inputSchema' => array(
                        'type' => 'object',
                        'properties' => (object) array(),
                        'required' => array(),
                    ),
                ),
                'acf_get_fields' => array(
                    'name' => 'acf_get_fields',
                    'description' => 'Get ACF field values for a post. Returns field keys, names, types and current values. Requires ACF plugin active.',
                    'inputSchema' => array(
                        'type' => 'object',
                        'properties' => array(
                            'post_id' => array('type' => 'integer', 'description' => 'Post ID to read ACF fields from'),
                        ),
                        'required' => array('post_id'),
                    ),
                ),
                'acf_update_field' => array(
                    'name' => 'acf_update_field',
                    'description' => 'Update an ACF field value for a post by field name or key. Requires ACF plugin active.',
                    'inputSchema' => array(
                        'type' => 'object',
                        'properties' => array(
                            'post_id'     => array('type' => 'integer'),
                            'field_name'  => array('type' => 'string', 'description' => 'ACF field name or key'),
                            'value'       => array('description' => 'New field value (string, number, boolean, array)'),
                        ),
                        'required' => array('post_id', 'field_name', 'value'),
                    ),
                ),

                // WPForms
                'wpforms_list_forms' => array(
                    'name' => 'wpforms_list_forms',
                    'description' => 'List all WPForms forms (ID, title, status, created date). Requires WPForms plugin active.',
                    'inputSchema' => array(
                        'type' => 'object',
                        'properties' => array(
                            'limit'  => array('type' => 'integer', 'description' => 'Max forms to return (default 50)'),
                            'offset' => array('type' => 'integer'),
                        ),
                        'required' => array(),
                    ),
                ),
                'wpforms_get_entries' => array(
                    'name' => 'wpforms_get_entries',
                    'description' => 'Get form entries for a WPForms form. Returns entry ID, date, fields and status. Requires WPForms plugin active.',
                    'inputSchema' => array(
                        'type' => 'object',
                        'properties' => array(
                            'form_id' => array('type' => 'integer'),
                            'limit'   => array('type' => 'integer', 'description' => 'Max entries (default 20)'),
                            'offset'  => array('type' => 'integer'),
                            'status'  => array('type' => 'string', 'description' => 'Entry status: active, spam, trash or empty for all'),
                        ),
                        'required' => array('form_id'),
                    ),
                ),

                // Gravity Forms
                'gf_list_forms' => array(
                    'name' => 'gf_list_forms',
                    'description' => 'List all Gravity Forms (ID, title, description, entry count). Requires Gravity Forms plugin active.',
                    'inputSchema' => array(
                        'type' => 'object',
                        'properties' => array(
                            'active' => array('type' => 'boolean', 'description' => 'Filter by active status'),
                        ),
                        'required' => array(),
                    ),
                ),
                'gf_get_entries' => array(
                    'name' => 'gf_get_entries',
                    'description' => 'Get entries for a Gravity Forms form. Requires Gravity Forms plugin active.',
                    'inputSchema' => array(
                        'type' => 'object',
                        'properties' => array(
                            'form_id'    => array('type' => 'integer'),
                            'page_size'  => array('type' => 'integer', 'description' => 'Entries per page (default 20)'),
                            'offset'     => array('type' => 'integer'),
                            'status'     => array('type' => 'string', 'description' => 'active, spam, trash or empty for all'),
                            'search_value' => array('type' => 'string'),
                            'field_id'   => array('type' => 'string', 'description' => 'Field ID for search filter'),
                        ),
                        'required' => array('form_id'),
                    ),
                ),
                'gf_update_entry' => array(
                    'name' => 'gf_update_entry',
                    'description' => 'Update a Gravity Forms entry (status, is_read, is_starred, or field values). Requires Gravity Forms plugin active.',
                    'inputSchema' => array(
                        'type' => 'object',
                        'properties' => array(
                            'entry_id'     => array('type' => 'integer'),
                            'status'       => array('type' => 'string', 'description' => 'active, spam, trash'),
                            'is_read'      => array('type' => 'boolean'),
                            'is_starred'   => array('type' => 'boolean'),
                            'field_values' => array('type' => 'object', 'description' => 'Object of field_id => value to update'),
                        ),
                        'required' => array('entry_id'),
                    ),
                ),

                // Forminator
                'forminator_list_forms' => array(
                    'name' => 'forminator_list_forms',
                    'description' => 'List all Forminator forms (custom forms, polls, quizzes). Requires Forminator plugin active.',
                    'inputSchema' => array(
                        'type' => 'object',
                        'properties' => array(
                            'type'  => array('type' => 'string', 'description' => 'Form type: custom-forms (default), poll, quiz'),
                            'limit' => array('type' => 'integer'),
                        ),
                        'required' => array(),
                    ),
                ),
                'forminator_get_entries' => array(
                    'name' => 'forminator_get_entries',
                    'description' => 'Get submission entries for a Forminator form. Requires Forminator plugin active.',
                    'inputSchema' => array(
                        'type' => 'object',
                        'properties' => array(
                            'form_id' => array('type' => 'integer'),
                            'per_page' => array('type' => 'integer', 'description' => 'Entries per page (default 20)'),
                            'page'    => array('type' => 'integer'),
                        ),
                        'required' => array('form_id'),
                    ),
                ),

                // AI Image Generation
                'wp_generate_image' => array(
                    'name' => 'wp_generate_image',
                    'description' => 'Generate an image using AI and save it as a WordPress media attachment. Uses the configured AI provider (OpenAI/Gemini). Returns attachment ID, URL and medium-size URL. Supports size (square, landscape, portrait or aspect ratio like 16:9) and quality (low, medium, high for OpenAI).',
                    'execution' => array(
                        'taskSupport' => 'optional',
                    ),
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
                    'execution' => array(
                        'taskSupport' => 'optional',
                    ),
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
                    'description' => 'Run a WordPress site audit with selectable depth. Level 0: basic, fast checks. Level 1: medium, all direct Site Health checks. Level 2: deep, adds async checks and storage scan.',
                    'inputSchema' => array(
                        'type' => 'object',
                        'properties' => array(
                            'level' => array(
                                'type' => 'integer',
                                'description' => 'Audit depth. 0 = basic and fast. 1 = medium with all direct Site Health checks. 2 = deep with async Site Health checks and directory sizes. Default: 0.',
                                'minimum' => 0,
                                'maximum' => 2,
                            ),
                        ),
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
            $input_schema = StifliFlexMcpUtils::normalizeToolInputSchema( $input_schema );
            
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
            'wp_set_featured_image' => 'edit_posts',
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
            'wp_get_plugin_settings' => 'manage_options',
            'wp_update_post_meta' => 'manage_options',
            'wp_delete_post_meta' => 'manage_options',
            'wp_get_settings' => 'manage_options',
            'wp_update_settings' => 'manage_options',
            // terms
            'wp_create_term' => 'manage_categories',
            'wp_update_term' => 'manage_categories',
            'wp_delete_term' => 'manage_categories',
            // term meta
            'wp_get_term_meta'    => 'manage_categories',
            'wp_update_term_meta' => 'manage_categories',
            'wp_delete_term_meta' => 'manage_categories',
            // menús
            'wp_create_nav_menu' => 'edit_theme_options',
            'wp_add_nav_menu_item' => 'edit_theme_options',
            'wp_update_nav_menu_item' => 'edit_theme_options',
            'wp_delete_nav_menu_item' => 'edit_theme_options',
            'wp_delete_nav_menu' => 'edit_theme_options',
            'wp_reorder_menu_items' => 'edit_theme_options',
            // Changelog
            'mcp_get_changelog' => 'manage_options',
            'mcp_get_change_detail' => 'manage_options',
            'mcp_rollback_change' => 'manage_options',
            'mcp_redo_change' => 'manage_options',
            'mcp_rollback_session' => 'manage_options',
            // Rank Math
            'wp_rm_get_head' => 'edit_posts',
            'wp_rm_get_post_seo' => 'edit_posts',
            'wp_rm_update_post_seo' => 'edit_posts',
            // Yoast SEO
            'yoast_get_meta' => 'edit_posts',
            'yoast_set_meta' => 'edit_posts',
            'yoast_reindex' => 'manage_options',
            // ACF
            'acf_get_field_groups' => 'edit_posts',
            'acf_get_fields' => 'edit_posts',
            'acf_update_field' => 'edit_posts',
            // WPForms
            'wpforms_list_forms' => 'manage_options',
            'wpforms_get_entries' => 'manage_options',
            // Gravity Forms
            'gf_list_forms' => 'manage_options',
            'gf_get_entries' => 'manage_options',
            'gf_update_entry' => 'manage_options',
            // Forminator
            'forminator_list_forms' => 'manage_options',
            'forminator_get_entries' => 'manage_options',
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
        $isTruthy = function($value) {
            if (is_bool($value)) {
                return $value;
            }
            if (is_int($value)) {
                return 1 === $value;
            }
            if (is_string($value)) {
                return in_array(strtolower(trim($value)), array('1', 'true', 'yes', 'on'), true);
            }
            return !empty($value);
        };
        $buildPaginationMeta = function($totalItems, $limit, $offset = 0, $paged = 1) {
            $limit = max(1, (int) $limit);
            $offset = max(0, (int) $offset);
            $paged = max(1, (int) $paged);
            $currentPage = $offset > 0 ? (int) floor($offset / $limit) + 1 : $paged;
            return array(
                'total_items' => (int) $totalItems,
                'per_page' => $limit,
                'offset' => $offset,
                'current_page' => $currentPage,
                'total_pages' => (int) ceil(max(0, (int) $totalItems) / $limit),
                'has_more' => ($offset + $limit) < (int) $totalItems,
            );
        };
        $buildPostAuthor = function($postObj) {
            $authorId = isset($postObj->post_author) ? (int) $postObj->post_author : 0;
            if ($authorId <= 0) {
                return null;
            }
            $author = get_userdata($authorId);
            if (!$author) {
                return array('ID' => $authorId);
            }
            return array(
                'ID' => $author->ID,
                'user_login' => $author->user_login,
                'display_name' => $author->display_name,
            );
        };
        $buildFeaturedMedia = function($postId) {
            $attachmentId = (int) get_post_thumbnail_id($postId);
            if ($attachmentId <= 0) {
                return null;
            }
            return array(
                'ID' => $attachmentId,
                'url' => wp_get_attachment_url($attachmentId),
                'alt_text' => get_post_meta($attachmentId, '_wp_attachment_image_alt', true),
            );
        };
        $buildPostTaxonomies = function($postObj) {
            $postId = isset($postObj->ID) ? (int) $postObj->ID : 0;
            $postType = isset($postObj->post_type) ? $postObj->post_type : get_post_type($postId);
            $taxonomies = get_object_taxonomies($postType, 'objects');
            if (empty($taxonomies) || !is_array($taxonomies)) {
                return array();
            }
            $summary = array();
            foreach ($taxonomies as $taxonomy) {
                if (empty($taxonomy->name)) {
                    continue;
                }
                $terms = get_the_terms($postId, $taxonomy->name);
                if (is_wp_error($terms) || empty($terms)) {
                    continue;
                }
                $summary[$taxonomy->name] = array();
                foreach ($terms as $term) {
                    $summary[$taxonomy->name][] = array(
                        'term_id' => $term->term_id,
                        'name' => $term->name,
                        'slug' => $term->slug,
                    );
                }
            }
            return $summary;
        };
        $sanitizeQueryParams = function($params) {
            $normalized = array();
            if (!is_array($params)) {
                return $normalized;
            }
            foreach ($params as $paramName => $paramValue) {
                $cleanName = preg_replace('/[^A-Za-z0-9_\-\.\[\]]/', '', (string) $paramName);
                if ('' === $cleanName) {
                    continue;
                }
                if (is_array($paramValue)) {
                    $cleanValues = array();
                    foreach ($paramValue as $value) {
                        if (is_scalar($value) || null === $value) {
                            $cleanValues[] = sanitize_text_field((string) $value);
                        }
                    }
                    if (!empty($cleanValues)) {
                        $normalized[$cleanName] = $cleanValues;
                    }
                    continue;
                }
                if (is_scalar($paramValue) || null === $paramValue) {
                    $normalized[$cleanName] = sanitize_text_field((string) $paramValue);
                }
            }
            return $normalized;
        };
        $sanitizeHeaderMap = function($headers) {
            $normalized = array();
            if (!is_array($headers)) {
                return $normalized;
            }
            foreach ($headers as $headerName => $headerValue) {
                $cleanName = preg_replace('/[^A-Za-z0-9\-]/', '', (string) $headerName);
                if ('' === $cleanName) {
                    continue;
                }
                if (is_array($headerValue)) {
                    $parts = array();
                    foreach ($headerValue as $value) {
                        if (is_scalar($value) || null === $value) {
                            $parts[] = trim(str_replace(array("\r", "\n"), ' ', sanitize_text_field((string) $value)));
                        }
                    }
                    $cleanValue = implode(', ', array_filter($parts, function($part) {
                        return '' !== $part;
                    }));
                } elseif (is_scalar($headerValue) || null === $headerValue) {
                    $cleanValue = trim(str_replace(array("\r", "\n"), ' ', sanitize_text_field((string) $headerValue)));
                } else {
                    $cleanValue = '';
                }
                if ('' === $cleanValue) {
                    continue;
                }
                $normalized[$cleanName] = $cleanValue;
            }
            return $normalized;
        };
        $hasHeaderName = function(array $headers, $headerName) {
            foreach (array_keys($headers) as $existingHeaderName) {
                if (0 === strcasecmp((string) $existingHeaderName, (string) $headerName)) {
                    return true;
                }
            }
            return false;
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

        $allowed_by_integration = apply_filters('sflmcp_is_tool_enabled_for_integrations', true, $tool, 'call', null);
        if (!$allowed_by_integration) {
            return array(
                'jsonrpc' => '2.0',
                'id' => $id,
                'error' => array(
                    'code' => -42609,
                    'message' => 'Tool is disabled by plugin integration settings',
                ),
            );
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
                $diagnosticsEnabled = $isTruthy($utils::getArrayValue($args, 'diagnostics', false));
                $timeoutSec = max(1, min(10, intval($utils::getArrayValue($args, 'timeout_sec', 3, 1))));
                $homeUrl = home_url('/');
                $restUrl = get_rest_url(null, '/');
                $pingData = array(
                    'time' => gmdate('Y-m-d H:i:s'),
                    'name' => get_bloginfo('name'),
                    'home_url' => $homeUrl,
                    'rest_url' => $restUrl,
                    'https' => is_ssl(),
                    'wordpress_version' => get_bloginfo('version'),
                    'php_version' => PHP_VERSION,
                );

                if ($diagnosticsEnabled) {
                    $host = (string) wp_parse_url($homeUrl, PHP_URL_HOST);
                    $resolvedIp = '';
                    if ('' !== $host) {
                        $resolvedIp = (string) gethostbyname($host);
                    }
                    $dnsResolved = '' !== $resolvedIp && ($resolvedIp !== $host || filter_var($host, FILTER_VALIDATE_IP));

                    $pingData['diagnostics'] = array(
                        'host' => $host,
                        'resolved_ip' => $resolvedIp,
                        'dns_resolved' => (bool) $dnsResolved,
                        'timeout_sec' => $timeoutSec,
                    );

                    $homeResponse = wp_remote_head($homeUrl, array('timeout' => $timeoutSec, 'redirection' => 3));
                    if (is_wp_error($homeResponse)) {
                        $pingData['diagnostics']['home_request'] = array(
                            'ok' => false,
                            'message' => $homeResponse->get_error_message(),
                        );
                    } else {
                        $pingData['diagnostics']['home_request'] = array(
                            'ok' => true,
                            'status' => (int) wp_remote_retrieve_response_code($homeResponse),
                        );
                    }

                    $restResponse = wp_remote_get($restUrl, array('timeout' => $timeoutSec, 'redirection' => 3));
                    if (is_wp_error($restResponse)) {
                        $pingData['diagnostics']['rest_request'] = array(
                            'ok' => false,
                            'message' => $restResponse->get_error_message(),
                        );
                    } else {
                        $pingData['diagnostics']['rest_request'] = array(
                            'ok' => true,
                            'status' => (int) wp_remote_retrieve_response_code($restResponse),
                        );
                    }
                }

                $addResultText($r, 'Ping successful: ' . wp_json_encode($pingData, JSON_PRETTY_PRINT));
                break;
            case 'wp_get_posts':
                $postsLimit = max(1, intval($utils::getArrayValue($args, 'limit', 10, 1)));
                $postsPaged = max(1, intval($utils::getArrayValue($args, 'paged', 1, 1)));
                $hasPostsOffset = array_key_exists('offset', $args);
                $postsOffset = $hasPostsOffset ? max(0, intval($args['offset'])) : null;
                // WP_Query prioritizes offset over paged; offset=0 should not force page 1 forever.
                $usePostsOffset = null !== $postsOffset && $postsOffset > 0;
                $includePostAuthor = $isTruthy($utils::getArrayValue($args, 'include_author', false));
                $includePostFeaturedMedia = $isTruthy($utils::getArrayValue($args, 'include_featured_media', false));
                $includePostTaxonomies = $isTruthy($utils::getArrayValue($args, 'include_taxonomies', false));
                $includePostsPagination = $isTruthy($utils::getArrayValue($args, 'include_pagination', false));
                $q = array(
                    'post_type' => sanitize_key($utils::getArrayValue($args, 'post_type', 'post')),
                    'post_status' => sanitize_key($utils::getArrayValue($args, 'post_status', 'publish')),
                    'posts_per_page' => $postsLimit,
                    'no_found_rows' => !$includePostsPagination,
                );
                $postsSearch = sanitize_text_field($utils::getArrayValue($args, 'search'));
                if ('' !== $postsSearch) {
                    $q['s'] = $postsSearch;
                }
                if ($usePostsOffset) {
                    $q['offset'] = $postsOffset;
                } else {
                    $q['paged'] = $postsPaged;
                }
                $date = array();
                if (!empty($args['after'])) {
                    $date['after'] = sanitize_text_field($args['after']);
                }
                if (!empty($args['before'])) {
                    $date['before'] = sanitize_text_field($args['before']);
                }
                if ($date) {
                    $q['date_query'] = array($date);
                }
                $postQuery = new WP_Query($q);
                $rows = array();
                foreach ($postQuery->posts as $p) {
                    $row = array(
                        'ID' => $p->ID,
                        'post_type' => $p->post_type,
                        'post_title' => $p->post_title,
                        'post_status' => $p->post_status,
                        'post_excerpt' => $postExcerpt($p),
                        'permalink' => get_permalink($p),
                    );
                    if ($includePostAuthor) {
                        $row['author'] = $buildPostAuthor($p);
                    }
                    if ($includePostFeaturedMedia) {
                        $row['featured_media'] = $buildFeaturedMedia($p->ID);
                    }
                    if ($includePostTaxonomies) {
                        $row['taxonomies'] = $buildPostTaxonomies($p);
                    }
                    $rows[] = $row;
                }
                if ($includePostsPagination) {
                    $effectivePostsOffset = $usePostsOffset ? $postsOffset : (($postsPaged - 1) * $postsLimit);
                    $rows = array(
                        'items' => $rows,
                        'pagination' => $buildPaginationMeta((int) $postQuery->found_posts, $postsLimit, $effectivePostsOffset, $postsPaged),
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
                $includeSingleAuthor = $isTruthy($utils::getArrayValue($args, 'include_author', false));
                $includeSingleFeaturedMedia = $isTruthy($utils::getArrayValue($args, 'include_featured_media', false));
                $includeSingleTaxonomies = $isTruthy($utils::getArrayValue($args, 'include_taxonomies', false));
                $out = array(
                    'ID' => $p->ID,
                    'post_type' => $p->post_type,
                    'post_title' => $p->post_title,
                    'post_status' => $p->post_status,
                    'post_content' => $cleanHtml($p->post_content),
                    'post_excerpt' => $postExcerpt($p),
                    'permalink' => get_permalink($p),
                    'post_date' => $p->post_date,
                    'post_modified' => $p->post_modified,
                );
                if ($includeSingleAuthor) {
                    $out['author'] = $buildPostAuthor($p);
                }
                if ($includeSingleFeaturedMedia) {
                    $out['featured_media'] = $buildFeaturedMedia($p->ID);
                }
                if ($includeSingleTaxonomies) {
                    $out['taxonomies'] = $buildPostTaxonomies($p);
                }
                $addResultText($r, wp_json_encode($out, JSON_PRETTY_PRINT));
                break;
            case 'wp_create_post':
                if (empty($args['post_title'])) {
                    $r['error'] = array('code' => -42602, 'message' => 'post_title required');
                    break;
                }
                $cp_post_type = sanitize_key($utils::getArrayValue($args, 'post_type', 'post'));
                if (!post_type_exists($cp_post_type)) {
                    $r['error'] = array('code' => -42600, 'message' => 'Unknown post_type: ' . $cp_post_type);
                    break;
                }
                $cp_pt_obj = get_post_type_object($cp_post_type);
                if ($cp_pt_obj && empty($cp_pt_obj->public) && empty($cp_pt_obj->show_ui)) {
                    $r['error'] = array('code' => -42600, 'message' => 'post_type "' . $cp_post_type . '" is not exposed via UI/public.');
                    break;
                }
                $cp_create_cap = ($cp_pt_obj && !empty($cp_pt_obj->cap->edit_posts)) ? $cp_pt_obj->cap->edit_posts : 'edit_posts';
                if (!current_user_can($cp_create_cap)) {
                    $r['error'] = array('code' => 'permission_denied', 'message' => 'Insufficient permissions to create ' . $cp_post_type);
                    break;
                }
                $ins = array(
                    'post_title' => sanitize_text_field($args['post_title']),
                    'post_status' => sanitize_key($utils::getArrayValue($args, 'post_status', 'draft')),
                    'post_type' => $cp_post_type,
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
                if (isset($args['post_author'])) {
                    $cp_author_id = intval($args['post_author']);
                    if ($cp_author_id <= 0 || !get_userdata($cp_author_id)) {
                        $r['error'] = array('code' => -42600, 'message' => 'post_author user not found.');
                        break;
                    }
                    $cp_others_cap = ($cp_pt_obj && !empty($cp_pt_obj->cap->edit_others_posts)) ? $cp_pt_obj->cap->edit_others_posts : 'edit_others_posts';
                    if ($cp_author_id !== get_current_user_id() && !current_user_can($cp_others_cap)) {
                        $r['error'] = array('code' => 'permission_denied', 'message' => 'Insufficient permissions to assign post_author of ' . $cp_post_type);
                        break;
                    }
                    $ins['post_author'] = $cp_author_id;
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
                    // Featured image (post thumbnail).
                    if ( isset( $args['featured_media'] ) ) {
                        $att_id = intval( $args['featured_media'] );
                        if ( $att_id > 0 ) {
                            $att = get_post( $att_id );
                            if ( $att && 'attachment' === $att->post_type ) {
                                set_post_thumbnail( $new, $att_id );
                            }
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
                $up_id = intval($args['ID']);
                $up_existing = get_post($up_id);
                if (!$up_existing) {
                    $r['error'] = array('code' => -42600, 'message' => 'Post not found');
                    break;
                }
                $up_pt_obj = get_post_type_object($up_existing->post_type);
                $c = array('ID' => $up_id);
                if (!empty($args['fields']) && is_array($args['fields'])) {
                    foreach ($args['fields'] as $k => $v) {
                        // Validate post_type change
                        if ('post_type' === $k) {
                            $new_pt = sanitize_key($v);
                            if (!post_type_exists($new_pt)) {
                                $r['error'] = array('code' => -42600, 'message' => 'Unknown post_type: ' . $new_pt);
                                break 2;
                            }
                            $new_pt_obj = get_post_type_object($new_pt);
                            if ($new_pt_obj && empty($new_pt_obj->public) && empty($new_pt_obj->show_ui)) {
                                $r['error'] = array('code' => -42600, 'message' => 'post_type "' . $new_pt . '" is not exposed via UI/public.');
                                break 2;
                            }
                            $c[$k] = $new_pt;
                            continue;
                        }
                        // Validate post_author change
                        if ('post_author' === $k) {
                            $up_author_id = intval($v);
                            if ($up_author_id <= 0 || !get_userdata($up_author_id)) {
                                $r['error'] = array('code' => -42600, 'message' => 'post_author user not found.');
                                break 2;
                            }
                            $up_others_cap = ($up_pt_obj && !empty($up_pt_obj->cap->edit_others_posts)) ? $up_pt_obj->cap->edit_others_posts : 'edit_others_posts';
                            if ($up_author_id !== get_current_user_id() && !current_user_can($up_others_cap, $up_id)) {
                                $r['error'] = array('code' => 'permission_denied', 'message' => 'Insufficient permissions to reassign post_author.');
                                break 2;
                            }
                            $c[$k] = $up_author_id;
                            continue;
                        }
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
                if ( isset( $args['featured_media'] ) ) {
                    $att_id = intval( $args['featured_media'] );
                    if ( $att_id > 0 ) {
                        $att = get_post( $att_id );
                        if ( $att && 'attachment' === $att->post_type ) {
                            set_post_thumbnail( $u, $att_id );
                        }
                    } else {
                        delete_post_thumbnail( $u );
                    }
                }
                $addResultText($r, 'Post #' . $u . ' updated');
                break;
            case 'wp_set_featured_image':
                $post_id = intval( $utils::getArrayValue( $args, 'post_id', 0 ) );
                if ( ! $post_id ) {
                    $r['error'] = array( 'code' => -42602, 'message' => 'post_id required' );
                    break;
                }
                $post_obj = get_post( $post_id );
                if ( ! $post_obj ) {
                    $r['error'] = array( 'code' => -42600, 'message' => 'Post not found' );
                    break;
                }
                if ( ! current_user_can( 'edit_post', $post_id ) ) {
                    $r['error'] = array( 'code' => 'permission_denied', 'message' => 'Insufficient permissions to edit this post.' );
                    break;
                }
                $att_id = intval( $utils::getArrayValue( $args, 'attachment_id', 0 ) );
                if ( $att_id > 0 ) {
                    $att = get_post( $att_id );
                    if ( ! $att || 'attachment' !== $att->post_type ) {
                        $r['error'] = array( 'code' => -42600, 'message' => 'Attachment not found' );
                        break;
                    }
                    $ok = set_post_thumbnail( $post_id, $att_id );
                    if ( $ok ) {
                        $addResultText( $r, 'Featured image set: post #' . $post_id . ' -> attachment #' . $att_id );
                    } else {
                        $r['error'] = array( 'code' => -42603, 'message' => 'Failed to set featured image' );
                    }
                } else {
                    delete_post_thumbnail( $post_id );
                    $addResultText( $r, 'Featured image cleared for post #' . $post_id );
                }
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
                $commentsLimit = max(1, $utils::getArrayValue($args, 'limit', 10, 1));
                $commentsPaged = max(1, intval($utils::getArrayValue($args, 'paged', 1, 1)));
                $commentsOffset = isset($args['offset']) ? max(0, intval($args['offset'])) : (($commentsPaged - 1) * $commentsLimit);
                $includeCommentPostTitle = $isTruthy($utils::getArrayValue($args, 'include_post_title', false));
                $includeCommentsPagination = $isTruthy($utils::getArrayValue($args, 'include_pagination', false));
                $cargs = array(
                    'status' => $utils::getArrayValue($args, 'status', 'approve'),
                    'number' => $commentsLimit,
                    'offset' => $commentsOffset,
                );
                $commentsPostId = $utils::getArrayValue($args, 'post_id', 0, 1);
                if (!empty($commentsPostId)) {
                    $cargs['post_id'] = $commentsPostId;
                }
                $commentsSearch = sanitize_text_field($utils::getArrayValue($args, 'search'));
                if ('' !== $commentsSearch) {
                    $cargs['search'] = $commentsSearch;
                }
                $commentsDate = array();
                if (!empty($args['after'])) {
                    $commentsDate['after'] = sanitize_text_field($args['after']);
                }
                if (!empty($args['before'])) {
                    $commentsDate['before'] = sanitize_text_field($args['before']);
                }
                if (!empty($commentsDate)) {
                    $cargs['date_query'] = array($commentsDate);
                }
                $list = array();
                foreach (get_comments($cargs) as $c) {
                    // Mask author email and IP for privacy/GDPR; full data is
                    // available natively in WP admin to users with the cap.
                    $row = array(
                        'comment_ID' => $c->comment_ID,
                        'comment_post_ID' => $c->comment_post_ID,
                        'comment_author' => $c->comment_author,
                        'comment_author_email' => StifliFlexMcpUtils::maskEmail( (string) $c->comment_author_email ),
                        'comment_author_IP' => StifliFlexMcpUtils::maskIp( (string) $c->comment_author_IP ),
                        'comment_content' => wp_trim_words(wp_strip_all_tags($c->comment_content), 40),
                        'comment_date' => $c->comment_date,
                        'comment_approved' => $c->comment_approved,
                    );
                    if ($includeCommentPostTitle) {
                        $row['post_title'] = get_the_title($c->comment_post_ID);
                    }
                    $list[] = $row;
                }
                if ($includeCommentsPagination) {
                    $countArgs = $cargs;
                    unset($countArgs['number'], $countArgs['offset']);
                    $countArgs['count'] = true;
                    $list = array(
                        'items' => $list,
                        'pagination' => $buildPaginationMeta((int) get_comments($countArgs), $commentsLimit, $commentsOffset, $commentsPaged),
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
                $usersLimit = max(1, intval($utils::getArrayValue($args, 'limit', 10, 1)));
                $usersPaged = max(1, intval($utils::getArrayValue($args, 'paged', 1, 1)));
                $usersOffset = isset($args['offset']) ? max(0, intval($args['offset'])) : (($usersPaged - 1) * $usersLimit);
                $includeRegisteredDate = $isTruthy($utils::getArrayValue($args, 'include_registered_date', false));
                $includeAvatarUrl = $isTruthy($utils::getArrayValue($args, 'include_avatar_url', false));
                $includePostCounts = $isTruthy($utils::getArrayValue($args, 'include_post_counts', false));
                $includeUsersPagination = $isTruthy($utils::getArrayValue($args, 'include_pagination', false));
                $q = array(
                    'number' => $usersLimit,
                    'offset' => $usersOffset,
                    'count_total' => $includeUsersPagination,
                );
                $usersSearch = trim((string) $utils::getArrayValue($args, 'search'));
                if ('' !== $usersSearch) {
                    $q['search'] = '*' . esc_attr($usersSearch) . '*';
                    $q['search_columns'] = array('user_login', 'user_email', 'user_nicename', 'display_name');
                }
                $usersRole = $utils::getArrayValue($args, 'role');
                if (!empty($usersRole)) {
                    $q['role'] = sanitize_text_field($usersRole);
                }
                $userQuery = new WP_User_Query($q);
                $rows = array();
                foreach ($userQuery->get_results() as $u) {
                    $row = array(
                        'ID' => $u->ID,
                        'user_login' => $u->user_login,
                        'display_name' => $u->display_name,
                        'roles' => $u->roles,
                    );
                    if ($includeRegisteredDate) {
                        $row['user_registered'] = $u->user_registered;
                    }
                    if ($includeAvatarUrl) {
                        $row['avatar_url'] = get_avatar_url($u->ID);
                    }
                    if ($includePostCounts) {
                        $row['post_count'] = (int) count_user_posts($u->ID);
                    }
                    $rows[] = $row;
                }
                if ($includeUsersPagination) {
                    $rows = array(
                        'items' => $rows,
                        'pagination' => $buildPaginationMeta((int) $userQuery->get_total(), $usersLimit, $usersOffset, $usersPaged),
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
                    if ( StifliFlexMcpUtils::keyLooksSensitive( $meta_key ) ) {
                        $value = is_scalar( $value ) && '' !== (string) $value ? '[REDACTED]' : StifliFlexMcpUtils::redactSecrets( $value, $meta_key );
                    } else {
                        $value = StifliFlexMcpUtils::redactSecrets( $value, $meta_key );
                    }
                    $addResultText($r, 'User meta ' . $meta_key . ': ' . wp_json_encode($value, JSON_PRETTY_PRINT));
                } else {
                    // Get all meta
                    $all_meta = get_user_meta($user_id);
                    $cleaned = array();
                    foreach ($all_meta as $key => $values) {
                        $val = count($values) === 1 ? $values[0] : $values;
                        if ( StifliFlexMcpUtils::keyLooksSensitive( $key ) ) {
                            $cleaned[$key] = is_scalar( $val ) && '' !== (string) $val ? '[REDACTED]' : '[REDACTED]';
                        } else {
                            $cleaned[$key] = StifliFlexMcpUtils::redactSecrets( $val, $key );
                        }
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

                // SSRF protection: HTTPS only, block private/reserved IPs and internal hosts.
                // Allow opting out only via filter (e.g. for local-dev environments).
                $require_https = (bool) apply_filters( 'sflmcp_upload_require_https', true, $url );
                $url_check = StifliFlexMcpUtils::validateOutboundUrl( $url, $require_https );
                if ( is_wp_error( $url_check ) ) {
                    stifli_flex_mcp_log( 'wp_upload_image_from_url: blocked URL = ' . $url . ' reason=' . $url_check->get_error_code() );
                    $r['error'] = array(
                        'code'    => $url_check->get_error_code(),
                        'message' => 'URL rejected: ' . $url_check->get_error_message(),
                    );
                    break;
                }

                // Restrict allowed image MIME types. SVG and HTML/script-capable
                // formats are explicitly excluded to avoid stored XSS.
                $allowed_image_mimes = apply_filters( 'sflmcp_upload_allowed_image_mimes', array(
                    'image/jpeg' => array( 'jpg', 'jpeg', 'jpe' ),
                    'image/png'  => array( 'png' ),
                    'image/gif'  => array( 'gif' ),
                    'image/webp' => array( 'webp' ),
                ) );

                add_filter('upload_mimes', function($mimes) use ( $allowed_image_mimes ) {
                    foreach ( $allowed_image_mimes as $mime => $exts ) {
                        $key = implode( '|', $exts );
                        $mimes[ $key ] = $mime;
                    }
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
                // Cap download size at 20MB by default (filterable).
                $max_bytes = (int) apply_filters( 'sflmcp_upload_max_bytes', 20 * 1024 * 1024, $url );
                $tmp = download_url( $url, 30, false );
                
                if (is_wp_error($tmp)) { 
                    stifli_flex_mcp_log('wp_upload_image_from_url: Download error = ' . $tmp->get_error_message());
                    $r['error'] = array('code' => 'download_error', 'message' => $tmp->get_error_message()); 
                    break; 
                }

                // Enforce size limit after download.
                if ( $max_bytes > 0 && file_exists( $tmp ) && filesize( $tmp ) > $max_bytes ) {
                    wp_delete_file( $tmp );
                    $r['error'] = array(
                        'code'    => 'file_too_large',
                        'message' => 'Downloaded file exceeds the maximum allowed size (' . $max_bytes . ' bytes).',
                    );
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

                // Validate the actual downloaded file is a real image of an allowed MIME.
                $detected_mime = '';
                if ( function_exists( 'finfo_open' ) ) {
                    $finfo_v = finfo_open( FILEINFO_MIME_TYPE );
                    if ( $finfo_v ) {
                        $detected_mime = (string) finfo_file( $finfo_v, $tmp );
                        finfo_close( $finfo_v );
                    }
                } elseif ( function_exists( 'mime_content_type' ) ) {
                    $detected_mime = (string) mime_content_type( $tmp );
                }
                $detected_mime = strtolower( $detected_mime );
                if ( ! isset( $allowed_image_mimes[ $detected_mime ] ) ) {
                    wp_delete_file( $tmp );
                    $r['error'] = array(
                        'code'    => 'mime_not_allowed',
                        'message' => 'Downloaded file MIME (' . $detected_mime . ') is not an allowed image type.',
                    );
                    break;
                }
                // Final sanity: getimagesize() must succeed (rules out SVG/HTML disguised as image).
                $imgsize = @getimagesize( $tmp );
                if ( ! is_array( $imgsize ) || empty( $imgsize[2] ) ) {
                    wp_delete_file( $tmp );
                    $r['error'] = array(
                        'code'    => 'invalid_image',
                        'message' => 'Downloaded file is not a valid image.',
                    );
                    break;
                }

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
                foreach ($tax as $k => $o) {
                    $out[] = array(
                        'slug' => $k,
                        'name' => $k,
                        'label' => $o->label,
                    );
                }
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
                if (!taxonomy_exists($taxonomy)) { $r['error'] = array('code' => -42600, 'message' => 'Unknown taxonomy: ' . $taxonomy); break; }
                $tax_obj = get_taxonomy($taxonomy);
                $cap = $tax_obj && !empty($tax_obj->cap->edit_terms) ? $tax_obj->cap->edit_terms : 'manage_categories';
                if (!current_user_can($cap)) { $r['error'] = array('code' => 'permission_denied', 'message' => 'Insufficient permissions for taxonomy: ' . $taxonomy); break; }
                $term_args = array();
                if (isset($args['slug']))        { $term_args['slug']        = sanitize_title($args['slug']); }
                if (isset($args['description'])) { $term_args['description'] = sanitize_textarea_field($args['description']); }
                if (isset($args['parent']))      { $term_args['parent']      = intval($args['parent']); }
                $res = wp_insert_term($name, $taxonomy, $term_args);
                if (is_wp_error($res)) { $r['error'] = array('code' => $res->get_error_code(), 'message' => $res->get_error_message()); } else { $addResultText($r, 'Term created: ' . wp_json_encode($res)); }
                break;
            case 'wp_update_term':
                $term_id  = intval($utils::getArrayValue($args, 'term_id'));
                $taxonomy = sanitize_text_field($utils::getArrayValue($args, 'taxonomy'));
                if (!$term_id || !$taxonomy) { $r['error'] = array('code' => -42602, 'message' => 'term_id & taxonomy required'); break; }
                if (!taxonomy_exists($taxonomy)) { $r['error'] = array('code' => -42600, 'message' => 'Unknown taxonomy: ' . $taxonomy); break; }
                $tax_obj = get_taxonomy($taxonomy);
                $cap = $tax_obj && !empty($tax_obj->cap->edit_terms) ? $tax_obj->cap->edit_terms : 'manage_categories';
                if (!current_user_can($cap)) { $r['error'] = array('code' => 'permission_denied', 'message' => 'Insufficient permissions for taxonomy: ' . $taxonomy); break; }
                $term_args = array();
                if (isset($args['name']))        { $term_args['name']        = sanitize_text_field($args['name']); }
                if (isset($args['slug']))        { $term_args['slug']        = sanitize_title($args['slug']); }
                if (isset($args['description'])) { $term_args['description'] = sanitize_textarea_field($args['description']); }
                if (isset($args['parent']))      { $term_args['parent']      = intval($args['parent']); }
                $res = wp_update_term($term_id, $taxonomy, $term_args);
                if (is_wp_error($res)) { $r['error'] = array('code' => $res->get_error_code(), 'message' => $res->get_error_message()); } else { $addResultText($r, 'Term updated: ' . wp_json_encode($res)); }
                break;
            case 'wp_delete_term':
                $term_id = intval($utils::getArrayValue($args, 'term_id'));
                $taxonomy = sanitize_text_field($utils::getArrayValue($args, 'taxonomy'));
                if (!$term_id || !$taxonomy) { $r['error'] = array('code' => -42602, 'message' => 'term_id & taxonomy required'); break; }
                if (!taxonomy_exists($taxonomy)) { $r['error'] = array('code' => -42600, 'message' => 'Unknown taxonomy: ' . $taxonomy); break; }
                $tax_obj = get_taxonomy($taxonomy);
                $cap = $tax_obj && !empty($tax_obj->cap->delete_terms) ? $tax_obj->cap->delete_terms : 'manage_categories';
                if (!current_user_can($cap)) { $r['error'] = array('code' => 'permission_denied', 'message' => 'Insufficient permissions for taxonomy: ' . $taxonomy); break; }
                $done = wp_delete_term($term_id, $taxonomy);
                if (is_wp_error($done)) { $r['error'] = array('code' => $done->get_error_code(), 'message' => $done->get_error_message()); } else { $addResultText($r, 'Term deleted'); }
                break;
            case 'wp_get_term_meta':
                $term_id = intval($utils::getArrayValue($args, 'term_id'));
                if (!$term_id) { $r['error'] = array('code' => -42602, 'message' => 'term_id required'); break; }
                $meta_key = isset($args['meta_key']) ? sanitize_key($args['meta_key']) : '';
                $single   = !empty($args['single']);
                if ($meta_key) {
                    $value = get_term_meta($term_id, $meta_key, $single);
                    $value = $utils::redactSecrets($value, $meta_key);
                    $payload = array(
                        'term_id' => $term_id,
                        'key' => $meta_key,
                        'value' => $value,
                    );
                } else {
                    $meta = get_term_meta($term_id);
                    $meta = $utils::redactSecrets($meta, '');
                    $payload = array(
                        'term_id' => $term_id,
                        'meta' => $meta,
                    );
                }
                $addResultText($r, wp_json_encode($payload, JSON_PRETTY_PRINT));
                break;
            case 'wp_update_term_meta':
                $term_id  = intval($utils::getArrayValue($args, 'term_id'));
                $meta_key = isset($args['meta_key']) ? sanitize_key($args['meta_key']) : '';
                if (!$term_id || !$meta_key) { $r['error'] = array('code' => -42602, 'message' => 'term_id & meta_key required'); break; }
                $meta_value = isset($args['meta_value']) ? $args['meta_value'] : '';
                $ok = update_term_meta($term_id, $meta_key, $meta_value);
                if (false === $ok) { $r['error'] = array('code' => -42603, 'message' => 'update_term_meta failed'); } else { $addResultText($r, 'Term meta updated'); }
                break;
            case 'wp_delete_term_meta':
                $term_id  = intval($utils::getArrayValue($args, 'term_id'));
                $meta_key = isset($args['meta_key']) ? sanitize_key($args['meta_key']) : '';
                if (!$term_id || !$meta_key) { $r['error'] = array('code' => -42602, 'message' => 'term_id & meta_key required'); break; }
                if (isset($args['meta_value'])) {
                    $done = delete_term_meta($term_id, $meta_key, $args['meta_value']);
                } else {
                    $done = delete_term_meta($term_id, $meta_key);
                }
                if ($done) { $addResultText($r, 'Term meta deleted'); } else { $r['error'] = array('code' => -42603, 'message' => 'delete_term_meta failed'); }
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
            case 'wp_reorder_menu_items':
                $menu_id = intval($utils::getArrayValue($args, 'menu_id', 0));
                $items   = $utils::getArrayValue($args, 'items', array());
                if (!$menu_id || !is_array($items) || empty($items)) {
                    $r['error'] = array('code' => -42602, 'message' => 'menu_id and items[] required');
                    break;
                }
                $menu_obj = wp_get_nav_menu_object($menu_id);
                if (!$menu_obj) { $r['error'] = array('code' => -42600, 'message' => 'Menu not found'); break; }
                if (!current_user_can('edit_theme_options')) { $r['error'] = array('code' => 'permission_denied', 'message' => 'Insufficient permissions to edit menus'); break; }
                $existing = wp_get_nav_menu_items($menu_id);
                $existing_ids = array();
                if (is_array($existing)) {
                    foreach ($existing as $ex) { $existing_ids[(int)$ex->ID] = true; }
                }
                $reorder_results = array();
                foreach ($items as $entry) {
                    if (!is_array($entry) || empty($entry['item_id'])) { continue; }
                    $item_id = intval($entry['item_id']);
                    if (!isset($existing_ids[$item_id])) {
                        $reorder_results[] = array('item_id' => $item_id, 'status' => 'skipped', 'reason' => 'not in this menu');
                        continue;
                    }
                    $upd = array(
                        'ID'         => $item_id,
                        'menu_order' => isset($entry['menu_order']) ? intval($entry['menu_order']) : 0,
                    );
                    if (array_key_exists('parent_id', $entry)) {
                        $upd['post_parent'] = intval($entry['parent_id']);
                        update_post_meta($item_id, '_menu_item_menu_item_parent', (string) intval($entry['parent_id']));
                    }
                    $res = wp_update_post($upd, true);
                    if (is_wp_error($res)) {
                        $reorder_results[] = array('item_id' => $item_id, 'status' => 'error', 'message' => $res->get_error_message());
                    } else {
                        $reorder_results[] = array('item_id' => $item_id, 'status' => 'ok');
                    }
                }
                $addResultText($r, wp_json_encode(array('menu_id' => $menu_id, 'results' => $reorder_results), JSON_PRETTY_PRINT));
                break;
            case 'wp_generate_image':
                stifli_flex_mcp_log('wp_generate_image: === START ===');
                // Keep processing even if the MCP client disconnects/retries.
                if ( function_exists( 'ignore_user_abort' ) ) {
                    ignore_user_abort( true );
                }
                // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged,WordPress.PHP.NoSilencedErrors.Discouraged -- required for long-running media generation on unstable client transports.
                @set_time_limit( 0 );
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
                    // --- OpenAI image generation (configurable model with DALL-E fallback) ---
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
                    $is_gpt_image_model = ( strpos( $oai_model, 'gpt-image-' ) === 0 );
                    if ( $is_gpt_image_model ) {
                        $oai_body['output_format'] = $oai_out_format;
                        if ( $oai_bg !== 'auto' ) {
                            // gpt-image-2 does not support transparent background.
                            if ( $oai_model === 'gpt-image-2' && $oai_bg === 'transparent' ) {
                                stifli_flex_mcp_log('wp_generate_image: transparent background is not supported by gpt-image-2, using auto');
                            } else {
                                $oai_body['background'] = $oai_bg;
                            }
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

                        // Fallback to DALL-E 3 if GPT Image requires verification
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
                            $mime_map = array(
                                'png'  => 'image/png',
                                'jpeg' => 'image/jpeg',
                                'webp' => 'image/webp',
                            );
                            $fmt = isset( $oai_body['output_format'] ) ? $oai_body['output_format'] : 'png';
                            $mime_type = isset( $mime_map[ $fmt ] ) ? $mime_map[ $fmt ] : 'image/png';
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

                // Save as WordPress media attachment. Avoid media_handle_sideload()
                // here because it generates image metadata/subsizes synchronously.
                if ( ! function_exists( 'wp_upload_bits' ) ) {
                    require_once ABSPATH . 'wp-admin/includes/file.php';
                }

                $ext_map  = array( 'image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp' );
                $ext      = isset( $ext_map[ $mime_type ] ) ? $ext_map[ $mime_type ] : 'png';
                $filename = 'ai-generated-' . gmdate( 'Ymd-His' ) . '-' . wp_generate_password( 6, false ) . '.' . $ext;

                stifli_flex_mcp_log('wp_generate_image: Writing image to uploads as ' . $filename);
                $upload = wp_upload_bits( $filename, null, $image_binary );
                unset( $image_binary );
                if ( ! empty( $upload['error'] ) ) {
                    stifli_flex_mcp_log('wp_generate_image: Upload bits error: ' . $upload['error']);
                    $r['error'] = array( 'code' => 'write_error', 'message' => 'Failed to write image file: ' . $upload['error'] );
                    break;
                }
                if ( empty( $upload['file'] ) || ! file_exists( $upload['file'] ) ) {
                    stifli_flex_mcp_log('wp_generate_image: Upload bits returned no readable file');
                    $r['error'] = array( 'code' => 'write_error', 'message' => 'Failed to create image file in uploads.' );
                    break;
                }
                stifli_flex_mcp_log('wp_generate_image: Upload file ready path=' . $upload['file']);

                $filetype = wp_check_filetype( $upload['file'], null );
                $attachment = array(
                    'guid'           => $upload['url'],
                    'post_mime_type' => ! empty( $filetype['type'] ) ? $filetype['type'] : $mime_type,
                    'post_title'     => $img_title ? $img_title : preg_replace( '/\.[^.]+$/', '', basename( $filename ) ),
                    'post_content'   => '',
                    'post_status'    => 'inherit',
                );
                $att_id = wp_insert_attachment( $attachment, $upload['file'], $img_post_id, true );
                if ( is_wp_error( $att_id ) ) {
                    stifli_flex_mcp_log('wp_generate_image: Attachment insert error: ' . $att_id->get_error_message());
                    wp_delete_file( $upload['file'] );
                    $r['error'] = array( 'code' => 'upload_error', 'message' => $att_id->get_error_message() );
                    break;
                }
                $att_id = (int) $att_id;
                update_attached_file( $att_id, $upload['file'] );
                stifli_flex_mcp_log('wp_generate_image: Saved as attachment ID=' . $att_id . ' file=' . $upload['file']);

                $pp_enabled = ! empty( $mm_settings['pp_enabled'] ) && '1' === $mm_settings['pp_enabled'];
                $post_process_scheduled = false;
                if ( ! wp_next_scheduled( 'sflmcp_process_generated_image_attachment', array( $att_id ) ) ) {
                    $schedule_result = wp_schedule_single_event( time() + 1, 'sflmcp_process_generated_image_attachment', array( $att_id ) );
                    if ( is_wp_error( $schedule_result ) ) {
                        stifli_flex_mcp_log('wp_generate_image: Failed to schedule metadata/post-processing for ID=' . $att_id . ' message=' . $schedule_result->get_error_message());
                    } else {
                        $post_process_scheduled = ( false !== $schedule_result );
                    }
                    if ( $post_process_scheduled && function_exists( 'spawn_cron' ) ) {
                        spawn_cron( time() );
                    }
                    stifli_flex_mcp_log('wp_generate_image: Scheduled attachment metadata/post-processing for ID=' . $att_id . ' scheduled=' . ( $post_process_scheduled ? 'yes' : 'no' ));
                } else {
                    $post_process_scheduled = true;
                    stifli_flex_mcp_log('wp_generate_image: Attachment metadata/post-processing already scheduled for ID=' . $att_id);
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
                    'post_processed' => false,
                    'post_process_scheduled' => $post_process_scheduled,
                    'prompt'         => $prompt,
                );
                stifli_flex_mcp_log('wp_generate_image: === SUCCESS === attachment_id=' . $att_id . ' url=' . $att_url . ' provider=' . $provider . ' post_process_scheduled=' . ( $post_process_scheduled ? 'yes' : 'no' ) . ' pp_enabled=' . ( $pp_enabled ? 'yes' : 'no' ));
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

                // Save as WordPress media attachment. Avoid media_handle_sideload()
                // here because metadata extraction can stall long-running workers.
                if ( ! function_exists( 'wp_upload_bits' ) ) {
                    require_once ABSPATH . 'wp-admin/includes/file.php';
                }

                $vid_ext_map  = array( 'video/mp4' => 'mp4', 'video/webm' => 'webm' );
                $vid_ext      = isset( $vid_ext_map[ $vid_mime ] ) ? $vid_ext_map[ $vid_mime ] : 'mp4';
                $vid_filename = 'ai-video-' . gmdate( 'Ymd-His' ) . '-' . wp_generate_password( 6, false ) . '.' . $vid_ext;

                stifli_flex_mcp_log('wp_generate_video: Writing video to uploads as ' . $vid_filename);
                $vid_upload = wp_upload_bits( $vid_filename, null, $vid_binary );
                unset( $vid_binary );
                if ( ! empty( $vid_upload['error'] ) ) {
                    stifli_flex_mcp_log('wp_generate_video: Upload bits error: ' . $vid_upload['error']);
                    $r['error'] = array( 'code' => 'write_error', 'message' => 'Failed to write video file: ' . $vid_upload['error'] );
                    break;
                }
                if ( empty( $vid_upload['file'] ) || ! file_exists( $vid_upload['file'] ) ) {
                    stifli_flex_mcp_log('wp_generate_video: Upload bits returned no readable file');
                    $r['error'] = array( 'code' => 'write_error', 'message' => 'Failed to create video file in uploads.' );
                    break;
                }
                stifli_flex_mcp_log('wp_generate_video: Upload file ready path=' . $vid_upload['file']);

                $vid_filetype = wp_check_filetype( $vid_upload['file'], null );
                $vid_attachment = array(
                    'guid'           => $vid_upload['url'],
                    'post_mime_type' => ! empty( $vid_filetype['type'] ) ? $vid_filetype['type'] : $vid_mime,
                    'post_title'     => $vid_title ? $vid_title : preg_replace( '/\.[^.]+$/', '', basename( $vid_filename ) ),
                    'post_content'   => '',
                    'post_status'    => 'inherit',
                );
                $vid_att_id = wp_insert_attachment( $vid_attachment, $vid_upload['file'], $vid_post_id, true );

                if ( is_wp_error( $vid_att_id ) ) {
                    stifli_flex_mcp_log('wp_generate_video: Attachment insert error: ' . $vid_att_id->get_error_message());
                    wp_delete_file( $vid_upload['file'] );
                    $r['error'] = array( 'code' => 'upload_error', 'message' => $vid_att_id->get_error_message() );
                    break;
                }
                $vid_att_id = (int) $vid_att_id;
                update_attached_file( $vid_att_id, $vid_upload['file'] );
                stifli_flex_mcp_log('wp_generate_video: Saved as attachment ID=' . $vid_att_id . ' file=' . $vid_upload['file']);

                $vid_metadata_scheduled = false;
                if ( ! wp_next_scheduled( 'sflmcp_process_generated_video_attachment', array( $vid_att_id ) ) ) {
                    $vid_schedule_result = wp_schedule_single_event( time() + 1, 'sflmcp_process_generated_video_attachment', array( $vid_att_id ) );
                    if ( is_wp_error( $vid_schedule_result ) ) {
                        stifli_flex_mcp_log('wp_generate_video: Failed to schedule metadata processing for ID=' . $vid_att_id . ' message=' . $vid_schedule_result->get_error_message());
                    } else {
                        $vid_metadata_scheduled = ( false !== $vid_schedule_result );
                    }
                    if ( $vid_metadata_scheduled && function_exists( 'spawn_cron' ) ) {
                        spawn_cron( time() );
                    }
                    stifli_flex_mcp_log('wp_generate_video: Scheduled attachment metadata processing for ID=' . $vid_att_id . ' scheduled=' . ( $vid_metadata_scheduled ? 'yes' : 'no' ));
                } else {
                    $vid_metadata_scheduled = true;
                    stifli_flex_mcp_log('wp_generate_video: Attachment metadata already scheduled for ID=' . $vid_att_id);
                }

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
                    'metadata_scheduled' => $vid_metadata_scheduled,
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
                $paged = max(1, intval($utils::getArrayValue($args, 'paged', 1, 1)));
                $hasOffset = array_key_exists('offset', $args);
                $offset = $hasOffset ? max(0, intval($args['offset'])) : null;
                // Keep paged behavior when offset is 0, otherwise page 2 can be stuck on page 1.
                $useOffset = null !== $offset && $offset > 0;
                $includePagination = $isTruthy($utils::getArrayValue($args, 'include_pagination', false));
                $searchOrderby = sanitize_key($utils::getArrayValue($args, 'orderby', 'date'));
                $searchOrder = strtoupper(sanitize_text_field($utils::getArrayValue($args, 'order', 'DESC')));
                $queryArgs = array(
                    's' => $s,
                    'post_type' => sanitize_key($utils::getArrayValue($args, 'post_type', 'post')),
                    'post_status' => sanitize_key($utils::getArrayValue($args, 'post_status', 'publish')),
                    'posts_per_page' => $limit,
                    'orderby' => '' !== $searchOrderby ? $searchOrderby : 'date',
                    'order' => in_array($searchOrder, array('ASC', 'DESC'), true) ? $searchOrder : 'DESC',
                    'no_found_rows' => !$includePagination,
                );
                $searchAuthor = intval($utils::getArrayValue($args, 'author', 0, 1));
                if ($searchAuthor > 0) {
                    $queryArgs['author'] = $searchAuthor;
                }
                $searchCategory = sanitize_title($utils::getArrayValue($args, 'category', ''));
                if ('' !== $searchCategory) {
                    $queryArgs['category_name'] = $searchCategory;
                }
                $searchTag = sanitize_title($utils::getArrayValue($args, 'tag', ''));
                if ('' !== $searchTag) {
                    $queryArgs['tag'] = $searchTag;
                }
                if ($useOffset) {
                    $queryArgs['offset'] = $offset;
                } else {
                    $queryArgs['paged'] = $paged;
                }
                $searchDate = array();
                if (!empty($args['after'])) {
                    $searchDate['after'] = sanitize_text_field($args['after']);
                }
                if (!empty($args['before'])) {
                    $searchDate['before'] = sanitize_text_field($args['before']);
                }
                if (!empty($searchDate)) {
                    $queryArgs['date_query'] = array($searchDate);
                }
                $q = new WP_Query($queryArgs);
                $out = array();
                foreach ($q->posts as $p) {
                    $out[] = array(
                        'ID' => $p->ID,
                        'post_type' => $p->post_type,
                        'post_status' => $p->post_status,
                        'post_title' => $p->post_title,
                        'excerpt' => $postExcerpt($p),
                        'permalink' => get_permalink($p),
                    );
                }
                if ($includePagination) {
                    $effectiveOffset = $useOffset ? $offset : (($paged - 1) * $limit);
                    $out = array(
                        'items' => $out,
                        'pagination' => $buildPaginationMeta((int) $q->found_posts, $limit, $effectiveOffset, $paged),
                    );
                }
                $addResultText($r, wp_json_encode($out, JSON_PRETTY_PRINT));
                break;
            case 'fetch':
                $rawUrl = (string) $utils::getArrayValue($args, 'url');
                $queryParams = $sanitizeQueryParams($utils::getArrayValue($args, 'query_params', array()));
                if (!empty($queryParams)) {
                    $rawUrl = add_query_arg($queryParams, $rawUrl);
                }
                $url = esc_url_raw($rawUrl);
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
                $timeoutSec = max(1, min(20, intval($utils::getArrayValue($args, 'timeout_sec', 10, 1))));
                $maxRedirects = max(0, min(10, intval($utils::getArrayValue($args, 'max_redirects', 3, 1))));
                $headOnly = $isTruthy($utils::getArrayValue($args, 'head_only', false));
                $includeHeaders = $isTruthy($utils::getArrayValue($args, 'include_headers', false));
                $includeRequestHeaders = $isTruthy($utils::getArrayValue($args, 'include_request_headers', false));
                $extractText = $isTruthy($utils::getArrayValue($args, 'extract_text', false));
                $maxBytes = max(128, min(50000, intval($utils::getArrayValue($args, 'max_bytes', 2000, 1))));
                $method = strtoupper($utils::getArrayValue($args, 'method', 'GET'));
                if ($headOnly) {
                    $method = 'HEAD';
                }
                $requestHeaders = $sanitizeHeaderMap($utils::getArrayValue($args, 'headers', array()));
                $acceptHeader = trim((string) $utils::getArrayValue($args, 'accept', ''));
                if ('' !== $acceptHeader && !$hasHeaderName($requestHeaders, 'Accept')) {
                    $requestHeaders['Accept'] = sanitize_text_field($acceptHeader);
                }
                $contentTypeHeader = trim((string) $utils::getArrayValue($args, 'content_type', ''));
                if ('HEAD' !== $method && '' !== $contentTypeHeader && !$hasHeaderName($requestHeaders, 'Content-Type')) {
                    $requestHeaders['Content-Type'] = sanitize_text_field($contentTypeHeader);
                }
                $userAgent = trim((string) $utils::getArrayValue($args, 'user_agent', ''));
                $opts = array(
                    'timeout' => $timeoutSec,
                    'redirection' => $maxRedirects,
                );
                if ('' !== $userAgent) {
                    $opts['user-agent'] = sanitize_text_field($userAgent);
                }
                if (!empty($requestHeaders)) {
                    $opts['headers'] = $requestHeaders;
                }
                if (!empty($args['body'])) { $opts['body'] = $args['body']; }
                if ('HEAD' === $method) {
                    $resp = wp_remote_head($url, $opts);
                } elseif ('GET' === $method) {
                    $resp = wp_remote_get($url, $opts);
                } else {
                    $resp = wp_remote_request($url, array_merge($opts, array('method' => $method)));
                }
                if (is_wp_error($resp)) { $r['error'] = array('code' => 'fetch_error', 'message' => $resp->get_error_message()); break; }
                $code = wp_remote_retrieve_response_code($resp);
                $body = 'HEAD' === $method ? '' : wp_remote_retrieve_body($resp);
                if ($extractText && '' !== $body) {
                    $body = trim((string) preg_replace('/\s+/', ' ', wp_strip_all_tags($body)));
                }
                $bodyShort = (strlen($body) > $maxBytes) ? substr($body, 0, $maxBytes) . '... [truncated]' : $body;
                if ($includeHeaders || 'HEAD' === $method) {
                    $headers = wp_remote_retrieve_headers($resp);
                    if (is_object($headers) && method_exists($headers, 'getAll')) {
                        $headers = $headers->getAll();
                    } elseif (!is_array($headers)) {
                        $headers = (array) $headers;
                    }
                    $payload = array(
                        'status' => (int) $code,
                        'method' => $method,
                        'url' => $url,
                        'response_message' => wp_remote_retrieve_response_message($resp),
                        'content_type' => wp_remote_retrieve_header($resp, 'content-type'),
                    );
                    if ($includeRequestHeaders) {
                        $payload['request_headers'] = $requestHeaders;
                    }
                    if ($includeHeaders) {
                        $payload['headers'] = $headers;
                    }
                    if ('HEAD' !== $method) {
                        $payload['body'] = $bodyShort;
                    }
                    $addResultText($r, wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                } else {
                    $addResultText($r, "Fetch status: $code\n" . $bodyShort);
                }
                break;
            case 'wp_rm_get_head':
                if ( ! function_exists( 'rank_math' ) ) {
                    $r['error'] = array('code' => -32603, 'message' => 'Rank Math SEO is not active on this site.');
                    break;
                }
                $rm_url = esc_url_raw( $utils::getArrayValue( $args, 'url', '' ) );
                if ( empty( $rm_url ) ) {
                    $r['error'] = array('code' => -32602, 'message' => 'Missing required parameter: url');
                    break;
                }
                if ( class_exists( 'RankMath\\Helper' ) && method_exists( 'RankMath\\Helper', 'get_settings' ) ) {
                    $rm_headless = call_user_func( array( 'RankMath\\Helper', 'get_settings' ), 'general.headless_support' );
                    if ( empty( $rm_headless ) ) {
                        $r['error'] = array('code' => -32603, 'message' => 'Rank Math Headless CMS Support is disabled. Enable it in Rank Math > General Settings > Others.');
                        break;
                    }
                }
                $rm_head_req = new WP_REST_Request( 'GET', '/rankmath/v1/getHead' );
                $rm_head_req->set_param( 'url', $rm_url );
                $rm_head_res = rest_do_request( $rm_head_req );
                if ( $rm_head_res->is_error() ) {
                    $rm_error = $rm_head_res->as_error();
                    $r['error'] = array('code' => -32603, 'message' => $rm_error->get_error_message());
                    break;
                }
                $addResultText( $r, wp_json_encode( $rm_head_res->get_data(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
                break;
            case 'wp_rm_get_post_seo':
                if ( ! function_exists( 'rank_math' ) ) {
                    $r['error'] = array('code' => -32603, 'message' => 'Rank Math SEO is not active on this site.');
                    break;
                }
                $rm_post_id = intval( $utils::getArrayValue( $args, 'post_id', 0 ) );
                if ( ! $rm_post_id ) {
                    $r['error'] = array('code' => -32602, 'message' => 'Missing required parameter: post_id');
                    break;
                }
                if ( ! get_post( $rm_post_id ) ) {
                    $r['error'] = array('code' => -32602, 'message' => 'Post not found.');
                    break;
                }
                $rm_meta_keys = array(
                    'rank_math_title',
                    'rank_math_description',
                    'rank_math_focus_keyword',
                    'rank_math_robots',
                    'rank_math_canonical_url',
                    'rank_math_facebook_title',
                    'rank_math_facebook_description',
                    'rank_math_facebook_image',
                    'rank_math_twitter_title',
                    'rank_math_twitter_description',
                    'rank_math_twitter_image',
                    'rank_math_pillar_content',
                );
                $rm_data = array( 'post_id' => $rm_post_id );
                foreach ( $rm_meta_keys as $rm_meta_key ) {
                    $rm_data[ $rm_meta_key ] = get_post_meta( $rm_post_id, $rm_meta_key, true );
                }
                $addResultText( $r, wp_json_encode( $rm_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
                break;
            case 'wp_rm_update_post_seo':
                if ( ! function_exists( 'rank_math' ) ) {
                    $r['error'] = array('code' => -32603, 'message' => 'Rank Math SEO is not active on this site.');
                    break;
                }
                $rm_post_id = intval( $utils::getArrayValue( $args, 'post_id', 0 ) );
                if ( ! $rm_post_id ) {
                    $r['error'] = array('code' => -32602, 'message' => 'Missing required parameter: post_id');
                    break;
                }
                $rm_post = get_post( $rm_post_id );
                if ( ! $rm_post ) {
                    $r['error'] = array('code' => -32602, 'message' => 'Post not found.');
                    break;
                }
                if ( ! current_user_can( 'edit_post', $rm_post_id ) ) {
                    $r['error'] = array('code' => -32603, 'message' => 'You do not have permission to edit this post.');
                    break;
                }
                $rm_map = array(
                    'title'                => 'rank_math_title',
                    'description'          => 'rank_math_description',
                    'focus_keyword'        => 'rank_math_focus_keyword',
                    'canonical_url'        => 'rank_math_canonical_url',
                    'facebook_title'       => 'rank_math_facebook_title',
                    'facebook_description' => 'rank_math_facebook_description',
                    'twitter_title'        => 'rank_math_twitter_title',
                    'twitter_description'  => 'rank_math_twitter_description',
                    'facebook_image'       => 'rank_math_facebook_image',
                    'twitter_image'        => 'rank_math_twitter_image',
                );
                $rm_url_fields = array( 'canonical_url', 'facebook_image', 'twitter_image' );
                $rm_updated = array();
                foreach ( $rm_map as $rm_arg_key => $rm_meta_key ) {
                    if ( isset( $args[ $rm_arg_key ] ) ) {
                        $rm_value = in_array( $rm_arg_key, $rm_url_fields, true )
                            ? esc_url_raw( $args[ $rm_arg_key ] )
                            : sanitize_text_field( $args[ $rm_arg_key ] );
                        if ( false !== update_post_meta( $rm_post_id, $rm_meta_key, $rm_value ) ) {
                            $rm_updated[] = $rm_arg_key;
                        }
                    }
                }
                $addResultText( $r, wp_json_encode( array(
                    'post_id' => $rm_post_id,
                    'updated_fields' => $rm_updated,
                ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
                break;
            // ── Yoast SEO ─────────────────────────────────────────────────────
            case 'yoast_get_meta':
                if ( ! defined( 'WPSEO_VERSION' ) ) {
                    $r['error'] = array( 'code' => -32603, 'message' => 'Yoast SEO plugin is not active.' );
                    break;
                }
                $ys_post_id = intval( $utils::getArrayValue( $args, 'post_id', 0 ) );
                if ( ! $ys_post_id || ! get_post( $ys_post_id ) ) {
                    $r['error'] = array( 'code' => -32602, 'message' => 'Invalid or missing post_id.' );
                    break;
                }
                $ys_keys = array(
                    '_yoast_wpseo_title'              => 'title',
                    '_yoast_wpseo_metadesc'           => 'description',
                    '_yoast_wpseo_focuskw'            => 'focus_keyword',
                    '_yoast_wpseo_canonical'          => 'canonical',
                    '_yoast_wpseo_meta-robots-noindex' => 'noindex',
                    '_yoast_wpseo_meta-robots-nofollow' => 'nofollow',
                    '_yoast_wpseo_opengraph-title'    => 'facebook_title',
                    '_yoast_wpseo_opengraph-description' => 'facebook_description',
                    '_yoast_wpseo_opengraph-image'    => 'facebook_image',
                    '_yoast_wpseo_twitter-title'      => 'twitter_title',
                    '_yoast_wpseo_twitter-description' => 'twitter_description',
                    '_yoast_wpseo_twitter-image'      => 'twitter_image',
                );
                $ys_data = array( 'post_id' => $ys_post_id );
                foreach ( $ys_keys as $ys_meta_key => $ys_field ) {
                    $ys_data[ $ys_field ] = get_post_meta( $ys_post_id, $ys_meta_key, true );
                }
                $addResultText( $r, wp_json_encode( $ys_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
                break;
            case 'yoast_set_meta':
                if ( ! defined( 'WPSEO_VERSION' ) ) {
                    $r['error'] = array( 'code' => -32603, 'message' => 'Yoast SEO plugin is not active.' );
                    break;
                }
                $ys_post_id = intval( $utils::getArrayValue( $args, 'post_id', 0 ) );
                if ( ! $ys_post_id || ! get_post( $ys_post_id ) ) {
                    $r['error'] = array( 'code' => -32602, 'message' => 'Invalid or missing post_id.' );
                    break;
                }
                $ys_map = array(
                    'title'                => '_yoast_wpseo_title',
                    'description'          => '_yoast_wpseo_metadesc',
                    'focus_keyword'        => '_yoast_wpseo_focuskw',
                    'canonical'            => '_yoast_wpseo_canonical',
                    'facebook_title'       => '_yoast_wpseo_opengraph-title',
                    'facebook_description' => '_yoast_wpseo_opengraph-description',
                    'facebook_image'       => '_yoast_wpseo_opengraph-image',
                    'twitter_title'        => '_yoast_wpseo_twitter-title',
                    'twitter_description'  => '_yoast_wpseo_twitter-description',
                    'twitter_image'        => '_yoast_wpseo_twitter-image',
                );
                $ys_bool_map = array(
                    'noindex'   => '_yoast_wpseo_meta-robots-noindex',
                    'nofollow'  => '_yoast_wpseo_meta-robots-nofollow',
                );
                $ys_updated = array();
                foreach ( $ys_map as $ys_arg => $ys_meta ) {
                    if ( isset( $args[ $ys_arg ] ) ) {
                        $ys_val = in_array( $ys_arg, array( 'canonical', 'facebook_image', 'twitter_image' ), true )
                            ? esc_url_raw( $args[ $ys_arg ] )
                            : sanitize_text_field( $args[ $ys_arg ] );
                        update_post_meta( $ys_post_id, $ys_meta, $ys_val );
                        $ys_updated[] = $ys_arg;
                    }
                }
                foreach ( $ys_bool_map as $ys_arg => $ys_meta ) {
                    if ( isset( $args[ $ys_arg ] ) ) {
                        update_post_meta( $ys_post_id, $ys_meta, $args[ $ys_arg ] ? '1' : '0' );
                        $ys_updated[] = $ys_arg;
                    }
                }
                $addResultText( $r, wp_json_encode( array( 'post_id' => $ys_post_id, 'updated_fields' => $ys_updated ), JSON_PRETTY_PRINT ) );
                break;
            case 'yoast_reindex':
                if ( ! defined( 'WPSEO_VERSION' ) ) {
                    $r['error'] = array( 'code' => -32603, 'message' => 'Yoast SEO plugin is not active.' );
                    break;
                }
                $ys_post_id = intval( $utils::getArrayValue( $args, 'post_id', 0 ) );
                $ys_scope = 'site-wide';
                if ( $ys_post_id ) {
                    // Delete indexable for the post so Yoast rebuilds it on next request.
                    if ( class_exists( 'Yoast\\WP\\SEO\\Repositories\\Indexable_Repository' ) ) {
                        $ys_repo = \YoastSEO()->classes->get( 'Yoast\\WP\\SEO\\Repositories\\Indexable_Repository' );
                        if ( $ys_repo ) {
                            $ys_indexable = $ys_repo->find_by_id_and_type( $ys_post_id, 'post', false );
                            if ( $ys_indexable ) {
                                $ys_indexable->delete();
                            }
                        }
                    }
                    delete_post_meta( $ys_post_id, '_yoast_wpseo_content_score' );
                    $ys_scope = 'post:' . $ys_post_id;
                } else {
                    // Trigger full reindex by bumping the version option used by Yoast's background indexing.
                    delete_option( 'wpseo_indexation_complete' );
                    delete_transient( 'wpseo_total_unindexed' );
                }
                $addResultText( $r, 'Yoast SEO reindex triggered. Scope: ' . $ys_scope );
                break;
            // ── ACF ───────────────────────────────────────────────────────────
            case 'acf_get_field_groups':
                if ( ! function_exists( 'acf_get_field_groups' ) ) {
                    $r['error'] = array( 'code' => -32603, 'message' => 'Advanced Custom Fields plugin is not active.' );
                    break;
                }
                $acf_groups = acf_get_field_groups();
                $acf_out = array();
                foreach ( $acf_groups as $acf_g ) {
                    $acf_out[] = array(
                        'key'       => $acf_g['key'],
                        'title'     => $acf_g['title'],
                        'active'    => $acf_g['active'],
                        'location'  => $acf_g['location'],
                        'menu_order' => $acf_g['menu_order'],
                    );
                }
                $addResultText( $r, wp_json_encode( $acf_out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
                break;
            case 'acf_get_fields':
                if ( ! function_exists( 'get_fields' ) ) {
                    $r['error'] = array( 'code' => -32603, 'message' => 'Advanced Custom Fields plugin is not active.' );
                    break;
                }
                $acf_post_id = intval( $utils::getArrayValue( $args, 'post_id', 0 ) );
                if ( ! $acf_post_id ) {
                    $r['error'] = array( 'code' => -32602, 'message' => 'Missing required parameter: post_id' );
                    break;
                }
                $acf_fields = get_fields( $acf_post_id );
                if ( false === $acf_fields ) {
                    $acf_fields = array();
                }
                $addResultText( $r, wp_json_encode( $acf_fields, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
                break;
            case 'acf_update_field':
                if ( ! function_exists( 'update_field' ) ) {
                    $r['error'] = array( 'code' => -32603, 'message' => 'Advanced Custom Fields plugin is not active.' );
                    break;
                }
                $acf_post_id    = intval( $utils::getArrayValue( $args, 'post_id', 0 ) );
                $acf_field_name = sanitize_text_field( $utils::getArrayValue( $args, 'field_name', '' ) );
                if ( ! $acf_post_id || '' === $acf_field_name ) {
                    $r['error'] = array( 'code' => -32602, 'message' => 'Missing required parameters: post_id, field_name.' );
                    break;
                }
                // Value can be any type (string, int, bool, array) — passed as-is.
                $acf_value  = $utils::getArrayValue( $args, 'value', null );
                $acf_result = update_field( $acf_field_name, $acf_value, $acf_post_id );
                if ( false === $acf_result ) {
                    $r['error'] = array( 'code' => -32603, 'message' => 'ACF update_field returned false. Check field name and post ID.' );
                    break;
                }
                $addResultText( $r, wp_json_encode( array( 'post_id' => $acf_post_id, 'field_name' => $acf_field_name, 'updated' => true ), JSON_PRETTY_PRINT ) );
                break;

            // ── WPForms ───────────────────────────────────────────────────────
            case 'wpforms_list_forms':
                if ( ! function_exists( 'wpforms' ) ) {
                    $r['error'] = array( 'code' => -32603, 'message' => 'WPForms plugin is not active.' );
                    break;
                }
                $wpf_limit  = max( 1, intval( $utils::getArrayValue( $args, 'limit', 50 ) ) );
                $wpf_offset = max( 0, intval( $utils::getArrayValue( $args, 'offset', 0 ) ) );
                $wpf_forms  = wpforms()->form->get( '', array(
                    'posts_per_page' => $wpf_limit,
                    'offset'         => $wpf_offset,
                    'fields'         => 'ids',
                ) );
                $wpf_out = array();
                if ( $wpf_forms ) {
                    foreach ( $wpf_forms as $wpf_form_id ) {
                        $wpf_form = wpforms()->form->get( $wpf_form_id );
                        if ( $wpf_form ) {
                            $wpf_out[] = array(
                                'id'      => $wpf_form->ID,
                                'title'   => $wpf_form->post_title,
                                'status'  => $wpf_form->post_status,
                                'created' => $wpf_form->post_date,
                            );
                        }
                    }
                }
                $addResultText( $r, wp_json_encode( $wpf_out, JSON_PRETTY_PRINT ) );
                break;
            case 'wpforms_get_entries':
                if ( ! function_exists( 'wpforms' ) ) {
                    $r['error'] = array( 'code' => -32603, 'message' => 'WPForms plugin is not active.' );
                    break;
                }
                $wpf_form_id = intval( $utils::getArrayValue( $args, 'form_id', 0 ) );
                if ( ! $wpf_form_id ) {
                    $r['error'] = array( 'code' => -32602, 'message' => 'Missing required parameter: form_id.' );
                    break;
                }
                $wpf_limit   = max( 1, intval( $utils::getArrayValue( $args, 'limit', 20 ) ) );
                $wpf_offset  = max( 0, intval( $utils::getArrayValue( $args, 'offset', 0 ) ) );
                $wpf_status  = sanitize_text_field( $utils::getArrayValue( $args, 'status', '' ) );
                $wpf_query   = array(
                    'form_id'  => $wpf_form_id,
                    'number'   => $wpf_limit,
                    'offset'   => $wpf_offset,
                );
                if ( $wpf_status ) {
                    $wpf_query['status'] = $wpf_status;
                }
                $wpf_entries = wpforms()->entry->get_entries( $wpf_query );
                $wpf_out = array();
                if ( $wpf_entries ) {
                    foreach ( $wpf_entries as $wpf_entry ) {
                        $wpf_out[] = array(
                            'entry_id' => $wpf_entry->entry_id,
                            'form_id'  => $wpf_entry->form_id,
                            'date'     => $wpf_entry->date,
                            'status'   => $wpf_entry->status,
                            'fields'   => json_decode( $wpf_entry->fields, true ),
                        );
                    }
                }
                $addResultText( $r, wp_json_encode( $wpf_out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
                break;

            // ── Gravity Forms ─────────────────────────────────────────────────
            case 'gf_list_forms':
                if ( ! class_exists( 'GFAPI' ) ) {
                    $r['error'] = array( 'code' => -32603, 'message' => 'Gravity Forms plugin is not active.' );
                    break;
                }
                $gf_active_only = isset( $args['active'] ) ? (bool) $args['active'] : null;
                $gf_forms = GFAPI::get_forms( $gf_active_only );
                $gf_out = array();
                foreach ( $gf_forms as $gf_form ) {
                    $gf_out[] = array(
                        'id'          => $gf_form['id'],
                        'title'       => $gf_form['title'],
                        'description' => isset( $gf_form['description'] ) ? $gf_form['description'] : '',
                        'is_active'   => ! empty( $gf_form['is_active'] ),
                        'entry_count' => GFAPI::count_entries( $gf_form['id'] ),
                    );
                }
                $addResultText( $r, wp_json_encode( $gf_out, JSON_PRETTY_PRINT ) );
                break;
            case 'gf_get_entries':
                if ( ! class_exists( 'GFAPI' ) ) {
                    $r['error'] = array( 'code' => -32603, 'message' => 'Gravity Forms plugin is not active.' );
                    break;
                }
                $gf_form_id   = intval( $utils::getArrayValue( $args, 'form_id', 0 ) );
                if ( ! $gf_form_id ) {
                    $r['error'] = array( 'code' => -32602, 'message' => 'Missing required parameter: form_id.' );
                    break;
                }
                $gf_page_size = max( 1, intval( $utils::getArrayValue( $args, 'page_size', 20 ) ) );
                $gf_offset    = max( 0, intval( $utils::getArrayValue( $args, 'offset', 0 ) ) );
                $gf_status    = sanitize_text_field( $utils::getArrayValue( $args, 'status', 'active' ) );
                $gf_search    = array();
                $gf_sv        = sanitize_text_field( $utils::getArrayValue( $args, 'search_value', '' ) );
                $gf_fid       = sanitize_text_field( $utils::getArrayValue( $args, 'field_id', '' ) );
                if ( $gf_sv && $gf_fid ) {
                    $gf_search = array( 'field_filters' => array( array( 'key' => $gf_fid, 'value' => $gf_sv ) ) );
                } elseif ( $gf_sv ) {
                    $gf_search = array( 'field_filters' => array( array( 'key' => 0, 'value' => $gf_sv ) ) );
                }
                $gf_criteria = array( 'status' => $gf_status );
                $gf_sorting  = array( 'key' => 'date_created', 'direction' => 'DESC', 'is_numeric' => false );
                $gf_paging   = array( 'offset' => $gf_offset, 'page_size' => $gf_page_size );
                $gf_entries  = GFAPI::get_entries( $gf_form_id, array_merge( $gf_criteria, $gf_search ), $gf_sorting, $gf_paging );
                if ( is_wp_error( $gf_entries ) ) {
                    $r['error'] = array( 'code' => -32603, 'message' => $gf_entries->get_error_message() );
                    break;
                }
                $addResultText( $r, wp_json_encode( $gf_entries, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
                break;
            case 'gf_update_entry':
                if ( ! class_exists( 'GFAPI' ) ) {
                    $r['error'] = array( 'code' => -32603, 'message' => 'Gravity Forms plugin is not active.' );
                    break;
                }
                $gf_entry_id = intval( $utils::getArrayValue( $args, 'entry_id', 0 ) );
                if ( ! $gf_entry_id ) {
                    $r['error'] = array( 'code' => -32602, 'message' => 'Missing required parameter: entry_id.' );
                    break;
                }
                $gf_entry = GFAPI::get_entry( $gf_entry_id );
                if ( is_wp_error( $gf_entry ) ) {
                    $r['error'] = array( 'code' => -32603, 'message' => $gf_entry->get_error_message() );
                    break;
                }
                if ( isset( $args['status'] ) ) {
                    $gf_entry['status'] = sanitize_text_field( $args['status'] );
                }
                if ( isset( $args['is_read'] ) ) {
                    $gf_entry['is_read'] = $args['is_read'] ? '1' : '0';
                }
                if ( isset( $args['is_starred'] ) ) {
                    $gf_entry['is_starred'] = $args['is_starred'] ? '1' : '0';
                }
                if ( isset( $args['field_values'] ) && is_array( $args['field_values'] ) ) {
                    foreach ( $args['field_values'] as $gf_fid => $gf_fval ) {
                        $gf_entry[ $gf_fid ] = is_string( $gf_fval ) ? sanitize_text_field( $gf_fval ) : $gf_fval;
                    }
                }
                $gf_result = GFAPI::update_entry( $gf_entry );
                if ( is_wp_error( $gf_result ) ) {
                    $r['error'] = array( 'code' => -32603, 'message' => $gf_result->get_error_message() );
                    break;
                }
                $addResultText( $r, wp_json_encode( array( 'entry_id' => $gf_entry_id, 'updated' => true ), JSON_PRETTY_PRINT ) );
                break;

            // ── Forminator ────────────────────────────────────────────────────
            case 'forminator_list_forms':
                if ( ! class_exists( 'Forminator_API' ) ) {
                    $r['error'] = array( 'code' => -32603, 'message' => 'Forminator plugin is not active.' );
                    break;
                }
                $fi_type  = sanitize_text_field( $utils::getArrayValue( $args, 'type', 'custom-forms' ) );
                $fi_limit = max( 1, intval( $utils::getArrayValue( $args, 'limit', 50 ) ) );
                switch ( $fi_type ) {
                    case 'poll':
                        $fi_forms = Forminator_API::get_polls( null, $fi_limit );
                        break;
                    case 'quiz':
                        $fi_forms = Forminator_API::get_quizzes( null, $fi_limit );
                        break;
                    default:
                        $fi_forms = Forminator_API::get_forms( null, $fi_limit );
                }
                if ( is_wp_error( $fi_forms ) ) {
                    $r['error'] = array( 'code' => -32603, 'message' => $fi_forms->get_error_message() );
                    break;
                }
                $fi_out = array();
                if ( is_array( $fi_forms ) ) {
                    foreach ( $fi_forms as $fi_form ) {
                        $fi_out[] = array(
                            'id'     => $fi_form->id,
                            'name'   => isset( $fi_form->settings['formName'] ) ? $fi_form->settings['formName'] : '',
                            'status' => isset( $fi_form->settings['status'] ) ? $fi_form->settings['status'] : '',
                        );
                    }
                }
                $addResultText( $r, wp_json_encode( $fi_out, JSON_PRETTY_PRINT ) );
                break;
            case 'forminator_get_entries':
                if ( ! class_exists( 'Forminator_API' ) ) {
                    $r['error'] = array( 'code' => -32603, 'message' => 'Forminator plugin is not active.' );
                    break;
                }
                $fi_form_id  = intval( $utils::getArrayValue( $args, 'form_id', 0 ) );
                if ( ! $fi_form_id ) {
                    $r['error'] = array( 'code' => -32602, 'message' => 'Missing required parameter: form_id.' );
                    break;
                }
                $fi_per_page = max( 1, intval( $utils::getArrayValue( $args, 'per_page', 20 ) ) );
                $fi_page     = max( 1, intval( $utils::getArrayValue( $args, 'page', 1 ) ) );
                $fi_entries  = Forminator_API::get_entries( $fi_form_id, $fi_page, $fi_per_page );
                if ( is_wp_error( $fi_entries ) ) {
                    $r['error'] = array( 'code' => -32603, 'message' => $fi_entries->get_error_message() );
                    break;
                }
                $fi_out = array();
                if ( is_array( $fi_entries ) ) {
                    foreach ( $fi_entries as $fi_entry ) {
                        $fi_out[] = array(
                            'entry_id'   => $fi_entry->entry_id,
                            'form_id'    => $fi_entry->form_id,
                            'date_created' => $fi_entry->date_created,
                            'fields'     => $fi_entry->meta_data,
                        );
                    }
                }
                $addResultText( $r, wp_json_encode( $fi_out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
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
                // Redact secrets before exposing meta values to the LLM.
                if ( StifliFlexMcpUtils::keyLooksSensitive( $meta_key ) ) {
                    $value = is_scalar( $value ) && '' !== (string) $value ? '[REDACTED]' : $value;
                } else {
                    $value = StifliFlexMcpUtils::redactSecrets( $value, $meta_key );
                }
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
                // Redact secrets recursively. If the option key itself looks
                // sensitive (e.g. *_api_key), the whole value is masked.
                if ( StifliFlexMcpUtils::keyLooksSensitive( $option ) ) {
                    $val = is_scalar( $val ) && '' !== (string) $val ? '[REDACTED]' : StifliFlexMcpUtils::redactSecrets( $val, $option );
                } else {
                    $val = StifliFlexMcpUtils::redactSecrets( $val, $option );
                }
                $optionValueLog = wp_json_encode($val, JSON_PRETTY_PRINT);
                if (false === $optionValueLog) {
                    $optionValueLog = '[unserializable]';
                }
                $addResultText($r, 'Valor de opción (' . $option . '): ' . $optionValueLog);
                break;
            case 'wp_get_plugin_settings':
                if (!current_user_can('manage_options')) {
                    $r['error'] = array('code' => 'permission_denied', 'message' => 'No tienes permisos para leer opciones de plugins.');
                    break;
                }

                global $wpdb;

                $plugin_slug = sanitize_key((string) $utils::getArrayValue($args, 'plugin_slug', ''));
                if ('' === $plugin_slug) {
                    $r['error'] = array('code' => 'invalid_params', 'message' => 'plugin_slug is required.');
                    break;
                }

                $limit = intval($utils::getArrayValue($args, 'limit', 100));
                if ($limit <= 0) {
                    $limit = 100;
                }
                if ($limit > 300) {
                    $limit = 300;
                }

                $summary_raw = $utils::getArrayValue($args, 'summary', false);
                $summary = in_array($summary_raw, array(true, 1, '1', 'true', 'yes', 'on'), true);

                $known_mappings = array(
                    'rank-math'       => array('rank_math', 'rank-math', 'rankmath'),
                    'yoast'           => array('wpseo', 'yoast'),
                    'woocommerce'     => array('woocommerce', 'wc_'),
                    'acf'             => array('acf'),
                    'elementor'       => array('elementor', '_elementor'),
                    'wpforms'         => array('wpforms'),
                    'stifli-flex-mcp' => array('sflmcp', 'stifli_flex_mcp'),
                );

                $base_variants = array(
                    $plugin_slug,
                    str_replace('-', '_', $plugin_slug),
                    str_replace('_', '-', $plugin_slug),
                );

                $prefix_map = array();
                foreach ($base_variants as $variant) {
                    $clean_variant = sanitize_key((string) $variant);
                    if ('' !== $clean_variant) {
                        $prefix_map[$clean_variant] = true;
                    }
                }

                if (isset($known_mappings[$plugin_slug]) && is_array($known_mappings[$plugin_slug])) {
                    foreach ($known_mappings[$plugin_slug] as $mapped_prefix) {
                        $clean_prefix = sanitize_key((string) $mapped_prefix);
                        if ('' !== $clean_prefix) {
                            $prefix_map[$clean_prefix] = true;
                        }
                    }
                }

                $custom_prefixes = $utils::getArrayValue($args, 'option_prefixes', array());
                if (is_array($custom_prefixes)) {
                    foreach ($custom_prefixes as $custom_prefix) {
                        $clean_prefix = sanitize_key((string) $custom_prefix);
                        if ('' !== $clean_prefix) {
                            $prefix_map[$clean_prefix] = true;
                        }
                    }
                }

                $exact_matches = array_keys($prefix_map);

                $like_prefixes = array();
                foreach ($base_variants as $variant) {
                    $clean_variant = sanitize_key((string) $variant);
                    if ('' !== $clean_variant) {
                        // Required explicit pattern: plugin_slug + '_%'.
                        $like_prefixes[$clean_variant . '_'] = true;
                    }
                }
                foreach ($exact_matches as $exact_prefix) {
                    $like_prefixes[$exact_prefix] = true;
                }

                $where = array();
                $params = array();

                foreach ($exact_matches as $exact_match) {
                    $where[] = 'option_name = %s';
                    $params[] = $exact_match;
                }
                foreach (array_keys($like_prefixes) as $like_prefix) {
                    $where[] = 'option_name LIKE %s';
                    $params[] = $wpdb->esc_like($like_prefix) . '%';
                }

                if (empty($where)) {
                    $payload = array(
                        'count' => 0,
                        'options' => array(),
                    );
                    $r['result'] = array(
                        'content' => array(
                            array('type' => 'text', 'text' => wp_json_encode($payload, JSON_PRETTY_PRINT)),
                        ),
                    );
                    break;
                }

                $params[] = $limit;
                // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Dynamic WHERE SQL is composed from static placeholder fragments; values are bound via $wpdb->prepare.
                $rows = $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT option_name, option_value FROM {$wpdb->options} WHERE (" . implode(' OR ', $where) . ') ORDER BY option_name ASC LIMIT %d',
                        $params
                    ),
                    ARRAY_A
                );
                // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

                $count_redactions = function($value) use (&$count_redactions) {
                    if (is_string($value) && '[REDACTED]' === $value) {
                        return 1;
                    }
                    if (is_array($value)) {
                        $sum = 0;
                        foreach ($value as $child) {
                            $sum += $count_redactions($child);
                        }
                        return $sum;
                    }
                    if (is_object($value)) {
                        return $count_redactions((array) $value);
                    }
                    return 0;
                };

                $result_options = array();
                $example_keys = array();
                $sensitive_fields_detected = 0;

                if (!is_array($rows)) {
                    $rows = array();
                }

                foreach ($rows as $row) {
                    $option_name = isset($row['option_name']) ? (string) $row['option_name'] : '';
                    $raw_value = isset($row['option_value']) ? maybe_unserialize($row['option_value']) : null;
                    // First apply strict key-based redaction helper, then run the
                    // existing recursive/value-pattern redaction as a second pass.
                    $redacted_value = sflmcp_redact_sensitive_value($raw_value, $option_name);
                    $redacted_value = StifliFlexMcpUtils::redactSecrets($redacted_value, $option_name);
                    $sensitive_fields_detected += $count_redactions($redacted_value);

                    if (count($example_keys) < 20) {
                        $example_keys[] = $option_name;
                    }

                    if (!$summary) {
                        $result_options[] = array(
                            'option_name' => $option_name,
                            'option_value' => $redacted_value,
                        );
                    }
                }

                if ($summary) {
                    $payload = array(
                        'total_options' => count($rows),
                        'example_keys' => $example_keys,
                        'sensitive_fields_detected' => (int) $sensitive_fields_detected,
                    );
                } else {
                    $payload = array(
                        'count' => count($result_options),
                        'options' => $result_options,
                    );
                }

                stifli_flex_mcp_log('wp_get_plugin_settings executed', array(
                    'plugin_slug' => $plugin_slug,
                    'rows' => count($rows),
                    'summary' => $summary,
                    'limit' => $limit,
                ));

                $payload_json = wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                if (false === $payload_json) {
                    $payload_json = '{}';
                }
                $r['result'] = array(
                    'content' => array(
                        array('type' => 'text', 'text' => $payload_json),
                    ),
                );
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
                // Hard denylist + sensitive-pattern check + optional allowlist.
                $writable = StifliFlexMcpUtils::checkOptionWritable( $option );
                if ( true !== $writable ) {
                    $messages = array(
                        'option_denied_hard'              => 'This option is on the hard denylist and cannot be modified via MCP.',
                        'option_denied_sensitive_pattern' => 'This option name matches a sensitive pattern (key/secret/token/etc.) and cannot be modified via MCP.',
                        'option_not_in_allowlist'         => 'Strict mode is enabled and this option is not in the writable allowlist (filter sflmcp_writable_options).',
                        'invalid_option'                  => 'Invalid option name.',
                    );
                    $msg = isset( $messages[ $writable ] ) ? $messages[ $writable ] : 'Option write blocked.';
                    $r['error'] = array( 'code' => 'option_write_blocked', 'message' => $msg . ' (' . $option . ')' );
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
            // wp_delete_option intentionally removed: too destructive without reliable undo.
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
                    $clean_key = sanitize_text_field($key);
                    $raw       = get_option($clean_key);
                    if ( StifliFlexMcpUtils::keyLooksSensitive( $clean_key ) ) {
                        $settings[$clean_key] = is_scalar($raw) && '' !== (string) $raw ? '[REDACTED]' : StifliFlexMcpUtils::redactSecrets( $raw, $clean_key );
                    } else {
                        $settings[$clean_key] = StifliFlexMcpUtils::redactSecrets( $raw, $clean_key );
                    }
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
                $blocked = array();
                foreach ($settings as $key => $value) {
                    $key = sanitize_text_field($key);
                    $writable = StifliFlexMcpUtils::checkOptionWritable( $key );
                    if ( true !== $writable ) {
                        $blocked[$key] = $writable;
                        continue;
                    }
                    $result = update_option($key, $value);
                    $updated[$key] = $result;
                }
                $msg = 'Configuración actualizada: ' . wp_json_encode($updated, JSON_PRETTY_PRINT);
                if ( ! empty( $blocked ) ) {
                    $msg .= "\nOpciones bloqueadas (no se modificaron): " . wp_json_encode( $blocked );
                }
                $addResultText($r, $msg);
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
                $audit_level = $this->normalizeSiteHealthAuditLevel( $utils::getArrayValue( $args, 'level', 0 ) );
                $health_report = $this->getSiteHealthAuditReport( $audit_level );
                $summary = isset($health_report['summary']) && is_array($health_report['summary']) ? $health_report['summary'] : array();

                $headline = sprintf(
                    'Site audit level %d (%s). Score %d/100 (%s). Critical issues: %d. Recommended improvements: %d. Checks run: %d.',
                    intval( $health_report['audit_level']['value'] ?? $audit_level ),
                    isset( $health_report['audit_level']['label'] ) ? $health_report['audit_level']['label'] : 'basic',
                    intval( $summary['score'] ?? 0 ),
                    isset( $summary['overall_status'] ) ? $summary['overall_status'] : 'unknown',
                    intval( $summary['critical'] ?? 0 ),
                    intval( $summary['recommended'] ?? 0 ),
                    intval( $summary['tests_run'] ?? 0 )
                );

                $addResultText(
                    $r,
                    $headline . "\n\n" . wp_json_encode( $health_report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES )
                );
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
     * Build an extended Site Health audit report.
     *
     * @param int $level Audit depth: 0 basic, 1 medium, 2 deep.
     * @return array
     */
    private function getSiteHealthAuditReport( $level = 0 ) {
        global $wpdb;

        $level = $this->normalizeSiteHealthAuditLevel( $level );

        if ( $level >= 2 && file_exists( ABSPATH . 'wp-admin/includes/admin.php' ) ) {
            require_once ABSPATH . 'wp-admin/includes/admin.php';
        }

        if ( ! function_exists( 'get_plugins' ) && file_exists( ABSPATH . 'wp-admin/includes/plugin.php' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        if ( ! class_exists( 'WP_Site_Health' ) && file_exists( ABSPATH . 'wp-admin/includes/class-wp-site-health.php' ) ) {
            require_once ABSPATH . 'wp-admin/includes/class-wp-site-health.php';
        }

        if ( ! class_exists( 'WP_Debug_Data' ) && file_exists( ABSPATH . 'wp-admin/includes/class-wp-debug-data.php' ) ) {
            require_once ABSPATH . 'wp-admin/includes/class-wp-debug-data.php';
        }

        $theme          = wp_get_theme();
        $uploads        = wp_upload_dir();
        $all_plugins    = function_exists( 'get_plugins' ) ? get_plugins() : array();
        $active_plugins = (array) get_option( 'active_plugins', array() );
        $memory_limit   = defined( 'WP_MEMORY_LIMIT' ) ? WP_MEMORY_LIMIT : '';
        $max_memory     = defined( 'WP_MAX_MEMORY_LIMIT' ) ? WP_MAX_MEMORY_LIMIT : '';

        $site_profile = array(
            'wordpress' => array(
                'version'           => get_bloginfo( 'version' ),
                'environment_type'  => function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production',
                'site_url'          => get_site_url(),
                'home_url'          => get_home_url(),
                'is_multisite'      => is_multisite(),
                'language'          => get_bloginfo( 'language' ),
                'https'             => is_ssl(),
            ),
            'server' => array(
                'php_version'       => phpversion(),
                'server_software'   => isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : 'Unknown',
                'database_version'  => method_exists( $wpdb, 'db_version' ) ? $wpdb->db_version() : '',
                'database_charset'  => defined( 'DB_CHARSET' ) ? DB_CHARSET : '',
                'table_prefix'      => $wpdb->prefix,
            ),
            'theme' => array(
                'name'              => $theme->get( 'Name' ),
                'version'           => $theme->get( 'Version' ),
                'parent_theme'      => $theme->get( 'Template' ),
            ),
            'plugins' => array(
                'active'            => count( $active_plugins ),
                'inactive'          => max( 0, count( $all_plugins ) - count( $active_plugins ) ),
                'total'             => count( $all_plugins ),
            ),
            'runtime' => array(
                'wp_memory_limit'       => $memory_limit,
                'wp_memory_limit_bytes' => function_exists( 'wp_convert_hr_to_bytes' ) ? wp_convert_hr_to_bytes( $memory_limit ) : null,
                'wp_max_memory_limit'   => $max_memory,
                'wp_max_memory_bytes'   => function_exists( 'wp_convert_hr_to_bytes' ) ? wp_convert_hr_to_bytes( $max_memory ) : null,
                'object_cache'          => function_exists( 'wp_using_ext_object_cache' ) ? wp_using_ext_object_cache() : false,
                'wp_cron_disabled'      => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
                'alternate_wp_cron'     => defined( 'ALTERNATE_WP_CRON' ) && ALTERNATE_WP_CRON,
            ),
            'debug' => array(
                'wp_debug'          => defined( 'WP_DEBUG' ) && WP_DEBUG,
                'wp_debug_log'      => defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG,
                'wp_debug_display'  => defined( 'WP_DEBUG_DISPLAY' ) && WP_DEBUG_DISPLAY,
                'script_debug'      => defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG,
            ),
            'paths' => array(
                'content_dir'       => defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : '',
                'plugin_dir'        => defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR : '',
                'uploads_dir'       => is_array( $uploads ) && isset( $uploads['basedir'] ) ? $uploads['basedir'] : 'Unknown',
            ),
        );

        if ( class_exists( 'WooCommerce' ) && function_exists( 'WC' ) && WC() ) {
            $site_profile['woocommerce'] = array(
                'version'          => WC()->version,
                'database_version' => get_option( 'woocommerce_db_version' ),
            );
        }

        $tests            = $this->collectSiteHealthTests( $level );
        $analysis         = $this->buildSiteHealthAuditSummary( $tests );
        $pending_updates  = $this->getSiteHealthUpdateSnapshot( $all_plugins );
        $directory_sizes  = $level >= 2
            ? $this->getSiteHealthDirectorySizes()
            : array(
                'skipped' => true,
                'reason'  => 'Directory sizes are only included at level 2 (deep) to avoid slow scans.',
            );

        $tests_mode = 'selected direct tests';
        if ( 1 === $level ) {
            $tests_mode = 'all direct Site Health tests';
        } elseif ( 2 === $level ) {
            $tests_mode = 'all direct and async Site Health tests';
        }

        return array(
            'audit_level'   => array(
                'value'        => $level,
                'label'        => $this->getSiteHealthAuditLevelLabel( $level ),
                'tests_mode'   => $tests_mode,
                'storage_scan' => $level >= 2,
            ),
            'summary'       => $analysis['summary'],
            'site_profile'  => $site_profile,
            'updates'       => $pending_updates,
            'storage'       => $directory_sizes,
            'audit'         => array(
                'top_findings'               => $analysis['top_findings'],
                'prioritized_recommendations'=> $analysis['prioritized_recommendations'],
            ),
            'health_checks' => array(
                'counts'             => $analysis['counts'],
                'counts_by_category' => $analysis['counts_by_category'],
                'tests'              => $tests,
            ),
        );
    }

    /**
     * Collect Site Health tests that can run directly in the current request.
     *
     * @param int $level Audit depth.
     * @return array
     */
    private function collectSiteHealthTests( $level = 0 ) {
        if ( ! class_exists( 'WP_Site_Health' ) || ! method_exists( 'WP_Site_Health', 'get_tests' ) ) {
            return array();
        }

        $level = $this->normalizeSiteHealthAuditLevel( $level );

        $site_health = method_exists( 'WP_Site_Health', 'get_instance' )
            ? WP_Site_Health::get_instance()
            : new WP_Site_Health();

        $tests = WP_Site_Health::get_tests();
        $basic_direct_tests = array(
            'wordpress_version',
            'plugin_version',
            'theme_version',
            'php_version',
            'php_extensions',
            'php_default_timezone',
            'php_sessions',
            'sql_server',
            'utf8mb4_support',
            'debug_enabled',
            'file_uploads',
            'plugin_theme_auto_updates',
            'opcode_cache',
        );

        if (
            function_exists( 'wp_get_environment_type' )
            && 'development' === wp_get_environment_type()
            && isset( $tests['async']['https_status'] )
        ) {
            unset( $tests['async']['https_status'] );
        }

        if ( 0 === $level ) {
            $tests['direct'] = array_filter(
                $tests['direct'],
                function( $identifier ) use ( $basic_direct_tests ) {
                    return in_array( $identifier, $basic_direct_tests, true );
                },
                ARRAY_FILTER_USE_KEY
            );
            $tests['async'] = array();
        } elseif ( 1 === $level ) {
            $tests['async'] = array();
        }

        $results = array();

        foreach ( $tests['direct'] as $identifier => $test_definition ) {
            $results[] = $this->normalizeSiteHealthTestResult(
                $this->executeSiteHealthTest( $site_health, $test_definition, false ),
                $identifier,
                $test_definition['label'] ?? ''
            );
        }

        foreach ( $tests['async'] as $identifier => $test_definition ) {
            $results[] = $this->normalizeSiteHealthTestResult(
                $this->executeSiteHealthTest( $site_health, $test_definition, true ),
                $identifier,
                $test_definition['label'] ?? ''
            );
        }

        return $results;
    }

    /**
     * Execute a Site Health test definition.
     *
     * @param WP_Site_Health $site_health     Site health instance.
     * @param array          $test_definition Test definition.
     * @param bool           $is_async        Whether this is an async test.
     * @return array
     */
    private function executeSiteHealthTest( $site_health, $test_definition, $is_async = false ) {
        try {
            if ( $is_async ) {
                if ( ! empty( $test_definition['async_direct_test'] ) && is_callable( $test_definition['async_direct_test'] ) ) {
                    return call_user_func( $test_definition['async_direct_test'] );
                }

                if ( isset( $test_definition['test'] ) && is_callable( $test_definition['test'] ) ) {
                    return call_user_func( $test_definition['test'] );
                }
            } else {
                if ( isset( $test_definition['test'] ) && is_string( $test_definition['test'] ) ) {
                    $test_function = sprintf( 'get_test_%s', $test_definition['test'] );

                    if ( method_exists( $site_health, $test_function ) && is_callable( array( $site_health, $test_function ) ) ) {
                        return call_user_func( array( $site_health, $test_function ) );
                    }
                }

                if ( isset( $test_definition['test'] ) && is_callable( $test_definition['test'] ) ) {
                    return call_user_func( $test_definition['test'] );
                }
            }
        } catch ( Throwable $e ) {
            return array(
                'test'        => isset( $test_definition['test'] ) && is_string( $test_definition['test'] ) ? sanitize_key( $test_definition['test'] ) : 'unknown',
                'label'       => isset( $test_definition['label'] ) ? $test_definition['label'] : 'Site Health test failed to execute',
                'status'      => 'recommended',
                'badge'       => array(
                    'label' => 'System',
                    'color' => 'gray',
                ),
                'description' => 'The test could not be executed directly in the current context: ' . $e->getMessage(),
                'actions'     => '',
            );
        }

        return array(
            'test'        => isset( $test_definition['test'] ) && is_string( $test_definition['test'] ) ? sanitize_key( $test_definition['test'] ) : 'unknown',
            'label'       => isset( $test_definition['label'] ) ? $test_definition['label'] : 'Site Health test not available',
            'status'      => 'recommended',
            'badge'       => array(
                'label' => 'System',
                'color' => 'gray',
            ),
            'description' => 'The test could not be executed directly in the current context.',
            'actions'     => '',
        );
    }

    /**
     * Normalize a Site Health test result for MCP output.
     *
     * @param mixed  $result         Test result.
     * @param string $identifier     Test identifier.
     * @param string $fallback_label Fallback label.
     * @return array
     */
    private function normalizeSiteHealthTestResult( $result, $identifier, $fallback_label ) {
        if ( is_object( $result ) ) {
            $result = (array) $result;
        }

        if ( ! is_array( $result ) ) {
            $result = array();
        }

        $status = isset( $result['status'] ) && in_array( $result['status'], array( 'good', 'recommended', 'critical' ), true )
            ? $result['status']
            : 'recommended';

        $badge = isset( $result['badge'] ) && is_array( $result['badge'] ) ? $result['badge'] : array();
        $category = isset( $badge['label'] ) ? $this->normalizeSiteHealthText( $badge['label'] ) : 'General';

        return array(
            'id'          => sanitize_key( $identifier ),
            'test'        => isset( $result['test'] ) ? sanitize_key( $result['test'] ) : sanitize_key( $identifier ),
            'label'       => $this->normalizeSiteHealthText( $result['label'] ?? $fallback_label ),
            'status'      => $status,
            'category'    => $category,
            'badge'       => array(
                'label' => $category,
                'color' => isset( $badge['color'] ) ? sanitize_key( $badge['color'] ) : 'gray',
            ),
            'description' => $this->normalizeSiteHealthText( $result['description'] ?? '' ),
            'actions'     => $this->normalizeSiteHealthText( $result['actions'] ?? '' ),
        );
    }

    /**
     * Build an executive summary from Site Health tests.
     *
     * @param array $tests Normalized Site Health tests.
     * @return array
     */
    private function buildSiteHealthAuditSummary( $tests ) {
        $counts = array(
            'total'       => 0,
            'good'        => 0,
            'recommended' => 0,
            'critical'    => 0,
            'skipped'     => 0,
        );
        $counts_by_category = array();
        $findings = array();

        foreach ( $tests as $test ) {
            $status = isset( $test['status'] ) ? $test['status'] : 'recommended';
            if ( ! isset( $counts[ $status ] ) ) {
                $counts['skipped']++;
                continue;
            }

            $counts['total']++;
            $counts[ $status ]++;

            $category = isset( $test['category'] ) && '' !== $test['category'] ? $test['category'] : 'General';
            if ( ! isset( $counts_by_category[ $category ] ) ) {
                $counts_by_category[ $category ] = array(
                    'good'        => 0,
                    'recommended' => 0,
                    'critical'    => 0,
                );
            }

            if ( isset( $counts_by_category[ $category ][ $status ] ) ) {
                $counts_by_category[ $category ][ $status ]++;
            }

            if ( 'good' !== $status ) {
                $findings[] = array(
                    'status'      => $status,
                    'category'    => $category,
                    'test'        => $test['test'] ?? '',
                    'label'       => $test['label'] ?? '',
                    'description' => $test['description'] ?? '',
                    'actions'     => $test['actions'] ?? '',
                );
            }
        }

        usort(
            $findings,
            function( $left, $right ) {
                $priority = array(
                    'critical'    => 0,
                    'recommended' => 1,
                    'good'        => 2,
                );

                $left_priority  = $priority[ $left['status'] ] ?? 9;
                $right_priority = $priority[ $right['status'] ] ?? 9;

                if ( $left_priority === $right_priority ) {
                    return strcmp( $left['label'], $right['label'] );
                }

                return $left_priority - $right_priority;
            }
        );

        $recommendations = array();
        foreach ( $findings as $finding ) {
            $action_text = ! empty( $finding['actions'] ) ? $finding['actions'] : $finding['description'];
            if ( empty( $action_text ) ) {
                $action_text = $finding['label'];
            }

            $recommendation_key = md5( strtolower( $finding['test'] . '|' . $action_text ) );
            if ( isset( $recommendations[ $recommendation_key ] ) ) {
                continue;
            }

            $recommendations[ $recommendation_key ] = array(
                'status'   => $finding['status'],
                'category' => $finding['category'],
                'test'     => $finding['test'],
                'label'    => $finding['label'],
                'action'   => $action_text,
            );
        }

        $score = max( 0, 100 - ( $counts['critical'] * 20 ) - ( $counts['recommended'] * 7 ) );
        if ( 0 === $counts['total'] ) {
            $score = 0;
        }

        $overall_status = 'good';
        if ( $counts['critical'] > 0 ) {
            $overall_status = 'critical';
        } elseif ( $counts['recommended'] > 0 ) {
            $overall_status = 'recommended';
        }

        return array(
            'summary' => array(
                'overall_status' => $overall_status,
                'score'          => $score,
                'tests_run'      => $counts['total'],
                'good'           => $counts['good'],
                'recommended'    => $counts['recommended'],
                'critical'       => $counts['critical'],
            ),
            'counts'                    => $counts,
            'counts_by_category'        => $counts_by_category,
            'top_findings'              => array_slice( $findings, 0, 8 ),
            'prioritized_recommendations' => array_values( array_slice( $recommendations, 0, 8, true ) ),
        );
    }

    /**
     * Get pending update information without forcing remote refreshes.
     *
     * @param array $all_plugins All installed plugins.
     * @return array
     */
    private function getSiteHealthUpdateSnapshot( $all_plugins ) {
        $snapshot = array(
            'core'    => array(
                'pending' => 0,
                'items'   => array(),
            ),
            'plugins' => array(
                'pending' => 0,
                'items'   => array(),
            ),
            'themes'  => array(
                'pending' => 0,
                'items'   => array(),
            ),
        );

        $core_updates = get_site_transient( 'update_core' );
        if ( is_object( $core_updates ) && ! empty( $core_updates->updates ) && is_array( $core_updates->updates ) ) {
            foreach ( $core_updates->updates as $update ) {
                if ( ! is_object( $update ) ) {
                    continue;
                }

                $response = isset( $update->response ) ? (string) $update->response : '';
                if ( 'latest' === $response ) {
                    continue;
                }

                $snapshot['core']['items'][] = array(
                    'current_version' => get_bloginfo( 'version' ),
                    'new_version'     => isset( $update->current ) ? sanitize_text_field( $update->current ) : '',
                    'response'        => $response,
                );
            }
        }
        $snapshot['core']['pending'] = count( $snapshot['core']['items'] );

        $plugin_updates = get_site_transient( 'update_plugins' );
        if ( is_object( $plugin_updates ) && ! empty( $plugin_updates->response ) ) {
            foreach ( (array) $plugin_updates->response as $plugin_file => $update ) {
                if ( ! is_object( $update ) ) {
                    continue;
                }

                $snapshot['plugins']['items'][] = array(
                    'plugin'          => isset( $all_plugins[ $plugin_file ]['Name'] ) ? $all_plugins[ $plugin_file ]['Name'] : $plugin_file,
                    'file'            => $plugin_file,
                    'current_version' => isset( $all_plugins[ $plugin_file ]['Version'] ) ? $all_plugins[ $plugin_file ]['Version'] : '',
                    'new_version'     => isset( $update->new_version ) ? sanitize_text_field( $update->new_version ) : '',
                );
            }
        }
        $snapshot['plugins']['pending'] = count( $snapshot['plugins']['items'] );

        $theme_updates = get_site_transient( 'update_themes' );
        $all_themes    = wp_get_themes();
        if ( is_object( $theme_updates ) && ! empty( $theme_updates->response ) && is_array( $theme_updates->response ) ) {
            foreach ( $theme_updates->response as $stylesheet => $update ) {
                if ( ! is_array( $update ) ) {
                    continue;
                }

                $theme = isset( $all_themes[ $stylesheet ] ) ? $all_themes[ $stylesheet ] : null;
                $snapshot['themes']['items'][] = array(
                    'theme'           => $theme ? $theme->get( 'Name' ) : $stylesheet,
                    'stylesheet'      => $stylesheet,
                    'current_version' => $theme ? $theme->get( 'Version' ) : '',
                    'new_version'     => isset( $update['new_version'] ) ? sanitize_text_field( $update['new_version'] ) : '',
                );
            }
        }
        $snapshot['themes']['pending'] = count( $snapshot['themes']['items'] );

        return $snapshot;
    }

    /**
     * Get directory sizes from WordPress debug data.
     *
     * @return array
     */
    private function getSiteHealthDirectorySizes() {
        if ( ! class_exists( 'WP_Debug_Data' ) || ! method_exists( 'WP_Debug_Data', 'get_sizes' ) ) {
            return array();
        }

        try {
            $sizes = WP_Debug_Data::get_sizes();
        } catch ( Throwable $e ) {
            return array(
                'error' => $e->getMessage(),
            );
        }

        if ( ! is_array( $sizes ) ) {
            return array();
        }

        $formatted = array();
        $total_raw = 0;

        foreach ( $sizes as $name => $size_data ) {
            if ( ! is_array( $size_data ) ) {
                continue;
            }

            $raw_bytes = isset( $size_data['raw'] ) ? intval( $size_data['raw'] ) : 0;
            if ( $raw_bytes > 0 ) {
                $total_raw += $raw_bytes;
            }

            $formatted[ sanitize_key( $name ) ] = array(
                'size'      => isset( $size_data['size'] ) ? sanitize_text_field( (string) $size_data['size'] ) : ( $raw_bytes > 0 ? size_format( $raw_bytes, 2 ) : '' ),
                'raw_bytes' => $raw_bytes,
            );
        }

        if ( $total_raw > 0 ) {
            $formatted['total'] = array(
                'size'      => size_format( $total_raw, 2 ),
                'raw_bytes' => $total_raw,
            );
        }

        return $formatted;
    }

    /**
     * Strip HTML noise from Site Health text.
     *
     * @param mixed $text Raw Site Health text.
     * @return string
     */
    private function normalizeSiteHealthText( $text ) {
        if ( is_array( $text ) || is_object( $text ) ) {
            $text = wp_json_encode( $text );
        }

        if ( ! is_string( $text ) || '' === $text ) {
            return '';
        }

        $text = html_entity_decode( wp_strip_all_tags( $text, true ), ENT_QUOTES, get_bloginfo( 'charset' ) ?: 'UTF-8' );
        $text = preg_replace( '/\s+/', ' ', $text );

        return trim( (string) $text );
    }

    /**
     * Normalize requested audit depth.
     *
     * @param mixed $level Requested level.
     * @return int
     */
    private function normalizeSiteHealthAuditLevel( $level ) {
        $level = intval( $level );

        if ( $level < 0 ) {
            return 0;
        }

        if ( $level > 2 ) {
            return 2;
        }

        return $level;
    }

    /**
     * Get human-readable audit level label.
     *
     * @param int $level Audit level.
     * @return string
     */
    private function getSiteHealthAuditLevelLabel( $level ) {
        $level = $this->normalizeSiteHealthAuditLevel( $level );

        if ( 2 === $level ) {
            return 'deep';
        }

        if ( 1 === $level ) {
            return 'medium';
        }

        return 'basic';
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
