<?php
// Isolated tests: HTTP, database and attachments are doubles; optional real WordPress GD editor.
define( 'ABSPATH', __DIR__ . '/' );
define( 'ARRAY_A', 'ARRAY_A' );
require_once dirname( __DIR__ ) . '/models/utils.php';
require_once dirname( __DIR__ ) . '/models/model.php';

class WP_Error {
	private $code;
	private $message;
	public function __construct( $code, $message, $data = null ) { $this->code = $code; $this->message = $message; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function __( $text ) { return $text; }
function apply_filters( $name, $value, ...$args ) {
	foreach ( $GLOBALS['filters'][$name] ?? array() as $callback ) { $value = $callback( $value, ...$args ); }
	return $value;
}
function add_filter( $name, $callback, ...$args ) { $GLOBALS['filters'][$name][] = $callback; }
function remove_filter( $name, $callback, ...$args ) {
	foreach ( $GLOBALS['filters'][$name] ?? array() as $key => $value ) {
		if ( $value === $callback ) { unset( $GLOBALS['filters'][$name][$key] ); }
	}
}
function current_user_can( $capability, ...$args ) { return $GLOBALS['permissions'][$capability] ?? true; }
function get_current_user_id() { return 1; }
function is_multisite() { return false; }
function get_post( $id, $output = null ) {
	if ( 99 === $id ) { return null; }
	$post = array( 'ID' => $id, 'post_type' => isset( $GLOBALS['attachments'][$id] ) ? 'attachment' : 'post' );
	return ARRAY_A === $output ? $post : (object) $post;
}
function current_time( $format ) { return '2026-10-13 12:00:00'; }
function get_option( $key, $default = false ) { return 'sflmcp_changelog_enabled' === $key ? false : $default; }
function do_action( ...$args ) {}
function stifli_flex_mcp_log( $message ) { $GLOBALS['logs'][] = $message; }
function sanitize_file_name( $value ) { return preg_replace( '/[^a-zA-Z0-9._-]/', '-', $value ); }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( $value ) ); }
function sanitize_text_field( $value ) { return trim( strip_tags( $value ) ); }
function wp_basename( $path, $suffix = '' ) { return basename( str_replace( '\\', '/', $path ), $suffix ); }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
function wp_max_upload_size() { return $GLOBALS['upload_limit']; }
function wp_upload_dir() { return array( 'path' => $GLOBALS['workdir'] ); }
function wp_unique_filename( $directory, $name ) {
	$name = uniqid( 'legacy-', true ) . '-' . $name;
	$GLOBALS['files'][] = $directory . DIRECTORY_SEPARATOR . $name;
	return $name;
}
function wp_tempnam( $name ) {
	if ( ! empty( $GLOBALS['temp_failure'] ) ) { return false; }
	$file = tempnam( $GLOBALS['workdir'], 'tmp-' );
	$GLOBALS['files'][] = $file;
	$GLOBALS['temps'][] = $file;
	return $file;
}
function wp_delete_file( $file ) { if ( is_file( $file ) ) { unlink( $file ); } }
function wp_getimagesize( $file, ...$args ) {
	if ( isset( $GLOBALS['fake_dimensions'][$file] ) ) { return $GLOBALS['fake_dimensions'][$file]; }
	return @getimagesize( $file );
}
function wp_get_image_mime( $file ) { $size = wp_getimagesize( $file ); return $size['mime'] ?? false; }
function get_allowed_mime_types() {
	return array(
		'jpg|jpeg|jpe' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp',
		'pdf' => 'application/pdf', 'txt' => 'text/plain', 'zip' => 'application/zip',
		'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
		'svg' => 'image/svg+xml', 'html' => 'text/html', 'php' => 'application/x-httpd-php',
	);
}
function wp_get_mime_types() { return get_allowed_mime_types(); }
function wp_check_filetype( $name, $mimes = null ) {
	foreach ( $mimes ?? get_allowed_mime_types() as $extensions => $type ) {
		if ( preg_match( '/\.(' . $extensions . ')$/i', $name, $match ) ) {
			return array( 'ext' => strtolower( $match[1] ), 'type' => $type );
		}
	}
	return array( 'ext' => false, 'type' => false );
}
function wp_check_filetype_and_ext( $file, $name, $mimes ) {
	$GLOBALS['mime_checks']++;
	$type = wp_check_filetype( $name, $mimes );
	if ( ! empty( $GLOBALS['reject_mime'] ) || ( 'application/pdf' === $type['type'] && '%PDF-' !== substr( file_get_contents( $file ), 0, 5 ) ) ) {
		return array( 'ext' => false, 'type' => false );
	}
	return $type + array( 'proper_filename' => false );
}
class WP_Http {
	public static function make_absolute_url( $location, $url ) {
		return 0 === strpos( $location, '/' ) ? 'https://' . parse_url( $url, PHP_URL_HOST ) . $location : $location;
	}
}
function wp_safe_remote_get( $url, $args ) {
	$GLOBALS['requests'][] = array( $url, $args );
	$response = array_shift( $GLOBALS['responses'] );
	if ( is_wp_error( $response ) ) { return $response; }
	if ( ! is_array( $response ) ) { throw new RuntimeException( 'Unexpected HTTP request' ); }
	file_put_contents( $args['filename'], substr( $response['body'] ?? '', 0, $args['limit_response_size'] ) );
	return $response;
}
function wp_remote_retrieve_response_code( $response ) { return $response['status']; }
function wp_remote_retrieve_header( $response, $name ) { return $response['headers'][$name] ?? ''; }
function media_handle_sideload( $file, $post, $description = null, $fields = array() ) {
	if ( ! empty( $GLOBALS['sideload_failure'] ) ) { return new WP_Error( 'sideload_failed', 'Test sideload failure' ); }
	$id = ++$GLOBALS['next_id'];
	$destination = $GLOBALS['workdir'] . DIRECTORY_SEPARATOR . 'attachment-' . $id . '-' . $file['name'];
	rename( $file['tmp_name'], $destination );
	if ( isset( $GLOBALS['fake_dimensions'][$file['tmp_name']] ) ) {
		$GLOBALS['fake_dimensions'][$destination] = $GLOBALS['fake_dimensions'][$file['tmp_name']];
	}
	if ( ! empty( $GLOBALS['inflate_attachment'] ) ) { file_put_contents( $destination, str_repeat( 'x', 25000 ), FILE_APPEND ); }
	$GLOBALS['files'][] = $destination;
	$GLOBALS['attachments'][$id] = array( 'path' => $destination, 'type' => wp_check_filetype( $file['name'] )['type'], 'parent' => $post, 'fields' => $fields );
	return $id;
}
function get_attached_file( $id ) { return $GLOBALS['attachments'][$id]['path']; }
function get_post_mime_type( $id ) { return $GLOBALS['attachments'][$id]['type']; }
function wp_get_attachment_url( $id ) { return 'https://site.example/uploads/' . wp_basename( get_attached_file( $id ) ); }
function wp_delete_attachment( $id, $force ) {
	wp_delete_file( get_attached_file( $id ) );
	unset( $GLOBALS['attachments'][$id] );
	return true;
}
function update_post_meta( $id, $key, $value ) { $GLOBALS['metadata'][$id][$key] = $value; return 1; }
function get_post_meta( $id, $key = '', $single = false ) { return '' === $key ? ( $GLOBALS['metadata'][$id] ?? array() ) : ( $GLOBALS['metadata'][$id][$key] ?? '' ); }
class UploadTestDatabase {
	public $prefix = 'wp_';
	public $insert_id = 1;
	public $row;
	public $prepared_args = array();
	public function esc_like( $value ) { return $value; }
	public function prepare( $sql, ...$args ) { $this->prepared_args = $args; return $sql; }
	public function get_var( $sql ) {
		if ( 0 === strpos( $sql, 'SHOW TABLES' ) ) { return 'wp_sflmcp_tools'; }
		$enabled = in_array( 'wp_upload_image', $this->prepared_args, true )
			? ( $GLOBALS['image_tool_enabled'] ?? true ) : $GLOBALS['tool_enabled'];
		return $enabled ? '1' : '0';
	}
	public function insert( $table, $data, $formats ) { $this->row = $data + array( 'rolled_back' => 0 ); return 1; }
	public function get_row( $sql, $format ) { return $this->row; }
	public function update( $table, $data, ...$args ) { $this->row = array_merge( $this->row, $data ); return 1; }
}
class UploadTestModel extends StifliFlexMcpModel {
	public function getTools() { return StifliFlexMcp_Upload_Media::getTools(); }
}
class UploadTestEditor {
	private $width = 1000;
	private $height = 800;
	private $quality = 82;
	public function get_size() { return array( 'width' => $this->width, 'height' => $this->height ); }
	public function maybe_exif_rotate() { return false; }
	public function resize( $width, $height, $crop ) {
		$ratio = min( $width / $this->width, $height / $this->height );
		$this->width = max( 1, (int) floor( $this->width * $ratio ) );
		$this->height = max( 1, (int) floor( $this->height * $ratio ) );
		return true;
	}
	public function set_quality( $quality ) { $this->quality = $quality; return true; }
	public function save( $path, $mime ) {
		$GLOBALS['encodes']++;
		if ( ! empty( $GLOBALS['save_failure'] ) ) { return new WP_Error( 'save_failed', 'Test encoder failure' ); }
		$size = ! empty( $GLOBALS['impossible_image'] ) ? 50000 : max( 500, (int) ( $this->width * $this->height / 10 ) );
		file_put_contents( $path, str_repeat( 'x', $size ) );
		$GLOBALS['files'][] = $path;
		$GLOBALS['fake_dimensions'][$path] = array( 0 => $this->width, 1 => $this->height, 'mime' => $mime );
		return array( 'path' => $path );
	}
}
function wp_get_image_editor( $path ) {
	if ( ! empty( $GLOBALS['editor_failure'] ) ) { return new WP_Error( 'editor_failed', 'No image editor available' ); }
	if ( ! empty( $GLOBALS['real_images'] ) ) {
		$editor = new WP_Image_Editor_GD( $path );
		$loaded = $editor->load();
		return is_wp_error( $loaded ) ? $loaded : $editor;
	}
	return new UploadTestEditor();
}

function check( $condition, $label ) {
	if ( ! $condition ) { throw new RuntimeException( $label ); }
	$GLOBALS['checks']++;
	echo 'PASS ' . $label . PHP_EOL;
}
function upload_error( $args, $code, $label ) {
	$count = count( $GLOBALS['attachments'] );
	$result = StifliFlexMcp_Upload_Media::upload( $args );
	check( is_wp_error( $result ) && $code === $result->get_error_code(), $label . ( is_wp_error( $result ) ? ' [' . $result->get_error_code() . ']' : '' ) );
	check( count( $GLOBALS['attachments'] ) === $count, $label . ': no attachment created' );
}
function check_temporary_cleanup() {
	foreach ( $GLOBALS['temps'] as $temp ) {
		check( ! file_exists( $temp ), 'temporary file removed' );
	}
	foreach ( $GLOBALS['files'] as $file ) {
		if ( false !== strpos( basename( $file ), 'tmp-' ) ) {
			check( ! file_exists( $file ), 'processed temporary file removed' );
		}
	}
	$GLOBALS['temps'] = array();
}

$GLOBALS['workdir'] = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sflmcp-upload-tests-' . bin2hex( random_bytes( 8 ) );
mkdir( $GLOBALS['workdir'] );
$GLOBALS['files'] = $GLOBALS['temps'] = $GLOBALS['attachments'] = $GLOBALS['filters'] = $GLOBALS['requests'] = array();
$GLOBALS['upload_limit'] = StifliFlexMcp_Upload_Media::MAX_BYTES;
$GLOBALS['next_id'] = $GLOBALS['checks'] = $GLOBALS['mime_checks'] = $GLOBALS['encodes'] = 0;
$GLOBALS['tool_enabled'] = true;
$GLOBALS['wpdb'] = new UploadTestDatabase();
register_shutdown_function( function () {
	foreach ( array_unique( $GLOBALS['files'] ) as $file ) { wp_delete_file( $file ); }
	rmdir( $GLOBALS['workdir'] );
} );

$pdf = "%PDF-1.4\nTest document\n%%EOF\n";
$pdf_args = array( 'content_base64' => base64_encode( $pdf ), 'filename' => 'document.pdf' );
$png = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aWZkAAAAASUVORK5CYII=' );
$image_args = array( 'content_base64' => base64_encode( $png ), 'filename' => 'picture.jfif' );
$image_file = array( 'download_url' => 'https://8.8.8.8/image', 'file_id' => 'file-image' );
$file = array( 'download_url' => 'https://8.8.8.8/download', 'file_id' => 'file-test', 'file_name' => 'document.pdf' );
$tool = StifliFlexMcp_Upload_Media::getTools()['wp_upload_media'];
check( array( 'file' ) === $tool['_meta']['openai/fileParams'], 'OpenAI metadata' );
check( array( 'download_url', 'file_id' ) === $tool['inputSchema']['properties']['file']['required'], 'only mandatory OpenAI file fields required' );
check( array( 'download_url', 'file_id', 'mime_type', 'file_name' ) === array_keys( $tool['inputSchema']['properties']['file']['properties'] ), 'all four OpenAI properties declared' );
check( false === $tool['inputSchema']['properties']['file']['additionalProperties'], 'strict file object schema' );
$model = new UploadTestModel();
check( 'upload_files' === $model->getToolCapability( 'wp_upload_media' ), 'upload capability wired' );
check( 'write' === $model->getIntentForTool( 'wp_upload_media' )['intent'], 'write confirmation wired' );
$GLOBALS['tool_enabled'] = false;
$GLOBALS['responses'] = array( array( 'status' => 200, 'body' => $png ) );
$image_response = $model->dispatchTool( 'wp_upload_image', array( 'file' => json_encode( $image_file ), 'alt_text' => 'ChatGPT image' ), 'image-upload' );
check( isset( $image_response['result']['structuredContent']['id'] ), 'image tool uploads ChatGPT file without base64 or filename and without enabling media tool' );
$image_uploaded = $image_response['result']['structuredContent'];
check( 'image/png' === $image_uploaded['mime_type'] && 'image-upload' === $image_response['id'], 'image file MIME and RPC ID returned' );
check( 'ChatGPT image' === get_post_meta( $image_uploaded['id'], '_wp_attachment_image_alt', true ), 'conversation image alt text saved' );
check( 0 === strpos( $image_response['result']['content'][0]['text'], 'Image uploaded successfully. Attachment ID: ' ), 'image upload success text preserved' );
$GLOBALS['tool_enabled'] = true;
$GLOBALS['responses'] = array( array( 'status' => 200, 'body' => $pdf ) );
$attachment_count = count( $GLOBALS['attachments'] );
$document_result = $model->dispatchTool( 'wp_upload_image', array( 'file' => array( 'download_url' => 'https://8.8.8.8/a.pdf', 'file_id' => 'file-pdf', 'file_name' => 'a.pdf' ) ), 1 );
check( 'not_an_image' === $document_result['error']['data']['reason'] && count( $GLOBALS['attachments'] ) === $attachment_count, 'image tool rejects documents before creating an attachment' );
$image_conflict = $model->dispatchTool( 'wp_upload_image', array( 'file' => $image_file, 'image_data' => base64_encode( $png ) ), 1 );
check( -32602 === $image_conflict['error']['code'], 'image tool rejects ambiguous sources' );
check( -32602 === $model->dispatchTool( 'wp_upload_image', array(), 1 )['error']['code'], 'image tool requires a source' );
check( isset( $model->dispatchTool( 'wp_upload_image', array( 'file' => 'invalid JSON' ), 1 )['error'] ), 'image tool rejects invalid file JSON' );
$GLOBALS['image_tool_enabled'] = false;
check( isset( $model->dispatchTool( 'wp_upload_image', array( 'file' => $image_file ), 1 )['error'] ), 'disabled image tool rejects conversation files' );
$GLOBALS['image_tool_enabled'] = true;
foreach ( array( base64_encode( $png ), 'data:image/png;base64,' . chunk_split( base64_encode( $png ) ) ) as $legacy_data ) {
	$legacy_response = $model->dispatchTool( 'wp_upload_image', array( 'image_data' => $legacy_data, 'filename' => 'legacy.png' ), 1 );
	check( isset( $legacy_response['result']['content'][0]['text'] ) && ! isset( $legacy_response['result']['structuredContent'] ), 'legacy base64 upload retains original response shape' );
}
check( isset( $model->dispatchTool( 'wp_upload_image', array( 'image_data' => base64_encode( $png ) ), 1 )['error'] ), 'legacy base64 upload still requires filename' );
$result = $model->dispatchTool( 'wp_upload_media', $pdf_args, 'upload-test' );
check( isset( $result['result']['structuredContent']['id'] ), 'dispatcher uploads document with structured result' );
check( 'upload-test' === $result['id'], 'string RPC ID preserved' );
$uploaded = $result['result']['structuredContent'];
check( strlen( $pdf ) === $uploaded['size_bytes'] && 'application/pdf' === $uploaded['mime_type'], 'exact PDF bytes and MIME returned' );
check( json_decode( $result['result']['content'][0]['text'], true ) === $uploaded, 'text and structured outputs agree' );
$GLOBALS['tool_enabled'] = false;
check( isset( $model->dispatchTool( 'wp_upload_media', $pdf_args, 1 )['error'] ), 'disabled tool rejected at execution' );
$GLOBALS['tool_enabled'] = true;
$GLOBALS['permissions']['upload_files'] = false;
upload_error( $pdf_args, 'permission_denied', 'upload permission enforced' );
$GLOBALS['permissions']['upload_files'] = true;
$GLOBALS['permissions']['edit_post'] = false;
upload_error( $pdf_args + array( 'post_id' => 1 ), 'permission_denied', 'parent permission enforced' );
$GLOBALS['permissions']['edit_post'] = true;
upload_error( $pdf_args + array( 'post_id' => 99 ), 'permission_denied', 'missing parent rejected' );
upload_error( array(), 'invalid_arguments', 'missing source rejected' );
upload_error( $pdf_args + array( 'url' => 'https://8.8.8.8/a' ), 'invalid_arguments', 'ambiguous sources rejected' );
upload_error( array( 'file' => array( 'download_url' => $file['download_url'] ) ), 'invalid_file', 'file_id required' );
upload_error( array( 'file' => 'not JSON' ), 'invalid_file', 'invalid file JSON rejected' );
upload_error( array( 'file' => $file + array( 'unexpected' => true ) ), 'invalid_file', 'unknown file properties rejected' );
upload_error( $pdf_args + array( 'max_width' => 0 ), 'invalid_arguments', 'dimension lower bound' );
upload_error( $pdf_args + array( 'max_width' => 10001 ), 'invalid_arguments', 'dimension upper bound' );
upload_error( $pdf_args + array( 'max_kb' => 9 ), 'invalid_arguments', 'size target lower bound' );
upload_error( $pdf_args + array( 'quality' => 101 ), 'invalid_arguments', 'quality upper bound' );
upload_error( $pdf_args + array( 'convert_to' => 'svg' ), 'invalid_arguments', 'conversion format restricted' );
upload_error( $pdf_args + array( 'quality' => 82 ), 'not_an_image', 'documents reject image options' );
upload_error( array( 'content_base64' => '###', 'filename' => 'a.pdf' ), 'invalid_base64', 'invalid base64 rejected' );
upload_error( array( 'content_base64' => base64_encode( 'not PDF' ), 'filename' => 'a.pdf' ), 'file_type_not_allowed', 'MIME validation errors propagated' );
foreach ( array( 'file.svg', 'file.html', 'file.php', 'file.php.jpg', 'file.xml', 'file.js' ) as $blocked ) {
	upload_error( array( 'content_base64' => base64_encode( $pdf ), 'filename' => $blocked ), 'file_type_blocked', 'active format blocked: ' . $blocked );
}
$GLOBALS['upload_limit'] = 16;
upload_error( $pdf_args, 'file_too_large', 'lower site limit applied to base64' );
$GLOBALS['upload_limit'] = StifliFlexMcp_Upload_Media::MAX_BYTES;
$result = StifliFlexMcp_Upload_Media::upload( array( 'content_base64' => 'data:application/pdf;base64,' . chunk_split( base64_encode( $pdf ) ), 'filename' => 'a.pdf' ) );
check( ! is_wp_error( $result ), 'base64 data URL and whitespace accepted' );
$result = StifliFlexMcp_Upload_Media::upload( array( 'content_base64' => base64_encode( 'office archive' ), 'filename' => 'document.docx' ) );
check( ! is_wp_error( $result ), 'OpenXML MIME is not confused with active XML' );
$result = StifliFlexMcp_Upload_Media::upload( $image_args + array( 'alt_text' => 'Photo', 'title' => 'Image title', 'post_id' => 1 ) );
check( ! is_wp_error( $result ) && '.png' === substr( $result['filename'], -4 ), 'unfamiliar image extension corrected from bytes' );
check( 1 === $result['width'] && 1 === $result['height'], 'actual image dimensions returned' );
check( 'Photo' === get_post_meta( $result['id'], '_wp_attachment_image_alt', true ), 'alt text saved' );
check( 1 === $GLOBALS['attachments'][$result['id']]['parent'], 'parent post passed to WordPress' );

$GLOBALS['responses'] = array( array( 'status' => 200, 'body' => $pdf ) );
$result = $model->dispatchTool( 'wp_upload_media', array( 'file' => json_encode( $file ) ), 2 );
check( isset( $result['result']['structuredContent']['id'] ), 'JSON-string file normalized before schema validation' );
$request = end( $GLOBALS['requests'] )[1];
check( true === $request['stream'] && 0 === $request['redirection'], 'streamed download with explicitly handled redirects' );
check( StifliFlexMcp_Upload_Media::MAX_BYTES + 1 === $request['limit_response_size'], 'download limit has overflow detection byte' );
check( array() === $request['cookies'] && ! isset( $request['headers']['Authorization'] ), 'download sends no site credentials' );
$GLOBALS['responses'] = array( array( 'status' => 302, 'headers' => array( 'location' => '/final.pdf' ) ), array( 'status' => 200, 'body' => $pdf ) );
check( ! is_wp_error( StifliFlexMcp_Upload_Media::upload( array( 'file' => $file ) ) ), 'relative public redirect handled' );
$GLOBALS['responses'] = array( array( 'status' => 302, 'headers' => array( 'location' => 'https://127.0.0.1/file.pdf' ) ) );
upload_error( array( 'file' => $file ), 'private_ip_blocked', 'private redirect blocked before request' );
upload_error( array( 'url' => 'http://8.8.8.8/a.pdf' ), 'https_required', 'HTTP downloads rejected' );
upload_error( array( 'url' => 'https://user:secret@8.8.8.8/a.pdf' ), 'invalid_url', 'URL credentials rejected' );
$GLOBALS['responses'] = array( array( 'status' => 403, 'body' => 'expired' ) );
upload_error( array( 'file' => $file ), 'download_failed', 'expired download rejected' );
$GLOBALS['responses'] = array_fill( 0, 4, array( 'status' => 302, 'headers' => array( 'location' => '/again' ) ) );
upload_error( array( 'file' => $file ), 'too_many_redirects', 'redirect count bounded' );
$GLOBALS['responses'] = array( array( 'status' => 200, 'body' => '' ) );
upload_error( array( 'file' => $file ), 'empty_download', 'empty download rejected' );
$GLOBALS['upload_limit'] = 16;
$GLOBALS['responses'] = array( array( 'status' => 200, 'body' => $pdf ) );
upload_error( array( 'file' => $file ), 'file_too_large', 'truncated oversized download rejected' );
$GLOBALS['upload_limit'] = StifliFlexMcp_Upload_Media::MAX_BYTES;
$GLOBALS['responses'] = array( array( 'status' => 200, 'body' => $pdf, 'headers' => array( 'content-length' => StifliFlexMcp_Upload_Media::MAX_BYTES + 1 ) ) );
upload_error( array( 'file' => $file ), 'file_too_large', 'oversized Content-Length rejected' );
$GLOBALS['responses'] = array( new WP_Error( 'timeout', 'Timed out' ) );
upload_error( array( 'file' => $file ), 'download_failed', 'network failure propagated' );
$GLOBALS['responses'] = array( array( 'status' => 200, 'body' => $pdf ) );
check( ! is_wp_error( StifliFlexMcp_Upload_Media::upload( array( 'url' => 'https://8.8.8.8/document.pdf' ) ) ), 'standalone URL upload supported' );
$GLOBALS['responses'] = array( array( 'status' => 200, 'body' => $png ) );
check( ! is_wp_error( StifliFlexMcp_Upload_Media::upload( array( 'file' => array( 'download_url' => 'https://8.8.8.8/download', 'file_id' => 'file-test' ) ) ) ), 'file name and MIME hints are optional for images' );
$GLOBALS['reject_mime'] = true;
upload_error( $pdf_args, 'file_type_not_allowed', 'WordPress disallowed MIME cannot be overridden' );
$GLOBALS['reject_mime'] = false;
$GLOBALS['temp_failure'] = true;
upload_error( $pdf_args, 'temp_file_failed', 'temporary file failure reported' );
$GLOBALS['temp_failure'] = false;
$GLOBALS['sideload_failure'] = true;
upload_error( $pdf_args, 'sideload_failed', 'WordPress insertion failure propagated' );
$GLOBALS['sideload_failure'] = false;
$GLOBALS['editor_failure'] = true;
upload_error( $image_args + array( 'quality' => 82 ), 'editor_failed', 'missing image editor reported' );
$GLOBALS['editor_failure'] = false;
$GLOBALS['save_failure'] = true;
upload_error( $image_args + array( 'quality' => 82 ), 'save_failed', 'encoder error propagated' );
check( empty( $GLOBALS['filters']['wp_editor_set_quality'] ) && empty( $GLOBALS['filters']['jpeg_quality'] ), 'quality filters restored after failure' );
$GLOBALS['save_failure'] = false;
$GLOBALS['encodes'] = 0;
$result = StifliFlexMcp_Upload_Media::upload( $image_args + array( 'convert_to' => 'jpeg', 'max_kb' => 20 ) );
check( ! is_wp_error( $result ) && $result['size_bytes'] <= 20 * 1024 && $result['width'] < 1000, 'dimension fallback meets exact byte target' );
check( $GLOBALS['encodes'] <= 28, 'encoding attempts bounded' );
$GLOBALS['impossible_image'] = true;
upload_error( $image_args + array( 'max_kb' => 10 ), 'image_target_unreachable', 'impossible target rejected without media' );
$GLOBALS['impossible_image'] = false;
$GLOBALS['inflate_attachment'] = true;
upload_error( $image_args + array( 'max_kb' => 20 ), 'attachment_verification_failed', 'post-sideload size verified and attachment removed' );
$GLOBALS['inflate_attachment'] = false;
$raw = array( 'file' => $file, 'url' => 'https://8.8.8.8/a?signature=private', 'content_base64' => base64_encode( $pdf ), 'filename' => 'a.pdf' );
$redacted = StifliFlexMcpUtils::redactToolArguments( 'wp_upload_media', $raw );
check( '[REDACTED]' === $redacted['file'] && '[REDACTED]' === $redacted['url'] && '[REDACTED]' === $redacted['content_base64'], 'upload credentials and content omitted from audit arguments' );
check( $raw === StifliFlexMcpUtils::redactToolArguments( 'unrelated_tool', $raw ), 'other tools retain their argument behavior' );
$rpc = array( 'params' => array( 'name' => 'wp_upload_media', 'arguments' => $raw ) );
check( $redacted === StifliFlexMcpUtils::redactSecrets( $rpc )['params']['arguments'], 'raw RPC diagnostic redaction' );
$image_log_args = array( 'file' => $image_file, 'alt_text' => 'Image' );
check( '[REDACTED]' === StifliFlexMcpUtils::redactSecrets( array( 'name' => 'wp_upload_image', 'arguments' => $image_log_args ) )['arguments']['file'], 'conversation image URL redacted from RPC diagnostics' );
$legacy_log_args = array( 'image_data' => base64_encode( $png ), 'filename' => 'image.png' );
check( $legacy_log_args === StifliFlexMcpUtils::redactToolArguments( 'wp_upload_image', $legacy_log_args ), 'legacy base64 redo arguments preserved' );
require_once dirname( __DIR__ ) . '/models/class-change-tracker.php';
$tracker = StifliFlexMcp_ChangeTracker::getInstance();
$before = $tracker->captureBeforeState( 'wp_upload_media', $raw );
check( 'file_create' === $before['operation_type'] && 'media' === $before['object_type'], 'new upload participates in media rollback tracking' );
$tracker->recordChange( 'wp_upload_media', $raw, $before, array( 'result' => array( 'structuredContent' => $uploaded ) ) );
$record = $GLOBALS['wpdb']->row;
check( (string) $uploaded['id'] === $record['object_id'], 'audit record resolves ID from structured output' );
check( $redacted === json_decode( $record['args_json'], true ), 'persisted audit record excludes source URLs and bytes' );
$rollback = $tracker->rollback( 1 );
check( $rollback['success'] && ! isset( $GLOBALS['attachments'][$uploaded['id']] ), 'rollback removes the new attachment' );
$redo = $tracker->redo( 1 );
check( ! $redo['success'] && false !== strpos( $redo['message'], 'fresh download URL' ), 'redo explicitly requests a fresh upload instead of replaying redacted credentials' );
$image_before = $tracker->captureBeforeState( 'wp_upload_image', $image_log_args );
$tracker->recordChange( 'wp_upload_image', $image_log_args, $image_before, $image_response );
check( '[REDACTED]' === json_decode( $GLOBALS['wpdb']->row['args_json'], true )['file'], 'conversation image source omitted from persisted rollback arguments' );
$image_rollback = $tracker->rollback( 1 );
check( $image_rollback['success'] && ! isset( $GLOBALS['attachments'][$image_uploaded['id']] ), 'conversation image upload supports rollback' );
check( ! $tracker->redo( 1 )['success'], 'conversation image redo requires fresh source' );
check_temporary_cleanup();

if ( in_array( '--real-images', $argv, true ) ) {
	require __DIR__ . '/test-upload-media-real-images.php';
}
echo 'Upload checks passed: ' . $GLOBALS['checks'] . PHP_EOL;
