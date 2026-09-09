<?php
/**
 * Contact Form 7 and Flamingo MCP integration.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class StifliFlexMcp_ContactForm7 {

    public static function getTools() {
        $tools = array(
            'cf7_list_forms' => array(
                'name' => 'cf7_list_forms',
                'description' => 'List Contact Form 7 forms with status, dates, and field counts.',
                'inputSchema' => array(
                    'type' => 'object',
                    'properties' => array(
                        'search' => array('type' => 'string', 'description' => 'Optional title search.'),
                        'limit' => array('type' => 'integer', 'description' => 'Maximum forms to return (1-100).'),
                        'offset' => array('type' => 'integer', 'description' => 'Number of forms to skip.'),
                    ),
                    'required' => array(),
                ),
            ),
            'cf7_get_form' => array(
                'name' => 'cf7_get_form',
                'description' => 'Get a Contact Form 7 form and its normalized field schema.',
                'inputSchema' => array(
                    'type' => 'object',
                    'properties' => array(
                        'form_id' => array('type' => 'integer', 'description' => 'Contact Form 7 form ID.'),
                    ),
                    'required' => array('form_id'),
                ),
            ),
        );

        if ( post_type_exists( 'flamingo_inbound' ) ) {
            $tools['flamingo_get_submissions'] = array(
                'name' => 'flamingo_get_submissions',
                'description' => 'Read redacted Contact Form 7 submissions stored by Flamingo. Email, phone, IP, and secrets are removed.',
                'inputSchema' => array(
                    'type' => 'object',
                    'properties' => array(
                        'search' => array('type' => 'string', 'description' => 'Optional submission search.'),
                        'status' => array('type' => 'string', 'description' => 'Optional Flamingo post status.'),
                        'after' => array('type' => 'string', 'description' => 'Optional ISO 8601 lower date bound.'),
                        'before' => array('type' => 'string', 'description' => 'Optional ISO 8601 upper date bound.'),
                        'limit' => array('type' => 'integer', 'description' => 'Maximum submissions to return (1-50).'),
                        'offset' => array('type' => 'integer', 'description' => 'Number of submissions to skip.'),
                    ),
                    'required' => array(),
                ),
            );
        }

        return $tools;
    }

    public static function getCapabilities() {
        return array(
            'cf7_list_forms' => 'manage_options',
            'cf7_get_form' => 'manage_options',
            'flamingo_get_submissions' => 'manage_options',
        );
    }

    public static function dispatch( $tool, $args, &$r, $addResultText, $utils ) {
        if ( 0 !== strpos( (string) $tool, 'cf7_' ) && 0 !== strpos( (string) $tool, 'flamingo_' ) ) {
            return null;
        }

        if ( ! class_exists( 'WPCF7_ContactForm' ) || ! post_type_exists( 'wpcf7_contact_form' ) ) {
            $r['error'] = array('code' => -32603, 'message' => 'Contact Form 7 plugin is not active.');
            return true;
        }

        $args = is_array( $args ) ? $args : array();

        switch ( $tool ) {
            case 'cf7_list_forms':
                $limit = min( 100, max( 1, intval( $utils::getArrayValue( $args, 'limit', 50 ) ) ) );
                $offset = max( 0, intval( $utils::getArrayValue( $args, 'offset', 0 ) ) );
                $query = new WP_Query( array(
                    'post_type' => 'wpcf7_contact_form',
                    'post_status' => array('publish', 'draft', 'private'),
                    'posts_per_page' => $limit,
                    'offset' => $offset,
                    'orderby' => 'ID',
                    'order' => 'DESC',
                    's' => sanitize_text_field( $utils::getArrayValue( $args, 'search', '' ) ),
                ) );
                $forms = array();
                foreach ( $query->posts as $post ) {
                    $form = WPCF7_ContactForm::get_instance( $post->ID );
                    if ( ! $form ) {
                        continue;
                    }
                    $forms[] = array(
                        'form_id' => (int) $post->ID,
                        'title' => get_the_title( $post ),
                        'status' => $post->post_status,
                        'date_created' => self::post_date_iso( $post->post_date_gmt ),
                        'date_modified' => self::post_date_iso( $post->post_modified_gmt ),
                        'field_count' => count( self::normalize_tags( $form ) ),
                    );
                }
                $payload = array(
                    'forms' => $forms,
                    'total' => (int) $query->found_posts,
                    'limit' => $limit,
                    'offset' => $offset,
                    'has_more' => ( $offset + $limit ) < (int) $query->found_posts,
                );
                self::set_result( $r, $payload );
                return true;

            case 'cf7_get_form':
                $formId = intval( $utils::getArrayValue( $args, 'form_id', 0 ) );
                $post = $formId ? get_post( $formId ) : null;
                $form = $post && 'wpcf7_contact_form' === $post->post_type
                    ? WPCF7_ContactForm::get_instance( $formId )
                    : null;
                if ( ! $form ) {
                    $r['error'] = array('code' => -32602, 'message' => 'Contact Form 7 form not found.');
                    return true;
                }
                $fields = self::normalize_tags( $form );
                self::set_result( $r, array(
                    'form_id' => $formId,
                    'title' => get_the_title( $post ),
                    'status' => $post->post_status,
                    'date_created' => self::post_date_iso( $post->post_date_gmt ),
                    'date_modified' => self::post_date_iso( $post->post_modified_gmt ),
                    'fields' => $fields,
                    'field_count' => count( $fields ),
                ) );
                return true;

            case 'flamingo_get_submissions':
                if ( ! post_type_exists( 'flamingo_inbound' ) ) {
                    $r['error'] = array('code' => -32603, 'message' => 'Flamingo submission storage is not available.');
                    return true;
                }
                $limit = min( 50, max( 1, intval( $utils::getArrayValue( $args, 'limit', 20 ) ) ) );
                $offset = max( 0, intval( $utils::getArrayValue( $args, 'offset', 0 ) ) );
                $status = sanitize_key( $utils::getArrayValue( $args, 'status', 'any' ) );
                $queryArgs = array(
                    'post_type' => 'flamingo_inbound',
                    'post_status' => $status ?: 'any',
                    'posts_per_page' => $limit,
                    'offset' => $offset,
                    'orderby' => 'ID',
                    'order' => 'DESC',
                    's' => sanitize_text_field( $utils::getArrayValue( $args, 'search', '' ) ),
                );
                $dateQuery = array();
                $after = sanitize_text_field( $utils::getArrayValue( $args, 'after', '' ) );
                $before = sanitize_text_field( $utils::getArrayValue( $args, 'before', '' ) );
                if ( '' !== $after ) {
                    $dateQuery['after'] = $after;
                }
                if ( '' !== $before ) {
                    $dateQuery['before'] = $before;
                }
                if ( ! empty( $dateQuery ) ) {
                    $dateQuery['inclusive'] = true;
                    $queryArgs['date_query'] = array( $dateQuery );
                }

                $query = new WP_Query( $queryArgs );
                $submissions = array();
                foreach ( $query->posts as $post ) {
                    $fields = get_post_meta( $post->ID, '_fields', true );
                    $fields = is_array( $fields ) ? $fields : array();
                    $fields = self::redact_pii( StifliFlexMcpUtils::redactSecrets( $fields ) );
                    $submissions[] = array(
                        'submission_id' => (int) $post->ID,
                        'status' => $post->post_status,
                        'subject' => sanitize_text_field( get_post_meta( $post->ID, '_subject', true ) ),
                        'received_at' => self::post_date_iso( $post->post_date_gmt ),
                        'fields' => $fields,
                    );
                }
                self::set_result( $r, array(
                    'submissions' => $submissions,
                    'total' => (int) $query->found_posts,
                    'limit' => $limit,
                    'offset' => $offset,
                    'has_more' => ( $offset + $limit ) < (int) $query->found_posts,
                    'redacted_fields' => array('email', 'phone', 'ip', 'secrets'),
                ) );
                return true;
        }

        return null;
    }

    private static function normalize_tags( $form ) {
        if ( ! is_object( $form ) || ! method_exists( $form, 'scan_form_tags' ) ) {
            return array();
        }

        $fields = array();
        foreach ( (array) $form->scan_form_tags() as $tag ) {
            if ( ! is_object( $tag ) ) {
                continue;
            }
            $rawType = isset( $tag->type ) ? (string) $tag->type : '';
            $baseType = isset( $tag->basetype ) ? sanitize_key( (string) $tag->basetype ) : sanitize_key( rtrim( $rawType, '*' ) );
            $name = isset( $tag->name ) ? sanitize_key( (string) $tag->name ) : '';
            if ( '' === $name ) {
                continue;
            }
            $options = isset( $tag->options ) && is_array( $tag->options )
                ? array_values( array_map( 'sanitize_text_field', $tag->options ) )
                : array();
            $values = isset( $tag->values ) && is_array( $tag->values )
                ? array_values( array_map( 'sanitize_text_field', $tag->values ) )
                : array();
            $choiceTypes = array('select', 'checkbox', 'radio');
            $choices = in_array( $baseType, $choiceTypes, true ) ? $values : array();
            $defaults = in_array( $baseType, $choiceTypes, true ) ? array() : $values;
            if ( ! empty( $choices ) && method_exists( $tag, 'get_option' ) ) {
                foreach ( (array) $tag->get_option( 'default:', 'int', true ) as $defaultIndex ) {
                    $defaultIndex = intval( $defaultIndex ) - 1;
                    if ( isset( $choices[ $defaultIndex ] ) ) {
                        $defaults[] = $choices[ $defaultIndex ];
                    }
                }
            }
            $fields[] = array(
                'name' => $name,
                'type' => $baseType,
                'required' => method_exists( $tag, 'is_required' ) ? (bool) $tag->is_required() : '*' === substr( $rawType, -1 ),
                'options' => $options,
                'choices' => $choices,
                'default_values' => $defaults,
            );
        }
        return $fields;
    }

    private static function redact_pii( $value, $key = '' ) {
        if ( preg_match( '/(?:^|[_-])(email|e-mail|phone|telephone|tel|mobile|ip|remote-addr|remote_addr)(?:$|[_-])/i', (string) $key ) ) {
            return '[REDACTED]';
        }
        if ( ! is_array( $value ) ) {
            return $value;
        }
        $redacted = array();
        foreach ( $value as $childKey => $childValue ) {
            $redacted[ $childKey ] = self::redact_pii( $childValue, (string) $childKey );
        }
        return $redacted;
    }

    private static function post_date_iso( $gmtDate ) {
        $timestamp = is_string( $gmtDate ) && '' !== $gmtDate && '0000-00-00 00:00:00' !== $gmtDate
            ? strtotime( $gmtDate . ' UTC' )
            : false;
        return false === $timestamp ? null : gmdate( 'c', $timestamp );
    }

    private static function set_result( &$r, $payload ) {
        $r['result'] = array(
            'content' => array(array(
                'type' => 'text',
                'text' => wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ),
            )),
            'structuredContent' => $payload,
        );
    }
}
