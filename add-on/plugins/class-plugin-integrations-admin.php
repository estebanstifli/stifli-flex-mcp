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
            ? wp_unslash( $_POST['enabled_tools'] )
            : array();

        $state = $this->build_integrations_state( $enabled_groups, $enabled_tools_by_integration );
        update_option( self::OPTION_KEY, $state, false );

        wp_send_json_success(
            array(
                'message' => __( 'Plugin integrations saved.', 'stifli-flex-mcp' ),
                'state' => $state,
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
        $enabled_tools_by_integration = isset( $_POST['enabled_tools'] ) && is_array( $_POST['enabled_tools'] ) ? wp_unslash( $_POST['enabled_tools'] ) : array();
        $bulk_action = isset( $_POST['bulk_action'] ) ? sanitize_key( wp_unslash( $_POST['bulk_action'] ) ) : '';

        $state = $this->build_integrations_state( $enabled_groups, $enabled_tools_by_integration );
        $valid_ids = StifliFlexMcp_Plugin_Integrations_Registry::get_integration_ids();

        if ( 'enable_all' === $bulk_action ) {
            $state['enabled_groups'] = $valid_ids;
        } elseif ( 'disable_all' === $bulk_action ) {
            $state['enabled_groups'] = array();
        } elseif ( 'reset_overrides' === $bulk_action ) {
            $state['disabled_tools'] = array();
        }

        update_option( self::OPTION_KEY, $state, false );

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
            'enabled_groups' => $enabled_groups,
            'disabled_tools' => array_values( array_unique( $disabled_tools ) ),
        );
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
        echo '<p class="description" id="sflmcp-plugins-save-status" style="margin:6px 0 12px;">' . esc_html__( 'Changes are saved automatically.', 'stifli-flex-mcp' ) . '</p>';

        echo '<style>';
        echo '.sflmcp-plugin-card{background:#fff;border:1px solid #dcdcde;border-radius:8px;margin:12px 0;overflow:hidden;}';
        echo '.sflmcp-plugin-card summary{list-style:none;display:flex;align-items:center;gap:10px;padding:12px 14px;cursor:pointer;}';
        echo '.sflmcp-plugin-card summary::-webkit-details-marker{display:none;}';
        echo '.sflmcp-plugin-card .sflmcp-chevron{font-size:14px;color:#646970;width:14px;text-align:center;}';
        echo '.sflmcp-plugin-card .sflmcp-plugin-title{font-weight:600;}';
        echo '.sflmcp-plugin-card .sflmcp-plugin-summary-left{display:flex;align-items:center;gap:8px;min-width:0;}';
        echo '.sflmcp-plugin-card .sflmcp-plugin-summary-right{margin-left:auto;white-space:nowrap;}';
        echo '.sflmcp-plugin-card .sflmcp-plugin-group-checkbox{cursor:pointer;flex-shrink:0;}';
        echo '.sflmcp-plugin-card .sflmcp-plugin-group-checkbox.sflmcp-partial{-webkit-appearance:none;appearance:none;width:16px;height:16px;background-color:#8a9baa;border:1px solid #5f7a8e;border-radius:2px;vertical-align:middle;background-image:linear-gradient(#fff,#fff);background-size:55% 2px;background-position:center;background-repeat:no-repeat;}';
        echo '.sflmcp-plugin-card .sflmcp-badges{display:flex;gap:6px;flex-wrap:wrap;}';
        echo '.sflmcp-plugin-card .sflmcp-badge{font-size:11px;line-height:1;padding:4px 7px;border-radius:999px;background:#eff1f2;color:#3c434a;font-weight:600;}';
        echo '.sflmcp-plugin-card .sflmcp-badge-installed{background:#e8f4ff;color:#125e9c;}';
        echo '.sflmcp-plugin-card .sflmcp-badge-active{background:#e7f7eb;color:#1b5e20;}';
        echo '.sflmcp-plugin-card .sflmcp-badge-muted{background:#f6f7f7;color:#646970;}';
        echo '.sflmcp-plugin-body{border-top:1px solid #dcdcde;padding:14px;}';
        echo '.sflmcp-plugin-tools-table{border:1px solid #dcdcde;border-radius:6px;overflow:hidden;}';
        echo '.sflmcp-plugin-tool-row .sflmcp-tools-col-mode,.sflmcp-plugin-tool-row .sflmcp-tools-col-tokens{text-align:right;white-space:nowrap;}';
        echo '.sflmcp-empty{padding:10px 12px;color:#646970;}';
        echo '</style>';

        $plugins_nonce = wp_create_nonce( 'sflmcp_plugins_integrations_save' );

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
            echo '<input type="checkbox" class="sflmcp-plugin-group-checkbox" name="enabled_groups[]" value="' . esc_attr( $integration_id ) . '" ' . esc_attr( $checked ) . ' onclick="event.stopPropagation();" />';
            echo '<span class="sflmcp-chevron" aria-hidden="true">&#9656;</span>';
            echo '<span class="sflmcp-plugin-title">' . esc_html( $integration_name ) . '</span>';
            echo '<span class="sflmcp-badges">';
            if ( ! empty( $status['is_active'] ) ) {
                echo '<span class="sflmcp-badge sflmcp-badge-active">' . esc_html__( 'ACTIVE', 'stifli-flex-mcp' ) . '</span>';
            } elseif ( ! empty( $status['is_installed'] ) ) {
                echo '<span class="sflmcp-badge sflmcp-badge-installed">' . esc_html__( 'INSTALLED', 'stifli-flex-mcp' ) . '</span>';
            } else {
                echo '<span class="sflmcp-badge sflmcp-badge-muted">' . esc_html__( 'NOT INSTALLED', 'stifli-flex-mcp' ) . '</span>';
            }
            echo '</span>';
            if ( ! empty( $status['action_url'] ) ) {
                echo '<a class="button button-small" style="margin-left:8px;" href="' . esc_url( $status['action_url'] ) . '">' . esc_html( $status['action_label'] ) . '</a>';
            }
            echo '</div>';
            echo '<div class="sflmcp-plugin-summary-right">';
            echo '<span class="sflmcp-enabled-count ' . esc_attr( $count_class ) . '">' . wp_kses_post( $summary_html ) . '</span>';
            echo '</div>';
            echo '</summary>';

            echo '<div class="sflmcp-plugin-body">';
            if ( ! empty( $integration['description'] ) ) {
                echo '<p class="description" style="margin-top:0;">' . esc_html( $integration['description'] ) . '</p>';
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

        echo '<script>';
        echo 'document.addEventListener("DOMContentLoaded", function(){';
        echo 'var saveStatus=document.getElementById("sflmcp-plugins-save-status");';
        echo 'var saveTimer=null;';
        echo 'function setSaveStatus(text,isError){if(!saveStatus){return;}saveStatus.textContent=text;saveStatus.style.color=isError?"#b32d2e":"";}';
        echo 'function collectState(){var state={enabled_groups:[],enabled_tools:{}};document.querySelectorAll(".sflmcp-plugin-card").forEach(function(card){var integrationId=card.getAttribute("data-integration")||"";if(!integrationId){return;}var checkedTools=[];card.querySelectorAll(".sflmcp-plugin-tool-checkbox").forEach(function(cb){if(cb.checked){checkedTools.push(cb.value);}});state.enabled_tools[integrationId]=checkedTools;if(checkedTools.length>0){state.enabled_groups.push(integrationId);}});return state;}';
        echo 'function saveState(){if(!(window.jQuery&&window.ajaxurl)){return;}var state=collectState();setSaveStatus("Saving...",false);window.jQuery.post(window.ajaxurl,{action:"sflmcp_save_plugin_integrations",nonce:"' . esc_js( $plugins_nonce ) . '",enabled_groups:state.enabled_groups,enabled_tools:state.enabled_tools},function(res){if(res&&res.success){setSaveStatus("Changes saved automatically.",false);}else{setSaveStatus("Error saving changes.",true);}}).fail(function(){setSaveStatus("Error saving changes.",true);});}';
        echo 'function scheduleSave(){if(saveTimer){clearTimeout(saveTimer);}saveTimer=setTimeout(saveState,220);}';
        echo 'function updateCardSummary(card){';
        echo 'var groupCheckbox=card.querySelector(".sflmcp-plugin-group-checkbox");';
        echo 'var toolCheckboxes=card.querySelectorAll(".sflmcp-plugin-tool-checkbox");';
        echo 'var summary=card.querySelector(".sflmcp-enabled-count");';
        echo 'if(!groupCheckbox||!summary){return;}';
        echo 'var total=toolCheckboxes.length;';
        echo 'var enabled=0, enabledRead=0, enabledWrite=0, enabledTokens=0;';
        echo 'toolCheckboxes.forEach(function(cb){if(cb.checked){enabled++;enabledTokens+=(parseInt(cb.getAttribute("data-tokens"),10)||0);if((cb.getAttribute("data-mode")||"")==="WRITE"){enabledWrite++;}else{enabledRead++;}}});';
        echo 'var isAll=total>0&&enabled===total;';
        echo 'var isSome=enabled>0&&enabled<total;';
        echo 'groupCheckbox.checked=isAll;';
        echo 'groupCheckbox.indeterminate=isSome;';
        echo 'if(isSome){groupCheckbox.classList.add("sflmcp-partial");}else{groupCheckbox.classList.remove("sflmcp-partial");}';
        echo 'summary.classList.remove("sflmcp-count-partial","sflmcp-count-full");';
        echo 'if(isAll){summary.classList.add("sflmcp-count-full");}else if(enabled>0){summary.classList.add("sflmcp-count-partial");}';
        echo 'var html=enabled+"/"+total+" enabled";';
        echo 'if(enabledRead>0){html+=" &middot; <span class=\"sflmcp-mode-label\">"+enabledRead+" read</span>";}';
        echo 'if(enabledWrite>0){html+=" &middot; <span class=\"sflmcp-mode-label\">"+enabledWrite+" write</span>";}';
        echo 'if(enabled>0){html+=" &middot; "+enabledTokens.toLocaleString()+" tokens";}';
        echo 'summary.innerHTML=html;';
        echo '}';
        echo 'document.querySelectorAll(".sflmcp-plugin-card").forEach(function(card){';
        echo 'var groupCheckbox=card.querySelector(".sflmcp-plugin-group-checkbox");';
        echo 'var toolCheckboxes=card.querySelectorAll(".sflmcp-plugin-tool-checkbox");';
        echo 'if(groupCheckbox){groupCheckbox.addEventListener("change", function(){toolCheckboxes.forEach(function(cb){cb.checked=groupCheckbox.checked;});updateCardSummary(card);scheduleSave();});}';
        echo 'toolCheckboxes.forEach(function(cb){cb.addEventListener("change", function(){updateCardSummary(card);scheduleSave();});});';
        echo 'updateCardSummary(card);';
        echo '});';
        echo '});';
        echo '</script>';

        echo '<details style="margin-top:22px; border:1px solid #dcdcde; background:#fff; padding:10px;">';
        echo '<summary style="cursor:pointer; font-weight:600;">' . esc_html__( 'Advanced: Custom Tools (legacy)', 'stifli-flex-mcp' ) . '</summary>';
        echo '<div style="margin-top:12px;">';

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

    private function get_state() {
        $raw = get_option( self::OPTION_KEY, array() );
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
}
