<?php
/**
 * Elementor MCP integration.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class StifliFlexMcp_Elementor {

    const TEMPLATE_POST_TYPE = 'elementor_library';

    private static function is_elementor_plugin_active() {
        if ( function_exists( 'is_plugin_active' ) && is_plugin_active( 'elementor/elementor.php' ) ) {
            return true;
        }

        $active_plugins = (array) get_option( 'active_plugins', array() );
        if ( in_array( 'elementor/elementor.php', $active_plugins, true ) ) {
            return true;
        }

        if ( is_multisite() ) {
            $network_active = (array) get_site_option( 'active_sitewide_plugins', array() );
            if ( isset( $network_active['elementor/elementor.php'] ) ) {
                return true;
            }
        }

        return false;
    }

    public static function isAvailable() {
        return class_exists( 'Elementor\\Plugin' )
            || defined( 'ELEMENTOR_VERSION' )
            || self::is_elementor_plugin_active();
    }

    public static function getTools() {
        return array(
            'elementor_clone_page' => array(
                'name' => 'elementor_clone_page',
                'description' => 'Duplicate an existing Elementor post/page as a new draft or selected status. Copies Elementor data, regenerates element IDs, and preserves structural Elementor meta.',
                'inputSchema' => array(
                    'type' => 'object',
                    'properties' => array(
                        'source_post_id' => array( 'type' => 'integer', 'description' => 'Post or page ID to clone from. Must have Elementor data.' ),
                        'new_title' => array( 'type' => 'string', 'description' => 'Title for the cloned post.' ),
                        'new_status' => array( 'type' => 'string', 'enum' => array( 'draft', 'publish', 'private', 'pending' ), 'description' => 'Status for the new post. Defaults to draft.' ),
                    ),
                    'required' => array( 'source_post_id', 'new_title' ),
                ),
            ),
            'elementor_replace_text' => array(
                'name' => 'elementor_replace_text',
                'description' => 'Replace text in known text-bearing Elementor widget settings. Atomic widgets are left opaque. Supports dry_run to preview the replacement count without saving.',
                'inputSchema' => array(
                    'type' => 'object',
                    'properties' => array(
                        'post_id' => array( 'type' => 'integer' ),
                        'find' => array( 'type' => 'string', 'description' => 'Text to find.' ),
                        'replace' => array( 'type' => 'string', 'description' => 'Replacement text.' ),
                        'case_insensitive' => array( 'type' => 'boolean', 'description' => 'Default false.' ),
                        'dry_run' => array( 'type' => 'boolean', 'description' => 'Preview changes without writing _elementor_data.' ),
                    ),
                    'required' => array( 'post_id', 'find', 'replace' ),
                ),
            ),
            'elementor_replace_image' => array(
                'name' => 'elementor_replace_image',
                'description' => 'Swap image URLs in Elementor settings, including image widgets, backgrounds, galleries, and nested repeaters. Optionally remaps attachment IDs. Supports dry_run.',
                'inputSchema' => array(
                    'type' => 'object',
                    'properties' => array(
                        'post_id' => array( 'type' => 'integer' ),
                        'old_url' => array( 'type' => 'string', 'description' => 'Image URL to find.' ),
                        'new_url' => array( 'type' => 'string', 'description' => 'Image URL to replace with.' ),
                        'old_id' => array( 'type' => 'integer', 'description' => 'Optional old attachment ID.' ),
                        'new_id' => array( 'type' => 'integer', 'description' => 'Optional new attachment ID.' ),
                        'dry_run' => array( 'type' => 'boolean', 'description' => 'Preview changes without writing _elementor_data.' ),
                    ),
                    'required' => array( 'post_id', 'old_url', 'new_url' ),
                ),
            ),
            'elementor_replace_link' => array(
                'name' => 'elementor_replace_link',
                'description' => 'Replace links in Elementor link-shaped settings and URL-like string fields. Useful after cloning a landing page to update buttons, CTAs, icon boxes, and menu-like repeaters. Supports dry_run.',
                'inputSchema' => array(
                    'type' => 'object',
                    'properties' => array(
                        'post_id' => array( 'type' => 'integer' ),
                        'old_url' => array( 'type' => 'string', 'description' => 'Link URL to find.' ),
                        'new_url' => array( 'type' => 'string', 'description' => 'Replacement link URL.' ),
                        'dry_run' => array( 'type' => 'boolean', 'description' => 'Preview changes without writing _elementor_data.' ),
                    ),
                    'required' => array( 'post_id', 'old_url', 'new_url' ),
                ),
            ),
            'elementor_get_page_outline' => array(
                'name' => 'elementor_get_page_outline',
                'description' => 'Extract a compact outline of an Elementor page with section/container hierarchy, widget types, and short text snippets.',
                'inputSchema' => array(
                    'type' => 'object',
                    'properties' => array(
                        'post_id' => array( 'type' => 'integer' ),
                    ),
                    'required' => array( 'post_id' ),
                ),
            ),
            'elementor_list_local_templates' => array(
                'name' => 'elementor_list_local_templates',
                'description' => 'List saved templates from the Elementor Library with optional type filter.',
                'inputSchema' => array(
                    'type' => 'object',
                    'properties' => array(
                        'type' => array( 'type' => 'string', 'description' => 'Optional template type filter such as page, section, widget, popup, header, footer, single, or archive.' ),
                        'limit' => array( 'type' => 'integer', 'description' => 'Maximum templates to return. Default 50, max 200.' ),
                    ),
                    'required' => array(),
                ),
            ),
            'elementor_import_template' => array(
                'name' => 'elementor_import_template',
                'description' => 'Create a new Elementor local template from JSON exported by Elementor or a bare Elementor elements array. Regenerates element IDs before saving.',
                'inputSchema' => array(
                    'type' => 'object',
                    'properties' => array(
                        'title' => array( 'type' => 'string', 'description' => 'Template name.' ),
                        'template_type' => array( 'type' => 'string', 'description' => 'Template type. Defaults to page.' ),
                        'template_json' => array( 'type' => 'string', 'description' => 'JSON-encoded Elementor export or elements array.' ),
                    ),
                    'required' => array( 'title', 'template_json' ),
                ),
            ),
        );
    }

    public static function getCapabilities() {
        return array(
            'elementor_clone_page' => 'edit_posts',
            'elementor_replace_text' => 'edit_posts',
            'elementor_replace_image' => 'edit_posts',
            'elementor_replace_link' => 'edit_posts',
            'elementor_get_page_outline' => 'edit_posts',
            'elementor_list_local_templates' => 'edit_posts',
            'elementor_import_template' => 'edit_posts',
        );
    }

    public static function getChangeTrackerSnapshot( $tool, $args ) {
        $args = is_array( $args ) ? $args : array();

        if ( in_array( $tool, array( 'elementor_replace_text', 'elementor_replace_image', 'elementor_replace_link' ), true ) && self::is_truthy( self::array_value( $args, 'dry_run', false ) ) ) {
            return null;
        }

        if ( 'elementor_clone_page' === $tool ) {
            $source_id = self::sanitize_positive_int( self::array_value( $args, 'source_post_id', 0 ) );
            $source = $source_id > 0 ? get_post( $source_id ) : null;
            return self::build_post_snapshot( 'create', $source ? $source->post_type : 'post', 0 );
        }

        if ( 'elementor_import_template' === $tool ) {
            return self::build_post_snapshot( 'create', self::TEMPLATE_POST_TYPE, 0 );
        }

        if ( in_array( $tool, array( 'elementor_replace_text', 'elementor_replace_image', 'elementor_replace_link' ), true ) ) {
            $post_id = self::sanitize_positive_int( self::array_value( $args, 'post_id', 0 ) );
            $post = $post_id > 0 ? get_post( $post_id ) : null;
            return self::build_post_snapshot( 'update', $post ? $post->post_type : 'post', $post_id );
        }

        return null;
    }

    public static function dispatch( $tool, $args, &$r, $addResultText, $utils ) {
        if ( 0 !== strpos( (string) $tool, 'elementor_' ) ) {
            return null;
        }

        if ( ! self::isAvailable() ) {
            $r['error'] = array( 'code' => -32603, 'message' => 'Elementor plugin is not active.' );
            return true;
        }

        $args = is_array( $args ) ? $args : array();

        try {
            switch ( $tool ) {
                case 'elementor_clone_page':
                    $payload = self::clone_page( $args );
                    break;
                case 'elementor_replace_text':
                    $payload = self::replace_text( $args );
                    break;
                case 'elementor_replace_image':
                    $payload = self::replace_image( $args );
                    break;
                case 'elementor_replace_link':
                    $payload = self::replace_link( $args );
                    break;
                case 'elementor_get_page_outline':
                    $payload = self::get_page_outline( $args );
                    break;
                case 'elementor_list_local_templates':
                    $payload = self::list_local_templates( $args );
                    break;
                case 'elementor_import_template':
                    $payload = self::import_template( $args );
                    break;
                default:
                    return null;
            }
        } catch ( Exception $e ) {
            $r['error'] = array( 'code' => -32603, 'message' => $e->getMessage() );
            return true;
        }

        self::set_result_payload( $r, $payload, $addResultText );
        return true;
    }

    private static function clone_page( $args ) {
        $source_id = self::sanitize_positive_int( self::array_value( $args, 'source_post_id', 0 ) );
        $new_title = sanitize_text_field( (string) self::array_value( $args, 'new_title', '' ) );
        $new_status = sanitize_key( (string) self::array_value( $args, 'new_status', 'draft' ) );
        if ( ! in_array( $new_status, array( 'draft', 'publish', 'private', 'pending' ), true ) ) {
            $new_status = 'draft';
        }

        if ( $source_id <= 0 || '' === $new_title ) {
            throw new Exception( 'source_post_id and new_title are required.' );
        }

        $source = get_post( $source_id );
        if ( ! $source ) {
            throw new Exception( 'Source post not found.' );
        }
        if ( ! current_user_can( 'edit_post', $source_id ) ) {
            throw new Exception( 'edit_post capability required on source post.' );
        }
        if ( 'publish' === $new_status && ! self::current_user_can_publish_post_type( $source->post_type ) ) {
            throw new Exception( 'publish capability required for the cloned post type.' );
        }

        $tree = self::get_elementor_tree( $source_id, 'Source post' );
        $regenerated = self::regenerate_element_ids( $tree );

        $new_id = wp_insert_post(
            array(
                'post_title' => $new_title,
                'post_status' => $new_status,
                'post_type' => $source->post_type,
                'post_author' => get_current_user_id() ? get_current_user_id() : $source->post_author,
                'post_parent' => 0,
                'menu_order' => 0,
            ),
            true
        );

        if ( is_wp_error( $new_id ) ) {
            throw new Exception( esc_html( $new_id->get_error_message() ) );
        }

        self::save_elementor_tree( $new_id, $regenerated );
        self::copy_elementor_meta( $source_id, $new_id );
        self::ensure_builder_meta( $new_id, $source->post_type );
        self::clear_elementor_cache( $new_id );

        return array(
            'success' => true,
            'id' => (int) $new_id,
            'new_post_id' => (int) $new_id,
            'source_post_id' => $source_id,
            'new_title' => $new_title,
            'new_status' => $new_status,
            'edit_url' => admin_url( 'post.php?post=' . (int) $new_id . '&action=elementor' ),
            'view_url' => 'publish' === $new_status ? get_permalink( $new_id ) : get_preview_post_link( $new_id ),
        );
    }

    private static function replace_text( $args ) {
        $post_id = self::require_editable_elementor_post( $args );
        $find = (string) self::array_value( $args, 'find', '' );
        $replace = (string) self::array_value( $args, 'replace', '' );
        $case_insensitive = self::is_truthy( self::array_value( $args, 'case_insensitive', false ) );
        $dry_run = self::is_truthy( self::array_value( $args, 'dry_run', false ) );

        if ( '' === $find ) {
            throw new Exception( 'find is required.' );
        }

        $tree = self::get_elementor_tree( $post_id, 'Target post' );
        $counter = array( 'count' => 0 );
        $updated = self::walk_widgets_text( $tree, $find, $replace, $case_insensitive, $counter );

        if ( ! $dry_run && $counter['count'] > 0 ) {
            self::save_elementor_tree( $post_id, $updated );
            self::clear_elementor_cache( $post_id );
        }

        return array(
            'success' => true,
            'post_id' => $post_id,
            'replacements' => (int) $counter['count'],
            'dry_run' => $dry_run,
            'find' => $find,
            'replace' => $replace,
        );
    }

    private static function replace_image( $args ) {
        $post_id = self::require_editable_elementor_post( $args );
        $old_url = esc_url_raw( (string) self::array_value( $args, 'old_url', '' ) );
        $new_url = esc_url_raw( (string) self::array_value( $args, 'new_url', '' ) );
        $old_id = self::sanitize_positive_int( self::array_value( $args, 'old_id', 0 ) );
        $new_id = self::sanitize_positive_int( self::array_value( $args, 'new_id', 0 ) );
        $dry_run = self::is_truthy( self::array_value( $args, 'dry_run', false ) );

        if ( '' === $old_url || '' === $new_url ) {
            throw new Exception( 'old_url and new_url are required valid URLs.' );
        }

        $tree = self::get_elementor_tree( $post_id, 'Target post' );
        $counter = array( 'count' => 0 );
        $updated = self::walk_widgets_image( $tree, $old_url, $new_url, $old_id, $new_id, $counter );

        if ( ! $dry_run && $counter['count'] > 0 ) {
            self::save_elementor_tree( $post_id, $updated );
            self::clear_elementor_cache( $post_id );
        }

        return array(
            'success' => true,
            'post_id' => $post_id,
            'replacements' => (int) $counter['count'],
            'dry_run' => $dry_run,
            'old_url' => $old_url,
            'new_url' => $new_url,
        );
    }

    private static function replace_link( $args ) {
        $post_id = self::require_editable_elementor_post( $args );
        $old_url = esc_url_raw( (string) self::array_value( $args, 'old_url', '' ) );
        $new_url = esc_url_raw( (string) self::array_value( $args, 'new_url', '' ) );
        $dry_run = self::is_truthy( self::array_value( $args, 'dry_run', false ) );

        if ( '' === $old_url || '' === $new_url ) {
            throw new Exception( 'old_url and new_url are required valid URLs.' );
        }

        $tree = self::get_elementor_tree( $post_id, 'Target post' );
        $counter = array( 'count' => 0 );
        $updated = self::walk_widgets_links( $tree, $old_url, $new_url, $counter );

        if ( ! $dry_run && $counter['count'] > 0 ) {
            self::save_elementor_tree( $post_id, $updated );
            self::clear_elementor_cache( $post_id );
        }

        return array(
            'success' => true,
            'post_id' => $post_id,
            'replacements' => (int) $counter['count'],
            'dry_run' => $dry_run,
            'old_url' => $old_url,
            'new_url' => $new_url,
        );
    }

    private static function get_page_outline( $args ) {
        $post_id = self::sanitize_positive_int( self::array_value( $args, 'post_id', 0 ) );
        if ( $post_id <= 0 ) {
            throw new Exception( 'post_id is required.' );
        }
        if ( ! current_user_can( 'read_post', $post_id ) && ! current_user_can( 'edit_post', $post_id ) ) {
            throw new Exception( 'Permission denied.' );
        }

        $tree = self::get_elementor_tree( $post_id, 'Target post' );
        $post = get_post( $post_id );

        return array(
            'success' => true,
            'post_id' => $post_id,
            'post_title' => $post ? $post->post_title : '',
            'post_type' => $post ? $post->post_type : '',
            'edit_mode' => get_post_meta( $post_id, '_elementor_edit_mode', true ),
            'template_type' => get_post_meta( $post_id, '_elementor_template_type', true ),
            'outline' => self::build_outline( $tree, 0 ),
        );
    }

    private static function list_local_templates( $args ) {
        if ( ! post_type_exists( self::TEMPLATE_POST_TYPE ) ) {
            throw new Exception( 'Elementor template library post type is not available.' );
        }

        $type_filter = sanitize_key( (string) self::array_value( $args, 'type', '' ) );
        $limit = self::sanitize_limit( self::array_value( $args, 'limit', 50 ), 50, 200 );

        $query_args = array(
            'post_type' => self::TEMPLATE_POST_TYPE,
            'post_status' => array( 'publish', 'draft', 'pending', 'private' ),
            'posts_per_page' => $limit,
            'orderby' => 'modified',
            'order' => 'DESC',
            'no_found_rows' => true,
        );

        if ( '' !== $type_filter && taxonomy_exists( 'elementor_library_type' ) ) {
            $query_args['tax_query'] = array(
                array(
                    'taxonomy' => 'elementor_library_type',
                    'field' => 'slug',
                    'terms' => $type_filter,
                ),
            );
        }

        $posts = get_posts( $query_args );
        $templates = array();

        foreach ( $posts as $template ) {
            if ( ! current_user_can( 'read_post', $template->ID ) && ! current_user_can( 'edit_post', $template->ID ) ) {
                continue;
            }

            $terms = taxonomy_exists( 'elementor_library_type' )
                ? wp_get_post_terms( $template->ID, 'elementor_library_type', array( 'fields' => 'slugs' ) )
                : array();

            $templates[] = array(
                'id' => (int) $template->ID,
                'name' => $template->post_title,
                'type' => is_array( $terms ) && ! is_wp_error( $terms ) && ! empty( $terms ) ? (string) $terms[0] : get_post_meta( $template->ID, '_elementor_template_type', true ),
                'status' => $template->post_status,
                'date_modified_gmt' => $template->post_modified_gmt,
            );
        }

        return array(
            'success' => true,
            'count' => count( $templates ),
            'templates' => $templates,
        );
    }

    private static function import_template( $args ) {
        if ( ! post_type_exists( self::TEMPLATE_POST_TYPE ) ) {
            throw new Exception( 'Elementor template library post type is not available.' );
        }

        $title = sanitize_text_field( (string) self::array_value( $args, 'title', '' ) );
        $template_type = sanitize_key( (string) self::array_value( $args, 'template_type', 'page' ) );
        $template_json = (string) self::array_value( $args, 'template_json', '' );

        if ( '' === $title || '' === $template_json ) {
            throw new Exception( 'title and template_json are required.' );
        }

        $allowed_types = array( 'page', 'section', 'widget', 'popup', 'header', 'footer', 'single', 'archive', 'loop-item', 'container' );
        if ( '' === $template_type || ! in_array( $template_type, $allowed_types, true ) ) {
            $template_type = 'page';
        }

        $decoded = json_decode( $template_json, true );
        if ( ! is_array( $decoded ) ) {
            throw new Exception( 'template_json must be a JSON-encoded Elementor export or elements array.' );
        }

        $page_settings = array();
        if ( isset( $decoded['content'] ) && is_array( $decoded['content'] ) ) {
            $elements = $decoded['content'];
            if ( isset( $decoded['page_settings'] ) && is_array( $decoded['page_settings'] ) ) {
                $page_settings = $decoded['page_settings'];
            }
        } else {
            $elements = $decoded;
        }

        if ( ! self::looks_like_elementor_elements( $elements ) ) {
            throw new Exception( 'template_json content is not a valid Elementor elements array.' );
        }

        $elements = self::regenerate_element_ids( $elements );

        $new_id = wp_insert_post(
            array(
                'post_title' => $title,
                'post_status' => 'publish',
                'post_type' => self::TEMPLATE_POST_TYPE,
                'post_author' => get_current_user_id(),
            ),
            true
        );

        if ( is_wp_error( $new_id ) ) {
            throw new Exception( esc_html( $new_id->get_error_message() ) );
        }

        if ( taxonomy_exists( 'elementor_library_type' ) ) {
            wp_set_object_terms( $new_id, $template_type, 'elementor_library_type' );
        }

        self::save_elementor_tree( $new_id, $elements );
        update_post_meta( $new_id, '_elementor_edit_mode', 'builder' );
        update_post_meta( $new_id, '_elementor_template_type', $template_type );
        if ( defined( 'ELEMENTOR_VERSION' ) ) {
            update_post_meta( $new_id, '_elementor_version', ELEMENTOR_VERSION );
        }
        if ( ! empty( $page_settings ) ) {
            update_post_meta( $new_id, '_elementor_page_settings', $page_settings );
        }
        self::clear_elementor_cache( $new_id );

        return array(
            'success' => true,
            'id' => (int) $new_id,
            'template_id' => (int) $new_id,
            'title' => $title,
            'template_type' => $template_type,
            'edit_url' => admin_url( 'post.php?post=' . (int) $new_id . '&action=elementor' ),
        );
    }

    private static function get_elementor_tree( $post_id, $label ) {
        $post_id = self::sanitize_positive_int( $post_id );
        if ( $post_id <= 0 ) {
            throw new Exception( 'Invalid post_id.' );
        }

        $raw = get_post_meta( $post_id, '_elementor_data', true );
        if ( '' === $raw || null === $raw || false === $raw ) {
            throw new Exception( esc_html( $label ) . ' does not have Elementor data.' );
        }

        $tree = is_string( $raw ) ? json_decode( $raw, true ) : $raw;
        if ( ! is_array( $tree ) ) {
            throw new Exception( 'Could not parse _elementor_data as a JSON array.' );
        }

        return $tree;
    }

    private static function save_elementor_tree( $post_id, $tree ) {
        update_post_meta( $post_id, '_elementor_data', wp_slash( wp_json_encode( $tree ) ) );
        if ( get_post_meta( $post_id, '_elementor_edit_mode', true ) === '' ) {
            update_post_meta( $post_id, '_elementor_edit_mode', 'builder' );
        }
        if ( defined( 'ELEMENTOR_VERSION' ) && get_post_meta( $post_id, '_elementor_version', true ) === '' ) {
            update_post_meta( $post_id, '_elementor_version', ELEMENTOR_VERSION );
        }
    }

    private static function copy_elementor_meta( $source_id, $target_id ) {
        $meta_keys = array(
            '_elementor_edit_mode',
            '_elementor_template_type',
            '_elementor_version',
            '_elementor_pro_version',
            '_elementor_page_settings',
            '_elementor_page_assets',
            '_wp_page_template',
        );

        foreach ( $meta_keys as $key ) {
            $value = get_post_meta( $source_id, $key, true );
            if ( '' !== $value && null !== $value && false !== $value ) {
                update_post_meta( $target_id, $key, $value );
            }
        }
    }

    private static function ensure_builder_meta( $post_id, $post_type ) {
        if ( get_post_meta( $post_id, '_elementor_edit_mode', true ) === '' ) {
            update_post_meta( $post_id, '_elementor_edit_mode', 'builder' );
        }
        if ( get_post_meta( $post_id, '_elementor_template_type', true ) === '' ) {
            update_post_meta( $post_id, '_elementor_template_type', 'page' === $post_type ? 'wp-page' : 'wp-post' );
        }
        if ( defined( 'ELEMENTOR_VERSION' ) && get_post_meta( $post_id, '_elementor_version', true ) === '' ) {
            update_post_meta( $post_id, '_elementor_version', ELEMENTOR_VERSION );
        }
    }

    private static function regenerate_element_ids( $elements ) {
        if ( ! is_array( $elements ) ) {
            return $elements;
        }

        $out = array();
        foreach ( $elements as $element ) {
            if ( ! is_array( $element ) ) {
                $out[] = $element;
                continue;
            }

            if ( isset( $element['id'] ) ) {
                $element['id'] = self::generate_element_id();
            }
            if ( isset( $element['elements'] ) && is_array( $element['elements'] ) ) {
                $element['elements'] = self::regenerate_element_ids( $element['elements'] );
            }

            $out[] = $element;
        }

        return $out;
    }

    private static function generate_element_id() {
        try {
            return bin2hex( random_bytes( 4 ) );
        } catch ( Exception $e ) {
            return substr( md5( wp_generate_uuid4() . wp_rand() ), 0, 8 );
        }
    }

    private static function walk_widgets_text( $elements, $find, $replace, $case_insensitive, &$counter ) {
        if ( ! is_array( $elements ) ) {
            return $elements;
        }

        $text_fields = array(
            'heading' => array( 'title' ),
            'text-editor' => array( 'editor' ),
            'button' => array( 'text' ),
            'image' => array( 'caption', 'alt' ),
            'image-box' => array( 'title_text', 'description_text' ),
            'icon-box' => array( 'title_text', 'description_text' ),
            'icon-list' => array( 'icon_list' ),
            'video' => array( 'caption' ),
            'testimonial' => array( 'testimonial_content', 'testimonial_name', 'testimonial_job' ),
            'tabs' => array( 'tabs' ),
            'accordion' => array( 'tabs' ),
            'toggle' => array( 'tabs' ),
            'star-rating' => array( 'title' ),
            'call-to-action' => array( 'title', 'description', 'button' ),
            'flip-box' => array( 'title_text_a', 'description_text_a', 'title_text_b', 'description_text_b', 'button_text' ),
            'counter' => array( 'title' ),
            'progress' => array( 'title' ),
            'alert' => array( 'title', 'description' ),
            'price-table' => array( 'heading', 'sub_heading', 'description', 'button_text', 'footer_additional_info' ),
        );

        $out = array();
        foreach ( $elements as $element ) {
            if ( ! is_array( $element ) ) {
                $out[] = $element;
                continue;
            }

            if ( self::is_mutable_classic_widget( $element ) ) {
                $widget_type = (string) self::array_value( $element, 'widgetType', '' );
                if ( isset( $text_fields[ $widget_type ], $element['settings'] ) && is_array( $element['settings'] ) ) {
                    foreach ( $text_fields[ $widget_type ] as $field ) {
                        if ( array_key_exists( $field, $element['settings'] ) ) {
                            $element['settings'][ $field ] = self::replace_text_value( $element['settings'][ $field ], $find, $replace, $case_insensitive, $counter );
                        }
                    }
                }
            }

            if ( isset( $element['elements'] ) && is_array( $element['elements'] ) ) {
                $element['elements'] = self::walk_widgets_text( $element['elements'], $find, $replace, $case_insensitive, $counter );
            }

            $out[] = $element;
        }

        return $out;
    }

    private static function replace_text_value( $value, $find, $replace, $case_insensitive, &$counter ) {
        if ( is_string( $value ) ) {
            $count = 0;
            $updated = $case_insensitive ? str_ireplace( $find, $replace, $value, $count ) : str_replace( $find, $replace, $value, $count );
            $counter['count'] += $count;
            return $updated;
        }

        if ( is_array( $value ) ) {
            foreach ( $value as $key => $item ) {
                if ( is_string( $item ) || is_array( $item ) ) {
                    $value[ $key ] = self::replace_text_value( $item, $find, $replace, $case_insensitive, $counter );
                }
            }
        }

        return $value;
    }

    private static function walk_widgets_image( $elements, $old_url, $new_url, $old_id, $new_id, &$counter ) {
        if ( ! is_array( $elements ) ) {
            return $elements;
        }

        $out = array();
        foreach ( $elements as $element ) {
            if ( ! is_array( $element ) ) {
                $out[] = $element;
                continue;
            }

            $el_type = (string) self::array_value( $element, 'elType', '' );
            if ( isset( $element['settings'] ) && is_array( $element['settings'] ) && ( 'widget' !== $el_type || self::is_mutable_classic_widget( $element ) ) ) {
                $element['settings'] = self::swap_image_in_settings( $element['settings'], $old_url, $new_url, $old_id, $new_id, $counter );
            }

            if ( isset( $element['elements'] ) && is_array( $element['elements'] ) ) {
                $element['elements'] = self::walk_widgets_image( $element['elements'], $old_url, $new_url, $old_id, $new_id, $counter );
            }

            $out[] = $element;
        }

        return $out;
    }

    private static function swap_image_in_settings( $settings, $old_url, $new_url, $old_id, $new_id, &$counter ) {
        foreach ( $settings as $key => $value ) {
            if ( is_array( $value ) ) {
                if ( isset( $value['url'] ) && is_string( $value['url'] ) && $old_url === $value['url'] ) {
                    $settings[ $key ]['url'] = $new_url;
                    if ( $old_id > 0 && $new_id > 0 && isset( $value['id'] ) && (int) $value['id'] === $old_id ) {
                        $settings[ $key ]['id'] = $new_id;
                    }
                    $counter['count']++;
                } else {
                    $settings[ $key ] = self::swap_image_in_settings( $value, $old_url, $new_url, $old_id, $new_id, $counter );
                }
            }
        }

        return $settings;
    }

    private static function walk_widgets_links( $elements, $old_url, $new_url, &$counter ) {
        if ( ! is_array( $elements ) ) {
            return $elements;
        }

        $out = array();
        foreach ( $elements as $element ) {
            if ( ! is_array( $element ) ) {
                $out[] = $element;
                continue;
            }

            $el_type = (string) self::array_value( $element, 'elType', '' );
            if ( isset( $element['settings'] ) && is_array( $element['settings'] ) && ( 'widget' !== $el_type || self::is_mutable_classic_widget( $element ) ) ) {
                $element['settings'] = self::swap_links_in_settings( $element['settings'], $old_url, $new_url, $counter, '' );
            }

            if ( isset( $element['elements'] ) && is_array( $element['elements'] ) ) {
                $element['elements'] = self::walk_widgets_links( $element['elements'], $old_url, $new_url, $counter );
            }

            $out[] = $element;
        }

        return $out;
    }

    private static function swap_links_in_settings( $settings, $old_url, $new_url, &$counter, $parent_key ) {
        foreach ( $settings as $key => $value ) {
            $key_string = is_string( $key ) ? $key : '';

            if ( is_array( $value ) ) {
                if ( isset( $value['url'] ) && is_string( $value['url'] ) && $old_url === $value['url'] && self::is_linkish_key( $key_string ) ) {
                    $settings[ $key ]['url'] = $new_url;
                    $counter['count']++;
                    continue;
                }
                $settings[ $key ] = self::swap_links_in_settings( $value, $old_url, $new_url, $counter, $key_string );
                continue;
            }

            if ( is_string( $value ) && $old_url === $value && self::is_linkish_key( $key_string ? $key_string : $parent_key ) && ! ( 'url' === $key_string && self::is_mediaish_key( $parent_key ) ) ) {
                $settings[ $key ] = $new_url;
                $counter['count']++;
            }
        }

        return $settings;
    }

    private static function is_linkish_key( $key ) {
        $key = strtolower( (string) $key );
        if ( '' === $key ) {
            return false;
        }

        if ( in_array( $key, array( 'link', 'url', 'button_link', 'link_to', 'selected_url', 'website' ), true ) ) {
            return true;
        }

        return false !== strpos( $key, 'link' ) || false !== strpos( $key, 'url' );
    }

    private static function is_mediaish_key( $key ) {
        $key = strtolower( (string) $key );
        return false !== strpos( $key, 'image' ) || false !== strpos( $key, 'gallery' ) || false !== strpos( $key, 'media' ) || false !== strpos( $key, 'background' );
    }

    private static function build_outline( $elements, $depth ) {
        if ( ! is_array( $elements ) ) {
            return array();
        }
        if ( $depth > 6 ) {
            return array( 'deep nesting truncated' );
        }

        $out = array();
        foreach ( $elements as $element ) {
            if ( ! is_array( $element ) ) {
                continue;
            }

            $el_type = (string) self::array_value( $element, 'elType', 'unknown' );
            $entry = array( 'elType' => $el_type );

            if ( 'widget' === $el_type ) {
                $entry['widgetType'] = (string) self::array_value( $element, 'widgetType', 'unknown' );
                $snippet = self::widget_text_snippet( $element );
                if ( '' !== $snippet ) {
                    $entry['snippet'] = $snippet;
                }
            } elseif ( 'container' === $el_type && isset( $element['settings']['flex_direction'] ) ) {
                $entry['flex_direction'] = (string) $element['settings']['flex_direction'];
            }

            if ( isset( $element['elements'] ) && is_array( $element['elements'] ) && ! empty( $element['elements'] ) ) {
                $entry['children'] = self::build_outline( $element['elements'], $depth + 1 );
            }

            $out[] = $entry;
        }

        return $out;
    }

    private static function widget_text_snippet( $widget ) {
        $widget_type = (string) self::array_value( $widget, 'widgetType', '' );
        $settings = isset( $widget['settings'] ) && is_array( $widget['settings'] ) ? $widget['settings'] : array();
        $candidates = array(
            'heading' => array( 'title' ),
            'text-editor' => array( 'editor' ),
            'button' => array( 'text' ),
            'image-box' => array( 'title_text' ),
            'icon-box' => array( 'title_text' ),
            'call-to-action' => array( 'title' ),
            'flip-box' => array( 'title_text_a', 'title_text_b' ),
            'price-table' => array( 'heading', 'sub_heading' ),
        );

        if ( ! isset( $candidates[ $widget_type ] ) ) {
            return '';
        }

        foreach ( $candidates[ $widget_type ] as $key ) {
            if ( isset( $settings[ $key ] ) && is_string( $settings[ $key ] ) && '' !== $settings[ $key ] ) {
                $plain = preg_replace( '/\s+/', ' ', wp_strip_all_tags( $settings[ $key ] ) );
                return trim( function_exists( 'mb_strimwidth' ) ? mb_strimwidth( $plain, 0, 80, '...' ) : substr( $plain, 0, 80 ) );
            }
        }

        return '';
    }

    private static function is_mutable_classic_widget( $element ) {
        if ( ! is_array( $element ) || 'widget' !== (string) self::array_value( $element, 'elType', '' ) ) {
            return false;
        }

        $widget_type = (string) self::array_value( $element, 'widgetType', '' );
        if ( '' === $widget_type ) {
            return false;
        }

        return 0 !== strpos( $widget_type, 'a-' ) && 0 !== strpos( $widget_type, 'e-' );
    }

    private static function looks_like_elementor_elements( $elements ) {
        if ( ! is_array( $elements ) ) {
            return false;
        }
        if ( empty( $elements ) ) {
            return true;
        }

        foreach ( $elements as $element ) {
            if ( ! is_array( $element ) ) {
                return false;
            }
            if ( ! isset( $element['id'] ) && ! isset( $element['elType'] ) && ! isset( $element['elements'] ) ) {
                return false;
            }
        }

        return true;
    }

    private static function require_editable_elementor_post( $args ) {
        $post_id = self::sanitize_positive_int( self::array_value( $args, 'post_id', 0 ) );
        if ( $post_id <= 0 ) {
            throw new Exception( 'post_id is required.' );
        }
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            throw new Exception( 'edit_post capability required on target post.' );
        }
        self::get_elementor_tree( $post_id, 'Target post' );
        return $post_id;
    }

    private static function current_user_can_publish_post_type( $post_type ) {
        $post_type_object = get_post_type_object( $post_type );
        if ( $post_type_object && ! empty( $post_type_object->cap ) && isset( $post_type_object->cap->publish_posts ) ) {
            return current_user_can( $post_type_object->cap->publish_posts );
        }
        return current_user_can( 'publish_posts' );
    }

    private static function clear_elementor_cache( $post_id ) {
        if ( class_exists( 'Elementor\\Plugin' ) && isset( \Elementor\Plugin::$instance ) ) {
            $plugin = \Elementor\Plugin::$instance;
            if ( is_object( $plugin ) && isset( $plugin->files_manager ) && is_object( $plugin->files_manager ) && method_exists( $plugin->files_manager, 'clear_cache' ) ) {
                try {
                    $plugin->files_manager->clear_cache();
                } catch ( Exception $e ) {
                    stifli_flex_mcp_log( 'Elementor cache clear failed: ' . $e->getMessage() );
                }
            }
        }

        clean_post_cache( $post_id );
    }

    private static function set_result_payload( &$r, $payload, $addResultText ) {
        $payload = is_array( $payload ) ? $payload : array();
        $r['result'] = array(
            'content' => array(),
            'structuredContent' => $payload,
        );

        $json = wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
        if ( is_callable( $addResultText ) ) {
            $addResultText( $r, $json );
        } else {
            $r['result']['content'][] = array( 'type' => 'text', 'text' => $json );
        }
    }

    private static function build_post_snapshot( $operation, $post_type, $object_id ) {
        $snapshot = array(
            'operation_type' => $operation,
            'object_type' => 'post',
            'object_id' => $object_id > 0 ? $object_id : null,
            'object_subtype' => $post_type,
            'before_state' => null,
        );

        if ( $object_id > 0 && in_array( $operation, array( 'update', 'delete' ), true ) ) {
            $post = get_post( $object_id, ARRAY_A );
            if ( $post ) {
                $snapshot['before_state'] = $post;
                $snapshot['before_state']['meta'] = get_post_meta( $object_id );
                $snapshot['object_subtype'] = isset( $post['post_type'] ) ? $post['post_type'] : $post_type;
            }
        }

        return $snapshot;
    }

    private static function sanitize_limit( $value, $default, $max ) {
        $value = self::sanitize_positive_int( $value );
        if ( $value <= 0 ) {
            $value = (int) $default;
        }
        return max( 1, min( $max, $value ) );
    }

    private static function sanitize_positive_int( $value ) {
        return max( 0, absint( $value ) );
    }

    private static function is_truthy( $value ) {
        if ( is_bool( $value ) ) {
            return $value;
        }
        if ( is_int( $value ) ) {
            return 1 === $value;
        }
        if ( is_string( $value ) ) {
            return in_array( strtolower( trim( $value ) ), array( '1', 'true', 'yes', 'on' ), true );
        }
        return ! empty( $value );
    }

    private static function array_value( $array, $key, $default = null ) {
        return is_array( $array ) && array_key_exists( $key, $array ) ? $array[ $key ] : $default;
    }
}
