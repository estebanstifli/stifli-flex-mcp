<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class StifliFlexMcp_Search_Image {
	const TOOL_NAME = 'wp_search_image';

	public static function getTools() {
		return array(
			self::TOOL_NAME => array(
				'name' => self::TOOL_NAME,
				'description' => 'Search Unsplash, Pexels, or Pixabay and return one image URL with attribution metadata.',
				'inputSchema' => array(
					'type' => 'object',
					'properties' => array(
						'query' => array(
							'type' => 'string',
							'description' => 'Search term.',
						),
						'provider' => array(
							'type' => 'string',
							'description' => 'Optional: random, unsplash, pexels, or pixabay.',
						),
						'selection' => array(
							'type' => 'string',
							'description' => 'Optional: most_relevant, random_top10, or random_top20.',
						),
						'orientation' => array(
							'type' => 'string',
							'description' => 'Optional: any, landscape, portrait, or square.',
						),
						'page' => array(
							'type' => 'integer',
							'description' => 'Optional page number.',
						),
					),
					'required' => array( 'query' ),
				),
			),
		);
	}

	public static function getCapabilities() {
		return array(
			self::TOOL_NAME => 'upload_files',
		);
	}

	public static function dispatch( $tool, $args, $id = null ) {
		if ( self::TOOL_NAME !== $tool ) {
			return false;
		}

		$response = array( 'jsonrpc' => '2.0', 'id' => $id );
		$result   = self::search( is_array( $args ) ? $args : array() );

		if ( is_wp_error( $result ) ) {
			$response['error'] = array(
				'code' => -32603,
				'message' => $result->get_error_message(),
			);
			return $response;
		}

		$encoded = wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		if ( false === $encoded ) {
			$encoded = 'Image search completed.';
		}

		$response['result'] = array(
			'content' => array(
				array(
					'type' => 'text',
					'text' => $encoded,
				),
			),
			'structuredContent' => $result,
		);

		return $response;
	}

	private static function search( array $args ) {
		$query = isset( $args['query'] ) ? sanitize_text_field( wp_unslash( $args['query'] ) ) : '';
		if ( '' === trim( $query ) ) {
			return new WP_Error( 'sflmcp_search_image_missing_query', __( 'Search query is required.', 'stifli-flex-mcp' ) );
		}

		$settings   = self::getSettings();
		$providers  = self::getConfiguredProviders( $settings );
		$provider   = self::resolveProvider( $args, $settings, $providers );

		if ( is_wp_error( $provider ) ) {
			return $provider;
		}

		$selection   = self::resolveSelection( $args, $settings );
		$pool_size   = self::getSelectionPoolSize( $selection );
		$page        = isset( $args['page'] ) ? max( 1, intval( $args['page'] ) ) : 1;
		$orientation = self::resolveOrientation( $args, $settings );
		$api_key     = isset( $providers[ $provider ]['api_key'] ) ? $providers[ $provider ]['api_key'] : '';
		$timeout     = isset( $settings['search_image_timeout'] ) ? max( 5, min( 60, intval( $settings['search_image_timeout'] ) ) ) : 20;

		$images = self::requestProviderImages( $provider, $api_key, $query, $pool_size, $page, $orientation, $settings, $timeout );
		if ( is_wp_error( $images ) ) {
			return $images;
		}

		if ( empty( $images ) ) {
			return new WP_Error( 'sflmcp_search_image_no_results', __( 'No image results were found.', 'stifli-flex-mcp' ) );
		}

		$selected_index = self::selectImageIndex( $selection, count( $images ) );
		$selected_image = $images[ $selected_index ];

		return array(
			'success' => true,
			'query' => $query,
			'provider' => $provider,
			'selection' => $selection,
			'selection_pool_size' => min( $pool_size, count( $images ) ),
			'selected_index' => $selected_index,
			'page' => $page,
			'orientation' => $orientation,
			'image' => $selected_image,
			'result_count' => count( $images ),
		);
	}

	private static function getSettings() {
		$defaults = array(
			'search_image_preferred_bank' => 'random',
			'search_image_selection' => 'most_relevant',
			'search_image_orientation' => 'any',
			'search_image_safe_search' => '1',
			'search_image_language' => 'en',
			'search_image_locale' => 'en-US',
			'search_image_timeout' => 20,
			'search_image_unsplash_enabled' => '0',
			'search_image_unsplash_api_key' => '',
			'search_image_pexels_enabled' => '0',
			'search_image_pexels_api_key' => '',
			'search_image_pixabay_enabled' => '0',
			'search_image_pixabay_api_key' => '',
		);

		$saved = get_option( 'sflmcp_multimedia_settings', array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}

		return wp_parse_args( $saved, $defaults );
	}

	private static function getConfiguredProviders( array $settings ) {
		$providers = array();
		foreach ( self::getProviderSlugs() as $provider ) {
			$enabled_key = 'search_image_' . $provider . '_enabled';
			$api_key_key = 'search_image_' . $provider . '_api_key';
			$enabled     = isset( $settings[ $enabled_key ] ) && '1' === (string) $settings[ $enabled_key ];
			$api_key     = isset( $settings[ $api_key_key ] ) ? self::decryptValue( $settings[ $api_key_key ] ) : '';

			if ( $enabled && '' !== trim( $api_key ) ) {
				$providers[ $provider ] = array(
					'api_key' => trim( $api_key ),
				);
			}
		}

		return $providers;
	}

	private static function resolveProvider( array $args, array $settings, array $providers ) {
		if ( empty( $providers ) ) {
			return new WP_Error( 'sflmcp_search_image_no_providers', __( 'Configure apikeys providers in Multimedia > Search Image.', 'stifli-flex-mcp' ) );
		}

		$requested = isset( $args['provider'] ) ? sanitize_key( wp_unslash( $args['provider'] ) ) : '';
		if ( '' === $requested ) {
			$requested = isset( $settings['search_image_preferred_bank'] ) ? sanitize_key( $settings['search_image_preferred_bank'] ) : 'random';
		}

		if ( 'random' === $requested ) {
			$available = array_keys( $providers );
			return $available[ self::randomInt( 0, count( $available ) - 1 ) ];
		}

		if ( ! in_array( $requested, self::getProviderSlugs(), true ) ) {
			return new WP_Error( 'sflmcp_search_image_invalid_provider', __( 'Invalid Search Image provider.', 'stifli-flex-mcp' ) );
		}

		if ( ! isset( $providers[ $requested ] ) ) {
			return new WP_Error( 'sflmcp_search_image_provider_not_configured', sprintf( __( 'The %s provider is not enabled or has no API key.', 'stifli-flex-mcp' ), $requested ) );
		}

		return $requested;
	}

	private static function resolveSelection( array $args, array $settings ) {
		$selection = isset( $args['selection'] ) ? sanitize_key( wp_unslash( $args['selection'] ) ) : '';
		if ( '' === $selection ) {
			$selection = isset( $settings['search_image_selection'] ) ? sanitize_key( $settings['search_image_selection'] ) : 'most_relevant';
		}

		return in_array( $selection, array( 'most_relevant', 'random_top10', 'random_top20' ), true ) ? $selection : 'most_relevant';
	}

	private static function resolveOrientation( array $args, array $settings ) {
		$orientation = isset( $args['orientation'] ) ? sanitize_key( wp_unslash( $args['orientation'] ) ) : '';
		if ( '' === $orientation ) {
			$orientation = isset( $settings['search_image_orientation'] ) ? sanitize_key( $settings['search_image_orientation'] ) : 'any';
		}

		return in_array( $orientation, array( 'any', 'landscape', 'portrait', 'square' ), true ) ? $orientation : 'any';
	}

	private static function getSelectionPoolSize( $selection ) {
		if ( 'random_top20' === $selection ) {
			return 20;
		}

		if ( 'random_top10' === $selection ) {
			return 10;
		}

		return 1;
	}

	private static function selectImageIndex( $selection, $count ) {
		if ( $count <= 1 || 'most_relevant' === $selection ) {
			return 0;
		}

		return self::randomInt( 0, $count - 1 );
	}

	private static function requestProviderImages( $provider, $api_key, $query, $per_page, $page, $orientation, array $settings, $timeout ) {
		switch ( $provider ) {
			case 'unsplash':
				return self::searchUnsplash( $api_key, $query, $per_page, $page, $orientation, $settings, $timeout );
			case 'pexels':
				return self::searchPexels( $api_key, $query, $per_page, $page, $orientation, $settings, $timeout );
			case 'pixabay':
				return self::searchPixabay( $api_key, $query, $per_page, $page, $orientation, $settings, $timeout );
		}

		return new WP_Error( 'sflmcp_search_image_invalid_provider', __( 'Invalid Search Image provider.', 'stifli-flex-mcp' ) );
	}

	private static function searchUnsplash( $api_key, $query, $per_page, $page, $orientation, array $settings, $timeout ) {
		$mapped_orientation = 'square' === $orientation ? 'squarish' : $orientation;
		$query_args = array(
			'query' => $query,
			'per_page' => max( 1, min( 20, intval( $per_page ) ) ),
			'page' => max( 1, intval( $page ) ),
			'client_id' => $api_key,
			'content_filter' => ! empty( $settings['search_image_safe_search'] ) && '1' === (string) $settings['search_image_safe_search'] ? 'high' : 'low',
		);

		if ( in_array( $mapped_orientation, array( 'landscape', 'portrait', 'squarish' ), true ) ) {
			$query_args['orientation'] = $mapped_orientation;
		}

		$payload = self::remoteJson( 'https://api.unsplash.com/search/photos', $query_args, array(
			'Accept-Version' => 'v1',
		), $timeout, 'Unsplash' );

		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

		$results = isset( $payload['results'] ) && is_array( $payload['results'] ) ? $payload['results'] : array();
		$images  = array();

		foreach ( $results as $item ) {
			$image_url = self::getNestedValue( $item, array( 'urls', 'regular' ), '' );
			if ( '' === $image_url ) {
				$image_url = self::getNestedValue( $item, array( 'urls', 'full' ), '' );
			}
			if ( '' === $image_url ) {
				continue;
			}

			$author = self::getNestedValue( $item, array( 'user', 'name' ), '' );
			$alt    = self::firstNonEmpty( array(
				isset( $item['alt_description'] ) ? $item['alt_description'] : '',
				isset( $item['description'] ) ? $item['description'] : '',
				$query,
			) );

			$images[] = array(
				'id' => isset( $item['id'] ) ? (string) $item['id'] : '',
				'provider' => 'unsplash',
				'title' => $alt,
				'alt_text' => $alt,
				'caption' => self::buildCaption( $author, 'Unsplash' ),
				'description' => isset( $item['description'] ) ? (string) $item['description'] : '',
				'url' => $image_url,
				'full_url' => self::getNestedValue( $item, array( 'urls', 'full' ), '' ),
				'thumbnail_url' => self::getNestedValue( $item, array( 'urls', 'thumb' ), '' ),
				'source_url' => self::getNestedValue( $item, array( 'links', 'html' ), '' ),
				'download_url' => self::getNestedValue( $item, array( 'links', 'download' ), '' ),
				'download_location' => self::getNestedValue( $item, array( 'links', 'download_location' ), '' ),
				'width' => isset( $item['width'] ) ? intval( $item['width'] ) : 0,
				'height' => isset( $item['height'] ) ? intval( $item['height'] ) : 0,
				'author' => $author,
				'author_url' => self::getNestedValue( $item, array( 'user', 'links', 'html' ), '' ),
				'license' => 'Unsplash License',
				'color' => isset( $item['color'] ) ? (string) $item['color'] : '',
				'likes' => isset( $item['likes'] ) ? intval( $item['likes'] ) : 0,
			);
		}

		return $images;
	}

	private static function searchPexels( $api_key, $query, $per_page, $page, $orientation, array $settings, $timeout ) {
		$query_args = array(
			'query' => $query,
			'per_page' => max( 1, min( 20, intval( $per_page ) ) ),
			'page' => max( 1, intval( $page ) ),
			'locale' => isset( $settings['search_image_locale'] ) ? sanitize_text_field( $settings['search_image_locale'] ) : 'en-US',
		);

		if ( in_array( $orientation, array( 'landscape', 'portrait', 'square' ), true ) ) {
			$query_args['orientation'] = $orientation;
		}

		$payload = self::remoteJson( 'https://api.pexels.com/v1/search', $query_args, array(
			'Authorization' => $api_key,
		), $timeout, 'Pexels' );

		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

		$photos = isset( $payload['photos'] ) && is_array( $payload['photos'] ) ? $payload['photos'] : array();
		$images = array();

		foreach ( $photos as $photo ) {
			$image_url = self::getNestedValue( $photo, array( 'src', 'original' ), '' );
			if ( '' === $image_url ) {
				$image_url = self::getNestedValue( $photo, array( 'src', 'large2x' ), '' );
			}
			if ( '' === $image_url ) {
				$image_url = self::getNestedValue( $photo, array( 'src', 'large' ), '' );
			}
			if ( '' === $image_url ) {
				continue;
			}

			$author = isset( $photo['photographer'] ) ? (string) $photo['photographer'] : '';
			$alt    = self::firstNonEmpty( array(
				isset( $photo['alt'] ) ? $photo['alt'] : '',
				$query,
			) );

			$images[] = array(
				'id' => isset( $photo['id'] ) ? (string) $photo['id'] : '',
				'provider' => 'pexels',
				'title' => $alt,
				'alt_text' => $alt,
				'caption' => self::buildCaption( $author, 'Pexels' ),
				'description' => $alt,
				'url' => $image_url,
				'full_url' => $image_url,
				'thumbnail_url' => self::getNestedValue( $photo, array( 'src', 'medium' ), '' ),
				'source_url' => isset( $photo['url'] ) ? (string) $photo['url'] : '',
				'download_url' => $image_url,
				'download_location' => '',
				'width' => isset( $photo['width'] ) ? intval( $photo['width'] ) : 0,
				'height' => isset( $photo['height'] ) ? intval( $photo['height'] ) : 0,
				'author' => $author,
				'author_url' => isset( $photo['photographer_url'] ) ? (string) $photo['photographer_url'] : '',
				'license' => 'Pexels License',
				'color' => isset( $photo['avg_color'] ) ? (string) $photo['avg_color'] : '',
				'likes' => 0,
			);
		}

		return $images;
	}

	private static function searchPixabay( $api_key, $query, $per_page, $page, $orientation, array $settings, $timeout ) {
		$query_args = array(
			'key' => $api_key,
			'q' => $query,
			'per_page' => max( 3, min( 20, intval( $per_page ) ) ),
			'page' => max( 1, intval( $page ) ),
			'image_type' => 'photo',
			'safesearch' => ! empty( $settings['search_image_safe_search'] ) && '1' === (string) $settings['search_image_safe_search'] ? 'true' : 'false',
			'lang' => isset( $settings['search_image_language'] ) ? sanitize_key( $settings['search_image_language'] ) : 'en',
		);

		if ( in_array( $orientation, array( 'landscape', 'portrait' ), true ) ) {
			$query_args['orientation'] = $orientation;
		}

		$payload = self::remoteJson( 'https://pixabay.com/api/', $query_args, array(), $timeout, 'Pixabay' );
		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

		$hits   = isset( $payload['hits'] ) && is_array( $payload['hits'] ) ? $payload['hits'] : array();
		$images = array();

		foreach ( $hits as $hit ) {
			$image_url = isset( $hit['largeImageURL'] ) ? (string) $hit['largeImageURL'] : '';
			if ( '' === $image_url && ! empty( $hit['webformatURL'] ) ) {
				$image_url = (string) $hit['webformatURL'];
			}
			if ( '' === $image_url ) {
				continue;
			}

			$author = isset( $hit['user'] ) ? (string) $hit['user'] : '';
			$alt    = self::firstNonEmpty( array(
				isset( $hit['tags'] ) ? $hit['tags'] : '',
				$query,
			) );

			$images[] = array(
				'id' => isset( $hit['id'] ) ? (string) $hit['id'] : '',
				'provider' => 'pixabay',
				'title' => $alt,
				'alt_text' => $alt,
				'caption' => self::buildCaption( $author, 'Pixabay' ),
				'description' => $alt,
				'url' => $image_url,
				'full_url' => isset( $hit['fullHDURL'] ) ? (string) $hit['fullHDURL'] : $image_url,
				'thumbnail_url' => isset( $hit['previewURL'] ) ? (string) $hit['previewURL'] : '',
				'source_url' => isset( $hit['pageURL'] ) ? (string) $hit['pageURL'] : '',
				'download_url' => $image_url,
				'download_location' => '',
				'width' => isset( $hit['imageWidth'] ) ? intval( $hit['imageWidth'] ) : 0,
				'height' => isset( $hit['imageHeight'] ) ? intval( $hit['imageHeight'] ) : 0,
				'author' => $author,
				'author_url' => isset( $hit['userImageURL'] ) ? (string) $hit['userImageURL'] : '',
				'license' => 'Pixabay License',
				'color' => '',
				'likes' => isset( $hit['likes'] ) ? intval( $hit['likes'] ) : 0,
			);
		}

		return $images;
	}

	private static function remoteJson( $endpoint, array $query_args, array $headers, $timeout, $provider_label ) {
		$request_url = add_query_arg( $query_args, $endpoint );
		$response    = wp_remote_get( $request_url, array(
			'timeout' => max( 5, min( 60, intval( $timeout ) ) ),
			'redirection' => 5,
			'user-agent' => 'StifLi Flex MCP/' . ( defined( 'SFLMCP_VERSION' ) ? SFLMCP_VERSION : '1.0' ) . '; ' . home_url( '/' ),
			'headers' => $headers,
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body        = wp_remote_retrieve_body( $response );
		$payload     = json_decode( $body, true );

		if ( 200 !== intval( $status_code ) || ! is_array( $payload ) ) {
			$message = sprintf( __( '%s returned an unexpected response.', 'stifli-flex-mcp' ), $provider_label );
			if ( is_array( $payload ) ) {
				$message = self::extractProviderErrorMessage( $payload, $message );
			}
			return new WP_Error( 'sflmcp_search_image_http_error', $message, array( 'status' => $status_code ) );
		}

		return $payload;
	}

	private static function extractProviderErrorMessage( array $payload, $fallback ) {
		if ( ! empty( $payload['error'] ) && is_string( $payload['error'] ) ) {
			return $payload['error'];
		}

		if ( ! empty( $payload['message'] ) && is_string( $payload['message'] ) ) {
			return $payload['message'];
		}

		if ( ! empty( $payload['errors'] ) && is_array( $payload['errors'] ) ) {
			return implode( '; ', array_map( 'sanitize_text_field', $payload['errors'] ) );
		}

		return $fallback;
	}

	private static function getProviderSlugs() {
		return array( 'unsplash', 'pexels', 'pixabay' );
	}

	private static function decryptValue( $value ) {
		if ( '' === (string) $value ) {
			return '';
		}

		if ( class_exists( 'StifliFlexMcp_Client_Admin' ) ) {
			return StifliFlexMcp_Client_Admin::decrypt_value( $value );
		}

		return (string) $value;
	}

	private static function buildCaption( $author, $provider_label ) {
		if ( '' === trim( $author ) ) {
			return sprintf( __( 'Photo from %s', 'stifli-flex-mcp' ), $provider_label );
		}

		return sprintf( __( 'Photo by %1$s on %2$s', 'stifli-flex-mcp' ), $author, $provider_label );
	}

	private static function firstNonEmpty( array $values ) {
		foreach ( $values as $value ) {
			if ( is_string( $value ) && '' !== trim( $value ) ) {
				return trim( $value );
			}
		}

		return '';
	}

	private static function getNestedValue( array $source, array $keys, $default = '' ) {
		$current = $source;
		foreach ( $keys as $key ) {
			if ( ! is_array( $current ) || ! array_key_exists( $key, $current ) ) {
				return $default;
			}
			$current = $current[ $key ];
		}

		return is_scalar( $current ) ? (string) $current : $default;
	}

	private static function randomInt( $min, $max ) {
		if ( function_exists( 'wp_rand' ) ) {
			return wp_rand( $min, $max );
		}

		return mt_rand( $min, $max );
	}
}