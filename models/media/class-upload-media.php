<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class StifliFlexMcp_Upload_Media {
	const TOOL_NAME = 'wp_upload_media';
	const MAX_BYTES = 20971520;

	public static function getFileSchema() {
		return array(
			'type' => 'object',
			'properties' => array(
				'download_url' => array( 'type' => 'string' ),
				'file_id' => array( 'type' => 'string' ),
				'mime_type' => array( 'type' => 'string' ),
				'file_name' => array( 'type' => 'string' ),
			),
			'required' => array( 'download_url', 'file_id' ),
			'additionalProperties' => false,
		);
	}

	public static function getTools() {
		return array(
			self::TOOL_NAME => array(
				'name' => self::TOOL_NAME,
				'description' => 'Upload one file to WordPress from a ChatGPT conversation file, HTTPS URL, or base64. Supply exactly one of file, url, content_base64. Supports WordPress-permitted documents and images, excluding executable/active formats. Maximum 20 MiB or the lower site limit. Optional image conversion, resizing and max_kb target may reduce quality and dimensions; an unattainable target returns an error without creating media.',
				'_meta' => array( 'openai/fileParams' => array( 'file' ) ),
				'annotations' => array( 'readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false, 'openWorldHint' => true ),
				'inputSchema' => array(
					'type' => 'object',
					'properties' => array(
						'file' => self::getFileSchema(),
						'url' => array( 'type' => 'string', 'description' => 'Public HTTPS download URL.' ),
						'content_base64' => array( 'type' => 'string', 'description' => 'Raw base64 or a base64 data URL. Use filename for documents.' ),
						'filename' => array( 'type' => 'string', 'description' => 'Optional filename; overrides file.file_name. Image extensions are detected from the bytes.' ),
						'title' => array( 'type' => 'string' ),
						'alt_text' => array( 'type' => 'string', 'description' => 'Image alternative text.' ),
						'post_id' => array( 'type' => 'integer', 'minimum' => 1, 'description' => 'Optional parent post; requires permission to edit it.' ),
						'convert_to' => array( 'type' => 'string', 'enum' => array( 'jpeg', 'webp' ) ),
						'max_width' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 10000 ),
						'max_height' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 10000 ),
						'max_kb' => array( 'type' => 'integer', 'minimum' => 10, 'maximum' => 20480, 'description' => 'Maximum output size in KiB (1024 bytes). May reduce image quality and dimensions.' ),
						'quality' => array( 'type' => 'integer', 'minimum' => 10, 'maximum' => 100, 'default' => 82 ),
					),
					'additionalProperties' => false,
				),
				'outputSchema' => array(
					'type' => 'object',
					'properties' => array(
						'id' => array( 'type' => 'integer' ),
						'url' => array( 'type' => 'string' ),
						'filename' => array( 'type' => 'string' ),
						'mime_type' => array( 'type' => 'string' ),
						'size_bytes' => array( 'type' => 'integer' ),
						'width' => array( 'type' => 'integer' ),
						'height' => array( 'type' => 'integer' ),
						'optimized' => array( 'type' => 'boolean' ),
					),
					'required' => array( 'id', 'url', 'filename', 'mime_type', 'size_bytes', 'optimized' ),
					'additionalProperties' => false,
				),
			),
		);
	}

	public static function normalizeArguments( array $args ) {
		$file = StifliFlexMcpUtils::getArrayValue( $args, 'file', null );
		if ( is_string( $file ) ) {
			$decoded = json_decode( $file );
			if ( is_object( $decoded ) ) {
				$args['file'] = get_object_vars( $decoded );
			}
		} elseif ( is_object( $file ) ) {
			$args['file'] = get_object_vars( $file );
		}
		return $args;
	}

	public static function upload( array $args, $images_only = false ) {
		if ( ! current_user_can( 'upload_files' ) ) {
			return new WP_Error( 'permission_denied', 'You do not have permission to upload files.' );
		}
		$args = self::normalizeArguments( $args );
		$schema = self::getTools()[self::TOOL_NAME]['inputSchema'];
		foreach ( $args as $key => $value ) {
			if ( ! isset( $schema['properties'][$key] ) ) {
				return new WP_Error( 'invalid_arguments', 'Unknown upload parameter: ' . $key );
			}
			$property = $schema['properties'][$key];
			if ( 'string' === $property['type'] && ( ! is_string( $value ) || ( ! in_array( $key, array( 'title', 'alt_text' ), true ) && '' === trim( $value ) ) ) ) {
				return new WP_Error( 'invalid_arguments', $key . ' must be a string and source/filename values must not be empty.' );
			}
			if ( 'integer' === $property['type'] && ( ! is_int( $value ) || $value < $property['minimum'] || ( isset( $property['maximum'] ) && $value > $property['maximum'] ) ) ) {
				return new WP_Error( 'invalid_arguments', $key . ' is outside its supported integer range.' );
			}
		}
		$sources = array_intersect( array( 'file', 'url', 'content_base64' ), array_keys( $args ) );
		if ( 1 !== count( $sources ) ) {
			return new WP_Error( 'invalid_arguments', 'Supply exactly one of file, url or content_base64.' );
		}
		if ( isset( $args['convert_to'] ) && ! in_array( $args['convert_to'], array( 'jpeg', 'webp' ), true ) ) {
			return new WP_Error( 'invalid_arguments', 'convert_to must be jpeg or webp.' );
		}
		$post_id = StifliFlexMcpUtils::getArrayValue( $args, 'post_id', 0 );
		if ( $post_id && ( ! get_post( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) ) {
			return new WP_Error( 'permission_denied', 'The parent post does not exist or cannot be edited.' );
		}
		$url = StifliFlexMcpUtils::getArrayValue( $args, 'url', '' );
		$name = StifliFlexMcpUtils::getArrayValue( $args, 'filename', '' );
		if ( array_key_exists( 'file', $args ) ) {
			$file = $args['file'];
			if ( ! is_array( $file ) || array_diff( array_keys( $file ), array( 'download_url', 'file_id', 'mime_type', 'file_name' ) ) ) {
				return new WP_Error( 'invalid_file', 'file must be a file object with download_url and file_id.' );
			}
			foreach ( array( 'download_url', 'file_id', 'mime_type', 'file_name' ) as $field ) {
				if ( ( in_array( $field, array( 'download_url', 'file_id' ), true ) && empty( $file[$field] ) )
					|| ( array_key_exists( $field, $file ) && ( ! is_string( $file[$field] ) || '' === trim( $file[$field] ) ) ) ) {
					return new WP_Error( 'invalid_file', 'Invalid or missing file.' . $field );
				}
			}
			$url = $file['download_url'];
			$name = $name ?: StifliFlexMcpUtils::getArrayValue( $file, 'file_name', '' );
		}
		if ( ! function_exists( 'media_handle_sideload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}
		$limit = min( self::MAX_BYTES, (int) wp_max_upload_size() );
		if ( $limit <= 0 ) {
			return new WP_Error( 'upload_disabled', 'The WordPress upload size limit does not permit uploads.' );
		}
		$tmp = wp_tempnam( 'sflmcp-upload' );
		if ( ! $tmp ) {
			return new WP_Error( 'temp_file_failed', 'Could not create an upload temporary file.' );
		}
		$temporary_files = array( $tmp );
		try {
			if ( '' !== $url ) {
				$download = self::download( $url, $tmp, $limit );
				if ( is_wp_error( $download ) ) {
					return $download;
				}
				$name = $name ?: wp_basename( (string) wp_parse_url( $url, PHP_URL_PATH ) );
			} else {
				$encoded = $args['content_base64'];
				if ( strlen( $encoded ) > 4 * (int) ceil( $limit / 3 ) + 4096 ) {
					return new WP_Error( 'file_too_large', 'Base64 input exceeds the upload size limit.' );
				}
				$encoded = preg_replace( '~^data:[^;,]+;base64,~i', '', trim( $encoded ) );
				$bytes = base64_decode( preg_replace( '/\s+/', '', $encoded ), true );
				if ( false === $bytes || '' === $bytes ) {
					return new WP_Error( 'invalid_base64', 'content_base64 must contain valid, nonempty base64 data.' );
				}
				if ( strlen( $bytes ) > $limit ) {
					return new WP_Error( 'file_too_large', 'The decoded file exceeds the upload size limit.' );
				}
				if ( file_put_contents( $tmp, $bytes ) !== strlen( $bytes ) ) {
					return new WP_Error( 'write_error', 'Could not write the upload temporary file.' );
				}
				unset( $bytes );
			}
			$type = self::validateFile( $tmp, $name );
			if ( is_wp_error( $type ) ) {
				return $type;
			}
			$image_options = array_intersect_key( $args, array_flip( array( 'convert_to', 'max_width', 'max_height', 'max_kb', 'quality' ) ) );
			$is_image = 0 === strpos( $type['type'], 'image/' );
			if ( $images_only && ! $is_image ) {
				return new WP_Error( 'not_an_image', 'wp_upload_image only accepts images. Use wp_upload_media for documents and other files.' );
			}
			if ( $image_options && ! $is_image ) {
				return new WP_Error( 'not_an_image', 'Image conversion, size and quality parameters cannot be used for documents.' );
			}
			if ( $image_options ) {
				$processed = self::processImage( $tmp, $type, $image_options, $limit, $temporary_files );
				if ( is_wp_error( $processed ) ) {
					return $processed;
				}
				$tmp = $processed['path'];
				$type = self::validateFile( $tmp, $processed['filename'] );
				if ( is_wp_error( $type ) ) {
					return $type;
				}
			}
			clearstatcache( true, $tmp );
			$size = filesize( $tmp );
			if ( false === $size || $size < 1 || $size > $limit ) {
				return new WP_Error( 'file_too_large', 'The final file is empty, unreadable or exceeds the upload size limit.' );
			}
			$fields = array();
			if ( isset( $args['title'] ) ) {
				$fields['post_title'] = sanitize_text_field( $args['title'] );
			}
			$id = media_handle_sideload( array( 'name' => $type['filename'], 'tmp_name' => $tmp ), $post_id, null, $fields );
			if ( is_wp_error( $id ) ) {
				return $id;
			}
			if ( $is_image && isset( $args['alt_text'] ) ) {
				$alt = sanitize_text_field( $args['alt_text'] );
				update_post_meta( $id, '_wp_attachment_image_alt', $alt );
				if ( get_post_meta( $id, '_wp_attachment_image_alt', true ) !== $alt ) {
					return self::rejectAttachment( $id, 'Could not save image alternative text.' );
				}
			}
			$attached_path = get_attached_file( $id );
			$attached_url = wp_get_attachment_url( $id );
			clearstatcache( true, $attached_path );
			$final_size = is_file( $attached_path ) ? filesize( $attached_path ) : false;
			$final_limit = isset( $args['max_kb'] ) ? min( $limit, $args['max_kb'] * 1024 ) : $limit;
			if ( ! $attached_url || false === $final_size || $final_size < 1 || $final_size > $final_limit ) {
				return self::rejectAttachment( $id, 'The saved attachment is unavailable or does not meet the requested size limit.' );
			}
			$result = array(
				'id' => (int) $id,
				'url' => $attached_url,
				'filename' => wp_basename( $attached_path ),
				'mime_type' => get_post_mime_type( $id ),
				'size_bytes' => (int) $final_size,
				'optimized' => ! empty( $image_options ),
			);
			if ( $is_image ) {
				$dimensions = wp_getimagesize( $attached_path );
				if ( ! $dimensions || ( isset( $args['max_width'] ) && $dimensions[0] > $args['max_width'] )
					|| ( isset( $args['max_height'] ) && $dimensions[1] > $args['max_height'] )
					|| ( isset( $args['convert_to'] ) && $result['mime_type'] !== 'image/' . $args['convert_to'] ) ) {
					return self::rejectAttachment( $id, 'The saved image does not meet the requested format or dimensions.' );
				}
				$result['width'] = (int) $dimensions[0];
				$result['height'] = (int) $dimensions[1];
			}
			return $result;
		} finally {
			foreach ( array_unique( $temporary_files ) as $path ) {
				if ( file_exists( $path ) ) {
					wp_delete_file( $path );
				}
			}
		}
	}

	private static function rejectAttachment( $id, $message ) {
		if ( ! wp_delete_attachment( $id, true ) ) {
			$message .= ' Could not remove incomplete attachment #' . (int) $id . '; remove it from the Media Library.';
			stifli_flex_mcp_log( 'wp_upload_media: ' . $message );
		}
		return new WP_Error( 'attachment_verification_failed', $message );
	}

	private static function download( $url, $tmp, $limit ) {
		for ( $redirect = 0; $redirect <= 3; $redirect++ ) {
			$parts = wp_parse_url( $url );
			if ( ! is_array( $parts ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
				return new WP_Error( 'invalid_url', 'Download URLs must not contain credentials.' );
			}
			$valid = StifliFlexMcpUtils::validateOutboundUrl( $url, true );
			if ( is_wp_error( $valid ) ) {
				return $valid;
			}
			$response = wp_safe_remote_get( $url, array(
				'timeout' => 10,
				'redirection' => 0,
				'stream' => true,
				'filename' => $tmp,
				'limit_response_size' => $limit + 1,
				'cookies' => array(),
				'headers' => array( 'Accept' => '*/*' ),
			) );
			if ( is_wp_error( $response ) ) {
				return new WP_Error( 'download_failed', 'Download failed. The temporary URL may have expired; request a new file URL.' );
			}
			$status = wp_remote_retrieve_response_code( $response );
			if ( in_array( $status, array( 301, 302, 303, 307, 308 ), true ) ) {
				$location = wp_remote_retrieve_header( $response, 'location' );
				if ( ! is_string( $location ) || '' === $location ) {
					return new WP_Error( 'download_failed', 'Download redirect has no destination.' );
				}
				$url = WP_Http::make_absolute_url( $location, $url );
				continue;
			}
			if ( 200 !== $status ) {
				return new WP_Error( 'download_failed', 'Download returned HTTP ' . $status . '. Request a fresh URL if it has expired.' );
			}
			clearstatcache( true, $tmp );
			$size = filesize( $tmp );
			if ( false === $size || $size < 1 ) {
				return new WP_Error( 'empty_download', 'The download was empty or unreadable.' );
			}
			$length = wp_remote_retrieve_header( $response, 'content-length' );
			if ( $size > $limit || ( is_numeric( $length ) && (float) $length > $limit ) ) {
				return new WP_Error( 'file_too_large', 'The download exceeds the upload size limit.' );
			}
			return true;
		}
		return new WP_Error( 'too_many_redirects', 'The download exceeded three redirects.' );
	}

	private static function validateFile( $path, $name ) {
		$name = sanitize_file_name( wp_basename( $name ) );
		if ( preg_match( '/(?:^|\.)(?:svgz?|s?html?|xhtml|xml|[cm]?js|php\d*|phps|pht|phtml|phar|exe|com|bat|cmd|sh|ps1)(?:\.|$)/i', $name ) ) {
			return new WP_Error( 'file_type_blocked', 'Executable and active-content file formats are not permitted.' );
		}
		$image_mime = wp_get_image_mime( $path );
		$mimes = get_allowed_mime_types();
		if ( $image_mime ) {
			$dimensions = wp_getimagesize( $path );
			if ( ! $dimensions || $dimensions[0] * $dimensions[1] > 40000000 ) {
				return new WP_Error( 'image_too_large', 'Images must have valid dimensions and at most 40 megapixels.' );
			}
		}
		$mime = $image_mime;
		if ( ! $mime && '' === pathinfo( $name, PATHINFO_EXTENSION ) && class_exists( 'finfo' ) ) {
			$info = new finfo( FILEINFO_MIME_TYPE );
			$mime = $info->file( $path );
		}
		if ( $mime ) {
			foreach ( $mimes as $extensions => $allowed_mime ) {
				if ( $allowed_mime === $mime ) {
					$stem = pathinfo( $name, PATHINFO_FILENAME );
					$name = ( $stem ?: 'upload' ) . '.' . explode( '|', $extensions )[0];
					break;
				}
			}
		}
		$checked = wp_check_filetype_and_ext( $path, $name, $mimes );
		if ( empty( $checked['ext'] ) || empty( $checked['type'] ) || ! in_array( $checked['type'], $mimes, true )
			|| preg_match( '~^(?:image/svg\+xml|text/(?:html|xml|javascript)|application/(?:xml|xhtml\+xml|javascript|x-(?:httpd-php|executable|dosexec|shellscript)))$~i', $checked['type'] )
			|| ( 0 === strpos( $checked['type'], 'image/' ) && ! $image_mime ) ) {
			return new WP_Error( 'file_type_not_allowed', 'File type could not be verified or is not permitted by WordPress.' );
		}
		$checked['filename'] = ! empty( $checked['proper_filename'] ) ? $checked['proper_filename'] : $name;
		return $checked;
	}

	private static function processImage( $path, $type, $options, $limit, &$temporary_files ) {
		$editor = wp_get_image_editor( $path );
		if ( is_wp_error( $editor ) ) {
			return $editor;
		}
		$mime = isset( $options['convert_to'] ) ? 'image/' . $options['convert_to'] : $type['type'];
		if ( method_exists( $editor, 'maybe_exif_rotate' ) ) {
			$rotated = $editor->maybe_exif_rotate();
			if ( is_wp_error( $rotated ) ) {
				return $rotated;
			}
		}
		$size = $editor->get_size();
		$width = min( $size['width'], StifliFlexMcpUtils::getArrayValue( $options, 'max_width', $size['width'] ) );
		$height = min( $size['height'], StifliFlexMcpUtils::getArrayValue( $options, 'max_height', $size['height'] ) );
		$target = isset( $options['max_kb'] ) ? min( $limit, $options['max_kb'] * 1024 ) : $limit;
		$quality = StifliFlexMcpUtils::getArrayValue( $options, 'quality', 82 );
		$qualities = array( $quality );
		if ( isset( $options['max_kb'] ) && in_array( $mime, array( 'image/jpeg', 'image/webp' ), true ) ) {
			foreach ( array( 68, 52, 38 ) as $lower ) {
				if ( $lower < $quality ) {
					$qualities[] = $lower;
				}
			}
		}
		$ext = 'image/jpeg' === $mime ? 'jpg' : ( 'image/webp' === $mime ? 'webp' : $type['ext'] );
		$output = wp_tempnam( 'sflmcp-image' );
		if ( ! $output ) {
			return new WP_Error( 'temp_file_failed', 'Could not create the processed image temporary file.' );
		}
		$temporary_files[] = $output;
		$output .= '.' . $ext;
		$temporary_files[] = $output;
		$scales = isset( $options['max_kb'] ) ? array( 1, 0.9, 0.8, 0.7, 0.6, 0.5, 0.4 ) : array( 1 );
		foreach ( $scales as $scale ) {
			$w = max( 1, (int) floor( $width * $scale ) );
			$h = max( 1, (int) floor( $height * $scale ) );
			$current = $editor->get_size();
			if ( $current['width'] > $w || $current['height'] > $h ) {
				$resized = $editor->resize( $w, $h, false );
				if ( is_wp_error( $resized ) ) {
					return $resized;
				}
			}
			foreach ( $qualities as $q ) {
				$set_quality = $editor->set_quality( $q );
				if ( is_wp_error( $set_quality ) ) {
					return $set_quality;
				}
				// WordPress can reset quality when switching output MIME types.
				$requested_quality = static function () use ( $q ) { return $q; };
				add_filter( 'wp_editor_set_quality', $requested_quality, PHP_INT_MAX );
				add_filter( 'jpeg_quality', $requested_quality, PHP_INT_MAX );
				try {
					$saved = $editor->save( $output, $mime );
				} finally {
					remove_filter( 'wp_editor_set_quality', $requested_quality, PHP_INT_MAX );
					remove_filter( 'jpeg_quality', $requested_quality, PHP_INT_MAX );
				}
				if ( is_wp_error( $saved ) ) {
					return $saved;
				}
				$temporary_files[] = $saved['path'];
				clearstatcache( true, $saved['path'] );
				$bytes = filesize( $saved['path'] );
				if ( false !== $bytes && $bytes > 0 && $bytes <= $target ) {
					return array( 'path' => $saved['path'], 'filename' => pathinfo( $type['filename'], PATHINFO_FILENAME ) . '.' . $ext );
				}
			}
		}
		return new WP_Error( 'image_target_unreachable', 'Could not meet the image size target. Reduce max_width/max_height or increase max_kb; for PNG consider convert_to: jpeg or webp.' );
	}
}
