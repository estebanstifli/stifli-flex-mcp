<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class StifliFlexMcp_Plugin_Integrations_Registry {

    public static function get_integrations() {
        return array(
            array(
                'id' => 'wpcode',
                'name' => 'WPCode',
                'description' => 'Snippet automation pack shared across snippets plugins.',
                'plugin_files' => array( 'insert-headers-and-footers/ihaf.php' ),
                'install_slug' => 'insert-headers-and-footers',
                'match' => array(
                    'prefixes' => array( 'snippet_' ),
                ),
                'catalog_tools' => array( 'snippet_list', 'snippet_get', 'snippet_create', 'snippet_update', 'snippet_delete' ),
            ),
            array(
                'id' => 'code_snippets',
                'name' => 'Code Snippets',
                'description' => 'Uses the same snippets tool pack as WPCode and Woody.',
                'plugin_files' => array( 'code-snippets/code-snippets.php' ),
                'install_slug' => 'code-snippets',
                'match' => array(
                    'prefixes' => array( 'snippet_' ),
                ),
                'catalog_tools' => array( 'snippet_list', 'snippet_get', 'snippet_create', 'snippet_update', 'snippet_delete' ),
            ),
            array(
                'id' => 'woody_snippets',
                'name' => 'Woody Snippets',
                'description' => 'Alternative snippets provider that shares snippet_* tools.',
                'plugin_files' => array( 'insert-php-code-snippet/insert-php-code-snippet.php' ),
                'install_slug' => 'insert-php-code-snippet',
                'match' => array(
                    'prefixes' => array( 'snippet_' ),
                ),
                'catalog_tools' => array( 'snippet_list', 'snippet_get', 'snippet_create', 'snippet_update', 'snippet_delete' ),
            ),
            array(
                'id' => 'acf_catalog',
                'name' => 'Advanced Custom Fields Catalog',
                'description' => 'Tools for reading and updating ACF fields and field groups.',
                'plugin_files' => array( 'advanced-custom-fields/acf.php' ),
                'install_slug' => 'advanced-custom-fields',
                'match' => array(
                    'tools' => array( 'acf_get_fields', 'acf_update_field', 'acf_get_field_groups' ),
                ),
                'catalog_tools' => array( 'acf_get_fields', 'acf_update_field', 'acf_get_field_groups' ),
            ),
            array(
                'id' => 'yoast_catalog',
                'name' => 'Yoast SEO Catalog',
                'description' => 'SEO tools for reading and updating Yoast metadata and indexing tasks.',
                'plugin_files' => array( 'wordpress-seo/wp-seo.php' ),
                'install_slug' => 'wordpress-seo',
                'match' => array(
                    'tools' => array( 'yoast_get_meta', 'yoast_set_meta', 'yoast_reindex' ),
                ),
                'catalog_tools' => array( 'yoast_get_meta', 'yoast_set_meta', 'yoast_reindex' ),
            ),
            array(
                'id' => 'rankmath_catalog',
                'name' => 'Rank Math Catalog',
                'description' => 'SEO tools for Rank Math head output and post SEO metadata updates.',
                'plugin_files' => array( 'seo-by-rank-math/rank-math.php', 'seo-by-rank-math-pro/rank-math-pro.php' ),
                'match_classes' => array( 'RankMath', 'RankMath\\Runner' ),
                'match_constants' => array( 'RANK_MATH_VERSION' ),
                'install_slug' => 'seo-by-rank-math',
                'match' => array(
                    'tools' => array( 'wp_rm_get_head', 'wp_rm_get_post_seo', 'wp_rm_update_post_seo' ),
                ),
                'catalog_tools' => array( 'wp_rm_get_head', 'wp_rm_get_post_seo', 'wp_rm_update_post_seo' ),
            ),
            array(
                'id' => 'wpforms_catalog',
                'name' => 'WPForms Catalog',
                'description' => 'Form tools for listing WPForms forms and reading entries.',
                'plugin_files' => array( 'wpforms-lite/wpforms.php' ),
                'install_slug' => 'wpforms-lite',
                'match' => array(
                    'tools' => array( 'wpforms_list_forms', 'wpforms_get_entries' ),
                ),
                'catalog_tools' => array( 'wpforms_list_forms', 'wpforms_get_entries' ),
            ),
            array(
                'id' => 'gravityforms_catalog',
                'name' => 'Gravity Forms Catalog',
                'description' => 'Form tools for listing Gravity Forms, reading entries, and updates.',
                'plugin_files' => array( 'gravityforms/gravityforms.php' ),
                'install_slug' => 'gravityforms',
                'match' => array(
                    'tools' => array( 'gf_list_forms', 'gf_get_entries', 'gf_update_entry' ),
                ),
                'catalog_tools' => array( 'gf_list_forms', 'gf_get_entries', 'gf_update_entry' ),
            ),
            array(
                'id' => 'forminator_catalog',
                'name' => 'Forminator Catalog',
                'description' => 'Form tools for listing Forminator forms and reading entries.',
                'plugin_files' => array( 'forminator/forminator.php' ),
                'install_slug' => 'forminator',
                'match' => array(
                    'tools' => array( 'forminator_list_forms', 'forminator_get_entries' ),
                ),
                'catalog_tools' => array( 'forminator_list_forms', 'forminator_get_entries' ),
            ),
        );
    }

    public static function get_integration_ids() {
        $ids = array();
        foreach ( self::get_integrations() as $integration ) {
            $ids[] = $integration['id'];
        }
        return $ids;
    }

    public static function get_integrations_for_tool( $tool_name ) {
        $matches = array();
        foreach ( self::get_integrations() as $integration ) {
            if ( self::tool_matches_integration( $tool_name, $integration ) ) {
                $matches[] = $integration['id'];
            }
        }
        return $matches;
    }

    public static function tool_matches_integration( $tool_name, $integration ) {
        $match = isset( $integration['match'] ) && is_array( $integration['match'] ) ? $integration['match'] : array();
        $tools = isset( $match['tools'] ) && is_array( $match['tools'] ) ? $match['tools'] : array();
        $prefixes = isset( $match['prefixes'] ) && is_array( $match['prefixes'] ) ? $match['prefixes'] : array();

        if ( in_array( $tool_name, $tools, true ) ) {
            return true;
        }

        foreach ( $prefixes as $prefix ) {
            if ( '' !== $prefix && strpos( $tool_name, $prefix ) === 0 ) {
                return true;
            }
        }

        return false;
    }
}
