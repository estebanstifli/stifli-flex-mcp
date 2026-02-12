<?php
/**
 * Base Provider Handler for AI APIs
 *
 * @package StifliFlexMcp
 * @since 1.0.5
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Abstract base class for AI provider handlers
 */
abstract class StifliFlexMcp_Client_Provider_Base {

	/**
	 * Send a message to the AI provider
	 *
	 * @param array $args Message arguments.
	 * @return array|WP_Error Response data or error.
	 */
	abstract public function send_message( $args );

	/**
	 * Execute an MCP tool locally
	 *
	 * @param string $tool_name Tool name.
	 * @param array  $arguments Tool arguments.
	 * @return array Tool result.
	 */
	protected function execute_tool( $tool_name, $arguments ) {
		global $stifliFlexMcp;

		if ( ! isset( $stifliFlexMcp ) || ! isset( $stifliFlexMcp->model ) ) {
			return array(
				'error' => true,
				'message' => __( 'MCP model not available', 'stifli-flex-mcp' ),
			);
		}

		// Dispatch the tool
		$result = $stifliFlexMcp->model->dispatchTool( $tool_name, $arguments, null );

		if ( isset( $result['error'] ) ) {
			return array(
				'error' => true,
				'message' => $result['error']['message'] ?? __( 'Tool execution failed', 'stifli-flex-mcp' ),
			);
		}

		// Extract text content from result
		$content = '';
		if ( isset( $result['result']['content'] ) && is_array( $result['result']['content'] ) ) {
			foreach ( $result['result']['content'] as $item ) {
				if ( isset( $item['text'] ) ) {
					$content .= $item['text'] . "\n";
				}
			}
		}

		return array(
			'success' => true,
			'content' => trim( $content ),
		);
	}

	/**
	 * Make an HTTP request to an API
	 *
	 * @param string $url     API URL.
	 * @param array  $headers Request headers.
	 * @param array  $body    Request body.
	 * @return array|WP_Error Response or error.
	 */
	protected function make_request( $url, $headers, $body ) {
		$meta = $this->make_request_with_meta( $url, $headers, $body );
		if ( is_wp_error( $meta ) ) {
			return $meta;
		}
		return $meta['body'] ?? array();
	}

	/**
	 * Make an HTTP request and return response body + headers + status.
	 *
	 * @param string $url     API URL.
	 * @param array  $headers Request headers.
	 * @param array  $body    Request body.
	 * @return array|WP_Error Meta response (body/headers/status/body_raw) or error.
	 */
	protected function make_request_with_meta( $url, $headers, $body ) {
		$response = wp_remote_post( $url, array(
			'headers' => $headers,
			'body'    => wp_json_encode( $body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
			'timeout' => 120, // AI responses can be slow
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body_raw    = wp_remote_retrieve_body( $response );
		$headers_obj = wp_remote_retrieve_headers( $response );
		$headers_arr = array();
		if ( $headers_obj ) {
			// WP_HTTP_Requests_Response::get_headers returns Requests_Utility_CaseInsensitiveDictionary
			if ( is_object( $headers_obj ) && method_exists( $headers_obj, 'getAll' ) ) {
				$headers_arr = $headers_obj->getAll();
			} elseif ( is_array( $headers_obj ) ) {
				$headers_arr = $headers_obj;
			} elseif ( $headers_obj instanceof ArrayAccess ) {
				// Best-effort conversion.
				foreach ( $headers_obj as $k => $v ) {
					$headers_arr[ $k ] = $v;
				}
			}
		}

		// Normalize keys to lowercase for consistent lookups.
		$headers_lower = array();
		if ( is_array( $headers_arr ) ) {
			foreach ( $headers_arr as $k => $v ) {
				if ( is_string( $k ) ) {
					$headers_lower[ strtolower( $k ) ] = $v;
				}
			}
		}
		$headers_arr = $headers_lower;

		$body_json = json_decode( $body_raw, true );
		if ( ! is_array( $body_json ) ) {
			$body_json = array();
		}

		if ( $status_code >= 400 ) {
			$error_msg = isset( $body_json['error']['message'] )
				? $body_json['error']['message']
				: ( isset( $body_json['error'] ) && is_string( $body_json['error'] )
					? $body_json['error']
					/* translators: %d is the HTTP status code */
					: sprintf( __( 'API error: %d', 'stifli-flex-mcp' ), $status_code ) );

			// Enrich with HTTP meta for debugging (rate limit headers, retry-after, etc.).
			$data = array(
				'status_code' => $status_code,
				'headers'     => $headers_arr,
			);
			if ( ! empty( $body_raw ) ) {
				$data['body_raw'] = $body_raw;
			}

			$prefix = sprintf( __( 'HTTP %d: ', 'stifli-flex-mcp' ), $status_code );
			return new WP_Error( 'api_error', $prefix . $error_msg, $data );
		}

		return array(
			'status_code' => $status_code,
			'headers'     => $headers_arr,
			'body_raw'    => $body_raw,
			'body'        => $body_json,
		);
	}

	/**
	 * Format tools for the specific provider
	 *
	 * @param array $mcp_tools MCP tool definitions.
	 * @return array Formatted tools for the provider.
	 */
	abstract protected function format_tools( $mcp_tools );

	/**
	 * Get system prompt for MCP context
	 *
	 * @param string $custom_prompt Optional custom prompt from settings.
	 * @return string
	 */
	protected function get_system_prompt( $custom_prompt = '' ) {
		$site_name = get_bloginfo( 'name' );
		$site_url  = get_bloginfo( 'url' );
		
		// Base context about the WordPress site
		$base_context = sprintf(
			/* translators: %1$s is the site name, %2$s is the site URL */
			__( 'You are connected to the WordPress site "%1$s" (%2$s). You have access to MCP (Model Context Protocol) tools that can read and modify WordPress content, WooCommerce data, and more.', 'stifli-flex-mcp' ),
			$site_name,
			$site_url
		);
		
		// If custom prompt is provided, combine them
		if ( ! empty( $custom_prompt ) ) {
			return $custom_prompt . "\n\n" . $base_context;
		}
		
		// Default behavior instructions
		return $base_context . ' ' . __( 'When the user asks you to perform actions on the site, use the available tools. Always explain what you\'re doing and show the results clearly.', 'stifli-flex-mcp' );
	}
}
