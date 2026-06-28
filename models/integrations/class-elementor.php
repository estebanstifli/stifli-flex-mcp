<?php
/**
 * Elementor MCP integration.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class StifliFlexMcp_Elementor {

    const TEMPLATE_POST_TYPE = 'elementor_library';
    const CURATED_WIDGET_TYPES = array(
        'container',
        'heading',
        'text-editor',
        'button',
        'image',
        'image-box',
        'icon-box',
        'icon-list',
        'video',
        'divider',
        'spacer',
    );

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
            'elementor_add_widget' => array(
                'name' => 'elementor_add_widget',
                'description' => 'Add a widget or container to an existing Elementor post/page. Supports raw settings for registered widget types only when the caller has unfiltered_html, and curated flat parameters for container, heading, text-editor, button, image, image-box, icon-box, icon-list, video, divider, and spacer. Containers can include recursive children. Returns the new element ID and Elementor edit URL.',
                'inputSchema' => array(
                    'type' => 'object',
                    'properties' => array(
                        'post_id' => array( 'type' => 'integer', 'description' => 'Target post or page ID with existing Elementor data.' ),
                        'widget_type' => array( 'type' => 'string', 'description' => 'Elementor widget slug, or container for a Flexbox container.' ),
                        'settings' => array( 'type' => 'object', 'description' => 'Raw Elementor settings object. Requires unfiltered_html capability and takes precedence over curated flat parameters.', 'additionalProperties' => true ),
                        'parent_id' => array( 'type' => 'string', 'description' => 'Optional parent element ID. Must reference a container, section, or column.' ),
                        'position' => array( 'type' => 'integer', 'description' => 'Optional zero-based insertion position. Defaults to append.' ),
                        'flex_direction' => array( 'type' => 'string', 'enum' => array( 'row', 'column' ), 'description' => 'Curated container direction. Defaults to column.' ),
                        'content_width' => array( 'type' => 'string', 'enum' => array( 'boxed', 'full' ), 'description' => 'Curated container width. Defaults to boxed.' ),
                        'children' => array( 'type' => 'array', 'items' => array( 'type' => 'object' ), 'description' => 'Curated container children. Each child supports widget_type plus curated params or settings.' ),
                        'title' => array( 'type' => 'string', 'description' => 'Curated heading text.' ),
                        'header_size' => array( 'type' => 'string', 'description' => 'Curated heading tag: h1-h6, div, span, or p. Defaults to h2.' ),
                        'editor' => array( 'type' => 'string', 'description' => 'Curated text-editor HTML content.' ),
                        'text' => array( 'type' => 'string', 'description' => 'Curated button label.' ),
                        'link_url' => array( 'type' => 'string', 'description' => 'Curated button/image/image-box/icon-box link URL.' ),
                        'link_target' => array( 'type' => 'string', 'enum' => array( '_blank', '_self' ), 'description' => 'Curated link target. Defaults to _self.' ),
                        'image_url' => array( 'type' => 'string', 'description' => 'Curated image or image-box URL.' ),
                        'image_alt' => array( 'type' => 'string', 'description' => 'Curated image alt text.' ),
                        'title_text' => array( 'type' => 'string', 'description' => 'Curated image-box or icon-box title.' ),
                        'description_text' => array( 'type' => 'string', 'description' => 'Curated image-box or icon-box description.' ),
                        'title_size' => array( 'type' => 'string', 'description' => 'Curated image-box or icon-box title tag. Defaults to h3.' ),
                        'icon' => array( 'type' => 'string', 'description' => 'Curated icon-box FontAwesome class, such as fas fa-check.' ),
                        'items' => array( 'type' => 'array', 'items' => array( 'type' => 'object' ), 'description' => 'Curated icon-list items: { text, icon?, link_url? }.' ),
                        'video_url' => array( 'type' => 'string', 'description' => 'Curated YouTube, Vimeo, or Dailymotion URL.' ),
                        'aspect_ratio' => array( 'type' => 'string', 'enum' => array( '169', '219', '43', '32', '11', '916' ), 'description' => 'Curated video aspect ratio. Defaults to 169.' ),
                        'autoplay' => array( 'type' => 'boolean', 'description' => 'Curated video autoplay. Defaults false.' ),
                        'weight' => array( 'type' => 'integer', 'description' => 'Curated divider weight in pixels. Defaults to 1.' ),
                        'color' => array( 'type' => 'string', 'description' => 'Curated divider color.' ),
                        'space' => array( 'type' => 'integer', 'description' => 'Curated spacer height in pixels. Defaults to 50.' ),
                    ),
                    'required' => array( 'post_id', 'widget_type' ),
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
            'elementor_add_widget' => 'edit_posts',
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

        if ( in_array( $tool, array( 'elementor_replace_text', 'elementor_replace_image', 'elementor_replace_link', 'elementor_add_widget' ), true ) ) {
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
                case 'elementor_add_widget':
                    $payload = self::add_widget( $args );
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

    private static function add_widget( $args ) {
        $args = is_array( $args ) ? $args : array();
        $post_id = self::require_editable_elementor_post( $args );
        $widget_type = sanitize_key( (string) self::array_value( $args, 'widget_type', '' ) );
        if ( '' === $widget_type ) {
            throw new Exception( 'widget_type is required.' );
        }

        $tree = self::get_elementor_tree( $post_id, 'Target post' );
        $new_element = self::build_element_from_widget_args( $args );

        $parent_id_raw = self::array_value( $args, 'parent_id', null );
        $parent_id = null;
        if ( is_scalar( $parent_id_raw ) || null === $parent_id_raw ) {
            $parent_id = trim( (string) $parent_id_raw );
            if ( '' === $parent_id ) {
                $parent_id = null;
            }
        }
        $position = array_key_exists( 'position', $args ) ? max( 0, intval( $args['position'] ) ) : null;

        if ( null !== $parent_id ) {
            $parent = self::find_element_by_id( $tree, $parent_id );
            if ( null === $parent ) {
                throw new Exception( 'parent_id not found in this Elementor document.' );
            }
            $parent_type = (string) self::array_value( $parent, 'elType', '' );
            if ( ! in_array( $parent_type, array( 'container', 'section', 'column' ), true ) ) {
                throw new Exception( 'parent_id must reference a container, section, or column.' );
            }
            if ( 'container' === self::array_value( $new_element, 'elType', '' ) && 'container' === $parent_type ) {
                $new_element['isInner'] = true;
            }
        }

        $updated_tree = self::insert_element_into_tree( $tree, $parent_id, $position, $new_element );
        self::save_elementor_tree( $post_id, $updated_tree );
        self::clear_elementor_cache( $post_id );

        $payload = array(
            'success' => true,
            'post_id' => $post_id,
            'new_id' => (string) self::array_value( $new_element, 'id', '' ),
            'widget_type' => $widget_type,
            'parent_id' => $parent_id,
            'position' => $position,
            'edit_url' => admin_url( 'post.php?post=' . $post_id . '&action=elementor' ),
        );

        if ( self::has_raw_settings( $args ) && self::is_curated_widget_type( $widget_type ) ) {
            $payload['notice'] = 'Raw settings were supplied for a curated widget_type, so curated flat parameters were ignored.';
        }

        return $payload;
    }

    private static function build_element_from_widget_args( $args ) {
        $widget_type = sanitize_key( (string) self::array_value( $args, 'widget_type', '' ) );
        if ( '' === $widget_type ) {
            throw new Exception( 'widget_type is required for every element.' );
        }

        $is_curated = self::is_curated_widget_type( $widget_type );
        $has_settings = self::has_raw_settings( $args );
        if ( ! $is_curated && ! $has_settings ) {
            throw new Exception( 'Non-curated widget_type requires a settings object.' );
        }
        if ( ! $is_curated && ! self::is_registered_or_atomic_widget_type( $widget_type ) ) {
            throw new Exception( 'widget_type is not registered with Elementor on this site.' );
        }

        $settings = self::resolve_widget_settings( $widget_type, $args, $has_settings );
        $el_type = 'container' === $widget_type ? 'container' : 'widget';
        $element = array(
            'id' => self::generate_element_id(),
            'elType' => $el_type,
            'settings' => $settings,
            'elements' => array(),
            'isInner' => false,
        );
        if ( 'widget' === $el_type ) {
            $element['widgetType'] = $widget_type;
        }

        $children = self::array_value( $args, 'children', array() );
        if ( 'container' === $widget_type && is_array( $children ) ) {
            foreach ( $children as $child_args ) {
                if ( ! is_array( $child_args ) ) {
                    continue;
                }
                $child = self::build_element_from_widget_args( $child_args );
                if ( 'container' === self::array_value( $child, 'elType', '' ) ) {
                    $child['isInner'] = true;
                }
                $element['elements'][] = $child;
            }
        }

        return $element;
    }

    private static function has_raw_settings( $args ) {
        return isset( $args['settings'] ) && is_array( $args['settings'] );
    }

    private static function resolve_widget_settings( $widget_type, $args, $has_settings = null ) {
        $has_settings = is_bool( $has_settings ) ? $has_settings : self::has_raw_settings( $args );
        if ( $has_settings ) {
            if ( ! current_user_can( 'unfiltered_html' ) ) {
                throw new Exception( 'Raw Elementor settings require unfiltered_html capability. Use curated parameters instead.' );
            }

            $raw_settings = self::array_value( $args, 'settings', array() );
            return is_array( $raw_settings ) ? $raw_settings : array();
        }

        return self::build_curated_widget_settings( $widget_type, $args );
    }

    private static function is_curated_widget_type( $widget_type ) {
        return in_array( (string) $widget_type, self::CURATED_WIDGET_TYPES, true );
    }

    private static function is_registered_or_atomic_widget_type( $widget_type ) {
        $widget_type = (string) $widget_type;
        if ( 0 === strpos( $widget_type, 'a-' ) || 0 === strpos( $widget_type, 'e-' ) ) {
            return true;
        }
        if ( ! class_exists( 'Elementor\\Plugin' ) || empty( \Elementor\Plugin::$instance ) ) {
            return false;
        }
        $manager = isset( \Elementor\Plugin::$instance->widgets_manager ) ? \Elementor\Plugin::$instance->widgets_manager : null;
        if ( ! is_object( $manager ) || ! method_exists( $manager, 'get_widget_types' ) ) {
            return false;
        }
        $registered = $manager->get_widget_types();
        return is_array( $registered ) && isset( $registered[ $widget_type ] );
    }

    private static function build_curated_widget_settings( $widget_type, $args ) {
        switch ( $widget_type ) {
            case 'container':
                return self::curated_container_settings( $args );
            case 'heading':
                return self::curated_heading_settings( $args );
            case 'text-editor':
                return self::curated_text_editor_settings( $args );
            case 'button':
                return self::curated_button_settings( $args );
            case 'image':
                return self::curated_image_settings( $args );
            case 'image-box':
                return self::curated_image_box_settings( $args );
            case 'icon-box':
                return self::curated_icon_box_settings( $args );
            case 'icon-list':
                return self::curated_icon_list_settings( $args );
            case 'video':
                return self::curated_video_settings( $args );
            case 'divider':
                return self::curated_divider_settings( $args );
            case 'spacer':
                return self::curated_spacer_settings( $args );
        }
        throw new Exception( 'No curated builder for widget_type.' );
    }

    private static function curated_container_settings( $args ) {
        $direction = (string) self::array_value( $args, 'flex_direction', 'column' );
        $width = (string) self::array_value( $args, 'content_width', 'boxed' );
        return array(
            'content_width' => in_array( $width, array( 'boxed', 'full' ), true ) ? $width : 'boxed',
            'flex_direction' => in_array( $direction, array( 'row', 'column' ), true ) ? $direction : 'column',
        );
    }

    private static function curated_heading_settings( $args ) {
        $title = wp_kses_post( (string) self::array_value( $args, 'title', '' ) );
        if ( '' === $title ) {
            throw new Exception( 'Curated heading requires title.' );
        }
        return array(
            'title' => $title,
            'header_size' => self::sanitize_html_tag_choice( self::array_value( $args, 'header_size', 'h2' ), 'h2' ),
        );
    }

    private static function curated_text_editor_settings( $args ) {
        $editor = wp_kses_post( (string) self::array_value( $args, 'editor', '' ) );
        if ( '' === $editor ) {
            throw new Exception( 'Curated text-editor requires editor.' );
        }
        return array( 'editor' => $editor );
    }

    private static function curated_button_settings( $args ) {
        $text = sanitize_text_field( (string) self::array_value( $args, 'text', '' ) );
        $url = esc_url_raw( (string) self::array_value( $args, 'link_url', '' ) );
        if ( '' === $text || '' === $url ) {
            throw new Exception( 'Curated button requires text and link_url.' );
        }
        return array(
            'text' => $text,
            'link' => self::elementor_link_value( $url, self::sanitize_link_target( self::array_value( $args, 'link_target', '_self' ) ) ),
        );
    }

    private static function curated_image_settings( $args ) {
        $image_url = esc_url_raw( (string) self::array_value( $args, 'image_url', '' ) );
        if ( '' === $image_url ) {
            throw new Exception( 'Curated image requires image_url.' );
        }
        $settings = array(
            'image' => self::elementor_image_value( $image_url, (string) self::array_value( $args, 'image_alt', '' ) ),
        );
        $link_url = esc_url_raw( (string) self::array_value( $args, 'link_url', '' ) );
        if ( '' !== $link_url ) {
            $settings['link_to'] = 'custom';
            $settings['link'] = self::elementor_link_value( $link_url, self::sanitize_link_target( self::array_value( $args, 'link_target', '_self' ) ) );
        }
        return $settings;
    }

    private static function curated_image_box_settings( $args ) {
        $image_url = esc_url_raw( (string) self::array_value( $args, 'image_url', '' ) );
        $title = sanitize_text_field( (string) self::array_value( $args, 'title_text', '' ) );
        if ( '' === $image_url || '' === $title ) {
            throw new Exception( 'Curated image-box requires image_url and title_text.' );
        }
        $settings = array(
            'image' => self::elementor_image_value( $image_url, (string) self::array_value( $args, 'image_alt', '' ) ),
            'title_text' => $title,
            'description_text' => wp_kses_post( (string) self::array_value( $args, 'description_text', '' ) ),
            'title_size' => self::sanitize_html_tag_choice( self::array_value( $args, 'title_size', 'h3' ), 'h3' ),
        );
        $link_url = esc_url_raw( (string) self::array_value( $args, 'link_url', '' ) );
        if ( '' !== $link_url ) {
            $settings['link'] = self::elementor_link_value( $link_url, self::sanitize_link_target( self::array_value( $args, 'link_target', '_self' ) ) );
        }
        return $settings;
    }

    private static function curated_icon_box_settings( $args ) {
        $icon = sanitize_text_field( (string) self::array_value( $args, 'icon', '' ) );
        $title = sanitize_text_field( (string) self::array_value( $args, 'title_text', '' ) );
        if ( '' === $icon || '' === $title ) {
            throw new Exception( 'Curated icon-box requires icon and title_text.' );
        }
        $settings = array(
            'selected_icon' => self::elementor_icon_value( $icon ),
            'title_text' => $title,
            'description_text' => wp_kses_post( (string) self::array_value( $args, 'description_text', '' ) ),
            'title_size' => self::sanitize_html_tag_choice( self::array_value( $args, 'title_size', 'h3' ), 'h3' ),
        );
        $link_url = esc_url_raw( (string) self::array_value( $args, 'link_url', '' ) );
        if ( '' !== $link_url ) {
            $settings['link'] = self::elementor_link_value( $link_url, self::sanitize_link_target( self::array_value( $args, 'link_target', '_self' ) ) );
        }
        return $settings;
    }

    private static function curated_icon_list_settings( $args ) {
        $items = self::array_value( $args, 'items', array() );
        if ( empty( $items ) || ! is_array( $items ) ) {
            throw new Exception( 'Curated icon-list requires items.' );
        }
        $icon_list = array();
        foreach ( $items as $item ) {
            if ( ! is_array( $item ) || '' === trim( (string) self::array_value( $item, 'text', '' ) ) ) {
                throw new Exception( 'Each icon-list item requires text.' );
            }
            $icon = sanitize_text_field( (string) self::array_value( $item, 'icon', 'fas fa-check' ) );
            $row = array(
                '_id' => self::generate_repeater_id(),
                'text' => sanitize_text_field( (string) self::array_value( $item, 'text', '' ) ),
                'selected_icon' => self::elementor_icon_value( $icon ),
            );
            $link_url = esc_url_raw( (string) self::array_value( $item, 'link_url', '' ) );
            if ( '' !== $link_url ) {
                $row['link'] = self::elementor_link_value( $link_url, '_self' );
            }
            $icon_list[] = $row;
        }
        return array(
            'icon_list' => $icon_list,
            'view' => 'traditional',
        );
    }

    private static function curated_video_settings( $args ) {
        $video_url = esc_url_raw( (string) self::array_value( $args, 'video_url', '' ) );
        if ( '' === $video_url ) {
            throw new Exception( 'Curated video requires video_url.' );
        }
        $video = self::route_video_url( $video_url );
        $ratio = (string) self::array_value( $args, 'aspect_ratio', '169' );
        if ( ! in_array( $ratio, array( '169', '219', '43', '32', '11', '916' ), true ) ) {
            $ratio = '169';
        }
        $settings = array(
            'video_type' => $video['type'],
            $video['field'] => $video['url'],
            'aspect_ratio' => $ratio,
        );
        if ( self::is_truthy( self::array_value( $args, 'autoplay', false ) ) ) {
            $settings['autoplay'] = 'yes';
        }
        return $settings;
    }

    private static function curated_divider_settings( $args ) {
        $settings = array( 'style' => 'solid' );
        if ( array_key_exists( 'weight', $args ) ) {
            $settings['weight'] = self::elementor_slider_px( intval( $args['weight'] ) );
        }
        $color = sanitize_hex_color( (string) self::array_value( $args, 'color', '' ) );
        if ( is_string( $color ) && '' !== $color ) {
            $settings['color'] = $color;
        }
        return $settings;
    }

    private static function curated_spacer_settings( $args ) {
        $space = array_key_exists( 'space', $args ) ? intval( $args['space'] ) : 50;
        return array( 'space' => self::elementor_slider_px( max( 0, $space ) ) );
    }

    private static function elementor_link_value( $url, $target = '_self', $nofollow = false ) {
        return array(
            'url' => (string) $url,
            'is_external' => '_blank' === $target ? 'on' : '',
            'nofollow' => $nofollow ? 'on' : '',
        );
    }

    private static function elementor_image_value( $url, $alt = '' ) {
        return array(
            'url' => (string) $url,
            'id' => '',
            'alt' => sanitize_text_field( (string) $alt ),
            'source' => 'library',
            'size' => '',
        );
    }

    private static function elementor_icon_value( $icon ) {
        $icon = sanitize_text_field( (string) $icon );
        return array(
            'value' => $icon,
            'library' => self::derive_icon_library( $icon ),
        );
    }

    private static function elementor_slider_px( $size ) {
        return array(
            'size' => (int) $size,
            'unit' => 'px',
        );
    }

    private static function derive_icon_library( $icon ) {
        $icon = trim( (string) $icon );
        if ( 0 === strpos( $icon, 'fab ' ) ) {
            return 'fa-brands';
        }
        if ( 0 === strpos( $icon, 'far ' ) ) {
            return 'fa-regular';
        }
        return 'fa-solid';
    }

    private static function route_video_url( $url ) {
        if ( preg_match( '#(?:youtube\.com|youtu\.be)#i', $url ) ) {
            return array( 'type' => 'youtube', 'field' => 'youtube_url', 'url' => (string) $url );
        }
        if ( preg_match( '#vimeo\.com#i', $url ) ) {
            return array( 'type' => 'vimeo', 'field' => 'vimeo_url', 'url' => (string) $url );
        }
        if ( preg_match( '#dailymotion\.com#i', $url ) ) {
            return array( 'type' => 'dailymotion', 'field' => 'dailymotion_url', 'url' => (string) $url );
        }
        throw new Exception( 'Curated video supports YouTube, Vimeo, and Dailymotion URLs. Use raw settings for self-hosted video.' );
    }

    private static function sanitize_html_tag_choice( $value, $default ) {
        $value = strtolower( sanitize_key( (string) $value ) );
        return in_array( $value, array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'div', 'span', 'p' ), true ) ? $value : $default;
    }

    private static function sanitize_link_target( $value ) {
        $value = (string) $value;
        return in_array( $value, array( '_blank', '_self' ), true ) ? $value : '_self';
    }

    private static function generate_repeater_id() {
        try {
            return substr( bin2hex( random_bytes( 4 ) ), 0, 7 );
        } catch ( Exception $e ) {
            return substr( md5( wp_generate_uuid4() . wp_rand() ), 0, 7 );
        }
    }

    private static function find_element_by_id( $elements, $element_id ) {
        if ( ! is_array( $elements ) ) {
            return null;
        }
        foreach ( $elements as $element ) {
            if ( ! is_array( $element ) ) {
                continue;
            }
            if ( isset( $element['id'] ) && (string) $element['id'] === (string) $element_id ) {
                return $element;
            }
            if ( isset( $element['elements'] ) && is_array( $element['elements'] ) ) {
                $found = self::find_element_by_id( $element['elements'], $element_id );
                if ( null !== $found ) {
                    return $found;
                }
            }
        }
        return null;
    }

    private static function insert_element_into_tree( $elements, $parent_id, $position, $new_element ) {
        if ( null === $parent_id ) {
            return self::insert_element_at_position( is_array( $elements ) ? $elements : array(), $position, $new_element );
        }

        $out = array();
        foreach ( is_array( $elements ) ? $elements : array() as $element ) {
            if ( ! is_array( $element ) ) {
                $out[] = $element;
                continue;
            }
            if ( isset( $element['id'] ) && (string) $element['id'] === (string) $parent_id ) {
                $children = isset( $element['elements'] ) && is_array( $element['elements'] ) ? $element['elements'] : array();
                $element['elements'] = self::insert_element_at_position( $children, $position, $new_element );
            } elseif ( isset( $element['elements'] ) && is_array( $element['elements'] ) ) {
                $element['elements'] = self::insert_element_into_tree( $element['elements'], $parent_id, $position, $new_element );
            }
            $out[] = $element;
        }
        return $out;
    }

    private static function insert_element_at_position( $elements, $position, $new_element ) {
        $elements = is_array( $elements ) ? $elements : array();
        if ( null === $position || $position >= count( $elements ) ) {
            $elements[] = $new_element;
            return $elements;
        }
        if ( $position <= 0 ) {
            array_unshift( $elements, $new_element );
            return $elements;
        }
        array_splice( $elements, $position, 0, array( $new_element ) );
        return $elements;
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
