<?php
if ( ! isset( $GLOBALS['workdir'] ) ) {
	throw new RuntimeException( 'Run test-upload-media.php --real-images instead.' );
}
if ( ! extension_loaded( 'gd' ) ) {
	throw new RuntimeException( 'Real image checks require the PHP GD extension.' );
}
$wp_root = getenv( 'SFLMCP_TEST_WP_ROOT' );
if ( ! $wp_root || ! is_file( $wp_root . '/wp-includes/class-wp-image-editor-gd.php' ) ) {
	throw new RuntimeException( 'Set SFLMCP_TEST_WP_ROOT to a local WordPress source directory. No database is used.' );
}
require_once $wp_root . '/wp-includes/class-wp-image-editor.php';
require_once $wp_root . '/wp-includes/class-wp-image-editor-gd.php';

function wp_raise_memory_limit( $context ) { return false; }
function is_gd_image( $value ) { return $value instanceof GdImage || is_resource( $value ); }
function wp_get_image_editor_output_format( $filename, $mime ) { return array(); }
function trailingslashit( $value ) { return rtrim( $value, '/\\' ) . '/'; }
function wp_is_stream( $file ) { return false; }
function wp_mkdir_p( $dir ) { return is_dir( $dir ) || mkdir( $dir ); }
function wp_filesize( $file ) { return filesize( $file ); }
function wp_get_default_extension_for_mime_type( $mime ) {
	foreach ( get_allowed_mime_types() as $extensions => $type ) {
		if ( $mime === $type ) { return explode( '|', $extensions )[0]; }
	}
	return false;
}
function wp_imagecreatetruecolor( $width, $height ) {
	$image = imagecreatetruecolor( $width, $height );
	imagealphablending( $image, false );
	imagesavealpha( $image, true );
	return $image;
}
function image_resize_dimensions( $original_width, $original_height, $max_width, $max_height, $crop ) {
	$ratio = min( 1, $max_width / $original_width, $max_height / $original_height );
	return array( 0, 0, 0, 0, max( 1, (int) round( $original_width * $ratio ) ), max( 1, (int) round( $original_height * $ratio ) ), $original_width, $original_height );
}

$GLOBALS['real_images'] = true;
$source = $GLOBALS['workdir'] . DIRECTORY_SEPARATOR . 'noise.png';
$GLOBALS['files'][] = $source;
$image = imagecreatetruecolor( 1600, 1200 );
mt_srand( 12345 );
for ( $y = 0; $y < 1200; $y++ ) {
	for ( $x = 0; $x < 1600; $x++ ) { imagesetpixel( $image, $x, $y, mt_rand( 0, 0xffffff ) ); }
}
imagepng( $image, $source );
imagedestroy( $image );
$input = array( 'content_base64' => base64_encode( file_get_contents( $source ) ), 'filename' => 'noise.jfif' );
$output = StifliFlexMcp_Upload_Media::upload( $input + array( 'convert_to' => 'jpeg', 'max_width' => 1600, 'max_kb' => 400 ) );
check( ! is_wp_error( $output ), 'real WP GD encoder reaches JPEG 400 KiB target' . ( is_wp_error( $output ) ? ': ' . $output->get_error_message() : '' ) );
$path = get_attached_file( $output['id'] );
$size = filesize( $path );
$dimensions = getimagesize( $path );
check( $size <= 400 * 1024 && $size === $output['size_bytes'], 'actual JPEG file <= 409600 bytes' );
check( 'image/jpeg' === $dimensions['mime'] && $dimensions[0] <= 1600, 'actual output MIME and width match request' );
check( $dimensions[0] < 1600, 'real noisy image required dimension reduction beyond quality reduction' );
echo 'REAL JPEG: ' . $size . ' bytes, ' . $dimensions[0] . 'x' . $dimensions[1] . PHP_EOL;
$low = StifliFlexMcp_Upload_Media::upload( $input + array( 'convert_to' => 'jpeg', 'quality' => 10, 'max_width' => 800 ) );
$high = StifliFlexMcp_Upload_Media::upload( $input + array( 'convert_to' => 'jpeg', 'quality' => 95, 'max_width' => 800 ) );
check( ! is_wp_error( $low ) && ! is_wp_error( $high ) && $low['size_bytes'] < $high['size_bytes'], 'requested quality survives WordPress output-format conversion' );
$webp = StifliFlexMcp_Upload_Media::upload( $input + array( 'convert_to' => 'webp', 'max_width' => 600, 'max_height' => 300, 'max_kb' => 100 ) );
check( ! is_wp_error( $webp ) && 'image/webp' === $webp['mime_type'] && $webp['size_bytes'] <= 102400 && $webp['height'] <= 300, 'real WebP conversion, height and size target' );
upload_error( $input + array( 'max_kb' => 10 ), 'image_target_unreachable', 'real lossless PNG impossible target reports error' );
check_temporary_cleanup();
