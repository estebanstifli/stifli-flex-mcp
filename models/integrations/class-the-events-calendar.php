<?php
/**
 * The Events Calendar MCP integration.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class StifliFlexMcp_TheEventsCalendar {

    const EVENT_POST_TYPE     = 'tribe_events';
    const VENUE_POST_TYPE     = 'tribe_venue';
    const ORGANIZER_POST_TYPE = 'tribe_organizer';

    public static function getTools() {
        return array(
            'wp_tec_list_events' => array(
                'name' => 'wp_tec_list_events',
                'description' => 'List The Events Calendar events with filters for date, status, venue, organizer, categories and tags.',
                'inputSchema' => array(
                    'type' => 'object',
                    'properties' => array(
                        'search'       => array( 'type' => 'string' ),
                        'start_date'   => array( 'type' => 'string' ),
                        'end_date'     => array( 'type' => 'string' ),
                        'status'       => array( 'type' => 'string' ),
                        'venue_id'     => array( 'type' => 'integer' ),
                        'organizer_id' => array( 'type' => 'integer' ),
                        'category_ids' => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ) ),
                        'tag_ids'      => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ) ),
                        'featured'     => array( 'type' => 'boolean' ),
                        'limit'        => array( 'type' => 'integer' ),
                        'page'         => array( 'type' => 'integer' ),
                    ),
                    'required' => array(),
                ),
            ),
            'wp_tec_get_event' => array(
                'name' => 'wp_tec_get_event',
                'description' => 'Get full details for a single The Events Calendar event.',
                'inputSchema' => array(
                    'type' => 'object',
                    'properties' => array(
                        'id' => array( 'type' => 'integer' ),
                    ),
                    'required' => array( 'id' ),
                ),
            ),
            'wp_tec_save_event' => array(
                'name' => 'wp_tec_save_event',
                'description' => 'Create or update a The Events Calendar event. Uses undo snapshots before AI changes.',
                'inputSchema' => array(
                    'type' => 'object',
                    'properties' => array(
                        'id'            => array( 'type' => 'integer' ),
                        'title'         => array( 'type' => 'string' ),
                        'description'   => array( 'type' => 'string' ),
                        'start_date'    => array( 'type' => 'string' ),
                        'end_date'      => array( 'type' => 'string' ),
                        'all_day'       => array( 'type' => 'boolean' ),
                        'venue_id'      => array( 'type' => 'integer' ),
                        'organizer_ids' => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ) ),
                        'cost'          => array( 'type' => 'string' ),
                        'url'           => array( 'type' => 'string' ),
                        'status'        => array( 'type' => 'string' ),
                        'featured'      => array( 'type' => 'boolean' ),
                        'category_ids'  => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ) ),
                        'tag_ids'       => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ) ),
                    ),
                    'required' => array(),
                ),
            ),
            'wp_tec_list_entities' => array(
                'name' => 'wp_tec_list_entities',
                'description' => 'List or get The Events Calendar venues and organizers.',
                'inputSchema' => array(
                    'type' => 'object',
                    'properties' => array(
                        'type'   => array( 'type' => 'string' ),
                        'id'     => array( 'type' => 'integer' ),
                        'search' => array( 'type' => 'string' ),
                        'limit'  => array( 'type' => 'integer' ),
                        'page'   => array( 'type' => 'integer' ),
                    ),
                    'required' => array( 'type' ),
                ),
            ),
            'wp_tec_save_entity' => array(
                'name' => 'wp_tec_save_entity',
                'description' => 'Create or update a The Events Calendar venue or organizer. Uses undo snapshots before AI changes.',
                'inputSchema' => array(
                    'type' => 'object',
                    'properties' => array(
                        'type'        => array( 'type' => 'string' ),
                        'id'          => array( 'type' => 'integer' ),
                        'name'        => array( 'type' => 'string' ),
                        'title'       => array( 'type' => 'string' ),
                        'description' => array( 'type' => 'string' ),
                        'address'     => array( 'type' => 'string' ),
                        'city'        => array( 'type' => 'string' ),
                        'country'     => array( 'type' => 'string' ),
                        'state'       => array( 'type' => 'string' ),
                        'province'    => array( 'type' => 'string' ),
                        'zip'         => array( 'type' => 'string' ),
                        'phone'       => array( 'type' => 'string' ),
                        'website'     => array( 'type' => 'string' ),
                        'email'       => array( 'type' => 'string' ),
                        'latitude'    => array( 'type' => 'number' ),
                        'longitude'   => array( 'type' => 'number' ),
                        'status'      => array( 'type' => 'string' ),
                    ),
                    'required' => array( 'type' ),
                ),
            ),
            'wp_tec_trash_event' => array(
                'name' => 'wp_tec_trash_event',
                'description' => 'Safely move a The Events Calendar event to trash with undo support.',
                'inputSchema' => array(
                    'type' => 'object',
                    'properties' => array(
                        'id'    => array( 'type' => 'integer' ),
                        'force' => array( 'type' => 'boolean' ),
                    ),
                    'required' => array( 'id' ),
                ),
            ),
        );
    }

    public static function getCapabilities() {
        return array(
            'wp_tec_save_event'  => 'edit_posts',
            'wp_tec_save_entity' => 'edit_posts',
            'wp_tec_trash_event' => 'delete_posts',
        );
    }

    public static function getChangeTrackerSnapshot( $tool, $args ) {
        if ( ! is_array( $args ) ) {
            $args = array();
        }

        if ( 'wp_tec_save_event' === $tool ) {
            $object_id = self::sanitize_positive_int( self::array_value( $args, 'id', 0 ) );
            return self::build_post_snapshot( $object_id ? 'update' : 'create', self::EVENT_POST_TYPE, $object_id );
        }

        if ( 'wp_tec_save_entity' === $tool ) {
            $type      = sanitize_key( (string) self::array_value( $args, 'type', '' ) );
            $post_type = 'organizer' === $type ? self::ORGANIZER_POST_TYPE : self::VENUE_POST_TYPE;
            $object_id = self::sanitize_positive_int( self::array_value( $args, 'id', 0 ) );
            return self::build_post_snapshot( $object_id ? 'update' : 'create', $post_type, $object_id );
        }

        if ( 'wp_tec_trash_event' === $tool ) {
            $object_id = self::sanitize_positive_int( self::array_value( $args, 'id', 0 ) );
            $operation = self::is_truthy( self::array_value( $args, 'force', false ) ) ? 'delete' : 'update';
            return self::build_post_snapshot( $operation, self::EVENT_POST_TYPE, $object_id );
        }

        return null;
    }

    public static function dispatch( $tool, $args, &$r, $addResultText, $utils ) {
        if ( 0 !== strpos( (string) $tool, 'wp_tec_' ) ) {
            return null;
        }

        if ( ! self::tec_is_available() ) {
            $r['error'] = array(
                'code' => -32603,
                'message' => self::get_availability_error(),
            );
            return true;
        }

        $args = is_array( $args ) ? $args : array();

        switch ( $tool ) {
            case 'wp_tec_list_events':
                $status = sanitize_key( (string) self::array_value( $args, 'status', '' ) );
                if ( '' !== $status && ! in_array( $status, array( 'publish', 'draft', 'pending', 'future', 'any' ), true ) ) {
                    $r['error'] = array( 'code' => -32602, 'message' => 'Invalid status.' );
                    return true;
                }

                $request_args = array(
                    'page' => max( 1, self::sanitize_positive_int( self::array_value( $args, 'page', 1 ) ) ),
                    'per_page' => self::sanitize_limit( self::array_value( $args, 'limit', 20 ), 20 ),
                );

                $search = trim( (string) self::array_value( $args, 'search', '' ) );
                if ( '' !== $search ) {
                    $request_args['search'] = sanitize_text_field( $search );
                }

                $start_date = self::sanitize_date_value( self::array_value( $args, 'start_date', '' ) );
                if ( '' !== $start_date ) {
                    $request_args['start_date'] = $start_date;
                }

                $end_date = self::sanitize_date_value( self::array_value( $args, 'end_date', '' ) );
                if ( '' !== $end_date ) {
                    $request_args['end_date'] = $end_date;
                }

                if ( '' !== $status && 'any' !== $status ) {
                    $request_args['status'] = $status;
                }

                $venue_id = self::sanitize_positive_int( self::array_value( $args, 'venue_id', 0 ) );
                if ( $venue_id > 0 ) {
                    $request_args['venue'] = array( $venue_id );
                }

                $organizer_id = self::sanitize_positive_int( self::array_value( $args, 'organizer_id', 0 ) );
                if ( $organizer_id > 0 ) {
                    $request_args['organizer'] = array( $organizer_id );
                }

                $category_ids = self::sanitize_positive_int_list( self::array_value( $args, 'category_ids', array() ) );
                if ( ! empty( $category_ids ) ) {
                    $request_args['categories'] = $category_ids;
                }

                $tag_ids = self::sanitize_positive_int_list( self::array_value( $args, 'tag_ids', array() ) );
                if ( ! empty( $tag_ids ) ) {
                    $request_args['tags'] = $tag_ids;
                }

                if ( array_key_exists( 'featured', $args ) ) {
                    $request_args['featured'] = self::is_truthy( self::array_value( $args, 'featured', false ) );
                }

                $response = self::tec_rest_request( 'GET', '/tribe/events/v1/events', $request_args );
                if ( is_wp_error( $response ) ) {
                    $r['error'] = self::format_wp_error( $response );
                    return true;
                }

                $events = array();
                $rows   = isset( $response['data']['events'] ) && is_array( $response['data']['events'] ) ? $response['data']['events'] : array();
                foreach ( $rows as $row ) {
                    $events[] = self::normalize_event_response( $row, true );
                }

                self::set_result_payload(
                    $r,
                    array(
                        'success' => true,
                        'data' => array(
                            'events' => $events,
                            'pagination' => array(
                                'page' => (int) $request_args['page'],
                                'per_page' => (int) $request_args['per_page'],
                                'total' => isset( $response['data']['total'] ) ? (int) $response['data']['total'] : count( $events ),
                                'total_pages' => isset( $response['data']['total_pages'] ) ? (int) $response['data']['total_pages'] : 1,
                                'next_rest_url' => isset( $response['data']['next_rest_url'] ) ? esc_url_raw( $response['data']['next_rest_url'] ) : '',
                                'previous_rest_url' => isset( $response['data']['previous_rest_url'] ) ? esc_url_raw( $response['data']['previous_rest_url'] ) : '',
                            ),
                        ),
                    ),
                    $addResultText
                );
                return true;

            case 'wp_tec_get_event':
                $event_id = self::sanitize_positive_int( self::array_value( $args, 'id', 0 ) );
                if ( $event_id <= 0 ) {
                    $r['error'] = array( 'code' => -32602, 'message' => 'Missing required parameter: id' );
                    return true;
                }

                $response = self::tec_rest_request( 'GET', '/tribe/events/v1/events/' . $event_id, array() );
                if ( is_wp_error( $response ) ) {
                    $r['error'] = self::format_wp_error( $response, 'Event not found.' );
                    return true;
                }

                self::set_result_payload(
                    $r,
                    array(
                        'success' => true,
                        'data' => self::normalize_event_response( $response['data'], false ),
                    ),
                    $addResultText
                );
                return true;

            case 'wp_tec_save_event':
                $validated = self::validate_event_input( $args );
                if ( ! $validated['success'] ) {
                    $r['error'] = array( 'code' => -32602, 'message' => $validated['message'] );
                    return true;
                }

                $prepared         = $validated['data'];
                $warnings         = $validated['warnings'];
                $is_update        = ! empty( $prepared['id'] );
                $event_id         = $is_update ? (int) $prepared['id'] : 0;
                $before_snapshot  = self::create_undo_snapshot_before( $tool, $prepared );

                if ( $is_update ) {
                    $existing_event = get_post( $event_id );
                    if ( ! $existing_event || self::EVENT_POST_TYPE !== $existing_event->post_type ) {
                        $r['error'] = array( 'code' => -32603, 'message' => 'Event not found.' );
                        return true;
                    }
                    if ( ! self::current_user_can_manage_existing_post( $event_id, 'edit' ) ) {
                        $r['error'] = array( 'code' => -32603, 'message' => 'Permission denied.' );
                        return true;
                    }
                } else {
                    if ( ! self::current_user_can_create_post_type( self::EVENT_POST_TYPE ) ) {
                        $r['error'] = array( 'code' => -32603, 'message' => 'Permission denied.' );
                        return true;
                    }
                }

                $request_args = array();
                if ( isset( $prepared['title'] ) ) {
                    $request_args['title'] = $prepared['title'];
                }
                if ( isset( $prepared['description'] ) ) {
                    $request_args['description'] = $prepared['description'];
                }
                if ( isset( $prepared['start_date'] ) ) {
                    $request_args['start_date'] = $prepared['start_date'];
                }
                if ( isset( $prepared['end_date'] ) ) {
                    $request_args['end_date'] = $prepared['end_date'];
                }
                if ( array_key_exists( 'all_day', $prepared ) ) {
                    $request_args['all_day'] = (bool) $prepared['all_day'];
                }
                if ( isset( $prepared['cost'] ) ) {
                    $request_args['cost'] = $prepared['cost'];
                }
                if ( isset( $prepared['website'] ) ) {
                    $request_args['website'] = $prepared['website'];
                }
                if ( isset( $prepared['status'] ) ) {
                    $request_args['status'] = $prepared['status'];
                }
                if ( array_key_exists( 'featured', $prepared ) ) {
                    $request_args['featured'] = (bool) $prepared['featured'];
                }
                if ( array_key_exists( 'categories', $prepared ) ) {
                    $request_args['categories'] = $prepared['categories'];
                }
                if ( array_key_exists( 'tags', $prepared ) ) {
                    $request_args['tags'] = $prepared['tags'];
                }

                $route    = $is_update ? '/tribe/events/v1/events/' . $event_id : '/tribe/events/v1/events';
                $response = self::tec_rest_request( 'POST', $route, $request_args );
                if ( is_wp_error( $response ) ) {
                    $r['error'] = self::format_wp_error( $response );
                    return true;
                }

                $saved_event_id = isset( $response['data']['id'] ) ? (int) $response['data']['id'] : $event_id;
                if ( $saved_event_id <= 0 ) {
                    $r['error'] = array( 'code' => -32603, 'message' => 'The event was saved but no event ID was returned.' );
                    return true;
                }

                $sync_result = self::sync_event_relationships( $saved_event_id, $prepared );
                if ( is_wp_error( $sync_result ) ) {
                    $r['error'] = self::format_wp_error( $sync_result, 'The event was saved but linked venue/organizer data could not be synchronized.' );
                    return true;
                }

                $refetched = self::tec_rest_request( 'GET', '/tribe/events/v1/events/' . $saved_event_id, array() );
                $event_data = is_wp_error( $refetched )
                    ? self::normalize_event_response( $response['data'], false )
                    : self::normalize_event_response( $refetched['data'], false );
                $undo_meta  = self::create_undo_snapshot_after( $tool, $before_snapshot, $event_data );

                self::set_result_payload(
                    $r,
                    array(
                        'success' => true,
                        'data' => array_merge(
                            $event_data,
                            array(
                                'event_id' => isset( $event_data['id'] ) ? (int) $event_data['id'] : 0,
                            )
                        ),
                        'warnings' => $warnings,
                        'undo' => $undo_meta,
                    ),
                    $addResultText
                );
                return true;

            case 'wp_tec_list_entities':
                $type = sanitize_key( (string) self::array_value( $args, 'type', '' ) );
                if ( ! in_array( $type, array( 'venue', 'organizer' ), true ) ) {
                    $r['error'] = array( 'code' => -32602, 'message' => 'Invalid type. Expected venue or organizer.' );
                    return true;
                }

                $entity_id = self::sanitize_positive_int( self::array_value( $args, 'id', 0 ) );
                $base_route = 'venue' === $type ? '/tribe/events/v1/venues' : '/tribe/events/v1/organizers';

                if ( $entity_id > 0 ) {
                    $response = self::tec_rest_request( 'GET', $base_route . '/' . $entity_id, array() );
                    if ( is_wp_error( $response ) ) {
                        $r['error'] = self::format_wp_error( $response, 'Entity not found.' );
                        return true;
                    }

                    $entity_data = 'venue' === $type
                        ? self::normalize_venue_response( $response['data'], false )
                        : self::normalize_organizer_response( $response['data'], false );

                    self::set_result_payload(
                        $r,
                        array(
                            'success' => true,
                            'data' => array(
                                'type' => $type,
                                'entity' => $entity_data,
                            ),
                        ),
                        $addResultText
                    );
                    return true;
                }

                $request_args = array(
                    'page' => max( 1, self::sanitize_positive_int( self::array_value( $args, 'page', 1 ) ) ),
                    'per_page' => self::sanitize_limit( self::array_value( $args, 'limit', 20 ), 20 ),
                );

                $search = trim( (string) self::array_value( $args, 'search', '' ) );
                if ( '' !== $search ) {
                    $request_args['search'] = sanitize_text_field( $search );
                }

                $response = self::tec_rest_request( 'GET', $base_route, $request_args );
                if ( is_wp_error( $response ) ) {
                    $r['error'] = self::format_wp_error( $response );
                    return true;
                }

                $items = array();
                $rows  = 'venue' === $type
                    ? ( isset( $response['data']['venues'] ) && is_array( $response['data']['venues'] ) ? $response['data']['venues'] : array() )
                    : ( isset( $response['data']['organizers'] ) && is_array( $response['data']['organizers'] ) ? $response['data']['organizers'] : array() );

                foreach ( $rows as $row ) {
                    $items[] = 'venue' === $type
                        ? self::normalize_venue_response( $row, false )
                        : self::normalize_organizer_response( $row, false );
                }

                self::set_result_payload(
                    $r,
                    array(
                        'success' => true,
                        'data' => array(
                            'type' => $type,
                            'items' => $items,
                            'pagination' => array(
                                'page' => (int) $request_args['page'],
                                'per_page' => (int) $request_args['per_page'],
                                'total' => isset( $response['data']['total'] ) ? (int) $response['data']['total'] : count( $items ),
                                'total_pages' => isset( $response['data']['total_pages'] ) ? (int) $response['data']['total_pages'] : 1,
                            ),
                        ),
                    ),
                    $addResultText
                );
                return true;

            case 'wp_tec_save_entity':
                $validated = self::validate_entity_input( $args );
                if ( ! $validated['success'] ) {
                    $r['error'] = array( 'code' => -32602, 'message' => $validated['message'] );
                    return true;
                }

                $prepared        = $validated['data'];
                $warnings        = $validated['warnings'];
                $entity_type     = $prepared['type'];
                $entity_posttype = 'venue' === $entity_type ? self::VENUE_POST_TYPE : self::ORGANIZER_POST_TYPE;
                $is_update       = ! empty( $prepared['id'] );
                $entity_id       = $is_update ? (int) $prepared['id'] : 0;
                $before_snapshot = self::create_undo_snapshot_before( $tool, $prepared );

                if ( $is_update ) {
                    $existing_entity = get_post( $entity_id );
                    if ( ! $existing_entity || $entity_posttype !== $existing_entity->post_type ) {
                        $r['error'] = array( 'code' => -32603, 'message' => 'Entity not found.' );
                        return true;
                    }
                    if ( ! self::current_user_can_manage_existing_post( $entity_id, 'edit' ) ) {
                        $r['error'] = array( 'code' => -32603, 'message' => 'Permission denied.' );
                        return true;
                    }
                } else {
                    if ( ! self::current_user_can_create_post_type( $entity_posttype ) ) {
                        $r['error'] = array( 'code' => -32603, 'message' => 'Permission denied.' );
                        return true;
                    }
                }

                $request_args = array();
                if ( 'venue' === $entity_type ) {
                    if ( isset( $prepared['name'] ) ) {
                        $request_args['venue'] = $prepared['name'];
                    }
                    if ( isset( $prepared['description'] ) ) {
                        $request_args['description'] = $prepared['description'];
                    }
                    if ( isset( $prepared['status'] ) ) {
                        $request_args['status'] = $prepared['status'];
                    }
                    foreach ( array( 'address', 'city', 'country', 'province', 'state', 'zip', 'phone', 'website' ) as $field_name ) {
                        if ( array_key_exists( $field_name, $prepared ) ) {
                            $request_args[ $field_name ] = $prepared[ $field_name ];
                        }
                    }
                    if ( array_key_exists( 'stateprovince', $prepared ) ) {
                        $request_args['stateprovince'] = $prepared['stateprovince'];
                    }
                } else {
                    if ( isset( $prepared['name'] ) ) {
                        $request_args['organizer'] = $prepared['name'];
                    }
                    if ( isset( $prepared['description'] ) ) {
                        $request_args['description'] = $prepared['description'];
                    }
                    if ( isset( $prepared['status'] ) ) {
                        $request_args['status'] = $prepared['status'];
                    }
                    foreach ( array( 'phone', 'website', 'email' ) as $field_name ) {
                        if ( array_key_exists( $field_name, $prepared ) ) {
                            $request_args[ $field_name ] = $prepared[ $field_name ];
                        }
                    }
                }

                $base_route = 'venue' === $entity_type ? '/tribe/events/v1/venues' : '/tribe/events/v1/organizers';
                $route      = $is_update ? $base_route . '/' . $entity_id : $base_route;
                $response   = self::tec_rest_request( 'POST', $route, $request_args );
                if ( is_wp_error( $response ) ) {
                    $r['error'] = self::format_wp_error( $response );
                    return true;
                }

                $created_id = isset( $response['data']['id'] ) ? (int) $response['data']['id'] : 0;
                if ( 'venue' === $entity_type && $created_id > 0 ) {
                    if ( array_key_exists( 'latitude', $prepared ) ) {
                        update_post_meta( $created_id, '_VenueLat', $prepared['latitude'] );
                        $warnings[] = 'Venue latitude was saved via post meta fallback because the TEC REST create/update schema does not expose it directly.';
                    }
                    if ( array_key_exists( 'longitude', $prepared ) ) {
                        update_post_meta( $created_id, '_VenueLng', $prepared['longitude'] );
                        $warnings[] = 'Venue longitude was saved via post meta fallback because the TEC REST create/update schema does not expose it directly.';
                    }
                    if ( ( array_key_exists( 'latitude', $prepared ) || array_key_exists( 'longitude', $prepared ) ) && ! class_exists( 'Tribe__Events__Pro__Geo_Loc' ) ) {
                        $warnings[] = 'Geo coordinates were stored, but some venue geolocation features may require The Events Calendar Pro.';
                    }
                }

                if ( 'venue' === $entity_type ) {
                    $fresh_response = self::tec_rest_request( 'GET', '/tribe/events/v1/venues/' . $created_id, array() );
                    $entity_data = is_wp_error( $fresh_response ) ? self::normalize_venue_response( $response['data'], false ) : self::normalize_venue_response( $fresh_response['data'], false );
                } else {
                    $fresh_response = self::tec_rest_request( 'GET', '/tribe/events/v1/organizers/' . $created_id, array() );
                    $entity_data = is_wp_error( $fresh_response ) ? self::normalize_organizer_response( $response['data'], false ) : self::normalize_organizer_response( $fresh_response['data'], false );
                }

                $undo_meta = self::create_undo_snapshot_after( $tool, $before_snapshot, $entity_data );

                self::set_result_payload(
                    $r,
                    array(
                        'success' => true,
                        'data' => array(
                            'type' => $entity_type,
                            'entity_id' => isset( $entity_data['id'] ) ? (int) $entity_data['id'] : 0,
                            'entity' => $entity_data,
                        ),
                        'warnings' => $warnings,
                        'undo' => $undo_meta,
                    ),
                    $addResultText
                );
                return true;

            case 'wp_tec_trash_event':
                $event_id = self::sanitize_positive_int( self::array_value( $args, 'id', 0 ) );
                $force    = self::is_truthy( self::array_value( $args, 'force', false ) );

                if ( $event_id <= 0 ) {
                    $r['error'] = array( 'code' => -32602, 'message' => 'Missing required parameter: id' );
                    return true;
                }

                $event = get_post( $event_id );
                if ( ! $event || self::EVENT_POST_TYPE !== $event->post_type ) {
                    $r['error'] = array( 'code' => -32603, 'message' => 'Event not found.' );
                    return true;
                }

                if ( ! self::current_user_can_manage_existing_post( $event_id, 'delete' ) ) {
                    $r['error'] = array( 'code' => -32603, 'message' => 'Permission denied.' );
                    return true;
                }

                $old_status      = $event->post_status;
                $before_snapshot = self::create_undo_snapshot_before( $tool, $args );

                if ( $force ) {
                    $deleted = wp_delete_post( $event_id, true );
                    if ( ! $deleted ) {
                        $r['error'] = array( 'code' => -32603, 'message' => 'Could not permanently delete the event.' );
                        return true;
                    }

                    $payload = array(
                        'success' => true,
                        'data' => array(
                            'id' => $event_id,
                            'event_id' => $event_id,
                            'old_status' => $old_status,
                            'new_status' => 'deleted',
                            'message' => 'Event permanently deleted.',
                        ),
                        'undo' => self::create_undo_snapshot_after( $tool, $before_snapshot, array( 'id' => $event_id ) ),
                    );
                    self::set_result_payload( $r, $payload, $addResultText );
                    return true;
                }

                $response = self::tec_rest_request( 'DELETE', '/tribe/events/v1/events/' . $event_id, array() );
                if ( is_wp_error( $response ) ) {
                    $r['error'] = self::format_wp_error( $response );
                    return true;
                }

                $fresh_event = get_post( $event_id );
                $payload = array(
                    'success' => true,
                    'data' => array(
                        'id' => $event_id,
                        'event_id' => $event_id,
                        'old_status' => $old_status,
                        'new_status' => $fresh_event ? $fresh_event->post_status : 'trash',
                        'message' => 'Event moved to trash.',
                    ),
                    'undo' => self::create_undo_snapshot_after( $tool, $before_snapshot, array( 'id' => $event_id ) ),
                );
                self::set_result_payload( $r, $payload, $addResultText );
                return true;
        }

        return null;
    }

    private static function tec_is_available() {
        if ( ! class_exists( 'Tribe__Events__Main' ) ) {
            return false;
        }

        if ( ! post_type_exists( self::EVENT_POST_TYPE ) || ! post_type_exists( self::VENUE_POST_TYPE ) || ! post_type_exists( self::ORGANIZER_POST_TYPE ) ) {
            return false;
        }

        return self::tec_rest_route_exists( '/tribe/events/v1/events' )
            && self::tec_rest_route_exists( '/tribe/events/v1/venues' )
            && self::tec_rest_route_exists( '/tribe/events/v1/organizers' );
    }

    private static function tec_rest_request( $method, $route, $params ) {
        $request = new WP_REST_Request( strtoupper( (string) $method ), $route );
        $params  = is_array( $params ) ? $params : array();

        if ( 'GET' === $request->get_method() || 'DELETE' === $request->get_method() ) {
            foreach ( $params as $key => $value ) {
                $request->set_param( $key, $value );
            }
        } else {
            $request->set_body_params( $params );
            foreach ( $params as $key => $value ) {
                $request->set_param( $key, $value );
            }
            $request->set_header( 'content-type', 'application/x-www-form-urlencoded; charset=' . get_option( 'blog_charset' ) );
        }

        $response = rest_do_request( $request );
        if ( is_wp_error( $response ) ) {
            return $response;
        }

        if ( method_exists( $response, 'is_error' ) && $response->is_error() ) {
            return $response->as_error();
        }

        $status = method_exists( $response, 'get_status' ) ? (int) $response->get_status() : 200;
        $data   = method_exists( $response, 'get_data' ) ? $response->get_data() : array();

        if ( $status >= 400 ) {
            $message = self::extract_error_message_from_data( $data );
            return new WP_Error( 'tec_rest_error', $message ? $message : 'The Events Calendar REST API request failed.', array( 'status' => $status ) );
        }

        return array(
            'status' => $status,
            'data' => $data,
            'headers' => method_exists( $response, 'get_headers' ) ? $response->get_headers() : array(),
        );
    }

    private static function normalize_event_response( $event, $summary = false ) {
        $event = is_array( $event ) ? $event : array();

        $venue = self::normalize_venue_response( self::array_value( $event, 'venue', array() ), true );
        $organizers = array();
        $raw_organizers = self::array_value( $event, 'organizer', array() );
        if ( is_array( $raw_organizers ) ) {
            $is_list = isset( $raw_organizers[0] ) || empty( $raw_organizers );
            if ( $is_list ) {
                foreach ( $raw_organizers as $raw_organizer ) {
                    if ( is_array( $raw_organizer ) ) {
                        $organizers[] = self::normalize_organizer_response( $raw_organizer, true );
                    }
                }
            } elseif ( ! empty( $raw_organizers ) ) {
                $organizers[] = self::normalize_organizer_response( $raw_organizers, true );
            }
        }

        $data = array(
            'id' => (int) self::array_value( $event, 'id', 0 ),
            'title' => sanitize_text_field( (string) self::array_value( $event, 'title', '' ) ),
            'status' => sanitize_key( (string) self::array_value( $event, 'status', '' ) ),
            'start_date' => (string) self::array_value( $event, 'start_date', '' ),
            'end_date' => (string) self::array_value( $event, 'end_date', '' ),
            'all_day' => self::is_truthy( self::array_value( $event, 'all_day', false ) ),
            'venue' => $venue,
            'organizers' => array_values( array_filter( $organizers ) ),
            'permalink' => esc_url_raw( (string) self::array_value( $event, 'url', '' ) ),
            'excerpt' => (string) self::array_value( $event, 'excerpt', '' ),
        );

        if ( ! $summary ) {
            $data['description'] = (string) self::array_value( $event, 'description', '' );
            $data['categories'] = self::normalize_terms( self::array_value( $event, 'categories', array() ) );
            $data['tags'] = self::normalize_terms( self::array_value( $event, 'tags', array() ) );
            $data['cost'] = (string) self::array_value( $event, 'cost', '' );
            $data['url'] = esc_url_raw( (string) self::array_value( $event, 'website', '' ) );
            $data['featured'] = self::is_truthy( self::array_value( $event, 'featured', false ) );
            $data['timezone'] = sanitize_text_field( (string) self::array_value( $event, 'timezone', '' ) );
            $data['image'] = self::array_value( $event, 'image', false );
        }

        return array_filter(
            $data,
            static function( $value ) {
                return null !== $value && '' !== $value && array() !== $value;
            }
        );
    }

    private static function normalize_venue_response( $venue, $summary = false ) {
        $venue = is_array( $venue ) ? $venue : array();
        $data  = array(
            'id' => (int) self::array_value( $venue, 'id', 0 ),
            'name' => sanitize_text_field( (string) self::array_value( $venue, 'venue', self::array_value( $venue, 'title', '' ) ) ),
            'address' => sanitize_text_field( (string) self::array_value( $venue, 'address', '' ) ),
            'city' => sanitize_text_field( (string) self::array_value( $venue, 'city', '' ) ),
            'country' => sanitize_text_field( (string) self::array_value( $venue, 'country', '' ) ),
            'province' => sanitize_text_field( (string) self::array_value( $venue, 'province', '' ) ),
            'state' => sanitize_text_field( (string) self::array_value( $venue, 'state', '' ) ),
            'zip' => sanitize_text_field( (string) self::array_value( $venue, 'zip', '' ) ),
            'phone' => sanitize_text_field( (string) self::array_value( $venue, 'phone', '' ) ),
            'website' => esc_url_raw( (string) self::array_value( $venue, 'website', '' ) ),
            'latitude' => self::sanitize_coordinate( self::array_value( $venue, 'geo_lat', null ) ),
            'longitude' => self::sanitize_coordinate( self::array_value( $venue, 'geo_lng', null ) ),
            'permalink' => esc_url_raw( (string) self::array_value( $venue, 'url', '' ) ),
        );

        if ( ! $summary ) {
            $data['status'] = sanitize_key( (string) self::array_value( $venue, 'status', '' ) );
            $data['description'] = (string) self::array_value( $venue, 'description', '' );
            $data['excerpt'] = (string) self::array_value( $venue, 'excerpt', '' );
            $data['stateprovince'] = sanitize_text_field( (string) self::array_value( $venue, 'stateprovince', '' ) );
        }

        return array_filter(
            $data,
            static function( $value ) {
                return null !== $value && '' !== $value && array() !== $value;
            }
        );
    }

    private static function normalize_organizer_response( $organizer, $summary = false ) {
        $organizer = is_array( $organizer ) ? $organizer : array();
        $data      = array(
            'id' => (int) self::array_value( $organizer, 'id', 0 ),
            'name' => sanitize_text_field( (string) self::array_value( $organizer, 'organizer', self::array_value( $organizer, 'title', '' ) ) ),
            'email' => sanitize_email( (string) self::array_value( $organizer, 'email', '' ) ),
            'phone' => sanitize_text_field( (string) self::array_value( $organizer, 'phone', '' ) ),
            'website' => esc_url_raw( (string) self::array_value( $organizer, 'website', '' ) ),
            'permalink' => esc_url_raw( (string) self::array_value( $organizer, 'url', '' ) ),
        );

        if ( ! $summary ) {
            $data['status'] = sanitize_key( (string) self::array_value( $organizer, 'status', '' ) );
            $data['description'] = (string) self::array_value( $organizer, 'description', '' );
            $data['excerpt'] = (string) self::array_value( $organizer, 'excerpt', '' );
        }

        return array_filter(
            $data,
            static function( $value ) {
                return null !== $value && '' !== $value && array() !== $value;
            }
        );
    }

    private static function validate_event_input( $args ) {
        $warnings  = array();
        $prepared  = array();
        $event_id  = self::sanitize_positive_int( self::array_value( $args, 'id', 0 ) );
        $is_update = $event_id > 0;

        if ( $is_update ) {
            $prepared['id'] = $event_id;
        }

        if ( array_key_exists( 'title', $args ) ) {
            $title = sanitize_text_field( (string) self::array_value( $args, 'title', '' ) );
            if ( '' === $title ) {
                return array( 'success' => false, 'message' => 'Title cannot be empty.', 'data' => array(), 'warnings' => $warnings );
            }
            $prepared['title'] = $title;
        }

        if ( array_key_exists( 'description', $args ) ) {
            $prepared['description'] = wp_kses_post( wp_unslash( (string) self::array_value( $args, 'description', '' ) ) );
        }

        if ( array_key_exists( 'start_date', $args ) ) {
            $start_date = self::sanitize_date_value( self::array_value( $args, 'start_date', '' ) );
            if ( '' === $start_date ) {
                return array( 'success' => false, 'message' => 'Invalid start_date.', 'data' => array(), 'warnings' => $warnings );
            }
            $prepared['start_date'] = $start_date;
        }

        if ( array_key_exists( 'end_date', $args ) ) {
            $end_date = self::sanitize_date_value( self::array_value( $args, 'end_date', '' ) );
            if ( '' === $end_date ) {
                return array( 'success' => false, 'message' => 'Invalid end_date.', 'data' => array(), 'warnings' => $warnings );
            }
            $prepared['end_date'] = $end_date;
        }

        if ( ! empty( $prepared['start_date'] ) && ! empty( $prepared['end_date'] ) && strtotime( $prepared['end_date'] ) < strtotime( $prepared['start_date'] ) ) {
            return array( 'success' => false, 'message' => 'end_date must be greater than or equal to start_date.', 'data' => array(), 'warnings' => $warnings );
        }

        if ( array_key_exists( 'all_day', $args ) ) {
            $prepared['all_day'] = self::is_truthy( self::array_value( $args, 'all_day', false ) );
        }

        if ( array_key_exists( 'venue_id', $args ) ) {
            $venue_id = self::sanitize_positive_int( self::array_value( $args, 'venue_id', 0 ) );
            if ( $venue_id > 0 ) {
                $venue = get_post( $venue_id );
                if ( ! $venue || self::VENUE_POST_TYPE !== $venue->post_type ) {
                    return array( 'success' => false, 'message' => 'Venue not found.', 'data' => array(), 'warnings' => $warnings );
                }
            }
            $prepared['venue_id'] = $venue_id;
        }

        if ( array_key_exists( 'organizer_ids', $args ) ) {
            $organizer_ids = self::sanitize_positive_int_list( self::array_value( $args, 'organizer_ids', array() ) );
            foreach ( $organizer_ids as $organizer_id ) {
                $organizer = get_post( $organizer_id );
                if ( ! $organizer || self::ORGANIZER_POST_TYPE !== $organizer->post_type ) {
                    return array( 'success' => false, 'message' => 'Organizer not found.', 'data' => array(), 'warnings' => $warnings );
                }
            }
            $prepared['organizer_ids'] = $organizer_ids;
        }

        if ( array_key_exists( 'cost', $args ) ) {
            $prepared['cost'] = sanitize_text_field( (string) self::array_value( $args, 'cost', '' ) );
        }

        if ( array_key_exists( 'url', $args ) ) {
            $website = esc_url_raw( (string) self::array_value( $args, 'url', '' ) );
            if ( '' === $website && '' !== trim( (string) self::array_value( $args, 'url', '' ) ) ) {
                return array( 'success' => false, 'message' => 'Invalid url.', 'data' => array(), 'warnings' => $warnings );
            }
            $prepared['website'] = $website;
        }

        if ( array_key_exists( 'status', $args ) ) {
            $status = sanitize_key( (string) self::array_value( $args, 'status', '' ) );
            if ( ! in_array( $status, array( 'draft', 'publish', 'pending' ), true ) ) {
                return array( 'success' => false, 'message' => 'Invalid status.', 'data' => array(), 'warnings' => $warnings );
            }
            $prepared['status'] = $status;
        }

        if ( array_key_exists( 'featured', $args ) ) {
            $prepared['featured'] = self::is_truthy( self::array_value( $args, 'featured', false ) );
        }

        if ( array_key_exists( 'category_ids', $args ) ) {
            $prepared['categories'] = self::sanitize_positive_int_list( self::array_value( $args, 'category_ids', array() ) );
        }

        if ( array_key_exists( 'tag_ids', $args ) ) {
            $prepared['tags'] = self::sanitize_positive_int_list( self::array_value( $args, 'tag_ids', array() ) );
        }

        if ( ! $is_update ) {
            foreach ( array( 'title', 'start_date', 'end_date' ) as $required_key ) {
                if ( empty( $prepared[ $required_key ] ) ) {
                    return array( 'success' => false, 'message' => 'Missing required fields for create: title, start_date and end_date are required.', 'data' => array(), 'warnings' => $warnings );
                }
            }
        }

        return array(
            'success' => true,
            'message' => '',
            'data' => $prepared,
            'warnings' => $warnings,
        );
    }

    private static function validate_entity_input( $args ) {
        $warnings  = array();
        $prepared  = array();
        $type      = sanitize_key( (string) self::array_value( $args, 'type', '' ) );
        $entity_id = self::sanitize_positive_int( self::array_value( $args, 'id', 0 ) );
        $is_update = $entity_id > 0;

        if ( ! in_array( $type, array( 'venue', 'organizer' ), true ) ) {
            return array( 'success' => false, 'message' => 'Invalid type. Expected venue or organizer.', 'data' => array(), 'warnings' => $warnings );
        }

        $prepared['type'] = $type;
        if ( $is_update ) {
            $prepared['id'] = $entity_id;
        }

        if ( array_key_exists( 'name', $args ) || array_key_exists( 'title', $args ) ) {
            $name = sanitize_text_field( (string) self::array_value( $args, 'name', self::array_value( $args, 'title', '' ) ) );
            if ( '' === $name ) {
                return array( 'success' => false, 'message' => 'Name cannot be empty.', 'data' => array(), 'warnings' => $warnings );
            }
            $prepared['name'] = $name;
        }

        if ( ! $is_update && empty( $prepared['name'] ) ) {
            return array( 'success' => false, 'message' => 'Missing required field: name.', 'data' => array(), 'warnings' => $warnings );
        }

        if ( array_key_exists( 'description', $args ) ) {
            $prepared['description'] = wp_kses_post( wp_unslash( (string) self::array_value( $args, 'description', '' ) ) );
        }

        foreach ( array( 'address', 'city', 'country', 'state', 'province', 'zip', 'phone' ) as $field_name ) {
            if ( array_key_exists( $field_name, $args ) ) {
                $prepared[ $field_name ] = sanitize_text_field( (string) self::array_value( $args, $field_name, '' ) );
            }
        }

        if ( array_key_exists( 'website', $args ) ) {
            $website = esc_url_raw( (string) self::array_value( $args, 'website', '' ) );
            if ( '' === $website && '' !== trim( (string) self::array_value( $args, 'website', '' ) ) ) {
                return array( 'success' => false, 'message' => 'Invalid website.', 'data' => array(), 'warnings' => $warnings );
            }
            $prepared['website'] = $website;
        }

        if ( array_key_exists( 'status', $args ) ) {
            $status = sanitize_key( (string) self::array_value( $args, 'status', '' ) );
            if ( ! in_array( $status, array( 'draft', 'publish', 'pending' ), true ) ) {
                return array( 'success' => false, 'message' => 'Invalid status.', 'data' => array(), 'warnings' => $warnings );
            }
            $prepared['status'] = $status;
        }

        if ( 'venue' === $type ) {
            if ( ! empty( $prepared['province'] ) && ! empty( $prepared['state'] ) ) {
                $prepared['stateprovince'] = sanitize_text_field( $prepared['province'] . ', ' . $prepared['state'] );
            } elseif ( ! empty( $prepared['province'] ) ) {
                $prepared['stateprovince'] = $prepared['province'];
            } elseif ( ! empty( $prepared['state'] ) ) {
                $prepared['stateprovince'] = $prepared['state'];
            }

            if ( array_key_exists( 'latitude', $args ) ) {
                $latitude = self::sanitize_coordinate( self::array_value( $args, 'latitude', null ) );
                if ( null === $latitude ) {
                    return array( 'success' => false, 'message' => 'Invalid latitude.', 'data' => array(), 'warnings' => $warnings );
                }
                $prepared['latitude'] = $latitude;
            }

            if ( array_key_exists( 'longitude', $args ) ) {
                $longitude = self::sanitize_coordinate( self::array_value( $args, 'longitude', null ) );
                if ( null === $longitude ) {
                    return array( 'success' => false, 'message' => 'Invalid longitude.', 'data' => array(), 'warnings' => $warnings );
                }
                $prepared['longitude'] = $longitude;
            }

            if ( array_key_exists( 'email', $args ) && '' !== trim( (string) self::array_value( $args, 'email', '' ) ) ) {
                $warnings[] = 'email is ignored for venues.';
            }
        } else {
            if ( array_key_exists( 'email', $args ) ) {
                $email = sanitize_email( (string) self::array_value( $args, 'email', '' ) );
                if ( '' !== $email && ! is_email( $email ) ) {
                    return array( 'success' => false, 'message' => 'Invalid email.', 'data' => array(), 'warnings' => $warnings );
                }
                $prepared['email'] = $email;
            }

            if ( array_key_exists( 'address', $args ) || array_key_exists( 'city', $args ) || array_key_exists( 'country', $args ) || array_key_exists( 'state', $args ) || array_key_exists( 'province', $args ) || array_key_exists( 'zip', $args ) ) {
                $warnings[] = 'Address fields are ignored for organizers.';
            }
            if ( array_key_exists( 'latitude', $args ) || array_key_exists( 'longitude', $args ) ) {
                $warnings[] = 'Coordinates are ignored for organizers.';
            }
        }

        return array(
            'success' => true,
            'message' => '',
            'data' => $prepared,
            'warnings' => $warnings,
        );
    }

    private static function create_undo_snapshot_before( $tool, $args ) {
        if ( ! class_exists( 'StifliFlexMcp_ChangeTracker' ) || ! get_option( 'sflmcp_changelog_enabled', true ) ) {
            return null;
        }

        return self::getChangeTrackerSnapshot( $tool, $args );
    }

    private static function create_undo_snapshot_after( $tool, $snapshot, $data ) {
        if ( empty( $snapshot ) || ! is_array( $snapshot ) ) {
            return null;
        }

        $object_id = 0;
        if ( is_array( $data ) ) {
            if ( isset( $data['id'] ) ) {
                $object_id = (int) $data['id'];
            } elseif ( isset( $data['entity']['id'] ) ) {
                $object_id = (int) $data['entity']['id'];
            }
        }

        return array_filter(
            array(
                'supported' => true,
                'tool' => $tool,
                'operation' => isset( $snapshot['operation_type'] ) ? $snapshot['operation_type'] : '',
                'object_type' => isset( $snapshot['object_type'] ) ? $snapshot['object_type'] : '',
                'object_subtype' => isset( $snapshot['object_subtype'] ) ? $snapshot['object_subtype'] : '',
                'object_id' => $object_id > 0 ? $object_id : null,
            )
        );
    }

    private static function sync_event_relationships( $event_id, $prepared ) {
        $event_id = absint( $event_id );
        if ( $event_id <= 0 ) {
            return new WP_Error( 'invalid-event-id', 'Invalid event ID.' );
        }

        if ( class_exists( 'Tribe__Events__Linked_Posts' ) ) {
            $linked_posts = Tribe__Events__Linked_Posts::instance();

            if ( array_key_exists( 'venue_id', $prepared ) ) {
                $new_venue_ids = ! empty( $prepared['venue_id'] ) ? array( absint( $prepared['venue_id'] ) ) : array();
                $old_venue_ids = $linked_posts->get_linked_post_ids_by_post_type( $event_id, self::VENUE_POST_TYPE );
                $linked_posts->maybe_reorder_linked_posts_ids( $event_id, self::VENUE_POST_TYPE, $new_venue_ids, $old_venue_ids );
            }

            if ( array_key_exists( 'organizer_ids', $prepared ) ) {
                $new_organizer_ids = self::sanitize_positive_int_list( self::array_value( $prepared, 'organizer_ids', array() ) );
                $old_organizer_ids = $linked_posts->get_linked_post_ids_by_post_type( $event_id, self::ORGANIZER_POST_TYPE );
                $linked_posts->maybe_reorder_linked_posts_ids( $event_id, self::ORGANIZER_POST_TYPE, $new_organizer_ids, $old_organizer_ids );
            }

            clean_post_cache( $event_id );
            return true;
        }

        if ( array_key_exists( 'venue_id', $prepared ) ) {
            delete_post_meta( $event_id, '_EventVenueID' );
            if ( ! empty( $prepared['venue_id'] ) ) {
                add_post_meta( $event_id, '_EventVenueID', absint( $prepared['venue_id'] ) );
            }
        }

        if ( array_key_exists( 'organizer_ids', $prepared ) ) {
            delete_post_meta( $event_id, '_EventOrganizerID' );
            foreach ( self::sanitize_positive_int_list( self::array_value( $prepared, 'organizer_ids', array() ) ) as $organizer_id ) {
                add_post_meta( $event_id, '_EventOrganizerID', $organizer_id );
            }
        }

        clean_post_cache( $event_id );

        return true;
    }

    private static function set_result_payload( &$r, $payload, $addResultText ) {
        $payload = is_array( $payload ) ? $payload : array();

        if ( isset( $payload['warnings'] ) && empty( $payload['warnings'] ) ) {
            unset( $payload['warnings'] );
        }
        if ( isset( $payload['undo'] ) && empty( $payload['undo'] ) ) {
            unset( $payload['undo'] );
        }

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

    private static function format_wp_error( $error, $fallback_message = '' ) {
        $message = $fallback_message;
        if ( $error instanceof WP_Error ) {
            $message = $error->get_error_message();
        }
        if ( '' === $message ) {
            $message = 'The Events Calendar request failed.';
        }
        return array( 'code' => -32603, 'message' => $message );
    }

    private static function normalize_terms( $terms ) {
        $terms = is_array( $terms ) ? $terms : array();
        $rows  = array();

        foreach ( $terms as $term ) {
            if ( ! is_array( $term ) ) {
                continue;
            }
            $rows[] = array_filter(
                array(
                    'id' => (int) self::array_value( $term, 'id', self::array_value( $term, 'term_id', 0 ) ),
                    'name' => sanitize_text_field( (string) self::array_value( $term, 'name', '' ) ),
                    'slug' => sanitize_key( (string) self::array_value( $term, 'slug', '' ) ),
                    'url' => esc_url_raw( (string) self::array_value( $term, 'url', self::array_value( $term, 'link', '' ) ) ),
                )
            );
        }

        return $rows;
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
            if ( $post && isset( $post['post_type'] ) && $post_type === $post['post_type'] ) {
                $snapshot['before_state'] = $post;
                $snapshot['before_state']['meta'] = get_post_meta( $object_id );
            }
        }

        return $snapshot;
    }

    private static function current_user_can_create_post_type( $post_type ) {
        $post_type_object = get_post_type_object( $post_type );
        if ( ! $post_type_object || empty( $post_type_object->cap ) ) {
            return current_user_can( 'edit_posts' );
        }

        if ( isset( $post_type_object->cap->edit_posts ) ) {
            return current_user_can( $post_type_object->cap->edit_posts );
        }

        return current_user_can( 'edit_posts' );
    }

    private static function current_user_can_manage_existing_post( $post_id, $action ) {
        $post = get_post( $post_id );
        if ( ! $post ) {
            return false;
        }

        $post_type_object = get_post_type_object( $post->post_type );
        if ( ! $post_type_object || empty( $post_type_object->cap ) ) {
            return current_user_can( 'edit_posts' );
        }

        if ( 'delete' === $action && isset( $post_type_object->cap->delete_post ) ) {
            return current_user_can( $post_type_object->cap->delete_post, $post_id );
        }

        if ( isset( $post_type_object->cap->edit_post ) ) {
            return current_user_can( $post_type_object->cap->edit_post, $post_id );
        }

        return current_user_can( 'edit_posts' );
    }

    private static function sanitize_limit( $value, $default ) {
        $value = self::sanitize_positive_int( $value );
        if ( $value <= 0 ) {
            $value = (int) $default;
        }
        return max( 1, min( 50, $value ) );
    }

    private static function sanitize_positive_int( $value ) {
        return max( 0, absint( $value ) );
    }

    private static function sanitize_positive_int_list( $value ) {
        if ( is_string( $value ) ) {
            $value = explode( ',', $value );
        }
        if ( ! is_array( $value ) ) {
            $value = array();
        }

        $clean = array();
        foreach ( $value as $item ) {
            $item = absint( $item );
            if ( $item > 0 ) {
                $clean[] = $item;
            }
        }

        return array_values( array_unique( $clean ) );
    }

    private static function sanitize_date_value( $value ) {
        $value = trim( (string) $value );
        if ( '' === $value ) {
            return '';
        }

        $timezone = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );
        $date = date_create_immutable( $value, $timezone );
        if ( false === $date ) {
            return '';
        }

        return $date->format( 'Y-m-d H:i:s' );
    }

    private static function sanitize_coordinate( $value ) {
        if ( null === $value ) {
            return null;
        }

        if ( '' === trim( (string) $value ) ) {
            return null;
        }

        if ( ! is_numeric( $value ) ) {
            return null;
        }

        return (string) (float) $value;
    }

    private static function tec_rest_route_exists( $route ) {
        if ( ! function_exists( 'rest_get_server' ) ) {
            return false;
        }

        $routes = rest_get_server()->get_routes();
        return isset( $routes[ $route ] );
    }

    private static function get_availability_error() {
        if ( ! class_exists( 'Tribe__Events__Main' ) ) {
            return 'The Events Calendar plugin is not active.';
        }

        if ( ! post_type_exists( self::EVENT_POST_TYPE ) || ! post_type_exists( self::VENUE_POST_TYPE ) || ! post_type_exists( self::ORGANIZER_POST_TYPE ) ) {
            return 'The Events Calendar plugin is not fully initialized.';
        }

        if ( ! self::tec_rest_route_exists( '/tribe/events/v1/events' ) || ! self::tec_rest_route_exists( '/tribe/events/v1/venues' ) || ! self::tec_rest_route_exists( '/tribe/events/v1/organizers' ) ) {
            return 'The Events Calendar REST API is not available.';
        }

        return 'The Events Calendar plugin is not active.';
    }

    private static function extract_error_message_from_data( $data ) {
        if ( is_array( $data ) ) {
            if ( ! empty( $data['message'] ) && is_string( $data['message'] ) ) {
                return $data['message'];
            }
            if ( ! empty( $data['data']['message'] ) && is_string( $data['data']['message'] ) ) {
                return $data['data']['message'];
            }
        }

        return '';
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