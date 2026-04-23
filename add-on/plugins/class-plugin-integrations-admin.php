<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class StifliFlexMcp_Plugin_Integrations_Admin {

    const OPTION_KEY = 'sflmcp_plugin_integrations_state';

    /**
     * @var StifliFlexMcp|null
     */
    private $host;

    public function __construct( $host = null ) {
        $this->host = $host;
    }

    public function init() {
        add_filter( 'sflmcp_admin_tabs', array( $this, 'register_tab' ), 15, 3 );
        add_filter( 'sflmcp_admin_tab_renderers', array( $this, 'register_tab_renderer' ), 15, 2 );
        add_action( 'sflmcp_admin_enqueue_tab_assets', array( $this, 'enqueue_tab_assets' ), 10, 3 );
        add_action( 'admin_init', array( $this, 'handle_settings_post' ) );
        add_action( 'wp_ajax_sflmcp_save_plugin_integrations', array( $this, 'ajax_save_plugin_integrations' ) );
        add_action( 'wp_ajax_sflmcp_discover_plugin_abilities', array( $this, 'ajax_discover_plugin_abilities' ) );
        add_filter( 'sflmcp_is_tool_enabled_for_integrations', array( $this, 'filter_tool_enabled' ), 10, 4 );
    }

    public function ajax_save_plugin_integrations() {
        check_ajax_referer( 'sflmcp_plugins_integrations_save', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'stifli-flex-mcp' ) ), 403 );
        }

        $enabled_groups = isset( $_POST['enabled_groups'] ) && is_array( $_POST['enabled_groups'] )
            ? array_map( 'sanitize_key', wp_unslash( $_POST['enabled_groups'] ) )
            : array();

        $enabled_tools_by_integration = isset( $_POST['enabled_tools'] ) && is_array( $_POST['enabled_tools'] )
            ? $this->sanitize_enabled_tools_input( wp_unslash( $_POST['enabled_tools'] ) )
            : array();

        $state_build = $this->build_integrations_state( $enabled_groups, $enabled_tools_by_integration );
        $state = $state_build['state'];
        $this->save_state( $state );

        wp_send_json_success(
            array(
                'message' => __( 'Plugin integrations saved.', 'stifli-flex-mcp' ),
                'state' => $state,
                'reload' => ! empty( $state_build['imported_count'] ),
            )
        );
    }

    public function ajax_discover_plugin_abilities() {
        check_ajax_referer( 'sflmcp_plugins_integrations_save', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'stifli-flex-mcp' ) ), 403 );
        }

        $integration_id = isset( $_POST['integration_id'] ) ? sanitize_key( wp_unslash( $_POST['integration_id'] ) ) : '';

        if ( '' === $integration_id ) {
            wp_send_json_error( array( 'message' => __( 'Integration ID required.', 'stifli-flex-mcp' ) ) );
        }

        $integration = null;
        $integrations = StifliFlexMcp_Plugin_Integrations_Registry::get_integrations();
        foreach ( $integrations as $candidate ) {
            $candidate_id = isset( $candidate['id'] ) ? sanitize_key( $candidate['id'] ) : '';
            if ( $integration_id === $candidate_id ) {
                $integration = $candidate;
                break;
            }
        }

        if ( ! is_array( $integration ) ) {
            wp_send_json_error( array( 'message' => __( 'Unknown integration.', 'stifli-flex-mcp' ) ) );
        }

        $status = $this->get_plugin_status( $integration );
        if ( empty( $status['is_active'] ) ) {
            wp_send_json_error( array( 'message' => __( 'Activate the plugin first, then discover abilities.', 'stifli-flex-mcp' ) ) );
        }

        // Execute import for this single integration (without enabling it).
        $stats = array();
        $imported_count = $this->auto_import_abilities_for_enabled_integrations( array( $integration_id ), $stats );

        // Re-save current state so imported/reactivated tools are reflected in active profile memberships.
        $this->save_state( $this->get_state() );

        $already_existing = isset( $stats['already_existing'] ) ? intval( $stats['already_existing'] ) : 0;
        if ( $imported_count === 0 && $already_existing > 0 ) {
            wp_send_json_success(
                array(
                    /* translators: %d: number of abilities already imported. */
                    'message' => sprintf( __( '%d abilities were already imported.', 'stifli-flex-mcp' ), $already_existing ),
                    'imported_count' => 0,
                    'already_existing' => $already_existing,
                )
            );
        }

        if ( $imported_count === 0 ) {
            wp_send_json_error( array( 'message' => __( 'No abilities found for this plugin.', 'stifli-flex-mcp' ) ) );
        }

        wp_send_json_success(
            array(
                /* translators: %d: number of abilities imported. */
                'message' => sprintf( __( 'Imported %d ability/abilities.', 'stifli-flex-mcp' ), $imported_count ),
                'imported_count' => $imported_count,
            )
        );
    }

    public function register_tab( $tabs, $active_tab, $host ) {
        $new_tabs = array();
        foreach ( $tabs as $slug => $label ) {
            $new_tabs[ $slug ] = $label;
            if ( 'wc_tools' === $slug ) {
                $new_tabs['plugins'] = __( 'Plugins', 'stifli-flex-mcp' );
            }
        }

        if ( ! isset( $new_tabs['plugins'] ) ) {
            $new_tabs['plugins'] = __( 'Plugins', 'stifli-flex-mcp' );
        }

        return $new_tabs;
    }

    public function register_tab_renderer( $renderers, $host ) {
        $renderers['plugins'] = array( $this, 'render_plugins_tab' );
        return $renderers;
    }

    public function enqueue_tab_assets( $active_tab, $hook, $host ) {
        if ( 'plugins' === $active_tab && is_object( $host ) && method_exists( $host, 'enqueueCustomToolsAssets' ) ) {
            $host->enqueueCustomToolsAssets();
        }
    }

    public function handle_settings_post() {
        if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $page = isset( $_POST['page'] ) ? sanitize_text_field( wp_unslash( $_POST['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $action = isset( $_POST['sflmcp_plugins_action'] ) ? sanitize_text_field( wp_unslash( $_POST['sflmcp_plugins_action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

        if ( 'sflmcp-server' !== $page || 'save_integrations' !== $action ) {
            return;
        }

        check_admin_referer( 'sflmcp_plugins_integrations_save', 'sflmcp_plugins_nonce' );

        $enabled_groups = isset( $_POST['enabled_groups'] ) && is_array( $_POST['enabled_groups'] )
            ? array_map( 'sanitize_key', wp_unslash( $_POST['enabled_groups'] ) )
            : array();
        $enabled_tools_by_integration = isset( $_POST['enabled_tools'] ) && is_array( $_POST['enabled_tools'] )
            ? $this->sanitize_enabled_tools_input( wp_unslash( $_POST['enabled_tools'] ) )
            : array();
        $bulk_action = isset( $_POST['bulk_action'] ) ? sanitize_key( wp_unslash( $_POST['bulk_action'] ) ) : '';

        $state_build = $this->build_integrations_state( $enabled_groups, $enabled_tools_by_integration );
        $state = $state_build['state'];
        $valid_ids = StifliFlexMcp_Plugin_Integrations_Registry::get_integration_ids();

        if ( 'enable_all' === $bulk_action ) {
            $state['enabled_groups'] = $valid_ids;
        } elseif ( 'disable_all' === $bulk_action ) {
            $state['enabled_groups'] = array();
        } elseif ( 'reset_overrides' === $bulk_action ) {
            $state['disabled_tools'] = array();
        }

        $this->save_state( $state );

        $redirect = add_query_arg(
            array(
                'page' => 'sflmcp-server',
                'tab' => 'plugins',
                'settings-updated' => '1',
            ),
            admin_url( 'admin.php' )
        );

        wp_safe_redirect( $redirect );
        exit;
    }

    private function build_integrations_state( $enabled_groups, $enabled_tools_by_integration ) {
        $valid_ids = StifliFlexMcp_Plugin_Integrations_Registry::get_integration_ids();
        $enabled_groups = is_array( $enabled_groups ) ? array_map( 'sanitize_key', $enabled_groups ) : array();
        $enabled_groups = array_values( array_intersect( $enabled_groups, $valid_ids ) );

        $imported_count = $this->auto_import_abilities_for_enabled_integrations( $enabled_groups );

        $implemented_tools = array();
        if ( class_exists( 'StifliFlexMcpModel' ) ) {
            $model = new StifliFlexMcpModel();
            $tools_map = $model->getTools();
            if ( is_array( $tools_map ) ) {
                $implemented_tools = array_keys( $tools_map );
            }
        }

        $managed_tools_by_integration = array();
        $all_managed_tools = array();
        $integrations = StifliFlexMcp_Plugin_Integrations_Registry::get_integrations();
        foreach ( $integrations as $integration ) {
            $integration_id = isset( $integration['id'] ) ? sanitize_key( $integration['id'] ) : '';
            if ( '' === $integration_id ) {
                continue;
            }

            $managed_tools = $this->get_matching_tools( $integration, $implemented_tools );
            $managed_tools_by_integration[ $integration_id ] = $managed_tools;
            foreach ( $managed_tools as $tool_name ) {
                $all_managed_tools[ $tool_name ] = true;
            }
        }

        $selected_enabled_tools = array();
        if ( is_array( $enabled_tools_by_integration ) ) {
            foreach ( $enabled_tools_by_integration as $integration_id => $tool_names ) {
                $integration_id = sanitize_key( $integration_id );
                if ( ! isset( $managed_tools_by_integration[ $integration_id ] ) || ! is_array( $tool_names ) ) {
                    continue;
                }

                $allowed_for_integration = array_fill_keys( $managed_tools_by_integration[ $integration_id ], true );
                foreach ( $tool_names as $tool_name ) {
                    $tool_name = sanitize_key( $tool_name );
                    if ( isset( $allowed_for_integration[ $tool_name ] ) ) {
                        $selected_enabled_tools[ $tool_name ] = true;
                    }
                }
            }
        }

        $disabled_tools = array();
        foreach ( $all_managed_tools as $tool_name => $true_value ) {
            if ( ! isset( $selected_enabled_tools[ $tool_name ] ) ) {
                $disabled_tools[] = $tool_name;
            }
        }

        if ( ! empty( $implemented_tools ) ) {
            $disabled_tools = array_values( array_intersect( $disabled_tools, $implemented_tools ) );
        }

        if ( ! empty( $disabled_tools ) ) {
            $disabled_tools = array_values(
                array_filter(
                    $disabled_tools,
                    static function( $tool_name ) {
                        return ! empty( StifliFlexMcp_Plugin_Integrations_Registry::get_integrations_for_tool( $tool_name ) );
                    }
                )
            );
        }

        return array(
            'state' => array(
                'enabled_groups' => $enabled_groups,
                'disabled_tools' => array_values( array_unique( $disabled_tools ) ),
            ),
            'imported_count' => $imported_count,
        );
    }

    private function auto_import_abilities_for_enabled_integrations( $enabled_groups, &$stats = null ) {
        if ( empty( $enabled_groups ) || ! function_exists( 'stifli_flex_mcp_abilities_available' ) || ! stifli_flex_mcp_abilities_available() || ! function_exists( 'wp_get_ability' ) ) {
            return 0;
        }

        $stats = array(
            'imported' => 0,
            'reactivated' => 0,
            'already_existing' => 0,
            'discovered_total' => 0,
        );

        global $wpdb;
        $table = $wpdb->prefix . 'sflmcp_abilities';
        $table_sql = $this->get_safe_table_sql( $table );
        if ( '' === $table_sql ) {
            return 0;
        }
        $like = $wpdb->esc_like( $table );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- schema check.
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) ) !== $table ) {
            return 0;
        }

        $integrations = StifliFlexMcp_Plugin_Integrations_Registry::get_integrations();
        $integrations_by_id = array();
        foreach ( $integrations as $integration ) {
            if ( ! empty( $integration['id'] ) ) {
                $integrations_by_id[ sanitize_key( $integration['id'] ) ] = $integration;
            }
        }

        $imported_count = 0;
        foreach ( $enabled_groups as $group_id ) {
            if ( empty( $integrations_by_id[ $group_id ] ) ) {
                continue;
            }

            $integration = $integrations_by_id[ $group_id ];

            // Gather ability names: explicit list OR discovered by prefix pattern
            $ability_names = array();

            // 1. Use explicit ability_names if provided
            if ( isset( $integration['ability_names'] ) && is_array( $integration['ability_names'] ) ) {
                $ability_names = array_filter(
                    $integration['ability_names'],
                    static function( $name ) {
                        return is_string( $name ) && '' !== trim( $name );
                    }
                );
            }

            // 2. If no explicit list, discover by prefix pattern from match.prefixes
            if ( empty( $ability_names ) && isset( $integration['match']['prefixes'] ) && is_array( $integration['match']['prefixes'] ) ) {
                $prefixes = array_filter(
                    $integration['match']['prefixes'],
                    static function( $prefix ) {
                        return is_string( $prefix ) && '' !== trim( $prefix );
                    }
                );

                if ( ! empty( $prefixes ) && function_exists( 'stifli_flex_mcp_discover_abilities_by_prefixes' ) ) {
                    $discovered = stifli_flex_mcp_discover_abilities_by_prefixes( $prefixes );
                    if ( is_array( $discovered ) ) {
                        $ability_names = array_unique( array_merge( $ability_names, $discovered ) );
                    }
                }
            }

            // Import each discovered/explicit ability
            foreach ( $ability_names as $ability_name ) {
                $ability_name = is_string( $ability_name ) ? trim( $ability_name ) : '';
                if ( '' === $ability_name ) {
                    continue;
                }

                $stats['discovered_total']++;

                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- point lookup.
                $existing_row = $wpdb->get_row( $wpdb->prepare( "SELECT id, enabled FROM {$table_sql} WHERE ability_name = %s", $ability_name ), ARRAY_A );
                if ( ! empty( $existing_row ) ) {
                    if ( isset( $existing_row['enabled'] ) && intval( $existing_row['enabled'] ) !== 1 ) {
                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- plugin-managed table write.
                        $wpdb->update(
                            $table,
                            array(
                                'enabled' => 1,
                                'updated_at' => current_time( 'mysql', true ),
                            ),
                            array( 'id' => intval( $existing_row['id'] ) ),
                            array( '%d', '%s' ),
                            array( '%d' )
                        );
                        $imported_count++;
                        $stats['reactivated']++;
                    } else {
                        $stats['already_existing']++;
                    }
                    continue;
                }

                $get_ability = 'wp_get_ability';
                $ability = call_user_func( $get_ability, $ability_name );
                if ( ! $ability ) {
                    continue;
                }

                $input_schema = StifliFlexMcpUtils::normalizeToolInputSchema( $ability->get_input_schema() );
                $output_schema = method_exists( $ability, 'get_output_schema' ) ? $ability->get_output_schema() : null;

                $category = method_exists( $ability, 'get_category' ) ? $ability->get_category() : '';
                if ( is_object( $category ) && method_exists( $category, 'get_label' ) ) {
                    $category = $category->get_label();
                }

                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- plugin-managed table write.
                $inserted = $wpdb->insert(
                    $table,
                    array(
                        'ability_name' => $ability_name,
                        'ability_label' => $ability->get_label(),
                        'ability_description' => $ability->get_description(),
                        'ability_category' => is_string( $category ) ? $category : '',
                        'input_schema' => is_array( $input_schema ) ? wp_json_encode( $input_schema ) : null,
                        'output_schema' => is_array( $output_schema ) ? wp_json_encode( $output_schema ) : null,
                        'enabled' => 1,
                        'created_at' => current_time( 'mysql', true ),
                        'updated_at' => current_time( 'mysql', true ),
                    ),
                    array( '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
                );

                if ( false !== $inserted ) {
                    $imported_count++;
                    $stats['imported']++;
                }
            }
        }

        return $imported_count;
    }

    public function filter_tool_enabled( $allowed, $tool_name, $context, $tool_definition ) {
        if ( ! $allowed || ! is_string( $tool_name ) || '' === $tool_name ) {
            return $allowed;
        }

        $groups_for_tool = StifliFlexMcp_Plugin_Integrations_Registry::get_integrations_for_tool( $tool_name );
        if ( empty( $groups_for_tool ) ) {
            return true;
        }

        $state = $this->get_state();
        $enabled_lookup = array_fill_keys( $state['enabled_groups'], true );

        $enabled_by_any_group = false;
        foreach ( $groups_for_tool as $group_id ) {
            if ( isset( $enabled_lookup[ $group_id ] ) ) {
                $enabled_by_any_group = true;
                break;
            }
        }

        if ( ! $enabled_by_any_group ) {
            return false;
        }

        return ! in_array( $tool_name, $state['disabled_tools'], true );
    }

    public function render_plugins_tab( $active_tab, $host ) {
        $state = $this->get_state();
        $integrations = StifliFlexMcp_Plugin_Integrations_Registry::get_integrations();
        $model = class_exists( 'StifliFlexMcpModel' ) ? new StifliFlexMcpModel() : null;
        $tools_map = is_object( $model ) ? $model->getTools() : array();
        $implemented_tools = is_array( $tools_map ) ? array_keys( $tools_map ) : array();

        echo '<h2>' . esc_html__( 'Plugin Integrations', 'stifli-flex-mcp' ) . '</h2>';
        echo '<p>' . esc_html__( 'Enable MCP tool groups for third-party plugins. Only plugins that are installed and active can be enabled. Disabled tools return an error when called.', 'stifli-flex-mcp' ) . '</p>';
        echo '<p class="description sflmcp-plugins-save-status" id="sflmcp-plugins-save-status">' . esc_html__( 'Changes are saved automatically.', 'stifli-flex-mcp' ) . '</p>';

        $plugins_nonce = wp_create_nonce( 'sflmcp_plugins_integrations_save' );
        echo '<div id="sflmcp-plugin-integrations-config" class="sflmcp-hidden" data-nonce="' . esc_attr( $plugins_nonce ) . '"></div>';

        usort(
            $integrations,
            static function( $a, $b ) {
                $a_featured = ! empty( $a['featured'] ) ? 1 : 0;
                $b_featured = ! empty( $b['featured'] ) ? 1 : 0;
                if ( $a_featured !== $b_featured ) {
                    return $b_featured - $a_featured;
                }
                $a_name = isset( $a['name'] ) ? (string) $a['name'] : '';
                $b_name = isset( $b['name'] ) ? (string) $b['name'] : '';
                return strcasecmp( $a_name, $b_name );
            }
        );

        foreach ( $integrations as $integration ) {
            $integration_id = $integration['id'];
            $integration_name = isset( $integration['name'] ) ? $integration['name'] : $integration_id;
            $status = $this->get_plugin_status( $integration );
            $checked = in_array( $integration_id, $state['enabled_groups'], true ) ? 'checked' : '';
            $is_enabled_group = in_array( $integration_id, $state['enabled_groups'], true );

            $managed_matches = $this->get_matching_tools( $integration, $implemented_tools );
            $managed_count = count( $managed_matches );
            $enabled_read_count = 0;
            $enabled_write_count = 0;
            $enabled_effective_count = 0;
            $enabled_tokens = 0;
            $tool_tokens = array();

            foreach ( $managed_matches as $tool_name ) {
                $tool_def = isset( $tools_map[ $tool_name ] ) && is_array( $tools_map[ $tool_name ] ) ? $tools_map[ $tool_name ] : array(
                    'name' => $tool_name,
                    'description' => '',
                );
                $tool_token_estimate = StifliFlexMcpUtils::estimateToolTokenUsage( $tool_def );
                if ( $tool_token_estimate <= 0 ) {
                    $tool_token_estimate = StifliFlexMcpUtils::estimateTokensFromString( $tool_name );
                }
                $tool_tokens[ $tool_name ] = $tool_token_estimate;

                $is_disabled = in_array( $tool_name, $state['disabled_tools'], true );
                $is_enabled = $is_enabled_group && ! $is_disabled;
                if ( $is_enabled ) {
                    $enabled_effective_count++;
                    $enabled_tokens += $tool_token_estimate;
                }
                $mode = $this->get_tool_mode( $model, $tool_name );
                if ( $is_enabled ) {
                    if ( 'write' === $mode ) {
                        $enabled_write_count++;
                    } else {
                        $enabled_read_count++;
                    }
                }
            }

            $count_class = '';
            if ( $managed_count > 0 && $enabled_effective_count === $managed_count ) {
                $count_class = 'sflmcp-count-full';
            } elseif ( $enabled_effective_count > 0 ) {
                $count_class = 'sflmcp-count-partial';
            }

            $summary_html = esc_html( $enabled_effective_count . '/' . $managed_count . ' enabled' );
            if ( $enabled_read_count > 0 ) {
                $summary_html .= ' &middot; <span class="sflmcp-mode-label">' . esc_html( $enabled_read_count . ' read' ) . '</span>';
            }
            if ( $enabled_write_count > 0 ) {
                $summary_html .= ' &middot; <span class="sflmcp-mode-label">' . esc_html( $enabled_write_count . ' write' ) . '</span>';
            }
            if ( $enabled_effective_count > 0 ) {
                $summary_html .= ' &middot; ' . esc_html( number_format_i18n( $enabled_tokens ) ) . ' tokens';
            }

            echo '<details class="sflmcp-plugin-card" data-integration="' . esc_attr( $integration_id ) . '">';
            echo '<summary>';
            echo '<div class="sflmcp-plugin-summary-left">';
            echo '<input type="checkbox" class="sflmcp-plugin-group-checkbox" name="enabled_groups[]" value="' . esc_attr( $integration_id ) . '" ' . esc_attr( $checked ) . ' />';
            echo '<span class="sflmcp-chevron" aria-hidden="true">&#9656;</span>';
            echo '<span class="sflmcp-plugin-title">' . esc_html( $integration_name ) . '</span>';
            echo '<span class="sflmcp-badges">';
            if ( ! empty( $integration['featured'] ) ) {
                $featured_label = ! empty( $integration['featured_label'] ) ? $integration['featured_label'] : __( 'Recommended!', 'stifli-flex-mcp' );
                echo '<span class="sflmcp-badge sflmcp-badge-featured">' . esc_html( '★ ' . $featured_label ) . '</span>';
            }
            if ( ! empty( $status['is_active'] ) ) {
                echo '<span class="sflmcp-badge sflmcp-badge-active">' . esc_html__( 'ACTIVE', 'stifli-flex-mcp' ) . '</span>';
            } elseif ( ! empty( $status['is_installed'] ) ) {
                echo '<span class="sflmcp-badge sflmcp-badge-installed">' . esc_html__( 'INSTALLED', 'stifli-flex-mcp' ) . '</span>';
            } else {
                echo '<span class="sflmcp-badge sflmcp-badge-muted">' . esc_html__( 'NOT INSTALLED', 'stifli-flex-mcp' ) . '</span>';
            }
            echo '</span>';
            if ( ! empty( $status['action_url'] ) ) {
                echo '<a class="button button-small sflmcp-plugin-action-btn" href="' . esc_url( $status['action_url'] ) . '">' . esc_html( $status['action_label'] ) . '</a>';
            }
            echo '</div>';
            echo '<div class="sflmcp-plugin-summary-right">';
            echo '<span class="sflmcp-enabled-count ' . esc_attr( $count_class ) . '">' . wp_kses_post( $summary_html ) . '</span>';
            echo '</div>';
            echo '</summary>';

            echo '<div class="sflmcp-plugin-body">';
            if ( ! empty( $integration['description'] ) ) {
                echo '<p class="description sflmcp-plugin-description">' . esc_html( $integration['description'] ) . '</p>';
            }

            // Show "Discover abilities" button if plugin is installed/active but has no tools yet
            if ( 0 === $managed_count && ( ! empty( $status['is_installed'] ) || ! empty( $status['is_active'] ) ) && ! empty( $integration['match']['prefixes'] ) ) {
                echo '<div class="sflmcp-discover-wrap">';
                echo '<button class="button button-secondary sflmcp-discover-btn" data-integration-id="' . esc_attr( $integration_id ) . '">' . esc_html__( 'Discover abilities for this plugin', 'stifli-flex-mcp' ) . '</button>';
                echo '<span id="sflmcp-discover-status-' . esc_attr( $integration_id ) . '" class="sflmcp-discover-status"></span>';
                echo '</div>';
            }

            echo '<div class="sflmcp-plugin-tools-table">';
            if ( $managed_count > 0 ) {
                echo '<table class="wp-list-table widefat fixed striped">';
                echo '<tbody>';
                foreach ( $managed_matches as $tool_name ) {
                    $tool_mode = $this->get_tool_mode( $model, $tool_name );
                    $is_disabled = in_array( $tool_name, $state['disabled_tools'], true );
                    $is_checked = $is_enabled_group && ! $is_disabled;
                    $tool_description = isset( $tools_map[ $tool_name ]['description'] ) ? $tools_map[ $tool_name ]['description'] : '';
                    $tool_mode_display = 'write' === $tool_mode ? 'WRITE' : 'READ';
                    $mode_class = 'write' === $tool_mode ? 'sflmcp-mode-write' : 'sflmcp-mode-read';
                    $tool_token_estimate = isset( $tool_tokens[ $tool_name ] ) ? intval( $tool_tokens[ $tool_name ] ) : 0;

                    echo '<tr class="sflmcp-plugin-tool-row">';
                    echo '<td class="sflmcp-tools-col-checkbox">';
                    echo '<input type="checkbox" class="sflmcp-plugin-tool-checkbox" data-mode="' . esc_attr( $tool_mode_display ) . '" data-tokens="' . esc_attr( $tool_token_estimate ) . '" name="enabled_tools[' . esc_attr( $integration_id ) . '][]" value="' . esc_attr( $tool_name ) . '" ' . checked( $is_checked, true, false ) . ' />';
                    echo '<code>' . esc_html( $tool_name ) . '</code>';
                    echo '</td>';
                    echo '<td class="sflmcp-tools-col-desc">';
                    if ( '' !== $tool_description ) {
                        echo esc_html( $tool_description );
                    }
                    echo '</td>';
                    echo '<td class="sflmcp-tools-col-mode"><span class="sflmcp-tool-mode-badge ' . esc_attr( $mode_class ) . '">' . esc_html( $tool_mode_display ) . '</span></td>';
                    echo '<td class="sflmcp-tools-col-tokens">' . esc_html( number_format_i18n( $tool_token_estimate ) ) . ' tokens</td>';
                    echo '</tr>';
                }
                echo '</tbody>';
                echo '</table>';
            } else {
                echo '<div class="sflmcp-empty">' . esc_html__( 'No implemented tools matched yet.', 'stifli-flex-mcp' ) . '</div>';
            }
            echo '</div>';
            echo '</div>';
            echo '</details>';
        }

        echo '<details class="sflmcp-plugins-advanced">';
        echo '<summary class="sflmcp-plugins-advanced-summary">' . esc_html__( 'Advanced: Custom Tools (legacy)', 'stifli-flex-mcp' ) . '</summary>';
        echo '<div class="sflmcp-plugins-advanced-body">';

        if ( is_object( $host ) && method_exists( $host, 'renderCustomToolsTab' ) ) {
            $host->renderCustomToolsTab( true );
        } else {
            echo '<p class="description">' . esc_html__( 'Custom tools panel unavailable.', 'stifli-flex-mcp' ) . '</p>';
        }

        echo '</div>';
        echo '</details>';
    }

    private function get_tool_mode( $model, $tool_name ) {
        if ( ! is_object( $model ) || ! method_exists( $model, 'getIntentForTool' ) ) {
            return 'read';
        }

        $meta = $model->getIntentForTool( $tool_name );
        if ( isset( $meta['intent'] ) && 'write' === $meta['intent'] ) {
            return 'write';
        }

        return 'read';
    }

    private function get_tool_source_label( $tool_name ) {
        if ( 'wp_rm_get_head' === $tool_name ) {
            return 'PLUGIN REST API';
        }

        if ( 0 === strpos( $tool_name, 'wp_rm_' ) ) {
            return 'PHP / POSTMETA';
        }

        return 'PLUGIN TOOL';
    }

    private function save_state( $state ) {
        if ( ! is_array( $state ) ) {
            return;
        }

        // Keep global state for compatibility and no-profile scenarios.
        update_option( self::OPTION_KEY, $state, false );

        $active_profile_id = $this->get_active_profile_id();
        if ( $active_profile_id <= 0 ) {
            return;
        }

        update_option( self::OPTION_KEY . '_profile_' . $active_profile_id, $state, false );
        $this->sync_managed_tools_to_active_profile( $state, $active_profile_id );
    }

    private function get_active_profile_id() {
        global $wpdb;

        $profiles_table = StifliFlexMcpUtils::getPrefixedTable( 'sflmcp_profiles', false );
        $like = $wpdb->esc_like( $profiles_table );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- schema check.
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) ) !== $profiles_table ) {
            return 0;
        }

        $profiles_table_sql = $this->get_safe_table_sql( $profiles_table );
        if ( '' === $profiles_table_sql ) {
            return 0;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from helper.
        $active_profile_id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$profiles_table_sql} WHERE is_active = %d LIMIT 1", 1 ) );

        return $active_profile_id ? intval( $active_profile_id ) : 0;
    }

    private function sync_managed_tools_to_active_profile( $state, $active_profile_id ) {
        if ( ! is_array( $state ) || $active_profile_id <= 0 || ! class_exists( 'StifliFlexMcpModel' ) ) {
            return;
        }

        global $wpdb;
        $profile_tools_table = StifliFlexMcpUtils::getPrefixedTable( 'sflmcp_profile_tools', false );
        $profile_tools_table_sql = $this->get_safe_table_sql( $profile_tools_table );
        if ( '' === $profile_tools_table_sql ) {
            return;
        }

        $model = new StifliFlexMcpModel();
        $tools_map = $model->getTools();
        if ( ! is_array( $tools_map ) || empty( $tools_map ) ) {
            return;
        }

        $enabled_groups = isset( $state['enabled_groups'] ) && is_array( $state['enabled_groups'] )
            ? array_fill_keys( array_map( 'sanitize_key', $state['enabled_groups'] ), true )
            : array();

        $disabled_tools = isset( $state['disabled_tools'] ) && is_array( $state['disabled_tools'] )
            ? array_fill_keys( array_map( 'sanitize_key', $state['disabled_tools'] ), true )
            : array();

        foreach ( array_keys( $tools_map ) as $tool_name ) {
            $groups = StifliFlexMcp_Plugin_Integrations_Registry::get_integrations_for_tool( $tool_name );
            if ( empty( $groups ) ) {
                continue;
            }

            $enabled_by_group = false;
            foreach ( $groups as $group_id ) {
                if ( isset( $enabled_groups[ $group_id ] ) ) {
                    $enabled_by_group = true;
                    break;
                }
            }

            $is_enabled = $enabled_by_group && ! isset( $disabled_tools[ $tool_name ] );

            if ( $is_enabled ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from helper.
                $exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$profile_tools_table_sql} WHERE profile_id = %d AND tool_name = %s LIMIT 1", $active_profile_id, $tool_name ) );
                if ( ! $exists ) {
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- plugin-managed table write.
                    $wpdb->insert(
                        $profile_tools_table,
                        array(
                            'profile_id' => $active_profile_id,
                            'tool_name'  => $tool_name,
                            'created_at' => current_time( 'mysql', true ),
                        ),
                        array( '%d', '%s', '%s' )
                    );
                }
            } else {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- plugin-managed table write.
                $wpdb->delete(
                    $profile_tools_table,
                    array(
                        'profile_id' => $active_profile_id,
                        'tool_name'  => $tool_name,
                    ),
                    array( '%d', '%s' )
                );
            }
        }
    }

    private function get_state() {
        $raw = array();
        $active_profile_id = $this->get_active_profile_id();
        if ( $active_profile_id > 0 ) {
            $profile_raw = get_option( self::OPTION_KEY . '_profile_' . $active_profile_id, null );
            if ( is_array( $profile_raw ) ) {
                $raw = $profile_raw;
            }
        }

        if ( empty( $raw ) ) {
            $raw = get_option( self::OPTION_KEY, array() );
        }

        $default_enabled = array();

        $enabled_groups = isset( $raw['enabled_groups'] ) && is_array( $raw['enabled_groups'] ) ? array_values( array_map( 'sanitize_key', $raw['enabled_groups'] ) ) : $default_enabled;
        $disabled_tools = isset( $raw['disabled_tools'] ) && is_array( $raw['disabled_tools'] ) ? array_values( array_map( 'sanitize_key', $raw['disabled_tools'] ) ) : array();

        return array(
            'enabled_groups' => array_values( array_intersect( $enabled_groups, StifliFlexMcp_Plugin_Integrations_Registry::get_integration_ids() ) ),
            'disabled_tools' => $disabled_tools,
        );
    }

    private function get_matching_tools( $integration, $implemented_tools ) {
        $matches = array();
        foreach ( $implemented_tools as $tool_name ) {
            if ( StifliFlexMcp_Plugin_Integrations_Registry::tool_matches_integration( $tool_name, $integration ) ) {
                $matches[] = $tool_name;
            }
        }
        return $matches;
    }

    private function get_catalog_coverage( $catalog_tools, $implemented_tools ) {
        $implemented = array();
        $missing = array();

        $implemented_lookup = array_fill_keys( $implemented_tools, true );

        foreach ( $catalog_tools as $catalog_tool ) {
            if ( ! is_string( $catalog_tool ) || '' === $catalog_tool ) {
                continue;
            }

            if ( $this->catalog_tool_matches_implemented( $catalog_tool, $implemented_lookup ) ) {
                $implemented[] = $catalog_tool;
            } else {
                $missing[] = $catalog_tool;
            }
        }

        return array(
            'implemented' => $implemented,
            'missing' => $missing,
            'implemented_count' => count( $implemented ),
            'total_count' => count( $catalog_tools ),
        );
    }

    private function catalog_tool_matches_implemented( $catalog_tool, $implemented_lookup ) {
        if ( isset( $implemented_lookup[ $catalog_tool ] ) ) {
            return true;
        }

        if ( substr( $catalog_tool, -1 ) !== '*' ) {
            return false;
        }

        $prefix = substr( $catalog_tool, 0, -1 );
        if ( '' === $prefix ) {
            return false;
        }

        foreach ( $implemented_lookup as $implemented_tool => $true_value ) {
            if ( strpos( $implemented_tool, $prefix ) === 0 ) {
                return true;
            }
        }

        return false;
    }

    private function get_plugin_status( $integration ) {
        if ( ! function_exists( 'is_plugin_active' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $plugin_files     = isset( $integration['plugin_files'] ) && is_array( $integration['plugin_files'] ) ? $integration['plugin_files'] : array();
        $match_classes    = isset( $integration['match_classes'] ) && is_array( $integration['match_classes'] ) ? $integration['match_classes'] : array();
        $match_constants  = isset( $integration['match_constants'] ) && is_array( $integration['match_constants'] ) ? $integration['match_constants'] : array();

        if ( empty( $plugin_files ) && empty( $match_classes ) && empty( $match_constants ) ) {
            return array(
                'is_active'    => false,
                'is_installed' => false,
                'label'        => __( 'Virtual integration', 'stifli-flex-mcp' ),
                'action_url'   => '',
                'action_label' => '',
            );
        }

        // Secondary detection: class or constant existence means the plugin is active.
        foreach ( $match_classes as $class_name ) {
            if ( class_exists( $class_name ) ) {
                return array(
                    'is_active'    => true,
                    'is_installed' => true,
                    'label'        => __( 'Active', 'stifli-flex-mcp' ),
                    'action_url'   => admin_url( 'plugins.php?plugin_status=active' ),
                    'action_label' => __( 'Manage', 'stifli-flex-mcp' ),
                );
            }
        }
        foreach ( $match_constants as $const_name ) {
            if ( defined( $const_name ) ) {
                return array(
                    'is_active'    => true,
                    'is_installed' => true,
                    'label'        => __( 'Active', 'stifli-flex-mcp' ),
                    'action_url'   => admin_url( 'plugins.php?plugin_status=active' ),
                    'action_label' => __( 'Manage', 'stifli-flex-mcp' ),
                );
            }
        }

        foreach ( $plugin_files as $plugin_file ) {
            if ( is_plugin_active( $plugin_file ) ) {
                return array(
                    'is_active'    => true,
                    'is_installed' => true,
                    'label'        => __( 'Active', 'stifli-flex-mcp' ),
                    'action_url'   => admin_url( 'plugins.php?plugin_status=active' ),
                    'action_label' => __( 'Manage', 'stifli-flex-mcp' ),
                );
            }
            if ( file_exists( WP_PLUGIN_DIR . '/' . $plugin_file ) ) {
                $activate_url = '';
                if ( current_user_can( 'activate_plugins' ) ) {
                    $activate_url = wp_nonce_url(
                        add_query_arg(
                            array(
                                'action' => 'activate',
                                'plugin' => $plugin_file,
                            ),
                            admin_url( 'plugins.php' )
                        ),
                        'activate-plugin_' . $plugin_file
                    );
                }

                return array(
                    'is_active'    => false,
                    'is_installed' => true,
                    'label'        => __( 'Installed (inactive)', 'stifli-flex-mcp' ),
                    'action_url'   => $activate_url,
                    'action_label' => __( 'Activate', 'stifli-flex-mcp' ),
                );
            }
        }

        $slug        = isset( $integration['install_slug'] ) ? sanitize_key( $integration['install_slug'] ) : '';
        $install_url = '';
        if ( '' !== $slug && current_user_can( 'install_plugins' ) ) {
            $install_url = wp_nonce_url(
                add_query_arg(
                    array(
                        'action' => 'install-plugin',
                        'plugin' => $slug,
                    ),
                    admin_url( 'update.php' )
                ),
                'install-plugin_' . $slug
            );
        }

        return array(
            'is_active'    => false,
            'is_installed' => false,
            'label'        => __( 'Not installed', 'stifli-flex-mcp' ),
            'action_url'   => $install_url,
            'action_label' => __( 'Install', 'stifli-flex-mcp' ),
        );
    }

    private function sanitize_enabled_tools_input( $raw_enabled_tools ) {
        if ( ! is_array( $raw_enabled_tools ) ) {
            return array();
        }

        $sanitized = array();
        foreach ( $raw_enabled_tools as $integration_id => $tool_names ) {
            $integration_id = sanitize_key( $integration_id );
            if ( '' === $integration_id || ! is_array( $tool_names ) ) {
                continue;
            }

            $sanitized[ $integration_id ] = array_values(
                array_filter(
                    array_map( 'sanitize_key', $tool_names )
                )
            );
        }

        return $sanitized;
    }

    private function get_safe_table_sql( $table_name ) {
        $table_name = is_string( $table_name ) ? trim( $table_name ) : '';
        if ( '' === $table_name || ! preg_match( '/^[A-Za-z0-9_]+$/', $table_name ) ) {
            return '';
        }

        return '`' . $table_name . '`';
    }
}
